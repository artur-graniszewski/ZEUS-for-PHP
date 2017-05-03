<?php

namespace Zeus\ServerService\Async;

use Opis\Closure\SerializableClosure;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zeus\Kernel\Networking\ConnectionInterface;
use Zeus\Kernel\Networking\FlushableConnectionInterface;
use Zeus\Kernel\Networking\SocketStream;

// Plugin class
class AsyncPlugin extends AbstractPlugin
{
    /** @var ConnectionInterface[] */
    protected $handles = [];

    /** @var Config */
    protected $config;

    protected $time;

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
    protected function getSocket()
    {
        $result = @stream_socket_client(sprintf('tcp://%s:%d', $this->config->getListenAddress(), $this->config->getListenPort()));
        if (!$result) {
            throw new \RuntimeException("Async call failed: async server is offline");
        }

        $stream = new SocketStream($result);
        if ($stream instanceof FlushableConnectionInterface) {
            $stream->setWriteBufferSize(0);
        }

        return $stream;
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

        $socket = $this->getSocket();

        $exception = null;
        try {
            $socket->write($message);
        } catch (\Exception $exception) {
        } catch (\Throwable $exception) {
        }

        if ($exception) {
            $socket->close();
            throw new \RuntimeException("Async call failed: unable to issue async call");
        }

        $response = $socket->read("\n");
        if (!$response || $response !== "PROCESSING") {
            $socket->close();
            throw new \RuntimeException("Async call failed, server response: " . rtrim($response));
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
                if (!$socket->isReadable()) {
                    throw new \RuntimeException("Async call failed: server connection lost", 1);
                }

                if (!$socket->select(0)) {
                    continue;
                }

                $result = $this->doJoin($socket);
                $results[$index] = $result;
                unset($read[$index]);
            }

            if ($read) {
                usleep(1000);
            }
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

        $result = $this->handles[$callId]->select(0);

        // report as working if no data is readable yet
        return $result === false;
    }

    protected function doJoin(ConnectionInterface $socket)
    {
        $result = $socket->read();
        if ($result === false) {
            throw new \RuntimeException("Async call failed: server connection lost", 1);
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

    protected function onSelectTimeout()
    {
        if (time() - $this->time > 30) {
            throw new \RuntimeException("Join timeout encountered");
        }
    }
}