<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    getDB()->prepare("UPDATE enseignants SET actif=0 WHERE id=?")->execute([$id]);
    setFlash('success', 'Enseignant désactivé.');
}
redirect('/modules/enseignants/index.php');
