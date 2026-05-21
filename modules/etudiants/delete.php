<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $db = getDB();
    $db->prepare("DELETE FROM etudiants WHERE id = ?")->execute([$id]);
    setFlash('success', 'Étudiant supprimé.');
}
redirect('/modules/etudiants/index.php');
