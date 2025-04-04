<?php

use App\Controllers\Auth\AuthController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\HomeController;
use App\Controllers\DomainsController;
use App\Controllers\ApplicationsController;
use App\Controllers\ContactsController;
use App\Controllers\HostsController;
use App\Controllers\LogsController;
use App\Controllers\RegistrarsController;
use App\Controllers\UsersController;
use App\Controllers\FinancialsController;
use App\Controllers\ReportsController;
use App\Controllers\ProfileController;
use App\Controllers\SystemController;
use App\Controllers\SupportController;
use App\Controllers\DapiController;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use Slim\Exception\HttpNotFoundException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;

$app->get('/', HomeController::class .':index')->setName('index');

$app->group('', function ($route) {
    $route->get('/login', AuthController::class . ':createLogin')->setName('login');
    $route->map(['GET', 'POST'], '/login/verify', AuthController::class . ':verify2FA')->setName('verify2FA');
    $route->post('/login', AuthController::class . ':login');
    $route->post('/webauthn/login/challenge', AuthController::class . ':getLoginChallenge')->setName('webauthn.login.challenge');
    $route->post('/webauthn/login/verify', AuthController::class . ':verifyLogin')->setName('webauthn.login.verify');
    $route->get('/forgot-password', PasswordController::class . ':createForgotPassword')->setName('forgot.password');
    $route->post('/forgot-password', PasswordController::class . ':forgotPassword');
    $route->get('/reset-password', PasswordController::class.':resetPassword')->setName('reset.password');
    $route->get('/update-password', PasswordController::class.':createUpdatePassword')->setName('update.password');
    $route->post('/update-password', PasswordController::class.':updatePassword');
    $route->post('/webhook/adyen', FinancialsController::class .':webhookAdyen')->setName('webhookAdyen');
    $route->post('/webhook/sumsub', ContactsController::class .':webhookSumsub')->setName('webhookSumsub');
})->add(new GuestMiddleware($container));

