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

    /**
     * @param ConnectionInterface $connection
     * @return $this
     */
    public function handleConnect(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $connection->on('data', [$this, 'handleData']);
        $connection->on('end', [$this, 'handleEnd']);
        $connection->on('error', function($exception) {
            $this->handleError($exception);
        });

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
            try {
                $this->app->onHeartBeat($this->connection, $data);
            } catch (\Throwable $exception) {
                $this->handleError($exception);
            } catch (\Exception $exception) {
                $this->handleError($exception);
            }
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
        try {
            $this->app->onMessage($connection, $data);
        } catch (\Throwable $exception) {
            $this->handleError($exception);
        } catch (\Exception $exception) {
            $this->handleError($exception);
        }

        return $this;
    }

    /**
     * A connection has been closed by React
     * @param ConnectionInterface $connection
     * @return $this
     */
    public function handleEnd(ConnectionInterface $connection)
    {
        try {
            $this->app->onClose($connection);
        } catch (\Throwable $exception) {
            $this->handleError($exception);
        } catch (\Exception $exception) {
            $this->handleError($exception);
        }

        unset($connection->decor);

        return $this;
    }

    /**
     * An error has occurred, let the listening application know
     * @param \Exception|\Throwable $exception
     */
    public function handleError($exception)
    {
        try {
            $this->app->onError($this->connection, $exception);
            $exception = null;
        } catch (\Throwable $exception) {

        } catch (\Exception $exception) {

        }

        $this->cleanUp();

        if ($exception) {
            throw $exception;
        }
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
