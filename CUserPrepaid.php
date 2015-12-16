<?php

/**
 * 用户储值卡类
 */
class CUserPrepaid
{

    private $brand_ctl_id; //品牌id
    private $store_ctl_id; //门店id
    private $openid; //微信id
    private $userPrepaid;

    public function __construct($openid = '',$brand_ctl_id = '',$store_ctl_id = ''){
        $this->brand_ctl_id = $brand_ctl_id;
        $this->store_ctl_id = $store_ctl_id;
        $this->openid = $openid;
    }
    
    /**
     * getUserPrepaid 获取用户当前储值详情
     * @param:string $openid  
     * @return:array       储值详情[id，openid，用户储值id，品牌id，储值余额]
     */
    public function getUserPrepaid($openid){
        if(empty($openid)){
            return false;
        }
        $condition = array('condition'=>'openid=:openid and brand_ctl_id=:brand_ctl_id',
                           'params'=>array(':openid'=>$openid,':brand_ctl_id'=>$this->brand_ctl_id)
                          );
        if($res = UserPrepaid::model()->find($condition)){
            return $res;
        }else{
            //插入新数据。考虑到可能数据库原因，读取失败，然后新数据会覆盖掉原有数据的情况，因此数据库中加了unique index，设定openid与brand_ctl_id 这一对值不可重复。
            $user_prepaid_id = $this->getUserPrepaidId($openid);
            
            $model = new UserPrepaid;
            $time = time();
            $data = array(
                'openid' => $openid,
                'user_prepaid_id' => $user_prepaid_id,
                'brand_ctl_id' => $this->brand_ctl_id,
                'balance' => 0,
                'create_time' => $time,
                'update_time' => 0
            );
            $model->attributes = $data;
            if($model->insert()){
                $data['id'] = Yii::app()->db->getLastInsertId();
                return $data;
            }else{
                return false;
            }
        }
    }
    
    public function getUserPrepaidId($openid){
        if(empty($openid)){
            return false;
        }
        $customer_info = Customer::model()->findByAttributes(array('openid'=>$openid,'brand_id'=>$this->brand_ctl_id));
        return $customer_info->customer_id;

//        preg_match_all('/.{2}/i',md5($openid),$matches);
//        $user_prepaid_id = '';
//        foreach ($matches[0] as $v){
//            $v = base_convert($v,36,10);
//            $user_prepaid_id .= $v%10;
//        }
//        return $user_prepaid_id;
    }
    
    //生成条形码
    public function createBarCodeImage($user_prepaid_id){
        if(empty($user_prepaid_id)){
            return false;
        }
        $_path = Yii::getPathOfAlias('shared');
        require_once($_path.'/components/barcodegen/class/BCGColor.php');
        require_once($_path.'/components/barcodegen/class/BCGDrawing.php');
        require_once($_path.'/components/barcodegen/class/BCGcode128.barcode.php');
        
        ob_end_clean();
        //生成条形码
        $color_black = new BCGColor(0, 0, 0);
        $color_white = new BCGColor(255, 255, 255);
        $drawException = null;
        try {
        	$code = new BCGcode128();
        	$code->setScale(4); // Resolution
        	$code->setThickness(32); // Thickness
        	$code->setForegroundColor($color_black); // Color of bars
        	$code->setBackgroundColor($color_white); // Color of spaces
        	$code->setFont(0); // $font/0
        	$code->parse($user_prepaid_id); // Text
        } catch(Exception $exception) {
        	$drawException = $exception;
        }
        
        $drawing = new BCGDrawing('', $color_white);
        if($drawException) {
        	$drawing->drawException($drawException);
        } else {
        	$drawing->setBarcode($code);
        	$drawing->draw();
        }
        header('Content-Type: image/png');
        $drawing->finish(BCGDrawing::IMG_FORMAT_PNG);
    }
    
