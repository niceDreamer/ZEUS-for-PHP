<?php

namespace Zeus\Kernel\ProcessManager;
use Zend\EventManager\Event;

/**
 * @package Zeus\Kernel\ProcessManager
 */
class SchedulerEvent extends Event
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_PROCESS_CREATE = 'processCreate';
    const EVENT_PROCESS_CREATED = 'processCreated';

    const EVENT_PROCESS_TERMINATED = 'processTerminated';
    const EVENT_PROCESS_TERMINATE = 'processTerminate';

    const EVENT_SCHEDULER_START = 'schedulerStart';
    const EVENT_SCHEDULER_STOP = 'schedulerStop';
    const EVENT_SCHEDULER_LOOP = 'schedulerLoop';
    const EVENT_KERNEL_LOOP = 'serverLoop';

    // WARNING: the following INTERNAL_* events should not be used in custom projects
    // and if used - are subjects to change and BC breaks.
    const INTERNAL_EVENT_KERNEL_START = 'serverStart';
    const INTERNAL_EVENT_KERNEL_STOP = 'serverStop';

    /**
     * @return Scheduler
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}