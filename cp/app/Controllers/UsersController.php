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
                'status' => v::in(['0', '4'])->setName('Status'),
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

            if (!checkPasswordComplexity($password)) {
                $this->container->get('flash')->addMessage('error', 'Password too weak. Use a stronger password');
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
                                'roles_mask' => 4,
                                'status' => $status,
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
                                'status' => $status,
                                'registered' => \time()
                            ]
                        );
                        $userId = $db->getlastInsertId();

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/user/create')->withStatus(302);
                    }

                    $db->exec('UPDATE users SET password_last_updated = NOW() WHERE id = ?', [$userId]);
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

    public function updateUser(Request $request, Response $response, $args)
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $registrars = $db->select("SELECT id, clid, name FROM registrar");

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-z0-9_-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid user name');
                return $response->withHeader('Location', '/users')->withStatus(302);
            }

            $user = $db->selectRow('SELECT id,email,username,status,verified,roles_mask,registered,last_login FROM users WHERE username = ?',
            [ $args ]);
            $user_asso = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?',
            [ $user['id'] ]);
            $registrar_name = $db->selectValue('SELECT name FROM registrar WHERE id = ?',
            [ $user_asso ]);

            if ($user) {
                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    return $response->withHeader('Location', '/dashboard')->withStatus(302);
                }

                $_SESSION['user_to_update'] = [$args];

                $roles_new = [
                    '4'  => ($user['roles_mask'] & 4)  ? true : false, // Registrar
                    '8'  => ($user['roles_mask'] & 8)  ? true : false, // Accountant
                    '16' => ($user['roles_mask'] & 16) ? true : false, // Support
                    '32' => ($user['roles_mask'] & 32) ? true : false, // Auditor
                    '64' => ($user['roles_mask'] & 64) ? true : false, // Sales
                ];

                return view($response,'admin/users/updateUser.twig', [
                    'user' => $user,
                    'currentUri' => $uri,
                    'registrars' => $registrars,
                    'user_asso' => $user_asso,
                    'registrar_name' => $registrar_name,
                    'roles_new' => $roles_new
                ]);
            } else {
                // User does not exist, redirect to the users view
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        } else {
            // Redirect to the users view
            return $response->withHeader('Location', '/users')->withStatus(302);
        }
    }

    public function updateUserProcess(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            $email = $data['email'] ?? null;
            $old_username = $_SESSION['user_to_update'][0];
            $username = $data['username'] ?? null;
            $password = $data['password'] ?? null;
            $password_confirmation = $data['password_confirmation'] ?? null;
            $status = $data['status'] ?? null;
            $verified = $data['verified'] ?? null;
            $roles_mask = isset($data['roles_mask']) ? (int)$data['roles_mask'] : null;

            $allowedRoles = [0, 2, 4, 8, 16, 32, 64];
            $allowedRolesMask = array_sum($allowedRoles); // 124 (sum of allowed roles)

            // Define validation rules
            $validators = [
                'email' => v::email()->notEmpty()->setName('Email'),
                'username' => v::regex('/^[a-zA-Z0-9_-]+$/')->length(3, 20)->setName('Username'),
                'status' => v::in(['0', '1', '2', '3', '4', '5'])->setName('Status'),
                'verified' => v::in(['0', '1'])->setName('Verified'), // Ensure verified is checked as 0 or 1
            ];

            // Add custom validation for roles_mask
            $validators['roles_mask'] = v::oneOf(
                v::intVal()->callback(function ($value) use ($allowedRolesMask) {
                    return ($value & ~$allowedRolesMask) === 0; // Ensure only allowed roles are included
                }),
                v::nullType() // Allow null as a valid value
            )->setName('Roles Mask');

            // Add password validation only if provided
            if (!empty($password)) {
                $validators['password'] = v::stringType()->notEmpty()->length(6, 255)->setName('Password');
                
                // Add password confirmation check only if both fields are provided
                if (!empty($password_confirmation)) {
                    $validators['password_confirmation'] = v::equals($password)->setName('Password Confirmation');
                }
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
                return $response->withHeader('Location', '/user/update/'.$old_username)->withStatus(302);
            }

            if (!checkPasswordComplexity($password)) {
                $this->container->get('flash')->addMessage('error', 'Password too weak. Use a stronger password');
                return $response->withHeader('Location', '/user/update/'.$old_username)->withStatus(302);
            }

            // Check if username already exists (excluding the current user)
            if ($username && $username !== $old_username) {
                $existingUsername = $db->selectValue('SELECT COUNT(*) FROM users WHERE username = ? AND username != ?', [$username, $old_username]);
                if ($existingUsername > 0) {
                    $errors[] = 'Username already exists';
                }
            }

            // Check if email already exists (excluding the current user)
            if ($email) {
                $existingEmail = $db->selectValue(
                    'SELECT COUNT(*) FROM users WHERE email = ? AND username != ?', 
                    [$email, $old_username]
                );
                if ($existingEmail > 0) {
                    $errors[] = 'Email already exists';
                }
            }

            // Fetch current roles_mask from the database
            $currentRolesMask = $db->selectValue(
                'SELECT roles_mask FROM users WHERE username = ?',
                [$old_username]
            );

            if ($currentRolesMask !== null) {
                // Prevent lowering privileges by setting roles_mask to 0 unless it was already 0
                if ($roles_mask == 0 && $currentRolesMask != 0) {
                    $errors[] = 'You cannot elevate role to admin unless the user was already admin';
                }

                // Prevent elevating privileges to 4 unless the user was already 4
                if ($roles_mask == 4 && $currentRolesMask != 4) {
                    $errors[] = 'You cannot elevate role to registrar unless the user was already registrar';
                }
            }

            // Handle errors
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->container->get('flash')->addMessage('error', $error);
                }
                return $response->withHeader('Location', '/user/update/' . $old_username)->withStatus(302);
            }

            if (empty($email)) {
                $this->container->get('flash')->addMessage('error', 'No email specified for update');
                return $response->withHeader('Location', '/user/update/'.$old_username)->withStatus(302);
            }

            if ($roles_mask === null) {
                $this->container->get('flash')->addMessage('error', 'No roles assigned. Please assign at least one role');
                return $response->withHeader('Location', '/user/update/' . $old_username)->withStatus(302);
            }

            $db->beginTransaction();

            try {
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');

                // Prepare the data to update
                $updateData = [
                    'email'      => $email,
                    'username'   => $username,
                    'verified'   => $verified,
                    'status' => $status,
                    'roles_mask' => $roles_mask,
                ];

                if (!empty($password)) {
                    $password_hashed = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);
                    $updateData['password'] = $password_hashed;
                }

                $db->update(
                    'users',
                    $updateData,
                    [
                        'username' => $old_username
                    ]
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/user/update/'.$old_username)->withStatus(302);
            }

            $userId = $db->selectValue('SELECT id from users WHERE username = ?', [ $username ]);
            unset($_SESSION['user_to_update']);
            $db->exec('UPDATE users SET password_last_updated = NOW() WHERE id = ?', [$userId]);
            $this->container->get('flash')->addMessage('success', 'User ' . $username . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/user/update/'.$username)->withStatus(302);
        }
    }
    
}