<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use LogicException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

class AbstractService
{
    /** @var Selector */
    private $selector;

    /** @var SocketServer */
    private $server;

    /** @var resource */
    private $streamContext;

    public function getStreamContext()
    {
        if (!$this->streamContext) {
            $this->streamContext = stream_context_create([
                'socket' => [
                    'tcp_nodelay' => true,
                ],
            ]);
        }

        return $this->streamContext;
    }

    public function getServer() : SocketServer
    {
        if (!$this->server) {
            throw new LogicException("Server not initiated");
        }

        return $this->server;
    }

    public function setServer(SocketServer $server)
    {
        $this->server = $server;
    }

    public function getSelector(): Selector
    {
        return $this->selector;
    }

    public function setSelector(Selector $selector)
    {
        $this->selector = $selector;
    }

    public function newSelector() : Selector
    {
        return new Selector();
    }

    protected function setStreamOptions(SocketStream $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }
}