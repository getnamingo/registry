<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class Contact
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllContact()
    {
        $sql = "
            SELECT 
                contact.*, 
                postalInfo.*,
                status.status AS contact_status,
                CASE WHEN EXISTS (
                    SELECT 1 FROM domain_contact_map WHERE domain_contact_map.contact_id = contact.id
                ) THEN 1 ELSE 0 END AS has_domain_contact_mapping
            FROM contact
            LEFT JOIN contact_postalInfo AS postalInfo ON contact.id = postalInfo.contact_id
            LEFT JOIN contact_status AS status ON contact.id = status.contact_id
        ";
        
        return $this->db->select($sql);
    }

    public function getContactById($id)
    {
        $sql = "
            SELECT 
                contact.*, 
                postalInfo.*,
                status.status AS contact_status
            FROM contact
            LEFT JOIN contact_postalInfo AS postalInfo ON contact.id = postalInfo.contact_id
            LEFT JOIN contact_status AS status ON contact.id = status.contact_id
            WHERE contact.id = ?
        ";
        
        return $this->db->select($sql, [$id])->fetch();
    }

    public function deleteContact($id)
    {
        $this->db->delete('DELETE FROM contact WHERE id = ?', [$id]);
        return true;
    }
}