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
        'pay' => 1,
        'pay_prepaid' => 1,
        'pay_unprepaid' => 1,
        'pay_prepaid_wxpay'=>1,
        'order' => 2,
        'order_prepaid' => 2,
        'order_unprepaid' => 1,
        'order_prepaid_wxpay'=>1,
        'scan_pay' => 3,
        'prepaid' => 4,
        'scan' => 5,
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

    public function createPayment($payment, $type = "payment")
    {
        $res = array('status' => false, 'msg' => '');
        $this->set('type', $type);
        $this->set('payment', $payment);
        $this->payment['openid'] = $this->openid;
        $this->payment['brand_ctl_id'] = $this->brand_ctl_id;
        $this->payment['store_ctl_id'] = $this->store_ctl_id;
        if ($type == 'payment') {
            $this->payment['payment_method'] = $this->method_config['wx_pay'];
        } elseif ($type == 'order') {
            $this->payment['payment_method'] = $this->method_config['order'];
        } elseif ($type == 'scan_pay')
            $this->payment['payment_method'] = $this->method_config['scan_pay'];
        $this->payment['update_time'] = time();
        $model = new Payment();
        $model->attributes = $this->payment;
        if ($model->save()) {
            $res['status'] = true;
        }else{
            Yii::log(var_export($model->getErrors(),true),'error');
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
            $res = $this->prepay($data);
            if ($res['status'] == true) {
                $this->payment['prepay_id'] = $res['data']['prepay_id'];
                $model = new Payment;
                $this->payment['order_id'] = $this->payment['order_id']?$this->payment['order_id']:$this->payment['id'];
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

    /**
     * updatePayment 支付成功更新支付信息
     * @param  array $data 微信支付结果数据
     * @return array 更新结果
     */
    public function updatePayment($data)
    {
        $res = array('status' => false, 'msg' => '');
        $attach = CJSON::decode($data['attach']);
        $id = $attach['id'];
        $storeCtlId = $attach['store_ctl_id'];
        $payment = Payment::model()->findByPk($id);
        Yii::log(var_export($payment->attributes, true), 'error');
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
        $payment->payment_time = time();
        $point = new CPoint($this->openid, $storeCtlId);

        if ($payment->save()) {
            $discount = CJSON::decode($payment->discount_info);
            Yii::log(var_export($discount, true), 'error');
            if (!empty($discount['point_discount']['point']) && $discount['point_discount']['point'] != 0) {
                $point->updatePoint($discount['point_discount']['point'], 'cash', 'spend', $discount['point_discount']['money'], $id);
            }
            $res = $point->getByOnlinePay($payment->actual_amount, $payment->id);
            $res['status'] = true;
        }
        return $res;
    }

    /**
     * paymentDone 支付成功更新支付信息
     * @param  array $data 微信支付结果数据
     * @return array 更新结果
     */
    public function paymentDone($data)
    {
        $res = array('status' => false, 'msg' => '');
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
        $payment->payment_time = time();
        if ($payment->save()) {
            Yii::log("支付成功=======>".$payment->id, 'error');
            $res['status'] = true;
        } else {
            Yii::log("支付失败=======>".$payment->id, 'error');
            Yii::log(var_export($payment->attributes, true), 'error');
            $res['msg'] = '支付信息保存失败';
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
    private function prepay($param = array())
    {
        $res = array('status' => false, 'msg' => '');
        Yii::log('total_fee====>'.$this->payment['wxpay_amount'],'error');
        Yii::log('prepay sense====>'.$this->sense,'error');
        $total_fee = $this->payment['wxpay_amount'];
        $total_fee *= 100;

        $nonce_str = Weixin::api()->tool()->bulidNoncestr();
        // $total_fee = 1; // 以分为单位,代表1分钱
        $data['appid'] = Yii::app()->site->account->appid;
        $data['mch_id'] = Yii::app()->site->account->mchid;
        $data['nonce_str'] = $nonce_str;
        $data['body'] = '支付订单';

        $data['out_trade_no'] = $this->payment['id'];
        $data['total_fee'] = $total_fee;
        $data['notify_url'] = $this->notify_url;
        $data['trade_type'] = $this->trade_type;
        $data['openid'] = $this->payment['openid'];
        $attach = array('id' => $this->payment['id'], 'store_ctl_id' => $this->store_ctl_id, 'sense' => $this->sense);
        if (isset($param['ruleId']) && !empty($param['ruleId'])) {
            $attach['ruleId'] = $param['ruleId'];
        }
        $data['attach'] = CJSON::encode($attach);
        $data['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];
        $data['sign'] = Weixin::api()->tool()->getSign($data, Yii::app()->site->account->key);
        $result = Weixin::api()->order->data($data)->create();
        if ($result['return_code'] == 'SUCCESS') {
            $res['status'] = true;
        }
        $res['data'] = $result;
        return $res;
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
                AND `payment_method` IN (1,3)
                AND `payment_time` > '{$beginTime}'
                AND `payment_time` < '{$stopTime}'
                AND `status` IN (1,2)
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

    public static function getStoreData($params,$type)
    {
        if($type !=null){
            $ctl_id = "brand_ctl_id";
        }else{
            $ctl_id = "store_ctl_id";
        }
        $connection = Yii::app()->db;
        $sql = "SELECT sum(`original_amount`) as original,
                    sum(`actual_amount`) as actual,
                    count(id) as total,
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
                AND `status`>0";
        $rows = $connection->createCommand($sql)->queryAll();
        return $rows[0];
    }

    public static function microPay($out_trade_no, $total, $auth_code)
    {
        $data['out_trade_no'] = $out_trade_no;
        $data['total_fee'] = intval($total * 100);
        $data['auth_code'] = $auth_code;
        $data['body'] = "微信刷卡支付";
        //①、提交被扫支付
        $result = Weixin::api()->micro()->micropay($data, 5);
        Yii::log(var_export($result, true), 'error');
        //如果返回成功
        if (!array_key_exists("return_code", $result)
            || !array_key_exists("result_code", $result)
        ) {
            return array('status' => false, 'msg' => '接口调用失败');
        }

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

        //③、确认支付是否成功
        $queryTimes = 15;
        while ($queryTimes > 0) {
            $succResult = 0;
            $queryResult = Weixin::api()->micro()->query($out_trade_no, $succResult);
            //如果需要等待1s后继续
            if ($succResult == 2) {
                sleep(3);
                $queryTimes -= 1;
                continue;
            } else if ($succResult == 1) {//查询成功
                Yii::log(var_export($queryResult, true), 'error');
                return $queryResult;
            } else {//订单交易失败
                return array('status' => false, 'msg' => '支付失败');
            }
        }

        //④、10次确认失败，则撤销订单
        if (!Weixin::api()->micro()->cancel($out_trade_no)) {
            return array('status' => false, 'msg' => '撤销订单失败');
        }

        return array('status' => false, 'msg' => '调用扫码支付失败');

    }
    /*
       * 品牌下买单总额
       */
    public static function total_wx($ctl_id,$start_time,$end_time){
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

    public static function getActualAmount($id){
        $payment = Payment::model()->findByPk($id);
        $money = $payment->actual_amount;
        return $money;
    }
}

