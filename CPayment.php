<?php

/**
 * 支付通用类
 */
class CPayment
{
    private $brand_ctl_id;
    private $store_ctl_id;
    private $openid;
    private $wap_domain;
    private $notify_url;
    private $trade_type = 'JSAPI';
    private $order_id;
    private $payment; //payment数据array
    private $notify = '/payment/notify';
    private $method_config = array(
        'wx_pay' => 1,
    );

    public function __construct($store_ctl_id, $openid)
    {
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
        $this->set('store_ctl_id', $store_ctl_id);
        $this->set('openid', $openid);
        $this->set('wap_domain', Yii::app()->params['domain']['wap']);
        $this->set('notify_url', $this->wapUrl() . Yii::app()->createUrl($this->notify));
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
     * initPayment 生成订单时初始化支付信息
     * @param  array $payment 订单对象array('orderId','original_amount')
     * @param  string $type 支付类型
     * @return array  初始化结果
     */
    public function initPayment($payment, $type = "payment")
    {
        //初始化返回值
        $res = array('status' => false, 'msg' => '','data'=>array());
        //设置类属性
        $this->set('type', $type);
        $this->set('payment', $payment);
        //检查订单号是否合法
        if ($this->checkOrderId()) {
            //初始化model
            $this->payment['order_id'] = $this->payment['orderId'];
            $this->payment['openid'] = $this->openid;
            $this->payment['brand_ctl_id'] = $this->brand_ctl_id;
            $this->payment['store_ctl_id'] = $this->store_ctl_id;
            $this->payment['payment_method'] = $this->method_config['wx_pay'];
            //获取该订单可以享受的优惠并设置优惠相关字段
            $cDiscount = new CDiscount($this->store_ctl_id);
            $discount = $cDiscount->discountInfo($this->payment['openid'], $this->payment['original_amount']);
            $this->setDiscount($discount);

            $this->payment['actual_amount'] = $this->payment['original_amount'] - $this->payment['point_discount'] - $this->payment['other_discount'];
            $this->payment['temp_order_id'] = Order::create_order_sn();
            $this->payment['update_time'] = time();
            //微信预支付
            $res = $this->prepay();
            if ($res['status'] == true) {
                $this->payment['prepay_id'] = $res['data']['prepay_id'];
                $model = Payment::model()->findByPk($this->payment['orderId']);
                if(empty($model))
                    $model = new Payment;
                $model->attributes = $this->payment;
                if ($model->save()) {
                    $res['status'] = true;
                    $res['data'] = array('prepay_id'=>$this->payment['prepay_id']);
                } else {
                    Yii::log(var_export($model->getErrors(),true),'error');
                    $res['msg'] = '订单保存失败';
                }
            } else {
                $res['msg'] = '预支付订单生成失败';
            }
        } else {
            $res['msg'] = '订单号验证失败';
        }
        return $res;
    }

    /**
     * @param array $discount
     */
    private function setDiscount($discount)
    {
        $this->payment['discount_info'] = CJSON::encode($discount);
        $this->payment['point_discount'] = $discount['point_discount']['money'];
        unset($discount['point_discount']);
        $other_discount = 0;
        foreach ($discount as $amount) {
            $other_discount += $amount;
        }
        $this->payment['other_discount'] = $other_discount;
    }


    public function notify()
    {
        // Yii::log(var_export($paymentParams,true), 'error');
        //使用通用通知接口
        $notify = Weixin::api()->notify;
        $this->setWechatData();
        //存储微信的回调
        $xml = $GLOBALS['HTTP_RAW_POST_DATA'];
        $notify->saveData($xml);
        Yii::log(var_export($notify->data, true), "error");
        //验证签名，并回应微信。
        //对后台通知交互时，如果微信收到商户的应答不是成功或超时，微信认为通知失败，
        //微信会通过一定的策略（如30分钟共8次）定期重新发起通知，
        //尽可能提高通知的成功率，但微信不保证通知最终能成功。
        if ($notify->checkSign($this->key) == FALSE) {
            $notify->setReturnParameter("return_code", "FAIL");//返回状态码
            $notify->setReturnParameter("return_msg", "签名失败");//返回信息
        } else {
            $notify->setReturnParameter("return_code", "SUCCESS");//设置返回码
        }
        $returnXml = $notify->returnXml();
        echo $returnXml;
        //==处理支付回调数据=======

        if ($notify->checkSign($this->key) == TRUE) {
            if ($notify->data["return_code"] == "FAIL") {
                //此处应该更新一下订单状态，商户自行增删操作
                Yii::log("return code fail", 'error');
            } elseif ($notify->data["result_code"] == "FAIL") {
                //此处应该更新一下订单状态，商户自行增删操作
                Yii::log("result code fail", 'error');
            } else {
                $orderId = $notify->data['attach'];
                $this->payment = Payment::model()->findByPk($orderId);
                $res = $this->updatePayment($this->payment,$notify->data);
                if ($res['status'] == true) {
                    //TODO:推送
                } else {
                    Yii::log($res['msg'], 'error');
                }
            }
        }
        return;
    }

    /**
     * updatePayment 支付成功更新支付信息
     * @param  array $data 微信支付结果数据
     * @return array 更新结果
     */
    public static function updatePayment($data)
    {
        $res = array('status' => false, 'msg' => '');
        $attach = CJSON::decode($data['attach']);
        $orderId = $attach['order_id'];
        $storeCtlId = $attach['orderId'];
        $payment = Payment::model()->findByPk($orderId);
        $payment->status = 1;
        $payment->transaction_id = $data['transaction_id'];
        $payment->payment_time = time();
        $point = new CPoint($storeCtlId);
        if ($payment->save()) {
            if ($point->getByOnlinePay($payment->actual_amount, $payment->order_id)) ;
            $res['status'] = true;
        }
        return $res;
    }

    /**
     * getPayment 获取支付数据
     * @param  array $conditions 条件array(owner=>array('role'=>brand||store,'ctl_id'=>10),'period'=>array('from_date'=>,'end_date'=>),'openid'=>mixed,'payment_method'=>)
     * @return array 支付数据
     */
    public static function getPayment($conditions = array())
    {
        $criteria = new CDbCriteria;
        if (isset($conditions['owner']) && !empty($conditions['owner'])) {
            $criteria->addCondition($conditions['owner']['role'] . '_ctl_id=:ctl_id');
            $criteria->params[':ctl_id'] = $conditions['owner']['ctl_id'];
        }

        if (isset($conditions['period']) && !empty($conditions['period'])) {
            $criteria->addBetweenCondition('payment_time', $conditions['period']['from_date'], $conditions['period']['end_date']);
        }
        if (isset($conditions['openid']) && !empty($conditions['openid'])) {
            if (is_array($conditions['openid'])) {
                $criteria->addInCondition('openid', join(',', $conditions['openid']));
            } else {
                $criteria->addCondition('openid=:openid');
                $criteria->params[':openid'] = $conditions['openid'];
            }
        }

        if (isset($conditions['payment_method']) && !empty($conditions['payment_method'])) {
            if (is_array($conditions['payment_method'])) {
                $criteria->addInCondition('payment_method', join(',', $conditions['payment_method']));
            } else {
                $criteria->addCondition('payment_method=:payment_method');
                $criteria->params[':payment_method'] = $conditions['payment_method'];
            }
        }

        $res = Payment::model()->findAll($criteria);
        return $res;
    }

    /**
     * prepay 生成微信预支付信息
     * @return array 初始化结果true or false,微信返回的数据
     */
    private function prepay()
    {
        $res = false;
        $total_fee = 0;
        $total_fee += $this->payment['actual_amount'];
        $total_fee *= 100;

        $nonce_str = Weixin::api()->tool()->bulidNoncestr();
        // $total_fee = 1; // 以分为单位,代表1分钱
        $data['appid'] = Yii::app()->site->account->appid;
        $data['mch_id'] = Yii::app()->site->account->mchid;
        $data['nonce_str'] = $nonce_str;
        $data['body'] = '支付订单';

        $data['out_trade_no'] = $this->payment['temp_order_id'] ? $this->payment['temp_order_id'] : $this->payment['order_id'];
        $data['total_fee'] = $total_fee;
        $data['notify_url'] = $this->notify_url;
        $data['trade_type'] = $this->trade_type;
        $data['openid'] = $this->payment['openid'];
        $data['attach'] = CJSON::encode(array('order_id'=>$this->payment['orderId'],'store_ctl_id'=>$this->store_ctl_id));
        $data['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];
        $data['sign'] = Weixin::api()->tool()->getSign($data, Yii::app()->site->account->key);
        $result = Weixin::api()->order->data($data)->create();
        if ($result['return_code'] == 'SUCCESS')
            $res = true;
        return array('status' => $res, 'data' => $result);
    }

    /**
     * wapUrl 生成wap端链接，前后端通用
     * @return string url地址
     */
    private function wapUrl()
    {
        $url = formart_domain_insert_ident($this->wap_domain, Yii::app()->site->account->identifier);
        return $url;
    }

    /**
     * checkOrderId 校验订单号是否正确
     * @return bool
     */
    private function checkOrderId()
    {
        $res = false;
        if ($this->type == 'payment') {
            $res = true;
        }
        return $res;
    }

}