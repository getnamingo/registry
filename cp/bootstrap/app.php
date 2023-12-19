<?php

use App\Lib\Logger;
use DI\Container;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;
use Slim\Handlers\Strategies\RequestResponseArgs;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFunction;
use Gettext\Loader\PoLoader;
use Gettext\Translations;
use Punic\Language;

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/helper.php';

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

$app = AppFactory::create();

$responseFactory = $app->getResponseFactory();

$routeCollector = $app->getRouteCollector();
$routeCollector->setDefaultInvocationStrategy(new RequestResponseArgs());
$routeParser = $app->getRouteCollector()->getRouteParser();

require_once __DIR__ . '/database.php';

// Known set of languages
$allowedLanguages = ['en_US', 'uk_UA', 'es_ES']; // Add more as needed

if (isset($_SESSION['_lang']) && in_array($_SESSION['_lang'], $allowedLanguages)) {
    // Use regex to validate the format: two letters, underscore, two letters
    if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $_SESSION['_lang'])) {
        $desiredLanguage = $_SESSION['_lang'];
        $parts = explode('_', $_SESSION['_lang']);
        if (isset($parts[1])) {
            $uiLang = strtolower($parts[1]);
        }
    } else {
        $desiredLanguage = 'en_US';
        $uiLang = 'us';
    }
} else {
    $desiredLanguage = 'en_US';
    $uiLang = 'us';
}
$lang_full = Language::getName($desiredLanguage, 'en');
$lang = trim(strstr($lang_full, ' (', true));

$languageFile = '../lang/' . $desiredLanguage . '/messages.po';
if (!file_exists($languageFile)) {
    $desiredLanguage = 'en_US'; // Fallback
    $languageFile = '../lang/en_US/messages.po';
}

$loader = new PoLoader();
$translations = $loader->loadFile($languageFile);

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

$container->set('view', function ($container) use ($translations, $uiLang, $lang) {
    $view = Twig::create(__DIR__ . '/../resources/views', [
        'cache' => false,
    ]);
    $view->getEnvironment()->addGlobal('auth', [
        'isLogin' => $container->get('auth')->isLogin(),
        'user' => $container->get('auth')->user(),
    ]);
    $view->getEnvironment()->addGlobal('uiLang', $uiLang);
    $view->getEnvironment()->addGlobal('lang', $lang);
    $view->getEnvironment()->addGlobal('flash', $container->get('flash'));
    if (isset($_SESSION['_screen_mode'])) {
        $view->getEnvironment()->addGlobal('screen_mode', $_SESSION['_screen_mode']);
    } else {
        $view->getEnvironment()->addGlobal('screen_mode', 'light');
    }
    if (isset($_SESSION['auth_roles'])) {
        $view->getEnvironment()->addGlobal('roles', $_SESSION['auth_roles']);
    }

    $db = $container->get('db');
    $query = 'SELECT r.currency, ru.registrar_id
              FROM registrar_users ru
              JOIN registrar r ON ru.registrar_id = r.id
              WHERE ru.user_id = ?';

    if (isset($_SESSION['auth_user_id'])) {
        $result = $db->select($query, [$_SESSION['auth_user_id']]);

        // Default values
        $_SESSION['_currency'] = 'USD';
        $_SESSION['auth_registrar_id'] = null;  // Default registrar_id

        if ($result !== null && count($result) > 0) {
            if (isset($result[0]['currency'])) {
                $_SESSION['_currency'] = $result[0]['currency'];
            }
            if (isset($result[0]['registrar_id'])) {
                $_SESSION['auth_registrar_id'] = $result[0]['registrar_id'];
            }
        }
    }

    $currency = isset($_SESSION['_currency']) ? $_SESSION['_currency'] : 'USD';
    $view->getEnvironment()->addGlobal('currency', $currency);

    $translateFunction = new TwigFunction('__', function ($text) use ($translations) {
        // Find the translation
        $translation = $translations->find(null, $text);
        if ($translation) {
            return $translation->getTranslation();
        }
        // Return the original text if translation not found
        return $text;
    });
    $view->getEnvironment()->addFunction($translateFunction);

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
    return new Slim\Csrf\Guard($responseFactory);
});

$app->add(new \App\Middleware\ValidationErrorsMiddleware($container));
$app->add(new \App\Middleware\OldInputMiddleware($container));
$app->add(new \App\Middleware\CsrfViewMiddleware($container));

$csrfMiddleware = function ($request, $handler) use ($container) {
    $uri = $request->getUri();
    $path = $uri->getPath();

    // Get the CSRF Guard instance from the container
    $csrf = $container->get('csrf');

    // Skip CSRF for the specific path
    if ($path && $path === '/webauthn/register/verify') {
        return $handler->handle($request);
    }
    if ($path && $path === '/webauthn/login/challenge') {
        return $handler->handle($request);
    }
    if ($path && $path === '/webauthn/login/verify') {
        return $handler->handle($request);
    }
    if ($path && $path === '/domain/deletehost') {
        return $handler->handle($request);
    }
    if ($path && $path === '/domain/deletesecdns') {
        return $handler->handle($request);
    }
    if ($path && $path === '/application/deletehost') {
        return $handler->handle($request);
    }

    // If not skipped, apply the CSRF Guard
    return $csrf->process($request, $handler);
};

$app->add($csrfMiddleware);
$app->setBasePath(routePath());

require __DIR__ . '/../routes/web.php';