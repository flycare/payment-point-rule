<?php

/**
 * Created by PhpStorm.
 * User: Vincent
 * Date: 15/6/30
 * Time: 下午4:21
 */
class CPaymentRouter
{

    //试算执行规则,经过逐条计算返回金额
    private $preCountRules = array(
        'order_prepaid' => array('discount', 'coupon', 'point', 'prepaid'),
        'order_unprepaid' => array('discount', 'coupon', 'point'),
        'order_prepaid_wxpay' => array('discount', 'coupon', 'point', 'prepaid'),
        'pay_prepaid' => array('discount', 'coupon', 'point', 'prepaid'),
        'pay_unprepaid' => array('discount', 'coupon', 'point'),
        'pay_prepaid_wxpay' => array('discount', 'coupon', 'point', 'prepaid'),
        'prepaid' => array('prepaid_rule'),
        'scan' => array('discount', 'coupon', 'point', 'prepaid'),
        'app_scan' => array('use_card'),
        'app_qrcode' => array('use_card'),
        'app_qrcode_unwxpay' => array('use_card'),
        'group' => array(),
    );
    //扣减执行规则,完成各个模块的数据操作
    private $deductRules = array(
        'order_prepaid' => array('discount', 'coupon', 'point', 'prepaid'),
        'order_prepaid_wxpay' => array('discount', 'coupon', 'point', 'prepaid'),
        'order_unprepaid' => array('discount', 'coupon', 'point'),
        'pay_prepaid' => array('discount', 'coupon', 'point', 'prepaid'),
        'pay_prepaid_wxpay' => array('discount', 'coupon', 'point', 'prepaid'),
        'pay_unprepaid' => array('discount', 'coupon', 'point'),
        'prepaid' => array(),
        'scan' => array('discount', 'coupon', 'point', 'prepaid', 'payment'),
        'app_scan' => array('payment','use_card'),
        'app_qrcode' => array('use_card'),
        'app_qrcode_unwxpay' => array('use_card'),
        'group' => array(),
    );
    //完成执行规则,增加积分、修改订单和支付信息状态,推送消息
    private $doneRules = array(
        'order_prepaid' => array('point', 'order', 'unwxpayment', 'message', 'coupon'),
        'order_prepaid_wxpay' => array('point', 'order', 'prepaid', 'payment', 'message', 'coupon'),
        'order_unprepaid' => array('point', 'order', 'payment', 'message', 'coupon'),
        'pay_prepaid' => array('point', 'pay', 'unwxpayment', 'message', 'coupon'),
        'pay_prepaid_wxpay' => array('point', 'pay', 'prepaid', 'payment', 'message', 'coupon'),
        'pay_unprepaid' => array('point', 'pay', 'payment', 'message', 'coupon'),
        'prepaid' => array('buy_prepaid', 'payment', 'message', 'coupon'),
        'scan' => array('point', 'message', 'coupon'),
        'app_scan' => array('card'),
        'app_qrcode' => array('payment'),
        'app_qrcode_unwxpay' => array('unwxpayment'),
        'group' => array('point', 'payment', 'group'),
        'ybspay' => array('point', 'unwxpayment', 'message'),
    );
    //退款规则，储值支付加回储值余额，积分抵扣部分加回/减掉，微信支付部分退款
    private $refundRules = array(
        'refund' => array('prepaid', 'point', 'coupon', 'payment', 'message'),
    );
    //扫码支付规则
    private $scanRules = array(
        'scan' => array('discount', 'coupon', 'point', 'prepaid', 'payment', 'message'),
    );
    public $openid;
    public $store_ctl_id;
    public $brand_ctl_id;
    static $timeSelf = 0;

    public function __construct($openid, $store_ctl_id)
    {
        //todo：需要检查参数
        $this->set('openid', $openid);
        $this->set('store_ctl_id', $store_ctl_id);
        $this->set('brand_ctl_id', Yii::app()->site->account->ctl_id);
    }

