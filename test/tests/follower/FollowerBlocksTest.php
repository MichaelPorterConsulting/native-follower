<?php

use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
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
        PHPUnit::assertEquals(56860000, $found_tx_map['A00001']['outputs'][0]['amount']);
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

    protected function getMockGuzzleClient() {
        if (!isset($this->mock_guzzle_client)) {

            $guzzle = $this->getMockBuilder('\GuzzleHttp\Client')
                     ->disableOriginalConstructor()
                     ->getMock();


            // Configure the stub.
            $guzzle->method('get')->will($this->returnCallback(function($url) {
                $hash = array_slice(explode('/', $url), -1)[0];

                foreach ($this->getSampleBlocks() as $sample_block) {
                    if ($sample_block['hash'] == $hash) {
                        $sample_block = $this->applyTransactionsToSampleBlock($sample_block);
                        return new Response(200, ['Content-Type' => 'application/json'], Stream::factory(json_encode($sample_block)));
                    }
                }

                throw new Exception("sample block not found with hash $hash", 1);
            }));

            $this->mock_guzzle_client = $guzzle;
        }
        return $this->mock_guzzle_client;
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
        $follower = new Follower($this->getMockBTCDClient(), $pdo, $this->getMockGuzzleClient());
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

    protected function applyTransactionsToSampleBlock($block) {
        $sample_transactions = $this->getSampleTransactionsForBlockchainInfo();
        $out = $block;
        $out['tx'] = [];
        foreach($block['tx'] as $tx_id) {
            $out['tx'][] = $sample_transactions[$tx_id];
        }
        return $out;
    }

    protected function getSampleBlocks() {
        return [
            "310000" => [
                "previousblockhash" => "BLK_NORM_G099",
                "hash"              => "BLK_NORM_A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "310001" => [

                "previousblockhash" => "BLK_NORM_A100",
                "hash"              => "BLK_NORM_B101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "310002" => [

                "previousblockhash" => "BLK_NORM_B101",
                "hash"              => "BLK_NORM_C102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "310003" => [

                "previousblockhash" => "BLK_NORM_C102",
                "hash"              => "BLK_NORM_D103",
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
                "previousblockhash" => "BLK_NORM_G099",
                "hash"              => "BLK_NORM_A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "310001" => [

                "previousblockhash" => "BLK_NORM_A100",
                "hash"              => "BLK_ORPH_B101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "310002" => [

                "previousblockhash" => "BLK_ORPH_B101",
                "hash"              => "BLK_ORPH_C102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "310003" => [

                "previousblockhash" => "BLK_ORPH_C102",
                "hash"              => "BLK_ORPH_D103",
                "tx" => [
                    "D00001",
                    "D00002",
                ],
            ],
        ];
    }

    protected function getSampleTransactionsForBlockchainInfo() {
        
        if (!isset($this->sample_txs)) {
        $samples =  [
            // ################################################################################################
            "A00001" => json_decode($_j = <<<EOT
{
    "hash": "A00001",
    "inputs": [
        {
            "prev_out": {
                "addr": "1J4gVXjd1CT2NnGFkmzaJJvNu4GVUfYLVK",
                "n": 1,
                "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac",
                "tx_index": 22961584,
                "type": 0,
                "value": 56870000
            },
            "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac"
        }
    ],
    "out": [
        {
            "addr": "15jhGQfARmEuh8JY73QwrgxYCGhqWAMkAC",
            "n": 0,
            "script": "210231a3996818ce0d955279421e4f0c4bd07502b9c03c135409e3189c0e067cbb9bac",
            "spent": false,
            "tx_index": 24736715,
            "type": 0,
            "value": 56860000
        }
    ],
    "relayed_by": "24.210.191.129",
    "size": 203,
    "time": 1376370366,
    "tx_index": 24736715,
    "ver": 1,
    "vin_sz": 1,
    "vout_sz": 1
}

EOT
            ),
            // ################################################################################################
            "A00002" => json_decode($_j = <<<EOT
{
    "hash": "A00002",
    "inputs": [
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 116,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "tx_index": 26175189,
                "type": 0,
                "value": 880
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        },
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 0,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "tx_index": 27968848,
                "type": 0,
                "value": 59000000
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        }
    ],
    "out": [
        {
            "addr": "1AEwxRGP4HdwrwoXo1rEKc3jihFmZUybCw",
            "n": 0,
            "script": "2102f4e9b26c2e0e86761e411e03ffd7b15b8ca4dbea464fb8a4a5dab4220e602c64ac",
            "spent": true,
            "tx_index": 30536227,
            "type": 0,
            "value": 58990880
        }
    ],
    "relayed_by": "85.17.239.32",
    "size": 349,
    "time": 1376370307,
    "tx_index": 30536227,
    "ver": 1,
    "vin_sz": 2,
    "vout_sz": 1
}


EOT
            ),
            // ################################################################################################
        ];

            // copy to 
            foreach (['B00001','B00002','C00001','C00002','D00001','D00002','BORPH1','BORPH2','CORPH1','CORPH2','DORPH1','DORPH2',] as $new_id) {
                $samples[$new_id] = clone $samples["A0000".substr($new_id, -1)];
                $samples[$new_id]->hash = $new_id;
            }
            $this->sample_txs = $samples;
        }

        return $this->sample_txs;
    }

}
