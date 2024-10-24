<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/constants.php';

use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\Debug\ExceptionHandler;
use Symfony\Component\Routing\RouteCollection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Registry;

$app = new Silex\Application();

/** @var \Core\Entity\AppParameters $app['params'] */
$app['params'] = new \Core\Entity\AppParameters(__DIR__ . '/config/parameters.yml');

/** Logging via Monolog settings */
$logLevel = $app['params']->parameterByKey('log_level');
$logger = new Logger('flyimg');
$logger->pushHandler(new StreamHandler('php://stdout', $logLevel));
Registry::addLogger($logger);
$app['logger'] = $logger;

$exceptionHandlerFunction = function (\Exception $e) use ($app): void {
    $request = $app['request_stack']->getCurrentRequest();
    $app['logger']->error(
        '',
        [
            'error' => $e->getMessage(),
            'uri' => is_null($request) ? '' : $request->getUri(),
            'user_agent' => is_null($request) ? '' : $request->headers->get('User-Agent'),
            'file' => $e->getFile() . ':' . $e->getLine()
        ]
    );
};

if (!isset($_ENV['env']) || $_ENV['env'] !== 'test') {
    $app->error($exceptionHandlerFunction);
}

ErrorHandler::register();
$exceptionHandler = ExceptionHandler::register($app['params']->parameterByKey('debug'));
$exceptionHandler->setHandler($exceptionHandlerFunction);


/**
 * Routes
 */
$routesResolver = new \Core\Resolver\RoutesResolver();
$app['routes'] = $app->extend(
    'routes',
    function (RouteCollection $routes) use ($routesResolver) {
        return $routesResolver->parseRoutesFromYamlFile($routes, __DIR__ . '/config/routes.yml');
    }
);

/** Register Storage provider */
switch ($app['params']->parameterByKey('storage_system')) {
    case 's3':
        $app->register(new \Core\StorageProvider\S3StorageProvider());
        break;
    case 'local':
    default:
        $app->register(new \Core\StorageProvider\LocalStorageProvider());
        break;
}

/**
 * Controller Resolver
 *
 * @param \Silex\Application $app
 *
 * @return \Core\Resolver\ControllerResolver
 */
$app['resolver'] = function (\Silex\Application $app) {
    return new \Core\Resolver\ControllerResolver($app, $app['logger']);
};

/**
 * Register Image Handler
 *
 * @param \Silex\Application $app
 *
 * @return \Core\Handler\ImageHandler
 */
$app['image.handler'] = function (\Silex\Application $app): \Core\Handler\ImageHandler {
    return new \Core\Handler\ImageHandler(
        $app['flysystems']['storage_handler'],
        $app['params']
    );
};

/**
 * To generate a hashed url when security key is enabled
 * Example usage: php app.php encrypt w_200,h_200,c_1/Rovinj-Croatia.jpg
 */
if (!empty($argv[1]) && !empty($argv[2]) && $argv[1] == 'encrypt') {
    printf("Hashed request: %s\n", $app['image.handler']->securityHandler()->encrypt($argv[2]));
    return;
}

/** debug conf */
$app['debug'] = $app['params']->parameterByKey('debug');

return $app;
