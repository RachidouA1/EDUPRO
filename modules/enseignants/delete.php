<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    getDB()->prepare("DELETE FROM enseignants WHERE id=?")->execute([$id]);
    setFlash('success', 'Enseignant supprimé.');
}
redirect('/modules/enseignants/index.php');
