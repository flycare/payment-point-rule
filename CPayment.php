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
    private $sense;
    private $method_config = array(
        'pay_prepaid' => 2,
        'pay_unprepaid' => 1,
        'pay_prepaid_wxpay' => 1,
        'order_prepaid' => 2,
        'order_unprepaid' => 1,
        'order_prepaid_wxpay' => 1,
        'scan_pay' => 3,
        'prepaid' => 4,
        'scan' => 3,
        'app_scan' => 3,
        'app_qrcode' => 1,
        'app_qrcode_unwxpay' => 1,
        'group' => 6,
        'ybspay' => 3,
    );
    static $timeSelf = 0;

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

    public function createPayment($payment, $type = "payment")
    {
        $res = array('status' => false, 'msg' => '');
        $this->set('type', $type);
        $this->set('payment', $payment);
        $this->payment['openid'] = $this->openid;
        $this->payment['brand_ctl_id'] = $this->brand_ctl_id;
        $this->payment['store_ctl_id'] = $this->store_ctl_id;
        $this->payment['payment_method'] = $this->method_config[$type];
        $this->payment['payment_time'] = !empty($payment['payment_time']) ? $payment['payment_time'] : time();
        $this->payment['update_time'] = time();
        $model = new Payment();
        $model->attributes = $this->payment;
        if ($model->save()) {
            $res['status'] = true;
        } else {
            Yii::log(var_export($model->getErrors(), true), 'error');
        }

        return $res;
    }

    public function unWxPaymentDone($payment, $sense)
    {
        //初始化返回值
        $res = array('status' => false, 'msg' => '', 'data' => array());
        //设置类属性
        $this->set('payment', $payment);
        $this->payment['openid'] = $this->openid;
        $this->payment['brand_ctl_id'] = $this->brand_ctl_id;
        $this->payment['store_ctl_id'] = $this->store_ctl_id;
        $this->payment['payment_method'] = $this->method_config[$sense];
        $this->payment['update_time'] = time();
        $this->payment['payment_time'] = time();
        $this->payment['status'] = 1;
        $payment = new Payment();
        $payment->attributes = $this->payment;
        if (!$payment->save()) {
            $res['msg'] = '保存支付信息失败';
            return $res;
        }
        $res['status'] = true;
        return $res;
    }

    /**
     * initPayment 生成订单时初始化支付信息
     * @param  array $payment 订单对象array('orderId','original_amount')
     * @param  string $sense 支付类型
     * @param  array $data 附加参数
     * @return array  初始化结果
     */
    public function initPayment($payment, $sense, $data = array())
    {
        //初始化返回值
        $res = array('status' => false, 'msg' => '', 'data' => array());
        //设置类属性
        $this->set('sense', $sense);
        $this->set('payment', $payment);
        //检查订单号是否合法
        if ($this->checkOrderId()) {
            //初始化model
            $this->payment['openid'] = $this->openid;
            $this->payment['brand_ctl_id'] = $this->brand_ctl_id;
            $this->payment['store_ctl_id'] = $this->store_ctl_id;
            $this->payment['payment_method'] = $this->method_config[$sense];
            $this->payment['payment_time'] = time();
            $this->payment['update_time'] = time();
            //微信
            Yii::log("预支付参数:" . var_export($data, true), 'error');
            $res = $this->prepay($data);
            if ($res['status'] == true) {
                $this->payment['prepay_id'] = $res['data']['prepay_id'];
                $model = new Payment;
                $this->payment['order_id'] = $this->payment['order_id'] ? $this->payment['order_id'] : $this->payment['id'];
                $model->attributes = $this->payment;
                if ($model->save()) {
                    $point = new CPoint();
                    $point->initPoint($this->openid);
                    $res['status'] = true;
                    $res['data'] = array('prepay_id' => $this->payment['prepay_id']);
                    return $res;
                } else {
                    Yii::log(var_export($model->getErrors(), true), 'error');
                    $res['msg'] = '订单保存失败';
                    return $res;
                }
            } else {
                $res['msg'] = '预支付订单生成失败';
                return $res;
            }
        } else {
            $res['msg'] = '订单号验证失败';
            return $res;
        }
    }

    /**
     * paymentDone 支付成功更新支付信息
     * @param  array $data 微信支付结果数据
     * @return array 更新结果
     */
    public function paymentDone($data)
    {
        $res = array('status' => false, 'msg' => '');
        if (!isset($data['transaction_id']) || empty($data['transaction_id'])) {
            $res['msg'] = '没有微信交易号';
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $id = $data['id'];
        $payment = Payment::model()->findByPk($id);
        if (empty($payment)) {
            $res['msg'] = "订单不存在";
            return $res;
        }
        if ($payment->status == 1) {
            $res['msg'] = "订单已支付";
            return $res;
        }
        $payment->status = 1;
        $payment->transaction_id = $data['transaction_id'];
        $payment->payment_time = !empty($data['payment_time']) ? $data['payment_time'] : time();
        if ($payment->save()) {
            Yii::log("支付成功=======>" . $payment->id, 'error');
            $res['status'] = true;
        } else {
            Yii::log("支付失败=======>" . $payment->id, 'error');
            Yii::log(var_export($payment->attributes, true), 'error');
            $res['msg'] = '支付信息保存失败';
        }
        return $res;
    }

    public function scanDeduct($orderId, $processData, $data,$sense)
    {
        //因为是先支付，后核销优惠券，所以在支付的时候，要提前减去优惠券抵扣掉的钱数
        $processData['remind'] = !empty($data['card']) ? $processData['remind'] - $data['card'] : $processData['remind'];
        $processData['remind'] = Tool::numberFormat($processData['remind']);
        //如果储值卡不足以完成付款，调用微信接口付款
        if ($processData['remind'] > 0) {
            $result = CPayment::microPay($this->store_ctl_id,$orderId, $processData['remind'], $data['auth_code']);
            if (isset($result['status']) && $result['status'] == false) {
                return $result;
            }
        }

        //不论是否通过微信付款，生成payment数据
        $store_ctl_id = $this->store_ctl_id;
        $payment = new CPayment($store_ctl_id, $this->openid);
        $temp = array(
            'id' => $data['id'],
            'order_id' => $data['orderId'],
            'original_amount' => $processData['prepaid'] + $processData['remind'] + $processData['point'] + $processData['discount'] + $processData['coupon'] + $data['card'],
            'actual_amount' => $processData['prepaid'] + $processData['remind'],
            'point_discount' => $processData['point'],
            'coupon_amount' => $processData['coupon'],
            'wxpay_amount' => $processData['remind'],
            'discount_amount' => $processData['discount'],
            'prepaid_amount' => $processData['prepaid'],
            'card_amount' => $data['card'],
            'transaction_id' => $result['transaction_id'],
            'payment_time' => !empty($result['time_end']) ? strtotime($result['time_end']) : time(),
            'comment' => !empty($data['comment']) ? $data['comment'] : '',
            'waiter_id' => !empty($data['waiter_id']) ? $data['waiter_id'] : 0,
            'status' => 1
        );
        $res = $payment->createPayment($temp, $sense);

        if ($res['status'] == true) {
            $res['status'] = true;
        } else {
            $res['msg'] = '创建支付信息失败';
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
        }

        if (isset($conditions['payment_method']) && !empty($conditions['payment_method'])) {
            if (is_array($conditions['payment_method'])) {
                $criteria->addInCondition('payment_method', join(',', $conditions['payment_method']));
            } else {
                $criteria->addCondition('payment_method=:payment_method');
                $criteria->params['payment_method'] = $conditions['payment_method'];
            }
        }
        $criteria->addCondition('status>=:status');
        $criteria->params['status'] = $conditions['status'];

        $res = Payment::model()->findAll($criteria);
        return $res;
    }

    /**
     * prepay 生成微信预支付信息
     * @param array $data
     * @return array 初始化结果true or false,微信返回的数据
     */
    public function prepay($param = array())
    {
        $res = array('status' => false, 'msg' => '');
        Yii::log('total_fee====>' . $this->payment['wxpay_amount'], 'error');
        Yii::log('prepay sense====>' . $this->sense, 'error');
        $total_fee = $this->payment['wxpay_amount'];
        $total_fee *= 100;
        $nonce_str = Weixin::api()->tool()->bulidNoncestr();
        $isSubMch = ControlHelps::isSubMch(Yii::app()->site->account->ctl_id);
        if ($isSubMch == 2) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = ControlHelps::getStoreSonMchid($this->store_ctl_id);
            if($dkl_openid = User::getUserDklOpenid($this->payment['openid'],Yii::app()->site->account->ctl_id)){
                $data['openid'] = $dkl_openid;
            }else{
                $data['sub_openid'] = $this->payment['openid'];
            }
        } elseif ($isSubMch == 1) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = Yii::app()->site->account->mchid;
            if($dkl_openid = User::getUserDklOpenid($this->payment['openid'],Yii::app()->site->account->ctl_id)){
                $data['openid'] = $dkl_openid;
            }else{
                $data['sub_openid'] = $this->payment['openid'];
            }
        } else {
            $key = Yii::app()->site->account->key;
            $data['appid'] = Yii::app()->site->account->appid;
            $data['mch_id'] = Yii::app()->site->account->mchid;
            $data['openid'] = $this->payment['openid'];
        }
        //$total_fee = 1; // 以分为单位,代表1分钱
        $data['nonce_str'] = $nonce_str;
        $data['body'] = '支付订单';
        $data['out_trade_no'] = $this->payment['id'];
        $data['total_fee'] = $total_fee;
        $data['notify_url'] = $this->notify_url;
        $data['trade_type'] = $this->trade_type;

        $attach = array('id' => $this->payment['id'], 'store_ctl_id' => $this->store_ctl_id, 'sense' => $this->sense, 'coupon_id' => $param['couponId']);
        if (isset($param['ruleId']) && !empty($param['ruleId'])) {
            $attach['ruleId'] = $param['ruleId'];
        }
        if (isset($param['user_card_id']) && !empty($param['user_card_id'])) {
            $attach['user_card_id'] = $param['user_card_id'];
        }
        $data['attach'] = CJSON::encode($attach);
        $data['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];
        $data['sign'] = Weixin::api()->tool()->getSign($data, $key);
        Yii::log('预支付请求参数:' . var_export($data, true), 'error');
        $result = Weixin::api()->order->data($data)->create();
        if ($result['return_code'] == 'SUCCESS') {
            $res['status'] = true;
        }
        $res['data'] = $result;
        return $res;
    }

    /**
     * @param $transaction_id string
     * @param $money float
     * @param $payment object
     * @return array
     */
    public function paymentRefund($transaction_id, $money, $payment, $refund_comment, $waiter_id)
    {
        $res = array('status' => false, 'msg' => '');
        if (empty($transaction_id) || empty($money)) {
            $res['msg'] = '错误的参数';
        }
        $data['out_refund_no'] = Order::create_order_sn();
        $refund_transaction_id = '';
        if ($money != 0) {
            $isSubMch = ControlHelps::isSubMch($this->brand_ctl_id);//判断是否属于子商户
            $nonce_str = Weixin::api()->tool()->bulidNoncestr();
            // $total_fee = 1; // 以分为单位,代表1分钱
            $data['appid'] = !empty($isSubMch) ? Yii::app()->params['table']['main_mch']['appid'] : Yii::app()->site->account->appid;
            $data['mch_id'] = !empty($isSubMch) ? Yii::app()->params['table']['main_mch']['mch_id'] :  Yii::app()->site->account->mchid;
            if($isSubMch == 2){
                $data['sub_mch_id'] =  ControlHelps::getStoreSonMchid($this->store_ctl_id);
            }elseif($isSubMch == 1){
                $data['sub_mch_id'] =  Yii::app()->site->account->mchid;
            }
            $data['nonce_str'] = $nonce_str;
            $data['transaction_id'] = $transaction_id;
            $data['total_fee'] = $money * 100;
            $data['refund_fee'] = $data['total_fee'];
            $data['op_user_id'] = $isSubMch == 2 ? ControlHelps::getStoreSonMchid($this->store_ctl_id) : Yii::app()->site->account->mchid;
            $key = !empty($isSubMch) ? Yii::app()->params['table']['main_mch']['key'] : Yii::app()->site->account->key;
            $data['sign'] = Weixin::api()->tool()->getSign($data, $key);
            $wx_res = Weixin::api()->order()->data($data)->refund();
            Yii::log(var_export($wx_res, true), 'error');
            if ($wx_res['return_code'] == 'SUCCESS') {
                if ($wx_res['result_code'] == 'SUCCESS') {
                    $refund_transaction_id = !empty($wx_res['refund_id']) ? trim($wx_res['refund_id']) : '';
                } else {
                    $res['msg'] = !empty($wx_res['err_code_des']) ? $wx_res['err_code_des'] : '提交业务失败';
                    return $res;
                }
            } elseif (isset($wx_res['return_code'])) {
                $res['msg'] = !empty($wx_res['return_msg']) ? $wx_res['return_msg'] : '未知错误';
                return $res;
            } else {
                $res['msg'] = '请求微信接口失败';
                return $res;
            }
        }

        $payment->refund_amount = $money;
        $res = $this->paymentRefundDone($payment, $data['out_refund_no'], $refund_comment, $waiter_id, $refund_transaction_id);
        return $res;
    }

    /**
     * 将记录保存到payment_refund表，同时将支付数据中的相关金额转为负数，并保存记录到payment表
     * @param $refund_id int   退款id
     * @param $payment object
     * @return array
     */
    public function paymentRefundDone($payment, $refund_id, $refund_comment, $waiter_id, $refund_transaction_id)
    {
        //初始化返回值
        $res = array('status' => false, 'msg' => '', 'data' => array());
        //保存记录到payment_refund表
        $payment_refund_data = $payment->attributes;
        $payment_refund_data['order_id'] = $payment_refund_data['id'];
        $payment_refund_data['id'] = $refund_id;
        $payment_refund_data['brand_ctl_id'] = $this->brand_ctl_id;
        $payment_refund_data['store_ctl_id'] = $this->store_ctl_id;
        $payment_refund_data['comment'] = $refund_comment;
        $payment_refund_data['refund_transaction_id'] = $refund_transaction_id;
        //如果不需要微信退款，则默认状态为退款完成
        $payment_refund_data['status'] = $payment_refund_data['wxpay_amount'] == 0 ? 1 : 0;
        $payment_refund_data['waiter_id'] = $waiter_id;
        $payment_refund_data['update_time'] = $payment_refund_data['payment_time'] = time();
        $payment_refund = new PaymentRefund;
        $payment_refund->attributes = $payment_refund_data;
        Yii::log(var_export($payment_refund->attributes, true), 'error');
        if (!$payment_refund->save()) {
            Yii::log(var_export($payment_refund->getErrors(), true), 'error');
            $res['msg'] = '保存退款表记录失败';
            return $res;
        }

        //将支付数据中的相关金额转为负数，保存记录到payment表
        $payment_data = $payment->attributes;
        $payment_data['id'] = $refund_id;
        $payment_data['brand_ctl_id'] = $this->brand_ctl_id;
        $payment_data['store_ctl_id'] = $this->store_ctl_id;
        $payment_data['transaction_id'] = $refund_transaction_id;
        $payment_data['original_amount'] = Tool::numberFormat($payment_data['original_amount'] * -1);
        $payment_data['actual_amount'] = Tool::numberFormat($payment_data['actual_amount'] * -1);
        $payment_data['wxpay_amount'] = Tool::numberFormat($payment_data['wxpay_amount'] * -1);
        $payment_data['point_discount'] = Tool::numberFormat($payment_data['point_discount'] * -1);
        $payment_data['discount_amount'] = Tool::numberFormat($payment_data['discount_amount'] * -1);
        $payment_data['coupon_amount'] = Tool::numberFormat($payment_data['coupon_amount'] * -1);
        $payment_data['prepaid_amount'] = Tool::numberFormat($payment_data['prepaid_amount'] * -1);
        $payment_data['other_discount'] = Tool::numberFormat($payment_data['other_discount'] * -1);
        $payment_data['comment'] = '退款';
        $payment_data['update_time'] = time();
        $payment_data['payment_time'] = time();
        $payment_data['status'] = -1;
        $payment = new Payment();
        $payment->attributes = $payment_data;
        Yii::log(var_export($payment->attributes, true), 'error');
        if (!$payment->save()) {
            Yii::log(var_export($payment->getErrors(), true), 'error');
            $res['msg'] = '保存payment表退款信息失败';
            return $res;
        }
        $res['status'] = true;
        return $res;
    }


    /**
     * wapUrl 生成wap端链接，前后端通用
     * @return string url地址
     */
    private function wapUrl()
    {
        $url = ControlHelps::getWapUrl();
        return $url;
    }

    /**
     * checkOrderId 校验订单号是否正确
     * @return bool
     */
    private function checkOrderId()
    {
        $res = true;
        if ($this->type == 'payment') {
            $res = true;
        }
        return $res;
    }

    /*
     *  微信买单当日收账
     * $beginTime 开始时间
     * $stopTime 结束时间
     *  $ctl_id 门店 id
     */
    public static function todayWxPay($beginTime, $stopTime, $ctl_id)
    {
        $sql = "SELECT sum(actual_amount) total ,
                count(*) order_num FROM {{payment}}
                WHERE  `store_ctl_id` = '{$ctl_id}'
                AND `payment_method` IN (1,2,3)
                AND `payment_time` > '{$beginTime}'
                AND `payment_time` < '{$stopTime}'
                AND `status` IN (1,2)
                ";
        /*$sql = "SELECT SUM(actual_amount) total,(select count(*) from oto_payment
                    WHERE payment_method in (1,3)
                    AND `payment_time` > '{$beginTime}'
                    AND `payment_time` < '{$stopTime}'
                    AND status >0
                ) order_num
                FROM oto_payment
                WHERE  `store_ctl_id` = '{$ctl_id}'
                AND  payment_method in (1,3)
                AND `payment_time` > '{$beginTime}'
                AND `payment_time` < '{$stopTime}'
                AND status !=0 ";*/
        $cmd = Yii::app()->db->createCommand($sql);
        $row = $cmd->query()->read();
        //var_dump($row);exit;
        return $row;

    }

    /*
     * 退款
     */
    public static function todayRefund($begintime, $stoptime, $ctl_id)
    {
        $sql = "SELECT sum(actual_amount) total ,
                count(*) order_num FROM {{payment_refund}}
                WHERE  `store_ctl_id` = '{$ctl_id}'
                AND `payment_time` > '{$begintime}'
                AND `payment_time` < '{$stoptime}'
                AND `payment_method` IN (1,2,3)
                ";
        $cmd = Yii::app()->db->createCommand($sql);
        $row = $cmd->query()->read();
        //var_dump($row);exit;
        return $row;
    }

    public static function getTotal($params)
    {
        $connection = Yii::app()->db;
        $sql = "SELECT sum(actual_amount) as total
                FROM {{payment}}
                WHERE " . $params['role'] . "_ctl_id = {$params['ctl_id']}
                AND `payment_time`>'{$params['start_time']}'
                AND `payment_time`<'{$params['end_time']}'
                AND payment_method in (1,3)
                AND `status`>0";
        $rows = $connection->createCommand($sql)->queryAll();
        if (empty($rows[0]['total']))
            $rows[0]['total'] = Tool::numberFormat(0);
        return $rows[0]['total'];
    }

    public static function getStoreData($params, $type)
    {
        if(empty($params['ctl_id']))
            return array();
        if ($type != null) {
            $ctl_id = "brand_ctl_id";
        } else {
            $ctl_id = "store_ctl_id";
        }
        $connection = Yii::app()->db;
        /*$sql = "SELECT sum(`original_amount`) as original,
                    sum(`actual_amount`) as actual,
                    ((select count(*) from oto_payment
                        WHERE payment_method in (1,3)
                         AND `payment_time`>'{$params['start_time']}'
                         AND `payment_time`<'{$params['end_time']}'
                         AND status >0
                      )-(select count(*) from oto_payment
                        WHERE payment_method in (1,3)
                         AND `payment_time`>'{$params['start_time']}'
                         AND `payment_time`<'{$params['end_time']}'
                         AND status=-1)) as total,
                    sum(wxpay_amount) as wxpay,
                    sum(prepaid_amount) as prepaid,
                    sum(point_discount) as point,
                    sum(discount_amount) as other,
                    sum(actual_amount)/sum(original_amount) as percent
                FROM {{payment}}
                WHERE $ctl_id = {$params['ctl_id']}
                AND `payment_time`>'{$params['start_time']}'
                AND `payment_time`<'{$params['end_time']}'
                AND payment_method in (1,3)
                AND `status`!=0";*/
        $sql1 = "SELECT sum(`original_amount`) as original,
                    sum(`actual_amount`) as actual,
                     count(*) as total,
                    sum(wxpay_amount) as wxpay,
                    sum(prepaid_amount) as prepaid,
                    sum(point_discount) as point,
                    sum(discount_amount) as other,
                    sum(coupon_amount) as coupon,
                    sum(actual_amount)/sum(original_amount) as percent
                FROM {{payment}}
                WHERE $ctl_id = {$params['ctl_id']}
                AND `payment_time`>'{$params['start_time']}'
                AND `payment_time`<'{$params['end_time']}'
                AND payment_method in (1,2,3)
                AND `status`>0";
        $sql2 = "SELECT sum(`original_amount`) as original,
                    sum(`actual_amount`) as actual,
                     count(*) as total,
                    sum(wxpay_amount) as wxpay,
                    sum(prepaid_amount) as prepaid,
                    sum(point_discount) as point,
                    sum(discount_amount) as other,
                    sum(coupon_amount) as coupon,
                    sum(actual_amount)/sum(original_amount) as percent
                FROM {{payment}}
                WHERE $ctl_id = {$params['ctl_id']}
                AND `payment_time`>'{$params['start_time']}'
                AND `payment_time`<'{$params['end_time']}'
                AND payment_method in (1,2,3)
                AND `status`<0";

        $rows1 = $connection->createCommand($sql1)->query()->read();
        $rows2 = $connection->createCommand($sql2)->query()->read();
        $rows['original'] = $rows1['original'] + $rows2['original'];
        $rows['actual'] = $rows1['actual'] + $rows2['actual'];
        $rows['total'] = $rows1['total'] - $rows2['total'];
        $rows['wxpay'] = $rows1['wxpay'] + $rows2['wxpay'];
        $rows['prepaid'] = $rows1['prepaid'] + $rows2['prepaid'];
        $rows['point'] = $rows1['point'] + $rows2['point'];
        $rows['other'] = $rows1['other'] + $rows1['coupon'] + $rows2['other'] + $rows2['coupon'];
        /*if($rows['original'] > $rows['actual']){
            $rows['other'] = abs(sprintf('%.2f', $rows['other']));
        }else{
            $rows['other'] = sprintf('%.2f', $rows['other']);
        }*/
        if ($rows['original'] == 0) {
            $rows['percent'] = 0;
        } else {
            $rows['percent'] = $rows['actual'] / $rows['original'];
        }
        return $rows;
    }

    public static function microPay($store_ctl_id,$out_trade_no, $total, $auth_code)
    {
        $isSubMch = ControlHelps::isSubMch(Yii::app()->site->account->ctl_id);

        if ($isSubMch == 2) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = ControlHelps::getStoreSonMchid($store_ctl_id);
        } elseif ($isSubMch == 1) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = Yii::app()->site->account->mchid;
        } else {
            $key = Yii::app()->site->account->key;
            $data['appid'] = Yii::app()->site->account->appid;
            $data['mch_id'] = Yii::app()->site->account->mchid;
        }

        //刷卡支付测试 商品标记
        $store_id       =  $store_ctl_id; //门店编号
        $store_control  =  Control::model()->find('id=:id and role=:role',
            array(':id'=>$store_id,':role'=>20));
        if(!empty($store_control->goods_tag)){
            $data['goods_tag'] = $store_control->goods_tag;
        }

        $data['out_trade_no'] = $out_trade_no;
        $data['total_fee'] = intval($total * 100);
        $data['auth_code'] = $auth_code;
        $data['body'] = "微信刷卡支付";

        Yii::log('商品标记data '.var_export($data,true),'error');
        //①、提交被扫支付
        $result = Weixin::api()->micro()->micropay($data, $key, 5);
        $result = (array)$result;
        Yii::log(var_export($result, true), 'error');
        //如果返回成功
        //注释原因：如果网络连接失败，需要去查询一下
