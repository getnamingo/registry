<?php
/**
 * Argora Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Taras Kondratyuk <help@argora.org>
 * @copyright  Copyright (c) 2025 Argora
 * @license    MIT License
 * @link       https://github.com/getargora/foundry
 */

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Pinga\Session;

class AuditMiddleware extends Middleware
{

    public function __invoke(Request $request, RequestHandler $handler)
    {
        if (isset($_SESSION['auth_user_id'])) {
            $userId = (int) $_SESSION['auth_user_id'];
            $sessionId = crc32(\Pinga\Session\Session::id());
            $db = $this->container->get('db');

            switch (envi('DB_DRIVER')) {
                case 'mysql':
                    $db->exec("SET @audit_usr_id = {$userId}");
                    $db->exec("SET @audit_ses_id = {$sessionId}");
                    break;

                case 'pgsql':
                    // Use dotted custom GUC names; SELECT set_config(...) works everywhere
                    $db->exec("SELECT set_config('app.audit_usr_id', '{$userId}', true)");
                    $db->exec("SELECT set_config('app.audit_ses_id', '{$sessionId}', true)");
                    break;
            }
        }

        return $handler->handle($request);
    }

}