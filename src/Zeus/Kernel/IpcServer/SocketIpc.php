<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\Kernel\IpcServer;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\FlushableStreamInterface;
use Zeus\IO\Stream\SelectableStreamInterface;
use Zeus\ServerService\Shared\Networking\Service\ReadBuffer;

use function strlen;

/**
 * Class SocketIpc
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
class SocketIpc extends ReadBuffer implements IpcDriver
{
    /** @var SelectableStreamInterface */
    public $stream;

    /** @var int */
    private $senderId;

    use MessagePackager;

    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function setId(int $senderId)
    {
        $this->senderId = $senderId;
    }

    public function getId() : int
    {
        return $this->senderId;
    }

    public function send($message, string $audience = IpcServer::AUDIENCE_ALL, int $number = 0)
    {
        $payload = [
            'sid' => $this->senderId,
            'aud' => $audience,
            'msg' => $message,
            'num' => $number,
        ];

        $data = $this->packMessage($payload);
        $len = strlen($data) + 1;
        while ($this->stream->write($data . "\0") < $len && !$this->stream instanceof FlushableStreamInterface) {

        }

        if ($this->stream instanceof FlushableStreamInterface) {
            while (!$this->stream->flush()) {

            }
        }
    }

    public function isReadable() : bool
    {
        $selector = new Selector();
        $this->stream->register($selector, SelectionKey::OP_READ);
        return (bool) $selector->select(0);
    }

    public function readAll(bool $returnRaw = false) : array
    {
        $messages = $this->decodeMessages($returnRaw);
        if ($messages) {
            return $messages;
        }

        $buffer = $this->stream->read();

        if ('' === $buffer) {
            throw new IOException("Connection closed");
        }

        $this->append($buffer);

        return $this->decodeMessages($returnRaw);
    }

    private function decodeMessages(bool $returnRaw)
    {
        $messages = [];
        $pos = $this->find("\0");

        while (0 < $pos) {
            $message = $this->unpackMessage($this->read($pos + 1));
            $messages[] = $returnRaw ? $message : $message['msg'];
            $pos = $this->find("\0");
        }

        return $messages;
    }
}