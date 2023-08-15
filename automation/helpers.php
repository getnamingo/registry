<?php

function fetchCount($pdo, $tableName) {
    $stmt = $pdo->prepare("SELECT count(id) AS count FROM {$tableName};");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['count'];
}