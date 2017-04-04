<?php

namespace Zeus\Kernel\IpcServer\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Kernel\IpcServer\Adapter\FifoAdapter;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\MessageQueueCapacityInterface;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;

final class IpcServerFactory implements FactoryInterface
{
    /** @var IpcAdapterInterface[] */
    protected static $channels = [];

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $channelName = $options['service_name'];

        $logger = $options['logger_adapter'];
        $ipcAdapter = isset($options['ipc_adapter']) ? $options['ipc_adapter'] : FifoAdapter::class;

        if (!isset(self::$channels[$channelName])) {
            $ipcInstance = $container->build($ipcAdapter, $options);
            self::$channels[$channelName] = $ipcInstance;
            $logger->info(sprintf("Using %s for $channelName IPC", $ipcAdapter));
            $info = [];
            if ($ipcInstance instanceof MessageQueueCapacityInterface) {
                $info[] = sprintf('queue capacity: %d messages', $ipcInstance->getMessageQueueCapacity());
            }

            if ($ipcInstance instanceof MessageSizeLimitInterface) {
                $info[] = sprintf('message size limit: %d bytes', $ipcInstance->getMessageSizeLimit());
            }

            if ($info) {
                $logger->info(sprintf("Enumerating IPC capabilities:"));
                foreach ($info as $row) {
                    $logger->info(sprintf("IPC %s", $row));
                }
            }
        }

        return self::$channels[$channelName];
    }
}