<?php

namespace Zeus\ServerService\Async\Message;

use Zeus\Kernel\Networking\ConnectionInterface;
use Zeus\Kernel\Networking\FlushableConnectionInterface;
use Zeus\ServerService\Async\UnserializeException;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;

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

    /** @var UnserializeException */
    protected $exception;

    public function __construct()
    {
        $this->exception = new UnserializeException("Callback unserialization failed");
    }

    public function onHeartBeat(ConnectionInterface $connection, $data = null)
    {
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
        if ($connection instanceof FlushableConnectionInterface) {
            $connection->setWriteBufferSize(0);
        }

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

        if (!$this->callback) {
            return;
        }

        $result = null;
        $exception = null;
        try {
            $result = $this->run(substr($this->callback, 0, -1));
        } catch (\Exception $exception) {

        } catch (\Throwable $exception) {

        }

        if ($connection->isWritable()) {
            if ($exception instanceof UnserializeException) {
                $connection->write("CORRUPTED_REQUEST\n");
                $connection->end();

                return;
            }
            $result = serialize($exception ? $exception : $result);
            $size = strlen($result);
            $connection->write("$size:$result\n");
            $connection->end();

            return;
        }

        $this->ttl++;
    }

    protected function run($message)
    {
        /** @var Callable $callable */
        $error = [];
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = array(
                'message' => $errstr,
                'number' => $errno,
                'file' => $errfile,
                'line' => $errline
            );
        });

        $callable = unserialize($message);
        if (!$callable) {
            if ($error['message']) {
                throw $this->exception;
            }
        }

        return $callable();
    }
}