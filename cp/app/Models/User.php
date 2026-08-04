<?php
/**
 * Voras Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Voras Team <help@namingo.org>
 * @copyright  Copyright (c) 2026 Voras
 * @license    MIT License
 * @link       https://github.com/atriohq/foundry
 */

namespace App\Models;

use Pinga\Db\PdoDatabase;

class User
{
    private $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllUsers()
    {
        return $this->db->select('SELECT * FROM users');
    }
    
    public function getUserById($id)
    {
        return $this->db->select('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    }

    public function createUser($username, $email, $password)
    {
        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

        $this->db->insert('INSERT INTO users (username, email, password) VALUES (?, ?, ?)', [$username, $email, $hashedPassword]);

        return $this->db->lastInsertId();
    }

    public function updateUser($id, $username, $email, $password)
    {
        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

        $this->db->update('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?', [$username, $email, $hashedPassword, $id]);

        return true;
    }

    public function deleteUser($id)
    {
        $this->db->delete('DELETE FROM users WHERE id = ?', [$id]);

        return true;
    }
}