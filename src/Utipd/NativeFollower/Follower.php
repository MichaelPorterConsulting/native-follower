<?php 

namespace Utipd\NativeFollower;

use Nbobtc\Bitcoind\Bitcoind;
use GuzzleHttp\Client as GuzzleClient;
use PDO;

/**
*       
*/
class Follower
{

    protected $db_connection              = null;
    protected $new_block_callback_fn      = null;
    protected $new_transaction_callback   = null;
    protected $orphaned_block_callback_fn = null;
    
    // no blocks before this are ever seen
    protected $genesis_block_id = 314170;

    function __construct(Bitcoind $bitcoin_client, PDO $db_connection, GuzzleClient $guzzle=null) {
        $this->bitcoin_client = $bitcoin_client;
        $this->db_connection = $db_connection;
        $this->guzzle = $guzzle;
    }

    public function setGenesisBlockID($genesis_block_id) {
        $this->genesis_block_id = $genesis_block_id;
    }

    // $follower->handleNewBlock(function($block_id) { });
    public function handleNewBlock(Callable $new_block_callback_fn) {
        $this->new_block_callback_fn = $new_block_callback_fn;
    }

    // $follower->handleNewTransaction(function($transaction, $block_id) { });

    // Transactions look like
    // txid: cc91db2f18b908903cb7c7a4474695016e12afd816f66a209e80b7511b29bba9
    // outputs:
    //     - amount: 100000
    //       address: 1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa

    public function handleNewTransaction(Callable $new_transaction_callback) {
        $this->new_transaction_callback = $new_transaction_callback;
    }

    // $follower->handleOrphanedBlock(function($orphaned_block_id) { });
    public function handleOrphanedBlock(Callable $orphaned_block_callback_fn) {
        $this->orphaned_block_callback_fn = $orphaned_block_callback_fn;
    }

    public function processAnyNewBlocks() {
        $last_block = $this->getLastProcessedBlock();
        if ($last_block === null) { $last_block = $this->genesis_block_id - 1; }

        $this->processBlocksNewerThan($last_block);
    }

    public function processBlocksNewerThan($last_processed_block) {
        $next_block_id = $last_processed_block + 1;

        // $bitcoin_block_height = $this->getBitcoinBlockHeight();
        $bitcoind_block_height = $this->getBitcoinBlockHeight();
        if (!$bitcoind_block_height) { throw new Exception("Could not get bitcoind block height.  Last result was:".json_encode($this->last_result, 192), 1); }


        while ($next_block_id <= $bitcoind_block_height) {
            // handle chain reorganization
            list($next_block_id, $block) = $this->loadBlockAndHandleOrphans($next_block_id);

            // process the block
            $this->processBlock($next_block_id, $block);

            // mark the block as processed
            $this->markBlockAsProcessed($next_block_id, $block);

            ++$next_block_id;
            if ($next_block_id > $bitcoind_block_height) {
                // reload the bitcoin block height in case this took a long time
                $bitcoind_block_height = $this->getBitcoinBlockHeight();
            }
        }
    }

    public function processBlock($block_id, $block) {
        // process the block
        $this->processNewBlockCallback($block_id);

        // process block data (uses blockchain.info)
        if ($this->new_transaction_callback !== null) {
            // https://blockchain.info/rawblock/00000000000000002dbf1f57004289a5489ff009a9e20da402ad5336976933ba
            if (isset($this->guzzle)) {
                $client = $this->guzzle;
            } else {
                $client = new GuzzleClient(['base_url' => 'https://blockchain.info',]);
            }
            $response = $client->get('/rawblock/'.$block->hash);
            $json_data = $response->json();

            if ($json_data['tx']) {
                $debug_counter = 0;
                foreach($json_data['tx'] as $decoded_tx) {
                    $this->processNewTransactionCallback($this->preprocessDecodedTransactionForBlockchainInfo($decoded_tx), $block_id);

                    // DEBUG
                    // echo "\$block_id=$block_id count: $debug_counter\n"; if (++$debug_counter >= 5) break;
                }
            }
        }

        // // process block data (native)
        // if ($this->new_transaction_callback !== null) {
        //     if ($block->tx) {
        //         $debug_counter = 0;
        //         foreach($block->tx as $tx_id) {
        //             $raw_tx = $this->bitcoin_client->getrawtransaction($tx_id);
        //             $decoded_tx = $this->bitcoin_client->decoderawtransaction($raw_tx);
        //             $this->processNewTransactionCallback($this->preprocessBitcoindDecodedTransaction($decoded_tx), $block_id);

        //             // DEBUG
        //             // echo "\$block_id=$block_id count: $debug_counter\n"; if (++$debug_counter >= 5) break;
        //         }
        //     }
        // }

        return $block;
    }

