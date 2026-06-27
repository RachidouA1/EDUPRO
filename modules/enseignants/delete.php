<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $id      = (int)($_POST['id'] ?? 0);
    $db      = getDB();
    $ecoleId = getEcoleId();
    if ($ecoleId > 0) {
        $db->prepare("DELETE FROM enseignants WHERE id=? AND ecole_id=?")->execute([$id, $ecoleId]);
    } else {
        $db->prepare("DELETE FROM enseignants WHERE id=?")->execute([$id]);
    }
    setFlash('success', 'Enseignant supprimé.');
}
redirect('/modules/enseignants/index.php');