    /**
     * @param $sense
     * @param $data
     * @return array
     */
    public function preCountRouter($sense, $data)
    {
        $money = $data['money'];
        $res = array('status' => false, 'msg' => '');
        //参数检测
        if (!array_key_exists($sense, $this->preCountRules)) {
            $res['msg'] = '场景错误';
            return $res;
        }
        if ($sense !== 'prepaid') {
            if ($money <= 0) {
                $res['msg'] = '非法参数';
                return $res;
            }
        }
        $money = Tool::numberFormat($money);
        $processData = array(
            'remind' => $money,
            'discount' => 0.00,
            'coupon_id' => 0,
            'coupon' => 0.00,
            'point' => 0.00,
            'prepaid' => 0.00,
            'user_card_id' => 0,
            'card' => 0.00,
            'card_real' => 0.00,
        );
        //如果场景中，没有需要执行的模块
        if(empty($this->preCountRules[$sense])){
            return $processData;
        }
        //根据场景配置中的规则进行遍历
        if ($sense == 'scan') {
            self::$timeSelf = Tool::getMillisecond();
        }
        foreach ($this->preCountRules[$sense] as $rule) {
            //储值卡规则和金额转换
            if ($rule == 'prepaid_rule') {
                $prepaid = new CUserPrepaid($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                $res = $prepaid->getPrepaidRulePrice($data['ruleId']);
                if ($res == false) {
                    $res['msg'] = '储值规则查询失败';
                    return $res;
                } else {
                    $processData['remind'] = Tool::numberFormat($res);
                }
            }

            //满减试算
            if ($rule == 'discount') {
                $discount = new CDiscount($this->store_ctl_id);
                $temp = $discount->discountPreCount($processData['remind'],$this->openid);
                Yii::log("满减试算结果:" . var_export($temp, true), 'error');
                $processData['discount'] = Tool::numberFormat($temp['discount']);
                $processData['remind'] = Tool::numberFormat($temp['remind']);
            }
            //红包试算
            if ($rule == 'coupon') {
                $coupon = new CCoupon($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                $temp = $coupon->preCount($processData['remind'], $data['couponId']);
                Yii::log("红包试算结果:" . var_export($temp, true), 'error');
                ControlHelps::setCurrentCoupon($temp['coupon_id']);
                $processData['coupon_id'] = $temp['coupon_id'];
                $processData['coupon'] = Tool::numberFormat($temp['deduction']);
                $processData['remind'] = Tool::numberFormat($temp['payment']);
            }
            //积分抵扣试算
            if ($rule == 'point') {
                $point = new CPoint($this->openid, $this->store_ctl_id);
                $temp = $point->pointPreCount($processData['remind']);
                $processData['point'] = Tool::numberFormat($temp['discount']);
                $processData['remind'] = Tool::numberFormat($temp['remind']);
            }
            //储值卡抵扣试算
            if ($rule == 'prepaid') {
                $prepaid = new CUserPrepaid($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                $temp = $prepaid->spendUserPrepaid('pre', false, $processData['remind']);
                $processData['prepaid'] = Tool::numberFormat($temp['spend_prepaid']);
                $processData['remind'] = Tool::numberFormat($temp['new_price']);
            }
            //优惠券抵扣试算
            if ($rule == 'use_card') {
                $ccard = new CCard($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                $temp = $ccard->get_suitable_card($processData['remind']);
                $processData['user_card_id'] = $temp['user_card_id'];
                $processData['card_id_wx'] = $temp['card_id_wx'];
                $processData['card'] = Tool::numberFormat($temp['card']);
                $processData['card_real'] = Tool::numberFormat($temp['card_real']);
                $processData['remind'] = Tool::numberFormat($temp['remind']);
            }
            $processData['coupon'] = Tool::numberFormat($processData['coupon']);
            $processData['point'] = Tool::numberFormat($processData['point']);
            $processData['discount'] = Tool::numberFormat($processData['discount']);
            $processData['prepaid'] = Tool::numberFormat($processData['prepaid']);
            $processData['remind'] = Tool::numberFormat($processData['remind']);
            $processData['card'] = Tool::numberFormat($processData['card']);
            $processData['card_real'] = Tool::numberFormat($processData['card_real']);
            if ($sense == 'scan') {
                Yii::log("payoptimize:{$this->openid}:".__FUNCTION__."->{$rule}->".(Tool::getMillisecond()-self::$timeSelf),'error');
                self::$timeSelf = Tool::getMillisecond();
            }
        }
        return $processData;
    }

    //扣减调度
    public function deductRouter($sense, $data)
    {
        Yii::log('开始执行扣减流程，场景:'.$sense.':brand_id:'.$this->brand_ctl_id.':store_id:'.$this->store_ctl_id.':openid:'.$this->openid.':参数:'.var_export($data,true),'error');
        $money = $data['money'];
        $res = array('status' => false, 'msg' => '');
        if (!array_key_exists($sense, $this->deductRules)) {
            $res['msg'] = '场景错误';
            return $res;
        }
        if ($money <= 0) {
            $res['msg'] = '非法参数';
            return $res;
        }
        //如果场景中，没有需要执行的模块
        if(empty($this->deductRules[$sense])){
            return array('remind' => $money);
        }
        $transaction = Yii::app()->db->beginTransaction();
        $money = Tool::numberFormat($money);
        $processData = array('remind' => $money);

        try {
            foreach ($this->deductRules[$sense] as $rule) {
                //满减试算
                if ($rule == 'discount') {
                    $discount = new CDiscount($this->store_ctl_id);
                    $temp = $discount->discountDeduct($processData['remind'],$this->openid);
                    Yii::log('满减扣减结果:'.var_export($temp,true),'error');
                    $processData['discount'] = Tool::numberFormat($temp['discount']);
                    $processData['remind'] = Tool::numberFormat($temp['remind']);
                }
                //红包扣减
                if ($rule == 'coupon') {
                    $coupon = new CCoupon($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $temp = $coupon->deduct($data['couponId'], $processData['remind'], $data['id']);
                    Yii::log("红包扣减结果:" . var_export($temp, true), 'error');
                    $processData['coupon'] = Tool::numberFormat($temp['deduction']);
                    $processData['remind'] = Tool::numberFormat($temp['payment']);
                }
                //积分扣减
                if ($rule == 'point') {
                    Yii::log('积分扣减:'.var_export($data,true),'error');
                    $point = new CPoint($this->openid, $this->store_ctl_id);
                    $temp = $point->pointDeduct($processData['remind']);
                    $processData['point'] = Tool::numberFormat($temp['point']);
                    $processData['remind'] = Tool::numberFormat($temp['remind']);
                }
                //储值支付
                if ($rule == 'prepaid') {
                    $prepaid = new CUserPrepaid($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    Yii::log("储值支付======>" . $processData['remind'], 'error');
                    $temp = $prepaid->spendUserPrepaid('real', false, $processData['remind'], $data['id']);
                    $processData['prepaid'] = Tool::numberFormat($temp['spend_prepaid']);
                    $processData['remind'] = Tool::numberFormat($temp['new_price']);
                }
                //此处只用于微信扫码支付，其他支付方式不能走这个流程
                if ($rule == 'payment') {
                    $payment = new CPayment($this->store_ctl_id, $this->openid);
                    //将计算信息和所有参数传入扫码扣款方法中，用于生成payment数据，doneRouter中不再处理payment
                    Yii::log("微信扫码支付参数======>" . var_export($processData,1), 'error');
                    $res = $payment->scanDeduct($data['orderId'], $processData, $data,$sense);
                    if ($res['status'] == false) {
                        if(!empty($processData['discount'])){
                            //如果使用了满减，要减去1个满减限制数
                            //这里如果该门店没有使用满减限制也会设置redis，但是减少了一次对是否开启满减限制的判断
                            CDiscount::setDiscountLimitNow($this->store_ctl_id,-1,$this->openid);
                        }
                        throw new Exception($res['msg']);
                    }
                }
                //优惠券抵扣
                if ($rule == 'use_card') {
                    $ccard = new CCard($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $temp = $ccard->deduct($data);
                    Yii::log("优惠券扣减结果:" . var_export($temp, true), 'error');
                }
                
                if ($sense == 'app_scan') {
                    Yii::log("payoptimize:{$this->openid}:".__FUNCTION__."->{$rule}->".(Tool::getMillisecond()-self::$timeSelf),'error');
                    self::$timeSelf = Tool::getMillisecond();
                }
            }
            $transaction->commit();
            $res['status'] = true;
            return $res;
        } catch (Exception $e) {
            $transaction->rollback();
            $res['msg'] = $e->getMessage();
            Yii::log(var_export($processData, true), 'error');
            Yii::log('实扣data：'.var_export($data, true), 'error');
            Yii::log($res['msg'], 'error');
            return $res;
        }

//        return $processData;
    }

    /**
     * @param $sense string 场景
     * @param $data array 数组
     * @return array
     */
    public function doneRouter($sense, $data)
    {
        $res = array('status' => false, 'msg' => '');
        if (!array_key_exists($sense, $this->doneRules)) {
            $res['msg'] = '场景错误';
            return $res;
        }
        $transaction = Yii::app()->db->beginTransaction();
        try {
            foreach ($this->doneRules[$sense] as $rule) {
                //买单状态更新
                if ($rule == 'pay') {
                    Yii::log("开始更新买单数据，Data:".var_export($data,true),'error');
                    $pay = new CPay($this->store_ctl_id, $this->openid);
                    $res = $pay->payDone($data);
                    Yii::log('买单更新结果'.var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        throw new Exception('更新买单状态失败');
                    }
                }
                //更新点单状态
                if ($rule == 'order') {
                    $point_discount = $data['point'];
                    $wxpay = $data['remind'];
                    $prepaid = $data['prepaid'];
                    $off = $data['discount'];
                    $coupon = $data['coupon'];
                    Yii::log("更新点单数据，Data:".var_export($data,true),'error');
                    $res = TotalOrder::updateOrder($data['orderId'], $point_discount, $wxpay, $prepaid, $off, $coupon);
                    Yii::log('点单更新结果'.var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        throw new Exception('更新订单状态失败');
                    }
                }
                //更新支付信息
                if ($rule == 'payment') {
                    Yii::log("更新支付信息数据，Data:".var_export($data,true),'error');
                    $payment = new CPayment($this->store_ctl_id, $this->openid);
                    $res = $payment->paymentDone($data);
                    Yii::log('支付信息更新结果'.var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        Yii::log(var_export($res, true), 'error');
                        throw new Exception($res['msg']);
                    }
                }
                //更新团购订单
                if ($rule == 'group') {
                    Yii::log("更新团购数据，Data:".var_export($data,true),'error');
                    $order_sn = $data['orderId'];
                    $model = Order::model()->find('order_sn=:orderSn', array('orderSn' => $order_sn));
                    $model->status = 1;
                    $model->payid = 2;
                    if ($model->save()) {
                        $res['status'] = true;
                        Yii::log('更新团购订单成功:' . $order_sn, 'error');
                    } else {
                        $res['status'] = false;
                        $res['msg'] = '更新订单失败' . $order_sn;
                    }
                    Yii::log('团购更新结果'.var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        Yii::log(var_export($res, true), 'error');
                        throw new Exception($res['msg']);
                    }
                }
                //非微信支付创建支付信息
                if ($rule == 'unwxpayment') {
                    $payment = new CPayment($this->store_ctl_id, $this->openid);
                    $temp = array(
                        'id' => $data['id'],
                        'order_id' => $data['orderId'],
                        'original_amount' => $data['prepaid'] + $data['remind'] + $data['point'] + $data['discount'] + $data['coupon'] + $data['card'],
                        'actual_amount' => $data['prepaid'] + $data['remind'],
                        'point_discount' => $data['point'],
                        'coupon_amount' => $data['coupon'],
                        'wxpay_amount' => $data['remind'],
                        'discount_amount' => $data['discount'],
                        'prepaid_amount' => $data['prepaid'],
                        'card_amount' => $data['card'],
                        'transaction_id' => !empty($data['transaction_id']) ? $data['transaction_id'] : '',
                        'comment' => !empty($data['comment']) ? $data['comment'] : '',
                        'waiter_id' => !empty($data['waiter_id']) ? $data['waiter_id'] : '',
                    );
                    $res = $payment->unWxPaymentDone($temp, $sense);
                    Yii::log(var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        throw new Exception('更新支付信息失败');
                    }
                }
                //加积分
                if ($rule == 'point') {
                    Yii::log("更新积分数据，Data:".var_export($data,true),'error');
                    $point = new CPoint($this->openid, $this->store_ctl_id);
                    $res = $point->pointDone($data['prepaid'] + $data['remind']);
                    Yii::log('积分更新结果'.var_export($res, true), 'error');
                    if (isset($res['status']) && $res['status'] === false) {
                        throw new Exception('更新用户积分失败');
                    }
                }
                //推送
                if ($rule == 'message') {
                    Yii::log("推送信息",'error');
                    $message = new CMessage($this->openid, $this->store_ctl_id);
                    $message->messageDone($sense, $data['id']);
                }
                //买储值卡
                if ($rule == 'buy_prepaid') {
                    $prepaid = new CUserPrepaid($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $prepaid->addPrepaid(false, $this->openid, $data['ruleId']);
                }
                if ($rule == 'coupon') {
                    Yii::log("生成优惠券,brand_ctl_id:".$this->brand_ctl_id,'error');
                    $coupon = new CCoupon($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $temp = $coupon->generateCoupon();
                    Yii::log("红包生成结果:" . var_export($temp, true), 'error');
                }
                if ($rule == 'card') {
                    Yii::log("生成微信优惠券,brand_ctl_id:".$this->brand_ctl_id,'error');
                    $card = new CCard($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $temp = $card->sendMessage();
                    Yii::log("微信优惠券生成结果:" . var_export($temp, true), 'error');
                }
                
                if ($sense == 'app_scan') {
                    Yii::log("payoptimize:{$this->openid}:".__FUNCTION__."->{$rule}->".(Tool::getMillisecond()-self::$timeSelf),'error');
                    self::$timeSelf = Tool::getMillisecond();
                }
            }
            $transaction->commit();
            //设置redis
            $date_number = Yii::app()->redisCache->get('number_'.intval($this->store_ctl_id) . '_' . date('Ymd'));
            if($date_number !== false){
                Yii::app()->redisCache->set('number_'.intval($this->store_ctl_id) . '_' . date('Ymd'),intval($date_number)+1,86400);
            }
            $date_money = Yii::app()->redisCache->get('money_'.intval($this->store_ctl_id) . '_' . date('Ymd'));
            if($date_money !== false){
                Yii::app()->redisCache->set('money_'.intval($this->store_ctl_id) . '_' . date('Ymd'),Tool::numberFormat($date_money+$data['prepaid'] + $data['remind']),86400);
            }

        } catch (Exception $e) {
            $transaction->rollback();
            $res['msg'] = $e->getMessage();
            Yii::log(var_export($data, true), 'error');
            Yii::log($res['msg'], 'error');
            return $res;
        }
        $res['status'] = true;
        return $res;
    }

    public function refundRouter($payment_id, $sense,$refund_comment,$waiter_id)
    {
        $res = array('status' => false, 'msg' => '');
        if (empty($payment_id) || empty($sense)) {
            $res['msg'] = '错误的参数';
            return $res;
        }
        $payment = Payment::model()->find("id=:paymentId", array(':paymentId' => $payment_id));
        $transaction = Yii::app()->db->beginTransaction();
        try {
            foreach ($this->refundRules[$sense] as $rule) {
                if ($rule == 'prepaid') {
                    if (!empty($payment->prepaid_amount) && $payment->prepaid_amount != 0) {
                        $userPrepaid = new CUserPrepaid($payment->openid, $payment->brand_ctl_id, $payment->store_ctl_id);
                        $r = $userPrepaid->addUserPrepaid(false, $payment->openid, 0, $payment->prepaid_amount, 0, '', 0, 0, '退款');
                        if ($r == false) {
                            throw new Exception('退款到储值卡失败');
                        }
                    }
                }
                if ($rule == 'point') {
//                    if ($payment->point_discount == 0) {
//                        continue;
//                    }
                    //不能判断积分抵现为0，因为消费可能还产生了新的积分
                    $point = new CPoint($payment->openid, $payment->store_ctl_id);
                    $discount = new CDiscount($payment->store_ctl_id);
                    //$tempPoint = intval($discount->discountMoneyToPoint($payment->point_discount) - intval($payment->actual_amount));

                    //退款积分修改
                    $tempPoint = array(
                        'userPoint'=>intval($discount->discountMoneyToPoint($payment->point_discount)),
                        'newPoint'=>intval($payment->actual_amount)
                    );

                    $r = $point->pointRefundRe($tempPoint,$payment->point_discount);
                    //$r = $point->pointRefund($tempPoint);
                    if ($r['status'] == false) {
                        $res['msg'] = $r['msg'];
                        throw new Exception($r['msg']);
                    }
                }
                if ($rule == 'payment') {
                    $cpayment = new CPayment($payment->store_ctl_id, $payment->openid);
                    $r = $cpayment->paymentRefund($payment->transaction_id, $payment->wxpay_amount, $payment,$refund_comment,$waiter_id);
                    if ($r['status'] == false) {
                        $res['msg'] = $r['msg'];
                        throw new Exception($r['msg']);
                    }
                }
                if ($rule == 'coupon') {
                    if ($payment->coupon_amount == 0) {
                        continue;
                    }
                    $coupon = new CCoupon($this->openid, $this->brand_ctl_id, $this->store_ctl_id);
                    $temp = $coupon->refund($payment->id);
                }
                //推送
                if ($rule == 'message') {
                    $message_data = WxTemplete::model('WxTemplete')
                        ->allot($payment->store_ctl_id, 'payment_refund')
                        ->assign(array('wxpay_amount' => $payment->wxpay_amount, 'prepaid_amount' => $payment->prepaid_amount, 'point' => intval($discount->discountMoneyToPoint($payment->point_discount) - intval($payment->actual_amount)), 'coupon_amount'=>$payment->coupon_amount))
                        ->PaymentRefund()
                        ->render();
                    wx_send($payment->openid, $message_data);
                }
            }
            $transaction->commit();
            //设置redis
            $date_number = Yii::app()->redisCache->get('number_'.intval($payment_id->store_ctl_id) . '_' . date('Ymd'));
            if($date_number !== false){
                Yii::app()->redisCache->set('number_'.intval($payment_id->store_ctl_id) . '_' . date('Ymd'),intval($date_number)-1,86400);
            }
            $date_money = Yii::app()->redisCache->get('money_'.intval($payment_id->store_ctl_id) . '_' . date('Ymd'));
            if($date_money !== false){
                Yii::app()->redisCache->set('money_'.intval($payment_id->store_ctl_id) . '_' . date('Ymd'),Tool::numberFormat($date_money-$payment_id->actual_amount),86400);
            }

            $res['status'] = true;
            return $res;
        } catch (Exception $e) {
            $transaction->rollback();
            $res['msg'] = $e->getMessage();
            Yii::log(var_export($payment, true), 'error');
            Yii::log($res['msg'], 'error');
            return $res;
        }
    }

    public function scanRouter()
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
}
