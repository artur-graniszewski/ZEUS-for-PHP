<?php

namespace Zeus\ServerService\Async;

use Opis\Closure\SerializableClosure;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

// Plugin class
class AsyncPlugin extends AbstractPlugin
{
    protected $handles = [];

    /** @var Config */
    protected $config;

    /**
     * AsyncPlugin constructor.
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param \Closure $callable
     * @return mixed $callId
     */
    public function run(\Closure $callable)
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new \LogicException("Async call failed: serialization module is missing");
        }
        $closure = new SerializableClosure($callable);
        $message = serialize($closure);
        $message = sprintf("%d:%s\n", strlen($message), $message);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $result = socket_connect($socket, $this->config->getListenAddress(), $this->config->getListenPort());
        if (!$result) {
            throw new \RuntimeException("Async call failed: async server is offline");
        }

        $messageSize = strlen($message);
        $sent = socket_write($socket, $message, $messageSize);

        if ($messageSize !== $sent) {
            socket_close($socket);
            throw new \RuntimeException("Async call failed: unable to issue async call");
        }
        $read = socket_recv($socket, $out, 11, MSG_PEEK);
        if (!$read || substr($out, 0, 11) !== "PROCESSING\n" || socket_recv($socket, $out, 11, MSG_DONTWAIT) != 11) {
            socket_close($socket);
            throw new \RuntimeException("Async call failed, server response: " . rtrim($out));
        }

        $this->handles[] = $socket;
        end($this->handles);
        return key($this->handles);
    }

    /**
     * @param mixed $callId
     * @return array|mixed
     */
    public function join($callId)
    {
        $callIds = is_array($callId) ? $callId : [$callId];

        $results = [];
        $read = [];
        $write = $except = [];

        foreach ($callIds as $id) {
            if (!isset($this->handles[$id])) {
                throw new \LogicException(sprintf("Invalid callback ID: %s", $id));
            }
            $read[$id] = $this->handles[$id];
            unset($this->handles[$id]);
        }

        $sockets = $read;
        $time = time();
        while ($read) {
            $amount = socket_select($read, $write, $except, 1);

            if (!$amount && time() - $time > 30) {
                throw new \RuntimeException("Join timeout encountered");
            }

            if ($amount) {
                foreach($read as $socket) {
                    $index = array_search($socket, $sockets);
                    $result = $this->doJoin($socket);
                    $results[$index] = $result;
                    unset($sockets[$index]);
                }
            }

            $read = $sockets;
        };

        if (is_array($callId)) {
            ksort($results, SORT_NUMERIC);
            return $results;
        }

        return current($results);
    }

    /**
     * @param int $callId
     * @return bool
     */
    public function isWorking($callId)
    {
        if (!isset($this->handles[$callId])) {
            throw new \LogicException(sprintf("Invalid callback ID: %s", $callId));
        }

        $callId = [$callId];
        $write = $except = [];

        $result = socket_select($callId, $write, $except, 0);

        return $result === false ? false : true;
    }

    protected function doJoin($socket)
    {
        socket_recv($socket, $result, 12, MSG_PEEK);
        $pos = strpos($result, ':');

        if (false === $pos) {
            throw new \RuntimeException("Async call failed: response is corrupted");
        }

        /** @var int $size */
        $size = substr($result, 0, $pos);

        if (!ctype_digit($size) || $size < 1) {
            throw new \RuntimeException("Async call failed: response size is invalid");
        }

        socket_recv($socket, $out, $size + $pos + 2, MSG_WAITALL);

        $end = substr($out, -1, 1);
        $result = substr($out, $pos + 1, -1);

        if ($end !== "\n") {
            throw new \RuntimeException("Async call failed: callback result is corrupted");
        }

        if (strlen($result) != $size) {
            throw new \RuntimeException("Async call failed: response size is invalid");
        }

        $result = unserialize($result);
        socket_close($socket);

        return $result;
    }
}