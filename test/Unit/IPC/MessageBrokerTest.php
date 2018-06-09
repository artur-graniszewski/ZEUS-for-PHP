<?php

namespace ZeusTest\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Zeus\IO\Stream\PipeStream;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\Service\MessageBroker;
use Zeus\Kernel\Scheduler\Reactor;

/**
 * Class ReactorTest
 * @package ZeusTest\Kernel\Scheduler
 */
class MessageBrokerTest extends TestCase
{
    private function getMessage($senderId, $audience, $message, $number = 1)
    {
        $payload['aud'] = $audience;
        $payload['msg'] = $message;
        $payload['sid'] = $senderId;
        $payload['num'] = $number;

        return $payload;
    }

    public function serverAudienceDataProvider()
    {
        return [
            [$this->getMessage(10, IpcServer::AUDIENCE_SERVER, "test1", 1), 10, 0, "test1", [10, 11, 12]],
            [$this->getMessage(11, IpcServer::AUDIENCE_SERVER, "test2", 1), 11, 0, "test2", [1]],
            [$this->getMessage(9, IpcServer::AUDIENCE_SELECTED, "test3", 11), 9, 11, "test3", [10, 11, 12]],
        ];
    }

    /**
     * @dataProvider serverAudienceDataProvider
     */
    public function testSingleAudience($message, $expectedSenderId, $expectedTargetId, $expectedMessage, $availableAudience)
    {
        $broker = new MessageBroker();
        $broker->distributeMessages([$message], $availableAudience,
            function($senderId, $targetId, $message) use ($expectedMessage, $expectedSenderId, $expectedTargetId) {
                $this->assertEquals($expectedMessage, $message);
                $this->assertEquals($expectedSenderId, $senderId);
                $this->assertEquals($expectedTargetId, $targetId);
            });
    }

    public function multipleAudienceProvider()
    {
        return [
            [IpcServer::AUDIENCE_AMOUNT, 3],
            [IpcServer::AUDIENCE_ANY, 1],
        ];
    }

    /**
     * @param $audienceType
     * @param $amount
     * @dataProvider multipleAudienceProvider
     */
    public function testMultipleAudience($audienceType, $amount)
    {
        $message = $this->getMessage(13, $audienceType, "test4", $amount);

        $targetIds = [];
        $broker = new MessageBroker();
        $broker->distributeMessages([$message], [1, 2, 3, 4],
            function($senderId, $targetId, $message) use (&$targetIds) {
                $targetIds[] = $targetId;
            });

        $this->assertTrue(array_intersect($targetIds, [1, 2, 3, 4]) == $targetIds);
        $this->assertEquals($amount, count(array_unique($targetIds)));
    }
}