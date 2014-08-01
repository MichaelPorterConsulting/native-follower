<?php 

namespace Utipd\NativeFollower;

use Nbobtc\Bitcoind\Bitcoind;
use PDO;

/**
*       
*/
class Follower
{

    protected $db_connection = null;
    protected $new_block_callback_fn = null;
    protected $new_transaction_callback = null;
    protected $orphaned_block_callback_fn = null;
    
    // no blocks before this are ever seen
    protected $genesis_block_id = 313364;

    function __construct(Bitcoind $bitcoin_client, PDO $db_connection) {
        $this->bitcoin_client = $bitcoin_client;
        $this->db_connection = $db_connection;
    }

    public function setGenesisBlockID($genesis_block_id) {
        $this->genesis_block_id = $genesis_block_id;
    }

    public function handleNewBlock(Callable $new_block_callback_fn) {
        $this->new_block_callback_fn = $new_block_callback_fn;
    }

    public function handleNewTransaction(Callable $new_transaction_callback) {
        $this->new_transaction_callback = $new_transaction_callback;
    }

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

        // process block data
        if ($block->tx) {
            $debug_counter = 0;
            foreach($block->tx as $tx_id) {
                $raw_tx = $this->bitcoin_client->getrawtransaction($tx_id);
                $decoded_tx = $this->bitcoin_client->decoderawtransaction($raw_tx);
                $this->processNewTransactionCallback($this->preprocessDecodedTransaction($decoded_tx), $block_id);

                // DEBUG
                // echo "\$block_id=$block_id count: $debug_counter\n"; if (++$debug_counter >= 5) break;
            }
        }

        return $block;
    }

    protected function preprocessDecodedTransaction($decoded_tx) {
        $info = ['txid' => $decoded_tx->txid, 'outputs' => []];
        foreach ($decoded_tx->vout as $vout) {
            $addresses = $vout->scriptPubKey->addresses;
            $info['outputs'][] = [
                'amount' => $vout->value,
                'addresses' => $addresses,
            ];
        }

        // lookup source addresses? we don't care about this yet...
        // "vin": [
        //     {
        //         "txid": "cc91db2f18b908903cb7c7a4474695016e12afd816f66a209e80b7511b29bba9",
        //         "vout": 0,
        //         "scriptSig": {
        //             "asm": "3044022011b748725510524d3be6327a0b2403470f19f262e02c358d7de28eb9899c3ea402200d81c919b92fd1b8d98197f06e8b15c2351e4c6a53c694084466fe6a1b77ba9701 02d056c337702b04fb4f3e4ec6d316b3482a1062ddbee07dcac31faea287516c4f",
        //             "hex": "473044022011b748725510524d3be6327a0b2403470f19f262e02c358d7de28eb9899c3ea402200d81c919b92fd1b8d98197f06e8b15c2351e4c6a53c694084466fe6a1b77ba97012102d056c337702b04fb4f3e4ec6d316b3482a1062ddbee07dcac31faea287516c4f"
        //         },
        //         "sequence": 4294967295
        //     },


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
            $parent_hash_in_db = $db_block_row['blockHash'];

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
