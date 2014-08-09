<?php

use Utipd\NativeFollower\Follower;
use Utipd\NativeFollower\FollowerSetup;

use Nbobtc\Bitcoind\Bitcoind;
use Nbobtc\Bitcoind\Client;

use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class LiveFollowerBlocksTest extends \PHPUnit_Framework_TestCase
{


    public function testLiveProcessBlocks() {
        if (getenv('NATIVE_RUN_LIVE_TEST') == false) { $this->markTestIncomplete(); }

        $this->getFollowerSetup()->initializeAndEraseDatabase();


        $client = $this->getBitcoinClient();
        $block_height = $client->getblockcount();
        $follower = $this->getFollower();
        $follower->setGenesisBlock($block_height - 1);

        $found_send_tx_map = [];
        $blocks_seen_count = 0;
        $follower->handleNewTransaction(function($bitcoin_transaction, $block_id) use (&$found_send_tx_map, &$blocks_seen_count) {
            ++$blocks_seen_count;
            echo json_encode($bitcoin_transaction, 192)."\n";
        });

        $follower->processAnyNewBlocks();


    }

    ////////////////////////////////////////////////////////////////////////

    protected function getFollower() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo();
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new Follower($this->getBitcoinClient(), $pdo);
    }

    protected function getFollowerSetup() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(false);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new FollowerSetup($pdo, $db_name);
    }

    protected function buildConnectionInfo($with_db=true) {
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

        if ($with_db) {
            $db_connection_string = "mysql:dbname={$db_name};host={$db_host};port={$db_port}";
        } else {
            $db_connection_string = "mysql:host={$db_host};port={$db_port}";
        }

        return [$db_connection_string, $db_user, $db_password, $db_name];
    }

    protected function getBitcoinClient() {
        if (!isset($this->bitcoin_client)) {
            $host         = getenv('NATIVE_RPC_HOST') ?: 'localhost';
            $port         = getenv('NATIVE_RPC_PORT') ?: '8333';
            $rpc_user     = getenv('NATIVE_RPC_USER') ?: null;
            $rpc_password = getenv('NATIVE_RPC_PASSWORD') ?: null;

            $connection_string = "http://{$rpc_user}:{$rpc_password}@{$host}:{$port}";
            // https://username:password@localhost:18332
            $this->bitcoin_client = new Bitcoind(new Client($connection_string));
        }
        return $this->bitcoin_client;
    }


}
