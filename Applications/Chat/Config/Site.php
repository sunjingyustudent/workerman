<?php
namespace Config;


 /** 产品密钥ID，产品标识 */
define("SECRETID", "15e420bc38c0c50dc246c4a851afef5c");
/** 产品私有密钥，服务端生成签名信息使用，请严格保管，避免泄露 */
define("SECRETKEY", "4c6ade635cd7c22b8faa1a87e3639a7a");
/** 业务ID，易盾根据产品业务特点分配 */
define("BUSINESSID", "9343ebe33fa067b9eea6af8c5d86cf8e");
/** 易盾反垃圾云服务文本在线检测接口地址 */
define("API_URL", "https://api.aq.163.com/v2/text/check");
/** api version */
define("VERSION", "v2");
/** API timeout*/
define("API_TIMEOUT", 1);
/** php内部使用的字符串编码 */
define("INTERNAL_STRING_CHARSET", "auto");
class Site
{
   public static $sitename = "wht138";

   public static $appintMsgCount=5;
   public static $webintMsgCount=25;
   public static $yundun=true;
   public static $analyst="119,120,121,122,132,136,128,130,129,131,127,123,126,152,146,142,139,135,144,138,151,125,124,148,147,133,145,140,141,137,143,150,149,157,158,153,155,154,156,174";
  
}
