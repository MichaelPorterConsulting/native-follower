A Bitcoin block and transaction follower. 

This is a standalone component of UTipdMe.


Here's a sample

```php

use Utipd\NativeFollower\FollowerSetup;
use Utipd\NativeFollower\Follower;
use Nbobtc\Bitcoind\Bitcoind;
use Nbobtc\Bitcoind\Client;

// create a bitcoin RPC client
$bitcoin_client = new Bitcoind(new Client("http://{$rpc_user}:{$rpc_password}@{$host}:{$port}"));

// init a mysql database connection
$pdo = new \PDO($db_connection_string, $db_user, $db_password);

// create the MySQL tables
if ($first_time) {
    $setup = new FollowerSetup($pdo, $db_name='bitcoin_blocks');
    $stup->InitializeDatabase();
}

// build the follower and start at a recent block
$follower = new Follower($bitcoin_client, $pdo);
$follower->setGenesisBlock(313500);

// setup the handlers
$follower->handleNewBlock(function($block_id) {
    echo "New block: $block_id\n";
});
$follower->handleNewTransaction(function($transaction, $block_id) {
    echo "\$transaction:\n".json_encode($transaction, 192)."\n";
});
$follower->handleOrphanedBlock(function($orphaned_block_id) {
    echo "Orphaned block: $orphaned_block_id\n";
});


// listen forever
while (true) {
    $follower->processAnyNewBlocks();
    sleep(10);
}

```


BTC or Counterparty Tokens are gratefully accepted at 1HKXoNbTCFY7kKWzJ929aFU6Z42K5CdgY

