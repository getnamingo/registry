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

    public function createDomain($id, $name, $tldid, $registrant, $crdate, $exdate, $update, $clid, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate, $idnlang, $delTime, $resTime, $rgpstatus, $rgppostData, $rgpdelTime, $rgpresTime, $rgpresReason, $rgpstatement1, $rgpstatement2, $rgpother, $addPeriod, $autoRenewPeriod, $renewPeriod, $transferPeriod, $renewedDate)
    {
        $id = $this->db->quote($id), $name = $this->db->quote($name), $tldid = $this->db->quote($tldid), $registrant = $this->db->quote($registrant), $crdate = $this->db->quote($crdate), $exdate = $this->db->quote($exdate), $update = $this->db->quote($update), $clid = $this->db->quote($clid), $crid = $this->db->quote($crid), $upid = $this->db->quote($upid), $trdate = $this->db->quote($trdate), $trstatus = $this->db->quote($trstatus), $reid = $this->db->quote($reid), $redate = $this->db->quote($redate), $acid = $this->db->quote($acid), $acdate = $this->db->quote($acdate), $transfer_exdate = $this->db->quote($transfer_exdate), $idnlang = $this->db->quote($idnlang), $delTime = $this->db->quote($delTime), $resTime = $this->db->quote($resTime), $rgpstatus = $this->db->quote($rgpstatus), $rgppostData = $this->db->quote($rgppostData), $rgpdelTime = $this->db->quote($rgpdelTime), $rgpresTime = $this->db->quote($rgpresTime), $rgpresReason = $this->db->quote($rgpresReason), $rgpstatement1 = $this->db->quote($rgpstatement1), $rgpstatement2 = $this->db->quote($rgpstatement2), $rgpother = $this->db->quote($rgpother), $addPeriod = $this->db->quote($addPeriod), $autoRenewPeriod = $this->db->quote($autoRenewPeriod), $renewPeriod = $this->db->quote($renewPeriod), $transferPeriod = $this->db->quote($transferPeriod), $renewedDate = $this->db->quote($renewedDate)

        $this->db->insert('INSERT INTO domain (id, name, tldid, registrant, crdate, exdate, update, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate, idnlang, delTime, resTime, rgpstatus, rgppostData, rgpdelTime, rgpresTime, rgpresReason, rgpstatement1, rgpstatement2, rgpother, addPeriod, autoRenewPeriod, renewPeriod, transferPeriod, renewedDate) VALUES ($id, $name, $tldid, $registrant, $crdate, $exdate, $update, $clid, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate, $idnlang, $delTime, $resTime, $rgpstatus, $rgppostData, $rgpdelTime, $rgpresTime, $rgpresReason, $rgpstatement1, $rgpstatement2, $rgpother, $addPeriod, $autoRenewPeriod, $renewPeriod, $transferPeriod, $renewedDate)');
        
        return $this->db->lastInsertId();
    }

    public function updateDomain($id, $id, $name, $tldid, $registrant, $crdate, $exdate, $update, $clid, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate, $idnlang, $delTime, $resTime, $rgpstatus, $rgppostData, $rgpdelTime, $rgpresTime, $rgpresReason, $rgpstatement1, $rgpstatement2, $rgpother, $addPeriod, $autoRenewPeriod, $renewPeriod, $transferPeriod, $renewedDate)
    {
        $id = $this->db->quote($id), $name = $this->db->quote($name), $tldid = $this->db->quote($tldid), $registrant = $this->db->quote($registrant), $crdate = $this->db->quote($crdate), $exdate = $this->db->quote($exdate), $update = $this->db->quote($update), $clid = $this->db->quote($clid), $crid = $this->db->quote($crid), $upid = $this->db->quote($upid), $trdate = $this->db->quote($trdate), $trstatus = $this->db->quote($trstatus), $reid = $this->db->quote($reid), $redate = $this->db->quote($redate), $acid = $this->db->quote($acid), $acdate = $this->db->quote($acdate), $transfer_exdate = $this->db->quote($transfer_exdate), $idnlang = $this->db->quote($idnlang), $delTime = $this->db->quote($delTime), $resTime = $this->db->quote($resTime), $rgpstatus = $this->db->quote($rgpstatus), $rgppostData = $this->db->quote($rgppostData), $rgpdelTime = $this->db->quote($rgpdelTime), $rgpresTime = $this->db->quote($rgpresTime), $rgpresReason = $this->db->quote($rgpresReason), $rgpstatement1 = $this->db->quote($rgpstatement1), $rgpstatement2 = $this->db->quote($rgpstatement2), $rgpother = $this->db->quote($rgpother), $addPeriod = $this->db->quote($addPeriod), $autoRenewPeriod = $this->db->quote($autoRenewPeriod), $renewPeriod = $this->db->quote($renewPeriod), $transferPeriod = $this->db->quote($transferPeriod), $renewedDate = $this->db->quote($renewedDate)

        $this->db->update('UPDATE domain SET id = $id, name = $name, tldid = $tldid, registrant = $registrant, crdate = $crdate, exdate = $exdate, update = $update, clid = $clid, crid = $crid, upid = $upid, trdate = $trdate, trstatus = $trstatus, reid = $reid, redate = $redate, acid = $acid, acdate = $acdate, transfer_exdate = $transfer_exdate, idnlang = $idnlang, delTime = $delTime, resTime = $resTime, rgpstatus = $rgpstatus, rgppostData = $rgppostData, rgpdelTime = $rgpdelTime, rgpresTime = $rgpresTime, rgpresReason = $rgpresReason, rgpstatement1 = $rgpstatement1, rgpstatement2 = $rgpstatement2, rgpother = $rgpother, addPeriod = $addPeriod, autoRenewPeriod = $autoRenewPeriod, renewPeriod = $renewPeriod, transferPeriod = $transferPeriod, renewedDate = $renewedDate WHERE id = ?', [$id]);
        
        return true;
    }

    public function deleteDomain($id)
    {
        $this->db->delete('DELETE FROM domain WHERE id = ?', [$id]);
        return true;
    }
}