    // protected function preprocessBitcoindDecodedTransaction($decoded_tx) {
    //     $info = ['txid' => $decoded_tx->txid, 'outputs' => []];
    //     foreach ($decoded_tx->vout as $vout) {
    //         $addresses = $vout->scriptPubKey->addresses;
    //         $info['outputs'][] = [
    //             'amount' => $vout->value,
    //             'address' => $addresses[0],
    //         ];
    //     }
    //     return $info;
    // }


    protected function preprocessDecodedTransactionForBlockchainInfo($decoded_tx) {
        $info = ['txid' => $decoded_tx['hash'], 'outputs' => []];
        foreach ($decoded_tx['out'] as $out) {
            if (!isset($out['addr'])) { continue; }
            $info['outputs'][] = [
                'amount'  => $out['value'],
                'address' => $out['addr'],
            ];
        }

        // lookup source addresses? we don't care about this yet...

        return $info;
    }

    protected function processNewBlockCallback($block_id) {
        // handle the send
        if ($this->new_block_callback_fn) {
            call_user_func($this->new_block_callback_fn, $block_id);
        }
    }

    protected function processNewTransactionCallback($transaction, $block_id) {
        // handle the send
        if ($this->new_transaction_callback) {
            call_user_func($this->new_transaction_callback, $transaction, $block_id);
        }
    }

    protected function processOrphanedBlockCallback($orphaned_block_id) {
        if ($this->orphaned_block_callback_fn) {
            call_user_func($this->orphaned_block_callback_fn, $orphaned_block_id);
        }
    }

    protected function getBitcoinBlockHeight() {
        $this->last_result = $this->bitcoin_client->getblockcount();
        return $this->last_result;
    }

    protected function getLastProcessedBlock() {
        $sql = "SELECT MAX(blockId) AS blockId FROM blocks WHERE status=?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute(['processed']);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $row['blockId'];
        }
        return null;
    }

    protected function markBlockAsProcessed($block_id, $block) {
        $sql = "REPLACE INTO blocks VALUES (?,?,?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$block_id, $block->hash, 'processed', time()]);
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Orphans


    
    protected function loadBlockAndHandleOrphans($block_id) {
        $starting_block_id = $block_id;
        $working_block_id = $block_id;

        $orphaned_block_ids = [];
        $done = false;
        while (!$done) {
            // load the block
            $block_hash = $this->bitcoin_client->getblockhash($working_block_id);
            $block = $this->bitcoin_client->getblock($block_hash);

            // we can't check the parent of the genesis block
            //   because, well, it's the genesis block
            if ($working_block_id === $this->genesis_block_id) { break; }

            // get the block from bitcoind
            $expected_hash = $block->previousblockhash;

            // check the parent db entry
            $db_block_row = $this->getBlockRowFromDB($working_block_id - 1);
            $parent_hash_in_db = trim($db_block_row['blockHash']);

            if ($parent_hash_in_db === $expected_hash) {
                // this block was not orphaned
                //   we're done
                break;
            } else { 
                // this parent block was orphaned
                //   and this block was never seen
                $working_block_id = $working_block_id - 1;
                $orphaned_block_ids[] = $working_block_id;
            }
        }

        // callback
        if ($orphaned_block_ids) {
            // these will be newest to oldest
            foreach($orphaned_block_ids as $orphaned_block_id) {
                $this->processOrphanedBlockCallback($orphaned_block_id);

                // remove from the db
                $this->deleteBlockRowFromDB($orphaned_block_id);
            }
        }

        return [$working_block_id, $block];
    }

    protected function getBlockRowFromDB($block_id) {
        $sql = "SELECT * FROM blocks WHERE blockId=?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$block_id]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        return $row;
    }

    protected function deleteBlockRowFromDB($block_id) {
        $sql = "DELETE FROM blocks WHERE blockId=?";
        $sth = $this->db_connection->prepare($sql);
        $success = $sth->execute([$block_id]);
        return $success;
    }




}
