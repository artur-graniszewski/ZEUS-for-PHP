<?php

namespace Zeus\IO\Stream;

use LogicException;
use function json_encode;

class SelectionKey
{
    const OP_READ = 1;
    const OP_WRITE = 2;
    const OP_ACCEPT = 4;
    const OP_ALL = 7;

    private $readyOps = 0;

    private $interestOps = 0;

    /** @var SelectableStreamInterface */
    private $stream;

    /** @var mixed */
    private $attachment;

    /** @var Selector */
    private $selector;

    public function __construct(SelectableStreamInterface $stream, Selector $selector)
    {
        $this->stream = $stream;
        $this->selector = $selector;
    }

    public function getStream() : SelectableStreamInterface
    {
        return $this->stream;
    }

    public function isReadable() : bool
    {
        return $this->readyOps & SelectionKey::OP_READ;
    }

    public function isWritable() : bool
    {
        return $this->readyOps & SelectionKey::OP_WRITE;
    }

    public function isAcceptable() : bool
    {
        return $this->readyOps & SelectionKey::OP_ACCEPT;
    }

    public function setReadable(bool $true)
    {
        $bit = (int) $true;

        $this->readyOps ^= (-$bit ^ $this->readyOps) & SelectionKey::OP_READ;
    }

    public function setWritable(bool $true)
    {
        $bit = (int) $true;

        $this->readyOps ^= (-$bit ^ $this->readyOps) & SelectionKey::OP_WRITE;
    }

    public function setAcceptable(bool $true)
    {
        $bit = (int) $true;

        $this->readyOps ^= (-$bit ^ $this->readyOps) & SelectionKey::OP_ACCEPT;
    }

    /**
     * @param mixed $attachment
     */
    public function attach($attachment)
    {
        $this->attachment = $attachment;
    }

    /**
     * @return mixed
     */
    public function getAttachment()
    {
        return $this->attachment;
    }

    public function getSelector() : Selector
    {
        return $this->selector;
    }

    public function cancel($operation = SelectionKey::OP_ALL)
    {
        $this->selector->unregister($this->stream, $operation);
    }

    public function getReadyOps() : int
    {
        return $this->readyOps;
    }

    public function getInterestOps() : int
    {
        return $this->interestOps;
    }

    /**
     * @param int $ops
     * @throws LogicException
     */
    public function setInterestOps(int $ops)
    {
        if ($ops >= 0 && $ops <= static::OP_ALL) {
            $this->interestOps = $ops;
        } else {
            throw new LogicException("Invalid operation type: " . json_encode($ops));
        }
    }
}