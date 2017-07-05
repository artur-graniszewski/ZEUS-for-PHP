<?php

namespace Zeus\Kernel\ProcessManager\Helper;

use SplObjectStorage;
use Zend\EventManager\ListenerAggregateInterface;

/**
 * Class PluginRegistry
 * @package Zeus\Kernel\ProcessManager\Helper
 * @internal
 */
trait PluginRegistry
{
    /** @var SplObjectStorage */
    protected $pluginRegistry;

    /**
     * Check if a plugin is registered
     *
     * @param  ListenerAggregateInterface $plugin
     * @return bool
     */
    public function hasPlugin(ListenerAggregateInterface $plugin)
    {
        $registry = $this->getPluginRegistry();
        return $registry->contains($plugin);
    }

    /**
     * Register a plugin
     *
     * @param  ListenerAggregateInterface $plugin
     * @param  int                    $priority
     * @return $this Fluent interface
     * @throws \LogicException
     */
    public function addPlugin(ListenerAggregateInterface $plugin, $priority = 1)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            throw new \LogicException(sprintf(
                'Plugin of type "%s" already registered',
                get_class($plugin)
            ));
        }

        $plugin->attach($this->getEventManager(), $priority);
        $registry->attach($plugin);

        return $this;
    }

    /**
     * Return registry of plugins
     *
     * @return SplObjectStorage|ListenerAggregateInterface[]
     */
    public function getPluginRegistry()
    {
        if (! $this->pluginRegistry instanceof SplObjectStorage) {
            $this->pluginRegistry = new SplObjectStorage();
        }
        return $this->pluginRegistry;
    }

    /**
     * Unregister an already registered plugin
     *
     * @param  ListenerAggregateInterface $plugin
     * @return $this Fluent interface
     * @throws \LogicException
     */
    public function removePlugin(ListenerAggregateInterface $plugin)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            $plugin->detach($this->getEventManager());
            $registry->detach($plugin);
        }

        return $this;
    }
}