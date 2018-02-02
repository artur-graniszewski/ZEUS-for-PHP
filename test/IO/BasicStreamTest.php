<?php

namespace ZeusTest\IO;

use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\FileStream;

class BasicStreamTest extends AbstractIOTest
{
    /**
     * @expectedExceptionMessage Failed to switch the stream to a non-blocking mode: supplied resource is not a valid stream resource
     * @expectedException \Zeus\IO\Exception\IOException
     */
    public function testFailOnSetBlockingForPHP()
    {
        if (defined("HHVM_VERSION")) {
            $this->markTestSkipped("This is a PHP-only test");
        }
        $stream = new FileStream(fopen(__FILE__, 'r'));
        fclose($stream->getResource());
        $stream->setBlocking(false);
    }

    /**
     * @expectedExceptionMessage Failed to switch the stream to a non-blocking mode: unknown error
     * @expectedException \Zeus\IO\Exception\IOException
     */
    public function testFailOnSetBlockingForHHVM()
    {
        if (!defined("HHVM_VERSION")) {
            $this->markTestSkipped("This is a HHVM-only test");
        }
        $stream = new FileStream(fopen(__FILE__, 'r'));
        fclose($stream->getResource());
        $stream->setBlocking(false);
    }

    public function testClose()
    {
        $stream = new FileStream(fopen(__FILE__, 'rw'));
        $this->assertFalse($stream->isClosed(), 'Stream should be opened');
        $this->assertTrue($stream->isReadable(), 'Stream should be readable when opened');
        $this->assertTrue($stream->isWritable(), 'Stream should be writable when opened');
        $stream->close();
        $this->assertTrue($stream->isClosed(), 'Stream should be closed');
        $this->assertFalse($stream->isReadable(), 'Stream should not be readable when closed');
        $this->assertFalse($stream->isWritable(), 'Stream should not be writable when closed');
    }

    public function testReadOperation()
    {
        $testString = "TEST STRING\0";
        $tmpName = $this->getTmpName("read.txt");
        file_put_contents($tmpName, $testString);
        $stream = new FileStream(fopen($tmpName,'r'));
        $out = $stream->read();
        $stream->close();
        $this->assertEquals($testString, $out, "File contents must match between write and read");
    }

    public function testWriteOperation()
    {
        $testString = "TEST STRING\0";
        $tmpName = $this->getTmpName("write.txt");
        $stream = new FileStream(fopen($tmpName,'w'));
        $stream->write($testString);
        $stream->flush();
        $stream->close();
        $this->assertEquals($testString, file_get_contents($tmpName), "File contents must match between write and read");
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream is not writable
     */
    public function testWriteOnReadOnlyFile()
    {
        $tmpName = $this->getTmpName("read.txt");
        file_put_contents($tmpName, "");
        $stream = new FileStream(fopen($tmpName, 'r'));
        $stream->write("TEST STRING");
        $stream->flush();
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream is not readable
     */
    public function testReadOnWriteOnlyFile()
    {
        $tmpName = $this->getTmpName("read.txt");
        file_put_contents($tmpName, "");
        $stream = new FileStream(fopen($tmpName, 'w'));
        $stream->read();
        $stream->flush();
    }

    public function fileModeProvider() : array
    {
        return [
            ['r+'],
            ['w+'],
            ['c+'],
            ['x+'],
            ['a+'],
        ];
    }

    /**
     * @param string $mode
     * @dataProvider fileModeProvider
     */
    public function testReadOnReadWriteFile(string $mode)
    {
        $testString = "test string $mode";
        $tmpName = $this->getTmpName("read.txt");
        if ($mode !== 'x+') {
            file_put_contents($tmpName, $testString);
        }
        $stream = new FileStream(fopen($tmpName, $mode));
        $out = $stream->read();
        $stream->close();

        if ($mode === 'w+' || $mode === 'x+') {
            $testString = '';
        }
        $this->assertEquals($testString, $out, "File contents must match between read and write");
    }

    /**
     * @expectedException \OutOfRangeException
     * @expectedExceptionMessage Read buffer size must be greater than 0
     */
    public function testIllegalReadBufferSize()
    {
        $stream = new DummySelectableStream(null);
        $stream->setReadBufferSize(-1);
    }

    /**
     * @expectedException \OutOfRangeException
     * @expectedExceptionMessage Write buffer size must be greater than or equal 0
     */
    public function testIllegalWriteBufferSize()
    {
        $stream = new DummySelectableStream(null);
        $stream->setWriteBufferSize(-1);
    }
}