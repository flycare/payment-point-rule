<?php

/**
 * 积分规则和兑换计算
 */
class CRule
{
    private $brand_ctl_id;
    private $store_ctl_id;
    private $default_rule;

    public function __construct($store_ctl_id = '')
    {
        $this->brand_ctl_id = Yii::app()->site->account->ctl_id;
        $this->store_ctl_id = $store_ctl_id;
        $this->default_rule = Yii::app()->params['table']['rule'];
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
     * moveToHistory description
     * @param  object $rule oto_point_rule表单条数据对象
     * @return bool                    rule_history是否正确创建
     */
    public function moveToHistory($rule)
    {
        $res = array('status' => false, 'msg' => '');
        $end_time = time();

        $ruleHistory = new RuleHistory;
        $data = array();
        $data['brand_ctl_id'] = $rule['brand_ctl_id'];
        $data['store_ctl_id'] = $rule['store_ctl_id'];
        $data['rule'] = $rule['rule'];
        $data['create_time'] = $rule['create_time'];
        $data['end_time'] = $end_time;
        $ruleHistory->attributes = $data;
        if ($ruleHistory->save()) {
            $res['status'] = true;
        }
        return $res;
    }

    public function initBrandRule($rule)
    {
        $res = array('status' => false, 'msg' => '');
        $brandRule = new PointRule();
        $data = array();
        $data['brand_ctl_id'] = $this->brand_ctl_id;
        $data['rule'] = CJSON::encode($rule);
        $data['create_time'] = time();
        $brandRule->attributes = $data;
        if ($brandRule->save()) {
            $res['status'] = true;
        }
        return $res;
    }

    /**
     * initStoreRule  初始化门店规则数据
     * @return bool   是否成功
     */
    public function initStoreRule($rule)
    {
        $res = array('status' => false, 'msg' => '');
        $storeRule = new PointRule();
        $data = array();
        $data['brand_ctl_id'] = $this->brand_ctl_id;
        $data['store_ctl_id'] = $this->store_ctl_id;
        $data['rule'] = CJSON::encode($rule);
        $data['create_time'] = time();
        $storeRule->attributes = $data;
        if ($storeRule->save()) {
            $res['status'] = true;
        }else{
            $res['status'] = false;
        }
        return $res;
    }

    /**
     * initStoreRule  初始化门店规则数据
     * @param $rule array 规则数据
     * @return bool   是否成功
     */
    public function updateBrandRule($rule)
    {
        $res = array('status' => false, 'msg' => '');
        $brandRule = PointRule::model()->find("brand_ctl_id={$this->brand_ctl_id} AND store_ctl_id is NULL");
        if (empty($brandRule)) {
            $r = self::initBrandRule($rule);
            if ($r['status'] == false) {
                return $res;
            }

        } else {
            $brandRule->rule = CJSON::encode($rule);
            if ($brandRule->save()) {
                $stores = ControlHelps::getStoresCount($this->brand_ctl_id, 'brand');
                foreach ($stores as $store) {
                    $this->store_ctl_id = $store['id'];
                    $storeRule = PointRule::model()->find("brand_ctl_id={$this->brand_ctl_id} AND store_ctl_id={$store['id']}");
                    if (empty($storeRule)) {
                        $tempRule = $this->default_rule;
                        $tempRule['point_rule'] = $rule['point_rule'];
                        $r = self::initStoreRule($tempRule);
                        if($r['status'] == false){
                            $res['msg'] = '初始化门店规则数据失败';
                            return $res;
                        }
                    } else {
                        $r = self::moveToHistory($storeRule);
                        if($r['status'] == false) {
                            $res['msg'] = '旧数据保存到历史数据失败';
                            return $res;
                        }
                        $tempRule = CJSON::decode($storeRule->rule);
                        $tempRule['point_rule'] = $rule['point_rule'];
                        $storeRule->rule = CJSON::encode($tempRule);
                        if(!$storeRule->save()){
                            $res['msg'] = '门店规则保存失败';
                            return $res;
                        }
                    }
                }
            }
        }
        $res['status'] = true;
        return $res;
    }

    public function updateStoreRule($rule,$store_ctl_id)
    {
        $res = array('status' => false, 'msg' => '');
        $this->store_ctl_id = $store_ctl_id;
        $storeRule = PointRule::model()->find("brand_ctl_id={$this->brand_ctl_id} AND store_ctl_id={$this->store_ctl_id}");
        if (empty($storeRule)) {
            $tempRule = $this->default_rule;
            $tempRule['discount_rule'] = $rule['discount_rule'];
            $r = self::initStoreRule($tempRule);
            if ($r['status'] == false) {
                return $r;
            }
        } else {
            $r = self::moveToHistory($storeRule);
            if($r['status'] == false)
                return $r;
            $tempRule = CJSON::decode($storeRule->rule);
            $tempRule['discount_rule'] = $rule['discount_rule'];
            $storeRule->rule = CJSON::encode($tempRule);
            if(!$storeRule->save()){
                $res['msg'] = '门店规则保存失败';
                return $res;
            }
        }
        $res['status'] = true;
        return $res;
    }

    /**
     * getDiscountRule 获取优惠规则
     * @param $role string 角色类型，品牌或门店
     * @return array 规则数据
     */
    public function getRule($role = 'store')
    {
        $rules = array();
        $rules['point_rule'] = $this->_getPointRule($role);
        $rules['discount_rule'] = $this->_getDiscountRule($role);
        return $rules;
    }

    /**
     * _getRule 查询品牌或门店规则
     * @param $role string 角色类型，品牌或门店
     */
    private function _getRule($role = "store")
    {
        $key = $role . '_ctl_id';
        if ($role == 'brand')
            $rule = PointRule::model()->find($key . "=:ctl_id AND store_ctl_id=:store_ctl_id", array(':ctl_id' => $this->$key, ':store_ctl_id' => NULL));
        else
            $rule = PointRule::model()->find($key . "=:ctl_id", array(':ctl_id' => $this->$key));
        $this->rule = CJSON::decode($rule['rule']);
    }

    /**
     * _getPointRule 获取积分优惠规则
     * @param $role string 角色类型，品牌或门店
     * @return [type] [description]
     */
    private function _getPointRule($role = "store")
    {
        if (empty($this->rule))
            $this->_getRule($role);
        if (empty($this->rule["point_rule"]))
            $pointRule = $this->default_rule['point_rule'];
        else
            $pointRule = $this->rule['point_rule'];
        return $pointRule;
    }

    /**
     * _getOffSaleRule 获取满减优惠规则
     * @param $role string 角色类型，品牌或门店
     * @return array 满减规则数组
     */
    private function _getDiscountRule($role = "store")
    {
        if (empty($this->rule))
            $this->_getRule($role);
        if (empty($this->rule['discount_rule']))
            $discountRule = $this->default_rule['discount_rule'];
        else
            $discountRule = $this->rule['discount_rule'];
        return $discountRule;
    }
}

