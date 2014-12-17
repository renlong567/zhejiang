<?php

/**
 * 河南新华一卡通
 *
 * @author 7sins
 * @version 1.0
 */
class giftcards extends DES
{

//    protected $_dsn = 'oci:dbname=(DESCRIPTION =
//                            (ADDRESS_LIST =
//                              (ADDRESS = (PROTOCOL = TCP)(HOST = 192.168.51.21)(PORT = 1521))
//                            )
//                            (CONNECT_DATA =
//                              (SERVICE_NAME = edidb)
//                            )
//                        )';
//    protected $_dsn = 'oci:dbname=10.0.0.59:1521/edidb;charset=UTF8';
//    protected $user = 'testdb';
//    protected $passwd = 'testdb';
//    protected $_dsn = 'oci:dbname=10.0.0.41:1521/orcl;charset=UTF8'; //外网测试
    protected $_dsn = 'oci:dbname=(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=192.168.51.7)(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=orcl)));charset=AL32UTF8;';
    protected $_user = 'yanfatest';
    protected $_passwd = 'yanfatest';
    protected $db;
    protected $_jcdhkId;    //基层店十位店号
    protected $_orderId;    //小票号
    protected $_posId;   //工作站号
    protected $_workerId;    //工号

    public function __construct()
    {
        try
        {
            $this->db = new PDO($this->_dsn, $this->_user, $this->_passwd);
        }
        catch (Exception $ex)
        {
            $this->warning('PDO', $ex->getMessage());
            $this->message(false, '服务器连接失败,无法进行查询和支付');
        }

        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        parent::DES('fc3ff98e');
    }

    public function __destruct()
    {
        $this->db = NULL;
    }

    public function index()
    {
        exit();
    }

    /**
     * @remark 支付接口
     */
    public function pay()
    {
        $data = $this->transform();

        if (empty($this->_jcdhkId) || empty($this->_orderId) || empty($this->_posId) || empty($this->_workerId))
        {
            $this->message(false, 2);
        }
        $orderid_check_sql = 'SELECT COUNT(*) FROM oneshop_gcardsuselog WHERE ordersn=\'' . $this->_orderId . '\' AND jcdkhid=\'' . $this->_jcdhkId . '\'';
        $orderid_check_result = $this->db->query($orderid_check_sql)->fetchColumn();
        if ($orderid_check_result)
        {
            $this->message(false, 3);
        }

        $checkout = $this->checkout($data);

        if (empty($checkout['new']) && empty($checkout['old']))
        {
            $this->message(false, 1);
        }

        $time = time();
        $expiredtime = strtotime('+3 year');
        if (!empty($checkout['new']))
        {
            $sql_new = 'UPDATE oneshop_giftcards SET expiredtime=' . $expiredtime . ',usedtime=' . $time . ',balance= CASE sn';
            foreach ($checkout['new'] as $key => $value)
            {
                $sql_new .= sprintf(' WHEN \'%s\' THEN balance-' . $value, $key);
            }
            $sql_new .= ' END WHERE sn in(';
            foreach ($checkout['new'] as $key => $value)
            {
                $sql_new .= "'$key'" . ',';
            }
            $sql_new = rtrim($sql_new, ',');
            $sql_new .= ') AND balance>0 AND invokestate>0';
        }
        if (!empty($checkout['old']))
        {
            $sql_old = 'UPDATE oneshop_giftcards SET usedtime=' . $time . ',balance= CASE sn';
            foreach ($checkout['old'] as $key => $value)
            {
                $sql_old .= sprintf(' WHEN \'%s\' THEN balance-' . $value, $key);
            }
            $sql_old .= ' END WHERE sn in(';
            foreach ($checkout['old'] as $key => $value)
            {
                $sql_old .= "'$key'" . ',';
            }
            $sql_old = rtrim($sql_old, ',');
            $sql_old .= ') AND balance>0 AND invokestate>0';
        }

        try
        {
            $this->db->beginTransaction();
            if ($sql_new)
            {
                $this->db->exec($sql_new);
            }
            if ($sql_old)
            {
                $this->db->exec($sql_old);
            }
            $this->db->commit();
        }
        catch (Exception $ex)
        {
            $this->db->rollBack();
            $checkout['ex'] = $ex->getMessage();
            $this->warning(__FUNCTION__, $checkout);
            $this->message(false, 1);   //支付失败
        }

        $log_info = array_merge($checkout['new'], $checkout['old']);
        $this->pay_log($log_info);
        $this->message(true, json_encode($log_info));    //支付成功
    }

