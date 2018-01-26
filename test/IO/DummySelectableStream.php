<?php

namespace ZeusTest\IO;

use Zeus\IO\Stream\AbstractSelectableStream;

class DummySelectableStream extends AbstractSelectableStream
{
    private $dataWritten = '';

    private $dataToRead = '';

    private $resourceId = 0;

    private static $resourceIndex = 0;

    public function __construct($resource, $peerName = null)
    {
        static::$resourceIndex++;

        $this->detectResourceMode();
        $this->resourceId = static::$resourceIndex;
    }

    protected function detectResourceMode()
    {
        $this->isWritable = true;
        $this->isReadable = true;
    }

    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function setReadable(bool $isReadable)
    {
        $this->isReadable = $isReadable;
    }

    public function setWritable(bool $isWritable)
    {
        $this->isWritable = $isWritable;
    }

    public function setClosed(bool $isClosed)
    {
        $this->isClosed = $isClosed;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function isWritable(): bool
    {
        return $this->isWritable;
    }

    public function isReadable(): bool
    {
        return $this->isReadable;
    }

    protected function doWrite($writeMethod): int
    {
        $this->dataWritten .= $this->writeBuffer;

        $wrote = strlen($this->writeBuffer);
        $this->writeBuffer = '';
        return $wrote;
    }

    protected function doRead($readMethod, string $ending = ''): string
    {
        $data = $this->dataToRead;
        $this->dataToRead = '';
        return $data;
    }

    public function getWrittenData() : string
    {
        $data = $this->dataWritten;
        $this->dataWritten = '';

        return $data;
    }

    public function setDataToRead(string $data)
    {
        $this->dataToRead = $data;
    }
}