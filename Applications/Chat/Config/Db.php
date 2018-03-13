<?php
namespace Config;
/**
 * mysql配置
 * User: 李卫星
 * Date: 2016/8/19
 * Time: 0:38
 */

class Db
{
    /**
     * 数据库的一个实例配置，则使用时像下面这样使用
     * $user_array = Db::instance('db1')->select('name,age')->from('users')->where('age>12')->query();
     * 等价于
     * $user_array = Db::instance('db1')->query('SELECT `name`,`age` FROM `users` WHERE `age`>12');
     * @var array
     */


    public static $db1 = array(
        'host'    => 'rdsm614a6mt50f295feoo.mysql.rds.aliyuncs.com',
        'port'    => 3306,
        'user'    => 'haoyuezhibo',
        'password' => 'AbCd1234',
        'dbname'  => 'haoyuezhibotest',
        'charset'    => 'utf8',
//        'host'    => 'rdsm614a6mt50f295feoo.mysql.rds.aliyuncs.com',
//        'port'    => 3306,
//        'user'    => 'root',
//        'password' => 'abcd1234',
//        'dbname'  => 'newwht',
//        'charset'    => 'utf8',
    );

    // Redis连接配置
    public static $redis = array(
        'host'    => 'r-bp189ba69c1505c4.redis.rds.aliyuncs.com',
        'port'    => 6379,
      // 'user' => "r-bp189ba69c1505c4",
      // 'pwd' => "Haoyuecm20161010jsbx",
       
    );





}