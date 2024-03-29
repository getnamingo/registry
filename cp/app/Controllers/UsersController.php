<?php

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class UsersController extends Controller
{
    public function listUsers(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $userModel = new User($this->container->get('db'));
        $users = $userModel->getAllUsers();
        return view($response,'admin/users/listUsers.twig', compact('users'));
    }
}