<?php

namespace Zeus;

use Zend\Console\Adapter\AdapterInterface as ConsoleAdapter;
use Zend\Console\ColorInterface;
use Zend\Console\Console;
use Zend\EventManager\EventInterface;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\BootstrapListenerInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ConsoleBannerProviderInterface;
use Zend\ModuleManager\Feature\ConsoleUsageProviderInterface;
use Zend\ModuleManager\ModuleEvent;
use Zend\ModuleManager\ModuleManager;
use Zend\Stdlib\ArrayUtils;

class Module implements
    AutoloaderProviderInterface,
    BootstrapListenerInterface,
    ConfigProviderInterface,
    ConsoleUsageProviderInterface,
    ConsoleBannerProviderInterface
{
    const MODULE_VERSION = "1.1.0";

    protected static $overrideConfig = '';

    public static function setOverrideConfig($overrideConfig)
    {
        static::$overrideConfig = $overrideConfig;
    }

    public static function getOverrideConfig()
    {
        return static::$overrideConfig;
    }

    /**
     * Listen to the bootstrap event
     *
     * @param EventInterface $e
     * @return array
     */
    public function onBootstrap(EventInterface $e)
    {

    }

    /**
     * Get config
     *
     * @return mixed[]
     */
    public function getConfig()
    {
        return include __DIR__ . '/../../config/module.config.php';
    }

    /**
     * Return an array for passing to Zend\Loader\AutoloaderFactory.
     *
     * @return mixed[]
     */
    public function getAutoloaderConfig()
    {
        return [
            'Zend\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__,
                ],
            ],
        ];
    }

    /**
     * Get console usage
     *
     * @param ConsoleAdapter $adapter
     * @return mixed[]
     */
    public function getConsoleUsage(ConsoleAdapter $adapter)
    {
        $usage = [];

        $usage['zeus start'] = 'Starts Zeus server with all its services';

        $usage['zeus start [<service-name>]'] = 'Starts Zeus server with selected service';

        return $usage;
    }

    /**
     * @param ModuleManager $moduleManager
     */
    public function init(ModuleManager $moduleManager)
    {
        $events = $moduleManager->getEventManager();

        // Registering a listener at default priority, 1, which will trigger
        // after the ConfigListener merges config.
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, [$this, 'onMergeConfig']);
    }

    /**
     * @param ModuleEvent $event
     */
    public function onMergeConfig(ModuleEvent $event)
    {
        $prefix = 'zeus server';

        $configListener = $event->getConfigListener();
        $config = $configListener->getMergedConfig(false);

        $routes = $config['console']['router']['routes'];

        // this is needed for BasePath plugin
        $config['view_manager']['console_base_path'] = '/';

        $newRoutes = $routes;

        foreach ($routes as $routeName => $routeOptions) {
            if (isset($routeOptions['options'])) {
                $routeOptions['options']['route'] = $prefix . ' ' . $routeOptions['options']['route'];

                $newRoutes['rerouted-' . $routeName] = $routeOptions;
            }
        }

        $config['console']['router']['routes'] = $newRoutes;

        // @todo: check routing first!
        $config['surpress_dispatch_event'] = Console::isConsole();

        if (static::getOverrideConfig()) {
            $config = ArrayUtils::merge($config, static::getOverrideConfig());
        }

        // Pass the changed configuration back to the listener:
        $configListener->setMergedConfig($config);
    }

    /**
     * @param ConsoleAdapter $console
     * @return string
     */
    public function getConsoleBanner(ConsoleAdapter $console)
    {
        $banner = "\r\n";
        $banner .= ' __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/ ';

        $banner .= "\r\n";
        $banner .= $console->colorize(' ZEUS for PHP - ZF3 Edition', ColorInterface::GREEN);
        $banner .= $console->colorize('   (' . self::MODULE_VERSION . ')', ColorInterface::CYAN);
        $banner .= "\r\n";

        return $banner;
    }
}