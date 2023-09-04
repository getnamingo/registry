<?php

namespace App\Models;

use Pinga\Db\PdoDatabase;

class Tickets
{
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    public function getAllTickets()
    {
        return $this->db->select('SELECT * FROM support_tickets');
    }

    public function getTicketsById($id)
    {
        return $this->db->select('SELECT * FROM support_tickets WHERE id = ?', [$id])->fetch();
    }

    public function createTickets($id, $user_id, $category_id, $subject, $message, $status, $priority, $reported_domain, $nature_of_abuse, $evidence, $relevant_urls, $date_of_incident, $date_created, $last_updated)
    {
        $id = $this->db->quote($id); $user_id = $this->db->quote($user_id); $category_id = $this->db->quote($category_id); $subject = $this->db->quote($subject); $message = $this->db->quote($message); $status = $this->db->quote($status); $priority = $this->db->quote($priority); $reported_domain = $this->db->quote($reported_domain); $nature_of_abuse = $this->db->quote($nature_of_abuse); $evidence = $this->db->quote($evidence); $relevant_urls = $this->db->quote($relevant_urls); $date_of_incident = $this->db->quote($date_of_incident); $date_created = $this->db->quote($date_created); $last_updated = $this->db->quote($last_updated);

        $this->db->insert('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES ($id, $user_id, $category_id, $subject, $message, $status, $priority, $reported_domain, $nature_of_abuse, $evidence, $relevant_urls, $date_of_incident, $date_created, $last_updated)');
        
        return $this->db->lastInsertId();
    }

    public function deleteTickets($id)
    {
        $this->db->delete('DELETE FROM support_tickets WHERE id = ?', [$id]);
        return true;
    }
}