    //生成二维码
    public function createQrCodeImage($user_prepaid_id,$openid){
        if(empty($user_prepaid_id)){
            return false;
        }
        //生成二维码图片
        $user_prepaid_id = (string)$user_prepaid_id;
        
        QRcode::png($user_prepaid_id, false, 'L', 13.34, false);
    }
    
    /**
     * getUserPrepaidHistoryByOrderId 根据order_id获取该订单的储值/消费记录
     * @param:string $order_id  订单id
     * @return:array       储值记录
     */
    public function getUserPrepaidHistoryByOrderId($order_id=''){
        if(empty($order_id)){
            return false;
        }
        $condition = array('condition'=>'order_id=:order_id',
                           'params'=>array(':order_id'=>$order_id)
                          );
        return UserPrepaidHistory::model()->find($condition);
    }
    
    /**
     * getUserPrepaidHistory 根据不同条件获取相关的储值/消费记录
     * @param:array $conditions  array('openid'=>mixed,'store_ctl_id'=>$store_ctl_id,'create_time'=>array('start'=>$start,'end'=>$end))
     * @param:int    $page_size  需要分页时使用，每页条数
     * @return:array array('list'=>$list,'total'=>$total);            $list=>储值记录,$total=>总条数
     */
    public function getUserPrepaidHistory($conditions='',$page_size=0){
        if(empty($this->brand_ctl_id)){
            return false;
        }
        $model = new UserPrepaidHistory();
        $criteria = new CDbCriteria;
        //openid
        if (isset($conditions['openid']) && !empty($conditions['openid'])) {
            if (is_array($conditions['openid'])) {
                $criteria->addInCondition('openid', $conditions['openid']);
            } else {
                $criteria->addCondition('openid=:openid');
                $criteria->params['openid'] = $conditions['openid'];
            }
        }
        //品牌id
        $criteria->addCondition('brand_ctl_id=:brand_ctl_id');
        $criteria->params['brand_ctl_id'] = $this->brand_ctl_id;
        
        //门店id
        if (isset($conditions['store_ctl_id']) && !empty($conditions['store_ctl_id'])) {
            $criteria->addCondition('store_ctl_id=:store_ctl_id');
            $criteria->params['store_ctl_id'] = $conditions['store_ctl_id'];
        }
        //时间间隔
        if (isset($conditions['create_time']) && !empty($conditions['create_time'])) {
            $criteria->addBetweenCondition('create_time', $conditions['create_time']['start'], $conditions['create_time']['end']);
        }
        $criteria->order = 'id DESC';
        //分页
        if(!empty($page_size)){
            //查询总条数
            $total = $model->Count($criteria);
            //分页开始
            $_GET['page'] = Yii::app()->request->getParam('page');;
            $pages = new CPagination($total);
            $pages->pageSize = $page_size;
            $pages->applyLimit($criteria);
        }
        $list = $model->findAll($criteria);
        $total = !empty($total) ? $total : count($list);
        return array('list'=>$list,'total'=>$total);
    }
    
