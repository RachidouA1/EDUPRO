<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$anneeId = (int)($_GET['annee_id'] ?? 0);

if (!$anneeId) {
    echo json_encode([]);
    exit;
}

$db      = getDB();
$ecoleId = getEcoleId();

// Filter via JOIN on annees_academiques.ecole_id to prevent cross-school data
if ($ecoleId > 0) {
    $stmt = $db->prepare("
        SELECT s.id, s.nom FROM semestres s
        JOIN annees_academiques a ON a.id = s.annee_id
        WHERE s.annee_id = ? AND a.ecole_id = ?
        ORDER BY s.id
    ");
    $stmt->execute([$anneeId, $ecoleId]);
} else {
    $stmt = $db->prepare("SELECT id, nom FROM semestres WHERE annee_id = ? ORDER BY id");
    $stmt->execute([$anneeId]);
}
echo json_encode($stmt->fetchAll());
