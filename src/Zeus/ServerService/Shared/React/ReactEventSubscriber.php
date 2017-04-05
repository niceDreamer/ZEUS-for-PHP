<?php

namespace Zeus\ServerService\Shared\React;

use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

/**
 * Class ReactEventSubscriber
 * @package Zeus\ServerService\Shared\React
 * @internal
 */
final class ReactEventSubscriber
{
    /** @var LoopInterface */
    protected $loop;

    /** @var SocketServer */
    protected $socket;

    /** @var int */
    protected $lastTickTime = 0;

    /** @var int */
    protected $tickInterval = 1;

    /**
     * ReactEventSubscriber constructor.
     * @param LoopInterface $loop
     * @param IoServerInterface $server
     * @param int $tickInterval
     */
    public function __construct(LoopInterface $loop, IoServerInterface $server, $tickInterval = 1)
    {
        $this->loop = $loop;
        $this->socket = $server->getServer();
        $this->tickInterval = $tickInterval;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onTaskStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onTaskLoop']);

        return $this;
    }

    /**
     * @return $this
     */
    public function onSchedulerStart()
    {
        $this->loop->removeStream($this->socket->master);

        return $this;
    }

    /**
     * @return $this
     */
    public function onTaskStart()
    {
        return $this;
    }

    /**
     * @param SchedulerEvent $event
     * @return $this
     */
    public function onTaskLoop(SchedulerEvent $event)
    {
        if (($connectionSocket = @stream_socket_accept($this->socket->master, 1))) {
            $event->getProcess()->setRunning();
            $timer = $this->loop->addPeriodicTimer($this->tickInterval, [$this, 'heartBeat']);

            $this->socket->handleConnection($connectionSocket);
            $this->loop->run();
            $this->loop->cancelTimer($timer);
            $event->getProcess()->setWaiting();
        }

        $this->heartBeat();

        return $this;
    }

    /**
     * @return $this
     */
    public function heartBeat()
    {
        $now = time();
        if ($this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            $this->socket->emit('heartBeat', []);
        }

        return $this;
    }
}