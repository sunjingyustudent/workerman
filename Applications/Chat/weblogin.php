<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Db;
use \Lib\RedisDb;
use \Lib\IpLocation;
use \Config\Site;
class weblogin
{
    
public  function weblogin(){
    var_dump("haha");
    return;
     $redis= Redis::onredis();
                // 判断是否有房间号，获取房间的房间号
                if (!isset($message_data['roomid']) || empty($message_data['roomid']))//判断房间是否存在或为空
                {
                    $sendMsg=array('type' => 'weblogin', 'msg' => 'roomid not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
               //都要传，游客的id为0，获取用户的userid
               if (!isset($message_data['fuserid']) || empty($message_data['fuserid']))//用户登录的accesstoken是否存在或为空
              {
                 if($message_data['fuserid']!==0){
                     $sendMsg=array('type' => 'weblogin', 'msg' => 'fuserid not exist or is empty ','code'=>'1');
                     Gateway::sendToCurrentClient(json_encode($sendMsg));
                     return;
                 }
              }
              
              //判断用户的ip地址是否存在
               if(!isset($message_data['ip']) || empty($message_data['ip'])){
                   $sendMsg=array('type' => 'weblogin', 'msg' => 'ip not exist or is empty ','code'=>'1');
                   Gateway::sendToCurrentClient(json_encode($sendMsg));
                   return;
               }
               
                //异常处理
                try {
                    $suserids = self::decode($message_data['fuserid']);//解密userid
                    
                    if(empty($message_data['fuserid'])){
                        $userid=0;
                    }else{
                        $userid = $suserids;//获取用户userid
                    }
                    
                        $roomid = $message_data['roomid'];//获取房间id
                        $ip=$message_data['ip'];//获取用户ip
                        $_SESSION["ip"]=$ip;//把用户ip存到session变量中


                }catch(Exception $e){
                    $sendMsg=array('type' => 'weblogin', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                //查询用户是否在黑名单中
                $isbalck=Db::instance('db1')->select('id')->from('wht_blacklist')->where('roomid="'.$roomid.'" and ip = "'.$ip.'"')->limit(1)->query();

                if($isbalck!=null){
                 //该用户是当前房间黑名单里的人
                    $sendMsg=array('type' => 'weblogin','code'=>'7777');
                    //把他是黑名单里的信息
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                    //如果用户userid不等于0，是游客
                if($userid!=0){
                    $_SESSION["userid"]=$userid;//把用户id存进session中
                    //查询数据库，根据userid获取人员信息
                    $userinfo = Db::instance('db1')->select('userid,username,nickname,balance,headimage,phone,gradeid,expervalue,ninemoney,roomid,usergrade,usergrade,majia')->from('wht_userinfos')->where('userid=' . $userid)->limit(1)->query();
                    //如果是当前用户会有当前用户信息
                    //把从数据库中的用户信息存到session变量中
                    $_SESSION['userinfo'] = $userinfo[0];
                }else{
                     $userinfo=null;
                }

                $sitename = Site::$sitename;//获取配置字符串
               //根据roomid从redis中获取房间配置信息
                $roominfo =$redis->hgetall($sitename . '-config-' . $roomid);//从redis中获取userid
                //判断redis中房间信息是否存在
                if (!isset($roominfo) || empty($roominfo)) {
                    $roominfos = Db::instance('db1')->select('roomid,roomtitle,roomnotice,filterswords,ischeck')->from('wht_rooms')->where('roomid=' . $roomid)->limit(1)->query();
                    $redis->hmset($sitename . '-config-' . $roomid, $roominfos[0]);
                    //应该设置房间信息的过期时间
                    //设置房间的过去
                    $redis->expire($sitename . '-config-' . $roomid,time()+3600);
                }

                $roominfo = $redis->hgetall($sitename . '-config-' . $roomid);//从redis中获取userid
                $_SESSION['roominfo'] = $roominfo;

            
                //一进来把前20条信息返回
                $msglist=null;//初始化聊天信息列表

                $msglist =   Db::instance('db1')->select('wcmid,gradeid,nickname,cstatus,msgcontent,tonickname,majia,touserid,tomajia,time,userid')->from('wht_webcastmessage')->where('roomid="'.$roomid.'" and cstatus != "4"')->orderByASC(array("createtime"),false)->limit(25)->query();
                           
                            $sendMsg = array('type' => 'initMsg','msglist'=>$msglist,'code'=>'33');
                            Gateway::sendToCurrentClient(json_encode($sendMsg));

               

                Gateway::joinGroup($client_id, $roomid);
                $clients=Gateway::getClientCountByGroup($roomid);
              $baseperoson=array(1,2,3,4,5,6,7,77,88,90,92,105,111,112);
                if(in_array($roomid,$baseperoson)){
                    $base=21000;
                    $clients=$clients+$base;
                    
                    
                     if($roomid==6){
                            $jinri=200;
                            $clients=  $clients+$jinri;
                        }
                }else{
                    $analyst= Site::$analyst;
                    $analystlist=explode(",",$analyst);
                    
                    if(in_array($roomid,$analystlist)){
                        $base=1000;
                        $clients=$clients+$base;
                    }
                    
                }

                //是游客,返回信息
                if($userid==0){
                    $_SESSION["userid"]=0;
                    $_SESSION["majia"]=0;
                    
                    
                    
                    $pattern='1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLOMNOPQRSTUVWXYZ';
                    $touristnickname="9d_";
                    $str="";
                    for($i=0;$i<=6;$i++){
                        $str=$str.$pattern{mt_rand(0,35)};
                    }
                    $timenow=date('His');
                    $_SESSION["touristnickname"]=$touristnickname.$str;

                    

                    Gateway::bindUid($client_id, $_SESSION["touristnickname"]);//把userid和游客昵称绑定
                    $sendMsg = array('type' => 'weblogin','person'=>$clients,'msg'=>'is tourist','code'=>'0');
                    Gateway::sendToGroup($roomid, json_encode($sendMsg));
                    return;
                }else{
                    Gateway::bindUid($client_id, $userid);//把userid和clientid绑定
                }

                
                
                $userid=$userinfo[0]["userid"];

                $headimage=$userinfo[0]["headimage"];
                $gradeid=$userinfo[0]["gradeid"];//获取用户等级
                $time=date('H:i');
                $username=$userinfo[0]["nickname"];//获取当前用户昵称
                //如果当前用户
                if(is_numeric($userinfo[0]["nickname"])){
                    $username = substr_replace($userinfo[0]["nickname"],'****',3,4);
                }
                if(self::is_email($userinfo[0]["nickname"])){
                    $username = substr_replace($userinfo[0]["nickname"],'****',0,4);
                }
                //给本站用户返回进来人员信息
                $sendMsg = array('type' => 'weblogin', 'userid' => $userid,'nickname'=>$username,'headimage'=>$headimage,'gradeid'=>$gradeid,'person'=>$clients,'time'=>$time,'code'=>'11');
                Gateway::sendToGroup($roomid, json_encode($sendMsg));

}

                
                
                
 }