    /**
     * @remark 读书卡实时验证
     */
    public function verify()
    {
        $data = $this->transform();

        if (empty($data['cid']))
        {
            $this->message(false, 5);    //未输入卡号
        }

        if (isset($data['verify']))
        {
            $sql = 'SELECT sn,balance,invokestate,expiredtime FROM oneshop_giftcards WHERE sn=\'' . $data['cid'] . '\' AND verify=\'' . $data['verify'] . '\'';
        }
        else
        {
            $key = substr($data['cid'], -2);
            $sn = rtrim($data['cid'], $key);
            $sql = 'SELECT sn,balance,invokestate,expiredtime FROM oneshop_giftcards WHERE lower(sn)=lower(\'' . $sn . '\') AND lower(pwd) LIKE lower(\'' . $key . '%\')';
        }

        $result = $this->db->query($sql)->fetchObject();

        if (!$result)
        {
            $this->message(false, 1);    //该卡不存在或校验码错误
        }
        if ($result->BALANCE == 0)
        {
            $this->message(false, 2);    //该卡余额为零
        }
        if ($result->INVOKESTATE == 0)
        {
            $this->message(false, 3);    //该卡未激活
        }
        if ($result->EXPIREDTIME < time() && $result->EXPIREDTIME != 0)
        {
            $this->message(false, 4);    //该卡已过期
        }

        $ok[$result->SN] = $result->BALANCE;
        $this->message(true, $ok);
    }

    /**
     * @remark 退货
     */
    public function refund()
    {
        $data = $this->transform();

        if (empty($this->_jcdhkId) || empty($this->_orderId) || empty($this->_posId) || empty($this->_workerId))
        {
            $this->message(false, 3);
        }

        $check_sql = 'SELECT count(*),sum(amount),description FROM oneshop_gcardsuselog WHERE ordersn=\'' . $this->_orderId . '\' AND jcdkhid=\'' . $this->_jcdhkId . '\' GROUP BY description ORDER BY description ASC';
        $check_rs = $this->db->query($check_sql)->fetchAll();

        if (!$check_rs)
        {
            $this->message(false, 2);    //小票号不存在
        }
        if ($check_rs[0][1] < $data['amount'])
        {
//            $this->message(false, $check_rs[0][1]);    //退款金额超过该小票总消费金额
            $this->message(false, 5);
        }
        if ($check_rs[0][1] <= $check_rs[1][1])
        {
            $this->message(false, 4);    //该小票已无可退金额
        }

        $sel_sql = 'SELECT l.giftcardssn,g.payvalue-g.balance as refund_space FROM oneshop_gcardsuselog l,oneshop_giftcards g WHERE l.giftcardssn=g.sn AND g.balance<g.payvalue AND description=\'实体店刷卡消费\' AND l.ordersn=\'' . $this->_orderId . '\' AND jcdkhid=\'' . $this->_jcdhkId . '\'';
        $result = $this->db->query($sel_sql)->fetchAll();

        $total_refund = $data['amount'];
        $ok_arr = array();
        $sql = 'UPDATE oneshop_giftcards SET balance= CASE sn';
        foreach ($result as $value)
        {
            if ($value['REFUND_SPACE'] < $total_refund)
            {
                $sql .= sprintf(' WHEN \'%s\' THEN balance+' . $value['REFUND_SPACE'], $value['GIFTCARDSSN']);
                $total_refund -= $value['REFUND_SPACE'];
                $ok_arr[$value['GIFTCARDSSN']] = $value['REFUND_SPACE'];
            }
            else
            {
                $sql .= sprintf(' WHEN \'%s\' THEN balance+' . $total_refund, $value['GIFTCARDSSN']);
                $ok_arr[$value['GIFTCARDSSN']] = $total_refund;
                $total_refund = 0;
                break;
            }
        }

        $sql .= ' END WHERE sn IN(';
        foreach ($ok_arr as $key => $value)
        {
            $sql .= '\'' . $key . '\',';
        }
        $sql = rtrim($sql, ',');
        $sql .= ')';

        if ($total_refund != 0)
        {
            $data['total_refund'] = $total_refund;
            $this->error_record($data);
        }

        try
        {
            $this->db->beginTransaction();
            $this->db->exec($sql);
            $this->db->commit();
        }
        catch (Exception $ex)
        {
            $this->db->rollBack();
            $ok_arr['ex'] = $ex->getMessage();
            $this->warning(__FUNCTION__, $ok_arr);
            $this->message(false, 1);    //退款失败
        }

        $this->refund_log($ok_arr);

        if (!empty($data['rollback']))  //撤销用
        {
            $sql = 'SELECT sn,balance FROM oneshop_giftcards WHERE';
            foreach ($ok_arr as $k => $val)
            {
                $sql .= ' sn=\'' . $k . '\' OR';
            }
            $sql = rtrim($sql, 'OR');
            $new_balance = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($new_balance as $v)
            {
                $ok_arr[$v['SN']] .= ' ' . $v['BALANCE'];
            }
        }

        $this->message(true, json_encode($ok_arr));
    }

