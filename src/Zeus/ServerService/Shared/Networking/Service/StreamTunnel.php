<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\Stream\FlushableStreamInterface;
use Zeus\Networking\Stream\SelectionKey;
use Zeus\Networking\Stream\Selector;

class StreamTunnel
{
    /** @var SelectionKey  */
    private $srcSelectionKey;

    /** @var SelectionKey */
    private $dstSelectionKey;

    /** @var bool */
    private $isSaturated = false;

    /** @var int */
    private $id;

    /** @var string */
    private $dataBuffer = '';

    public function __construct(SelectionKey $srcSelectionKey, SelectionKey $dstSelectionKey)
    {
        $this->srcSelectionKey = $srcSelectionKey;
        $this->dstSelectionKey = $dstSelectionKey;
    }

    public function setId(int $id)
    {
        $this->id = $id;
    }

    public function getId() : int
    {
        return $this->id;
    }

    public function tunnel()
    {
        if ($this->isSaturated) {
            // try to flush existing data
            $this->write($this->dataBuffer);

            return;
        }

        if (!$this->srcSelectionKey->isReadable()) {
            return;
        }

        if (!$this->srcSelectionKey->getStream()->isReadable()) {
            $this->srcSelectionKey->cancel(Selector::OP_READ);
            return;
        }

        $data = $this->srcSelectionKey->getStream()->read();

        if ('' === $data) {
            // EOF
            throw new StreamException("EOF");
        }

        $this->write($data);
    }

    private function write(string $data)
    {
        $this->dataBuffer = '';
        $dstStream = $this->dstSelectionKey->getStream();
        $srcStream = $this->srcSelectionKey->getStream();

        if (!$this->isSaturated || $this->dstSelectionKey->isWritable()) {
            $stream = $dstStream;
            $wrote = $stream->write($data);

            if (($stream instanceof FlushableStreamInterface && $stream->flush()) || !isset($data[$wrote + 1])) {
                if (!$this->isSaturated) {
                    return;
                }

                $this->isSaturated = false;
                if ($srcStream->isReadable()) {
                    $this->srcSelectionKey->getStream()->register($this->srcSelectionKey->getSelector(), Selector::OP_READ);
                }
                $this->srcSelectionKey->cancel(Selector::OP_WRITE);

                return;
            }

            if (!$stream instanceof FlushableStreamInterface) {
                if ($wrote === 0) {
                    $this->dataBuffer = $data;
                } else {
                    $this->dataBuffer = substr($data, $wrote);
                }
            }

            if ($this->isSaturated) {
                return;
            }

            $this->isSaturated = true;
            if ($dstStream->isWritable()) {
                $this->dstSelectionKey->getStream()->register($this->dstSelectionKey->getSelector(), Selector::OP_WRITE);
            }
            $this->srcSelectionKey->cancel(Selector::OP_READ);
        }
    }
}