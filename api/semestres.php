<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$anneeId = (int)($_GET['annee_id'] ?? 0);

if (!$anneeId) {
    echo json_encode([]);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, nom FROM semestres WHERE annee_id = ? ORDER BY id");
$stmt->execute([$anneeId]);
echo json_encode($stmt->fetchAll());
