<?php

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Respect\Validation\Validator as v;

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
    
    public function createUser(Request $request, Response $response)
    {
        // Registrars can not create new users, then need to ask the registry
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();    
            $db = $this->container->get('db');
            $email = $data['email'] ?? null;
            $username = $data['username'] ?? null;
            $password = $data['password'] ?? null;
            $password_confirmation = $data['password_confirmation'] ?? null;
            $status = $data['status'] ?? null;
            $verified = $data['verified'] ?? null;
            $role = $data['role'] ?? null;
            $registrar_id = $data['registrar_id'] ?? null;

            // Define validation rules
            $validators = [
                'email' => v::email()->notEmpty()->setName('Email'),
                'username' => v::regex('/^[a-zA-Z0-9_-]+$/')->length(3, 20)->setName('Username'),
                'password' => v::stringType()->notEmpty()->length(6, 255)->setName('Password'),
                'password_confirmation' => v::equals($data['password'] ?? '')->setName('Password Confirmation'),
                'status' => v::in(['active', 'inactive'])->setName('Status'),
                'role' => v::in(['admin', 'registrar'])->setName('Role'),
            ];

            // Add registrar_id validation if role is registrar
            if (($data['role'] ?? '') === 'registrar') {
                $validators['registrar_id'] = v::numericVal()->notEmpty()->setName('Registrar ID');
            }

            // Validate data
            $errors = [];
            foreach ($validators as $field => $validator) {
                try {
                    $validator->assert($data[$field] ?? null);
                } catch (\Respect\Validation\Exceptions\ValidationException $exception) {
                    $errors[$field] = $exception->getMessages(); // Collect all error messages
                }
            }

            // If errors exist, return with errors
            if (!empty($errors)) {
                // Flatten the errors array into a string
                $errorMessages = [];
                foreach ($errors as $field => $fieldErrors) {
                    $fieldMessages = implode(', ', $fieldErrors); // Concatenate messages for the field
                    $errorMessages[] = ucfirst($field) . ': ' . $fieldMessages; // Prefix with field name
                }
                $errorString = implode('; ', $errorMessages); // Join all fields' errors

                // Add the flattened error string as a flash message
                $this->container->get('flash')->addMessage('error', 'Error: ' . $errorString);

                // Redirect back to the form
                return $response->withHeader('Location', '/user/create')->withStatus(302);
            }

            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }

            if ($email) {                
                if ($registrar_id) {                   
                    $db->beginTransaction();

                    $password_hashed = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

                    try {
                        $db->insert(
                            'users',
                            [
                                'email' => $email,
                                'password' => $password_hashed,
                                'username' => $username,
                                'verified' => $verified,
                                'roles_mask' => 6,
                                'registered' => \time()
                            ]
                        );
                        $user_id = $db->getLastInsertId();

                        $db->insert(
                            'registrar_users',
                            [
                                'registrar_id' => $registrar_id,
                                'user_id' => $user_id
                            ]
                        );

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/user/create')->withStatus(302);
                    }

                    $this->container->get('flash')->addMessage('success', 'User ' . $email . ' has been created successfully');
                    return $response->withHeader('Location', '/users')->withStatus(302);
                } else {
                    $db->beginTransaction();

                    $password_hashed = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

                    try {
                        $db->insert(
                            'users',
                            [
                                'email' => $email,
                                'password' => $password_hashed,
                                'username' => $username,
                                'verified' => $verified,
                                'roles_mask' => 0,
                                'registered' => \time()
                            ]
                        );

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/user/create')->withStatus(302);
                    }

                    $this->container->get('flash')->addMessage('success', 'User ' . $email . ' has been created successfully');
                    return $response->withHeader('Location', '/users')->withStatus(302);
                }
            }
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/users/createUser.twig', [
            'registrars' => $registrars,
            'registrar' => $registrar,
        ]);
    }
}