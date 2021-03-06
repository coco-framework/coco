<?php

namespace coco\db;

/**
 * A class for PDO connection
 * @author TTT
 * @date 2016/1/29 9:55
 */
class PDB
{
    protected $pdo;
    protected $readPdo;
    protected $transactions = 0;

    protected $lastSql;
    protected $lastParams;

    /**
     * Master configuration
     * @var array
     */
    protected $config = array(
        'dsn' => 'mysql:host=localhost;dbname=tests;port=3306;charset=utf8',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8',
        'tablePrefix' => '',
        'emulatePrepares' => false,
        'options' => array(
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        )
    );

    /**
     * Slave configuration
     * array(
     *      array(
     *          'dsn' => 'mysql:host=localhost;dbname=tests;port=3306;charset=utf8',
     *          'username' => 'user1',
     *          'password' => 'pwd1',
     *          ...
     *      ),
     *      array(
     *          'dsn' => 'mysql:host=localhost;dbname=tests;port=3306;charset=utf8',
     *          'username' => 'user2',
     *          'password' => 'pwd2',
     *          ...
     *      ),
     *      ...
     * )
     * @var array
     */
    protected $slaveConfig = array();

    public function __construct(array $config, $slave = array())
    {
        $this->config = $this->complete_config($this->config, $config);
        $this->slaveConfig = $slave;
    }

