<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\Stream\FlushableStreamInterface;
use Zeus\Networking\Stream\SelectableStreamInterface;

/**
 * Class SocketIpc
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
class SocketIpc extends IpcDriver
{
    /** @var SelectableStreamInterface */
    public $stream;

    /** @var int */
    private $senderId;

    private $buffer = '';

    use MessagePackager;

    public function __construct($stream, $senderId)
    {
        $this->senderId = $senderId;

        $this->stream = $stream;
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
        return $this->stream->select(0);
    }

    public function readAll(bool $returnRaw = false) : array
    {
        $messages = [];

        $buffer = $this->stream->read();
        if ('' === $buffer) {
            throw new StreamException("Connection closed");
        }

        $this->buffer .= $buffer;

        if (false === ($pos = strrpos($this->buffer, "\0"))) {
            return $messages;
        }

        $data = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);
        foreach (explode("\0", $data) as $data) {
            $message = $this->unpackMessage($data);
            $messages[] = $returnRaw ? $message : $message['msg'];
        }

        return $messages;
    }
}