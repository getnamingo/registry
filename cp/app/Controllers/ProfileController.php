<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ProfileController extends Controller
{
    public function profile(Request $request, Response $response)
    {
        $username = $_SESSION['auth_username'];
        $email = $_SESSION['auth_email'];
        $status = $_SESSION['auth_status'];
        if ($status == 0) {
            $status = "Confirmed";
        } else {
            $status = "Unknown";
        }
        $roles = $_SESSION['auth_roles'];
        if ($roles == 0) {
            $role = "Admin";
        } else {
            $role = "Unknown";
        }
        
        return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
    }

}