    /**
     * @remark 生成残卡校验码
     */
    public function create_vcode()
    {
        $data = $this->transform();

        $sql = 'SELECT id,verify FROM oneshop_giftcards WHERE sn=\'' . $data['cid'] . '\'';
        $result = $this->db->query($sql)->fetchObject();

        if (!$result->ID)
        {
            $this->message(false, 3);   //该卡不存在
        }

        switch ($result->VERIFY)
        {
            case null:
                $time = time();
                $random = mt_rand();
                $temp = $time + $random;
                $vcode = substr(md5($temp), 0, 3);
                $sql = 'UPDATE oneshop_giftcards SET verify=\'' . $vcode . '\' WHERE sn=\'' . $data['cid'] . '\'';
                try
                {
                    $this->db->beginTransaction();
                    $this->db->exec($sql);
                    $this->db->commit();
                }
                catch (Exception $ex)
                {
                    $this->db->rollBack();
                    $data['ex'] = $ex->getMessage();
                    $this->warning(__FUNCTION__, $data);
                    $this->message(false, 2);    //校验码生成失败,请重试
                }
                $this->message(true, $vcode);
                break;

            default:
                $this->message(false, 1);    //校验码已存在
                break;
        }
    }

    /**
     * @remark 补打小票
     */
    public function print_again()
    {
        $data = $this->transform();
        $return_data = array();

        switch ($data['type'])
        {
            case 1:
                $description = '实体店刷卡消费';
                break;
            case 0:
                $description = '实体店退款';
                break;
            default:
                $this->warning(__FUNCTION__, $data);
                exit();
        }
        $sql = 'SELECT g.sn,g.balance,l.amount FROM oneshop_gcardsuselog l,oneshop_giftcards g WHERE l.giftcardssn=g.sn AND l.ordersn=\'' . $this->_orderId . '\' AND l.jcdkhid=\'' . $this->_jcdhkId . '\' AND l.description=\'' . $description . '\'';
        $result = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (!$result)
        {
            $this->message(false, 1);
        }

        foreach ($result as $value)
        {
            $return_data[$value['SN']] = $value['AMOUNT'];   //本次金额
            $return_data[$value['SN']] .= ' ' . $value['BALANCE'];  //当前余额
        }

        $this->message(true, json_encode($return_data));
    }

