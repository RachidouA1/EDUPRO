<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

if (!verifyCsrfToken($_GET['csrf'] ?? '')) {
    setFlash('error', 'Jeton invalide.');
    redirect('/modules/superadmin/index.php');
}

$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {
    // Sortir du contexte école (revenir vue globale)
    $_SESSION['ecole_id']         = 0;
    $_SESSION['ecole']            = null;
    $_SESSION['user']['ecole_id'] = null;
    setFlash('info', 'Vous êtes revenu en vue globale SuperAdmin.');
    redirect('/modules/superadmin/index.php');
}

if (!switchEcole($id)) {
    setFlash('error', 'École introuvable ou inactive.');
    redirect('/modules/superadmin/index.php');
}

setFlash('success', 'Vous gérez maintenant : <strong>' . h(getCurrentEcole()['nom'] ?? '') . '</strong>');
redirect('/dashboard.php');
