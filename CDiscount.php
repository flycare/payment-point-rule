<?php

/**
 * 优惠类
 */
class CDiscount
{
//    private $brand_ctl_id;
    private $store_ctl_id;
//    private $discount_config = array(
//        'point' => 1,     //积分抵扣
//        'off_sale' => 2,  //满减
//        'oddment' => 3,   //抹零
//
//    );
//    private $point_config = array(
//        1 => 'wxPay'
//    );

    public function __construct($store_ctl_id)
    {
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
        $this->set('store_ctl_id', $store_ctl_id);
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
     * setDiscountInfo description
     * @param [type] $order_id [description]
     * @param array $discount [description]
     * @return bool 是否成功
     */
//    public function setDiscountInfo($order_id,$discount=array()){
//        $res = false;
//    	return $res;
//    }
    /**
     * discountInfo 计算优惠数据详情
     * @param $openid string 微信id
     * @param $money int 消费金额
     * @return array 优惠数据
     */
    public function discountInfo($openid, $money)
    {
        $res = array('status'=>false,'msg'=>'');
        if(empty($openid)){
            $res['msg'] = '错误的openid';
            Yii::log($res['msg'],'error');
            return $res;
        }
        if($money <= 0){
            $res['msg'] = '异常金额:'.$money;
            Yii::log($res['msg'],'error');
            $temp = array();
            $temp['point_discount'] = 0;
            $temp['off_discount'] = 0;
            return $temp;
        }
        $cPoint = new CPoint($this->store_ctl_id);
        $userPoint = $cPoint->getUserPoint($openid);
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $point_rule = $rules['point_rule'];
        $temp['point_discount'] = $this->parsePointRule($userPoint['point'], $money, $point_rule);
        $discount_rule = $rules['discount_rule'];
        $temp['off_discount'] = $this->parseDiscountRule($money, $discount_rule);
        return $temp;
    }
    public function discountMoneyToPoint($money){
        $res = array('status'=>true,'msg'=>'');
        if($money < 0){
            $res['msg'] = '非法金额';
            Yii::log($res['msg'],'error');
            return $res;
        }
        if($money == 0){
            return 0;
        }
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $point_rule = $rules['point_rule'];
        $point = $money*$point_rule['point'];
        return $point;
    }

    /**
     * @param $money
     * @return array
     */
    public function discountPreCount($money,$openid=''){
        $res = array('status'=>false,'msg'=>'');
        if($money < 0){
            $res['msg'] = '异常金额';
            Yii::log($res['msg'],'error');
            $temp = array();
            $temp['discount'] = 0;
            $temp['remind'] = $money;
            return $temp;
        }
        if($money == 0){
            $temp = array('discount'=>0,'remind'=>0);
            return $temp;
        }
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $discount_rule = $rules['discount_rule'];
        //判断是否可用满减
        if(!$this->usable_discount($this->store_ctl_id,$discount_rule,$openid)){
            return array('discount'=>0,'remind'=>$money);
        }
        $temp['discount'] = $this->parseDiscountRule($money, $discount_rule);
        $temp['remind'] = $money - $temp['discount'];
        return $temp;
    }

    /**
     * @param $money
     * @return array
     */
    public function discountDeduct($money,$openid=''){
        $res = array('status'=>false,'msg'=>'');
        if($money < 0){
            $res['msg'] = '非法金额';
            Yii::log($res['msg'],'error');
            $temp['discount'] = 0;
            $temp['remind'] = $money;
            return $temp;
        }
        if($money == 0){
            $temp = array('discount'=>0,'remind'=>0);
            return $temp;
        }
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $discount_rule = $rules['discount_rule'];
        //判断是否可用满减
        if(!$this->usable_discount($this->store_ctl_id,$discount_rule,$openid)){
            return array('discount'=>0,'remind'=>$money);
        }
        $temp['discount'] = $this->parseDiscountRule($money, $discount_rule);
        $temp['remind'] = $money - $temp['discount'];
        if (!empty($temp['discount'])){
            //每日限制数加1
            $this->setDiscountLimitNow($this->store_ctl_id,1,$openid);
        }
        return $temp;
    }

    /**
     * @param $point int
     * @return float
     */
    public function pointToMoney($point){
        if(empty($point)||$point == 0)
            return 0;
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $point_rule = $rules['point_rule'];
        $money = Tool::numberFormat($point / $point_rule['point']);
        return $money;
    }
    /**
     * @param $point int 积分数量
     * @param $money float 消费金额
     * @param $point_rule array 积分兑换规则
     * @return float 优惠金额
     */
    public function parsePointRule($point, $money, $point_rule)
    {
        if($money == 0){
        }else{
            $totalPoint = intval($money * $point_rule['point']);
            $maxPoint = intval($totalPoint * $point_rule['max_percent'] / 100);
            if ($point > $maxPoint)
                $point = $maxPoint;
        }
        $tempMoney = ($point*100) / $point_rule['point'];
        $tempFloat = bcsub(abs($tempMoney),floor(abs($tempMoney)),20);
        $keepPoint = $tempFloat*$point_rule['point']/100;
        $point -= $keepPoint;
        $dMoney = Tool::numberFormat($point / $point_rule['point']);
        return array('point' => $point, 'money' => $dMoney);
    }

    /**
     * @param $money int 消费金额
     * @param $discount_rule array 优惠规则
     * @return float 优惠金额
     */
    private function parseDiscountRule($money, $discount_rule)
    {

        if ($discount_rule['type'] == 0) {
            $dMoney = 0;
        }
        if ($discount_rule['type'] == 1) {
            $param = intval($money / $discount_rule['money']);
            if ($param < 1) {
                $dMoney = 0;
            } else {
                if ($discount_rule['repeat'] == 0) {
                    $dMoney = $discount_rule['discount'];
                } else {
                    $dMoney = $param * $discount_rule['discount'];
                }
            }
        }
        return $dMoney;
    }

    public function usable_discount($store_id=0,$discount_rule=array(),$openid=''){
        if(empty($store_id) || empty($discount_rule)){
            return false;
        }
        if(empty($discount_rule['num'])){//没有每日限制
            return true;
        }
        $time = time();
        if($discount_rule['begin']>$time || $discount_rule['end']<$time){
            //未开始或者已失效
            return false;
        }
        //该用户是否已用过满减
        if($this->is_used_discount($store_id,$openid)){
            return false;
        }
        //当天的限制是否已达到
        $date_num = $this->getDiscountLimitNow($store_id);
        if($date_num >= $discount_rule['num']){
            return false;
        }
        return true;
    }

    private function getDiscountLimitNow($store_id=0){
        if(empty($store_id)){
            return false;
        }
        $key = 'getDiscountLimitNow:num:' . $store_id . ':date:' . date('Ymd');
        return (int)Yii::app()->redisCache->get($key);
    }

    private function is_used_discount($store_id=0,$openid=''){
        if(empty($store_id) || empty($openid)){
            return false;
        }
        $key_openid = 'getDiscountLimitNow:openid:' . $store_id . ':date:' . date('Ymd');
        $openids = (array)Yii::app()->redisCache->get($key_openid);
        return isset($openids[$openid]);
    }

    public function setDiscountLimitNow($store_id=0,$num=0,$openid=''){
        if(empty($store_id)){
            return false;
        }
        //总数
        $key_num = 'getDiscountLimitNow:num:' . $store_id . ':date:' . date('Ymd');
        $now = (int)Yii::app()->redisCache->get($key_num);
        $now = $now + $num > 0 ? $now + $num : 0;
        Yii::app()->redisCache->set($key_num,$now,86400);
        //用户
        $key_openid = 'getDiscountLimitNow:openid:' . $store_id . ':date:' . date('Ymd');
        $openids = (array)Yii::app()->redisCache->get($key_openid);
        if($num >0){
            $openids[$openid] = 1;
        }else{
            unset($openids[$openid]);
        }
        Yii::app()->redisCache->set($key_openid,$openids,86400);
        return true;
    }
}

