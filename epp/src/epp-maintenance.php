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

function processMaintenanceInfo($conn, $db, $xml, $trans): void
{
    $ns = 'urn:ietf:params:xml:ns:epp:maintenance-1.0';
    $clTRID = (string) $xml->command->clTRID;
    $info = $xml->command->info->children($ns)->info;
    $query = $info->children($ns);
    $isList = isset($query->list);
    $id = isset($query->id) ? (string) $query->id : '';
    if ((!$isList && $id === '') || ($isList && $id !== '')) {
        sendEppError($conn, $db, 2003, 'Specify maint:list or maint:id', $clTRID, $trans);
        return;
    }

    try {
        if ($isList) {
            // RFC 9167 allows server policy to include completed, non-deleted events.
            $stmt = $db->query('SELECT item_json FROM maintenance WHERE deleted = FALSE ORDER BY id');
            $items = [];
            while ($json = $stmt->fetchColumn()) {
                $items[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            }
            $stmt->closeCursor();
            $response = ['maintenance_list' => $items];
        } else {
            $stmt = $db->prepare('SELECT item_json FROM maintenance WHERE id = :id AND deleted = FALSE');
            $stmt->execute(['id' => $id]);
            $json = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($json === false) {
                sendEppError($conn, $db, 2303, 'Maintenance object does not exist', $clTRID, $trans);
                return;
            }
            $response = ['maintenance' => json_decode($json, true, 512, JSON_THROW_ON_ERROR)];
        }
        $response += [
            'command' => 'info_maintenance',
            'resultCode' => 1000,
            'clTRID' => $clTRID,
            'svTRID' => generateSvTRID(),
        ];
        $epp = new EPP\EppWriter();
        $resultXml = $epp->epp_writer($response);
        updateTransaction($db, 'info', null, $isList ? null : $id,
            1000, 'Command completed successfully', $response['svTRID'], $resultXml, $trans);
        sendEppResponse($conn, $resultXml);
    } catch (\Throwable $e) {
        sendEppError($conn, $db, 2400, 'Maintenance data error', $clTRID, $trans);
    }
}
