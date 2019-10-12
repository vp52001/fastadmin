<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/10/12
 * Time: 15:37
 */

namespace app\api\controller;


use think\Controller;

class Test extends Controller
{


    public function doalipay() {

        $userid = session("userid");
        $money = input('post.money');
        $alipay = input('post.alipay');
        $alipay_name = input('post.alipay_name');


        if (empty($alipay) || empty($alipay_name) || empty($money)) {
            return json(['status' => 0, 'info' => '信息填写不完整', 'data' => '']);
        }

        if(!is_numeric($money)){
            return json(['status' => 0, 'info' => '申请提现金额异常', 'data' => '']);
        }

        //检查用户是否存在
        $Tuser = model("Tuser");
        $tuser = $Tuser->where(array("id" => $userid))->find();
        if (empty($tuser)) {
            return json(['status' => 0, 'info' => '内部错误', 'data' => '']);
        }

        $innerUserGroup = array('42295','46826','125879');
        if (in_array($userid, $innerUserGroup)) {
            return json(['status' => 0, 'info' => '该用户组不允许提现', 'data' => '']);
        }

        if (!$tuser['phone']) {
            return json(['status' => 0, 'info' => '请先绑定手机', 'data' => '']);
        }

        if ($tuser['status'] == 1) {
            return json(['status' => 0, 'info' => '您的设备存在问题，请联系管理员', 'data' => '']);
        }
        if ($tuser['status'] == 2) {
            return json(['status' => 0, 'info' => '您的设备存在问题，请联系管理', 'data' => '']);
        }
        $Device = model("Device");
        $device = $Device->where(array("tuser_id" => $tuser['id']))->find();
        if (empty($device)) {
            return json(['status' => 0, 'info' => '参数错误', 'data' => '']);
        }

        if (in_array($device['batteryid'], config("CHANELS"))) {
            return json(['status' => 0, 'info' => '找管理员吧', 'data' => '']);
        }
        $TuserGetcash = model("TuserGetcash");
        $tuserGetcash = $TuserGetcash->where(array("tuser_id" => $tuser['id'], "compote" => 0))->find();
        if (!empty($tuserGetcash)) {
            return json(['status' => 0, 'info' => '您有提现中的操作', 'data' => '']);
        }
        $Alipay_qc = model("TuserAlipayQc");
        //用户支付宝信息排重   1 有另一个账号提交申请，但是未完成 ， 2另一个账号申请提现 成功  不同大虾账号用同一个支付宝信息
        $qc_ret = $Alipay_qc->where(array("alipay_name" => $alipay_name, "alipay_account" => $alipay))->field("tuser_id,bind")->find();
        if($qc_ret != null && $qc_ret['tuser_id'] != $userid) {
            #排重失败，抛错误
            return json(['status' => 0, 'info' => '该支付宝账号已被其他账户使用，请使用其他支付宝账号', 'data' => '']);
        }

        $alipay_qc = $Alipay_qc->where('tuser_id = '.$tuser['id'])->field("alipay_name,alipay_account,bind")->find();

        if($alipay_qc != null) {
            #查找该用户最近一条提现记录，观察是否提现成功 打款
            $is_bind = $TuserGetcash->where(array("tuser_id" => $tuser['id'], "compote" => 1))->field('alipay,alipay_name,alipay_json')->order('id desc')->find();
            $bindtype = 2;
            #判断打款后的记录和排重记录 名称 账号是否一致， 一致则 绑定完成
            if ($is_bind != null) {
                if (($is_bind['alipay'] == $alipay_qc['alipay_account']) && ($is_bind['alipay_name'] == $alipay_qc['alipay_name'])) {
                    $issuccess = substr($is_bind['alipay_json'], 58, 1);
                    if ($issuccess == 1) {
                        Db::table('tuser_alipay_qc')->where(array("tuser_id" => $tuser['id']))->update(array('bind' => 2));
                        $bindtype = 2;
                    }
                    if ($issuccess == 4) {
                        Db::table('tuser_alipay_qc')->where(array("tuser_id" => $tuser['id']))->update(array('bind' => 0));
                        $bindtype = 0;
                    }
                }
            }
            #判断 该用户已存在的排重记录 名称和账号是否一致          同一大虾账号 用不同支付宝信息 bind=0可用用新支付宝信息
            if($alipay_qc['alipay_name'] != $alipay_name || $alipay_qc['alipay_account'] != $alipay)
            {
                if($bindtype == 2)
                {
                    return json(['status' => 0, 'info' => '该支付宝账号与您绑定的不符', 'data' => '']);
                }
                if($bindtype == 0)
                {
                    #修改排重库 alipay_name alipay_account
                    Db::table('tuser_alipay_qc')->where(array("tuser_id" => $tuser['id']))->update(
                        array(
                            'alipay_name' => $alipay_name,
                            'alipay_account' => $alipay,
                            'bind' => 1
                        )
                    );
                }
            }
        }

        #如果支付宝排重结果为空，则添加排重库数据， 不为空并且 tuserid 等于排重库tuser_id 则无事发生
        if($alipay_qc == null) {
            //新添记录
            $Alipay_qc->insert(array(
                "alipay_name" => $alipay_name,
                "alipay_account" => $alipay,
                "tuser_id" => $userid,
                "time" => NOW_TIME
            ));
        }
        #现在打款用的不是tuser表的alipay字段，用的TuserGetCash，不过这里还是同步一下
        $Tuser->where(array("id" => $tuser['id']))->update(array(
            "alipay" => $alipay,
            "alipay_name" => $alipay_name
        ));

        //手续费
        $fee = $money==10?1:0;
        if ($money < 10 || $tuser['balance'] < $money+$fee) {
            return json(['status' => 0, 'info' => '余额不足', 'data' => '']);
        }
        if ($tuser['reg_time'] > NOW_TIME - 3600 * 24) {
            return json(['status' => 0, 'info' => '新用户需一天后才能提现', 'data' => '']);
        }

        $adddata = array();
        $adddata['money'] = $money;
        $adddata['cast_money'] = $money;
        $adddata['tuser_id'] = $tuser['id'];
        $adddata['cash_type'] = 2;
        $adddata['status'] = 1;
        $adddata['submit_time'] = NOW_TIME;
        $adddata['type'] = 2;
        $adddata['alipay'] = $alipay;
        $adddata['alipay_name'] = $alipay_name;

        //$adddata['account_name'] = $wxname;
        $Tuser->startTrans();
        $tuser = $Tuser->where(array("id" => $tuser['id']))->lock(true)->find();
        if ($tuser['balance'] < $money+$fee) {
            $Tuser->rollback();
            return json(['status' => 0, 'info' => '余额不足', 'data' => '']);
        }
        $TuserGetcash->insert($adddata);
        $Tuser->where(array("id" => $tuser['id']))->setDec("balance", $money+$fee);


        #当前余额
        $now_balance = $Tuser->where(array('id' => $tuser['id']))->field("balance")->find();

        model("TuserRecharge")->insert(
            array("time" => NOW_TIME,
                "money" => -$money,
                "tuser_id" => $tuser['id'],
                #"description" => "支付宝取现" . $money . "元",
                "description" => "支付宝提现",
                "source" => "dxsw",
                "balance" => $now_balance['balance']
            ));

        if ($fee){
            model("TuserRecharge")->insert(array(
                "time" => NOW_TIME,
                "money" => -$fee,
                "tuser_id" => $tuser['id'],
                #"description" => "支付宝取现" . $money . "元,手续费{$fee}元",
                "description" => "支付宝提现手续费",
                "source" => "dxsw",
                "balance" => $now_balance['balance']
            ));
        }



        $Tuser->commit();
        $TuserRechargeLogic = model("TuserRecharge", "logic");
        $TuserRechargeLogic->deleterechargesumcache($tuser['id']);
        return json(array("data" => 1, "status" => 1, "info" => "提现成功！"));
    }

}