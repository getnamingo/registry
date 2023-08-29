<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class RegistryTransaction
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllRegistryTransaction()
    {
        return $this->db->select('SELECT * FROM registryTransaction.transaction_identifier ORDER BY cldate DESC');
    }

    public function getRegistryTransactionById($id)
    {
        return $this->db->select('SELECT * FROM registryTransaction.transaction_identifier WHERE id = ?', [$id])->fetch();
    }
}