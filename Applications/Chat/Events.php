<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose
 */
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Db;
use \Lib\RedisDb;
use \Lib\IpLocation;
use \Config\Site;


class Events
{
    public static function onMessage($client_id, $message)
    {
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if (!$message_data) {
            return;
        }
        if (!isset($message_data['type']) || empty($message_data['type']))//判断房间是否存在或为空
        {
            return;
        }
        // 根据类型执行不同的业务
        switch ($message_data['type']) {
            // 客户端回应服务端的心跳
            case 'ping':
                return;
            //网页版登录
            case "login":
                $redis= self::onredis();
                //判断是否有房间号
                if (!isset($message_data['roomid']) || empty($message_data['roomid']))//判断房间是否存在或为空
                {
                    $sendMsg=array('type' => 'login', 'msg' => 'roomid not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断是否有accesstoken
                if (!isset($message_data['accesstoken']) || empty($message_data['accesstoken']))//用户登录的accesstoken是否存在或为空
                {
                    $sendMsg=array('type' => 'login', 'msg' => 'accesstoken not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断是否有ip地址
                if (!isset($message_data['ip']) || empty($message_data['ip']))//用户登录的accesstoken是否存在或为空
                {
                    $sendMsg=array('type' => 'login', 'msg' => 'ip not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                try {
                    $accesstoken = $message_data['accesstoken'];//获取用户accesstoken
                    $roomid = $message_data['roomid'];//获取房间id
                    $ip= $message_data['ip'];//获取用户ip地址
                    //根据accesstoken获取userid
                    $userids = $redis->hgetall($accesstoken);//从redis中获取userid
                    $userid= $userids["uid"];//获取用户id
                  
                    //把userid存到session中
                    $_SESSION["ip"]=$ip;//把用户ip存到session中

                    
                    
                    //判断token是否过期
                    if($userid!=0){
                        if (!isset($userid) || empty($userid)) {
                            $sendMsg=array('type' => 'login', 'msg' => 'token guoqi ','code'=>'5');
                            Gateway::sendToCurrentClient(json_encode($sendMsg));
                            return;
                        }
                    }
                 }catch(Exception $e){
                    $sendMsg=array('type' => 'login', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                 }


                //查询用户是否在黑名单中
                $isbalck=Db::instance('db1')->select('id')->from('wht_blacklist')->where('roomid="'.$roomid.'" and ip = "'.$ip.'"')->limit(1)->query();


                if($isbalck!=null){
                    //该用户是当前房间黑名单里的人
                    $sendMsg=array('type' => 'login','code'=>'7777');
                    //把他是黑名单里的信息
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                //app返回直播室警告标语
                $warntitle="管理员：市场有风险，投资须谨慎；主播观点不代表9度立场。";
                $warn=array('type' => 'chat','code'=>'55', 'msg' => $warntitle);
                Gateway::sendToCurrentClient(json_encode($warn));


                //如果用户userid不等于0，是游客
                if($userid!=0){
                    $_SESSION["userid"]=$userid;//把用户id存进session中

                    //查询数据库，根据userid获取人员信息
                    $userinfo = Db::instance('db1')->select('userid,username,nickname,balance,headimage,phone,gradeid,expervalue,ninemoney,roomid,usergrade,usergrade,majia')->from('wht_userinfos')->where('userid=' . $userid)->limit(1)->query();
                    //把从数据库中的用户信息存到session变量中

                    $_SESSION['userinfo'] = $userinfo[0];
                }else{
                    $userinfo=null;
                    $_SESSION["userid"]=0;//把游客的id存进session中
                }


                $sitename = Site::$sitename;//获取配置字符串

                $appintMsgCount=Site::$appintMsgCount;

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

                $roominfo =$redis->hgetall($sitename . '-config-' . $roomid);//从redis中获取userid
                $_SESSION['roominfo'] = $roominfo;

             

              

                //一进来把前20条信息返回
                $msglist=null;//初始化聊天信息列表

               $msglist =   Db::instance('db1')->select('wcmid,gradeid,nickname,cstatus,msgcontent,tonickname,majia,touserid,tomajia,time,userid')->from('wht_webcastmessage')->where('roomid="'.$roomid.'" and cstatus != "4"')->orderByASC(array("createtime"),false)->limit($appintMsgCount)->query();
                      

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
                    $sendMsg = array('type' => 'login','person'=>$clients,'msg'=>'is tourist','code'=>'0');
                    Gateway::sendToGroup($roomid, json_encode($sendMsg));
                    return;
                }else{
                    Gateway::bindUid($client_id, $userid);//把userid和clientid绑定
                }

                $userid=$userinfo[0]["userid"];
                $headimage=$userinfo[0]["headimage"];
                $gradeid=$userinfo[0]["gradeid"];//获取用户等级

                $username=$userinfo[0]["nickname"];//获取当前用户昵称

                //如果当前用户
                if(is_numeric($userinfo[0]["nickname"])){
                    $username = substr_replace($userinfo[0]["nickname"],'****',3,4);
                }


                if(self::is_email($userinfo[0]["nickname"])){
                    $username = substr_replace($userinfo[0]["nickname"],'****',0,4);
                }

                
                 //返回给用户自己的userid
                  $sendMsg = array('type' => 'login', 'userid' => $userid,'code'=>'99');
                  Gateway::sendToCurrentClient(json_encode($sendMsg));
               
                //给本站用户返回进来人员信息
                $sendMsg = array('type' => 'login', 'userid' => $userid,'nickname'=>$username,'headimage'=>$headimage,'gradeid'=>$gradeid,'person'=>$clients,'code'=>'11');
               
                Gateway::sendToGroup($roomid, json_encode($sendMsg));
                return;
                break;
            case "weblogin":
                //链接redis
                $redis= self::onredis();
             
                if (!isset($message_data['roomid']) || empty($message_data['roomid']))//判断房间是否存在或为空
                {
                    $sendMsg=array('type' => 'weblogin', 'msg' => 'roomid not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
              
               if (!isset($message_data['fuserid']) || empty($message_data['fuserid']))//用户登录的accesstoken是否存在或为空
              {
                 if($message_data['fuserid']!==0){
                     $sendMsg=array('type' => 'weblogin', 'msg' => 'fuserid not exist or is empty ','code'=>'1');
                     Gateway::sendToCurrentClient(json_encode($sendMsg));
                     return;
                 }
              }
              
              
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
                return;
                break;
            //发送聊天信息逻辑处理
            case "chat":
                
                $yundun= Site::$yundun;
                $redis= self::onredis();
                $timenow=date('H:i');
                
                    
                $userid= $_SESSION["userid"];//获取用户id
                $majia=0;//初始化的小马甲
                 
                    //获取用户信息
                if($_SESSION["userid"]!=0){
                        //获取用户的is
                        $userinfo =$_SESSION['userinfo'];//获取用户信息
                        $majia=$userinfo["majia"];//获取特殊人员的马甲
                }
                    
                //获取房间信息
                $roominfo = $_SESSION['roominfo'];
                  
            //判断聊天信息是否为空
                if (!isset($message_data["content"]) || empty($message_data["content"])) {
                    $sendMsg=array('type' => 'chat', 'msg' => 'content not  is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                //聊天不能不存在或者为空
                if (!isset($message_data["webadress"]) || empty($message_data["webadress"])) {
                    $sendMsg=array('type' => 'chat', 'msg' => 'webadress not  is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                if (!isset($message_data["jieshouuserid"]) || empty($message_data["jieshouuserid"])) {
                    $sendMsg=array('type' => 'chat', 'msg' => 'jieshouuserid not  is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                if (!isset($message_data["jieshounickname"]) || empty($message_data["jieshounickname"])) {
                    $sendMsg=array('type' => 'chat', 'msg' => 'jieshounickname not  is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                try {
                    $app=$message_data["app"];
                    $content = $message_data["content"];                          
//                    $content= base64_decode($content);              
                    $webaddress = $message_data["webadress"];
               
                    //获取当前接受聊天信息的id
                    $jieshouuserid=$message_data["jieshouuserid"];
                    //获取当前接受聊天信息的昵称
                    $jieshounickname=$message_data["jieshounickname"];
                } catch(Exception $e){
                    $sendMsg=array('type' => 'chat', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                    $tomajia=0;//初始化对谁说的小马甲
                    if($jieshouuserid!=-1){
                        if($jieshouuserid==-2){
                            $tomajia=0;
                        }else{
                            $to=Db::instance('db1')->select('majia,gradeid')->from('wht_userinfos')->where('userid='.$jieshouuserid)->limit(1)->query();
                            if($to[0]["majia"]==0||$to[0]["majia"]==null){
                                $tomajia=$to[0]["gradeid"];
                            }else{
                                if($to[0]["majia"]==1){
                                    $tomajia=66;
                                }else if($to[0]["majia"]==2){
                                    $tomajia =88;
                                }else if($to[0]["majia"]==3){
                                    $tomajia =99;
                                }
                            }
                        }
                   }

                   
                   
                    $reg= "/\[em_\d{1,5}\]/";
                    if(preg_match($reg, $content)){
                        $appcontent=preg_replace($reg,'',$content);

                    }else{
                        $appcontent=$content;
                    }
                    $cj="http://jdcjapp.oss-cn-hangzhou.aliyuncs.com/aliyun";
                    if(strstr( $content,$cj)){
                        $appcontent="";
                    }
                   
                   
                   
                   
                if($userid!=0){

                     if($userinfo["usergrade"]=="3"){
                         $username=$userinfo["nickname"];//获取当前用户昵称
                               
                                if(is_numeric($userinfo["nickname"])){
                                    $username = substr_replace($userinfo["nickname"],'****',3,4);
                                }

                                if(self::is_email($userinfo["nickname"])){
                                    $username = substr_replace($userinfo["nickname"],'****',0,4);
                                }
                         
                         var_dump("我是超级管理员");
                         $msgid = Db::instance('db1')
                                   ->insert('wht_webcastmessage')
                                  ->cols(array('roomid'=>$roominfo["roomid"],'time'=>$timenow,'majia'=>$majia,'tomajia'=>$tomajia, 'userid' =>$userinfo["userid"],'tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid,'gradeid' =>$userinfo["gradeid"] , 'nickname' => $username, 'webaddress' => $webaddress, 'cstatus' => '0', 'msgcontent' => $content))->limit(1)->query();
 
                         $sendMsg = array('type' => 'chat','appcontent'=>$appcontent,'timenow'=>$timenow,'code'=>'11','majia'=>$majia,'tomajia'=>$tomajia,'msgid'=>$msgid,'tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid, 'content' => $content,'userid' => $userinfo["userid"], 'webaddress' => $webaddress,'gradeid' => $userinfo["gradeid"] , 'nickname' => $username);

                           Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                           return;
                    }
                }
                   
                   
                   
                   
                 
                 if($userid==0){
                    //如果是游客，通过昵称找到clientid
                    $cip=Gateway::getClientIdByUid($_SESSION["touristnickname"]);
                }else{
                    //如果是用户，通过用户id找到clientid
                    $cip=Gateway::getClientIdByUid($userid);
                }
                if($cip==null){
                $userip="127.0.0.1";
                }
                
                //根据clientid找到当前用户的session数组
                $sessio=Gateway::getSession($cip[0]);
                //获取当前用户session数组
                $userip=$sessio["ip"];
              
                        
              $content=htmlspecialchars( $content);
                             
                    //获取房间过滤词
                    $guolu = $roominfo["filterswords"];//获取房间过滤词
                    //拆分过滤次
                     $guolvarr = explode(",", $guolu);
                  //设置一个敏感词检测旗帜
                    $hasG = false;
                    //进行敏感词检测
                    for ($i = 0, $k = count($guolvarr); $i < $k; $i++) {
                        // 如果检测到关键字，则返回匹配的关键字,并终止运行
                        if (@strpos($message_data['content'], trim($guolvarr[$i])) !== false) {
                            //$i=$k;
                            $hasG = true;
                            break;
                        }
                    }
                     //判断是否有
                    if ($hasG) {
                       $sendMsg = array('type' => 'chat','code'=>'134','msg'=>'敏感词');
                           Gateway::sendToCurrentClient(json_encode($sendMsg));
                           return;

                    }
               
                    
                    
                        $cj="http://jdcjapp.oss-cn-hangzhou.aliyuncs.com";
                        if(strstr( $content,$cj)){
                        }else{
                          
                    //正则匹配网址
                    $regex = "/^(https?:\/\/)?(((www\.)?[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)?\.([a-zA-Z]+))|(([0-1]?[0-9]?[0-9]|2[0-5][0-5])\.([0-1]?[0-9]?[0-9]|2[0-5][0-5])\.([0-1]?[0-9]?[0-9]|2[0-5][0-5])\.([0-1]?[0-9]?[0-9]|2[0-5][0-5]))(\:\d{0,4})?)(\/[\w- .\/?%&=]*)?$/i";

                    
                    //正则匹配连续数字
                    $numreg = "/\d{7}/";
                   

                   
                    $net="/[a-zA-Z\d_]{7}/";    
                 
                    if (preg_match_all($regex, $content))
                    {
                        
                          $content=preg_replace($regex,'*****',$content);
                                               
                    }
                   
                     if (preg_match_all($numreg, $content))
                    {
                          $content=preg_replace($numreg,'*****',$content);
                         
                    }
                   
                      if (preg_match_all($net, $content))
                    {
                        
                          $content=preg_replace($net,'*****',$content);
                    }
                    
                   
                        }

                    //如果该房间为审核房间。
                   //添加聊天消息
                            //如果进来的是游客
                            if($userid==0){

                                $msgid = Db::instance('db1')
                                    ->insert('wht_webcastmessage')
                                    ->cols(array('roomid'=>$roominfo["roomid"],'time'=>$timenow,'majia'=>$majia,'tomajia'=>$tomajia, 'userid' =>'0','tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid,'gradeid' => '0' , 'nickname' =>  $_SESSION["touristnickname"], 'webaddress' => $webaddress, 'cstatus' => '0', 'msgcontent' => $content))->limit(1)->query();

                                 $cj="http://jdcjapp.oss-cn-hangzhou.aliyuncs.com";
                        if(strstr( $content,$cj)){
                          
                        }else{
                            
                            if($yundun){
                                    $a=self::main($content,$msgid,$userid,$userip);
                                
                                  if($a!=1){
                                        $row_count=Db::instance('db1')->update('wht_webcastmessage')->cols(array('cstatus'=>'4'))->where('wcmid='.$msgid)->query();
                                   $sendMsg = array('type' => 'chat','code'=>'134','msg'=>'敏感词');
                                   Gateway::sendToCurrentClient(json_encode($sendMsg));
                                   return;
                                   }
                            }
                        }
                                

//                               $content= base64_encode($content);
                            
                                //定义返回的聊天消息
                                $sendMsg = array('type' => 'chat','appcontent'=>$appcontent,'timenow'=>$timenow,'code'=>'11','majia'=>$majia,'tomajia'=>$tomajia,'msgid'=>$msgid,'tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid, 'content' => $content,'userid' => '0', 'webaddress' => $webaddress,'gradeid' => '0', 'nickname' => $_SESSION["touristnickname"]);

                            }
                            else{
                             
                                $username=$userinfo["nickname"];//获取当前用户昵称
                               
                                if(is_numeric($userinfo["nickname"])){
                                    $username = substr_replace($userinfo["nickname"],'****',3,4);
                                }

                                if(self::is_email($userinfo["nickname"])){
                                    $username = substr_replace($userinfo["nickname"],'****',0,4);
                                }

                               
                                //将用户发过来的话添加到数据库中，返回添加记录id
                                $msgid = Db::instance('db1')
                                    ->insert('wht_webcastmessage')
                                    ->cols(array('roomid'=>$roominfo["roomid"],'time'=>$timenow,'tomajia'=>$tomajia,'majia'=>$majia, 'tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid ,'userid' => $userinfo["userid"],'gradeid' => $userinfo['gradeid'], 'nickname' => $username, 'webaddress' => $webaddress, 'cstatus' => '0', 'msgcontent' => $content))->limit(1)->query();
                               
                                
                                
                                 $cj="http://jdcjapp.oss-cn-hangzhou.aliyuncs.com";
                        if(strstr( $content,$cj)){
                          
                        }else{
                            if($yundun){
                                    $a=self::main($content,$msgid,$userid,$userip);
                                
                                  if($a!=1){
                                     
                                         $row_count=Db::instance('db1')->update('wht_webcastmessage')->cols(array('cstatus'=>'4'))->where('wcmid='.$msgid)->query();
                                      
                                   $sendMsg = array('type' => 'chat','code'=>'134','msg'=>'敏感词');
                                   Gateway::sendToCurrentClient(json_encode($sendMsg));
                                   return;
                                   }
                            }
                      
                                
//                                   $content= base64_encode($content);
                             
                                $sendMsg = array('type' => 'chat','appcontent'=>$appcontent,'timenow'=>$timenow,'majia'=>$majia,'tomajia'=>$tomajia,'code'=>'11','msgid'=>$msgid,'tonickname'=>$jieshounickname,'touserid'=>$jieshouuserid , 'content' => $content ,'userid' => $userinfo["userid"], 'webaddress' => $webaddress,'gradeid' => $userinfo['gradeid'], 'nickname' =>$username);


                            }
                }
                
                Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                return;
                break;
    
            // 发红包
            case "sendredpacket":
                $redis= self::onredis();//连接redis
                $userid=$_SESSION["userid"];//获取用户id
                //判断是否是游客
                if($userid==0){
                    $sendMsg=array('type' => 'sendredpacket', 'msg' => '游客不能发红包，请登录','code'=>'0');

                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                $roominfo = $_SESSION['roominfo'] ;//获取房间信息
                $room_id=$roominfo["roomid"];//获取房间号

                $sitename=Site::$sitename;//获取配置字符串

                //判断金额是否大于最大值
                if (!isset($message_data['amount']) || empty($message_data['amount'])) {
                    $sendMsg=array('type' => 'sendredpacket', 'msg' => 'amount canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                //获取红包个数不能为空或者
                if (!isset($message_data['num']) || empty($message_data['num'])) {
                    $sendMsg=array('type' => 'sendredpacket', 'msg' => 'num canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }


                try {
                    $amount = $message_data['amount'];//红包总额，
                    $num = $message_data['num'];// 分成多少个红包，支持多个人随机领取
                   /* $webcastid = $message_data['webcastid'];*/
                }catch(Exception $e){
                    $sendMsg=array('type' => 'sendredpacket', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));

                    return;
                }
                
                //发红包的客户端
                $userinfo= $_SESSION['userinfo'];
                $majia=$userinfo["majia"];
                
                
                $username=$userinfo["nickname"];//获取当前用户昵称

                        //如果当前用户
                        if(is_numeric($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',3,4);
                        }


                        if(self::is_email($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',0,4);
                        }
           

                if(((int)$amount/(int)$num)<1){
                    $result= array('type'=>'sendredpacket','code' => '4','msg' => '金额比重错误，每人不少于1钻');
                    Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送

                    return;
                }



                if($amount<1){
                    $result= array('type'=>'sendredpacket','code' => '4','msg' => '金额太小');
                    Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送
                    return;
                }elseif($amount>500){
                    $result= array('type'=>'sendredpacket','code' => '4','msg' => '金额太大');
                    Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送
                    return;
                }



                //查询用户账户钻石数
                $money = Db::instance('db1')->select('ninemoney')->from('wht_userinfos')->where('userid="'.$userinfo["userid"].'"')->limit(1)->query();



                //如果余额不等于空
                if(!empty($money))
                {


                    if($money[0]["ninemoney"]<$amount){
                       /* echo "sendredpacket fail money not enough";*/
                        $result=array('type'=>'sendredpacket','code' => '5','msg' => '个人账户余额不足');
                        // 转发消息给对应的客户端
                        Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送
                        return;
                    }



                    //否则，扣掉个人账户余额
                    $symoney = $money[0]["ninemoney"]-$amount;


                    $rowcot= Db::instance('db1')->update('wht_userinfos')->cols(array('ninemoney'))->where('userid='.$userid)->bindValue('ninemoney', $symoney)->query();


                    if($rowcot<=0){
                        $result=array('type'=>'sendredpacket','code' => '5','msg' => '扣款失败,请重新尝试');
                        // 转发消息给对应的客户端
                        Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送
                        return;
                    }



                }
                

                //扣完款，添加一条记录到数据库和redis里
                $redpacketid= Db::instance('db1')->insert('wht_redpackets')->cols($insertArr=array('total'=>$amount,'userid'=>$userinfo["userid"],'acount'=>$num,'starttime'=>date('y-m-d H:i:s',time())))->query();
                //插入到数据库中成功


                if(!empty($redpacketid))
                {

                        //去数据库中查红包详情
                        $redptmp=Db::instance('db1')->select('acount,total,redpacketid')->from('wht_redpackets')->where('redpacketid="'.$redpacketid.'"')->limit(1)->query();

                        //如果记录不为空
                        if(!empty($redptmp)){


                            //添加到redis里
                            $redis->hmset($sitename.'-redpackets-'.$redpacketid,$redptmp[0]);

                            $redis->expire($sitename . '-redpackets-' . $redpacketid,172800);//设置红包的过期信息





                            //把发红包用户昵称，头像存到redis中
                            $red=array(
                                'ninckname'=>$username,
                                'headimg'=>$userinfo["headimage"]
                            );


                            //设置发红包用户的昵称和头像存到redis中
                            $redis->hmset($sitename.'-redpackets-'.$redpacketid."hehe",$red);


                            $result=array('type'=>'sendredpacket','code' => '11','majia'=>$majia,'msg' => '红包生成成功',"result"=>$redpacketid,'imagehead'=>$userinfo["headimage"],'nickname'=>$username,'userid'=>$userinfo["userid"]);
                            // 转发消息给对应的客户端

                            Gateway::joinGroup($client_id, $roominfo["roomid"]);
                            Gateway::sendToGroup($roominfo["roomid"], json_encode($result));
                            return;
                        }
                    }

                else{
                    //红包ID为空
                    $result=array('type'=>'sendredpacket','code' => '3','msg' => '生成失败');
                    // 转发消息给对应的客户端
                    Gateway::sendToCurrentClient(json_encode($result));//只给当前用户发送
                    return;
                }
                return;
                break;
            //发红包处理ok
            case "getredpacket":
                $redis= self::onredis();
                $userid=$_SESSION["userid"];//获取用户userid
                // 判断是否是游客，或者第三方用户
                if($userid<=0 ||!isset($userid) || empty($userid)){
                    $sendMsg=array('type' => 'getredpacket', 'msg' => '游客不能发红包，请登录','code'=>'0');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }


                //判断红包id是否为空，或者不存在
                if (!isset($message_data['redpacketid']) || empty($message_data['redpacketid'])) {
                    $sendMsg=array('type' => 'getredpacket', 'msg' => 'redpacketid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                try {
                    $redpacketid = $message_data["redpacketid"];//获取红包id
                }catch(Exception $e){
                    $sendMsg=array('type' => 'getredpacket', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                //获取用户信息
                $userinfo= $_SESSION['userinfo'];

                $majia=$userinfo["majia"];


                //获取房间信息
                $roominfo = $_SESSION['roominfo'] ;

                //获取房间号id
                $room_id=$roominfo["roomid"];//获取房间号

                //判断用户是否抢过红包
                $redflag=$redis->get($userid.$redpacketid);

                //$redflag==1,为该用户已经抢过红包


                if($redflag==1){
                    $sendMsg=array('type' => 'getredpacket', 'msg' => '您已抢过红包 ','code'=>'7');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }


                $imagehead=$userinfo["headimage"];//获取抢红包用户头像

                //获取用户id
                $sitename=Site::$sitename;//获取配置信息

                //获取抢红包用户余额
                $userBalance=Db::instance('db1')->select("ninemoney")->from("wht_userinfos")->where("userid=$userid")->query();

                //根据红包id获取redis中的红包信息
                $result=$redis->hgetall($sitename.'-redpackets-'.$redpacketid);


                //从redis中获取发红包用户的昵称和头像
                $reduserid=$redis->hgetall($sitename.'-redpackets-'.$redpacketid."hehe");

                //判断红包数量是否大于0，如果是可以继续抢，否则不让抢
                //判断redis中是否有红包的信息

                if($result){
                    //判断红包个数
                    if($result["acount"]>1){

                        $title=floatval($result["total"])*2/floatval($result["acount"]);

                        $count= intval($result["acount"])-1;//红包个数减一，redis中红包剩余个数


                        $mon=rand (1,$result["total"]);//从一到所发金额中随机一个整数
                        $mon=strval($mon);

                        $cha=$result["total"]-$mon;

                        while($cha/$count<1) {
                            $mon=rand (1,$result["total"]);//从一到所发金额中随机一个整数
                            $cha=$result["total"]-$mon;
                        }

                        //计算剩余红包金额
                        $amony=intval($result["total"])-$mon;//redis中红包剩余金钱

                        //修改redis中的红包金额
                        //修改redis数据库红包信息
                        $redis->hmset($sitename.'-redpackets-'.$redpacketid,array("total"=>$amony,"acount"=>$count));


                        //插入数据库中，把当前抢红包记录插入数据库中
                        Db::instance('db1')->insert("wht_recpacketrecord")->cols($a=array('redpacketid'=>$redpacketid,'touserid'=>$userid,'amoney'=>$mon))->query();


                        //修改抢红包用户的用户余额
                        $re=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>intval($userBalance[0]["ninemoney"])+intval($mon)))
                            ->where("userid=$userid")
                            ->query();


                        $res=array('type'=>'getredpacket','msg' => '抢红包成功','majia'=>$majia,'money'=>$mon,'imagehead'=>$imagehead,'fhb'=>$reduserid,'userid'=>$userid,'count'=>'1','redpacketid'=>$redpacketid,'code'=>'11');

                        if($re>0) {

                            $redis->set($userid.$redpacketid,1);//设置当前用户已经抢过红包

                           Gateway::joinGroup($client_id, $room_id);


                            Gateway::sendToCurrentClient(json_encode($res));
                            return;
                        }

                    }

                    if($result["acount"]==1){


                        //修改redis数据库红包信息
                        $count= intval($result["acount"])-1;//红包个数减一，redis中红包剩余个数

                        //把redis中红包的数据，红包个数置为空
                       $redis->hmset($sitename.'-redpackets-'.$redpacketid,array("total"=>$result["total"],"acount"=>$count));

                        //插入数据库中
                        Db::instance("db1")->insert("wht_recpacketrecord")->cols($a=array('redpacketid'=>$redpacketid,'touserid'=>$userid,'amoney'=>$result["total"]))->query();


                        //修改用户账户金额
                        $re=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$userBalance[0]["ninemoney"]+$result["total"]))
                            ->where("userid=$userid")
                            ->query();


                        if($re>0) {


                            //查询手气最佳信息
                            $reds=Db::instance("db1")->query("select * from wht_recpacketrecord where redpacketid=".$redpacketid." order by amoney DESC " );

                            $redid=$reds[0]["redprecid"];

                            //修改信息
                            Db::instance("db1")->update("wht_recpacketrecord")->cols(array("ismax"))->where("redprecid=$redid")->bindValue("ismax","1")->query();
                            Db::instance("db1")->update("wht_redpackets")->cols(array("status"))->where("redpacketid=$redpacketid")->bindValue("status","1")->query();

                            //查询红包信息记录，及手气最佳等信息
                            $res=array('type'=>'getredpacket','msg' => '抢红包成功,您是最后一位幸运者','majia'=>$majia,'money'=>$result["total"],'fhb'=>$reduserid,'count'=>'0','imagehead'=>$imagehead,'userid'=>$userid,'redpacketid'=>$redpacketid,'code'=>'11');


                            Gateway::sendToCurrentClient(json_encode($res));
                            return;

                        }
                    }


                    if($result["acount"]==0){
                        echo "红包已抢完";

                        $res=array('type'=>'getredpacket','msg' => '红包已被抢完','code'=>'3');

                        Gateway::sendToCurrentClient(json_encode($res));
                        return;

                    }

                }else{
                   var_dump("红包已失效");
                    $res=array('type'=>'getredpacket','msg' => '红包已失效','code'=>'4');
                    Gateway::joinGroup($client_id, $room_id);
                    Gateway::sendToCurrentClient(json_encode($res));
                }



                break;
            //查看红包详情
            case "redpacketdetail":

                $userid=$_SESSION["userid"];
                if($userid==0 ||!isset($userid) || empty($userid)){
                    $sendMsg=array('type' => 'redpacketdetail', 'msg' => 'tourist canot look over redpacketdetail','code'=>'0');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                if (!isset($message_data['redpacketid']) || empty($message_data['redpacketid'])) {
                    $sendMsg=array('type' => 'redpacketdetail', 'msg' => 'redpacketid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }


                try {
                    $redpacketid = $message_data["redpacketid"];
                }catch(Exception $e){
                    $sendMsg=array('type' => 'redpacketdetail', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                $redinfo=Db::instance('db1')
                    ->select("wht_recpacketrecord.amoney,wht_recpacketrecord.ismax,wht_userinfos.nickname,wht_userinfos.headimage")
                    ->from("wht_recpacketrecord")
                    ->innerJoin('wht_userinfos','wht_recpacketrecord.touserid = wht_userinfos.userid')
                    ->where("redpacketid=$redpacketid")
                    ->query();
            
            var_dump($redinfo);
               $recount=count($redinfo);
               
               
               for($i=0;$i<$recount;$i++){
                   $redinfo[$i]["nickname"]=$redinfo[$i]["nickname"];//获取当前用户昵称

                        //如果当前用户
                        if(is_numeric($redinfo[$i]["nickname"])){
                            $redinfo[$i]["nickname"] = substr_replace($redinfo[$i]["nickname"],'****',3,4);
                        }


                        if(self::is_email($userinfo["nickname"])){
                            $redinfo[$i]["nickname"] = substr_replace($redinfo[$i]["nickname"],'****',0,4);
                        }
               }
                    
                        
                        
                        
                  //var_dump($redinfo);
                //获取发红包用户的头像

                $fhbheadimg=Db::instance('db1')
                    ->select("wht_userinfos.nickname,wht_userinfos.headimage")
                    ->from("wht_redpackets")
                    ->innerJoin('wht_userinfos','wht_redpackets.userid = wht_userinfos.userid')
                    ->where("redpacketid=$redpacketid")
                    ->query();


                $sendMsg=array('type'=>'redpacketdetail','redinfo'=>$redinfo,'fhbheadimg'=>$fhbheadimg,'code'=>'11');

                var_dump($sendMsg);
                Gateway::sendToCurrentClient(json_encode($sendMsg));//只给当前用户发送
                return;
                break;
            //发送礼物逻辑处理 孙靖宇
            case "gifts":
        
                $userid=$_SESSION["userid"];//获取用户userid
                if($userid<=0){
                    $sendMsg=array('type' => 'gifts', 'msg' => '游客不能发礼物，请登录','code'=>'0');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //获取房间信息
                $roominfo = $_SESSION['roominfo'];
                if(!isset($message_data["webcastkey"]) || empty($message_data["webcastkey"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'webcastkey canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["giftprice"]) || empty($message_data["giftprice"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'giftprice canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["giftname"]) || empty($message_data["giftname"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'giftname canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["giftid"]) || empty($message_data["giftid"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'giftid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["xznum"]) || empty($message_data["xznum"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'xznum canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                
                try {
                    $count=$message_data["xznum"];//获取选择数量
                    $webcastkey = $message_data["webcastkey"];//获取webcastkey
                    $danjian = $message_data["giftprice"];//获取礼物价格
                    $giftname = $message_data["giftname"];//获取礼物名称
                    $giftid = $message_data["giftid"];//获取礼物id
                }catch(Exception $e){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                }
                
                
                //获取userid
                $userinfo= $_SESSION['userinfo'];//获取用户信息
                $userid = $userinfo['userid'];//获取用户id
                $majia=$userinfo["majia"];
                
                
                 $username=$userinfo["nickname"];//获取当前用户昵称

                        //如果当前用户
                        if(is_numeric($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',3,4);
                        }


                        if(self::is_email($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',0,4);
                        }
                
                
                //获取当前用户的钻石数，经验值，等级id
                $userxiangguan=Db::instance('db1')->select("ninemoney,expervalue,gradeid")->from("wht_userinfos")->where("userid=$userid")->query();
                
                //计算发送礼物的总价
                $giftprice=$count*$danjian;
                
               //获取当前用户的钻石数
                $nowninemoney=$userxiangguan[0]["ninemoney"];
                
                //获取当前用户的经验值
                $jingyan=$userxiangguan[0]["expervalue"];
                
                 //如果当前用户的钻石数大于发送礼物的总价
                if($nowninemoney>=floatval($giftprice)){
                    //扣除当前用户的钻石数
                    $balance=$nowninemoney-(float)$giftprice;
                  //计算要增加的经验值
                    $addjingyan=$giftprice*10;
                   //计算增加后用户的经验值
                    $jingyanafter=$jingyan+$addjingyan;
                    //判断当前经验应该是哪个等级
                    $gradeid=$userxiangguan[0]["gradeid"];
                    if($jingyanafter<500){
                        $gradeid=1;
                    }else if($jingyanafter<1000){
                        $gradeid=2;
                    }else if($jingyanafter<1500){
                        $gradeid=3;
                    }else if($jingyanafter<3000){
                        $gradeid=4;
                    }else if($jingyanafter<4500){
                        $gradeid=5;
                    }else if($jingyanafter<6000){
                        $gradeid=6;
                    }else if($jingyanafter<7500){
                        $gradeid=7;
                    }else if($jingyanafter<8000){
                        $gradeid=8;
                    }else if($jingyanafter<9500){
                        $gradeid=9;
                    }else if($jingyanafter<10000){
                        $gradeid=10;
                    }else if($jingyanafter<50000){
                        $gradeid=11;
                    }else if($jingyanafter<100000){
                        $gradeid=12;
                    }else if($jingyanafter<500000){
                        $gradeid=13;
                    }else if($jingyanafter<1000000){
                        $gradeid=14;
                    }else if($jingyanafter<5000000){
                        $gradeid=15;
                    }else if($jingyanafter<10000000){
                        $gradeid=16;
                    }else{
                        $gradeid=17;
                    }

                    //更新用户账户余额,经验值，等级
                    $result=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$balance,'gradeid'=>$gradeid,'expervalue'=>$jingyanafter))
                        ->where("userid=$userid")
                        ->query();

                    //获取主播当前收益
            
                    $zhuboninemoney=Db::instance('db1')->select("ninemoney,userid")->from("wht_userinfos")->where('roomid="'.$_SESSION["roominfo"]["roomid"].'" and usergrade = "1"')->query();
               
                    if($zhuboninemoney==null){
                     return;
                    }
                    
                    if($zhuboninemoney[0]["ninemoney"]==null){
                        $zhuboninemoney[0]["ninemoney"]=0;
                    }

                    $zhubonow=intval($zhuboninemoney[0]["ninemoney"])+intval($giftprice);

                    //获取主播id
                    //更新当前主播的钻石数
                    $resultzhubo=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$zhubonow))
                        ->where("userid=".$zhuboninemoney[0]["userid"])
                        ->query();
                 //把聊天信息存到数据库中，
                    //定义礼物的聊天信息格式，礼物名称加礼物发送的数量

                    $content=$giftid."X".$count;
                    $timenow=date('H:i');//添加到聊天消息中的礼物时间

                    $touserid=-4;
                    $tonickname="礼物";

                    $messageid = Db::instance('db1')
                        ->insert('wht_webcastmessage')
                        ->cols(array('roomid'=>$roominfo["roomid"],'touserid'=>$touserid,'tonickname'=>$tonickname,'time'=>$timenow,'majia'=>$majia,'userid' => $userinfo["userid"],'gradeid' => $userinfo['gradeid'], 'nickname' => $username, 'msgcontent' => $content))->query();
                    $gifts= Db::instance('db1')->select('user_gift_count_money')->from('wht_user_gift_count')->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')->query();
                  
                    if($gifts==null){
                       Db::instance('db1')->insert('wht_user_gift_count')->cols(array('roomid'=>$roominfo["roomid"],'userid'=>$userinfo["userid"],'user_gift_count_money'=>$giftprice))->query();
                    }else{

                        $after=$gifts[0]["user_gift_count_money"]+$giftprice;

                      Db::instance('db1')->update("wht_user_gift_count")->cols($a=array('user_gift_count_money'=>$after))
                          ->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')
                            ->query();

                    }
                    
                    $timenow=date("H:i");
                    
                    //插入用户礼物交易信息
                    $msgid= Db::instance('db1')->insert('wht_usergift')
                        ->cols(array('frmuserid'=>$userinfo["userid"],'touserid'=>$zhuboninemoney[0]["userid"],'total'=>$giftprice,'time'=>$timenow,'roomid'=>$roominfo["roomid"],'count'=>$count,'giftid'=>$giftid,'webcastkey'=>$webcastkey,'giftprice'=>$giftprice,'giftname'=>$giftname))->query();

                    if($msgid>0&&$result>0){
                       
                        
                        
                        $sendMsg=array('type'=>'gifts','time'=>$timenow,'frmuserid'=>$userinfo["userid"],'majia'=>$majia,'ninemoney'=>$balance,'gradeid'=>$userinfo["gradeid"],'count'=>$count,'headimg'=>$userinfo["headimage"],'nickname'=>$username,'webcastkey'=>$webcastkey,'giftid'=>$giftid,'giftprice'=>$giftprice,'giftname'=>$giftname,'code'=>'11');

                        Gateway::joinGroup($client_id, $roominfo["roomid"]);
                        Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                    }

                }else{
                    $sendMsg=array('type'=>'gifts','msg'=>'钻石不够','code'=>'3');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));//只给当前用户发送
                }
                break;
                
           case "sendgifts":
                var_dump($message_data);
                $userid=$_SESSION["userid"];//获取用户userid
                if($userid<=0){
                    $sendMsg=array('type' => 'gifts', 'msg' => '游客不能发礼物，请登录','code'=>'0');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //获取房间信息
                $roominfo = $_SESSION['roominfo'];
                
              
                if(!isset($message_data["webcastkey"]) || empty($message_data["webcastkey"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'webcastkey canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
               
                if(!isset($message_data["giftid"]) || empty($message_data["giftid"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'giftid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["xznum"]) || empty($message_data["xznum"])){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'xznum canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                
                try {
                    $count=$message_data["xznum"];//获取选择数量
                    $webcastkey = $message_data["webcastkey"];//获取webcastkey
//                    $danjian = $message_data["giftprice"];//获取礼物价格
//                    $giftname = $message_data["giftname"];//获取礼物名称
                    $giftid = $message_data["giftid"];//获取礼物id
                }catch(Exception $e){
                    $sendMsg=array('type' => 'gifts', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                }
                //获取userid
                $userinfo= $_SESSION['userinfo'];//获取用户信息
                $userid = $userinfo['userid'];//获取用户id
                $majia=$userinfo["majia"];
                
               
                //根据礼物id查询礼物单价及名称
                $giftinfo=Db::instance('db1')->select('nmoney,giftname')->from("wht_gifts")->where("giftid=$giftid")->query();
                    
               $danjian=$giftinfo[0]["nmoney"];//获取礼物单价
               $giftname =$giftinfo[0]["giftname"];//获取礼物名称
                
                //获取当前用户的钻石数，经验值，等级id
                $userxiangguan=Db::instance('db1')->select("ninemoney,expervalue,gradeid")->from("wht_userinfos")->where("userid=$userid")->query();
                //计算发送礼物的总价
                $giftprice=$count*$danjian;
               //获取当前用户的钻石数
                $nowninemoney=$userxiangguan[0]["ninemoney"];
                //获取当前用户的经验值
                $jingyan=$userxiangguan[0]["expervalue"];
                 //如果当前用户的钻石数大于发送礼物的总价
                if($nowninemoney>=floatval($giftprice)){
                    //扣除当前用户的钻石数
                    $balance=$nowninemoney-(float)$giftprice;
                  //计算要增加的经验值
                    $addjingyan=$giftprice*10;
                   //计算增加后用户的经验值
                    $jingyanafter=$jingyan+$addjingyan;
                    //判断当前经验应该是哪个等级
                    $gradeid=$userxiangguan[0]["gradeid"];
                    if($jingyanafter<500){
                        $gradeid=1;
                    }else if($jingyanafter<1000){
                        $gradeid=2;
                    }else if($jingyanafter<1500){
                        $gradeid=3;
                    }else if($jingyanafter<3000){
                        $gradeid=4;
                    }else if($jingyanafter<4500){
                        $gradeid=5;
                    }else if($jingyanafter<6000){
                        $gradeid=6;
                    }else if($jingyanafter<7500){
                        $gradeid=7;
                    }else if($jingyanafter<8000){
                        $gradeid=8;
                    }else if($jingyanafter<9500){
                        $gradeid=9;
                    }else if($jingyanafter<10000){
                        $gradeid=10;
                    }else if($jingyanafter<50000){
                        $gradeid=11;
                    }else if($jingyanafter<100000){
                        $gradeid=12;
                    }else if($jingyanafter<500000){
                        $gradeid=13;
                    }else if($jingyanafter<1000000){
                        $gradeid=14;
                    }else if($jingyanafter<5000000){
                        $gradeid=15;
                    }else if($jingyanafter<10000000){
                        $gradeid=16;
                    }else{
                        $gradeid=17;
                    }

                    //更新用户账户余额,经验值，等级
                    $result=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$balance,'gradeid'=>$gradeid,'expervalue'=>$jingyanafter))
                        ->where("userid=$userid")
                        ->query();

                    //获取主播当前收益
                    $zhuboninemoney=Db::instance('db1')->select("ninemoney,userid")->from("wht_userinfos")->where('roomid="'.$_SESSION["roominfo"]["roomid"].'" and usergrade = "1"')->query();

                    if($zhuboninemoney[0]["ninemoney"]==null){
                        $zhuboninemoney[0]["ninemoney"]=0;
                    }

                    $zhubonow=intval($zhuboninemoney[0]["ninemoney"])+intval($giftprice);

                    //获取主播id
                    //更新当前主播的钻石数
                    $resultzhubo=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$zhubonow))
                        ->where("userid=".$zhuboninemoney[0]["userid"])
                        ->query();
                 //把聊天信息存到数据库中，
                    //定义礼物的聊天信息格式，礼物名称加礼物发送的数量

                    $content=$giftid."X".$count;
                    $timenow=date('H:i');//添加到聊天消息中的礼物时间

                    $touserid=-4;
                    $tonickname="礼物";

                    $messageid = Db::instance('db1')
                        ->insert('wht_webcastmessage')
                        ->cols(array('roomid'=>$roominfo["roomid"],'touserid'=>$touserid,'tonickname'=>$tonickname,'time'=>$timenow,'majia'=>$majia,'userid' => $userinfo["userid"],'gradeid' => $userinfo['gradeid'], 'nickname' => $userinfo["nickname"], 'msgcontent' => $content))->query();
                    $gifts= Db::instance('db1')->select('user_gift_count_money')->from('wht_user_gift_count')->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')->query();
                    if($gifts==null){
                       Db::instance('db1')->insert('wht_user_gift_count')->cols(array('roomid'=>$roominfo["roomid"],'userid'=>$userinfo["userid"],'user_gift_count_money'=>$giftprice))->query();
                    }else{

                        $after=$gifts[0]["user_gift_count_money"]+$giftprice;

                      Db::instance('db1')->update("wht_user_gift_count")->cols($a=array('user_gift_count_money'=>$after))
                          ->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')
                            ->query();

                    }
                    $timenow=date("H:i");
                    //插入用户礼物交易信息
                    $msgid= Db::instance('db1')->insert('wht_usergift')
                        ->cols(array('frmuserid'=>$userinfo["userid"],'touserid'=>$zhuboninemoney[0]["userid"],'total'=>$giftprice,'time'=>$timenow,'roomid'=>$roominfo["roomid"],'count'=>$count,'giftid'=>$giftid,'webcastkey'=>$webcastkey,'giftprice'=>$giftprice,'giftname'=>$giftname))->query();

                    if($msgid>0&&$result>0){
                        $sendMsg=array('type'=>'gifts','time'=>$timenow,'frmuserid'=>$userinfo["userid"],'majia'=>$majia,'ninemoney'=>$balance,'gradeid'=>$userinfo["gradeid"],'count'=>$count,'headimg'=>$userinfo["headimage"],'nickname'=>$userinfo["nickname"],'webcastkey'=>$webcastkey,'giftid'=>$giftid,'giftprice'=>$giftprice,'giftname'=>$giftname,'code'=>'11');

                        Gateway::joinGroup($client_id, $roominfo["roomid"]);
                        Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                    }

                }else{
                    $sendMsg=array('type'=>'gifts','msg'=>'钻石不够','code'=>'3');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));//只给当前用户发送
                }
                break;
            case "award":
                //获取用户id
                $userid=$_SESSION["userid"];
                //判断是否是本站用户
                if($userid<=0){
                    $sendMsg=array('type' => 'award', 'msg' => 'you are  not a user ,canot sendgift,plase register','code'=>'0');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //获取房间信息
                $roominfo = $_SESSION['roominfo'];
                //判断礼物接受接受者是否为空或不存在。
                if(!isset($message_data["tuserid"]) || empty($message_data["tuserid"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'tuserid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if(!isset($message_data["tname"]) || empty($message_data["tname"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'tname canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断webcastkey是否为空，或者不存在
                if(!isset($message_data["webcastkey"]) || empty($message_data["webcastkey"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'webcastkey canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断giftprice是否为空，或者不存在
                if(!isset($message_data["giftprice"]) || empty($message_data["giftprice"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'giftprice canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断礼物名称是否为空，或者不存在
                if(!isset($message_data["giftname"]) || empty($message_data["giftname"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'giftname canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断礼物id是否为空或者不存在
                if(!isset($message_data["giftid"]) || empty($message_data["giftid"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'giftid canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                //判断选择数量是否为空或者不存在
                if(!isset($message_data["xznum"]) || empty($message_data["xznum"])){
                    $sendMsg=array('type' => 'award', 'msg' => 'xznum canot is empty','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                try {
                    $count=$message_data["xznum"];
                    $tuserid = $message_data["tuserid"];
                    $tname = $message_data["tname"];
                    $webcastkey = $message_data["webcastkey"];
                    $danjian = $message_data["giftprice"];
                    $giftname = $message_data["giftname"];
                    $giftid = $message_data["giftid"];
                }catch(Exception $e){
                    $sendMsg=array('type' => 'award', 'msg' => 'parameter error ','exception'=>$e,'code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                  $userinfo= $_SESSION['userinfo'];//获取用户信息
                  $majia=$userinfo["majia"];
                //根据用户id查询用户帐号钻石数
                  $userninemoey=Db::instance('db1')->select("ninemoney,expervalue,gradeid")->from("wht_userinfos")->where("userid=$userid")->query();
                //计算用户要扣除的钻石数
                $giftprice=$count*$danjian;
                //获取用户当前钻石数
                $nowninemoney=$userninemoey[0]["ninemoney"];
                //判断用户当前钻石数是否有能力购买本次礼物
                if($nowninemoney>=intval($giftprice)){
                    $jingyan=$userninemoey[0]["expervalue"];
                    //计算要增加的经验值
                    $addjingyan=$giftprice*10;
                    //计算增加后用户的经验值
                    $jingyanafter=$jingyan+$addjingyan;
                    //判断当前经验应该是哪个等级
                    $gradeid=$userninemoey[0]["gradeid"];

                    if($jingyanafter<500){
                        $gradeid=1;
                    }else if($jingyanafter<1000){
                        $gradeid=2;
                    }else if($jingyanafter<1500){
                        $gradeid=3;
                    }else if($jingyanafter<3000){
                        $gradeid=4;
                    }else if($jingyanafter<4500){
                        $gradeid=5;
                    }else if($jingyanafter<6000){
                        $gradeid=6;
                    }else if($jingyanafter<7500){
                        $gradeid=7;
                    }else if($jingyanafter<8000){
                        $gradeid=8;
                    }else if($jingyanafter<9500){
                        $gradeid=9;
                    }else if($jingyanafter<10000){
                        $gradeid=10;
                    }else if($jingyanafter<50000){
                        $gradeid=11;
                    }else if($jingyanafter<100000){
                        $gradeid=12;
                    }else if($jingyanafter<500000){
                        $gradeid=13;
                    }else if($jingyanafter<1000000){
                        $gradeid=14;
                    }else if($jingyanafter<5000000){
                        $gradeid=15;
                    }else if($jingyanafter<10000000){
                        $gradeid=16;
                    }else{
                        $gradeid=17;
                    }



                    //可以购买，扣除用户钻石
                    $balance=$nowninemoney-intval($giftprice);
                    //更新用户账户余额，扣除本次发送礼物的钻石数
                    $result=Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$balance,'gradeid'=>$gradeid,'expervalue'=>$jingyanafter))
                        ->where("userid=$userid")
                        ->query();

                    if(!($result>0)){
                        var_dump("扣款失败");
                        $sendMsg=array('type' => 'award', 'msg' => '扣款失败 ','code'=>'2');
                        Gateway::sendToCurrentClient(json_encode($sendMsg));
                        return;
                    }

                    //更新赠送人余额
                    $zhobo=Db::instance('db1')->select("ninemoney")->from("wht_userinfos")->where("userid=$tuserid")->query();

                    if($zhobo[0]["ninemoney"]==null){
                        $zhobo[0]["ninemoney"]=0;
                    }


                    $zhuboafter=intval($zhobo[0]["ninemoney"])+intval($giftprice);
                    //更新账户余额，增加主播收益
                   Db::instance('db1')->update("wht_userinfos")->cols($a=array('ninemoney'=>$zhuboafter))
                        ->where("userid=$tuserid")
                        ->query();


                    $content=$giftid."X".$count;
                    $timenow=date('H:i');//添加到聊天消息中的礼物时间



                    $username=$userinfo["nickname"];//获取当前用户昵称

                    //如果当前用户
                    if(is_numeric($userinfo["nickname"])){
                        $username = substr_replace($userinfo["nickname"],'****',3,4);
                    }


                    if(self::is_email($userinfo["nickname"])){
                        $username = substr_replace($userinfo["nickname"],'****',0,4);
                    }

                    $touserid=-4;
                    $tonickname="礼物";
                    $messageid = Db::instance('db1')
                        ->insert('wht_webcastmessage')
                        ->cols(array('roomid'=>$roominfo["roomid"],'touserid'=>$touserid,'tonickname'=>$tonickname,'webaddress'=>$webcastkey,'time'=>$timenow,'majia'=>$majia,'userid' => $userinfo["userid"],'gradeid' => $userinfo['gradeid'], 'nickname' => $username, 'msgcontent' => $content))->query();


                    $gifts= Db::instance('db1')->select('user_gift_count_money')->from('wht_user_gift_count')->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')->query();

                    if($gifts==null){
                        Db::instance('db1')->insert('wht_user_gift_count')->cols(array('roomid'=>$roominfo["roomid"],'userid'=>$userinfo["userid"],'user_gift_count_money'=>$giftprice))->query();
                    }else{

                        $after=$gifts[0]["user_gift_count_money"]+$giftprice;

                        Db::instance('db1')->update("wht_user_gift_count")->cols($a=array('user_gift_count_money'=>$after))
                            ->where('roomid="'.$roominfo["roomid"].'" and userid = "'.$userinfo["userid"].'"')
                            ->query();

                    }




                    //插入用户礼物交易信息
                    $msgid= Db::instance('db1')->insert('wht_usergift')->cols(array('frmuserid'=>$userinfo["userid"],'time'=>$timenow,'total'=>$giftprice,'count'=>$count,'giftid'=>$giftid,'touserid'=>$tuserid,'webcastkey'=>$webcastkey,'giftprice'=>$giftprice,'giftname'=>$giftname,'roomid'=>$roominfo["roomid"]))->query();
                    //如果插入礼物记录成功和扣款成功

                    if($msgid>0){
                        $sendMsg=array('type'=>'award','frmuserid'=>$userinfo["userid"],'time'=>$timenow,'majia'=>$userinfo["majia"],'tname'=>$tname,'ninemoney'=>$balance,'gradeid'=>$userinfo["gradeid"],'count'=>$count,'headimg'=>$userinfo["headimage"],'nickname'=>$username,'tuserid'=>$tuserid,'webcastkey'=>$webcastkey,'giftid'=>$giftid,'giftprice'=>$giftprice,'giftname'=>$giftname,'code'=>'11');

                        Gateway::joinGroup($client_id, $roominfo["roomid"]);
                        Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                    }

                }else{

                    $sendMsg=array('type'=>'award','msg'=>'钻石不够','code'=>'3');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));//只给当前用户发送
                }

                break;
            case "close":
                if (!isset($message_data['roomid']) || empty($message_data['roomid']))//判断房间是否存在或为空
                {
                    $sendMsg=array('type' => 'close', 'msg' => 'roomid not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }
                if (!isset($message_data['webcastid']) || empty($message_data['webcastid']))//判断房间是否存在或为空
                {
                    $sendMsg=array('type' => 'close', 'msg' => 'webcastid not exist or is empty ','code'=>'1');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }

                try{
                    $roomid=$message_data['roomid'];
                    $webcastid=$message_data['webcastid'];
                }catch(Exception $e){
                    $sendMsg=array('type' => 'close', 'msg' => 'roomid not exist or is empty ','code'=>'2');
                    Gateway::sendToCurrentClient(json_encode($sendMsg));
                    return;
                }


                $clients=Gateway::getClientCountByGroup($roomid);
                var_dump($clients);



                $ninemoney=Db::instance("db1")->select('giftprice')->from('wht_usergift')->where('webcastid='.$webcastid)->query();


                var_dump($ninemoney);

                $count=0;
                var_dump(count($ninemoney));
                if(count($ninemoney)==0){
                    $count=0;
                }else{


                    var_dump("记录不为0");
                   var_dump($ninemoney[0]["giftprice"]);
                    for($i=0;$i<count($ninemoney);$i++){
                        var_dump("累加之前");
                        var_dump($count);
                        $count+=$ninemoney[$i]["giftprice"];
                        var_dump("累加之后");
                        var_dump($count);
                    }

                    var_dump($count);
                }



                $sendMsg = array('type' => 'close','person'=>$clients,'msg'=>'收获钻石数','ninemoney'=>$count,'code'=>'11');
                Gateway::sendToCurrentClient(json_encode($sendMsg));
                return;
                break;
            case "heart":
                $roominfo=$_SESSION["roominfo"];
                 $sendMsg=array('type' => 'heart');
                Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                return;
                break;
            case "closeuser":
                //拉黑并踢出
                $userid=$message_data['userid'];//获取用户传过来的userid
                $nickname=$message_data['nickname'];//以及获取用户名
               /* $d=Gateway::getClientSessionsByGroup($_SESSION["roominfo"]["roomid"]);*/
                if($userid==0){
                    //如果是游客，通过昵称找到clientid
                    $client_id=Gateway::getClientIdByUid($nickname);
                }else{
                    //如果是用户，通过用户id找到clientid
                    $client_id=Gateway::getClientIdByUid($userid);
                }

               
                //根据clientid找到当前用户的session数组
                $sessio=Gateway::getSession($client_id[0]);

                //获取当前用户session数组
                $userip=$sessio["ip"];

                //获取当前房间号
                $roomid=$_SESSION["roominfo"]["roomid"];

                $time=date('Y-h-d-H:i:s');//获取当前时间
                //把该信息插入到数据库中

                if($userid==0){
                    //如果被踢用户是游客
                    $redpacketid= Db::instance('db1')->insert('wht_blacklist')->cols(array('userid'=>$userid,'ip'=>$userip,'roomid'=>$roomid,'nick'=>$sessio["touristnickname"]))->query();
                }else{

                    $redpacketid= Db::instance('db1')->insert('wht_blacklist')->cols(array('userid'=>$userid,'ip'=>$userip,'roomid'=>$roomid,'nick'=>$sessio["userinfo"]["nickname"]))->query();
                }
               //把当前踢人信息传给用户
              //  Gateway::sendToCurrentClient(json_encode($sendMsg));
                $sendMsg=array('type'=>'inblack');

                Gateway::sendToClient($client_id[0], json_encode($sendMsg));

                
            case "concern":
                
                $userinfo=$_SESSION["userinfo"];
                $username=$userinfo["nickname"];//获取当前用户昵称

                        //如果当前用户
                        if(is_numeric($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',3,4);
                        }

                        if(self::is_email($userinfo["nickname"])){
                            $username = substr_replace($userinfo["nickname"],'****',0,4);
                        }
                  
                   $roominfo= $_SESSION['roominfo'];  
                       
                     
                       
                       
                    $time=date('H:i');
                    
                    $sendMsg=array('type' => 'concern',"nickname"=>$username,"time"=>$time,"code"=>"11","gradeid"=>$userinfo["gradeid"]);
                    var_dump($sendMsg);
                   Gateway::sendToGroup($roominfo["roomid"], json_encode($sendMsg));
                        
                
                
                
                return;
                break;
            default:
                return;
                break;
        }
    }





    /**
     * 判断某个字符串是否是邮箱格式
     * @param type $email
     * @return type
     */
    public static function  is_email($email){
        return strlen($email) > 6 && preg_match("/^[\w\-\.]+@[\w\-]+(\.\w+)+$/",$email);
    }


    public static function onredis(){
        //CHSHI
        $host = "r-bp189ba69c1505c4.redis.rds.aliyuncs.com";
        $port = 6379;
        $user ="r-bp189ba69c1505c4";
        $pwd = "Haoyuecm20161010jsbx";
//      $redis = new Redis();

             $redisco='redis';
             $redis= Lib\RedisDb::instance($redisco);
             
//        if ($redis->connect($host, $port) == false) {
//            die($redis->getLastError());
//        }
             
        /* user:password 拼接成AUTH的密码 */
        if ($redis->auth($user . ":" . $pwd) == false) {
            die($redis->getLastError());
        }
        return $redis;
    }

    /**
     * 简单对称加密算法之解密
     * @param String $string 需要解密的字串
     * @param String $skey 解密KEY
     * @author Anyon Zou <zoujingli@qq.com>
     * @date 2013-08-13 19:30
     * @update 2014-10-10 10:10
     * @return String
     */
    public static function decode($string = '', $skey = '9dcjhaoyuezhibojiami') {
        $strArr = str_split(str_replace(array('O0O0O', 'o000o', 'oo00o'), array('=', '+', '/'), $string), 2);
        $strCount = count($strArr);
        foreach (str_split($skey) as $key => $value)
            $key <= $strCount  && isset($strArr[$key]) && $strArr[$key][1] === $value && $strArr[$key] = $strArr[$key][0];
        return base64_decode(join('', $strArr));
    }



    public static function onClose($client_id)
    {
        // 从房间的客户端列表中删除
        if(isset($_SESSION['roominfo']))
        {
            //获取房间id
            $roominfo = $_SESSION['roominfo'] ;//获取房间信息
            $room_id=$roominfo["roomid"];//获取房间号

            //获取用户id

            $userid = $_SESSION['userid'];//获取用户id
            if($userid!=0){
                $userinfo= $_SESSION['userinfo'];//获取用户信息
            }

         

            //获取在线人数
            $clients=Gateway::getClientCountByGroup($room_id);
            //定义我们七家直播室的人员基数
              $baseperoson=array(1,2,3,4,5,6,7,77,88,90,92,105,111,112);
                if(in_array($room_id,$baseperoson)){
                    $base=21000;
                    $clients=$clients+$base;
                    
                    
                     if($room_id==6){
                            $jinri=200;
                            $clients=  $clients+$jinri;
                        }
                }else{
                    $analyst= Site::$analyst;
                    $analystlist=explode(",",$analyst);
                    
                    if(in_array($room_id,$analystlist)){
                        $base=1000;
                        $clients=$clients+$base;
                    }
                    
                }

                
                

            if($userid==0){
                $login = array('type' => 'userout', 'userid' => $userid,'person'=>$clients);
            }else{
                $login = array('type' => 'userout', 'userid' => $userid,'nickname'=>$userinfo['nickname'],'gradeid'=>$userinfo['gradeid'],'person'=>$clients);
            }
            //传回人员变动信息

            // Gateway::joinGroup($client_id, $room_id);
            $resu=Gateway::sendToGroup($room_id, json_encode($login));
            if($userid==0){
                $_SESSION['roominfo'] =null;
                $_SESSION['userid']=null;
            }else{
                $_SESSION['roominfo'] =null;
                $_SESSION['userid']=null;
                $_SESSION['userinfo']=null;
            }



        }



    }

    /**
     * 公共错误返回
     * @param $msg 需要打印的错误信息
     * @param $code 默认打印300信息
     */
//         public static function myApiPrint($type='',$msg='',$code=300,$data=''){
//             $result = array(
//                 'type'=>$type,
//                 'code' => $code,
//                 'msg' => $msg,
//                 'result' => $data
//             );
//             return json_encode($result);
//         }

//    }
    
    
 
/**
 * 计算参数签名
 * $params 请求参数
 * $secretKey secretKey
 */
public static function gen_signature($secretKey, $params){
    ksort($params);
    $buff="";
    foreach($params as $key=>$value){
        $buff .=$key;
        $buff .=$value;
    }
    $buff .= $secretKey;
    return md5($buff);
}
/**
 * 将输入数据的编码统一转换成utf8
 * @params 输入的参数
 * @inCharset 输入参数对象的编码
 */
public static function toUtf8($params){
    $utf8s = array();
    foreach ($params as $key => $value) {
      $utf8s[$key] = is_string($value) ? mb_convert_encoding($value, "utf8",INTERNAL_STRING_CHARSET) : $value;
    }
    return $utf8s;
}
/**
 * 反垃圾请求接口简单封装
 * $params 请求参数
 */
public static function check($params){
    $params["secretId"] = SECRETID;
    $params["businessId"] = BUSINESSID;
    $params["version"] = VERSION;
    $params["timestamp"] = sprintf("%d", round(microtime(true)*1000));// time in milliseconds
    $params["nonce"] = sprintf("%d", rand()); // random int
    $params = self::toUtf8($params);
    $params["signature"] = self::gen_signature(SECRETKEY, $params);
    // var_dump($params);
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'timeout' => API_TIMEOUT, // read timeout in seconds
            'content' => http_build_query($params),//将数组转换成url
        ),
    );
    $context  = stream_context_create($options);
    $result = file_get_contents(API_URL, false, $context);
    return json_decode($result, true);
}
// 简单测试
public static function main($data,$dataid,$userid,$userip){
    echo "mb_internal_encoding=".mb_internal_encoding()."\n";//获取内部字符串编码
    //定义参数
    $params = array(
        "dataId"=>$dataid,//数据的唯一id
        "content"=>$data,//数据内容
        "dataOpType"=>"1",//数据状态，1新增
        "dataType"=>"1",
        "ip"=>$userip,
        "account"=>$userid,
        "deviceType"=>"4",//用户设备
        "deviceId"=>"92B1E5AA-4C3D-4565-A8C2-86E297055088",
        "callback"=>"ebfcad1c-dba1-490c-b4de-e784c2691768",
        "publishTime"=>round(microtime(true)*1000)
    );
    $ret = self::check($params);

    if ($ret["code"] == 200) {
        $action = $ret["result"]["action"];
        if ($action == 1) {// 内容正常，通过
           return 1;
        } else if ($action == 2) {// 垃圾内容，删除
             return 2;
        } else if ($action == 3) {// 嫌疑内容
           return 3;
        }
    }else{
        // error handler
    }
}






}
