<?php

/**
 * 买单类
 */
class CPay
{

    private $brand_ctl_id;
    private $store_ctl_id;
    private $openid;

    public function __construct($store_ctl_id, $openid)
    {
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
        $this->set('store_ctl_id', $store_ctl_id);
        $this->set('openid', $openid);
    }

    /**
     * 试算时之后初始化买单数据
     */
    public function payInit($amountData)
    {
        $res = array('status' => false, 'msg' => '');
        if (!isset($amountData['orderId']) || empty($amountData['orderId'])) {
            $res['msg'] = 'orderId参数有误';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        if ($amountData['prepaid'] < 0 || $amountData['remind'] < 0 || $amountData['discount'] < 0 || $amountData['point'] < 0) {
            $res['msg'] = '非法金额';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $attributes['id'] = Order::create_order_sn();
        $attributes['order_id'] = $amountData['orderId'];
        $attributes['openid'] = $this->openid;
        $attributes['brand_ctl_id'] = $this->brand_ctl_id;
        $attributes['store_ctl_id'] = $this->store_ctl_id;
        $attributes['original_amount'] = $amountData['prepaid'] + $amountData['remind'] + $amountData['discount'] + $amountData['point'];
        $attributes['actual_amount'] = $amountData['prepaid'] + $amountData['remind'];
        $attributes['wxpay_amount'] = $amountData['remind'];
        $attributes['point_amount'] = $amountData['point'];
        $attributes['discount_amount'] = $amountData['discount'];
        $attributes['prepaid_amount'] = $amountData['prepaid'];
        $attributes['update_time'] = time();
        $attributes['status'] = 0;
        $pay = new Pay;
        $pay->attributes = $attributes;
        if ($pay->save()) {
            $res['status'] = true;
        } else {
            $res['msg'] = '初始化买单数据失败';
        }
        return $res;
    }

    /**
     *
     */
    public function payDone($data)
    {
        $res = array('status' => false, 'msg' => '');
        if (!isset($data['orderId']) || empty($data['orderId'])) {
            $res['msg'] = 'orderId参数有误';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        if ($data['prepaid'] < 0 || $data['remind'] < 0 || $data['discount'] < 0 || $data['point'] < 0) {
            $res['msg'] = '非法金额';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $attributes['id'] = $data['id'];
        $attributes['order_id'] = $data['orderId'];
        $attributes['openid'] = $this->openid;
        $attributes['brand_ctl_id'] = $this->brand_ctl_id;
        $attributes['store_ctl_id'] = $this->store_ctl_id;
        $attributes['original_amount'] = $data['prepaid'] + $data['remind'] + $data['discount'] + $data['point'];
        $attributes['actual_amount'] = $data['prepaid'] + $data['remind'];
        $attributes['wxpay_amount'] = $data['remind'];
        $attributes['point_amount'] = $data['point'];
        $attributes['discount_amount'] = $data['discount'];
        $attributes['prepaid_amount'] = $data['prepaid'];
        $attributes['update_time'] = time();
        $attributes['status'] = 1;
        $pay = new Pay;
        $pay->attributes = $attributes;
        $pay->payment_time = time();
        if (!$pay->save()) {
            $res['msg'] = '保存买单信息失败';
        } else {
            $res['status'] = true;
        }
        return $res;
    }

    /**
     * 获取买单详情
     */
    public function getPayData()
    {

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
     * getPayment 获取支付数据
     * @param  array $conditions 条件array(owner=>array('role'=>brand||store,'ctl_id'=>10),'period'=>array('from_date'=>,'end_date'=>),'openid'=>mixed,'payment_method'=>)
     * @return array 支付数据
     */
    public static function getPay($conditions = array())
    {
        $criteria = new CDbCriteria;
        if (isset($conditions['owner']) && !empty($conditions['owner'])) {
            $criteria->addCondition($conditions['owner']['role'] . '_ctl_id=:ctl_id');
            $criteria->params['ctl_id'] = $conditions['owner']['ctl_id'];
        }

        if (isset($conditions['period']) && !empty($conditions['period'])) {
            $criteria->addBetweenCondition('payment_time', $conditions['period']['from_date'], $conditions['period']['end_date']);
        }
        if (isset($conditions['openid']) && !empty($conditions['openid'])) {
            if (is_array($conditions['openid'])) {
                $criteria->addInCondition('openid', join(',', $conditions['openid']));
            } else {
                $criteria->addCondition('openid=:openid');
                $criteria->params['openid'] = $conditions['openid'];
            }
            $criteria->addCondition('status>=:status');
            $criteria->params['status'] = $conditions['status'];

            $res = Pay::model()->findAll($criteria);
            return $res;
        }
    }
}