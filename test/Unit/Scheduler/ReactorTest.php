<?php

namespace ZeusTest\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use Zeus\IO\Stream\PipeStream;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\Kernel\Scheduler\Reactor;

/**
 * Class ReactorTest
 * @package ZeusTest\Kernel\Scheduler
 */
class ReactorTest extends TestCase
{
    /**
     * @expectedException \TypeError
     * @expectedExceptionMessage Invalid callback parameter
     */
    public function testInvalidCallbackForTimer()
    {
        $reactor = new Reactor();
        $reactor->registerTimer("abcdefghijk", 10, true);
    }

    public function testTimerRegistration()
    {
        $reactor = new Reactor();
        $id = $reactor->registerTimer(function() {
            return true;
        }, 10, false);

        $this->assertNotNull($id, 'Timer ID should not be empty');
        $reactor->unregisterTimer($id);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot unregister: unknown timer
     */
    public function testInvalidTimerUnregistration()
    {
        $reactor = new Reactor();
        $id = $reactor->registerTimer(function() {
            return true;
        }, 10, false);

        $reactor->unregisterTimer($id + 100);
    }

    public function testSelectorRegistration()
    {
        $isStdOutWritable = false;
        $isStdErrWritable = false;
        $isStdInReadable = false;

        $reactor = new Reactor();
        $stdInSelector = new Selector();
        $stdOutSelector = new Selector();
        $stdErrSelector = new Selector();

        $stdIn = new PipeStream(fopen("php://stdin", "r"));
        $stdInKey = $stdIn->register($stdInSelector, SelectionKey::OP_READ);
        $stdOut = new PipeStream(fopen("php://stdout", "w"));
        $stdOutKey = $stdOut->register($stdOutSelector, SelectionKey::OP_WRITE);
        $stdErr = new PipeStream(fopen("php://stderr", "w"));
        $stdErrKey = $stdErr->register($stdErrSelector, SelectionKey::OP_WRITE);

        $reactor->observe($stdOutSelector, function() use (& $isStdOutWritable) {
            $isStdOutWritable = true;
        }, function() {}, 1000);

        $reactor->mainLoop(function(Reactor $reactor) {
            $reactor->setTerminating(true);
        });

        $isStdOutWritable1 = $isStdOutWritable;
        $keys1 = $reactor->getSelectionKeys();

        $reactor->observe($stdErrSelector, function() use (& $isStdErrWritable) {
            $isStdErrWritable = true;
        }, function() {}, 1000);

        $reactor->observe($stdInSelector, function() use (& $isStdInReadable) {
            $isStdInReadable = true;
        }, function() {}, 1000);

        $reactor->mainLoop(function(Reactor $reactor) {
            $reactor->setTerminating(true);
        });

        $keys2 = $reactor->getSelectionKeys();
        $allKeys1 = $reactor->getKeys();

        $reactor->unregister($stdOutSelector);
        $allKeys2 = $reactor->getKeys();

        $stdOut->close();
        $stdErr->close();
        $stdIn->close();

        $this->assertTrue($isStdOutWritable1, "stdout should be writable");
        $this->assertTrue($isStdErrWritable, "stderr should be writable");

        if (!defined("HHVM_VERSION")) {
            // it seems that @runTestsInSeparateProcesses makes stdin readable in HHVM
            $this->assertFalse($isStdInReadable, "stdin should not be readable");
            $this->assertEquals($this->getObjectHashes([$stdOutKey, $stdErrKey]), $this->getObjectHashes($keys2), "Both stdout and stderr selection keys should be returned in second test iteration");
        } else {
            $this->assertEquals($this->getObjectHashes([$stdInKey, $stdOutKey, $stdErrKey]), $this->getObjectHashes($keys2), "Stdin, stdout and stderr selection keys should be returned in second test iteration");
        }
        $this->assertEquals($this->getObjectHashes([$stdOutKey]), $this->getObjectHashes($keys1), "Only stdout selection key should be returned in first test iteration");

        $this->assertEquals($this->getObjectHashes([$stdOutKey, $stdErrKey, $stdInKey]), $this->getObjectHashes($allKeys1), "All registered selection keys should be returned in second test iteration");
        $this->assertEquals($this->getObjectHashes([$stdErrKey, $stdInKey]), $this->getObjectHashes($allKeys2), "All registered selection keys should be returned in third test iteration");
    }

    private function getObjectHashes(array $objects) : array
    {
        $hashes = [];
        foreach ($objects as $object) {
            $hashes[] = spl_object_hash($object);
        }

        return $hashes;
    }

    public function testSetSelector()
    {
        $reactor = new Reactor();
        $selector = new Selector();
        $stdIn = new PipeStream(fopen("php://stdin", "r"));
        $stdInKey = $stdIn->register($selector, SelectionKey::OP_READ);

        $reactor->setSelector($selector);
        $reactorSelector = $reactor->getSelector();
        $keys = $reactorSelector->getKeys();
        $reactorHash = spl_object_hash($reactorSelector);
        $stdIn->close();

        $this->assertEquals([$stdInKey], $keys, "All originally registered keys should be present in Reactor selector");
        $this->assertNotEquals(spl_object_hash($selector), $reactorHash, "Reactor should operate on cloned selector");
    }

    public function testUnregisterSelector()
    {
        $isSelected = $isLoopExecuted = $isTimedOut = false;
        $reactor = new Reactor();
        $reactorSelector = new Selector();
        $anotherSelector = new Selector();
        $stdIn = new PipeStream(fopen("php://stdin", "r"));
        $key = $stdIn->register($anotherSelector, SelectionKey::OP_READ);

        $reactor->setSelector($reactorSelector);
        $reactor->observe($anotherSelector, function() use (& $isSelected) {
            $isSelected = true;
        }, function() use (& $isTimedOut, $reactor) {
            $isTimedOut = true;
            $reactor->setTerminating(true);
        }, 100);

        $reactor->mainLoop(function() use (& $isLoopExecuted) {
            $isLoopExecuted = true;
        });

        $selectorsBefore = $reactor->getObservedSelectors();
        $keysBefore = $reactor->getKeys();
        $reactor->unregister($anotherSelector);
        $selectorsAfter = $reactor->getObservedSelectors();
        $keysAfter = $reactor->getKeys();

        $this->assertTrue($isTimedOut);
        $this->assertTrue($isLoopExecuted);
        $this->assertFalse($isSelected);

        $this->assertEquals(1, count($selectorsBefore));
        $this->assertEquals(1, count($keysBefore));
        $this->assertEquals($keysBefore[0], $key);
        $this->assertEquals(0, count($selectorsAfter));
        $this->assertEquals(0, count($keysAfter));
    }

    /**
     * @expectedException \TypeError
     * @expectedExceptionMessage Invalid callback parameter
     */
    public function testInvalidSelectorCallback()
    {
        $reactor = new Reactor();
        $reactor->observe(new Selector(), 'zzzzzzzzzzzzzzz', function() {}, 100);
    }

    public function getInvalidPercentage()
    {
        return [
            [-100],
            [-1],
            [-2],
            [21],
            [22],
            [23],
            [100],
        ];
    }

    public function getValidPercentage()
    {
        return array_map(function($value) {
            return [$value];
        }, range(0, 20));
    }
    /**
     * @expectedException \OutOfRangeException
     * @expectedExceptionMessage Tolerance should be in range of 0-20%
     * @dataProvider getInvalidPercentage
     * @param int $percentage
     */
    public function testInvalidTimerResolution(int $percentage)
    {
        $reactor = new Reactor();
        $reactor->setTimerResolutionTolerance($percentage);
    }

    /**
     * @param int $percentage
     * @dataProvider getValidPercentage
     */
    public function testValidTimerResolution(int $percentage)
    {
        $reactor = new Reactor();
        $reactor->setTimerResolutionTolerance($percentage);
        $this->assertEquals($percentage, $reactor->getTimerResolutionTolerance());
    }
}