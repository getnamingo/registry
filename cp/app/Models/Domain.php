<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class Domain
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllDomain()
    {
        return $this->db->select('SELECT * FROM domain');
    }

    public function getDomainById($id)
    {
        return $this->db->select('SELECT * FROM domain WHERE id = ?', [$id])->fetch();
    }

    public function getDomainByName($name)
    {
        $result = $this->db->select('SELECT name FROM domain WHERE name = ?', [$name]);
        
        if (is_array($result)) {
            return $result;
        } else if (is_object($result) && method_exists($result, 'fetch')) {
            return $result->fetch();
        }

        return null;
    }

    public function deleteDomain($id)
    {
        $this->db->delete('DELETE FROM domain WHERE id = ?', [$id]);
        return true;
    }
}