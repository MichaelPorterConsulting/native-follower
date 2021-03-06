<?php 

namespace Utipd\NativeFollower;

use GuzzleHttp\Client as GuzzleClient;
use Nbobtc\Bitcoind\Bitcoind;
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

    public function setGenesisBlock($genesis_block_id) {
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

    public function processAnyNewBlocks($limit=null) {
        $last_block = $this->getLastProcessedBlock();
        if ($last_block === null) { $last_block = $this->genesis_block_id - 1; }

        $this->processBlocksNewerThan($last_block, $limit);
    }


    public function processOneNewBlock() {
        return $this->processAnyNewBlocks(1);
    }

    public function processBlocksNewerThan($last_processed_block, $limit=null) {
        $next_block_id = $last_processed_block + 1;

        // $bitcoin_block_height = $this->getBitcoinBlockHeight();
        $bitcoind_block_height = $this->getBitcoinBlockHeight();
        if (!$bitcoind_block_height) { throw new Exception("Could not get bitcoind block height.  Last result was:".json_encode($this->last_result, 192), 1); }


        $processed_count = 0;
        while ($next_block_id <= $bitcoind_block_height) {
            // handle chain reorganization
            list($next_block_id, $block) = $this->loadBlockAndHandleOrphans($next_block_id);

            // process the block
            $this->processBlock($next_block_id, $block);

            // mark the block as processed
            $this->markBlockAsProcessed($next_block_id, $block);
            $last_processed_block = $next_block_id;

            // clear mempool, because a new block was processed
            $this->clearMempool();

            ++$next_block_id;
            if ($next_block_id > $bitcoind_block_height) {
                // reload the bitcoin block height in case this took a long time
                $bitcoind_block_height = $this->getBitcoinBlockHeight();
            }

            // check for limit
            ++$processed_count;
            if ($limit !== null) {
                if ($processed_count >= $limit) { break; }
            }
        }

        // if we are caught up, process mempool transactions
        if ($last_processed_block == $bitcoind_block_height) {
            $this->processMempoolTransactions();
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
                    $hash = $decoded_tx['hash'];
                    $confirmed_tx = $this->getCachedTransactionOrBuildNewOne($hash, $with_inputs=true, function($hash) use ($decoded_tx) {
                        return $this->preprocessDecodedTransactionForBlockchainInfo($decoded_tx);
                    });
                    $this->processNewTransactionCallback($confirmed_tx, $block_id, $is_mempool=false);
                }
            }
        }

        // garbage collection on cache
        $this->cleanCacheTable();

        return $block;
    }

    public function getLastProcessedBlock() {
        $sql = "SELECT MAX(blockId) AS blockId FROM blocks WHERE status=?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute(['processed']);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $row['blockId'];
        }
        return null;
    }


    protected function preprocessDecodedTransactionForBlockchainInfo($decoded_tx) {
        $info = ['txid' => $decoded_tx['hash'], 'outputs' => []];
        foreach ($decoded_tx['out'] as $out) {
            if (!isset($out['addr'])) { continue; }
            $info['outputs'][] = [
                'amount'  => $out['value'],
                'address' => $out['addr'],
            ];
        }

        // build inputs
        foreach ($decoded_tx['inputs'] as $input) {
            $info['inputs'][] = [
                'amount'  => $input['prev_out']['value'],
                'address' => $input['prev_out']['addr'],
            ];
        }

        return $info;
    }

    protected function processNewBlockCallback($block_id) {
        // handle the send
        if ($this->new_block_callback_fn) {
            call_user_func($this->new_block_callback_fn, $block_id);
        }
    }

    protected function processNewTransactionCallback($transaction, $block_id, $is_mempool) {
        // handle the send
        if ($this->new_transaction_callback) {
            call_user_func($this->new_transaction_callback, $transaction, $block_id, $is_mempool);
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
            if ($working_block_id <= $this->genesis_block_id) { break; }

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

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // mempool
    
    protected function clearMempool() {
        $sql = "TRUNCATE mempool";
        $sth = $this->db_connection->exec($sql);
    }

    protected function processMempoolTransactions() {
        // json={"filters": {"field": "category", "op": "==", "value": "sends"}}
        $mempool_txs = $this->bitcoin_client->getrawmempool();

        // load all processed mempool hashes
        $mempool_transactions_processed = $this->getAllMempoolTransactionsMap();

        foreach($mempool_txs as $mempool_tx_hash) {
            // if already processed, skip it
            if (isset($mempool_transactions_processed[$mempool_tx_hash])) { continue; }

            // decode the transaction
            $timestamp = time();
            if (isset($this->new_transaction_callback)) {
                $mempool_tx = $this->getCachedTransactionOrBuildNewOne($mempool_tx_hash, $with_inputs=true, function($mempool_tx_hash) {
                    $decoded_tx = $this->bitcoin_client->getrawtransaction($mempool_tx_hash, true);
                    $mempool_tx = $this->formatDecodedRawTransaction($decoded_tx, true);
                    return $mempool_tx;
                });

                // send new tx
                $this->processNewTransactionCallback($mempool_tx, null, $is_mempool=true);
            }

            // mark as processed
            $this->markMempoolTransactionAsProcessed($mempool_tx_hash, $timestamp);
        }
    }

    protected function formatDecodedRawTransaction($decoded_tx, $with_inputs) {
        $info = ['txid' => $decoded_tx->txid, 'inputs' => [], 'outputs' => []];
        foreach ($decoded_tx->vout as $vout) {
            $addresses = (isset($vout->scriptPubKey) AND isset($vout->scriptPubKey->addresses)) ? $vout->scriptPubKey->addresses : [];
            // build outputs
            if ($addresses) {
                $info['outputs'][] = [
                    // this needs to be in satoshis
                    'amount' => round($vout->value * 100000000),
                    'address' => $addresses[0],
                ];
            }

            // build inputs (if needed)
            if ($with_inputs) {
                foreach ($decoded_tx->vin as $vin) {
                    $info['inputs'][] = $this->buildInputForPreviousOutput($vin->txid, $vin->vout);
                }
            }

        }
        return $info;
    }


    protected function getAllMempoolTransactionsMap() {
        $mempool_transactions_map = [];
        $sql = "SELECT hash FROM mempool";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute();
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $mempool_transactions_map[$row['hash']] = true;
        }
        return $mempool_transactions_map;
    }

    protected function markMempoolTransactionAsProcessed($hash, $timestamp) {
        $sql = "REPLACE INTO mempool VALUES (?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$hash, $timestamp]);
    }

    ////////////////////////////////////////////////////////////////////////
    // build inputs

    protected function buildInputForPreviousOutput($previous_hash, $vout_offset) {
        // get the previous transaction
        $previous_tx = $this->getCachedTransactionOrBuildNewOne($previous_hash, $with_inputs=false, function($previous_hash) {
            $decoded_tx = $this->bitcoin_client->getrawtransaction($previous_hash, true);
            return $this->formatDecodedRawTransaction($decoded_tx, $with_inputs=false);
        });

        // decode the input
        $previous_output = $previous_tx['outputs'][$vout_offset];
        return [
            'amount'  => $previous_output['amount'],
            'address' => $previous_output['address'],
        ];
   
    }

    ////////////////////////////////////////////////////////////////////////
    // cache

    protected function getCachedTransactionOrBuildNewOne($hash, $with_inputs, $build_callback) {
        $with_inputs = ($with_inputs ? 1 : 0);

        // txcache
        $mempool_transactions_map = [];
        $sql = "SELECT * FROM txcache WHERE hash = ? AND with_inputs = ?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$hash, $with_inputs]);
        if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            return json_decode($row['tx'], true);
        }

        // not in cache, so build it
        $new_transaction = $build_callback($hash);

        // store it
        $sql = "REPLACE INTO txcache (`hash`, `with_inputs`, `timestamp`, `tx`) VALUES (?,?,?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$hash, $with_inputs, time(), json_encode($new_transaction)]);

        // return the new transaction
        return $new_transaction;
    }    

    protected function cleanCacheTable() {
        $TTL = 86400;
        $timestamp_to_delete = time() - $TTL;
        $sql = "DELETE FROM txcache WHERE `timestamp` < ?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$TTL]);
    }    



}
