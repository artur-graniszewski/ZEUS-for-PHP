<?php

namespace ZeusBench\Ipc;

use Athletic\AthleticEvent;
use Zeus\Kernel\IpcServer\Adapter\FifoAdapter;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use ZeusTest\Helpers\ZeusFactories;

class FifoIpcBenchmark extends AthleticEvent
{
    use ZeusFactories;

    protected $largeMessage;

    protected $mediumMessage;

    protected $smallMessage;

    protected $serviceManager;

    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    public function __construct()
    {
        $this->largeMessage = str_repeat('A', 65536);
        $this->mediumMessage = str_repeat('A', 32768);
        $this->smallMessage = str_repeat('A', 4096);
        $this->serviceManager = $this->getServiceManager();
        $this->ipcAdapter = $this->serviceManager->build(IpcAdapterInterface::class, ['ipc_adapter' => FifoAdapter::class, 'service_name' => 'zeus-test-' . md5(microtime(true))]);

        $adapter = $this->ipcAdapter;
        if (!$adapter::isSupported()) {
            throw new \RuntimeException('The PHP configuration or OS system does not support ' . get_class($adapter));
        }
    }

    public function __destruct()
    {
        $this->ipcAdapter->disconnect();
    }

    /**
     * @iterations 10000
     */
    public function testSmallMessage()
    {
        $this->ipcAdapter->useChannelNumber(0);
        $this->ipcAdapter->send($this->smallMessage);
        $this->ipcAdapter->useChannelNumber(1);
        $message = $this->ipcAdapter->receive();
        if ($message !== $this->smallMessage) {
            throw new \Exception('Small message is corrupted');
        }
    }

    /**
     * @iterations 10000
     */
    public function testMediumMessage()
    {
        $this->ipcAdapter->useChannelNumber(0);
        $this->ipcAdapter->send($this->mediumMessage);
        $this->ipcAdapter->useChannelNumber(1);
        $message = $this->ipcAdapter->receive();
        if ($message !== $this->mediumMessage) {
            throw new \Exception('Medium message is corrupted: ' . $message);
        }
    }

    /**
     * iterations 1000
     */
    private function testLargeMessage()
    {
        $this->ipcAdapter->useChannelNumber(0);
        $this->ipcAdapter->send($this->largeMessage);
        $this->ipcAdapter->useChannelNumber(1);
        $message = $this->ipcAdapter->receive();
        if ($message !== $this->largeMessage) {
            throw new \Exception('Large message is corrupted');
        }
    }
}