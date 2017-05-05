<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\Helper\Logger as LoggerHelper;
use Zeus\Kernel\ProcessManager\Helper\PluginRegistry;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Helper\EventManager;

/**
 * Class Scheduler
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
final class Scheduler implements EventsCapableInterface
{
    use LoggerHelper;
    use EventManager;
    use PluginRegistry;

    /** @var ProcessState[]|ProcessCollection */
    protected $processes = [];

    /** @var Config */
    protected $config;

    /** @var bool */
    protected $continueMainLoop = true;

    /** @var int */
    protected $schedulerId;

    /** @var ProcessState */
    protected $schedulerStatus;

    /** @var Process */
    protected $processService;

    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    /** @var float */
    protected $startTime;

    protected $discipline;

    /** @var SchedulerEvent */
    private $event;

    /** @var mixed[] */
    protected $eventHandles;

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->schedulerId;
    }

    /**
     * @param int $schedulerId
     * @return $this
     */
    public function setId($schedulerId)
    {
        $this->schedulerId = $schedulerId;

        return $this;
    }

    /**
     * @return bool
     */
    public function isContinueMainLoop()
    {
        return $this->continueMainLoop;
    }

    /**
     * @param bool $continueMainLoop
     * @return $this
     */
    public function setContinueMainLoop($continueMainLoop)
    {
        $this->continueMainLoop = $continueMainLoop;

        return $this;
    }

    /**
     * Scheduler constructor.
     * @param mixed[] $config
     * @param Process $processService
     * @param LoggerInterface $logger
     * @param IpcAdapterInterface $ipcAdapter
     * @param DisciplineInterface $discipline
     */
    public function __construct($config, Process $processService, LoggerInterface $logger, IpcAdapterInterface $ipcAdapter, DisciplineInterface $discipline)
    {
        $this->discipline = $discipline;
        $this->config = new Config($config);
        $this->ipcAdapter = $ipcAdapter;
        $this->processService = $processService;
        $this->schedulerStatus = new ProcessState($this->config->getServiceName());
        $this->setLogger($logger);

        $this->processes = new ProcessCollection($this->config->getMaxProcesses());
        $this->setLoggerExtraDetails(['service' => $this->config->getServiceName()]);

        $this->event = new SchedulerEvent();
        $this->event->setScheduler($this);
        $this->processService->attach($this->getEventManager());
    }

    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

        if ($this->eventHandles) {
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    /**
     * @return IpcAdapterInterface
     */
    public function getIpcAdapter()
    {
        return $this->ipcAdapter;
    }

    /**
     * @return $this
     */
    protected function attach()
    {
        $events = $this->getEventManager();

        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_CREATED, function(SchedulerEvent $e) { $this->addNewProcess($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $e) { $this->onProcessInit($e);}, SchedulerEvent::PRIORITY_REGULAR);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATED, function(SchedulerEvent $e) { $this->onProcessTerminated($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(SchedulerEvent $e) { $this->onProcessExit($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_MESSAGE, function(SchedulerEvent $e) { $this->onProcessMessage($e);});
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);}, SchedulerEvent::PRIORITY_REGULAR);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onProcessExit($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart'], SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->collectCycles();
            $this->handleMessages();
            $this->manageProcesses($this->discipline);
        });

        return $this;
    }

    /**
     * @param EventInterface $event
     */
    protected function onProcessMessage(EventInterface $event)
    {
        $this->ipcAdapter->send($event->getParams());
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessExit(SchedulerEvent $event)
    {
        /** @var \Exception $exception */
        $exception = $event->getParam('exception');

        $status = $exception ? $exception->getCode(): 0;
        exit($status);
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessTerminated(SchedulerEvent $event)
    {
        $pid = $event->getParam('uid');
        $this->log(Logger::DEBUG, "Process $pid exited");

        if (isset($this->processes[$pid])) {
            $processStatus = $this->processes[$pid];

            if (!ProcessState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(Logger::ERR, "Process $pid exited prematurely");
            }

            unset($this->processes[$pid]);
        }
    }

    /**
     * Stops the process manager.
     *
     * @return $this
     */
    public function stop()
    {
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        if ($pid = @file_get_contents($fileName)) {
            $pid = (int)$pid;

            if ($pid) {
                $this->stopProcess($pid, true);
                $this->log(Logger::INFO, "Server stopped");
                unlink($fileName);

                return $this;
            }
        }

        throw new ProcessManagerException("Server not running", ProcessManagerException::SERVER_NOT_RUNNING);
    }

    /**
     * @return mixed[]
     */
    public function getStatus()
    {
        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS_REQUEST,
            'priority' => '',
            'message' => 'fetchStatus',
            'extra' => [
                'uid' => $this->getId(),
                'logger' => __CLASS__
            ]
        ];

        if (!$this->ipcAdapter->isConnected()) {
            $this->ipcAdapter->connect();
        }
        $this->ipcAdapter->useChannelNumber(1);
        $this->ipcAdapter->send($payload);

        $timeout = 5;
        $result = null;
        do {
            $result = $this->ipcAdapter->receive();
            usleep(1000);
            $timeout--;
        } while (!$result && $timeout >= 0);

        $this->ipcAdapter->useChannelNumber(0);

        if ($result) {
            return $result['extra'];
        }

        return null;
    }

    /**
     * @param string $eventName
     * @param mixed[]$extraData
     * @return $this
     */
    protected function triggerEvent($eventName, $extraData = [])
    {
        $extraData = array_merge($this->schedulerStatus->toArray(), $extraData, ['service_name' => $this->config->getServiceName()]);
        $events = $this->getEventManager();
        $event = $this->event;
        $event->setParams($extraData);
        $event->setName($eventName);
        $event->stopPropagation(false);
        $events->triggerEvent($event);

        return $this;
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     * @return $this
     */
    public function start($launchAsDaemon)
    {
        $this->startTime = microtime(true);
        $plugins = $this->getPluginRegistry()->count();
        $this->log(Logger::INFO, sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->collectCycles();

        $events = $this->getEventManager();
        $this->attach();
        $this->log(Logger::INFO, "Establishing IPC");
        if (!$this->ipcAdapter->isConnected()) {
            $this->ipcAdapter->connect();
        }

        try {
            if (!$launchAsDaemon) {
                $this->setId(getmypid());
                $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);

                return $this;
            }

            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $e) {
                if ($e->getParam('server')) {
                    $e->stopPropagation(true);
                    $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                }
            }, 10000);

            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
                function (SchedulerEvent $event) {
                    $pid = $event->getParam('uid');
                    $this->setId($pid);

                    if (!$event->getParam('server')) {
                        return;
                    }

                    if (!@file_put_contents(sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->config->getServiceName()), $pid)) {
                        throw new ProcessManagerException("Could not write to PID file, aborting", ProcessManagerException::LOCK_FILE_ERROR);
                    }

                    $event->stopPropagation(true);
                }
                , -8000
            );

            $this->triggerEvent(SchedulerEvent::EVENT_PROCESS_CREATE, ['server' => true]);
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        } catch (\Throwable $exception) {
            $this->handleException($exception);
        } catch (\Exception $exception) {
            $this->handleException($exception);
        }

        return $this;
    }

    /**
     * @param \Throwable|\Exception $exception
     * @return $this
     */
    private function handleException($exception)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_STOP, ['exception' => $exception]);

        return $this;
    }

    /**
     * @return $this
     */
    public function onSchedulerStart()
    {
        $this->log(Logger::INFO, "Scheduler started");
        $this->createProcesses($this->getConfig()->getStartProcesses());

        return $this->mainLoop();
    }

    /**
     * @return $this
     */
    protected function collectCycles()
    {
        $enabled = gc_enabled();
        gc_enable();
        if (function_exists('gc_mem_caches')) {
            // @codeCoverageIgnoreStart
            gc_mem_caches();
            // @codeCoverageIgnoreEnd
        }
        gc_collect_cycles();


        if (!$enabled) {
            // @codeCoverageIgnoreStart
            gc_disable();
            // @codeCoverageIgnoreEnd
        }

        return $this;
    }

    /**
     * @param int $uid
     * @param bool $isSoftStop
     * @return $this
     */
    protected function stopProcess($uid, $isSoftStop)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_PROCESS_TERMINATE, ['uid' => $uid, 'soft' => $isSoftStop]);

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param EventInterface $event
     * @return $this
     */
    protected function onShutdown(EventInterface $event)
    {
        $this->log(Logger::DEBUG, "Shutting down");
        $exception = $event->getParam('exception', null);

        $this->setContinueMainLoop(false);

        $this->log(Logger::INFO, "Terminating scheduler");

        foreach (array_keys($this->processes->toArray()) as $pid) {
            $this->log(Logger::DEBUG, "Terminating process $pid");
            $this->stopProcess($pid, false);
        }

        $this->handleMessages();

        if ($exception) {
            $status = $exception->getCode();
            $this->log(Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        $this->log(Logger::INFO, "Scheduler terminated");

        $this->log(Logger::INFO, "Stopping IPC");
        $this->ipcAdapter->disconnect();
    }

    /**
     * Create processes
     *
     * @param int $count Number of processes to create.
     * @return $this
     */
    protected function createProcesses($count)
    {
        if ($count === 0) {
            return $this;
        }

        for ($i = 0; $i < $count; ++$i) {
            $this->triggerEvent(SchedulerEvent::EVENT_PROCESS_CREATE);
        }

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessInit(SchedulerEvent $event)
    {
        unset($this->processes);
        $this->collectCycles();
        $this->setContinueMainLoop(false);
        $this->ipcAdapter->useChannelNumber(1);

        $event->setProcess($this->processService);
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function addNewProcess(SchedulerEvent $event)
    {
        $pid = $event->getParam('uid');

        $this->processes[$pid] = [
            'code' => ProcessState::WAITING,
            'uid' => $pid,
            'time' => microtime(true),
            'service_name' => $this->config->getServiceName(),
            'requests_finished' => 0,
            'requests_per_second' => 0,
            'cpu_usage' => 0,
            'status_description' => '',
        ];
    }

    /**
     * Manages server processes.
     *
     * @param DisciplineInterface $discipline
     * @return $this
     */
    protected function manageProcesses(DisciplineInterface $discipline)
    {
        $operations = $discipline->manage($this->config, $this->processes);

        $toTerminate = $operations['terminate'];
        $toSoftTerminate = $operations['soft_terminate'];
        $toCreate = $operations['create'];

        $this->createProcesses($toCreate);
        $this->terminateProcesses($toTerminate, false);
        $this->terminateProcesses($toSoftTerminate, true);

        return $this;
    }

    /**
     * @param int[] $processIds
     * @param $isSoftTermination
     * @return $this
     */
    protected function terminateProcesses(array $processIds, $isSoftTermination)
    {
        $now = microtime(true);

        foreach ($processIds as $processId) {
            $processStatus = $this->processes[$processId];
            $processStatus['code'] = ProcessState::TERMINATED;
            $processStatus['time'] = $now;
            $this->processes[$processId] = $processStatus;

            $this->log(Logger::DEBUG, sprintf('Terminating process %d', $processId));
            $this->stopProcess($processId, $isSoftTermination);
        }

        return $this;
    }

    /**
     * Creates main (infinite) loop.
     *
     * @return $this
     */
    protected function mainLoop()
    {
        while ($this->isContinueMainLoop()) {
            $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        }

        return $this;
    }

    /**
     * Handles messages.
     *
     * @return $this
     */
    protected function handleMessages()
    {
        $this->schedulerStatus->updateStatus();

        /** @var Message[] $messages */
        $this->ipcAdapter->useChannelNumber(0);

        $messages = $this->ipcAdapter->receiveAll();
        $time = microtime(true);

        foreach ($messages as $message) {
            switch ($message['type']) {
                case Message::IS_STATUS:
                    $details = $message['extra'];
                    $pid = $details['uid'];

                    /** @var ProcessState $processStatus */
                    $processStatus = $message['extra']['status'];
                    $processStatus['time'] = $time;

                    if ($processStatus['code'] === ProcessState::RUNNING) {
                        $this->schedulerStatus->incrementNumberOfFinishedTasks();
                    }

                    // child status changed, update this information server-side
                    if (isset($this->processes[$pid])) {
                        $this->processes[$pid] = $processStatus;
                    }

                    break;

                case Message::IS_STATUS_REQUEST:
                    $this->logger->debug('Status request detected');
                    $this->sendSchedulerStatus($this->ipcAdapter);
                    break;

                default:
                    $this->logMessage($message);
                    break;
            }
        }

        return $this;
    }

    private function sendSchedulerStatus(IpcAdapterInterface $ipcAdapter)
    {
        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS,
            'priority' => '',
            'message' => 'statusSent',
            'extra' => [
                'uid' => $this->getId(),
                'logger' => __CLASS__,
                'process_status' => $this->processes->toArray(),
                'scheduler_status' => $this->schedulerStatus->toArray(),
            ]
        ];

        $payload['extra']['scheduler_status']['total_traffic'] = 0;
        $payload['extra']['scheduler_status']['start_timestamp'] = $this->startTime;

        $ipcAdapter->send($payload);
    }
}