<?php

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
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $this->db->insert('INSERT INTO users (username, email, password) VALUES (?, ?, ?)', [$username, $email, $hashedPassword]);

        return $this->db->lastInsertId();
    }

    public function updateUser($id, $username, $email, $password)
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $this->db->update('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?', [$username, $email, $hashedPassword, $id]);

        return true;
    }

    public function deleteUser($id)
    {
        $this->db->delete('DELETE FROM users WHERE id = ?', [$id]);

        return true;
    }
}
