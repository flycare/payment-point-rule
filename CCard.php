<?php
#define("CARD_TEMPLATE_ID","TM00483",TRUE);  //支付成功消息模板
/**
 * 用户储值卡类
 */
class CCard
{

    private $brand_ctl_id; //品牌id
    private $store_ctl_id; //门店id
    private $openid; //微信id
    private $special_brand_id = array(917,719); //优惠券可以门店间通用的品牌id

    public function __construct($openid = '',$brand_ctl_id = '',$store_ctl_id = ''){
        $this->brand_ctl_id = $brand_ctl_id;
        $this->store_ctl_id = $store_ctl_id;
        $this->openid = $openid;
    }

    public function get_special_brand_id(){
        return $this->special_brand_id;
    }

    public  function sendMessage(){
        if(in_array($this->brand_ctl_id,$this->special_brand_id)){
            Yii::log('特殊品牌，不发优惠券消息:brand_id:'.$this->brand_ctl_id,'error');
            return true;
        }
        $sql = "select count(id) as count from {{wx_card}} where ((store_id='".$this->store_ctl_id."' and get_store_type=1) or (brand_id='".$this->brand_ctl_id."' and store_id!='".$this->store_ctl_id."' and  get_store_type=2)) and current_quantity>0 and status=2";
        $connection = Yii::app()->db;
        $command = $connection->createCommand($sql);
        $count = $command->query()->read();
        if(empty($count['count'])){//没有卡券可领，不发推送
            return true;
        }
        $user = User::model()->find('openid=:openId',array('openId'=>$this->openid));
        if($user->subscribe!=1){
            Yii::log('用户未关注，暂时不中断，继续发送消息','error');
//            return false;
        }

        $domain = Tool::wapUrl();
        $control_store = Store::model()->findByAttributes(array('ctl_id'=>$this->store_ctl_id));
        $url = $domain.'/store/cardList/'.$control_store->id;
        /*$articles = array(
            array('title'=>'微信支付给你发优惠券啦','desc'=>'一大波优惠券袭来，快来领取吧','picurl'=>"http://mmbiz.qpic.cn/mmbiz/XQIJWxYnwF98ibV512McQjcRQmxp9F1St8TLMh4I8EDjGWcQf2Xa2bqFL9icMyvwOv3cxEn23hKm6Tic7r4Z2ubvw/0",'url'=>$url),
        );
        Yii::log('优惠券推送消息详情:'.var_export($articles,true),'error');
        return Weixin::api()->custom->news(array('to'=>$this->openid, 'items'=>$articles))->send();*/
        //卡券消息模板推送
        self::cardSend($url);
    }

