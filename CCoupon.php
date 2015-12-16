<?php
/**
 * 红包类
 * Date : 2015 07 28
 * @todo 分配红包所有人点进去后，如果已经分享过，则不要更改状态  //等待验证
 * @todo 点击发送与关闭模块，如何处理
 */
class CCoupon{
    private $openid;
    private $brand_ctl_id;
    private $store_ctl_id;
    private $table_inc;
    public $info = array();


    public function __construct($openid='',$brand_ctl_id='',$store_id=''){
        $this->openid = $openid;
        $this->brand_ctl_id = $brand_ctl_id;
        $this->store_ctl_id = $store_id;
        $this->table_inc = Yii::app()->params['table']['coupon'];

//        //设置异常的处理方式,不抛出错误，只记录日志
//        function exception_handler($exception) {
//            echo "Uncaught exception: " , $exception->getMessage(), "\n";
//        }
//        set_exception_handler('exception_handler');

    }

    /**
     * 验证是否已经关闭模块
     */
    public function _validate(){
        $flag = '';
        $module = BackMenu::model()->find('ctl_id=:ctlId AND flag=:F',array('ctlId'=>$this->brand_ctl_id,'F'=>$flag));
        //每个需要来根据模块开启与否的接口，在最开始都验证一下，如果返回true则继续
        if($module->status===1){
            return true;
        }
        return false;
    }

