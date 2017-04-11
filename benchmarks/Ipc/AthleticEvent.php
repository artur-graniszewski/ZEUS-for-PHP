<?php

namespace ZeusBench\Ipc;

use Athletic\AthleticEvent as Event;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\IpcServer\Adapter\ApcAdapter;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use ZeusTest\Helpers\ZeusFactories;

class AthleticEvent extends Event
{
    use ZeusFactories;

    protected $largeMessage;

    protected $mediumMessage;

    protected $smallMessage;

    protected $serviceManager;

    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    protected $ipcAdapterName = ApcAdapter::class;

    public function __construct()
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $this->largeMessage = str_repeat('A', 65536) . "\n";
        $this->mediumMessage = str_repeat('A', 32768) . "\n";
        $this->smallMessage = str_repeat('A', 4096) . "\n";
        $this->serviceManager = $this->getServiceManager();
        $this->ipcAdapter = $this->serviceManager->build(IpcAdapterInterface::class, ['logger_adapter' => $logger, 'ipc_adapter' => $this->ipcAdapterName, 'service_name' => 'zeus-test-' . md5(microtime(true))]);

        $adapter = $this->ipcAdapter;
        if (!$adapter->isSupported()) {
            throw new \RuntimeException('The PHP configuration or OS system does not support ' . get_class($adapter));
        }
        $adapter->connect();
    }

    public function __destruct()
    {
        $this->ipcAdapter->disconnect();
    }

    /**
     * @iterations 1000
     */
    public function testSmallMessage()
    {
        $this->ipcAdapter->useChannelNumber(0);
        $this->ipcAdapter->send($this->smallMessage);
        $this->ipcAdapter->useChannelNumber(1);
        $message = $this->ipcAdapter->receive();
        if ($message !== $this->smallMessage) {
            var_dump(json_encode($message), json_encode($this->smallMessage)); die();
            throw new \Exception('Small message is corrupted');
        }
    }

    /**
     * @iterations 1000
     */
    public function testMediumMessage()
    {
        $this->ipcAdapter->useChannelNumber(0);
        $this->ipcAdapter->send($this->mediumMessage);
        $this->ipcAdapter->useChannelNumber(1);
        $message = $this->ipcAdapter->receive();
        if ($message !== $this->mediumMessage) {
            throw new \Exception('Medium message is corrupted');
        }
    }

    /**
     * @iterations 1000
     */
    public function testLargeMessage()
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