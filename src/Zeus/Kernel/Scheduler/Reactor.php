<?php

namespace Zeus\Kernel\Scheduler;

use LogicException;
use TypeError;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zeus\IO\Stream\AbstractSelectorAggregate;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\Util\Math;
use Zeus\Util\UnitConverter;

use function key;
use function microtime;
use function array_search;
use function array_merge;
use function array_keys;
use function call_user_func;
use function call_user_func_array;
use function is_callable;
use function max;

/**
 * Class Reactor
 * @package Zeus\Kernel\Scheduler
 */
class Reactor extends AbstractSelectorAggregate implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    /** @var AbstractStreamSelector */
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

    private $timers = [];

    /** @var SelectionKey[] */
    private $selectedKeys;

    /** @var int Timeout in milliseconds, 1000 ms = 1 s */
    private $timerResolution = 1000;

    public function __construct()
    {
        $this->reactor = new Selector();
    }

    public function getNextTimeout() : int
    {
        $lastTick = $this->lastTick;
        $this->lastTick = microtime(true);
        $diff = UnitConverter::convertMicrosecondsToMilliseconds(microtime($this->lastTick) - $lastTick);

        $wait = (int) max(0, $this->timerResolution - $diff);

        return $wait;
    }

    public function mainLoop($callback)
    {
        if (!is_callable($callback)) {
            throw new TypeError("Invalid callback parameter");
        }

        do {
            call_user_func($callback, $this);

            $wait = $this->getNextTimeout();
            $changed = $this->select($wait);

            if (0 === $changed) {
                continue;
            }

            $selectorIdsToNotify = [];
            foreach ($this->getSelectionKeys() as $key) {
                $attachment = $key->getAttachment();
                $selectorId = $attachment['id'];
                /** @var SelectionKey $originalKey */
                $originalKey = $attachment['key'];
                $originalKey->setReadable($key->isReadable());
                $originalKey->setWritable($key->isWritable());
                $originalKey->setAcceptable($key->isAcceptable());
                $selectorIdsToNotify[$selectorId][] = $originalKey;
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
        $amount = $selector->select($timeout);
        $this->selectedKeys = $selector->getSelectionKeys();

        return $amount;
    }

    public function getSelector() : AbstractStreamSelector
    {
        $reactor = clone $this->reactor;

        foreach ($this->observedSelectors as $index => $selector) {
            $keys = $selector->getKeys();

            foreach ($keys as $key) {
                $key2 = $key->getStream()->register($reactor, $key->getInterestOps());
                $key2->attach([
                    'id' => $index,
                    'key' => $key,
                ]);
            }
        }

        return $reactor;
    }

    public function setSelector(AbstractStreamSelector $selector)
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

    /**
     * @param AbstractStreamSelector $selector
     * @param $callback
     * @param int $timeout Timeout in milliseconds
     */
    public function register(AbstractStreamSelector $selector, $callback, int $timeout)
    {
        $timeout *= 1000;
        $this->selectorCallbacks[] = $callback;
        $this->observedSelectors[] = $selector;
        $nextTick = microtime(true) + $timeout;
        $this->selectorTimeouts[] = ['nextTick' => $nextTick, 'timeout' => $timeout];

        $this->updateTimerResolution();
    }

    /**
     * @param callable $callback
     * @param int $timeout Timeout in milliseconds
     * @param bool $isPeriodic
     * @return mixed Timer ID
     */
    public function registerTimer($callback, int $timeout, bool $isPeriodic)
    {
        $timeout *= 1000;
        $nextTick = microtime(true) + $timeout;
        $this->timers[] = ['callback' => $callback, 'nextTick' => $nextTick, 'timeout' => $timeout, 'periodic' => $isPeriodic];

        $this->updateTimerResolution();

        return key($this->timers);
    }

    private function updateTimerResolution()
    {
        $timeouts = [1000];
        foreach (array_merge($this->timers, $this->selectorTimeouts) as $timer) {
            $timeouts[] = $timer['timeout'];
        }

        if (!isset($timeouts[1])) {
            $this->timerResolution = $timeouts[0];
            return;
        }

        $this->timerResolution = call_user_func_array([Math::class, 'gcd'], $timeouts);
    }

    /**
     * @param mixed $id Timer ID
     */
    public function unregisterTimer($id)
    {
        if (!isset($this->timers[$id])) {
            throw new LogicException("Cannot unregister: unknown timer");
        }
    }

    public function unregister(AbstractStreamSelector $selector)
    {
        $index = array_search($selector, $this->observedSelectors);

        if (false === $index) {
            throw new LogicException("Cannot unregister: unknown selector");
        }

        unset ($this->observedSelectors[$index]);
        unset ($this->selectorCallbacks[$index]);
        unset ($this->selectorTimeouts[$index]);
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
        $this->selectedKeys = $keys;
    }
}