    /**
     * step1
     * 付款成功后,生成用户红包
     * @openid $this->openid
     */
    public function generateCoupon(){
        $couponRole = $this->getNewestRule($this->brand_ctl_id); //获取couponRole
        //时间区间
        //固定时间
        if($couponRole->type==2){
            $var['expire_time'] = $couponRole->end_time-1;
            $var['available_dates'] =   $couponRole->available_dates;
            //动态时间
        }elseif($couponRole->type==1){
            $oneDay     =   60*60*24;
            $day        =   strtotime("today");
            $start_time =   $couponRole->start_time;
            $end_time   =   $couponRole->end_time;
            $s = $start_time+$day;
            $e = $start_time+$day+$end_time;
            while($e>$s){
                $availables[] = date('md',$s);
                $s+=$oneDay;
            }
            $var['available_dates'] =  implode(',',$availables);
            $var['expire_time'] = $day+$couponRole->start_time+$couponRole->end_time-1;
        }
        if($var['expire_time']<time()){
            Yii::log('红包已过期','error');
            return false;
        }
        //是否发放
        Yii::log('$couponRole'.var_export($couponRole,true),'error');
        if($couponRole['status']==0){
            Yii::log('红包未开始发放','error');
            return false;
        }
        //模块是否开启
        $flag_bonus = ViewHelps::closeFounction($this->brand_ctl_id,'coupon');
        if($flag_bonus!=1){
            Yii::log('红包模块未开启','error');
            return false;
        }

        $couponModel = new Coupons();

        try{
            if(!$couponRole){
                throw new Exception('rule Model 不存在');
            }
            $var['openid'] = $this->openid;
            $var['coupon_rule_id'] = $couponRole->id;
            $var['brand_ctl_id'] = $this->brand_ctl_id;
            $var['store_ctl_id'] = $this->store_ctl_id;
            $var['step'] = $this->table_inc['step_key']['send'];
            $var['create_time'] = time();
            $var['total'] = $couponRole->max_number;
            $couponModel->attributes = $var;
            $couponModel->save();
        }catch (Exception $e){
            Yii::log($e->getMessage(),'error');
            return false;
        }
        $this->sendMessage($couponModel->id,$this->store_ctl_id);
    }
    /**
     * step2
     * 付款成功后,推送消息
     */
    public  function sendMessage($coupon_id,$ctl_id){
        $user = User::model()->find('openid=:openId',array('openId'=>$this->openid));
        if($user->subscribe!=1){
            Yii::log('用户未关注','error');
            return false;
        }
//        $tpl_model = CouponRuleDesc::model()->find('brand_ctl_id=:B',array(':B'=>$this->brand_ctl_id)); //以品牌id为条件查询出唯一红包规则模板
        $tpl_model  =   $this->getValidRuleDesc($this->brand_ctl_id);
        $account = Account::model()->find('ctl_id=:C',array(':C'=>$this->brand_ctl_id)); //以门店id为条件查询出品牌模型
//        $couponModel = Coupons::model()->find('openid=:openId',array(':openId'=>$this->openid)); //以openid 为条件查询出红包模型
        //$domain = formart_domain_insert_ident(Yii::app()->params['domain']['wap'],$account->identifier);
        $domain = Tool::wapUrl();
        $url = $domain.'/coupon/share/coupon_id/'.$coupon_id.'/rule_model_id/'.$tpl_model->id.'/ctl_id/'.$ctl_id;
        $articles = array(
            array('title'=>$tpl_model->title,'desc'=>$tpl_model->desc,'picurl'=>"http://mmbiz.qpic.cn/mmbiz/XQIJWxYnwFibz9edEz8zaZXCNpibooCJ2FqOMpdUI3nx13zIeEiaLmGgUG00V5PmcYbX7bxJMCDCQP2VUoeDGfpCQ/0",'url'=>$url),
        );
        return Weixin::api()->custom->news(array('to'=>$this->openid, 'items'=>$articles))->send();

    }
    /**
     * step3
     * 领取红包
     * @param $coupon_id
     * @parma $nickname
     * @param $openid
     * @return bool
     */
    public function getCoupon($coupon_id,$nickname,$subscribe,$headimgurl){
        $openid = $this->openid;
        try{
            if(!$coupon_id || !$openid){
                throw new Exception('参数不存在');
            }
            //保存到用户红包记录表
            $userCouponModel    =   new UserCoupon();
            $couponsModel       =   new Coupons();
            $couponModel        =   $this->_getCouponModel($coupon_id); //couponsModel
            $couponRuleModel    =   $this->_getCouponRuleModel($couponModel->coupon_rule_id); //couponsRuleModel

            //固定时间
            if($couponRuleModel->type==2){
                $var['start_time']  =   $couponRuleModel->start_time;
                $var['expire_time'] = $couponRuleModel->end_time-1;
                $var['available_dates'] =   $couponRuleModel->available_dates;
                //动态时间
            }elseif($couponRuleModel->type==1){
                $oneDay     =   60*60*24;
                $day        =   strtotime("today");
                $start_time =   $couponRuleModel->start_time;
                $end_time   =   $couponRuleModel->end_time;
                $s = $start_time+$day;
                $e = $start_time+$day+$end_time;
                while($e>$s){
                    $availables[] = date('md',$s);
                    $s+=$oneDay;
                }
                $var['available_dates'] =  implode(',',$availables);
                $var['start_time']  = $day+$couponRuleModel->start_time;
                $var['expire_time'] = $day+$couponRuleModel->start_time+$couponRuleModel->end_time-1;
            }

            $var['coupon_id']       =   $coupon_id;
            $var['brand_ctl_id']    =   $this->brand_ctl_id;
            $var['store_ctl_id']    =   $this->store_ctl_id;
            $var['payment_id']      =   0;
            $var['openid']          =   $openid;
            $var['money']           =   $this->_getTotal($couponRuleModel->min_money,$couponRuleModel->max_money);
//            $var['expire_time']     =   $couponRuleModel->available_term ? $this->_returnLastTime($couponRuleModel->available_term) : $couponRuleModel->available_term ;
//            $var['available_dates'] =   $couponRuleModel->available_dates ? $couponRuleModel->available_dates : '';
            $var['condition']       =   $couponRuleModel->condition;
            $var['nickname']        =   $nickname;
            $var['coupon_type']     =   1 ;
            $var['created_time']    =   time();
            $var['status']          =   0;
            $var['subscribe']       =   $subscribe;
            $var['headimgurl']      =   $headimgurl;
            $var['say']             =   $this->say();
            $userCouponModel->attributes = $var;
            $userCouponModel->save();
            //红包表数量减少1
            $r = $couponsModel->findByPk($coupon_id);
            $r->total = $r->total-1;
            $r->save();
            return $userCouponModel;
        }catch (Exception $e){
            Yii::log($e->getMessage(),'error');
            return $e->getMessage();

        }

    }

    /**
     * 返回最后的过期时间
     * @param $available_term 秒数
     * @return int  过期时间
     */
    public function _returnLastTime($available_term){
        $one_day = 60*60*24;
        $date = date('Y-m-d',time());
        $today_start_time = strtotime($date);
        return $today_start_time+$available_term-$one_day;

    }

