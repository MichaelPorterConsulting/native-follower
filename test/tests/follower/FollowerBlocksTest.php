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

        // test inputs (Blockchain.info)
        PHPUnit::assertEquals('1J4gVXjd1CT2NnGFkmzaJJvNu4GVUfYLVK', $found_tx_map['A00001']['inputs'][0]['address']);
        PHPUnit::assertEquals(56870000, $found_tx_map['A00001']['inputs'][0]['amount']);
        
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



    public function testMempool() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->setupBTCDMockClient();
        $this->setupBTCDMockClientForMempool();


        $follower = $this->getFollower();
        $found_tx_map = ['normal' => [], 'mempool' => []];
        $mempool_transactions_processed = 0;

        $follower->handleNewTransaction(function($transaction, $block_id, $is_mempool) use (&$found_tx_map, &$mempool_transactions_processed) {
            if ($is_mempool) {
                ++$mempool_transactions_processed;
                $found_tx_map['mempool'][] = $transaction;
            } else {
                $found_tx_map['normal'][$transaction['txid']] = $transaction;
            }
        });

        // run three times
        $follower->processAnyNewBlocks(4);

        // check normal transactions
        // echo "\$found_tx_map:\n".json_encode($found_tx_map, 192)."\n";
        PHPUnit::assertArrayHasKey("A00001", $found_tx_map['normal']);

        // test inputs (raw bitcoind)
        PHPUnit::assertEquals('1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ', $found_tx_map['mempool'][0]['inputs'][0]['address']);
        PHPUnit::assertEquals(90000, $found_tx_map['mempool'][0]['inputs'][0]['amount']); // 0.0009 = 90000

        // make sure mempool table has two entries
        $db_connection = $this->getPDO();
        $sql = "SELECT hash FROM mempool";
        $sth = $db_connection->prepare($sql);
        $result = $sth->execute();
        PHPUnit::assertEquals(2, $sth->rowCount());


        $follower->processAnyNewBlocks(1);


        // now clear the mempool by processing a new block
        $this->getMockBTCDClient()->addCallback('getblockcount', function() {
            return 310004;
        });
        $this->getMockBTCDClient()->addCallback('getrawmempool', function() {
            return [];
        });
        $follower->processAnyNewBlocks(1);

        // make sure mempool table is cleared
        $db_connection = $this->getPDO();
        $sql = "SELECT hash FROM mempool";
        $sth = $db_connection->prepare($sql);
        $result = $sth->execute();
        PHPUnit::assertEquals(0, $sth->rowCount());

/*
    {
        "txid": "rawtxid001",
        "outputs": [
            {
                "amount": 50000000,
                "address": "1JdDmF3BmAVQP6cJYLhS9JMRqAM3bB1hP4"
            },
            {
                "amount": 2233228,
                "address": "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
            }
        ]
    },
*/
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function setupBTCDMockClient() {
        $this->getMockBTCDClient()->addCallback('getblockhash', function($block_id) {
            return $this->getSampleBlocks()[$block_id]['hash'];
        });
        $this->getMockBTCDClient()->addCallback('getblock', function($block_hash_params) {
            list($block_hash, $verbose) = $block_hash_params;
            $blocks = array_merge($this->getSampleBlocks(), $this->getSampleBlocksForReorganizedChain());
            foreach ($blocks as $block) {
                if ($block['hash'] == $block_hash) { return (object)$block; }
            }
            throw new Exception("Block not found: $block_hash", 1);
        });
        $this->getMockBTCDClient()->addCallback('getrawtransaction', function($tx_id_params) {
            $tx_id = $tx_id_params[0];
            return $this->getSampleTransactionsForNative()[$tx_id];
        });
        // $this->getMockBTCDClient()->addCallback('decoderawtransaction', function($raw_tx) {
        //     $tx_id = substr($raw_tx, 4);
        //     return $this->getSampleTransactions()[$tx_id];
        // });

        $this->getMockBTCDClient()->addCallback('getrawmempool', function() {
            return [];
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

    protected function setupBTCDMockClientForMempool() {
        $this->getMockBTCDClient()->addCallback('getrawmempool', function() {
            return ["rawtxid001","rawtxid002"];
        });

    }


    protected function getFollower() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo();
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $follower = new Follower($this->getMockBTCDClient(), $pdo, $this->getMockGuzzleClient());
        $follower->setGenesisBlock(310000);
        return $follower;
    }

    protected function getFollowerSetup() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(false);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new FollowerSetup($pdo, $db_name);
    }

    protected function getPDO() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(true);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
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
            "310004" => [

                "previousblockhash" => "BLK_NORM_D103",
                "hash"              => "BLK_NORM_E103",
                "tx" => [
                    "E00001",
                    "E00002",
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
                "txid": 22961584,
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
            "txid": 24736715,
            "type": 0,
            "value": 56860000
        }
    ],
    "relayed_by": "24.210.191.129",
    "size": 203,
    "time": 1376370366,
    "txid": 24736715,
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
                "txid": 26175189,
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
                "txid": 27968848,
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
            "txid": 30536227,
            "type": 0,
            "value": 58990880
        }
    ],
    "relayed_by": "85.17.239.32",
    "size": 349,
    "time": 1376370307,
    "txid": 30536227,
    "ver": 1,
    "vin_sz": 2,
    "vout_sz": 1
}


