<?php

namespace Zeus\Kernel\ProcessManager\Helper;

use Interop\Container\ContainerInterface;
use Zend\EventManager\ListenerAggregateInterface;

/**
 * Class PluginFactory
 * @package Zeus\Kernel\ProcessManager\Helper
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