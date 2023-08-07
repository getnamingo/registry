<?php

namespace App\Controllers;

use App\Models\User;
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
	
	public function notifications(Request $request, Response $response)
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
		
        return view($response,'admin/profile/notifications.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
	}
	
	public function security(Request $request, Response $response)
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
		
        return view($response,'admin/profile/security.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
	}
	
	public function plans(Request $request, Response $response)
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
		
        return view($response,'admin/profile/plans.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
	}
	
	public function invoices(Request $request, Response $response)
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
		
        return view($response,'admin/profile/invoices.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
	}

}
