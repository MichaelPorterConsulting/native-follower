<?php 

namespace Utipd\NativeFollower\Mocks;

use Exception;
use Nbobtc\Bitcoind\Bitcoind;
use PDO;

/**
*       
*/
class MockClient extends Bitcoind
{

    protected $callbacks_map = null;

    function __construct($callbacks_map=[]) {
        $this->callbacks_map = $callbacks_map;
    }
    public function addCallback($method, Callable $function) {
        $this->callbacks_map[$method] = $function;
        return $this;
    }

    function __call($method, $arguments) {
        if ($this->callbacks_map AND isset($this->callbacks_map[$method])) {
            $response = call_user_func_array($this->callbacks_map[$method], $arguments);
            return (object)['result' => $response];
        }

        throw new Exception("Mock method not implemented for $method", 1);
        
    }

    public function sendRequest($method, $params = null, $id = null) {
        // $args = func_get_args();
        return $this->__call($method, [$params]);
    }


}