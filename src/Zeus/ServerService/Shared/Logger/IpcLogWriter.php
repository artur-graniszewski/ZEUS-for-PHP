<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Log\Writer\AbstractWriter;
use Zend\Log\Writer\WriterInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

class IpcLogWriter extends AbstractWriter implements WriterInterface
{
    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    /**
     * @param IpcAdapterInterface $adapter
     * @return $this
     */
    public function setIpcAdapter(IpcAdapterInterface $adapter)
    {
        $this->ipcAdapter = $adapter;
        if (!$adapter->isConnected()) {
            $adapter->connect();
        }

        return $this;
    }

    /**
     * Write a message to the log
     *
     * @param mixed[] $event log data event
     * @return void
     */
    protected function doWrite(array $event)
    {
        $event['type'] = 'log';
        if (!isset($event['extra']['logger'])) {
            $event['extra']['logger'] = '<unknown>';
        }

        if (!isset($event['extra']['uid'])) {
            $event['extra']['uid'] = '?';
        }

        $this->ipcAdapter->send(1, $event);
    }
}