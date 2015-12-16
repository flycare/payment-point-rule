<?php

/**
 * 用户积分类
 */
class CPoint
{

    private $brand_ctl_id; //品牌id
    private $store_ctl_id; //门店id
    private $openid; //微信id
    private $criteria;
    private $userPoint;
    //操作配置:消费或累积
    private $operate_config = array(
        'spend' => 0,
        'get' => 1
    );
    //积分变动原因:抵现、砍价、微信支付、奖励、扫码支付,兑换
    private $way_config = array(
        'cash' => 1,
        'haggle' => 2,
        'wx_pay' => 3,
        'gift' => 4,
        'scan_pay' => 5,
        'refund' => 6,
        'exchange'=>7
    );

    public function __construct($openid = '', $store_ctl_id = '')
    {
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
        $this->set('store_ctl_id', $store_ctl_id);
        $this->set('openid', $openid);
    }

    /**
     * initPoint 初始化用户积分
     */
    public function initPoint($openid)
    {
        $res = array('status' => false, 'msg' => '');
        if (empty($openid)) {
            $res['msg'] = '非法的openid';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $point = $this->giftPoint();
        $currentTime = time();
        $temp = UserPoint::model()->find('openid=:openid and brand_ctl_id=:brand_ctl_id',
                            array("openid" => $openid,"brand_ctl_id"=>$this->brand_ctl_id));
        if (!empty($temp)) {
            $res['msg'] = '用户积分已存在';
            return $res;
        }
        $user = User::model()->find("openid=:openid and ctl_id=:ctl_id",
                            array('openid' => $openid,'ctl_id'=>$this->brand_ctl_id));
        $userPoint = array();
        $userPoint['brand_ctl_id'] = $this->brand_ctl_id;
        $userPoint['store_ctl_id'] = $this->store_ctl_id;
        $userPoint['openid'] = $openid;
        $userPoint['nickname'] = $user['nickname'];
        $userPoint['headimgurl'] = $user['headimgurl'];
        $userPoint['province'] = $user['province'];
        $userPoint['city'] = $user['city'];
        $userPoint['point'] = $point;
        $userPoint['create_time'] = $currentTime;
        $userPoint['update_time'] = $currentTime;
        $userPointModel = new UserPoint;
        $userPointModel->attributes = $userPoint;
        if (!$userPointModel->save()) {
            Yii::log(var_export($userPoint, true), 'error');
            Yii::log(var_export($userPointModel->getErrors(), true), 'error');
            $res['msg'] = '更新用户积分失败';
            return $res;
        }
        if ($point !== 0) {
            $pointHistory = array();
            $pointHistory['brand_ctl_id'] = $this->brand_ctl_id;
            $pointHistory['store_ctl_id'] = $this->store_ctl_id;
            $pointHistory['openid'] = $user['openid'];
            $pointHistory['point'] = $point;
            $pointHistory['operate'] = $this->operate_config['get'];
            $pointHistory['way'] = $this->way_config['gift'];
            $pointHistory['create_time'] = $currentTime;
            $model = new PointHistory;
            $model->attributes = $pointHistory;
            if (!$model->save()) {
                Yii::log(var_export($pointHistory, true), 'error');
                Yii::log(var_export($model->getErrors(), true), 'error');
                $res['msg'] = '保存用户积分历史失败';
                return $res;
            }
        }
        $res['status'] = true;
        $res['model'] = $userPointModel;
        return $res;

    }

    /**
     * updatePoint 更新用户积分
     * @param  int $point 变动积分数量
     * @param  int $money 变动相关金额
     * @param  string $way 变动原因
     * @param  string $operate 操作类型，加get或减spend
     * @return bool          操作结果true||false
     */
    public function updatePoint($point, $way, $operate, $money = 0, $order_id = '')
    {
        $res = array('status' => false, 'msg' => '');
        if ($point < 0) {
            $res['msg'] = '非法的积分数量';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        if (empty($way) || empty($operate)) {
            $res['msg'] = '积分操作参数缺失';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        if ($point == 0) {
            $res['status'] = true;
            return $res;
        }
        $this->userPoint = $this->getUserPoint($this->openid);
        if (empty($this->userPoint)) {
            $res = $this->initPoint($this->openid);
            if (!isset($res['status']) && $res['status'] == false) {
                return $res;
            }
            $this->userPoint = $res['model'];
        }
        if(empty($this->userPoint)){
            $res['msg'] = 'openoid='. $this->openid . ';没有取到用户积分model';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $this->userPoint = $this->getUserPoint($this->openid);
        //记录point
        $point_re = $this->userPoint->point;

        if ($operate == 'spend')
            $this->spend(abs($point));
        if ($operate == 'get')
            $this->get(abs($point));
        $this->userPoint->update_time = time();
        if ($this->userPoint->save()) {
            $pointHistory = new PointHistory;

            if($way=='refund' && $operate =='spend'){
                //if($point_re < $point)
                    //$point = -$point_re;
                    $point = -$point;
                    $this->operate_config[$operate]=1;
            }

            if($way=='refund' && $operate == 'get'){
                if($point!=0)
                    $point = -$point;
                    $money = -$money;
                    $this->operate_config[$operate]=0;
            }

            $data = array(
                'order_id' => $order_id,
                'brand_ctl_id' => $this->brand_ctl_id,
                'store_ctl_id' => $this->store_ctl_id,
                'openid' => $this->openid,
                'point' => $point,
                'money' => $money,
                'operate' => $this->operate_config[$operate],
                'way' => $this->way_config[$way],
                'create_time' => time()
            );
            $pointHistory->attributes = $data;
            if ($pointHistory->save()) {
                $res['status'] = true;
            } else {
                $res['msg'] = '更新积分历史记录失败';
                return $res;
            }
        } else {
            $res['msg'] = '保存用户积分数据失败';
            return $res;
        }
        unset($this->userPoint);
        return $res;
    }

    /**
     * 积分试算
     * @param $money
     * @return array
     */
    public function pointPreCount($money)
    {
        $rule = ControlHelps::getPointRuleStatus();
        if($rule['usePoint'] != 1){
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        if($money <= 0){
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        $res = array();
        $userPoint = $this->getUserPoint($this->openid);
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $point_rule = $rules['point_rule'];
        $discount = new CDiscount($this->store_ctl_id);
        $userPoint['point'] = $userPoint['point'] ? $userPoint['point'] : 0;
        $temp = $discount->parsePointRule($userPoint['point'], $money, $point_rule);
        if($temp['money']  <  1){
            $result['point'] = 0;
            $result['discount'] = 0;
            $result['remind'] = $money - $res['discount'];
            return $result;
        }
        $res['point'] = $temp['point'];
        $res['discount'] = $temp['money'];
        $res['remind'] = $money - $res['discount'];
        return $res;
    }

    /**
     * @param $point
     * @return array
     */
    public function pointRefund($point)
    {
        $res = array('status'=>false,'msg'=>'');
        if ($point == 0) {
            $res['status'] = true;
            return $res;
        }
        if ($point > 0) {
            $point = abs($point);
            $res = $this->updatePoint($point, 'refund', 'get');
        } else {
            $point = abs($point);
            $res = $this->updatePoint($point, 'refund', 'spend');
        }
        return $res;
    }

    /**
     * @param $point
     * @return array
     */
    public function pointRefundRe($point,$money)
    {
        $res = array('status'=>false,'msg'=>'');

        if ($point['userPoint'] == 0&&$point['newPoint']==0) {
            $res['status'] = true;
            return $res;
        }

        $res = $this->updatePoint($point['newPoint'], 'refund', 'spend',$money);
        $res = $this->updatePoint($point['userPoint'] , 'refund', 'get',$money);

        return $res;
    }

    /**
     * 订单完成，累加积分
     * @param $money
     * @return bool
     */
    public function pointDone($money)
    {
        $res = array('status' => false, 'msg' => '');
        $rule = ControlHelps::getPointRuleStatus();
        if($rule['generate'] == 0){
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        if ($money <= 0) {
            $res['msg'] = '异常金额:'.$money;
            Yii::log($res['msg'], 'error');
            $res['status'] = true;
            return $res;
        }
        $res = $this->updatePoint(intval($money), 'wx_pay', 'get');
        if ($res['status'] === false) {
            Yii::log(var_export($res, true), 'error');
        }
        return $res;
    }

    /**
     * 积分扣减
     * @param $money
     * @return bool
     */
    public function pointDeduct($money)
    {
        $res = array('status' => false, 'msg' => '');
        $rule = ControlHelps::getPointRuleStatus();
        if($rule['usePoint'] != 1){
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        if($money <= 0){
            $res['msg'] = '可疑金额';
            Yii::log($res['msg'], 'error');
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        $temp = $this->pointPreCount($money);
        $point = $temp['point'];
        $dmoney = $temp['discount'];
        if($dmoney  <  1){
            $result['point'] = 0;
            $result['remind'] = $money;
            return $result;
        }
        Yii::log("point=========" . $point, 'error');
        Yii::log("dmoney=========" . $dmoney, 'error');
        $res = $this->updatePoint($point, 'cash', 'spend', $dmoney);
        if (isset($res['status']) && $res['status'] === false) {
            return $res;
        } else {
            $result['point'] = $dmoney;
            $result['remind'] = $temp['remind'];
            return $result;
        }
    }

    private function spend($point)
    {
        if (($this->userPoint->point - $point) < 0)
            $this->userPoint->point = 0;
        else
            $this->userPoint->point -= $point;
    }

    private function get($point)
    {
        $this->userPoint->point += $point;
    }

    private function giftPoint()
    {
        return 0;
    }

    /**
     * getUserPoint 获取用户积分信息
     * @param  mixed $openid 微信id，单个或多个
     * @return array          积分信息
     */
    public function getUserPoint($openid)
    {
        $this->criteria = new CDbCriteria();
        if (is_array($openid)) {
            $ids = join(',', $openid);
            $this->criteria->addInCondition('openid', $ids);
        } else {
            $this->criteria->addCondition('openid=:openid');
            $this->criteria->params[':openid'] = $openid;
        }
        $this->criteria->addCondition('brand_ctl_id=:brand_ctl_id');
        $this->criteria->params[':brand_ctl_id'] = $this->brand_ctl_id;
        $userPoint = UserPoint::model()->find($this->criteria);
        unset($this->criteria);
        return $userPoint;
    }


    /**
     * getUserPoint 获取用户历史
     * @param  string $openid 微信id
     * @param  string $role 角色，品牌brand或门店store
     * @return array          积分历史信息
     */
    public function getPointHistory($openid, $role = '')
    {
        if (is_array($openid)) {
            $ids = join(',', $openid);
            $this->criteria = new CDbCriteria();
            $this->criteria->addInCondition('openid', $ids);
            $this->buildCondition($role);
            $res = PointHistory::model()->findAll($this->criteria);
        } else {
            $this->criteria = new CDbCriteria();
            $this->criteria->addInCondition('openid', $openid);
            $this->buildCondition($role);
            $res = PointHistory::model()->findAll($this->criteria);
        }
        unset($this->criteria);
        return $res;
    }

    /**
     * buildCondition 根据角色组装查询条件
     * @param $role string 角色，品牌brand或门店store
     */
    private function buildCondition($role = '')
    {
        if ($role == 'brand') {
            $this->criteria->addCondition('brand_ctl_id=:brand_ctl_id', array('brand_ctl_id' => $this->brand_ctl_id));
        }
        if ($role == 'store') {
            $this->criteria->addCondition('store_ctl_id=:store_ctl_id', array('store_ctl_id' => $this->store_ctl_id));
        }
    }

    /**
     * set 设置类属性
     * @param string $key 类属性名
     * @param mixed $value 值
     */
    private function set($key, $value)
    {
        $this->$key = $value;
    }

    /**
     * updatePointHistory 更新积分历史记录
     * @param  array $params 参数:order_id,openid,point,money,operate,way
     * @return bool             操作结果
     */
    public function updatePointHistory($params)
    {
        $res = false;
        $params['create_time'] = time();
        $history = new PointHistory;
        $history->attributes = $params;
        if ($history->save())
            $res = true;
        return $res;
    }
}
