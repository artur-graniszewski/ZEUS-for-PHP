<?php

namespace Zeus\Kernel\IpcServer\Service;

use OutOfBoundsException;
use Zeus\Kernel\IpcServer;

use function array_rand;
use function array_shift;
use function count;

class MessageBroker
{
    /** @var mixed[] */
    private $queuedMessages = [];

    public function getQueuedMessage()
    {
        if (count($this->queuedMessages) > 0) {
            return array_shift($this->queuedMessages);
        }

        return null;
    }

    /**
     * @param mixed[] $messages
     * @param array $availableAudience
     * @param callable $senderCallback
     */
    public function distributeMessages(array $messages, array $availableAudience, callable $senderCallback)
    {
        foreach ($messages as $payload) {
            $cids = [];
            $audience = $payload['aud'];
            $message = $payload['msg'];
            $senderId = $payload['sid'];
            $number = $payload['num'];

            // sender is not an audience
            unset($availableAudience[$senderId]);

            // @todo: implement read confirmation?
            switch ($audience) {
                case IpcServer::AUDIENCE_ALL:
                    $cids = array_keys($availableAudience);
                    break;

                case IpcServer::AUDIENCE_ANY:
                    if (1 > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }

                    $cids = [array_rand(array_flip($availableAudience), 1)];

                    break;

                case IpcServer::AUDIENCE_AMOUNT:
                    $diff = count($availableAudience) - $number;
                    if ($diff < 0) {
                        $queuedPayload = $payload;
                        $queuedPayload['num'] = -$diff;
                        $this->queuedMessages[] = $queuedPayload;
                        $number += $diff;
                    }

                    $cids = array_rand(array_flip($availableAudience), $number);
                    if ($number === 1) {
                        $cids = [$cids];
                    }

                    break;

                case IpcServer::AUDIENCE_SELECTED:
                    if (!in_array($number, $availableAudience)) {
                        throw new OutOfBoundsException("Message can't be delivered, no subscriber with ID: $number");
                    }
                    $cids = [$number];
                    break;

                case IpcServer::AUDIENCE_SERVER:
                case IpcServer::AUDIENCE_SELF:
                    $cids = [0];

                    break;
                default:
                    $cids = [];
                    break;
            }

            foreach ($cids as $cid) {
                $senderCallback($senderId, $cid, $message);
            }
        }
    }
}