$app->group('', function ($route) {
    $route->get('/dashboard', HomeController::class .':dashboard')->setName('home');

    $route->get('/domains', DomainsController::class .':listDomains')->setName('listDomains');
    $route->map(['GET', 'POST'], '/domain/check', DomainsController::class . ':checkDomain')->setName('checkDomain');
    $route->map(['GET', 'POST'], '/domain/create', DomainsController::class . ':createDomain')->setName('createDomain');
    $route->get('/domain/view/{domain}', DomainsController::class . ':viewDomain')->setName('viewDomain');
    $route->get('/domain/update/{domain}', DomainsController::class . ':updateDomain')->setName('updateDomain');
    $route->post('/domain/update', DomainsController::class . ':updateDomainProcess')->setName('updateDomainProcess');
    $route->post('/domain/deletesecdns', DomainsController::class . ':domainDeleteSecdns')->setName('domainDeleteSecdns');
    $route->post('/domain/deletehost', DomainsController::class . ':domainDeleteHost')->setName('domainDeleteHost');
    $route->map(['GET', 'POST'], '/domain/renew/{domain}', DomainsController::class . ':renewDomain')->setName('renewDomain');
    $route->map(['GET', 'POST'], '/domain/delete/{domain}', DomainsController::class . ':deleteDomain')->setName('deleteDomain');
    $route->map(['GET', 'POST'], '/domain/restore/{domain}', DomainsController::class . ':restoreDomain')->setName('restoreDomain');
    $route->map(['GET', 'POST'], '/domain/report/{domain}', DomainsController::class . ':reportDomain')->setName('reportDomain');
    
    $route->get('/applications', ApplicationsController::class .':listApplications')->setName('listApplications');
    $route->map(['GET', 'POST'], '/application/create', ApplicationsController::class . ':createApplication')->setName('createApplication');
    $route->get('/application/view/{application}', ApplicationsController::class . ':viewApplication')->setName('viewApplication');
    $route->get('/application/update/{application}', ApplicationsController::class . ':updateApplication')->setName('updateApplication');
    $route->post('/application/update', ApplicationsController::class . ':updateApplicationProcess')->setName('updateApplicationProcess');
    $route->post('/application/deletehost', ApplicationsController::class . ':applicationDeleteHost')->setName('applicationDeleteHost');
    $route->map(['GET', 'POST'], '/application/approve/{application}', ApplicationsController::class . ':approveApplication')->setName('approveApplication');
    $route->map(['GET', 'POST'], '/application/reject/{application}', ApplicationsController::class . ':rejectApplication')->setName('rejectApplication');
    $route->map(['GET', 'POST'], '/application/delete/{application}', ApplicationsController::class . ':deleteApplication')->setName('deleteApplication');

    $route->get('/transfers', DomainsController::class . ':listTransfers')->setName('listTransfers');
    $route->map(['GET', 'POST'], '/transfer/request', DomainsController::class . ':requestTransfer')->setName('requestTransfer');
    $route->map(['GET', 'POST'], '/transfer/approve/{domain}', DomainsController::class . ':approveTransfer')->setName('approveTransfer');
    $route->map(['GET', 'POST'], '/transfer/reject/{domain}', DomainsController::class . ':rejectTransfer')->setName('rejectTransfer');
    $route->map(['GET', 'POST'], '/transfer/cancel/{domain}', DomainsController::class . ':cancelTransfer')->setName('cancelTransfer');

    $route->get('/contacts', ContactsController::class .':listContacts')->setName('listContacts');
    $route->map(['GET', 'POST'], '/contact/create', ContactsController::class . ':createContact')->setName('createContact');
    $route->map(['GET', 'POST'], '/contact/create-api', ContactsController::class . ':createContactApi')->setName('createContactApi');
    $route->get('/contact/view/{contact}', ContactsController::class . ':viewContact')->setName('viewContact');
    $route->get('/contact/update/{contact}', ContactsController::class . ':updateContact')->setName('updateContact');
    $route->get('/contact/validate/{contact}', ContactsController::class . ':validateContact')->setName('validateContact');
    $route->post('/contact/update', ContactsController::class . ':updateContactProcess')->setName('updateContactProcess');
    $route->post('/contact/approve', ContactsController::class . ':approveContact')->setName('approveContact');
    $route->map(['GET', 'POST'], '/contact/delete/{contact}', ContactsController::class . ':deleteContact')->setName('deleteContact');
    
    $route->get('/hosts', HostsController::class .':listHosts')->setName('listHosts');
    $route->map(['GET', 'POST'], '/host/create', HostsController::class . ':createHost')->setName('createHost');
    $route->get('/host/view/{host}', HostsController::class . ':viewHost')->setName('viewHost');
    $route->get('/host/update/{host}', HostsController::class . ':updateHost')->setName('updateHost');
    $route->post('/host/update', HostsController::class . ':updateHostProcess')->setName('updateHostProcess');
    $route->map(['GET', 'POST'], '/host/delete/{host}', HostsController::class . ':deleteHost')->setName('deleteHost');

    $route->get('/registrars', RegistrarsController::class .':view')->setName('registrars');
    $route->map(['GET', 'POST'], '/registrar/create', RegistrarsController::class . ':create')->setName('registrarcreate');
    $route->get('/registrar/view/{registrar}', RegistrarsController::class . ':viewRegistrar')->setName('viewRegistrar');
    $route->get('/registrar/update/{registrar}', RegistrarsController::class . ':updateRegistrar')->setName('updateRegistrar');
    $route->get('/registrar/pricing/{registrar}', RegistrarsController::class . ':customPricingView')->setName('customPricingView');
    $route->map(['POST', 'DELETE'], '/registrar/updatepricing/{registrar}', RegistrarsController::class . ':updateCustomPricing')->setName('updateCustomPricing');
    $route->post('/registrar/update', RegistrarsController::class . ':updateRegistrarProcess')->setName('updateRegistrarProcess');
    $route->get('/registrar', RegistrarsController::class .':registrar')->setName('registrar');
    $route->map(['GET', 'POST'], '/registrar/edit', RegistrarsController::class .':editRegistrar')->setName('editRegistrar');
    $route->get('/registrar/check', RegistrarsController::class . ':oteCheck')->setName('oteCheck');
    $route->map(['GET', 'POST'], '/registrar/transfer', RegistrarsController::class . ':transferRegistrar')->setName('transferRegistrar');
    $route->map(['GET', 'POST'], '/registrar/process', RegistrarsController::class . ':transferRegistrarProcess')->setName('transferRegistrarProcess');
    $route->get('/registrar/impersonate/{registrar}', RegistrarsController::class . ':impersonateRegistrar')->setName('impersonateRegistrar');
    $route->get('/leave_impersonation', RegistrarsController::class . ':leave_impersonation')->setName('leave_impersonation');
    $route->map(['GET', 'POST'], '/registrars/notify', RegistrarsController::class .':notifyRegistrars')->setName('notifyRegistrars');

    $route->get('/users', UsersController::class .':listUsers')->setName('listUsers');
    $route->map(['GET', 'POST'], '/user/create', UsersController::class . ':createUser')->setName('createUser');
    $route->get('/user/update/{user}', UsersController::class . ':updateUser')->setName('updateUser');
    $route->post('/user/update', UsersController::class . ':updateUserProcess')->setName('updateUserProcess');
    $route->get('/user/impersonate/{user}', UsersController::class . ':impersonateUser')->setName('impersonateUser');

    $route->get('/epphistory', LogsController::class .':view')->setName('epphistory');
    $route->get('/poll', LogsController::class .':poll')->setName('poll');
    $route->get('/log', LogsController::class .':log')->setName('log');

    $route->get('/reports', ReportsController::class .':view')->setName('reports');
    $route->get('/export', ReportsController::class .':exportDomains')->setName('exportDomains');
    $route->get('/server', ReportsController::class .':serverHealth')->setName('serverHealth');
    $route->post('/clear-cache', ReportsController::class .':clearCache')->setName('clearCache');

    $route->get('/invoices', FinancialsController::class .':invoices')->setName('invoices');
    $route->get('/invoice/{invoice}', FinancialsController::class . ':viewInvoice')->setName('viewInvoice');
    $route->map(['GET', 'POST'], '/deposit', FinancialsController::class .':deposit')->setName('deposit');
    $route->map(['GET', 'POST'], '/create-payment', FinancialsController::class .':createStripePayment')->setName('createStripePayment');
    $route->map(['GET', 'POST'], '/create-adyen-payment', FinancialsController::class .':createAdyenPayment')->setName('createAdyenPayment');
    $route->map(['GET', 'POST'], '/create-crypto-payment', FinancialsController::class .':createCryptoPayment')->setName('createCryptoPayment');
    $route->map(['GET', 'POST'], '/create-nicky-payment', FinancialsController::class .':createNickyPayment')->setName('createNickyPayment');
    $route->map(['GET', 'POST'], '/payment-success', FinancialsController::class .':successStripe')->setName('successStripe');
    $route->map(['GET', 'POST'], '/payment-success-adyen', FinancialsController::class .':successAdyen')->setName('successAdyen');
    $route->map(['GET', 'POST'], '/payment-success-crypto', FinancialsController::class .':successCrypto')->setName('successCrypto');
    $route->map(['GET', 'POST'], '/payment-success-nicky', FinancialsController::class .':successNicky')->setName('successNicky');
    $route->map(['GET', 'POST'], '/payment-cancel', FinancialsController::class .':cancel')->setName('cancel');
    $route->get('/transactions', FinancialsController::class .':transactions')->setName('transactions');
    $route->get('/overview', FinancialsController::class .':overview')->setName('overview');

    $route->map(['GET', 'POST'], '/registry', SystemController::class .':registry')->setName('registry');
    $route->map(['GET', 'POST'], '/registry/tld/create', SystemController::class .':createTld')->setName('createTld');
    $route->map(['GET', 'POST'], '/registry/tld/{tld}', SystemController::class . ':manageTld')->setName('manageTld');
    $route->get('/registry/tlds', SystemController::class .':listTlds')->setName('listTlds');
    $route->map(['GET', 'POST'], '/registry/reserved', SystemController::class .':manageReserved')->setName('manageReserved');
    $route->get('/registry/tokens', SystemController::class .':manageTokens')->setName('manageTokens');
    $route->get('/registry/tokens/generate', SystemController::class .':generateTokens')->setName('generateTokens');
    $route->get('/registry/tokens/update/{token}', SystemController::class . ':updateToken')->setName('updateToken');
    $route->post('/registry/tokens/update', SystemController::class . ':updateTokenProcess')->setName('updateTokenProcess');
    $route->map(['GET', 'POST'], '/registry/tokens/delete/{token}', SystemController::class . ':deleteToken')->setName('deleteToken');
    $route->get('/registry/promotion/{tld}', SystemController::class . ':viewPromo')->setName('viewPromo');
    $route->post('/registry/promotions', SystemController::class . ':managePromo')->setName('managePromo');
    $route->get('/registry/phases/{tld}', SystemController::class . ':viewPhases')->setName('viewPhases');
    $route->post('/registry/phases', SystemController::class . ':managePhases')->setName('managePhases');
    $route->get('/registry/idnexport/{script}', SystemController::class .':idnexport')->setName('idnexport');
    $route->map(['GET', 'POST'], '/registry/dnssec', SystemController::class . ':manageDnssec')->setName('manageDnssec');

    $route->get('/support', SupportController::class .':view')->setName('ticketview');
    $route->map(['GET', 'POST'], '/support/new', SupportController::class .':newticket')->setName('newticket');
    $route->get('/ticket/{ticket}', SupportController::class . ':viewTicket')->setName('viewTicket');
    $route->post('/support/reply', SupportController::class . ':replyTicket')->setName('replyTicket');
    $route->post('/support/status', SupportController::class . ':statusTicket')->setName('statusTicket');
    $route->get('/support/docs', SupportController::class .':docs')->setName('docs');
    $route->get('/support/media', SupportController::class .':mediakit')->setName('mediakit');

    $route->get('/profile', ProfileController::class .':profile')->setName('profile');
    $route->post('/profile/2fa', ProfileController::class .':activate2fa')->setName('activate2fa');
    $route->post('/profile/logout-everywhere', ProfileController::class . ':logoutEverywhereElse')->setName('profile.logout.everywhere');
    $route->get('/webauthn/register/challenge', ProfileController::class . ':getRegistrationChallenge')->setName('webauthn.register.challenge');
    $route->post('/webauthn/register/verify', ProfileController::class . ':verifyRegistration')->setName('webauthn.register.verify');
    $route->post('/token-well', ProfileController::class .':tokenWell')->setName('tokenWell');

    $route->get('/mode', HomeController::class .':mode')->setName('mode');
    $route->get('/lang', HomeController::class .':lang')->setName('lang');
    $route->get('/logout', AuthController::class . ':logout')->setName('logout');
    $route->post('/change-password', PasswordController::class . ':changePassword')->setName('change.password');

    $route->get('/dapi/domains', [DapiController::class, 'listDomains']);
    $route->get('/dapi/applications', [DapiController::class, 'listApplications']);
    $route->get('/dapi/payments', [DapiController::class, 'listPayments']);
    $route->get('/dapi/statements', [DapiController::class, 'listStatements']);
    $route->get('/dapi/domain/price', [DapiController::class, 'domainPrice']);
})->add(new AuthMiddleware($container));

