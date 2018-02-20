<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use LogicException;
use Zeus\IO\Exception\EOFException;
use Zeus\IO\Stream\SelectionKey;

use function substr;

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
        if (null === $this->id) {
            throw new LogicException('Tunnel ID is not set');
        }
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
            $this->srcSelectionKey->cancel(SelectionKey::OP_READ);
            return;
        }

        $data = $this->srcSelectionKey->getStream()->read();

        if ('' === $data) {
            $this->srcSelectionKey->getStream()->shutdown(STREAM_SHUT_RD);
            // EOF
            throw new EOFException("Stream reached EOF mark");
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
            $stream->write($data);

            if ($stream->flush()) {
                if (!$this->isSaturated) {
                    return;
                }

                $this->isSaturated = false;
                if ($srcStream->isReadable()) {
                    $this->srcSelectionKey->getStream()->register($this->srcSelectionKey->getSelector(), SelectionKey::OP_READ);
                }
                $this->srcSelectionKey->cancel(SelectionKey::OP_WRITE);

                return;
            }

            if ($this->isSaturated) {
                return;
            }

            $this->isSaturated = true;
            if ($dstStream->isWritable()) {
                $this->dstSelectionKey->getStream()->register($this->dstSelectionKey->getSelector(), SelectionKey::OP_WRITE);
            }
            $this->srcSelectionKey->cancel(SelectionKey::OP_READ);
        }
    }
}