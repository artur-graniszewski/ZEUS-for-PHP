<?php

namespace Zeus\ServerService\Async\Message;

use Zend\Cache\Storage\Adapter\Apcu;
use Zend\Cache\Storage\AvailableSpaceCapableInterface;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\StorageInterface;
use Zend\Cache\Storage\TotalSpaceCapableInterface;
use Zeus\Module;
use Zeus\ServerService\Shared\Exception\PrerequisitesNotMetException;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use Zeus\ServerService\Shared\React\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\React\MessageComponentInterface;

/**
 * Class Message
 * @package Zeus\ServerService\Async\Message
 * @internal
 */
final class Message implements MessageComponentInterface, HeartBeatMessageInterface
{
    protected $expectedPayloadSize = 0;

    protected $ttl = 0;

    protected $message;

    protected $callback;

    public function onHeartBeat(ConnectionInterface $connection, $data = null)
    {
        if ($this->callback) {
            $result = null;
            $e = null;
            try {
                $result = $this->run(substr($this->callback, 0, -1));
            } catch (\Exception $e) {

            } catch (\Throwable $e) {

            }

            if ($connection->isWritable()) {
                $result = serialize($e ? $e : $result);
                $size = strlen($result);
                $connection->write("$size:$result\n");
                $connection->end();

                return;
            }
        }

        $this->ttl++;

        if ($this->ttl > 500000) {
            $connection->end();
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    public function onOpen(ConnectionInterface $connection)
    {
        $this->ttl = 0;
        $this->message = '';
        $this->callback = '';
        $this->expectedPayloadSize = 0;
    }

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    public function onClose(ConnectionInterface $connection)
    {
        $connection->end();
    }

    /**
     * @param ConnectionInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    public function onError(ConnectionInterface $connection, $exception)
    {
        $connection->end();
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     * @throws \Exception
     */
    public function onMessage(ConnectionInterface $connection, $message)
    {
        $this->message .= $message;

        if (!$this->expectedPayloadSize) {
            if (($pos = strpos($this->message, ":")) > 0) {
                $size = substr($this->message, 0, $pos);

                if (!ctype_digit($size) || $size < 1) {
                    $connection->write("BAD_REQUEST\n");
                    $connection->end();
                    return;
                }

                $this->expectedPayloadSize = (int) $size;

                $this->message = substr($this->message, $pos + 1);
            }

            if (!$pos) {
                if (!ctype_digit($this->message)) {
                    $connection->write("BAD_REQUEST\n");
                    $connection->end();
                    return;
                }
            }
        }

        if ($this->expectedPayloadSize) {
            $messageSize = strlen($this->message);
            if ($messageSize > $this->expectedPayloadSize + 1 || $this->message[$messageSize - 1] !== "\n") {
                $connection->write("CORRUPTED_REQUEST\n");
                $connection->end();
                return;
            }

            if ($messageSize === $this->expectedPayloadSize + 1) {
                $connection->write("PROCESSING\n");
                $this->callback = $this->message;
            }
        }
    }

    protected function run($message)
    {
        /** @var Callable $callable */
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            @trigger_error("");
            $callable = @unserialize($message);
            if (!$callable) {
                $error = error_get_last();
                if ($error['message']) {
                    throw new \LogicException($error['message']);
                }
            }

            return $callable();
        }

        $callable = unserialize($message);

        return $callable();
    }
}