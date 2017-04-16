<?php

namespace Zeus\ServerService\Shared\React;

use React\EventLoop\LoopInterface;
use React\Socket\ServerInterface;

/**
 * Class ReactIoServer
 * @package Zeus\ServerService\Shared\React
 * @internal
 */
class ReactIoServer implements IoServerInterface
{
    /** @var LoopInterface */
    protected $loop;

    /** @var MessageComponentInterface */
    protected $app;

    /** @var ServerInterface */
    protected $socket;

    /** @var ConnectionInterface */
    protected $connection;

    /**
     * ReactIoServer constructor.
     * @param MessageComponentInterface $app
     * @param ServerInterface $socket
     * @param LoopInterface|null $loop
     */
    public function __construct(MessageComponentInterface $app, ServerInterface $socket, LoopInterface $loop = null)
    {
        $this->loop = $loop;
        $this->app  = $app;
        $this->socket = $socket;

        $socket->on('connection', [$this, 'handleConnect']);
        $socket->on('heartBeat', [$this, 'handleHeartBeat']);
    }

    /**
     * @return ServerInterface
     */
    public function getServer()
    {
        return $this->socket;
    }

    protected function safeExecute($callback)
    {
        $args = func_get_args();
        array_shift($args);
        try {
            call_user_func_array($callback, $args);
        } catch (\Throwable $exception) {
            $this->handleError($exception);
        } catch (\Exception $exception) {
            $this->handleError($exception);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @return $this
     */
    public function handleConnect(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $connection->on('data', [$this, 'handleData']);
        $connection->on('end', [$this, 'handleEnd']);
        $connection->on('error', [$this, 'handleError']);
        $connection->on('end', [$this, 'cleanUp']);

        $this->app->onOpen($connection);

        return $this;
    }

    /**
     * HeartBeat handler
     * @param mixed $data
     * @return $this
     */
    public function handleHeartBeat($data = null)
    {
        if (!isset($this->connection)) {
            return $this;
        }

        if ($this->app instanceof HeartBeatMessageInterface) {
            $this->safeExecute([$this->app, 'onHeartBeat'], $this->connection, $data);
        }

        return $this;
    }

    /**
     * @param string $data
     * @param ConnectionInterface $connection
     * @return $this
     */
    public function handleData($data, ConnectionInterface $connection)
    {
        $this->safeExecute([$this->app, 'onMessage'], $connection, $data);

        return $this;
    }

    /**
     * A connection has been closed by React
     * @param ConnectionInterface $connection
     * @return $this
     */
    public function handleEnd(ConnectionInterface $connection)
    {
        $this->safeExecute([$this->app, 'onClose'], $connection);

        unset($connection->decor);

        return $this;
    }

    /**
     * An error has occurred, let the listening application know
     * @param \Exception|\Throwable $exception
     * @throws \Throwable
     */
    public function handleError($exception)
    {
        try {
            if ($this->connection) {
                $this->app->onError($this->connection, $exception);
            }

        } catch (\Exception $exception) {
        } catch (\Throwable $exception) {
        }

        $this->cleanUp();

        throw $exception;
    }

    /**
     * @return $this
     */
    public function cleanUp()
    {
        $this->loop->stop();
        $this->connection = null;

        return $this;
    }
}
