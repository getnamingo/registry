<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class Host
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllHost()
    {
        $sql = "
            SELECT 
                host.*, 
                addr.addr,
                addr.ip,
                status.status AS host_status,
                CASE WHEN EXISTS (
                    SELECT 1 FROM domain_host_map WHERE domain_host_map.host_id = host.id
                ) THEN 1 ELSE 0 END AS has_domain_mapping
            FROM host
            LEFT JOIN host_addr AS addr ON host.id = addr.host_id
            LEFT JOIN host_status AS status ON host.id = status.host_id
        ";
        
        return $this->db->select($sql);
    }

    public function getHostById($id)
    {
        $sql = "
            SELECT 
                host.*, 
                addr.addr,
                addr.ip,
                status.status AS host_status,
                domainMap.domain_id
            FROM host
            LEFT JOIN host_addr AS addr ON host.id = addr.host_id
            LEFT JOIN host_status AS status ON host.id = status.host_id
            LEFT JOIN domain_host_map AS domainMap ON host.id = domainMap.host_id
            WHERE host.id = ?
        ";
        
        return $this->db->select($sql, [$id])->fetch();
    }
    
    public function getHostByNom($name)
    {
        $result = $this->db->select('SELECT id FROM host WHERE name = ?', [$name]);
		
        if (is_array($result)) {
            return $result;
        } else if (is_object($result) && method_exists($result, 'fetch')) {
            return $result->fetch();
        }

        return null;
    }
	
    public function deleteHost($id)
    {
        $this->db->delete('DELETE FROM host WHERE id = ?', [$id]);
        return true;
    }
}