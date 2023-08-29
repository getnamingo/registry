<?php
use App\Controllers\Auth\AuthController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\HomeController;
use App\Controllers\DomainsController;
use App\Controllers\ContactsController;
use App\Controllers\HostsController;
use App\Controllers\LogsController;
use App\Controllers\ProfileController;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use Slim\Exception\HttpNotFoundException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;

$app->get('/', HomeController::class .':index')->setName('index');

$app->group('', function ($route) {
    $route->get('/register', AuthController::class . ':createRegister')->setName('register');
    $route->post('/register', AuthController::class . ':register');
    $route->get('/login', AuthController::class . ':createLogin')->setName('login');
    $route->post('/login', AuthController::class . ':login');

    $route->get('/verify-email', AuthController::class.':verifyEmail')->setName('verify.email');
    $route->get('/verify-email-resend',AuthController::class.':verifyEmailResend')->setName('verify.email.resend');

    $route->get('/forgot-password', PasswordController::class . ':createForgotPassword')->setName('forgot.password');
    $route->post('/forgot-password', PasswordController::class . ':forgotPassword');
    $route->get('/reset-password', PasswordController::class.':resetPassword')->setName('reset.password');
    $route->get('/update-password', PasswordController::class.':createUpdatePassword')->setName('update.password');
    $route->post('/update-password', PasswordController::class.':updatePassword');
})->add(new GuestMiddleware($container));

$app->group('', function ($route) {
    $route->get('/dashboard', HomeController::class .':dashboard')->setName('home');
    $route->get('/domains', DomainsController::class .':view')->setName('domains');
    $route->get('/contacts', ContactsController::class .':view')->setName('contacts');
    $route->get('/hosts', HostsController::class .':view')->setName('hosts');
    $route->get('/logs', LogsController::class .':view')->setName('logs');
    $route->get('/profile', ProfileController::class .':profile')->setName('profile');
    $route->get('/profile/notifications', ProfileController::class .':notifications')->setName('notifications');
    $route->get('/profile/security', ProfileController::class .':security')->setName('security');
    $route->get('/profile/plans', ProfileController::class .':plans')->setName('plans');
    $route->get('/profile/invoices', ProfileController::class .':invoices')->setName('invoices');
    $route->get('/mode', HomeController::class .':mode')->setName('mode');
    $route->get('/avatar', HomeController::class .':avatar')->setName('avatar');
    $route->get('/logout', AuthController::class . ':logout')->setName('logout');
    $route->post('/change-password', PasswordController::class . ':changePassword')->setName('change.password');
})->add(new AuthMiddleware($container));

$app->any('/api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    $config = new Config([
        'username' => $db['mysql']['username'],
        'password' => $db['mysql']['password'],
        'database' => $db['mysql']['database'],
        'basePath' => '/api',
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->add(function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Server\RequestHandlerInterface $handler) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException $e) {
        $response = new Response();
        $response->getBody()->write('404 Not Found');
        return $response->withStatus(404);
    }
});

$app->addErrorMiddleware(true, true, true);