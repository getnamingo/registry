<?php
use Imefisto\PsrSwoole\ServerRequest as PsrRequest;
use Imefisto\PsrSwoole\ResponseMerger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Chubbyphp\StaticFile\StaticFileMiddleware;
use Psr\Http\Message\StreamFactoryInterface;
use App\Lib\Logger;
use DI\Container;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;
use Slim\Handlers\Strategies\RequestResponseArgs;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFunction;

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/helper.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__. '/../')->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    //
}
//Enable error display in details when APP_ENV=local
if(envi('APP_ENV')=='local') {
    Logger::systemLogs(true);
}else{
    Logger::systemLogs(false);
}

$container = new Container();
// Set container to create App with on AppFactory
AppFactory::setContainer($container);

/**
 * Create your slim app
 */
$app = AppFactory::create();

$responseFactory = $app->getResponseFactory();

$routeCollector = $app->getRouteCollector();
$routeCollector->setDefaultInvocationStrategy(new RequestResponseArgs());
$routeParser = $app->getRouteCollector()->getRouteParser();

require_once __DIR__ . '/../bootstrap/database.php';

$container->set('router', function () use ($routeParser) {
    return $routeParser;
});

$container->set('db', function () use ($db) {
    return $db;
});

$container->set('pdo', function () use ($pdo) {
    return $pdo;
});

$container->set('auth', function() {
    return new \App\Auth\Auth;
});

$container->set('flash', function() {
    return new \Slim\Flash\Messages;
});

$container->set('view', function ($container) {
    $view = Twig::create(__DIR__ . '/../resources/views', [
        'cache' => false,
    ]);
    $view->getEnvironment()->addGlobal('auth', [
        'isLogin' => $container->get('auth')->isLogin(),
        'user' => $container->get('auth')->user(),
    ]);
    $view->getEnvironment()->addGlobal('flash', $container->get('flash'));
    $view->getEnvironment()->addGlobal('screen_mode', $_SESSION['_screen_mode']);

    //route
    $route = new TwigFunction('route', function ($name) {
        return route($name);
    });
    $view->getEnvironment()->addFunction($route);
    
    // Define the route_is function
    $routeIs = new \Twig\TwigFunction('route_is', function ($routeName) {
    	return strpos($_SERVER['REQUEST_URI'], $routeName) !== false;
    });
    $view->getEnvironment()->addFunction($routeIs);

    //assets
    $assets = new TwigFunction('assets', function ($location) {
        return assets($location);
    });
    $view->getEnvironment()->addFunction($assets);

    //Pagination
    $pagination = new TwigFunction("links", function ($object) {

    });
    $view->getEnvironment()->addFunction($pagination);

    return $view;
});
$app->add(TwigMiddleware::createFromContainer($app));

$container->set('validator', function ($container) {
    return new App\Lib\Validator;
});

$container->set('csrf', function($container) use ($responseFactory) {
    return new Guard($responseFactory);
});

$app->add(new \App\Middleware\ValidationErrorsMiddleware($container));
$app->add(new \App\Middleware\OldInputMiddleware($container));
$app->add(new \App\Middleware\CsrfViewMiddleware($container));



$app->add('csrf');
$app->setBasePath(routePath());

$uriFactory = new Psr17Factory;
$streamFactory = new Psr17Factory;
//$responseFactory = new Psr17Factory;
$uploadedFileFactory = new Psr17Factory;
$responseMerger = new ResponseMerger;

$app->add(new StaticFileMiddleware(
    $responseFactory,
    $streamFactory,
    __DIR__ . '/../public'
));

require __DIR__ . '/../routes/web.php';


$http = new Swoole\Http\Server("0.0.0.0", 3000);
$http->set([
    'worker_num' => swoole_cpu_num() * 2,
    'enable_coroutine' => true,
    'log_file' => '/tmp/sw'
]);



$http->on(
    'request',
    function (
        Request $swooleRequest,
        Response $swooleResponse
    ) use (
        $uriFactory,
        $streamFactory,
        $uploadedFileFactory,
        $responseFactory,
        $responseMerger,
        $app
    ) {
        /**
         * create psr request from swoole request
         */
        $psrRequest = new PsrRequest(
            $swooleRequest,
            $uriFactory,
            $streamFactory,
            $uploadedFileFactory
        );

    // Check if the request path matches a static file path
    if (preg_match('#^/assets/.*#', $psrRequest->getUri()->getPath())) {
        // If the request path matches a static file path, pass the request off to the StaticFile middleware
        $psrResponse = $app->handle($psrRequest, new Response());
    } else {
        // If the request path does not match a static file path, process the request with Slim
        $psrResponse = $app->handle($psrRequest);
    }

        /**
         * merge your psr response with swoole response
         */
		$response = $responseMerger->toSwoole(
			$psrResponse,
			$swooleResponse
		);

		if ($response->isWritable()) {
			$response->end();
		} else {
			// throw a generic exception
			throw new RuntimeException('HTTP response is not available');
		}
    }
);

$http->start();
