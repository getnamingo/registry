<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

namespace App\Models;

use App\Security\PasswordHasher;
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
        $hashedPassword = PasswordHasher::hash($password);

        $this->db->insert('INSERT INTO users (username, email, password) VALUES (?, ?, ?)', [$username, $email, $hashedPassword]);

        return $this->db->lastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'users_id_seq' : null);
    }

    public function updateUser($id, $username, $email, $password)
    {
        $hashedPassword = PasswordHasher::hash($password);

        $this->db->update('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?', [$username, $email, $hashedPassword, $id]);

        return true;
    }

    public function deleteUser($id)
    {
        $this->db->delete('DELETE FROM users WHERE id = ?', [$id]);

        return true;
    }
}