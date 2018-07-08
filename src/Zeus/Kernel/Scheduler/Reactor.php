<?php

namespace Zeus\Kernel\Scheduler;

use Closure;
use LogicException;
use OutOfRangeException;
use TypeError;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
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
 * @internal
 */
class Reactor extends AbstractSelectorAggregate implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    /** @var int */
    private $timerResolutionTolerance = 10;

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
    private $selectorTimeouts = [];

    private $timers = [];

    /** @var SelectionKey[] */
    private $selectedKeys = [];

    /** @var int Timeout in milliseconds, 1000 ms = 1 s */
    private $timerResolution = 1000;

    public function __construct()
    {
        $this->reactor = new Selector();
    }

    public function setTimerResolutionTolerance(int $percentage)
    {
        if ($percentage > 20 || $percentage < 0) {
            throw new OutOfRangeException("Tolerance should be in range of 0-20%");
        }
        $this->timerResolutionTolerance = $percentage;
    }

    public function getTimerResolutionTolerance() : int
    {
        return $this->timerResolutionTolerance;
    }

    public function getNextTimeout() : int
    {
        $lastTick = $this->lastTick;
        $this->lastTick = microtime(true);
        $diff = UnitConverter::convertMicrosecondsToMilliseconds($this->lastTick - $lastTick);

        $wait = (int) max(0, $this->timerResolution - $diff);
        if ($wait === 0) {
            $wait = 1;
        }

        return $wait;
    }

    public function mainLoop($mainLoopCallback)
    {
        if (!(is_callable($mainLoopCallback) || $mainLoopCallback instanceof Closure)) {
            throw new TypeError("Invalid callback parameter");
        }

        do {
            call_user_func($mainLoopCallback, $this);

            $wait = $this->getNextTimeout();
            $changed = $this->select($wait);

            if (0 === $changed) {
                $this->checkSelectTimeouts();
                continue;
            }

            $selectorIdsToNotify = [];
            foreach ($this->selectedKeys as $key) {
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
                $callback = $this->selectorCallbacks[$id]['onSelect'];
//                unset($this->observedSelectors[$id]);
//                unset($this->selectorCallbacks[$id]);
//                unset($this->selectorTimeouts[$id]);
                call_user_func($callback, $observedSelector);
            }

            $this->checkSelectTimeouts();
        } while (!$this->isTerminating());
    }

    private function checkSelectTimeouts()
    {
        $now = microtime(true);
        foreach ($this->selectorTimeouts as $id => $timeout) {
            if ($now > $timeout['nextTick']) {
                $callback = $this->selectorCallbacks[$id]['onTimeout'];
                $observedSelector = $this->observedSelectors[$id];
//                unset($this->observedSelectors[$id]);
//                unset($this->selectorCallbacks[$id]);
//                unset($this->selectorTimeouts[$id]);
                call_user_func($callback, $observedSelector);
            }
        }
    }

    public function select(int $timeout = 0) : int
    {
        $selector = $this->getSelector();
        $amount = $selector->select($timeout);
        $this->selectedKeys = $selector->getSelectionKeys();

        return $amount;
    }

    /**
     * @return Selector[]
     */
    public function getObservedSelectors() : array
    {
        return $this->observedSelectors;
    }

    public function getSelector() : AbstractStreamSelector
    {
        $reactor = clone $this->reactor;

        foreach ($this->observedSelectors as $index => $selector) {
            $keys = $selector->getKeys();

            foreach ($keys as $key) {
                try {
                    $key2 = $key->getStream()->register($reactor, $key->getInterestOps());
                } catch (IOException $exception) {
                    continue;
                }
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
     * @param $onSelectCallback
     * @param int $timeout Timeout in milliseconds
     * @throws TypeError
     */
    public function observe(AbstractStreamSelector $selector, $onSelectCallback, $onTimeoutCallback, int $timeout)
    {
        if (!is_callable($onSelectCallback)) {
            throw new TypeError("Invalid callback parameter");
        }

        $timeout /= 1000;
        $nextTick = microtime(true) + $timeout;
        $nextTick += $timeout * ($this->getTimerResolutionTolerance() / 100);
        $this->selectorCallbacks[] = ['onSelect' => $onSelectCallback, 'onTimeout' => $onTimeoutCallback];
        $this->observedSelectors[] = $selector;
        $this->selectorTimeouts[] = ['nextTick' => $nextTick, 'timeout' => $timeout];

        $this->updateTimerResolution();
    }

    /**
     * @param callable $callback
     * @param int $timeout Timeout in milliseconds
     * @param bool $isPeriodic
     * @return mixed Timer ID
     * @throws TypeError
     */
    public function registerTimer($callback, int $timeout, bool $isPeriodic)
    {
        if (!(is_callable($callback) || $callback instanceof Closure)) {
            throw new TypeError("Invalid callback parameter");
        }

        $timeout /= 1000;
        $nextTick = microtime(true) + $timeout;
        $nextTick += $timeout * ($this->getTimerResolutionTolerance() / 100);
        $this->timers[] = ['callback' => $callback, 'nextTick' => $nextTick, 'timeout' => $timeout, 'periodic' => $isPeriodic];

        $this->updateTimerResolution();

        return key($this->timers);
    }

    private function updateTimerResolution()
    {
        $timeouts = [1000];
        foreach (array_merge($this->timers, $this->selectorTimeouts) as $timer) {
            $timeouts[] = $timer['timeout'] * 1000;
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

        unset ($this->timers[$id]);
        $this->updateTimerResolution();
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
        $this->updateTimerResolution();
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
        $result = [];

        foreach ($this->selectedKeys as $key) {
            $attachment = $key->getAttachment();
            $result[] = $attachment['key'];
        }

        return $result;
    }

    /**
     * @param SelectionKey[] $keys
     */
    protected function setSelectionKeys(array $keys)
    {
        throw new UnsupportedOperationException("Cannot set selection keys in Reactor");
    }
}