    /**
     * @param $url
     */
    private function cardSend($url){
        $template_id = WxTemplateMessage::TemplateId($this->brand_ctl_id,CARD_TEMPLATE_ID);
        Yii::log("品牌编号： ".$this->brand_ctl_id." 直接从库里读取模板id: " . $template_id,'error');
        if(!$template_id){
            $res = Weixin::api()->template()->data(array('template_id_short'=>CARD_TEMPLATE_ID))->tm2tplid();
            if($res['errmsg']=='ok'){
                $template_id    =   $res['template_id'];
                Yii::log("品牌编号： ".$this->brand_ctl_id.'  从微信接口读取模板 template_id: ' . $template_id,'error');
                WxTemplateMessage::TemplateId($this->brand_ctl_id,CARD_TEMPLATE_ID,$template_id);
            }else{
                Yii::log("品牌编号： ".$this->brand_ctl_id.' 添加卡券通知模板失败：  ' . var_export($res,true),'error');
                return false;
            }
        }
        Yii::log('openid: '.$this->openid.' 订单号：'."品牌编号： ".$this->brand_ctl_id.'  卡券通知模板template_id：  ' . $template_id,'error');
        //拼接支付推送数据
        $data =self::templateData($this->openid,$template_id,$url);

        Yii::log("卡券推送前： ".$this->brand_ctl_id.' data: ' . var_export($data,1),'error');
        $res = Weixin::api()->template()->data($data)->send();
        Yii::log("卡券推送后： ".$this->brand_ctl_id.' res ' . var_export($res,1),'error');
        if($res['errcode'] == 43004){//如果推送失败，且失败原因为 用户未关注
            $key = 'CCard:cardSend:repeat';
            $cache = Yii::app()->redisCache;
            $fail_list = (array)$cache->get($key);
            $fail_list[$this->brand_ctl_id] = !empty($fail_list[$this->brand_ctl_id]) ? $fail_list[$this->brand_ctl_id] : array();
            $fail_list[$this->brand_ctl_id][] = array(
                'data' => $data
            );
            $cache->set($key,$fail_list,86400);
        }

    }
    /**
     * @param $payment
     * @param $openid
     * @param $template_id
     * @param $userModel
     * @return 卡券成功推送数据
     */
    private function templateData($openid,$template_id,$url){
        $color     =  '#000000';
        $topColor  =  '#4A90E2';
        $data = array(
            'topcolor"=>"#FF0000',
            'to'=>$openid,
            'template_id'=>$template_id,
            'url'=>$url,
            'data'=>array(
                'first'=>array(
                    'value'=>'恭喜你获得微信支付代金券，请点击领取',
                    'color'=>$topColor,
                ),
                'coupon'=>array(
                    'value'=>"看手气哦",
                    'color'=>$color,
                ),
                'expDate'=>array(
                    'value'=>"详见使用说明",
                    'color'=>$color,
                ),
            ),
        );
        return $data;
    }


    //根据oto_store表id获取附近的 29 家门店的新客消费优惠券列表id
    public function getNearCardListByStoreId($store_id=0,$is_redis=true){
        if(empty($store_id)){
            return false;
        }
        $redis_key = 'getNearCardListByStoreId:'.$store_id;
        if($is_redis && (false !== ($redis_res = Yii::app()->redisCache->get($redis_key)))){
            return $redis_res;
        }

        $brand_id = $this->brand_ctl_id;
        $sql_store = "select * from {{store}} where id='{$store_id}'";
        $connection = Yii::app()->db;
        $command = $connection->createCommand($sql_store);
        $store = $command->query()->read();
        $wxStore = WxStore::model()->find('store_id=:store_id',array('store_id'=>$store_id));
        if(empty($wxStore)){//微信门店不存在
            return false;
        }
        $userLat = $wxStore->lat;
        $userLng = $wxStore->lng;
        if(empty($store) || empty($userLat) || empty($userLng)){
            Yii::log('getNearCardListByStoreId:store表数据为空：id='.$store_id,'error');
            return false;
        }
        $sql = "SELECT distance,b.pay_pre,b.name,b.ctl_id,b.title from {{wx_card}} as c right JOIN (select * from
(SELECT ca.title,s.ctl_id,s.lat,s.lng,s.pay_pre,s.category_master,s.name,s.category_slave,ROUND((2 * 6378137* ASIN(
        SQRT(
        POW(SIN(PI()*({$userLat}-lat)/360),2)
        +
        COS(PI()*{$userLat}/180)* COS(lat * PI()/180)*POW(SIN(PI()*({$userLng}-lng)/360),2)
        )
        )
        ) )as distance FROM {{store}} as s left JOIN {{control}} as co on s.ctl_id = co.id LEFT JOIN {{category}} as ca on s.category_slave=ca.id where co.status=1 and s.id !={$store_id} and co.trade_id={$brand_id} and s.category_slave != {$store['category_slave']} ORDER BY distance ASC) as a  ORDER BY distance ASC) as b
on c.store_id=b.ctl_id where c.status=2 and c.get_store_type=2 and c.current_quantity>0 GROUP BY c.store_id ORDER BY distance ASC LIMIT 29";
        $command = $connection->createCommand($sql);
        $list = $command->queryAll();

