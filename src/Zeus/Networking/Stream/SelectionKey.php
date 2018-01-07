<?php

namespace Zeus\Networking\Stream;

class SelectionKey
{
    private $isReadable = false;

    private $isWritable = false;

    private $isAcceptable = false;

    /** @var SelectableStreamInterface */
    private $stream;

    private $object;

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
        return $this->isReadable;
    }

    public function isWritable() : bool
    {
        return $this->isWritable;
    }

    public function isAcceptable() : bool
    {
        return $this->isAcceptable;
    }

    public function setReadable(bool $true)
    {
        $this->isReadable = $true;
    }

    public function setWritable(bool $true)
    {
        $this->isWritable = $true;
    }

    public function setAcceptable(bool $true)
    {
        $this->isAcceptable = $true;
    }

    public function attach($object)
    {
        if (!is_object($object)) {
            throw new \LogicException("Input parameter must be of an object type");
        }
        $this->object = $object;
    }

    public function getAttachment()
    {
        return $this->object;
    }

    public function getSelector() : Selector
    {
        return $this->selector;
    }

    public function cancel($operation = Selector::OP_ALL)
    {
        $this->selector->unregister($this->stream, $operation);
    }
}