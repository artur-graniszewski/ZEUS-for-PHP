<?php

namespace Zeus\Kernel;

use SplObjectStorage;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\Log\LoggerInterface;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\Reactor;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;

interface SchedulerInterface extends EventManagerAwareInterface
{
    const WORKER_SERVER = 'server';
    const WORKER_INIT = 'initWorker';

    public function getMultiProcessingModule() : MultiProcessingModuleInterface;

    public function observeSelector(AbstractStreamSelector $selector, $onSelectCallback, $onTimeoutCallback, int $timeout);

    public function getReactor() : Reactor;

    public function syncWorker(WorkerState $worker);

    public function getStatus() : WorkerState;

    public function getSchedulerEvent() : SchedulerEvent;

    public function start(bool $launchAsDaemon = false);

    public function stop();

    public function isTerminating() : bool;

    public function getIpc() : IpcServer;

    public function getLogger() : LoggerInterface;

    public function getConfig() : ConfigInterface;

    /**
     * Return registry of plugins
     *
     * @return SplObjectStorage|ListenerAggregateInterface[]
     */
    public function getPluginRegistry() : SplObjectStorage;

    public function getPluginByClass(string $className) : ListenerAggregateInterface;

    public function hasPlugin(ListenerAggregateInterface $plugin) : bool;

    public function addPlugin(ListenerAggregateInterface $plugin, int $priority = 1);

    public function removePlugin(ListenerAggregateInterface $plugin);

    /**
     * @return WorkerCollection|WorkerState[]
     */
    public function getWorkers() : WorkerCollection;

    public function setTerminating(bool $true);
}