<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/modules/etudiants/absences.php');
}
if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
    setFlash('error', 'Jeton de sécurité invalide.');
    redirect('/modules/etudiants/absences.php');
}

$db      = getDB();
$ecoleId = getEcoleId();
$absId   = (int)($_POST['abs_id'] ?? 0);

if ($absId > 0) {
    $where  = $ecoleId > 0 ? " AND ecole_id=?" : "";
    $params = [$absId];
    if ($ecoleId > 0) $params[] = $ecoleId;
    $db->prepare("DELETE FROM absences WHERE id=?" . $where)->execute($params);
    setFlash('success', 'Absence supprimée.');
}

$back = $_POST['back'] ?? '';
redirect($back ?: '/modules/etudiants/absences.php');
