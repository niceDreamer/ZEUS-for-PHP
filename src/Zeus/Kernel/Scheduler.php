<?php

namespace Zeus\Kernel;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\Logger;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\AbstractWorker;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\ProcessInterface;
use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\Scheduler\WorkerEvent;

/**
 * Class Scheduler
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
final class Scheduler extends AbstractWorker implements EventsCapableInterface, ProcessInterface
{
    use PluginRegistry;
    use GarbageCollector;

    /** @var WorkerState[]|WorkerCollection */
    protected $workers = [];

    /** @var bool */
    protected $schedulerActive = true;

    /** @var WorkerState */
    protected $schedulerStatus;

    /** @var AbstractWorker */
    protected $workerService;

    protected $discipline;

    /** @var mixed[] */
    protected $eventHandles;

    /** @var MultiProcessingModuleInterface */
    protected $multiProcessingModule;

    /**
     * @return bool
     */
    public function isSchedulerActive()
    {
        return $this->schedulerActive;
    }

    /**
     * @return AbstractWorker
     */
    public function getWorkerService()
    {
        return $this->workerService;
    }

    /**
     * @param bool $schedulerActive
     * @return $this
     */
    public function setSchedulerActive(bool $schedulerActive)
    {
        $this->schedulerActive = $schedulerActive;

        return $this;
    }

    /**
     * Scheduler constructor.
     * @param ConfigInterface $config
     * @param AbstractWorker $workerService
     * @param DisciplineInterface $discipline
     */
    public function __construct(ConfigInterface $config, AbstractWorker $workerService, DisciplineInterface $discipline)
    {
        $this->discipline = $discipline;
        $this->setConfig($config);
        $this->workerService = $workerService;
        $this->status = new WorkerState($this->getConfig()->getServiceName());
        $this->workers = new WorkerCollection($this->getConfig()->getMaxProcesses());
    }

    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

