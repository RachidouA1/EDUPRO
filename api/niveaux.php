<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$filiereId = (int)($_GET['filiere_id'] ?? 0);

if (!$filiereId) {
    echo json_encode([]);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, nom, ordre FROM niveaux WHERE filiere_id = ? ORDER BY ordre");
$stmt->execute([$filiereId]);
echo json_encode($stmt->fetchAll());