    /**
     * @remark 计算总价及各卡应扣金额
     * @param array $data
     * @return array
     */
    protected function checkout($data)
    {
        $sn_where = '';
        foreach ($data['cid'] as $sn => $value)
        {
            if ($value > 0)
            {
                $sn_where .= ' sn=\'' . $sn . '\' OR';
            }
            else
            {
                unset($data['cid'][$sn]);
            }
        }
        $sn_where = rtrim($sn_where, 'OR');

        if (empty($sn_where))
        {
            $this->message(false, 1);
        }

        $sql = 'SELECT sn,balance,expiredtime FROM oneshop_giftcards WHERE (' . $sn_where . ') AND balance>0';
        $gcardsinfo = $this->db->query($sql)->fetchAll();

        $result = array('new' => array(), 'old' => array());
        foreach ($gcardsinfo as $value)
        {
            if ($data['cid'][$value['SN']] > $value['BALANCE'])
            {
                $this->message(false, $value['SN'] . ',支付金额超出该卡余额,无法使用,请联系云书网客服 400-606-5777');
            }

            switch ($value['EXPIREDTIME'])
            {
                case 0:
                    $k = 'new';
                    break;
                default:
                    $k = 'old';
                    break;
            }

            $result[$k][$value['SN']] = $data['cid'][$value['SN']];
        }

        return $result;
    }

    /**
     * @remark 生成支付记录
     * @param array $data
     */
    protected function pay_log($data)
    {
        $time = time();
        $sql = 'INSERT ALL ';
        foreach ($data as $key => $value)
        {
            $sql .= "INTO oneshop_gcardsuselog (userid,addtime,amount,giftcardssn,ordersn,description,jcdkhid,posid,workerid) VALUES (0,$time,$value,'$key','$this->_orderId','实体店刷卡消费','$this->_jcdhkId','$this->_posId','$this->_workerId') ";
        }
        $sql .= 'SELECT * FROM DUAL';

        try
        {
            $this->db->beginTransaction();
            $this->db->exec($sql);
            $this->db->commit();
        }
        catch (Exception $ex)
        {
            $this->db->rollBack();
            $data['ex'] = $ex->getMessage();
            $this->warning(__FUNCTION__, $data);
        }
    }

    /**
     * @remark 生成退款记录
     * @param array $data
     */
    protected function refund_log($data)
    {
        $time = time();
        $sql = 'INSERT ALL ';
        foreach ($data as $key => $value)
        {
            $sql .= "INTO oneshop_gcardsuselog (userid,addtime,amount,giftcardssn,ordersn,description,jcdkhid,posid,workerid) VALUES (0,$time,$value,'$key','$this->_orderId','实体店退款','$this->_jcdhkId','$this->_posId','$this->_workerId') ";
        }
        $sql .= 'SELECT * FROM DUAL';

        try
        {
            $this->db->beginTransaction();
            $this->db->exec($sql);
            $this->db->commit();
        }
        catch (Exception $ex)
        {
            $this->db->rollBack();
            $data['ex'] = $ex->getMessage();
            $this->warning(__FUNCTION__, $data);
        }
    }

    /**
     * @remark 状态信息
     * @param bool $state
     * @param string $data
     */
    protected function message($state = false, $data = '')
    {
        $arr = array('state' => $state, 'data' => $data);
        $result = $this->encode(json_encode($arr));
        echo $result;
        exit();
    }

    /**
     * @remark 异常纪录
     * @param array $data
     */
    protected function error_record($data)
    {
        $time = time();
        $sql = "INSERT INTO oneshop_exce_log (addtime,logtype,jcdkhid,ordersn,amount,description,posid,workerid) VALUES ($time,0,'$this->_jcdhkId','$this->_orderId','$data[total_refund]','退款异常','$this->_posId','$this->_workerId')";

        try
        {
            $this->db->beginTransaction();
            $this->db->exec($sql);
            $this->db->commit();
        }
        catch (Exception $ex)
        {
            $this->db->rollBack();
            $data['ex'] = $ex->getMessage();
            $this->warning(__FUNCTION__, $data);
        }
    }

    /**
     * @remark 转换接受数据
     * @return array
     */
    protected function transform()
    {
        $info = empty($_POST['info']) ? '' : trim($_POST['info']);
        $data = $this->decode($info);
        $result = json_decode($data, true);
        if (empty($result))
        {
            $this->message(false);
        }
        $this->filter_data($result);
        $this->_orderId = isset($result['orderid']) ? trim($result['orderid']) : '';
        $this->_jcdhkId = isset($result['jcdkhid']) ? trim($result['jcdkhid']) : '';
        $this->_posId = isset($result['posid']) ? trim($result['posid']) : '';
        $this->_workerId = isset($result['workerid']) ? trim($result['workerid']) : '';
        return $result;
    }

