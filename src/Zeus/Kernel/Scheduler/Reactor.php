<?php

namespace Zeus\Kernel\Scheduler;

use LogicException;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Stream\AbstractSelector;

use function microtime;
use function array_search;
use function array_keys;
use function call_user_func;
use Zeus\IO\Stream\SelectableStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;

/**
 * Class Reactor
 * @package Zeus\Kernel\Scheduler
 * @codeCoverageIgnore
 */
class Reactor extends AbstractSelector implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    /** @var Selector */
    private $reactor;

    /** @var float */
    private $lastTick = 0.0;

    /** @var bool */
    private $isTerminating = false;

    /** @var Selector[] */
    private $observedSelectors = [];

    private $selectorCallbacks = [];

    /** @var mixed[] */
    private $selectorTimeouts;

    /** @var SelectionKey[] */
    private $selectedKeys;

    public function __construct()
    {
        $this->reactor = new Selector();
    }

    public function mainLoop()
    {
        do {
            $this->selectedKeys = [];
            $selector = $this->getSelector();

            $lastTick = $this->lastTick;
            $this->lastTick = microtime(true);
            $diff = microtime($this->lastTick) - $lastTick;

            $wait = (int)($diff < 1 ? (1 - $diff) * 1000 : 100);
            if (0 === $this->select($wait)) {
                continue;
            }

            $selectorIdsToNotify = [];
            foreach ($selector->getSelectionKeys() as $key) {
                $selectorIdsToNotify[$key->getAttachment()][] = $key;
                $this->selectedKeys[] = $key;
            }

            foreach (array_keys($selectorIdsToNotify) as $id) {
                $observedSelector = $this->observedSelectors[$id];
                $observedSelector->setSelectionKeys($selectorIdsToNotify[$id]);
                call_user_func($this->selectorCallbacks[$id], $this->observedSelectors[$id]);
            }
        } while (!$this->isTerminating());
    }

    public function select(int $timeout = 0) : int
    {
        $selector = $this->getSelector();
        $amount = $selector->select();
        $this->selectedKeys = $selector->getSelectionKeys();

        return $amount;
    }

    public function getSelector() : Selector
    {
        $reactor = clone $this->reactor;

        foreach ($this->observedSelectors as $index => $selector) {
            $keys = $selector->getKeys();

            foreach ($keys as $key) {
                $key->getStream()->register($reactor, $key->getInterestOps());
                $key->attach($index);
            }
        }

        return $reactor;
    }

    public function setSelector(Selector $selector)
    {
        $this->reactor = $selector;
    }

    public function setTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }

    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }

    public function observeSelector(Selector $selector, $callback, int $timeout)
    {
        $this->selectorCallbacks[] = $callback;
        $this->observedSelectors[] = $selector;
        $this->selectorTimeouts[] = ['nextTick' => 0.0, 'timout' => $timeout];
    }

    public function unregisterSelector(Selector $selector)
    {
        $index = array_search($selector, $this->observedSelectors);

        if (false === $index) {
            throw new LogicException("Cannot unregister: unknown selector");
        }

        unset ($this->observedSelectors[$index]);
        unset ($this->selectorCallbacks[$index]);
        unset ($this->selectorTimeouts[$index]);
    }

    public function register(SelectableStreamInterface $stream, int $operation = SelectionKey::OP_ALL) : SelectionKey
    {
        throw new UnsupportedOperationException("Direct registration of Streams is unsupported by Reactor");
    }

    public function unregister(SelectableStreamInterface $stream, int $operation = SelectionKey::OP_ALL)
    {
        throw new UnsupportedOperationException("Direct unregistration of Streams is unsupported by Reactor");
    }

    /**
     * @return SelectionKey[]
     */
    public function getKeys() : array
    {
        $result = [];
        foreach ($this->observedSelectors as $index => $selector) {
            $keys = $selector->getKeys();

            foreach ($keys as $key) {
                $result[] = $key;
            }
        }

        return $result;
    }

    /**
     * @return SelectionKey[]
     */
    public function getSelectionKeys() : array
    {
        return $this->selectedKeys;
    }

    /**
     * @param SelectionKey[] $keys
     */
    protected function setSelectionKeys(array $keys)
    {
        $this->setSelectionKeys = $keys;
    }
}