//        if (!array_key_exists("return_code", $result)
//            || !array_key_exists("result_code", $result)
//        ) {
//            return array('status' => false, 'msg' => '接口调用失败');
//        }

        //签名验证

        //②、接口调用成功，明确返回调用失败
        if ($result["return_code"] == "SUCCESS" &&
            $result["result_code"] == "FAIL" &&
            $result["err_code"] != "USERPAYING" &&
            $result["err_code"] != "SYSTEMERROR" &&
            $result["err_code"] != "BANKERROR"
        ) {
            return array('status' => false, 'msg' => '支付失败');
        }
        $data = array();
        if ($isSubMch == 2) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = ControlHelps::getStoreSonMchid($store_ctl_id);
        } else if ($isSubMch == 1) {
            $key = Yii::app()->params['table']['main_mch']['key'];
            $data['appid'] = Yii::app()->params['table']['main_mch']['appid'];
            $data['mch_id'] = Yii::app()->params['table']['main_mch']['mch_id'];
            $data['sub_mch_id'] = Yii::app()->site->account->mchid;
        } else {
            $key = Yii::app()->site->account->key;
            $data['appid'] = Yii::app()->site->account->appid;
            $data['mch_id'] = Yii::app()->site->account->mchid;
        }
        //③、确认支付是否成功
        $queryTimes = 15;
        while ($queryTimes > 0) {

            $succResult = 0;
            $queryResult = Weixin::api()->micro()->query($out_trade_no, $data, $key, $succResult);

            //如果需要等待1s后继续
            if ($succResult == 2) {
                sleep(3);
                $queryTimes -= 1;
                continue;
            } else if ($succResult == 1) {//查询成功
                $queryResult['status'] = true;
                $queryResult['msg'] = 'OK';
                Yii::log(var_export($queryResult, true), 'error');
                return $queryResult;
            } else {//订单交易失败
                return array('status' => false, 'msg' => '支付失败');
            }
        }
        
        //④、10次确认失败，则撤销订单
        if (!Weixin::api()->micro()->cancel($out_trade_no, $data, $key)) {
            return array('status' => false, 'msg' => '撤销订单失败');
        }

        return array('status' => false, 'msg' => '调用扫码支付失败');

    }

    /*
       * 品牌下买单总额
       */
    public static function total_wx($ctl_id, $start_time, $end_time)
    {
        $sql = "SELECT sum(actual_amount) total ,
                sum(wxpay_amount) as wxpay,
                sum(prepaid_amount) as prepaid,
                sum(point_discount) as point_total,
                sum(other_discount) as off_total
                FROM {{payment}}
                WHERE  `payment_time` < '{$end_time}'
                AND `payment_time` > '{$start_time}'
                AND payment_method in (1,3)
                AND `status` =1
                AND brand_ctl_id = $ctl_id";
        //echo $sql;exit;
        $cmd = Yii::app()->db->createCommand($sql);
        $row = $cmd->query()->read();
        return $row;

    }

    public static function getActualAmount($id)
    {
        $payment = Payment::model()->findByPk($id);
        $money = $payment->actual_amount;
        return $money;
    }
}