$app->any('/api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    if (config('default') == 'mysql') {
        $db_username = $db['mysql']['username'];
        $db_password = $db['mysql']['password'];
        $db_database = $db['mysql']['database'];
        $db_address = 'localhost';
    } elseif (config('default') == 'pgsql') {
        $db_username = $db['pgsql']['username'];
        $db_password = $db['pgsql']['password'];
        $db_database = $db['pgsql']['database'];
        $db_address = 'localhost';
    } elseif (config('default') == 'sqlite') {
        $db_username = null;
        $db_password = null;
        $db_database = null;
        $db_address = '/var/www/cp/registry.db';
    }
    $config = new Config([
        'driver' => config('default'),
        'username' => $db_username,
        'password' => $db_password,
        'database' => $db_database,
        'address' => $db_address,
        'basePath' => '/api',
        'middlewares' => 'customization,dbAuth,authorization,sanitation,multiTenancy',
        'authorization.tableHandler' => function ($operation, $tableName) {
        $restrictedTables = ['contact_authInfo', 'contact_postalInfo', 'domain_authInfo', 'secdns'];
            return !in_array($tableName, $restrictedTables);
        },
        'authorization.columnHandler' => function ($operation, $tableName, $columnName) {
            if ($tableName == 'registrar' && $columnName == 'pw') {
                return false;
            }
            if ($tableName == 'users' && $columnName == 'password') {
                return false;
            }
            return true;
        },
        'sanitation.handler' => function ($operation, $tableName, $column, $value) {
            return is_string($value) ? strip_tags($value) : $value;
        },
        'customization.beforeHandler' => function ($operation, $tableName, $request, $environment) {
            if (!isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            $_SESSION['user'] = $_SESSION['auth_username'];
        },
        'customization.afterHandler' => function ($operation, $tableName, $response, $environment) {
            $bodyContent = (string) $response->getBody();
            $response->getBody()->rewind();
            $data = json_decode($bodyContent, true);

            if ($tableName == 'domain') {
                if (isset($data['records']) && is_array($data['records'])) {
                    foreach ($data['records'] as &$record) {
                        if (isset($record['name']) && stripos($record['name'], 'xn--') === 0) {
                            $record['name_o'] = $record['name'];
                            $record['name'] = idn_to_utf8($record['name'], 0, INTL_IDNA_VARIANT_UTS46);
                        } else {
                            $record['name_o'] = $record['name'];
                        }
                    }
                    unset($record);
                }
            }
            else if ($tableName == 'domain_tld') {
                if (isset($data['records']) && is_array($data['records'])) {
                    foreach ($data['records'] as &$record) {
                        if (isset($record['tld']) && stripos($record['tld'], '.xn--') === 0) {
                            $punycodeTld = ltrim($record['tld'], '.');
                            $record['tld_o'] = $record['tld'];
                            $record['tld'] = '.'.idn_to_utf8(strtolower($punycodeTld), 0, INTL_IDNA_VARIANT_UTS46);
                        } else {
                            $record['tld_o'] = $record['tld'];
                        }
                    }
                    unset($record);
                }
            }

            $modifiedBodyContent = json_encode($data, JSON_UNESCAPED_UNICODE);
            $stream = \Nyholm\Psr7\Stream::create($modifiedBodyContent);
            $response = $response->withBody($stream);
            $response = $response->withHeader('Content-Length', strlen($modifiedBodyContent));
            return $response;
        },
        'dbAuth.usersTable' => 'users',
        'dbAuth.usernameColumn' => 'email',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.returnedColumns' => 'email,roles_mask',
        'dbAuth.registerUser' => false,
        'multiTenancy.handler' => function ($operation, $tableName) {   
            if (isset($_SESSION['auth_roles']) && $_SESSION['auth_roles'] === 0) {
                return [];
            }
            $registrarId = $_SESSION['auth_registrar_id'];

            $columnMap = [
                'contact' => 'clid',
                'domain' => 'clid',
                'application' => 'clid',
                'host' => 'clid',
                'poll' => 'registrar_id',
                'invoices' => 'registrar_id',
                'registrar' => 'id',
                'payment_history' => 'registrar_id',
                'statement' => 'registrar_id',
                'support_tickets' => 'user_id', // Continues to use user_id
                'users_audit' => 'user_id', // Continues to use user_id
            ];

            // Check if the special filter condition for the domain table is met
            $isSpecialDomainRequest = $tableName === 'domain' && isset($_GET['filter']) && $_GET['filter'] === 'trstatus,nis';

            if (array_key_exists($tableName, $columnMap)) {
                // If it's a special domain request, bypass the usual filtering
                if ($isSpecialDomainRequest) {
                    return [];
                }

                // Use registrarId for tables where 'registrar_id' is the filter
                // For 'support_tickets' and 'users_audit', use userId
                return [$columnMap[$tableName] => (in_array($tableName, ['support_tickets', 'users_audit']) ? $_SESSION['auth_user_id'] : $registrarId)];
            }

            return ['1' => '0'];
        },
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->any('/log-api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    if (config('default') == 'mysql') {
        $db_username = $db['mysql']['username'];
        $db_password = $db['mysql']['password'];
        $db_address = 'localhost';
    } elseif (config('default') == 'pgsql') {
        $db_username = $db['pgsql']['username'];
        $db_password = $db['pgsql']['password'];
        $db_address = 'localhost';
    } elseif (config('default') == 'sqlite') {
        $db_username = null;
        $db_password = null;
        $db_address = '/var/www/cp/registry.db';
    }
    $config = new Config([
        'driver' => config('default'),
        'username' => $db_username,
        'password' => $db_password,
        'database' => 'registryTransaction',
        'address' => $db_address,
        'basePath' => '/log-api',
        'middlewares' => 'customization,dbAuth,multiTenancy',
        'customization.beforeHandler' => function ($operation, $tableName, $request, $environment) {
            if (!isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            $_SESSION['user'] = $_SESSION['auth_username'];
        },
        'dbAuth.usersTable' => 'users',
        'dbAuth.usernameColumn' => 'email',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.returnedColumns' => 'email,roles_mask',
        'dbAuth.registerUser' => false,
        'multiTenancy.handler' => function ($operation, $tableName) {    
            if (isset($_SESSION['auth_roles']) && $_SESSION['auth_roles'] === 0) {
                return [];
            }
            $registrarId = $_SESSION['auth_registrar_id'];
            $columnMap = [
                'transaction_identifier' => 'registrar_id',
            ];

            if (array_key_exists($tableName, $columnMap)) {
                return [$columnMap[$tableName] => $registrarId];
            }

            return ['1' => '0'];
        },
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->add(function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Server\RequestHandlerInterface $handler) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException $e) {
        $responseFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $response = $responseFactory->createResponse();
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
});