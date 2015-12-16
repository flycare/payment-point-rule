<?php

class CMessage
{
    public $wx;
    public function __construct()
    {
//        $this->wx = new WxMessageClass();
    }

    public function messageDone($sence, $orderId)
    {
        Yii::log('$sence is ' . $sence, 'error');
        $payment = Payment::model()->findByPk($orderId);
        $result['payment'] = $payment;
        $rule = ControlHelps::getPointRuleStatus();
        if ($sence == 'order_prepaid' || $sence == 'order_prepaid_wxpay' || $sence == 'order_unprepaid') {
            //发送给顾客
            $wx_message_for_user = WxTemplete::model('WxTemplete')
                ->allot($payment->store_ctl_id, 'payment_customer_success')
                ->assign(array('result' => $result['payment'], 'rule' => $rule))
                ->PayMessageForCustomer()
                ->render();
            $state = wx_send($result['payment']->openid, $wx_message_for_user);

            //发送给服务员
            $wx_message_for_service = WxTemplete::model('WxTemplete')
                ->allot($payment->store_ctl_id, 'payment_waiter_success')
                ->assign(array('result' => $result['payment'], 'rule' => $rule))
                ->PayMessageForWaiter()
                ->render();
            $state = wx_send(Tool::_getServiceUser($payment->store_ctl_id, 1), $wx_message_for_service);

//            $this->wx->PayMessageForWaiter($result);
//            $this->wx->PayMessageForCustomer($result);
        }

        if ($sence == 'pay_prepaid' || $sence == 'pay_unprepaid' || $sence == 'pay_prepaid_wxpay' || $sence == 'ybspay') {
            //发送给顾客
            $wx_message_for_user = WxTemplete::model('WxTemplete')
                ->allot($payment->store_ctl_id, 'payment_customer_success')
                ->assign(array('result' => $result['payment'], 'rule' => $rule))
                ->PayMessageForCustomer()
                ->render();
            $state = wx_send($result['payment']->openid, $wx_message_for_user);

            //发送给服务员
            $wx_message_for_service = WxTemplete::model('WxTemplete')
                ->allot($payment->store_ctl_id, 'payment_waiter_success')
                ->assign(array('result' => $result['payment'], 'rule' => $rule))
                ->PayMessageForWaiter()
                ->render();
            $state = wx_send(Tool::_getServiceUser($payment->store_ctl_id, 1), $wx_message_for_service);
//            $this->wx->PayMessageForWaiter($result);
//            $this->wx->PayMessageForCustomer($result);
           //推送给收款通知服务员
            $service5num = Tool::_getServiceUser($payment->store_ctl_id, 5);//收款通知服务员
            Yii::log('收款通知单号:  '.$payment->id.'  openids:  . ' . var_export($service5num,1),'error');
            WxTemplateMessage::sendForService5($payment,$service5num);


        }
        if ($sence == 'scan' || $sence == 'app_scan' || $sence == 'app_qrcode') {
            //推送
            if (isset($payment->openid) && !empty($payment->openid)) {
                $point = new CPoint($payment->openid, $payment->store_ctl_id);
                $userPoint = $point->getUserPoint($payment->openid);
                if ($userPoint) {
                    $point = $userPoint->point;
                } else {
                    $point = 0;
                }
                Yii::log('join', 'error');
                $scan_message = WxTemplete::model('WxTemplete')
                    ->allot($payment->store_ctl_id, 'scan_payment_customer_success')
                    ->assign(array('result' => $result['payment'], 'rule' => $rule))
                    ->ScanMessage()
                    ->render();
                $state = wx_send($payment->openid, $scan_message);
                Yii::log('scan_message is ' . $scan_message, 'error');
                Yii::log('openid is ' . $payment->openid, 'error');
            }
        }


    }
}