        $res = array();
        if(!empty($list)){
            $in_store_id_ary = array();
            foreach($list as $v){
                $res[$v['ctl_id']] = $v;
                $in_store_id_ary[] = $v['ctl_id'];
            }
            $in_store_id = join(',',$in_store_id_ary);
            $sql = "select id,store_id from {{wx_card}} where store_id in ({$in_store_id}) and status=2 and get_store_type=2 and current_quantity>0";
            $command = $connection->createCommand($sql);
            $id_list = $command->queryAll();
            if(!empty($id_list)){
                foreach($id_list as $val){
                    if(isset($res[$val['store_id']])){
                        $res[$val['store_id']]['card_ids'][] = $val['id'];
                    }
                }
            }
            Yii::app()->redisCache->set($redis_key,$res,86400);
        }
        return $res;
    }

    //获取本门店下的二次消费优惠券id列表
    public function getCardListByCtlId($ctl_id=0,$is_redis=true){
        if(empty($ctl_id)){
            return false;
        }
        $redis_key = 'getCardListByCtlId:'.$ctl_id;
        if($is_redis && (false !== ($redis_res = Yii::app()->redisCache->get($redis_key)))){
            return $redis_res;
        }
        $model = new WxCard();
        $list = $model->findAll(
            array(
                'select' =>array('id'),
                'condition'=>'current_quantity>0 and status=2 and get_store_type=1 and store_id=:ctl_id',
//                'order'=>'create_time DESC',
                'params'=>array(':ctl_id'=>$ctl_id)
            )
        );
        if(empty($list)){
            return false;
        }
        foreach($list as $k=>$v){
            $list[$k] = $v['id'];
        }
        Yii::app()->redisCache->set($redis_key,$list,86400);
        return $list;
    }

    //获取单个优惠券详情
    public function getCardInfoById($id=0,$is_redis=true){
        if(empty($id)){
            return false;
        }
        $redis_key = 'getCardInfoById:'.$id;
        if($is_redis && (false !== ($redis_res = Yii::app()->redisCache->get($redis_key)))){
            return $redis_res;
        }
        $info = WxCard::model()->findByPk($id);
        if(empty($info)){
            return false;
        }
        Yii::app()->redisCache->set($redis_key,$info,86400);
        return $info;
    }

    //根据oto_store表id获取用户的本店优惠券列表  【缓存30分钟】  【目前只取一张】
    public function getUserCardListByStoreId($store_id=0,$openid='',$store_model=''){
        if(empty($store_id) || empty($store_model)){
            return false;
        }
        $redis_key = 'getUserCardListByStoreId:'.$store_id.':'.$openid;
        if(false !== ($redis_res = Yii::app()->redisCache->get($redis_key))){
            $info = $this->getCardInfoById($redis_res['id']);
            if($info->status==2 && $info->current_quantity>0){
                return $redis_res;
            }
            return array();
        }
        $_store_card_list = $this->getCardListByCtlId($this->store_ctl_id);

        //取出本门店的一条优惠券详情
        $store_card_info = array();
        if(!empty($_store_card_list)){
            $count = count($_store_card_list);
            $limit = max($count,5);
            $i = 0;
            //最多循环5次，取出可领取的优惠券
            $redis_key_store = 'getCardListByCtlId:'.$this->store_ctl_id;
            while($i<$limit){
                $index = $count > 1 ? rand(0,$count-1) : 0;
                $id = $_store_card_list[$index];
                $store_card_info = $this->getCardInfoById($id);
                if(empty($store_card_info)) {
                    //如果读不到info，可能是服务器问题，所以不将该数据剔除缓存
                    $i++;
                }elseif($store_card_info->current_quantity < 1){
                    //已领完的优惠券，需要从缓存出剔除
                    unset($_store_card_list[$index]);
                    sort($_store_card_list);
                    Yii::app()->redisCache->set($redis_key_store,$_store_card_list,86400);
                    $i++;
                }elseif($store_card_info->status != 2){//状态不是发放中
                    //暂时不处理
                    $i++;
                }else{
                    //优惠券可用，退出循环
                    break;
                }
            }
        }
        if(!empty($store_card_info)){
            $category = Category::model()->findByPk($store_model->category_slave);
            $store_card_info = array(
                'id' =>$store_card_info->id,
                'ctl_id' => $this->store_ctl_id,
                'name' => $store_model['name'],
                'title' => $category['title'],
                'distance' => 0,
                'pay_pre' => $store_model['pay_pre'],
                'color' => $store_card_info->color,
                'current_quantity' => $store_card_info->current_quantity,
                'card_id' => $store_card_info->card_id,
                'logo_url' => $store_card_info->logo_url,
                'card_title' => $store_card_info->title,
                'least_cost' => $store_card_info->least_cost,
                'reduce_cost' => $store_card_info->reduce_cost,
            );
        }
        //缓存30分钟
        Yii::app()->redisCache->set($redis_key,$store_card_info,1800);
        return $store_card_info;
    }

    //根据oto_store表id获取用户的优惠券列表  【缓存30分钟】
    public function getUserNearCardListByStoreId($store_id=0,$openid='',$page=1){
        if(empty($store_id)){
            return false;
        }
        $redis_key = 'getUserNearCardListByStoreId:'.$store_id.':'.$openid;
        if(false != ($redis_res = Yii::app()->redisCache->get($redis_key))){
            $near_card_list = $redis_res;
            $flag=1;
        }else{
            $_near_card_list = $this->getNearCardListByStoreId($store_id);
            //取出附近29张优惠券
            $near_card_list = array();
            if(!empty($_near_card_list)){
                $redis_key_near = 'getNearCardListByStoreId:'.$store_id;
                $i = 29;
                foreach($_near_card_list as $k=>$v){
                    if(empty($v['card_ids'])){
                        //没有优惠券，需要从缓存出剔除
                        unset($_near_card_list[$k]);
                        Yii::app()->redisCache->set($redis_key_near,$_near_card_list,86400);
                        continue;
                    }
                    if($i<1){
                        break;
                    }
                    $i-- ;
                    $count = count($v['card_ids']);
                    $index = $count > 1 ? rand(0,$count-1) : 0;
                    $id = $v['card_ids'][$index];
                    $info = $this->getCardInfoById($id);
                    if(empty($info)) {
                        //如果读不到info，可能是服务器问题，所以不将该数据剔除缓存
                        continue;
                    }elseif($info->current_quantity < 1){
                        //已领完的优惠券，需要从缓存出剔除
                        if($count == 1){
                            unset($_near_card_list[$k]);
                        }else{
                            unset($_near_card_list[$k]['card_ids'][$index]);
                            sort($_near_card_list[$k]['card_ids']);
                        }
                        Yii::app()->redisCache->set($redis_key_near,$_near_card_list,86400);
                    }elseif($info->status != 2){//状态不是发放中
                        //暂时不处理，直接跳过
                        continue;
                    }else{
                        $near_card_list[] = array(
                            'distance' => $v['distance'],
                            'id'=>$info->id,
                            'ctl_id' => $k,
                            'name' => $v['name'],
                            'title' => $v['title'],
                            'pay_pre' => $v['pay_pre'],
                            'color' => $info->color,
                            'current_quantity' => $info->current_quantity,
                            'card_id' => $info->card_id,
                            'logo_url' => $info->logo_url,
                            'card_title' => $info->title,
                            'least_cost' => $info->least_cost,
                            'reduce_cost' => $info->reduce_cost,
                        );
                    }
                }
            }
            //缓存30分钟
            Yii::app()->redisCache->set($redis_key,$near_card_list,1800);
        }
        if(empty($near_card_list)){
            return false;
        }
        //判断是否是从redis取出来的 是在剔除 暂停发放和已领完的
        if($flag==1){
            foreach($near_card_list as $keys=>$vals){
                $info = $this->getCardInfoById($vals['id']);
                if($info->status !=2 || $info->current_quantity<1){
                    unset($near_card_list[$keys]);
                }
                if(empty($near_card_list)){
                    return false;
                }

                sort($near_card_list);
            }
            //缓存30分钟
            Yii::app()->redisCache->set($redis_key,$near_card_list,1800);
        }
        $page = !empty($page) ? intval($page) : 1;
        if($page == 1){
            $r = @array_slice($near_card_list,0,9);
        }else{
            $num = 10;
            $offset = 9 + ($page-2)*$num;
            $r = @array_slice($near_card_list,$offset,$num);
        }

        return !empty($r) ? $r : false;
    }

    //获取用户在某品牌下，已经领取到的card_id列表
    public function getWxUserCardIdList($openid='',$brand_ctl_id=0,$is_redis=true){
        if(empty($openid) || empty($brand_ctl_id)){
            return false;
        }
        $redis_key = 'getWxUserCardIdList:'.$brand_ctl_id.':'.$openid;
        if($is_redis && (false !== ($redis_res = Yii::app()->redisCache->get($redis_key)))){
            return $redis_res;
        }


        $start_time = strtotime(date('Y-m-d'));
        $end_time=$start_time+86400;
        $criteria = new CDbCriteria;
        $criteria->addCondition("openid=:openid");
        $criteria->params['openid']=$openid;
        $criteria->addCondition("brand_id=:brand_id");
        $criteria->params['brand_id']=$brand_ctl_id;
        $criteria->addBetweenCondition('time', $start_time, $end_time);
        $usercard = WxUserCard::model()->findAll($criteria);
        //$usercard = WxUserCard::model()->findAllByAttributes(array('openid'=>$openid,'brand_id'=>$brand_ctl_id));

        $datacard = array();
        if(!empty($usercard)){
            foreach($usercard as $uc){
                $datacard[$uc['card_id']]=$uc['card_id'];
            }
        }
        //缓存1小时
        Yii::app()->redisCache->set($redis_key,$datacard,3600);
        return $datacard;
    }
    //获取公众号附近优惠券通用标准
    public function getBrandGeneralCardList($ctl_id,$page=1){

        $redis_key = 'getBrandGeneralCardList:'.$ctl_id;
        if(false !== ($redis_res = Yii::app()->redisCache->get($redis_key))){
            foreach($redis_res as $k=>$val){
                $info = $this->getCardInfoById($val['id']);
                if($info->status!=2 && $info->car_merchant_id<1){
                    unset($redis_res[$k]);
                    $status_res=1;
                }
            }
            if(empty($redis_res)){
                return false;
            }
                if (!empty($status_res)) {
                    $new_redis = array();
                    foreach ($redis_res as $kval) {
                        $new_redis[] = $kval;
                    }
                    unset($redis_res);
                    $redis_res = $new_redis;
                    unset($new_redis);
                }
            //sort($redis_res);
            Yii::app()->redisCache->set($redis_key,$redis_res,1800);
        }else{
            $redis_res = $this->getStoreCard($ctl_id);
        }
        $page = !empty($page) ? intval($page) : 1;
        $num = 10;
        $offset = ($page-1)*$num;
        $r = @array_slice($redis_res,$offset,$num);
        return $r;
    }
    public function getStoreCard($brand_id){

        if(empty($brand_id)){
            Yii::log('brand_id为空');
            return false;
        }
        $redis_key = 'getBrandGeneralCardList:'.$brand_id;
        $connection = Yii::app()->db;
        $card= array();
        $ss=array();
        $sql="select s.id,s.name,c.title,s.pay_pre,c.title,s.ctl_id from {{control}} as cr LEFT JOIN {{store}} as s on cr.id=s.ctl_id RIGHT JOIN {{wx_card}} as w on s.ctl_id=w.store_id LEFT JOIN {{category}} as c on c.id=s.category_slave where w.status=2 and get_store_type=2 and w.current_quantity>0 and w.brand_id={$brand_id} and s.isCard=1 and s.car_merchant_id>0 and cr.status=1";
        $command = $connection->createCommand($sql);
        $store_list = $command->queryAll();
        if(empty($store_list)){
            return false;
        }
        foreach($store_list as $sk=>$val){
            $ss[$val['ctl_id']]=$val;
        }
        $store_ls=count($ss);
        if($store_ls>30){
            $rand_store = array_rand($ss,30);
            $str_store = implode(',',$rand_store);
        }elseif($store_ls>1 && $store_ls<30){
            $rand_store = array_rand($ss,$store_ls);
            $str_store = implode(',',$rand_store);
        }else{
            $str_store = array_rand($ss,$store_ls);
        }

        $card_sql="select id,store_id,current_quantity,logo_url,id,card_id,color,title,least_cost,reduce_cost from {{wx_card}} where store_id in ({$str_store}) and status=2 and get_store_type=2 and current_quantity>0";
        $command = $connection->createCommand($card_sql);
        $card_list = $command->queryAll();
        $card_all=array();
        foreach($card_list as $ks=>$vs){
            $card_all[$vs['store_id']][]=$vs;
        }
        foreach($card_all as $keys=>$val){

            $cards = $val[array_rand($val,1)];
            $card[]=array(
                'id'=>$cards['id'],
                'name' => $ss[$cards['store_id']]['name'],
                'title' => $ss[$cards['store_id']]['title'],
                'pay_pre' => $ss[$cards['store_id']]['pay_pre'],
                'color' => $cards['color'],
                'current_quantity' => $cards['current_quantity'],
                'card_id' => $cards['card_id'],
                'logo_url' => $cards['logo_url'],
                'card_title' => $cards['title'],
                'least_cost' => $cards['least_cost'],
                'reduce_cost' => $cards['reduce_cost'],
            );

        }
        if(empty($card)){
            return false;
        }
        
        Yii::app()->redisCache->set($redis_key,$card,1800);
        return $card;
    }
    //通过用户当前坐标找到最近的门店坐标
    public function getUserStore($lat='',$lng='',$brand_id='',$page=1,$store_id=''){

        $connection = Yii::app()->db;
        if(empty($store_id)) {
            //最近的门店要小于10公里（10000米）
            $sql = "select * from (SELECT  s.lat,s.lng,s.id,s.ctl_id,((2 * 6378137* ASIN(
        SQRT(
        POW(SIN(PI()*({$lat}-lat)/360),2)
        +
        COS(PI()*{$lat}/180)* COS(lat * PI()/180)*POW(SIN(PI()*({$lng}-lng)/360),2)
        )
        )
        ) )as distance FROM {{store}} as s left JOIN {{control}} as co on s.ctl_id = co.id  where co.status=1 and co.trade_id={$brand_id} and s.isCard=1 and s.car_merchant_id>0 and s.lat!='' and s.lng !='') as s where s.distance<10000 ORDER BY s.distance ASC limit 1";

            $command = $connection->createCommand($sql);
            $store = $command->query()->read();
            if (empty($store)) {
                $r = $this->getBrandGeneralCardList($brand_id,$page);
                return $r;
            }
            $store_id = $store['id'];
        }
        //获取到最近的门店 获取门店的优惠券列表
        $redis_key='getStoreCardList:'.$store_id;
        $r['store_id']=$store_id;
        $res = Yii::app()->redisCache->get($redis_key);
        if(!empty($res)) {
            foreach ($res as $k => $val) {
                $info = $this->getCardInfoById($val['id']);
                if ($info->status != 2 && $info->car_merchant_id < 1) {
                    unset($res[$k]);
                    $status_res = 1;
                }
            }
            if(empty($res)){
                return false;
            }
            if(!empty($status_res)){
                $new_redis=array();
                foreach($res as $kval){
                    $new_redis[]=$kval;
                }
                unset($res);
                $res=$new_redis;
                unset($new_redis);
            }
            //sort($res);
            foreach($res as $val){
                $new_res[]=$val;
            }
            Yii::app()->redisCache->set($redis_key, $res, 1800);
        }
        if(empty($res)){
            $res = $this->getBrandCardList($store['lat'],$store['lng'],$store_id,$brand_id);
        }
        $page = !empty($page) ? intval($page) : 1;
        $num = 10;
        $offset = ($page-1)*$num;
        $r = @array_slice($res,$offset,$num);
        return $r;
    }
    //获取门店最近的30家门店的优惠券列表
    public function getBrandCardList($lat,$lng,$store_id,$brand_id){
        $redis_key='getStoreCardList:'.$store_id;
        $sql="SELECT b.pay_pre,b.name,b.ctl_id,b.title,c.store_id,b.lat,b.lng,distance from {{wx_card}} as c right JOIN (select * from
(SELECT ca.title,s.ctl_id,s.lat,s.lng,s.pay_pre,s.category_master,s.name,s.category_slave,ROUND((2 * 6378137* ASIN(
        SQRT(
        POW(SIN(PI()*({$lat}-lat)/360),2)
        +
        COS(PI()*{$lat}/180)* COS(lat * PI()/180)*POW(SIN(PI()*({$lng}-lng)/360),2)
        )
        )
        ) )as distance FROM {{store}} as s left JOIN {{control}} as co on s.ctl_id = co.id LEFT JOIN {{category}} as ca on s.category_slave=ca.id where co.status=1 and co.trade_id={$brand_id} and s.isCard=1 and s.car_merchant_id>0  ORDER BY distance ASC) as a  ORDER BY distance ASC) as b
