<?php

namespace Zeus\ServerService\Async;

use Opis\Closure\SerializableClosure;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\AbstractStream;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\FlushableStreamInterface;
use Zeus\IO\Stream\SelectableStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

// Plugin class
class AsyncPlugin extends AbstractPlugin
{
    /** @var NetworkStreamInterface[]|SocketStream[] */
    protected $handles = [];

    /** @var Config */
    protected $config;

    protected $time;

    /** @var int */
    protected $joinTimeout = 30;

    /**
     * AsyncPlugin constructor.
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return SocketStream
     */
    protected function getSocket() : SocketStream
    {
        $result = @stream_socket_client(sprintf('tcp://%s:%d', $this->config->getListenAddress(), $this->config->getListenPort()));
        if (!$result) {
            throw new \RuntimeException("Async call failed: async server is offline");
        }

        $stream = new SocketStream($result);
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $exception) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
        if ($stream instanceof FlushableStreamInterface) {
            $stream->setWriteBufferSize(0);
        }

        return $stream;
    }

    public function setJoinTimeout(int $timeout)
    {
        $this->joinTimeout = $timeout;
    }

    /**
     * @return int
     */
    public function getJoinTimeout() : int
    {
        return $this->joinTimeout;
    }

    /**
     * @param \Closure $callable
     * @return int $callId
     */
    public function run(\Closure $callable) : int
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new \LogicException("Async call failed: serialization module is missing");
        }
        $closure = new SerializableClosure($callable);
        $message = serialize($closure);
        $message = sprintf("%d:%s\n", strlen($message), $message);

        $socket = $this->getSocket();

        $exception = null;
        try {
            $socket->write($message);
        } catch (\Throwable $exception) {
        }

        if ($exception) {
            $socket->close();
            throw new \RuntimeException("Async call failed: unable to issue async call");
        }

        $selector = new Selector();
        $socket->register($selector, SelectionKey::OP_READ);
        $response = $selector->select(100) > 0 ? $socket->read(11) : false;
        if (!$response || $response !== "PROCESSING\n") {
            try {
                if ($selector->select(100) > 0) {
                    $buffer = $socket->read(10);
                    $response .= $buffer;
                }
            } catch (IOException $exception) {
            }
            $socket->close();

            if ($response === "BAD_REQUEST\n") {
                throw new \RuntimeException("Async call failed: server reported bad request", 1);
            }
            throw new \RuntimeException(sprintf("Async call failed, %s", false === $response ? "no response from server" : "server response: " . json_encode($response)));
        }

        $this->handles[] = $socket;
        end($this->handles);
        return key($this->handles);
    }

    /**
     * @param int|int[] $callId
     * @return array|mixed
     */
    public function join($callId)
    {
        $callIds = is_array($callId) ? $callId : [$callId];

        $results = [];
        $read = [];

        foreach ($callIds as $id) {
            if (!isset($this->handles[$id])) {
                throw new \LogicException(sprintf("Invalid callback ID: %s", $id));
            }
            $read[$id] = $this->handles[$id];
            unset($this->handles[$id]);
        }

        $this->time = time();
        while ($read) {
            foreach($read as $index => $socket) {
                /** @var SelectableStreamInterface|AbstractStream $socket */
                if (!$socket->isReadable()) {
                    throw new \RuntimeException("Async call failed: server connection lost", 1);
                }

                $selector = new Selector();
                $socket->register($selector, SelectionKey::OP_READ);
                if (0 === $selector->select(0)) {
                    continue;
                }

                $result = $this->doJoin($socket);

                if ($result === null && !$socket->isClosed()) {
                    continue;
                }

                $results[$index] = $result;
                unset($read[$index]);
            }

            if ($read) {
                usleep(1000);
            }

            $this->checkSelectTimeout();
        };

        if (is_array($callId)) {
            ksort($results, SORT_NUMERIC);
            return $results;
        }

        return current($results);
    }

    public function isWorking(int $callId) : bool
    {
        if (!isset($this->handles[$callId])) {
            throw new \LogicException(sprintf("Invalid callback ID: %s", $callId));
        }

        $selector = new Selector();
        $this->handles[$callId]->register($selector, SelectionKey::OP_READ);
        $result = $selector->select(0);

        // report as working if no data is readable yet
        return $result === 0;
    }

    protected function doJoin(AbstractStream $socket)
    {
        $result = $socket->read();
        if ($result === false) {
            //throw new \RuntimeException("Async call failed: server connection lost", 1);

            return null;
        }

        if ($result === "CORRUPTED_REQUEST\n") {
            throw new \RuntimeException("Async call failed: request was corrupted");
        }

        $pos = strpos($result, ':');

        if (false === $pos) {
            throw new \RuntimeException("Async call failed: response is corrupted: $result");
        }

        /** @var int $size */
        $size = substr($result, 0, $pos);

        if (!ctype_digit($size) || $size < 1) {
            throw new \RuntimeException("Async call failed: response size is invalid");
        }

        $data = substr($result, $pos + 1);
        $read = true;

        while ($read !== false && $socket->isReadable() && strlen($data) < $size) {
            $read = $socket->read();
            $data .= $read;
        }

        if (strlen($data) !== $size + 1) {
            throw new \RuntimeException("Async call failed: server connection lost", 2);
        }

        $end = substr($data, -1, 1);
        $result = substr($data, 0, -1);

        if ($end !== "\n") {
            throw new \RuntimeException("Async call failed: callback result is corrupted");
        }

        $result = unserialize($result);
        $socket->close();

        return $result;
    }

    protected function checkSelectTimeout()
    {
        if ($this->joinTimeout > 0 && time() - $this->time > $this->joinTimeout) {
            throw new \RuntimeException("Join timeout encountered");
        }
    }
}