<?php

namespace Zeus\Kernel\Scheduler\Helper;

use LogicException;
use SplObjectStorage;
use Zend\EventManager\ListenerAggregateInterface;

use function get_class;
use function sprintf;

/**
 * Class PluginRegistry
 * @package Zeus\Kernel\Scheduler\Helper
 * @internal
 */
trait PluginRegistry
{
    /** @var SplObjectStorage */
    private $pluginRegistry;

    /**
     * Check if a plugin is registered
     *
     * @param  ListenerAggregateInterface $plugin
     * @return bool
     */
    public function hasPlugin(ListenerAggregateInterface $plugin) : bool
    {
        $registry = $this->getPluginRegistry();

        return $registry->contains($plugin);
    }

    /**
     * Register a plugin
     *
     * @param  ListenerAggregateInterface $plugin
     * @param  int                    $priority
     * @throws LogicException
     */
    public function addPlugin(ListenerAggregateInterface $plugin, int $priority = 1)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            throw new LogicException(sprintf(
                'Plugin of type "%s" already registered',
                get_class($plugin)
            ));
        }

        $plugin->attach($this->getEventManager(), $priority);
        $registry->attach($plugin);
    }

    /**
     * Return registry of plugins
     *
     * @return SplObjectStorage|ListenerAggregateInterface[]
     */
    public function getPluginRegistry() : SplObjectStorage
    {
        if (!$this->pluginRegistry instanceof SplObjectStorage) {
            $this->pluginRegistry = new SplObjectStorage();
        }
        return $this->pluginRegistry;
    }

    public function getPluginByClass(string $className) : ListenerAggregateInterface
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            if (get_class($plugin) === $className || in_array($className, class_parents($plugin))) {
                return $plugin;
            }
        }

        throw new LogicException(sprintf(
            'Plugin of type "%s" not found',
            $className
        ));
    }

    /**
     * Unregister an already registered plugin
     *
     * @param  ListenerAggregateInterface $plugin
     * @throws LogicException
     */
    public function removePlugin(ListenerAggregateInterface $plugin)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            $plugin->detach($this->getEventManager());
            $registry->detach($plugin);
        }
    }
}