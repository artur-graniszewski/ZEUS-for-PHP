<?php

namespace ZeusTest\Unit\IO;

use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;

class SelectionKeyTest extends AbstractIOTest
{
    public function opsProvider()
    {
        return [
            ['Readable', SelectionKey::OP_READ],
            ['Writable', SelectionKey::OP_WRITE],
            ['Acceptable', SelectionKey::OP_ACCEPT],
        ];
    }
    /**
     * @dataProvider opsProvider
     */
    public function testGettersSetters(string $operationName, int $operationFlag)
    {
        $selector = new Selector();
        $key = new SelectionKey(new DummySelectableStream(null), $selector);

        $getterName = 'is' . $operationName;
        $setterName = 'set' . $operationName;
        $operationType = strtolower($operationName);

        $this->assertFalse($key->$getterName(), "By default key should not be " . $operationType);
        $this->assertEquals(0,$key->getReadyOps() & $operationFlag, "By default key should not be " . $operationType);
        $key->$setterName(true);
        $this->assertTrue($key->$getterName(), "Key should be " . $operationType);
        $this->assertEquals($operationFlag,$key->getReadyOps() & $operationFlag, "Key should be " . $operationType);
        $key->$setterName(false);
        $this->assertFalse($key->$getterName(), "Key should not be " . $operationType);
        $this->assertEquals(0,$key->getReadyOps() & $operationFlag, "Key should not be " . $operationType);
    }
}