    /**
     * spendUserPrepaid 消费用户储值
     * @param  string $operate  操作类型，pre=>预算， 其他为正式消费。默认为正式消费
     * @param  bool $is_beginTransaction  是否开启事务，默认不开启
     * @param  float $price 消费的金额
     * @param  string $order_id 订单id
     * @param  string $desc 描述
     * @return array        操作结果 status=>true||false,msg=>错误信息,new_price=>扣除用户储值之后剩下的金额,spend_prepaid=>本次消费的储值金额
     */
    public function spendUserPrepaid($operate='', $is_beginTransaction=false, $price=0, $order_id = '', $desc = '消费'){
        $res = array('status'=>false,'msg'=>'','new_price'=>0,'spend_prepaid'=>0);
        if(empty($this->openid)){
            $res['msg'] = 'openid不存在';
            $res['new_price'] = $price;
            return $res;
        }
        if($price == 0){
            $res['status'] = true;//为使支付程序进行下去，故返回true
            $res['spend_prepaid'] = 0;
            $res['new_price'] = $price;
            return $res;
        }
        $this->userPrepaid = $this->getUserPrepaid($this->openid);
        if(empty($this->userPrepaid) || empty($this->userPrepaid->balance) || $this->userPrepaid->balance == 0){
            $res['status'] = true;//为使支付程序进行下去，故返回true
            $res['spend_prepaid'] = 0;
            $res['new_price'] = $price;
            return $res;
        }
        $price = Tool::numberFormat($price);
        $this->userPrepaid->balance = Tool::numberFormat($this->userPrepaid->balance);
        if($price > $this->userPrepaid->balance){
            $res['new_price'] = $price - $this->userPrepaid->balance;//剩余的金额
            $res['spend_prepaid'] = $this->userPrepaid->balance;//消费掉的储值金额
            $this->userPrepaid->balance = 0;
        }else{
            $this->userPrepaid->balance = $this->userPrepaid->balance - $price;
            $res['new_price'] = 0;
            $res['spend_prepaid'] = $price;
        }
        $this->userPrepaid->balance = Tool::numberFormat($this->userPrepaid->balance);
        //预算结束
        if($operate == 'pre'){
            $res['status'] = true;
            return $res;
        }
        //数据处理
        $this->userPrepaid->update_time = time();
        if(!empty($is_beginTransaction)){//开启事务
            $transaction = Yii::app()->db->beginTransaction();
        }
        try {
            Yii::log("spend_prepaid=========" . $res['spend_prepaid'], 'error');
            Yii::log("balance=========" . $this->userPrepaid->balance, 'error');
            if ($this->userPrepaid->save()) {
                //储值消费记录
                $data = array(
                    'openid' => $this->openid,
                    'brand_ctl_id' => $this->brand_ctl_id,
                    'store_ctl_id' => $this->store_ctl_id,
                    'price' => $res['spend_prepaid'],
                    'real' => $res['spend_prepaid'],
                    'type' => 0,
                    'pay_type' => 0,
                    'desc' => $desc,
                    'order_id' => $order_id,
                    'status' => 1
                );
                if (empty($res['spend_prepaid']) || $this->addUserPrepaidHistory($data)){
                    $res['status'] = true;
                }
            }
            if(!empty($is_beginTransaction)){//事务提交
                $transaction->commit();
            }
        } catch (Exception $e) {
            Yii::log($e->getMessage(), 'error');
            Yii::log(debug_print_backtrace(), 'error');
            if(!empty($is_beginTransaction)){//事务回滚
                $transaction->rollback();
            }
        }
        unset($this->userPrepaid);
        return $res;
    }
    
    /**
     * addUserPrepaidHistory 增加用户储值记录【微信支付时，先增加一条记录，设置status=0，当支付成功时，设置status=1】
     * @param  array $params  包含以下信息：
     *         string openid   openid【必须】
     *         int brand_ctl_id   品牌id【必须】
     *         int store_ctl_id   门店id【必须】
     *         float price   需要支付的金额/需要消耗的储值金额【必须】
     *         float real    用户实际 获得/消耗 的储值金额【必须】
     *         float rule_id 本次充值用到的prepaid_rule表规则id
     *         int type      0消费，1充值【必须】
     *         int pay_type   支付方式，0微信支付，1现金，2刷卡
     *         int opt_waiter_id   后台操作服务员id
     *         string desc 描述
     *         float order_id 订单id
     *         int status     该条储值是否有效【微信支付预处理时为无效，微信支付成功时设置有效，现金、刷卡等后台操作时为有效】
     * @return bool          操作结果true||false
     */
    public function addUserPrepaidHistory($params=array()){
        if(empty($params['openid']) || empty($params['brand_ctl_id'])){
            return false;
        }
        $params['create_time'] = !empty($params['create_time']) ? $params['create_time'] : time();
        $UserPrepaidHistory = new UserPrepaidHistory;
        $UserPrepaidHistory->attributes = $params;
        if ($UserPrepaidHistory->save()){
            return true;
        }else{
            return false;
        }
    }

