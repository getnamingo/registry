<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class RegistryTransaction
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        if (envi('DB_DRIVER') === 'pgsql') {
            $config = config('connections')['pgsql'];
            $pdo = new \PDO(
                "{$config['driver']}:dbname=registryTransaction;host={$config['host']};port={$config['port']}",
                $config['username'],
                $config['password']
            );
            $this->db = PdoDatabase::fromPdo($pdo);
        } else {
            $this->db = $db;
        }
    }

    public function getAllRegistryTransaction()
    {
        $table = envi('DB_DRIVER') === 'pgsql' ? 'transaction_identifier' : 'registryTransaction.transaction_identifier';
        return $this->db->select("SELECT * FROM $table ORDER BY cldate DESC");
    }

    public function getRegistryTransactionById($id)
    {
        $table = envi('DB_DRIVER') === 'pgsql' ? 'transaction_identifier' : 'registryTransaction.transaction_identifier';
        return $this->db->select("SELECT * FROM $table WHERE id = ?", [$id])->fetch();
    }
}