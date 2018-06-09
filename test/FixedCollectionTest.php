<?php

namespace ZeusTest;

use \PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler\Shared\FixedCollection;

/**
 * Class FixedCollectionTest
 * @package ZeusTest
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class FixedCollectionTest extends \PHPUnit\Framework\TestCase
{
    protected function getDefaultCollection()
    {
        $collection = new FixedCollection(10);

        foreach (range(1, 10) as $value) {
            $collection[$value] = $value;
        }

        return $collection;
    }
    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Array slots exhausted
     */
    public function testCollectionOverflow()
    {
        $collection = new FixedCollection(10);

        foreach (range(0, 11) as $value) {
            $collection[$value] = $value;
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Array slots exhausted
     */
    public function testCollectionIndexOverflow()
    {
        $collection = new FixedCollection(10);

        foreach (range(0, 11) as $value) {
            $collection[$value + 1] = $value;
        }
    }

    public function testCollectionSetup()
    {
        $collection = $this->getDefaultCollection();

        $this->assertEquals(10, $collection->count());
    }

    public function testCollectionIterator()
    {
        $collection = new FixedCollection(10);
        $regularArray = [];

        foreach (range(0, 9) as $value) {
            $collection[$value] = $value;
            $regularArray[$value] = $value;
        }

        foreach ($collection as $key => $value) {
            $this->assertEquals($regularArray[$key], $value);
        }
    }

    public function testItemUnset()
    {
        $collection = $this->getDefaultCollection();

        $this->assertEquals($collection->count(), $collection->getSize());
        
        unset($collection[1]);

        $this->assertEquals($collection->getSize() - 1, $collection->count());

        $foundItems = 0;
        $regularArray = [];
        foreach ($collection as $key => $value) {
            $foundItems++;
            $regularArray[$key] = $value;
        }

        $this->assertEquals($collection->getSize() - 1, $foundItems);
        $this->assertFalse(isset($regularArray[1]));
        $this->assertFalse(isset($collection[1]));
    }

    public function testItemOffset()
    {
        $collection = new FixedCollection(2);

        $collection[1] = 1;
        $collection[2] = 4;

        $this->assertEquals(1, $collection[1]);
        $this->assertEquals(4, $collection[2]);
    }

    public function testItemOffsetFromZero()
    {
        $collection = new FixedCollection(2);

        $collection[0] = 1;
        $collection[2] = 4;

        $this->assertEquals(1, $collection[0]);
        $this->assertEquals(4, $collection[2]);
    }

    public function testAccessToUnsetIndex()
    {
        $collection = new FixedCollection(2);

        $result = $collection[100];
        $this->assertEquals(null, $result);
    }

    public function testIteratorOnEmptyCollection()
    {
        $collection = new FixedCollection(10);

        $foundItems = 0;
        $regularArray = [];

        foreach ($collection as $key => $value) {
            $foundItems++;
            $regularArray[$key] = $value;
        }

        $this->assertEquals(0, $foundItems);
    }
}