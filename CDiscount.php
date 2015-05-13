<?php

/**
 * 优惠类
 */
class CDiscount
{
    private $brand_ctl_id;
    private $store_ctl_id;
    private $discount_config = array(
        'point' => 1,     //积分抵扣
        'off_sale' => 2,  //满减
        'oddment' => 3,   //抹零

    );
    private $point_config = array(
        1 => 'wxPay'
    );

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
        $res = array();
        $cPoint = new CPoint($this->store_ctl_id);
        $userPoint = $cPoint->getUserPoint($openid);
        $cRule = new CRule($this->store_ctl_id);
        $rules = $cRule->getRule();
        $point_rule = $rules['point_rule'];
        $res['point_discount'] = $this->parsePointRule($userPoint['point'], $money, $point_rule);
        $discount_rule = $rules['discount_rule'];
        $res['off_discount'] = $this->parseDiscountRule($money, $discount_rule);
        return $res;
    }

    /**
     * @param $point int 积分数量
     * @param $money float 消费金额
     * @param $point_rule array 积分兑换规则
     * @return float 优惠金额
     */
    private function parsePointRule($point, $money, $point_rule)
    {
        $totalPoint = intval($money * $point_rule['point']);
        $maxPoint = intval($totalPoint * $point_rule['max_percent'] / 100);
        if ($point > $maxPoint)
            $point = $maxPoint;
        $dMoney = $point / $point_rule['point'];
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

    /**
     * previewDiscount 预览优惠详情
     * @param  array $condition 优惠参数
     * @return array             优惠预览数据
     */
    /*public function previewDiscount($condition=array()){
        $res = array();
    	$rules = $this->getDiscountRule();
        return $res;
    }*/
}
