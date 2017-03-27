<?php

namespace Zeus\Kernel\ProcessManager\Helper;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Message;

/**
 * Class Logger
 * @package Zeus\Kernel\ProcessManager\Helper
 * @internal
 */
trait Logger
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var mixed[] */
    protected $loggerExtraDetails;

    /** @var string */
    private $loggerServiceName;

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return mixed[]
     */
    public function getLoggerExtraDetails()
    {
        return $this->loggerExtraDetails;
    }

    /**
     * @param mixed[] $loggerExtraDetails
     * @return Logger
     */
    public function setLoggerExtraDetails($loggerExtraDetails)
    {
        $this->loggerExtraDetails = $loggerExtraDetails;

        $this->loggerServiceName = isset($loggerExtraDetails['service']) ? $loggerExtraDetails['service'] : null;

        return $this;
    }

    /**
     * Logs server messages.
     *
     * @param Message $message
     * @return $this
     */
    protected function logMessage($message)
    {
        $extra = $message['extra'];
        $extra['service_name'] = sprintf("%s-%d", $this->loggerServiceName, $extra['uid']);
        $this->log($message['priority'], $message['message'], $extra);

        return $this;
    }

    /**
     * @param int $priority
     * @param string $message
     * @param mixed[] $extra
     * @return $this
     */
    protected function log($priority, $message, $extra = [])
    {
        if (!isset($extra['service_name'])) {
            $extra['service_name'] = $this->loggerServiceName;
        }

        if (!isset($extra['logger'])) {
            $extra['logger'] = __CLASS__;
        }

        $this->getLogger()->log($priority, $message, $extra);

        return $this;
    }
}