    /**
     * return a master database PDO instance (insert, delete, update)
     * @return \PDO
     */
    public function getPdo()
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $this->pdo = $this->makePdo($this->config);
        return $this->pdo;
    }

    /**
     * create and return a PDO instance
     * @param array $config
     * @return \PDO
     */
    protected function makePdo(array $config)
    {
        $pdo = new \PDO($config['dsn'], $config['username'], $config['password'], $config['options']);

        //false表示不使用PHP本地模拟prepare
        if (constant('PDO::ATTR_EMULATE_PREPARES')) {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $config['emulatePrepares']);
        }

        return $pdo;
    }

    /**
     * 返回用于查询的PDO对象 (如果在事务中，将自动调用getPdo()以确保整个事务均使用主库)
     * @return \PDO
     */
    public function getReadPdo()
    {
        if ($this->transactions >= 1) {
            return $this->getPdo();
        }

        if ($this->readPdo instanceof \PDO) {
            return $this->readPdo;
        }

        if (!is_array($this->slaveConfig) || count($this->slaveConfig) == 0) {
            return $this->getPdo();
        }

        $slaveDbConfig = $this->slaveConfig;
        shuffle($slaveDbConfig);
        do {
            // 取出一个打乱后的从库信息
            $config = array_shift($slaveDbConfig);

            // 使用主库信息补全从库配置
            $config = $this->complete_config($this->config, $config);
            //$config = array_replace_recursive($this->config, $config);

            try {
                $this->readPdo = $this->makePdo($config);
                return $this->readPdo;
            } catch (\PDOException $ex) {
                //
                echo $ex->getMessage();
            }

        } while (count($slaveDbConfig) > 0);

        // 没有可用的从库，直接使用主库
        return $this->readPdo = $this->getPdo();
    }

    /**
     * execute a query SQL and fetch all rows
     * @param string $sql
     * @param array $params
     * @param int $fetchStyle
     * @return array
     */
    public function query($sql, $params = array(), $fetchStyle = \PDO::FETCH_ASSOC)
    {
        $sql = $this->quoteSql($sql);

        $this->lastSql = $sql;
        $this->lastParams = $params;

        $statement = $this->getReadPdo()->prepare($sql);
        if ($statement->execute($params)) {
            $args = func_get_args();
            $args = array_slice($args, 2);
            $args[0] = $fetchStyle;
            if ($fetchStyle == \PDO::FETCH_FUNC) {
                return call_user_func_array(array($statement, 'fetchAll'), $args);
            }
            call_user_func_array(array($statement, 'setFetchMode'), $args);
            return $statement->fetchAll();
        }
        return false;
    }

    /**
     * 执行查询统计类型语句, 返回具体单个值, 常用于COUNT、AVG、MAX、MIN
     * @param $sql
     * @param array $params
     * @return mixed 成功返回数据，失败返回FALSE
     */
    public function queryScalar($sql, $params = array())
    {
        $sql = $this->quoteSql($sql);
        $this->lastSql = $sql;
        $this->lastParams = $params;

        $statement = $this->getReadPdo()->prepare($sql);
        if ($statement && $statement->execute($params) && ($data = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            if (is_array($data) && isset($data[0])) {
                return $data[0];
            }
        }
        return false;
    }

    /**
     * execute a query SQL and fetch a row
     * @param string $sql
     * @param array $params
     * @param int $fetchStyle
     * @return array
     */
    public function queryRow($sql, $params = array(), $fetchStyle = \PDO::FETCH_ASSOC)
    {
        $sql = $this->quoteSql($sql);

        $this->lastSql = $sql;
        $this->lastParams = $params;

        $statement = $this->getReadPdo()->prepare($sql);
        if ($statement->execute($params)) {
            $args = func_get_args();
            $args = array_slice($args, 2);
            $args[0] = $fetchStyle;
            if ($fetchStyle == \PDO::FETCH_FUNC) {
                return call_user_func_array(array($statement, 'fetch'), $args);
            }
            call_user_func_array(array($statement, 'setFetchMode'), $args);
            return $statement->fetch();
        }
        return false;
    }

    /**
     * execute a SQL (type: insert 、delete 、update )，return the number of affected rows
     * @param string $sql
     * @param array $params
     * @return int | bool false
     */
    public function execute($sql, $params = array())
    {
        $sql = $this->quoteSql($sql);
        $this->lastSql = $sql;
        $this->lastParams = $params;
        $statement = $this->getPdo()->prepare($sql);
        if ($statement->execute($params)) {
            return (int)$statement->rowCount();
        }
        return false;
    }

    /**
     * @param string $sql
     * @return string
     */
    protected function quoteSql($sql)
    {
        //parse tableName
        if (preg_match_all("/{{\w+}}/", $sql, $matches)) {
            if (!empty($matches[0])) {
                foreach ($matches[0] as $val) {
                    $table = trim($val, "{{}}");
                    $sql = preg_replace("/$val/", '`' . $this->getTablePrefix() . $table . '`', $sql, 1);
                }
            }
        }
        return $sql;
    }

    /**
     * 返回最后插入行的ID或序列值
     * PDO::lastInsertId
     * @param null $sequence 序列名称
     * @return int|string
     */
    public function getLastInsertId($sequence = null)
    {
        return $this->getPdo()->lastInsertId($sequence);
    }

    /**
     * 开启事务
     */
    public function beginTransaction()
    {
        ++$this->transactions;
        if ($this->transactions == 1) {
            $this->getPdo()->beginTransaction();
        }
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        if ($this->transactions == 1) $this->getPdo()->commit();
        --$this->transactions;
    }

    /**
     * 回滚事务
     */
    public function rollBack()
    {
        if ($this->transactions == 1) {
            $this->transactions = 0;

            $this->getPdo()->rollBack();
        } else {
            --$this->transactions;
        }
    }

    /**
     * 断开数据库链接
     */
    public function disconnect()
    {
        $this->pdo = null;
        $this->readPdo = null;
    }

    /**
     * return last execute sql
     * @return string
     */
    public function getLastSql()
    {
        if (!empty($this->lastParams)) {
            foreach ($this->lastParams as $key => $val) {
                if (strpos($key, ':') === 0) {
                    $this->lastSql = preg_replace("/$key/", $this->getPdo()->quote($val), $this->lastSql, 1);
                } else {
                    $this->lastSql = preg_replace("/\?/", $this->getPdo()->quote($val), $this->lastSql, 1);
                }
            }
        }
        return $this->lastSql;
    }

    /**
     * 返回表前缀
     * @return string
     */
    protected function getTablePrefix()
    {
        return $this->config['tablePrefix'];
    }

    /**
     * complete configuration
     *  if (PHP 5 >= 5.3.0, PHP 7) replace it with function array_replace_recursive
     * @param array $base
     * @param array $new
     * @return array
     */
    protected function complete_config(array $base, array $new)
    {
        foreach ($new as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $kk => $vv) {
                    $base[$k][$kk] = $vv;
                }
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

}