    /**
     * @remark 系统异常警告
     * @param string $code
     * @param array $data
     */
    protected function warning($code = '', $data = array())
    {
        $data = json_encode($data);
        require_once '/PHPMailer/PHPMailerAutoload.php';
        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->Host = 'smtp.exmail.qq.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'admin@iyunshu.com';
        $mail->Password = 'iyunshu123';
        $mail->Port = '25';
        $mail->CharSet = 'UTF-8';
        $mail->From = 'admin@iyunshu.com';
        $mail->FromName = '云书网读书卡系统';
        $mail->addAddress('51109638@qq.com');
//        $mail->addAddress('541561049@qq.com');
//        $mail->addAddress('393802411@qq.com');
        $mail->WordWrap = 70;
        $mail->isHTML(true);
        $mail->Subject = $code . ' Fail Warning';
        $mail->Body = "$code fail.Please check out.<br />"
                . "Jcdkhid:$this->_jcdhkId<br />"
                . "OrderId:$this->_orderId<br />"
                . "PosId:$this->_posId<br />"
                . "WorkerId:$this->_workerId<br />"
                . "Json Data:$data<br />"
                . '<hr />'
                . 'This email sent automatically by the system.';
        $mail->send();
    }

    /**
     * @remark 加密算法
     * @return string
     */
    protected function encode($data)
    {
        $result = $this->encrypt($data);
        return $result;
    }

    /**
     * @remark 解密算法
     * @return json
     */
    protected function decode($data)
    {
        $result = $this->decrypt($data);
        return $result;
    }

    /**
     * @remark 过滤数据
     * @param array $data
     */
    protected function filter_data($data)
    {
        $cut = true;

        if (array_key_exists('cid', $data))
        {
            $cut = false;
            if (is_array($data['cid']))
            {
                foreach ($data['cid'] as $k => $v)
                {
                    if (!ctype_alnum($k) || !is_numeric($v))
                    {
                        exit();
                    }
                }
            }
            elseif (is_string($data['cid']))
            {
                if (!ctype_alnum($data['cid']))
                {
                    exit();
                }
            }
            else
            {
                exit();
            }
        }

        if (array_key_exists('verify', $data))
        {
            $cut = false;
            $subject = substr($data['verify'], 0, 3);
            if (!ctype_alnum($subject))
            {
                exit();
            }
        }

        if (array_key_exists('orderid', $data))
        {
            $cut = false;
            $subject = substr($data['orderid'], 0, 16);
            $pattern = '/^\d{4}-\d{2}-\d{8}$/';
            $check_str = preg_match($pattern, $subject);
            if (!$check_str)
            {
                exit();
            }
        }

        if (array_key_exists('jcdkhid', $data))
        {
            $cut = false;
            $sub_str = substr($data['jcdkhid'], 0, 10);
            if (!is_numeric($sub_str) || $sub_str < 1000000000)
            {
                exit();
            }
        }

        if (array_key_exists('posid', $data))
        {
            $cut = false;
            $check_str = strpos($data['posid'], ';');
            if ($check_str !== false)
            {
                exit();
            }
        }

        if (array_key_exists('workerid', $data))
        {
            $cut = false;
            $check_str = strpos($data['workerid'], ';');
            if ($check_str !== false)
            {
                exit();
            }
        }

        if ($cut)
        {
            exit();
        }
    }

}

//                               _oo0oo_
//                              o8888888o
//                              88" . "88
//                              (| -_- |)
//                              0\  =  /0
//                            ___/`---'\___
//                          .' \\|     |// '.
//                         / \\|||  :  |||// \
//                        / _||||| -:- |||||- \
//                       |   | \\\  -  /// |   |
//                       | \_|  ''\---/''  |_/ |
//                       \  .-\___ '-' ___/-.  /
//                   ____`. .'   /--.--\  `. .'____
//                   ."" '< `.___\_<|>_/___.' >' "".
//                  | | : `- \`.; \ _ /`;.`/ - ` : | |
//                  \ \`_.   \_ ___\ / ___ _/  .-` / /
//             =====`-.____`.____\_____/____.-`____.-`=====
//                               '=---='
//                    客户虐我千百遍，我待客户如初恋
?>