<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;
use Zeus\Networking\Stream\AbstractStream;
use Zeus\Networking\Stream\FlushableStreamInterface;

/**
 * Class SocketIpc
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
class SocketIpc extends IpcDriver
{
    /** @var AbstractStream */
    public $stream;

    /** @var int */
    protected $senderId;

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

    public function readAll(bool $returnRaw = false) : array
    {
        $messages = [];

        while ($data = $this->stream->read("\0")) {
            $message = $this->unpackMessage($data);
            $messages[] = $returnRaw ? $message : $message['msg'];
        }

        if ($this->stream->select(0)) {
            trigger_error(json_encode($this->stream->read()));
        }

        return $messages;
    }
}