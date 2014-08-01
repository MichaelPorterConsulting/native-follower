<?php

use Utipd\NativeFollower\FollowerSetup;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class MysqlSetupTest extends \PHPUnit_Framework_TestCase
{


    public function testSetupTables() {
        $this->getFollowerSetup()->InitializeDatabase();
    }



    protected function getFollowerSetup() {
        $db_name = getenv('DB_NAME');
        if (!$db_name) { throw new Exception("No DB_NAME env var found", 1); }
        $db_host = getenv('DB_HOST');
        if (!$db_host) { throw new Exception("No DB_HOST env var found", 1); }
        $db_port = getenv('DB_PORT');
        if (!$db_port) { throw new Exception("No DB_PORT env var found", 1); }
        $db_user = getenv('DB_USER');
        if (!$db_user) { throw new Exception("No DB_USER env var found", 1); }
        $db_password = getenv('DB_PASSWORD');
        if ($db_password === false) { throw new Exception("No DB_PASSWORD env var found", 1); }


        $db_connection_string = "mysql:host={$db_host};port={$db_port}";
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new FollowerSetup($pdo, $db_name);
    }



}