    /**
     * 获取用户红包列表
     * @openid 用户Openid $this->openid
     * @brand_ctl_id  品牌id $this->brand_ctl_id
     * @money 钱数,过滤不能使用的红包
     * @return 返回可用红包列表
     */
    public function couponList($money=0){
        if(empty($money)){
            return false;
        }
        $sql = "SELECT * FROM {{user_coupon}}
              WHERE `openid` = :openId AND  `condition` <=:Condition AND UNIX_TIMESTAMP(NOW()) < `expire_time`
              AND `available_dates` LIKE :date
              AND status = 0
              ORDER BY money DESC,expire_time ASC"
        ;
        $connection=Yii::app()->db;
        $command=$connection->createCommand($sql);
        $date       =   '%'.date('md').'%';
        $command->bindParam(":openId",$this->openid,PDO::PARAM_STR);
        $command->bindParam(":Condition",$money,PDO::PARAM_INT);
        $command->bindParam(":date",$date,PDO::PARAM_INT);
        $result = $command->queryAll();
        return $result;


    }

    /**
     * 个人中心红包列表 (可用的,不可用的)
     * @openid $this->openid
     */
    public function centerCouponList(){
        $openid     =   $this->openid;
        $models = UserCoupon::model()->findAll(array(
            'condition' =>  "openid='$openid'",
            'order'     =>  "money DESC,expire_time ASC"
        ));
        $list = array();
        foreach($models as $model){
            $result = $this->_is_expire_pass($model);
            if($result===true){
                $arr = $model->attributes;
                $arr['msg'] = '';
                $list['available'][] =   $arr;
            }elseif($result['status']=='error'){
                $arr = $model->attributes;
                $arr['msg'] = $result['msg'];
                $list['not_available'][] =   $arr;
            }
        }
        return $list;
    }


    /**
     *试算
     * @coupon_id 红包Id
     * @$total 商品折扣后总钱数
     * @return 红包抵扣多少钱,剩余需要支付多少钱，红包id
     * @todo 增加无红包id,返回红包id,否则返回0
     */
    public function preCount($total,$user_coupon_id=0){
        $var = array('deduction'=>0,'payment'=>$total,'coupon_id'=>0);
        $flag_bonus = ViewHelps::closeFounction($this->brand_ctl_id,'coupon');
        if($flag_bonus!=1){
            return $var;
        }
        if(empty($total)){
            Yii::log('total missing');
            return $var;
        }
        $userModel = UserCoupon::model()->findByPk($user_coupon_id);
        //无红包id或者已使用
        if($user_coupon_id==0 || $userModel->status!=0){
            $userModel = $this->getMax($total);
        }
        try{
            if(!$userModel){
                throw new Exception('红包不存在');
            }

            if($userModel->condition > $total){

                Yii::log('$userModel->condition '. $userModel->condition);
                Yii::log('$total'. $total);
                throw new Exception('金额不满足');
            }
            if($userModel->status !=0){
                throw new Exception('已使用');
            }
            $result = $this->_is_expire_pass($userModel);
            if($result['status']=='error'){
                throw new Exception($result['msg']);
            }
            if($userModel['money'] > $total){
                $var['deduction']   = $total;
                $var['payment']     = 0;
            }else{
                $var['deduction']   = $userModel['money'];
                $var['payment']     = $total-$userModel['money'];
            }

            $var['coupon_id']   =   $userModel['id'];
        }catch (Exception $e){
            $var['deduction']   = 0;
            $var['payment']     = $total;
            //此处coupon_id应该为0
            $var['coupon_id']   =   0;//$userModel['id'] ? $userModel['id'] : 0;
            Yii::log($e->getMessage(),'error');
        }
        return $var;

    }

