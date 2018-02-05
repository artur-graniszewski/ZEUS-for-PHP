<?php

namespace Zeus\Kernel\Scheduler\Helper;

use Interop\Container\ContainerInterface;
use Zend\EventManager\ListenerAggregateInterface;

use function is_int;
use function is_object;
use function is_string;
use function class_exists;
use function in_array;
use function is_array;
use function class_implements;

/**
 * Class PluginFactory
 * @package Zeus\Kernel\Scheduler\Helper
 * @internal
 */
trait PluginFactory
{
    protected function startPlugins(ContainerInterface $container, $service, array $config)
    {
        foreach ($config as $index => $value) {
            if (is_int($index)) {
                if (is_object($value) && $value instanceof ListenerAggregateInterface) {
                    $service->addPlugin($value);

                    continue;
                }

                if (is_string($value)) {
                    if ($container->has($value)) {
                        $plugin = $container->build($value, []);
                        $service->addPlugin($plugin);

                        continue;
                    }

                    if (class_exists($value) && in_array(ListenerAggregateInterface::class, class_implements($value))) {
                        $plugin = new $value();
                        $service->addPlugin($plugin);

                        continue;
                    }
                }
            }

            if (is_string($index)) {
                if ($container->has($index)) {
                    $plugin = $container->build($index, is_array($value) ? $value : []);
                    $service->addPlugin($plugin);

                    continue;
                }

                if (class_exists($index) && in_array(ListenerAggregateInterface::class, class_implements($index))) {
                    $plugin = new $index(is_array($value) ? $value : []);
                    $service->addPlugin($plugin);

                    continue;
                }
            }

            throw new \LogicException("Plugin must implement " . ListenerAggregateInterface::class);
        }
    }
}