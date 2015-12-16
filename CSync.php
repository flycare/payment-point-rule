<?php
/**
 * Created by PhpStorm.
 * User: Vincent
 * Date: 15/8/27
 * Time: 下午3:28
 */
class CSync{
    private $models = array(
        Payment,
        Pay,
        UserPoint,
        PointHistory,
        PaymentRefund,
        Coupons,
        UserCoupon,
        UserPrepaid,
        UserPrepaidHistory
    );
    public function syncUserToCustomer(){
        $users = User::model()->findAll();
//        $users = array_slice($users,0,100);
        foreach($users as $user){
            if(Customer::model()->findByAttributes(array('openid'=>$user->openid))){
                continue;
            }
            $data = [];
            $data['customer_id'] = '';
            $data['brand_id'] = $user->ctl_id;
            $data['ctl_id'] = 0;
            $data['gender'] = $user->sex;
            $data['flag'] = 0;
            $data['openid'] = $user->openid;
            $data['mobile'] = ''; 
            $customer = new Customer();
            $customer->attributes = $data;
            if($customer->save(false)){
                $openid = $user->openid;
                $id = $customer->id;
                $customer_id = ControlHelps::generateCustomerId($id);
                $customer->updateByPk($id,array('customer_id'=>$customer_id));
                $this->update($openid,$customer_id);
            }else{
                Yii::log(var_export($customer->getErrors(),true),'error');
            }
        }
    }

    private function update($openid,$customerId){
        foreach($this->models as $model){
            $model::model()->updateAll(array('customer_id'=>$customerId),'openid=:openid',array(':openid'=>$openid));
        }
        UserPrepaid::model()->updateAll(array('user_prepaid_id'=>$customerId),'openid=:openid',array(':openid'=>$openid));
    }
}