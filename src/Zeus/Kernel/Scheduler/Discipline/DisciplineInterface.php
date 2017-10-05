<?php

namespace Zeus\Kernel\Scheduler\Discipline;

use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;

interface DisciplineInterface
{
    /**
     * @param ConfigInterface $config
     * @param WorkerCollection $processes
     * @return \mixed[]
     */
    public function manage(ConfigInterface $config, WorkerCollection $processes) : array;
}