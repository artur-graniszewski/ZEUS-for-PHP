<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;
use Zeus\Networking\Stream\AbstractStream;
use Zeus\Networking\Stream\FlushableConnectionInterface;

/**
 * Class SocketIpc
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
class SocketIpc extends IpcDriver
{
    /**
     * @var AbstractStream
     */
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

        $this->stream->write($data . "\0");

        if ($this->stream instanceof FlushableConnectionInterface) {
            $this->stream->flush();
        }
    }

    public function readAll(bool $returnRaw = false) : array
    {
        $messages = [];

        while ($data = $this->stream->read("\0")) {
            $message = $this->unpackMessage($data);
            $messages[] = $returnRaw ? $message : $message['msg'];
        }

        return $messages;
    }
}