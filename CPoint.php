<?php

/**
 * 用户积分类
 */
class CPoint
{

    private $brand_ctl_id; //品牌id
    private $store_ctl_id; //门店id
    private $open_id; //微信id
    private $criteria;
    private $userPoint;
    //操作配置，消费或累积
    private $operate_config = array(
        'spend' => 0,
        'get' => 1
    );
    //积分变动原因，抵现、砍价、微信支付奖励
    private $way_config = array(
        'cash' => 1,
        'haggle' => 2,
        'wx_pay' => 3,
        'gift' => 4
    );

    public function __construct($openid = '',$store_ctl_id = '')
    {
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
        $this->set('store_ctl_id', $store_ctl_id);
        $this->set('open_id', $openid);
    }

    /**
     * initPoint 初始化用户积分
     */
    public function initPoint($user)
    {
        $point = $this->giftPoint();
        $currentTime = time();
        $transaction = Yii::app()->db->beginTransaction();
        try {
            $userPoint = array();
            $userPoint['brand_ctl_id'] = $this->brand_ctl_id;
            $userPoint['store_ctl_id'] = $this->store_ctl_id;
            $userPoint['openid'] = $user['openid'];
            $userPoint['nickname'] = $user['nickname'];
            $userPoint['headimgurl'] = $user['headimgurl'];
            $userPoint['province'] = $user['province'];
            $userPoint['city'] = $user['city'];
            $userPoint['point'] = $point;
            $userPoint['create_time'] = $currentTime;
            $userPoint['update_time'] = $currentTime;
            $model = new UserPoint;
            $model->attributes = $userPoint;
            if (!$model->save()) {
                Yii::log(var_export($userPoint,true),'error');
                Yii::log(var_export($model->getErrors(),true),'error');
                throw new CException('更新用户积分失败');
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
                    Yii::log(var_export($pointHistory,true),'error');
                    Yii::log(var_export($model->getErrors(),true),'error');
                    throw new CException('保存用户积分历史失败');
                }
            }
            $transaction->commit();
        } catch (Exception $e) {
            Yii::log($e->getMessage(), 'error');
//            Yii::log(debug_print_backtrace(), 'error');
            $transaction->rollback();
            return false;
        }
        return true;

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
        $res = array('status'=>false,'msg'=>'');
        if($point == 0)
            return $res;
        $this->userPoint = $this->getUserPoint($this->open_id);
        if(empty($this->userPoint)){
            $res['msg'] = '用户积分数据不存在';
            return $res;
        }
        if($operate == 'spend')
            $this->spend($point);
        if($operate == 'get')
            $this->get($point);
        $this->userPoint->update_time = time();
        $transaction = Yii::app()->db->beginTransaction();
        try {
            if ($this->userPoint->save()) {
                $pointHistory = new PointHistory;
                $data = array(
                    'order_id' => $order_id,
                    'brand_ctl_id' => $this->brand_ctl_id,
                    'store_ctl_id' => $this->store_ctl_id,
                    'openid' => $this->open_id,
                    'point' => $point,
                    'money' => $money,
                    'operate' => $this->operate_config[$operate],
                    'way' => $this->way_config[$way],
                    'create_time' => time()
                );
                $pointHistory->attributes = $data;
                if ($pointHistory->save()){
                    $res['status'] = true;
                }
            }
            $transaction->commit();
        } catch (Exception $e) {
            Yii::log($e->getMessage(), 'error');
            Yii::log(debug_print_backtrace(), 'error');
            $transaction->rollback();
        }
        unset($this->userPoint);
        return $res;
    }

    private function spend($point)
    {
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
            $userPoint = UserPoint::model()->findAll($this->criteria);
        } else {
            $this->criteria->addCondition('openid=:openid');
            $this->criteria->params[':openid'] = $openid;
            $userPoint = UserPoint::model()->find($this->criteria);
        }
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

    /**
     * getByOnlinePay 微信支付获得积分
     * @return int 积分数量
     */
    public function getByOnlinePay($money, $orderId)
    {
        $point = intval($money);
        $res = $this->updatePoint($point, 'wx_pay', 'get', $money, $orderId);
        Yii::log("更新用户积分结果：".$res,'error');
        return $res;
    }


}