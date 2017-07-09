<?php

namespace ZeusTest\Kernel\Networking;

use Zeus\Kernel\Networking\SocketServer;
use Zeus\Kernel\Networking\Stream\FileStream;
use Zeus\Kernel\Networking\Stream\Selector;
use Zeus\Kernel\Networking\Stream\SocketStream;

class SelectorTest extends AbstractNetworkingTest
{
    const MIN_TEST_PORT = 7777;
    const MAX_TEST_PORT = 7787;

    public function testMultiSelectOnAcceptedSockets()
    {
        $readSelector = new Selector();
        $writeSelector = new Selector();
        $fullSelector = new Selector();
        $servers = [];
        $streams = [];

        foreach (range(self::MIN_TEST_PORT, self::MAX_TEST_PORT) as $port) {
            /** @var SocketServer[] $servers */
            $servers[$port] = $this->addServer($port);
            $this->addClient($port);

            $stream = $servers[$port]->accept(1);
            $streams[] = $stream;
            $readSelector->register($stream, Selector::OP_READ);
            $writeSelector->register($stream, Selector::OP_WRITE);
            $fullSelector->register($stream, Selector::OP_ALL);
        }

        $amountToRead = $readSelector->select();
        $amountToWrite = $writeSelector->select();
        $amountChanged = $fullSelector->select();

        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertEquals(count($servers), $amountToWrite, "All streams should have been writable");
        $this->assertEquals(count($servers), $amountChanged, "All streams should have been selected");
        $this->assertArraySubset($streams, $fullSelector->getSelectedKeys(), "All Stream objects should be returned by Selector");
        $this->assertArraySubset($streams, $writeSelector->getSelectedKeys(), "All Stream objects should be returned by Selector");
    }

    public function testMultiSelectOnReadableSockets()
    {
        $readSelector = new Selector();
        $fullSelector = new Selector();
        $servers = [];
        $streams = [];

        foreach (range(self::MIN_TEST_PORT, self::MAX_TEST_PORT) as $port) {
            /** @var SocketServer[] $servers */
            $servers[$port] = $this->addServer($port);
            $client = $this->addClient($port);
            fputs($client, "TEST");

            $stream = $servers[$port]->accept(1);
            $streams[] = $stream;
            $readSelector->register($stream, Selector::OP_READ);
            $fullSelector->register($stream, Selector::OP_ALL);
        }

        $amountToRead = $readSelector->select();
        $amountChanged = $fullSelector->select();

        $this->assertEquals(count($servers), $amountToRead, "All streams should have been readable");
        $this->assertEquals(count($servers), $amountChanged, "All streams should have been selected");
        $this->assertArraySubset($streams, $fullSelector->getSelectedKeys(), "All Stream objects should be returned by Selector");
        $this->assertArraySubset($streams, $readSelector->getSelectedKeys(), "All Stream objects should be returned by Selector");
    }

    public function testSelectorTimeout()
    {
        $selector = new Selector();
        $server = $this->addServer(self::MIN_TEST_PORT);
        $this->addClient(self::MIN_TEST_PORT);
        $stream = $server->accept(1);
        $selector->register($stream, Selector::OP_READ);

        $now = time();
        $amountToRead = $selector->select(2000000);
        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertTrue(time() <= $now + 2, "Select method should have waited at least two seconds");
        $this->assertEmpty($selector->getSelectedKeys(), "No streams should be returned");
    }

    public function testSelectorOnClosedStream()
    {
        $selector = new Selector();
        $server = $this->addServer(self::MIN_TEST_PORT);
        $this->addClient(self::MIN_TEST_PORT);
        $stream = $server->accept(1);
        $selector->register($stream, Selector::OP_READ);
        $stream->close();

        $this->assertTrue($stream->isClosed(), "Stream should be closed");

        $amountToRead = $selector->select();
        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertEmpty($selector->getSelectedKeys(), "No streams should be returned");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Invalid operation type: false
     */
    public function testOperationArgumentValidation()
    {
        $selector = new Selector();
        $stream = new SocketStream(fopen(__FILE__, "r"));
        $selector->register($stream, false);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Stream class must implement Zeus\Kernel\Networking\Stream\SelectableStreamInterface
     */
    public function testStreamArgumentValidation()
    {
        $selector = new Selector();
        $stream = new FileStream(fopen(__FILE__, "r"));
        $selector->register($stream, false);
    }
}