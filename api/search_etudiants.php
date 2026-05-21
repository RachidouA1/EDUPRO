<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$q = sanitize($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT id, nom, prenom, matricule
    FROM etudiants
    WHERE (nom LIKE ? OR prenom LIKE ? OR matricule LIKE ?) AND statut = 'actif'
    ORDER BY nom, prenom
    LIMIT 10
");
$like = "%{$q}%";
$stmt->execute([$like, $like, $like]);
echo json_encode($stmt->fetchAll());
