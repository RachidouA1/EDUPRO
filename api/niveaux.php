<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$filiereId = (int)($_GET['filiere_id'] ?? 0);

if (!$filiereId) {
    echo json_encode([]);
    exit;
}

$db      = getDB();
$ecoleId = getEcoleId();

// INF et SF ont un tronc_commun_id : les étudiants entrent directement en Année 2
// Also verify filière belongs to current school (prevents cross-school data leakage)
$fSql = "SELECT tronc_commun_id FROM filieres WHERE id = ?";
$fParams = [$filiereId];
if ($ecoleId > 0) { $fSql .= " AND ecole_id = ?"; $fParams[] = $ecoleId; }
$fStmt = $db->prepare($fSql);
$fStmt->execute($fParams);
$fil = $fStmt->fetch();

if (!$fil) {
    echo json_encode([]);
    exit;
}

$minOrdre = !empty($fil['tronc_commun_id']) ? 2 : 1;

$stmt = $db->prepare("SELECT id, nom, ordre FROM niveaux WHERE filiere_id = ? AND ordre >= ? ORDER BY ordre");
$stmt->execute([$filiereId, $minOrdre]);
echo json_encode($stmt->fetchAll());