     /**
     * addUserPrepaid 增加用户储值
     * @param  string openid   openid【必须】
     * @param  float $price   需要支付的金额/需要消耗的储值金额【必须】
     * @param  float $real   用户实际 获得/消耗 的储值金额【必须】
     * @param  float $rule_id 本次充值用到的prepaid_rule表规则id
     * @param  float $order_id 订单id
     * @param  int $pay_type   支付方式，0微信支付，1现金，2刷卡
     * @param  int $opt_waiter_id   后台操作服务员id
     * @param  string $desc 描述
     * @return bool          操作结果true||false
     */
    public function addUserPrepaid($is_beginTransaction=false,$openid=0,$price=0,$real=0,$rule_id=0,$order_id='',$pay_type=0,$opt_waiter_id=0,$desc=''){
        if(empty($openid) || empty($real)){
            return false;
        }
        if($real == 0 && $price == 0){
            return true;
        }
        $time = time();
        $this->userPrepaid = $this->getUserPrepaid($openid);
        $this->userPrepaid->balance += floatval($real);
        $this->userPrepaid->update_time = $time;
        if(!empty($is_beginTransaction)){//开启事务
            $transaction = Yii::app()->db->beginTransaction();
        }
        try{
            if(!$this->userPrepaid->save()){
                throw new CException('增加用户储值失败');
            }
            if(($real == 0 || $real == 0.00) && ($price == 0 || $price == 0.00)){
                return true;
            }
            //后台操作，现金/刷卡 进行储值
            $data = array(
                'openid' => $openid,
                'brand_ctl_id' => $this->brand_ctl_id,
                'store_ctl_id' => $this->store_ctl_id,
                'price' => $price,
                'real' => $real,
                'rule_id' => $rule_id,
                'type' => 1,
                'pay_type' => $pay_type,
                'opt_waiter_id' => $opt_waiter_id,
                'desc' => $desc,
                'order_id' => $order_id,
                'status' => 1,
                'create_time' => $time
            );
            if (!$this->addUserPrepaidHistory($data)){
                throw new CException('创建储值历史记录失败');
            }
            if(!empty($is_beginTransaction)){//开启事务
                $transaction->commit();
            }
        } catch (Exception $e) {
            Yii::log($e->getMessage(), 'error');
//            Yii::log(debug_print_backtrace(), 'error');
            if(!empty($is_beginTransaction)){//开启事务
                $transaction->rollback();
            }
            return false;
        }
        return true;
    }
    
    public function addPrepaid($is_beginTransaction=false,$openid=0,$rule_id=0,$pay_type=0,$opt_waiter_id=0,$desc=''){
        if(empty($openid)){
            return false;
        }
        $condition = array('condition'=>'id=:rule_id and status=1',
                           'params'=>array(':rule_id'=>$rule_id)
                          );
        $rule_info = PrepaidRule::model()->find($condition);
        if(empty($rule_info)){//储值规则不存在
            Yii::log('储值规则不存在:'.$rule_id, 'error');
            return false;
        }
        
        $user_prepaid_info = $this->getUserPrepaid($openid);
	    if(empty($user_prepaid_info)){//用户储值信息不存在
	        Yii::log('用户储值信息不存在:'.$openid, 'error');
	        return false;
	    }
        return $this->addUserPrepaid($is_beginTransaction,$openid,$rule_info['price'],$rule_info['real'],$rule_id,'',$pay_type,$opt_waiter_id,$desc);
    }
    
    public function getPrepaidRulePrice($rule_id){
        if(empty($rule_id)){
            return false;
        }
        $condition = array('condition'=>'id=:rule_id and status=1',
                           'params'=>array(':rule_id'=>$rule_id)
                          );
        $rule_info = PrepaidRule::model()->find($condition);
        return !empty($rule_info) && !empty($rule_info['price']) ? $rule_info['price'] : false;
    }

}