on c.store_id=b.ctl_id where c.status=2 and c.get_store_type=2 and c.current_quantity>0  ORDER BY distance ASC LIMIT 30";
           $connection = Yii::app()->db;
           $command = $connection->createCommand($sql);
           //获取到最近的30家门店
           $store_list = $command->queryAll();
           $res= array();
           $stores=array();
           if(!empty($store_list)) {
               $in_store_id_ary= array();
               foreach ($store_list as $key => $val) {
                   $res[$val['store_id']] = $val;
                   $in_store_id_ary[] = $val['ctl_id'];
                   $stores[$val['store_id']]=$val;
               }
               //通过门店获取到门店下所有的优惠券
               $store_string=implode(',',$in_store_id_ary);
               $card_sql="select store_id,card_id,logo_url,least_cost,reduce_cost,current_quantity,color,id,title from {{wx_card}} where store_id in ({$store_string}) and current_quantity>0 and get_store_type=2 and status=2";
               $command = $connection->createCommand($card_sql);
               $card_list = $command->queryAll();
               if(!empty($card_list)){
                   foreach($card_list as $ks=>$vs){
                       $card_all[$vs['store_id']][]=$vs;
                   }
                   foreach($card_all as $k=>$v){
                       //随机获取一个优惠券
                       $cards = $v[array_rand($v,1)];
                       $res[$cards['store_id']]=array(
                           'id'=>$cards['id'],
                           'name' => $stores[$cards['store_id']]['name'],
                           'title' => $stores[$cards['store_id']]['title'],
                           'pay_pre' => $stores[$cards['store_id']]['pay_pre'],
                           'color' => $cards['color'],
                           'current_quantity' => $cards['current_quantity'],
                           'card_id' => $cards['card_id'],
                           'logo_url' => $cards['logo_url'],
                           'card_title' => $stores[$cards['store_id']]['title'],
                           'least_cost' => $cards['least_cost'],
                           'reduce_cost' => $cards['reduce_cost'],
                           'lat'=>$stores[$cards['store_id']]['lat'],
                           'lng'=>$stores[$cards['store_id']]['lng'],
                           'card_title'=>$cards['title'],
                       );
                   }
                   Yii::app()->redisCache->set($redis_key,$res,1800);
                   unset($card_list);
                   unset($cards);
               }
           }
           unset($store_list);
           return $res;
    }

    //根据消费金额，筛选出 满足最低使用门槛、金额最大、最接近有效期 的优惠券
    public function get_suitable_card($money=0){
        $result = array('card'=>0,'user_card_id'=>0,'remind'=>$money);
        if(empty($this->openid) || empty($money)){
            return $result;
        }
        if(empty(Yii::app()->site->account->auto_use_card)){
            return $result;
        }
        $store_id_sql = '';
        if(!in_array($this->brand_ctl_id,$this->special_brand_id)){
            $store_id_sql = " and create_store_id='{$this->store_ctl_id}' ";
        }
        $time = time();
        $sql = "select id,reduce_cost,card_id from {{wx_user_card}} where brand_id='{$this->brand_ctl_id}' $store_id_sql and begin_timestamp<'{$time}' and end_timestamp>'{$time}' and status=0 and least_cost<='{$money}' and openid='{$this->openid}' order by reduce_cost desc,end_timestamp asc limit 1";
        $connection = Yii::app()->db;
        $command = $connection->createCommand($sql);
        $card = $command->query()->read();
        if(empty($card)){
            Yii::log('没有可用的优惠券:openid:'.$this->openid.':brand_id:'.$this->brand_ctl_id.':money:'.$money,'info');
            return $result;
        }
        $result['remind'] = $money>$card['reduce_cost'] ? $money - $card['reduce_cost'] : 0;
        $result['card'] = $money>$card['reduce_cost'] ? $card['reduce_cost'] : $money;
        $result['card_real'] = $card['reduce_cost'];
        $result['user_card_id'] = $card['id'];
        $result['card_id_wx'] = $card['card_id'];//抛页面的时候，需要用到
        return $result;
    }
    /**
     * 实扣 消掉优惠券[先调用支付，后执行该方法]
     * @data 包含有user_card_id，payment_id，waiter_id等
     * @return true/false
     */
    public function deduct($data=array()){
        $res = array('status'=>0,'msg'=>'');
        if(empty($data['user_card_id'])){
            Yii::log('实扣阶段，未使用优惠券:data:'.var_export($data,1),'info');
            $res['status'] = 1;
            return $res;
        }
        $user_card = WxUserCard::model()->findByPk($data['user_card_id']);
        if(empty($user_card)){
            Yii::log('实扣阶段，未找到优惠券：user_card_id：'.$data['user_card_id'].':data:'.var_export($data,1),'error');
            $res['msg'] = '实扣阶段，未找到优惠券';
            return $res;
        }
        if($user_card->status != 0){
            Yii::log('实扣阶段,优惠券status非法：user_card_id：'.$data['user_card_id'].'status='.$user_card->status,'error');
            $res['msg'] = '实扣阶段，优惠券状态非法';
            return $res;
        }
        $user_card->status = 1;
        $user_card->use_time = time();
        $user_card->waiter_id = !empty($data['waiter_id']) ? $data['waiter_id'] : 0;
        $user_card->payment_id = !empty($data['id']) ? $data['id'] : '';
        $user_card->use_store_id = $this->store_ctl_id;
        if(!$user_card->save()){
            Yii::log('实扣阶段，修改优惠券状态失败：'.var_export($user_card,1),'error');
            $res['msg'] = '实扣阶段，修改优惠券状态失败';
            //return $res;//即使失败，也去微信核销卡券
        }

        //核销优惠券
        $consume_data = array(
            'code' => $user_card->card_code
        );
        Yii::log("核销优惠券的数据:brand_id={$this->brand_id}:store_id={$this->store_id}:data=" . var_export($consume_data,true),'info');
        $wx_res = Weixin::api()->card()->consume($consume_data);
        Yii::log("核销优惠券的结果:brand_id={$this->brand_id}:store_id={$this->store_id}:res_data=" . var_export($wx_res,true),'info');
        //优惠券状态异常
        if (empty($wx_res) || $wx_res['errcode'] != 0 || empty($wx_res['card']['card_id'])) {
            Yii::log("核销优惠券失败:brand_id={$this->brand_id}:store_id={$this->store_id}:card_code={$user_card->card_code}:res_data=" . var_export($wx_res,true),'error');
            $res['msg'] = '请求微信核销优惠券失败';
            return $res;
        }
        $res['status'] = 1;
        return $res;
    }
}