EOT
            ),
            // ################################################################################################
        ];

            // copy to 
            foreach (['B00001','B00002','C00001','C00002','D00001','D00002','E00001','E00002','BORPH1','BORPH2','CORPH1','CORPH2','DORPH1','DORPH2',] as $new_id) {
                $samples[$new_id] = clone $samples["A0000".substr($new_id, -1)];
                $samples[$new_id]->hash = $new_id;
            }
            $this->sample_txs = $samples;
        }

        return $this->sample_txs;
    }


    protected function getSampleTransactionsForNative() {
        if (!isset($this->native_sample_txs)) {
            $this->native_sample_txs =  [
                // 1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ
                // ################################################################################################
                "rawtxid001" => json_decode($_j = <<<EOT
{
    "txid": "rawtxid001",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.5,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1JdDmF3BmAVQP6cJYLhS9JMRqAM3bB1hP4"
                ]
            }
        },
        {
            "value": 0.02233228,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
            ),
                // ################################################################################
                "rawtxid002" => json_decode($_j = <<<EOT
{
    "txid": "rawtxid002",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.6,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1JdDmF3BmAVQP6cJYLhS9JMRqAM3bB1hP4"
                ]
            }
        },
        {
            "value": 0.08,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
                ),
                // ################################################################################
                // # Previous TX
                "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6" => json_decode($_j = <<<EOT
{
    "hex": "01000000023956983b8b83f89fbab7351f0a7b2215898e111f94609d145ea83df9e8aa8697000000008b483045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e00141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80ffffffffc4464286201abca2421877f8ff5d9c27a168d3fefaca6fab2b5d1e8c0d0a531e000000008b483045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80ffffffff026011aa02000000001976a9140fdccbddf363a82392193e7ef11fa743e66cc85488ac905f0100000000001976a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac00000000",
    "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "9786aae8f93da85e149d60941f118e8915227b0a1f35b7ba9ff8838b3b985639",
            "vout": 0,
            "scriptSig": {
                "asm": "3045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e001 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e00141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        },
        {
            "txid": "1e530a0d8c1e5d2bab6fcafafed368a1279c5dfff8771842a2bc1a20864246c4",
            "vout": 0,
            "scriptSig": {
                "asm": "3045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.447,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 0fdccbddf363a82392193e7ef11fa743e66cc854 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a9140fdccbddf363a82392193e7ef11fa743e66cc85488ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "12Sse5UeBLLKKRVmSgwQzNehtFPbBdn4n8"
                ]
            }
        },
        {
            "value": 0.0009,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ],
    "blockhash": "000000000000000021a5dfd8763c64498c1087a07b2551d4d1b6362f3a5f1133",
    "confirmations": 12601,
    "time": 1402538244,
    "blocktime": 1402538244
}
EOT
                ),
            ];
        }
        return $this->native_sample_txs;
    }

}
