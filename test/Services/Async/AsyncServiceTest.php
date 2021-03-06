<?php

namespace ZeusTest\Services\Async;

use PHPUnit_Framework_TestCase;
use Zend\Cache\Service\StorageCacheAbstractServiceFactory;
use Zend\Http\Response;
use Zend\ServiceManager\ServiceManager;
use Zeus\ServerService\Async\Service;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use ZeusTest\Helpers\ZeusFactories;

class AsyncServiceTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /**
     * @return Service
     */
    protected function getService()
    {
        /** @var ServiceManager $sm */
        $sm = $this->getServiceManager();
        $sm->setAllowOverride(true);

        $sm->addAbstractFactory(StorageCacheAbstractServiceFactory::class);
        $sm->setFactory(Service::class, AbstractServerServiceFactory::class);
        $scheduler = $this->getScheduler();
        $logger = $scheduler->getLogger();

        $service = $sm->build(Service::class,
            [
                'service_name' => 'zeus-async-test',
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' =>
                [
                    'service_settings' => [
                        'listen_port' => 9999,
                        'listen_address' => '127.0.0.1',
                ]
            ]
        ]);

        return $service;
    }

    public function testServiceCreation()
    {
        $service = $this->getService();
        $service->start();
        $service->stop();
    }
}