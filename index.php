<?php

/**
 * 河南新华一卡通入口文件
 *
 * @author 7sins
 * @version 1.0
 */
date_default_timezone_set('Asia/Shanghai');
ini_set("error_reporting", "E_ALL & ~E_NOTICE");

$app = !empty($_REQUEST['app']) ? trim($_REQUEST['app']) : 'giftcards';
$act = !empty($_REQUEST['act']) ? trim($_REQUEST['act']) : 'index';

class_exists($app) || exit();

$cont = new $app();

method_exists($cont, $act) || exit();

function __autoload($class)
{
    $path = implode("/", explode('_', $class));
    include_once( $path . '.php');
}

$cont->$act();
?>