    /**
     * 实扣 红包消掉
     * @coupon_id 红包Id
     * @total 商品折扣后总钱数
     * @payment_id 订单id
     * @return 红包抵扣多少钱,剩余需要支付多少钱
     */
    public function deduct($user_coupon_id,$total,$payment_id){
        Yii::log('$user_coupon_id ' .$user_coupon_id);
        Yii::log('$total ' .$total);
        Yii::log('$payment_id ' .$payment_id);
        //满足多少钱，是否过期，是否已经使用，
        $userModel = UserCoupon::model()->findByPk($user_coupon_id);
        if(empty($userModel)){
            Yii::log('红包不存在,原样返回数据','error');
            $var['deduction']   = 0;
            $var['payment']     = $total;
            return $var;
        }
        Yii::log('$userModel ' .var_export($userModel,true),'error');
        $info=$this->_is_expire_pass($userModel);
        Yii::log('deduct info ' . var_export($info,true),'error');
        if($info!==true){
            Yii::log($info['msg'],'error');
            $var['deduction']   = 0;
            $var['payment']     = $total;
            return $var;
        }
        if($userModel['money'] > $total){
            $var['deduction']   = $total;
            $var['payment']     = 0;
        }else{
            $var['deduction']   = $userModel['money'];
            $var['payment']     = $total-$userModel['money'];
        }
        Yii::log('params' .var_export($var,true),'error');

        //扣除红包
        $userModel->status =1;
        $userModel->payment_id  =   $payment_id;
        $userModel->save();
        Yii::log('params' .var_export($var,true),'error');
        Yii::log('save status ' .var_export($userModel,true),'error');
        return $var;
    }


    /**
     * 退款，红包还原
     * @param $payment_id 订单id
     */
    public function refund($payment_id){
        try{
            if(empty($payment_id))
                throw new Exception('参数丢失');
            $model = UserCoupon::model()->find('payment_id=:ID',array(':ID'=>$payment_id));
            if(empty($model)){
                return;
            }
            $model->status = 0;
            $model->payment_id = '';
            if(!$model->save()){
                throw new Exception('保存失败');
            }
        }catch (Exception $e){
            Yii::log($e->getMessage(),'error');
        }

    }


    /**
     * 获取就要过期，且最大金额的红包
     * @param  $money　消费金额
     * @return 返回获取的红包对象
     */
    public function getMax($money){

        $sql = "SELECT * FROM {{user_coupon}}
              WHERE `openid` = :openId AND  `condition` <=:Condition AND UNIX_TIMESTAMP(NOW()) < `expire_time`
              AND `available_dates` LIKE :date
              AND status = 0
              ORDER BY money DESC,expire_time ASC"
        ;
        $connection=Yii::app()->db;
        $command=$connection->createCommand($sql);
        $date       =   '%'.date('md').'%';
        $command->bindParam(":openId",$this->openid,PDO::PARAM_STR);
        $command->bindParam(":Condition",$money,PDO::PARAM_INT);
        $command->bindParam(":date",$date,PDO::PARAM_INT);
        $result = $command->queryRow();
        return $result;
    }

    /**
     * 获取有效期内的红包，可用的放最上面，不可用放下面
     */
    public function getConditionCoupon($money){
        if(!$money){
            Yii::log('total is null','error');
            return false;
        }
        $sql = "SELECT * FROM {{user_coupon}}
              WHERE `openid` = :openId AND  `condition` <=:Condition AND UNIX_TIMESTAMP(NOW()) < `expire_time`
              AND `available_dates` LIKE :date
              AND status = 0
              ORDER BY money DESC,expire_time ASC"
        ;
        $connection=Yii::app()->db;
        $command=$connection->createCommand($sql);
        $date       =   '%'.date('md').'%';

        $command->bindParam(":openId",$this->openid,PDO::PARAM_STR);
        $command->bindParam(":Condition",$money,PDO::PARAM_INT);
        $command->bindParam(":date",$date,PDO::PARAM_INT);
        $available = $command->queryAll();
        Yii::log($available,'error');
        $sql = "SELECT * FROM {{user_coupon}}
              WHERE `openid` = :openId AND  `condition` > :Condition AND UNIX_TIMESTAMP(NOW()) < `expire_time`
              AND `available_dates` LIKE :date
              AND status = 0
              ORDER BY money DESC,expire_time ASC"
        ;
        $connection=Yii::app()->db;
        $command=$connection->createCommand($sql);
        $date       =   '%'.date('md').'%';
        $command->bindParam(":openId",$this->openid,PDO::PARAM_STR);
        $command->bindParam(":Condition",$money,PDO::PARAM_INT);
        $command->bindParam(":date",$date,PDO::PARAM_INT);
        $notavailable = $command->queryAll();

        $list = array();
        foreach((array)$available as $row){
            $row['state'] = true;
            $list[] =   $row;

        }

        foreach((array)$notavailable as $row){
            $row['state'] = false;
            $list[] =   $row;
        }
        return $list;

    }

    /**
     * 生成随机数
     * @param $min　最小规则
     * @param $max　最大规则
     * @return int　返回红包数
     */
    public function _getTotal($min,$max){
        return mt_rand($min, $max);
    }