//        if ($this->eventHandles) {
//            $events = $this->getEventManager();
//            foreach ($this->eventHandles as $handle) {
//                $events->detach($handle);
//            }
//        }
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events = $events->getSharedManager();
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $e) { $this->addNewWorker($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $e) { $this->onWorkerCreate($e);}, SchedulerEvent::PRIORITY_FINALIZE + 1);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATED, function(SchedulerEvent $e) { $this->onWorkerTerminated($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);}, SchedulerEvent::PRIORITY_REGULAR);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function() use ($events) {
            $this->eventHandles[] = $events->attach('*', IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $e) { $this->onWorkerMessage($e);});
            $this->onSchedulerStart();

        }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->collectCycles();
            $this->manageWorkers($this->discipline);
        });

        return $this;
    }

    /**
     * @param IpcEvent $event
     */
    protected function onWorkerMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!is_array($message)) {
            return;

        }
        switch ($message['type']) {
            case Message::IS_STATUS:
                $details = $message['extra'];
                $pid = $details['uid'];
                $threadId = $details['threadId'];

                if ($threadId > 1) {
                    $pid = $threadId;
                }

                /** @var WorkerState $processStatus */
                $processStatus = $message['extra']['status'];

                // process status changed, update this information server-side
                if (isset($this->workers[$pid])) {
                    if ($this->workers[$pid]['code'] !== $processStatus['code']) {
                        $processStatus['time'] = microtime(true);
                    }

                    $this->workers[$pid] = $processStatus;
                }

                break;
        }
    }

    /**
     * @param int $priority
     * @param string $message
     * @param mixed[] $extra
     * @return $this
     */
    protected function log($priority, $message, $extra = []) : Scheduler
    {
        if (!isset($extra['service_name'])) {
            $extra['service_name'] = $this->getConfig()->getServiceName();
        }

        if (!isset($extra['logger'])) {
            $extra['logger'] = __CLASS__;
        }

        $this->getLogger()->log($priority, $message, $extra);

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerTerminated(SchedulerEvent $event)
    {
        $id = $event->getParam('uid');
        $this->log(Logger::DEBUG, "Worker $id exited");

        if (isset($this->workers[$id])) {
            $processStatus = $this->workers[$id];

            if (!WorkerState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(Logger::ERR, "Worker $id exited prematurely");
            }

            unset($this->workers[$id]);
        }
    }

    /**
     * Stops the process manager.
     *
     * @return $this
     */
    public function stop() : Scheduler
    {
        $this->logger->debug("Stopping scheduler");
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        $pid = @file_get_contents($fileName);
        if (!$pid) {
            throw new SchedulerException("Scheduler not running: " . $fileName, SchedulerException::SCHEDULER_NOT_RUNNING);
        }

        $this->setSchedulerActive(false);
        $this->stopWorker($pid, true);

        unlink($fileName);

        return $this;
    }

    /**
     * @return string
     */
    public function getPidFile() : string
    {
        // @todo: make it more sophisticated
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        return $fileName;
    }

    /**
     * @param string $eventName
     * @param mixed[]$extraData
     * @return $this
     */
    protected function triggerEvent(string $eventName, array $extraData = [])
    {
        $extraData = array_merge($this->status->toArray(), $extraData, ['service_name' => $this->getConfig()->getServiceName()]);
        $events = $this->getEventManager();
        $event = new SchedulerEvent();
        $event->setTarget($this);
        $event->setParams($extraData);
        $event->setName($eventName);
        $events->triggerEvent($event);

        return $this;
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     * @return $this
     */
    public function start($launchAsDaemon = false)
    {
        $this->getMultiProcessingModule()->attach($this->getEventManager());
        $plugins = $this->getPluginRegistry()->count();
        $this->log(Logger::INFO, sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->collectCycles();

        $events = $this->getEventManager();
        $this->attach($events);
        $events = $events->getSharedManager();

        try {
            if (!$launchAsDaemon) {
                $this->setProcessId(getmypid());
                $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                $this->kernelLoop();

                return $this;
            }

            $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
                function(SchedulerEvent $e) use ($events) {
                    if (!$e->getParam('server')) {
                        return;
                    }

                    if ($e->getParam('init_process')) {
                        $e->stopPropagation(true);
                        $this->setProcessId(getmypid());
                        $this->log(Logger::INFO, "Establishing IPC");
                        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                    } else {
                        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                    }
                }, SchedulerEvent::PRIORITY_FINALIZE);

            $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
                function (SchedulerEvent $event) use ($events) {
                    if (!$event->getParam('server') || $event->getParam('init_process')) {
                        return;
                    }

                    $this->eventHandles[] = $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) {
                        $e->stopPropagation(true);
                    }, WorkerEvent::PRIORITY_INITIALIZE);

                    $pid = $event->getParam('uid');
                    $this->setProcessId($pid);

                    $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());
                    if (!@file_put_contents($fileName, $pid)) {
                        throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
                    }

                    $this->kernelLoop();
                }
                , SchedulerEvent::PRIORITY_FINALIZE
            );

            $this->workerService->start(['server' => true]);

        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }

        return $this;
    }

    /**
     * @param \Throwable $exception
     * @return $this
     */
    protected function handleException(\Throwable $exception) : Scheduler
    {
        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_STOP, ['exception' => $exception]);

        return $this;
    }

    protected function onSchedulerStart()
    {
        $this->log(Logger::NOTICE, "Scheduler started");
        $this->createWorkers($this->getConfig()->getStartProcesses());

        $this->mainLoop();
    }

    /**
     * @param int $uid
     * @param bool $isSoftStop
     * @return $this
     */
    protected function stopWorker(int $uid, bool $isSoftStop) : Scheduler
    {
        $this->triggerEvent(SchedulerEvent::EVENT_WORKER_TERMINATE, ['uid' => $uid, 'soft' => $isSoftStop]);

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];
            $workerState['code'] = WorkerState::TERMINATED;
            $this->workers[$uid] = $workerState;
        }

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param SchedulerEvent $event
     */
    protected function onShutdown(SchedulerEvent $event)
    {
        if (!$this->isSchedulerActive()) {
            return;
        }

        $exception = $event->getParam('exception', null);

        $this->log(Logger::DEBUG, "Shutting down" . ($exception ? ' with exception: ' . $exception->getMessage() : ''));
        if ($exception) {
            $status = $exception->getCode();
            $this->log(Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        $this->setSchedulerActive(false);

        $this->log(Logger::INFO, "Terminating scheduled workers");

        if ($this->workers) {
            foreach (array_keys($this->workers->toArray()) as $pid) {
                $this->log(Logger::DEBUG, "Terminating worker $pid");
                $this->stopWorker($pid, false);
            }
        }

        $this->log(Logger::NOTICE, "Scheduler terminated");
    }

    /**
     * Create processes
     *
     * @param int $count Number of processes to create.
     * @return $this
     */
    protected function createWorkers(int $count) : Scheduler
    {
        if ($count === 0) {
            return $this;
        }

        for ($i = 0; $i < $count; ++$i) {
            $this->workerService->start();
        }

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerCreate(SchedulerEvent $event)
    {
        if (!$event->getParam('init_process') || $event->getParam('server')) {
            return;
        }

        $process = $this->workerService;
        $process->setProcessId($event->getParam('uid'));
        $process->setThreadId($event->getParam('threadId', 1));
        $this->collectCycles();
        $this->setSchedulerActive(false);
    }

    /**
     * @param SchedulerEvent $event
     * @return $this
     */
    protected function addNewWorker(SchedulerEvent $event) : Scheduler
    {
        if ($event->getParam('server')) {
            return $this;
        }

        $pid = $event->getParam('uid');

        $this->workers[$pid] = [
            'code' => WorkerState::WAITING,
            'uid' => $pid,
            'time' => microtime(true),
            'service_name' => $this->getConfig()->getServiceName(),
            'requests_finished' => 0,
            'requests_per_second' => 0,
            'cpu_usage' => 0,
            'status_description' => '',
        ];

        return $this;
    }

    /**
     * Manages server workers.
     *
     * @param DisciplineInterface $discipline
     * @return $this
     */
    protected function manageWorkers(DisciplineInterface $discipline) : Scheduler
    {
        if (!$this->isSchedulerActive()) {
            return $this;
        }

        $operations = $discipline->manage($this->getConfig(), clone $this->workers);

        $toTerminate = $operations['terminate'];
        $toSoftTerminate = $operations['soft_terminate'];
        $toCreate = $operations['create'];

        $this->createWorkers($toCreate);
        $this->stopWorkers($toTerminate, false);
        $this->stopWorkers($toSoftTerminate, true);

        return $this;
    }

    /**
     * @param int[] $processIds
     * @param $isSoftTermination
     * @return $this
     */
    protected function stopWorkers(array $processIds, bool $isSoftTermination) : Scheduler
    {
        $now = microtime(true);

        foreach ($processIds as $processId) {
            $processStatus = $this->workers[$processId];
            $processStatus['code'] = WorkerState::TERMINATED;
            $processStatus['time'] = $now;
            $this->workers[$processId] = $processStatus;

            $this->log(Logger::DEBUG, sprintf('Terminating worker %d', $processId));
            $this->stopWorker($processId, $isSoftTermination);
        }

        return $this;
    }

    /**
     * Creates main (infinite) loop.
     *
     * @return $this
     */
    protected function mainLoop() : Scheduler
    {
        do {
            $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        } while ($this->isSchedulerActive());

        return $this;
    }

    /**
     * @return $this
     */
    public function kernelLoop() : Scheduler
    {
        while ($this->isSchedulerActive()) {
            $time = microtime(true);
            $this->triggerEvent(SchedulerEvent::EVENT_KERNEL_LOOP);
            $diff = microtime(true) - $time;

            if ($diff < 0.1) {
                $diff = 1 - $diff;
                // wait for 0.1 sec
                usleep($diff * 100000);
            }
        }

        return $this;
    }

    /**
     * @return WorkerCollection|WorkerState[]
     */
    public function getWorkers() : WorkerCollection
    {
        return $this->workers;
    }

    /**
     * @param MultiProcessingModuleInterface $driver
     * @return $this
     */
    public function setMultiProcessingModule(MultiProcessingModuleInterface $driver) : Scheduler
    {
        $this->multiProcessingModule = $driver;

        return $this;
    }

    /**
     * @return MultiProcessingModuleInterface
     */
    public function getMultiProcessingModule() : MultiProcessingModuleInterface
    {
        return $this->multiProcessingModule;
    }
}