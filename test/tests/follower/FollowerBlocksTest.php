<?php

use Utipd\NativeFollower\Follower;
use Utipd\NativeFollower\FollowerSetup;
use Utipd\NativeFollower\Mocks\MockClient;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class FollowerBlocksTest extends \PHPUnit_Framework_TestCase
{


    public function testProcessBlocks() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->setupBTCDMockClient();

        $follower = $this->getFollower();
        $found_tx_map = [];
        $follower->handleNewTransaction(function($transaction, $block_id) use (&$found_tx_map) {
            $found_tx_map[$transaction['txid']] = $transaction;
        });

        // process
        $follower->processAnyNewBlocks();

        PHPUnit::assertArrayHasKey("A00001", $found_tx_map);
        PHPUnit::assertArrayHasKey("B00001", $found_tx_map);
        PHPUnit::assertArrayHasKey("D00002", $found_tx_map);
        PHPUnit::assertEquals(0.44, $found_tx_map['A00001']['outputs'][0]['amount']);
    }

    public function testHandleOrphanBlocks() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->setupBTCDMockClient();
        $this->setupBTCDMockClientForOrphan(310001);

        $follower = $this->getFollower();

        // handle blocks
        $found_block_ids_map = [];
        $follower->handleNewBlock(function($block_id) use (&$found_block_ids_map) {
            // echo "handleNewBlock \$block_id=$block_id\n";
            $found_block_ids_map[$block_id] = true;
        });

        // handle orphans
        $found_orphans_map = [];
        $follower->handleOrphanedBlock(function($orphaned_block_id) use (&$found_orphans_map) {
            $found_orphans_map[$orphaned_block_id] = true;
        });



        $follower->processAnyNewBlocks();

        PHPUnit::assertArrayHasKey(310001, $found_orphans_map);
        PHPUnit::assertEquals(1, count($found_orphans_map));
    } 


    ////////////////////////////////////////////////////////////////////////
    
    protected function setupBTCDMockClient() {
        $this->getMockBTCDClient()->addCallback('getblockhash', function($block_id) {
            return $this->getSampleBlocks()[$block_id]['hash'];
        });
        $this->getMockBTCDClient()->addCallback('getblock', function($block_hash) {
            $blocks = array_merge($this->getSampleBlocks(), $this->getSampleBlocksForReorganizedChain());
            foreach ($blocks as $block) {
                if ($block['hash'] == $block_hash) { return (object)$block; }
            }
            throw new Exception("Block not found: $block_hash", 1);
        });
        $this->getMockBTCDClient()->addCallback('getrawtransaction', function($tx_id_info) {
            $tx_id = $tx_id_info[0];
            return "RAW:$tx_id";
        });
        $this->getMockBTCDClient()->addCallback('decoderawtransaction', function($raw_tx) {
            $tx_id = substr($raw_tx, 4);
            return $this->getSampleTransactions()[$tx_id];
        });
        $this->getMockBTCDClient()->addCallback('getblockcount', function() {
            return 310003;
        });

    }    

    protected function setupBTCDMockClientForOrphan($block_id_to_orphan) {
        $this->using_reorg_chain = false;

        $this->getMockBTCDClient()->addCallback('getblockhash', function($block_id) use ($block_id_to_orphan) {
            if ($this->using_reorg_chain) {
                // print "using reorg chain now\n";
                $blocks = $this->getSampleBlocksForReorganizedChain();
            } else {
                $blocks = $this->getSampleBlocks();

                // echo "\$block_id=$block_id \$block_id_to_orphan=$block_id_to_orphan\n";
                if ($block_id == $block_id_to_orphan) {
                    // we will orphan this block
                    $this->using_reorg_chain = true;
                    // echo "this->using_reorg_chain={$this->using_reorg_chain}\n";
                }
            }
            return $blocks[$block_id]['hash'];
        });

    }


    protected function getFollower() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo();
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $follower = new Follower($this->getMockBTCDClient(), $pdo);
        $follower->setGenesisBlockID(310000);
        return $follower;
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

    protected function getMockBTCDClient() {
        if (!isset($this->bitcoin_client)) {
            $this->bitcoin_client = new MockClient();
        }
        return $this->bitcoin_client;
    }

    protected function getSampleBlocks() {
        return [
            "310000" => [
                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000G099",
                "hash"              => "000000000000000000000000000000000000000000000000000000000000A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "310001" => [

                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000A100",
                "hash"              => "000000000000000000000000000000000000000000000000000000000000B101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "310002" => [

                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000B101",
                "hash"              => "000000000000000000000000000000000000000000000000000000000000C102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "310003" => [

                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000C102",
                "hash"              => "000000000000000000000000000000000000000000000000000000000000D103",
                "tx" => [
                    "D00001",
                    "D00002",
                ],
            ],
        ];
    }

    protected function getSampleBlocksForReorganizedChain() {
        return [
            "310000" => [
                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000G099",
                "hash"              => "000000000000000000000000000000000000000000000000000000000000A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "310001" => [

                "previousblockhash" => "000000000000000000000000000000000000000000000000000000000000A100",
                "hash"              => "00000000000000000000000000000000000000000000000000000000ORPHB101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "310002" => [

                "previousblockhash" => "00000000000000000000000000000000000000000000000000000000ORPHB101",
                "hash"              => "00000000000000000000000000000000000000000000000000000000ORPHC102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "310003" => [

                "previousblockhash" => "00000000000000000000000000000000000000000000000000000000ORPHC102",
                "hash"              => "00000000000000000000000000000000000000000000000000000000ORPHD103",
                "tx" => [
                    "D00001",
                    "D00002",
                ],
            ],
        ];
    }

    protected function getSampleTransactions() {
        
        if (!isset($this->sample_txs)) {
        $samples =  [
            // ################################################################################################
            "A00001" => json_decode($_j = <<<EOT
{
    "txid": "A00001",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "cf5b7548f7ee8a92acd672b47bb3603e35155c5cf4d256fd7c3a77ebc25bfe4e",
            "vout": 1,
            "sequence": 4294967295
        },
        {
            "txid": "837f2091e056cb8d26361d66d948a76484855328e62ffadee0a9b1f40913fc9c",
            "vout": 1,
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.44,
            "n": 0,
            "scriptPubKey": {
                "reqSigs": 1,
                "addresses": [
                    "18oRp8fdDarJunNHkbtSAsNyV6FJ1ik5fQ"
                ]
            }
        },
        {
            "value": 0.01644635,
            "n": 1,
            "scriptPubKey": {
                "reqSigs": 1,
                "addresses": [
                    "15jnNY6XaX2jiRzLDmWoyYczQeYDLxMr4r"
                ]
            }
        }
    ]
}
EOT
            ),
            // ################################################################################################
            "A00002" => json_decode($_j = <<<EOT

{
    "txid": "A00002",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "4251a18aafc8294003908e0e3dfb723d2c361b0d1a9a439d47cd319a90d33462",
            "vout": 1,
            "scriptSig": {
                "asm": "3046022100ae0a64194349b42cb25200195bdce6e0a04db6aae93f6d76062d610aa782af67022100e732118d54f7ded4e40aaf7f637374dc2e8ffc8f06c347ee8d8aadb1f143ad1f01 021040c3a70436d2310843a9a9571fde14b3ef4518a6b6b18d317bd132a1530d63",
                "hex": "493046022100ae0a64194349b42cb25200195bdce6e0a04db6aae93f6d76062d610aa782af67022100e732118d54f7ded4e40aaf7f637374dc2e8ffc8f06c347ee8d8aadb1f143ad1f0121021040c3a70436d2310843a9a9571fde14b3ef4518a6b6b18d317bd132a1530d63"
            },
            "sequence": 4294967295
        }
    ],
  "vout": [
        {
            "value": 0.22696,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c33cf3626792c2d75c7237d11f0293f7a0e02211 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c33cf3626792c2d75c7237d11f0293f7a0e0221188ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1JoKnLZQmnTwiHfqJpXvfpNSiK8xsdHFwi"
                ]
            }
        },
        {
            "value": 4.16644802,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 bc56e406a60551a01456e86ac0cabd4c6122633d OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914bc56e406a60551a01456e86ac0cabd4c6122633d88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1JAr8LaoFuqv8WTiX6t2tMP9PjFUUAHoSL"
                ]
            }
        }
    ]
}


EOT
            ),
            // ################################################################################################
        ];

            // copy to 
            foreach (['B00001','B00002','C00001','C00002','D00001','D00002','BORPH1','BORPH2','CORPH1','CORPH2','DORPH1','DORPH2',] as $new_id) {
                $samples[$new_id] = clone $samples["A0000".substr($new_id, -1)];
                $samples[$new_id]->txid = $new_id;
            }
            $this->sample_txs = $samples;
        }

        return $this->sample_txs;
    } 

}