    /**
     * 返回coupons的实例
     * @param $id coupons的id
     * @return CActiveRecord 返回查询出来后的对象
     */
    public function _getCouponModel($id){
        return Coupons::model()->findByPk($id);
    }
    /**
     * 返回couponRule的实例
     * @param $id couponRule的id
     * @return CActiveRecord 返回查询出来后的对象
     */
    public function _getCouponRuleModel($id){
        return CouponRule::model()->findByPk($id);
    }

    /**
     * 使用红包
     * @param $userModel
     * @return bool
     */
    public function _is_expire_pass($userModel){
        $time       =   time();
        $m_d  =   date('md',$time);
        try{
            //可用红包
            if($userModel->expire_time){
                if(($userModel->expire_time) < time()){
                    throw new Exception('已过期');
                }
            }
            if($userModel->available_dates){
                if(strpos($userModel->available_dates,$m_d)===false){
                    throw new Exception('未到期');
                }
            }
            if($userModel->status>0){
                throw new Exception('已使用');
            }
            return true;
        }catch (Exception $e){
            return array(
                'status' => 'error',
                'msg'    => $e->getMessage(),
            );
        }

    }
    /**
     * 领取红包
     * @param $userModel
     * @return bool
     */
    public function _is_expire_pass_for_get($model){
        $time       =   time();
        $m_d  =   date('md',$time);

        try{
            if($model->available_term){
                if(($model->available_term+$model->available_term)<$m_d){
                    throw new Exception('已过期');
                }
            }elseif($model->available_dates){
                if(strpos($model->available_dates,$m_d)===false){
                    throw new Exception('今天暂不可领取');
                }
            }else{
                throw new Exception('条件丢失');
            }
            return true;
        }catch (Exception $e){
            Yii::log($e->getMessage(),'error');
            return false;
        }

    }

    /**
     * 返回最新一条的红包规则描述
     * @param $brand_id 品牌id
     * @return CActiveRecord 返回查询出来后的对象
     */
    public function getValidRuleDesc($brand_id=0){
        if(empty($brand_id)){
            return false;
        }
        $condition = array('condition'=>'brand_ctl_id=:brand_ctl_id',
            'params'=>array(':brand_ctl_id'=>$brand_id),
            'order'=>'id DESC',
            'limit'=>1
        );
        return CouponRuleDesc::model()->find($condition);
    }

    /**
     * 返回最新一条的红包规则
     * @param $brand_id 品牌id
     * @param $is_available 是否限制当前红包可用状态，默认为true
     * @return CActiveRecord 返回查询出来后的对象
     */
    public function getNewestRule($brand_id=0,$is_available=true){
        if(empty($brand_id)){
            return false;
        }
        $condition = array('condition'=>'brand_ctl_id=:brand_ctl_id',
            'params'=>array(':brand_ctl_id'=>$brand_id),
            'order'=>'id DESC',
            'limit'=>1
        );
        $coupon_rule = CouponRule::model()->find($condition);
        $coupon_rule = !empty($coupon_rule) ? $coupon_rule : false;
        //判断有效规则
        if($is_available == true && !empty($coupon_rule)){
            if($coupon_rule->status == 0 || ($coupon_rule->type == 2 && $coupon_rule->end_time < time())){
                return false;
            }
        }
        return $coupon_rule;
    }

    /**
     * 根据rule_id获取红包信息，如果红包规则已失效，则返回false
     * @param $brand_id 品牌id
     * @param $id 规则id
     * @return 规则不可用时，返回false，否则返回CActiveRecord 返回查询出来后的对象
     */
    public function getRuleById($rule_id=0){
        if(empty($rule_id)){
            return false;
        }
        $rule = CouponRule::model()->findByPk($rule_id);
        if($rule->status == 0){//规则状态为无效
            return false;
        }
        if($rule->type == 2 && time()>$rule->end_time){//规则已过期
            return false;
        }
        return $rule;
    }

    /**
     * 领取红包后，随机说一句话
     */
    public function say(){
        $array = array(
            1=>'红包在手，天下我有',
            2=>'红包抢得好，妈妈没烦恼',
            3=>'此包只应本店有，别店能有几回闻',
            4=>'最美不是小卡片，是买单时的红包券',
            5=>'世界你去看，红包我来抢',
        );
        $rand = rand(1,count($array));
        return $array[$rand];
    }

}
