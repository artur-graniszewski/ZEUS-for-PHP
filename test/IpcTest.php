<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\IpcServer\Adapter\ApcAdapter;
use Zeus\Kernel\IpcServer\Adapter\FifoAdapter;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\Adapter\MsgAdapter;
use Zeus\Kernel\IpcServer\Adapter\SharedMemoryAdapter;
use Zeus\Kernel\IpcServer\Adapter\SocketAdapter;
use Zeus\Kernel\IpcServer\MessageQueueCapacityInterface;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;
use Zeus\ServerService\Shared\Logger\IpcLoggerInterface;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\ZeusFactories;

class IpcTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    public function getIpcAdapters()
    {
        return [
            [FifoAdapter::class],
            [SocketAdapter::class],
            [MsgAdapter::class],
            [ApcAdapter::class],
            [SharedMemoryAdapter::class],
        ];
    }

    /**
     * @dataProvider getIpcAdapters
     * @param string $adapter
     */
    public function testIpcAdapters($adapter)
    {
        $messagesAmount = 100;

        $logger = new Logger();
        $logger->addWriter(new Noop());

        $sm = $this->getServiceManager();
        /** @var IpcAdapterInterface $ipcAdapter */
        $ipcAdapter = $sm->build(IpcAdapterInterface::class, [
            'ipc_adapter' => $adapter,
            'service_name' => 'zeus-test-' . md5($adapter),
            'logger_adapter' => $logger
        ]);

        $this->assertInstanceOf($adapter, $ipcAdapter);

        if (!$ipcAdapter->isSupported()) {
            $this->markTestSkipped('The PHP configuration or OS system does not support ' . get_class($ipcAdapter));
        }

        $ipcAdapter->connect();

        $this->assertEquals(0, count($ipcAdapter->receiveAll(0)), 'Input queue should be empty');
        $this->assertEquals(0, count($ipcAdapter->receiveAll(1)), 'Output queue should be empty');

        foreach (range(1, $messagesAmount) as $index) {
            $ipcAdapter->send(0, 'Message number ' . $index);
        }

        $this->assertEquals(0, count($ipcAdapter->receiveAll(0)), $adapter . ' input queue should be empty after sending some data');

        $output = $ipcAdapter->receiveAll(1);
        $this->assertEquals($messagesAmount, count($output), 'Output queue should contain all the messages: ' . json_encode($output));
        $this->assertEquals(0, count($ipcAdapter->receiveAll(1)), 'Output queue should be empty after fetching the data');

        foreach (range(1, $messagesAmount) as $index) {
            $message = 'Message number ' . $index;

            $this->assertContains($message, $output, $message . ' should have been returned as output');
        }

        if ($ipcAdapter instanceof MessageSizeLimitInterface) {
            $this->assertGreaterThan(0, $ipcAdapter->getMessageSizeLimit());
        }

        if ($ipcAdapter instanceof MessageQueueCapacityInterface) {
            $this->assertGreaterThan(0, $ipcAdapter->getMessageQueueCapacity());
        }

        $ipcAdapter->disconnect();
    }

    /**
     * @dataProvider getIpcAdapters
     * @param string $adapter
     */
    public function testIpcDisconnects($adapter)
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $sm = $this->getServiceManager();
        /** @var IpcAdapterInterface $ipcAdapter */
        $ipcAdapter = $sm->build(IpcAdapterInterface::class, [
            'ipc_adapter' => $adapter,
            'service_name' => 'zeus-test2-' . md5($adapter . microtime(true)),
            'logger_adapter' => $logger
        ]);

        if (!$ipcAdapter->isSupported()) {
            $this->markTestSkipped('The PHP configuration or OS system does not support ' . get_class($ipcAdapter));
        }

        $ipcAdapter->connect();

        $this->assertInstanceOf($adapter, $ipcAdapter);

        $this->assertEquals(0, count($ipcAdapter->receiveAll(0)), 'Input queue should be empty');

        $this->assertEquals(0, count($ipcAdapter->receiveAll(1)), 'Output queue should be empty');

        $ipcAdapter->disconnect(1);

        $ex1 = $ex2 = null;
        try {
            $ipcAdapter->checkChannelAvailability(1);
        } catch (\LogicException $ex1) {

        }
        $ipcAdapter->disconnect(0);
        try {
            $ipcAdapter->checkChannelAvailability(0);
        } catch (\LogicException $ex2) {

        }

        $this->assertInstanceOf(\LogicException::class, $ex1, "$adapter does not support channel disconnect");
        $this->assertEquals('Channel number 1 is unavailable', $ex1->getMessage(), "$adapter does not support channel disconnect");
        $this->assertInstanceOf(\LogicException::class, $ex2, "$adapter does not support channel disconnect");
        $this->assertEquals('Channel number 0 is unavailable', $ex2->getMessage(), "$adapter does not support channel disconnect");
    }

    public function testIpcLogger()
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $serviceName = 'zeus-test-' . md5(__CLASS__);
        $sm = $this->getServiceManager();
        /** @var IpcAdapterInterface $ipcAdapter */
        $ipcAdapter = $sm->build(IpcAdapterInterface::class, [
            'ipc_adapter' => SocketAdapter::class,
            'service_name' => $serviceName,
            'logger_adapter' => $logger
        ]);

        /** @var LoggerInterface $logger */
        $logger = $sm->build(IpcLoggerInterface::class, ['ipc_adapter' => $ipcAdapter, 'service_name' => $serviceName]);
        $logger->info('TEST MESSAGE');

        $results = $ipcAdapter->receiveAll(0);
        $this->assertEquals(1, count($results));
        $this->assertEquals('INFO', $results[0]['priorityName']);
        $this->assertEquals('TEST MESSAGE', $results[0]['message']);
    }
}