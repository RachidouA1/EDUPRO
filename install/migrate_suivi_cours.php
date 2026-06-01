<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('admin');

$db = getDB();
$results = [];

// seances_cours
try {
    $db->exec("CREATE TABLE IF NOT EXISTS seances_cours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        emploi_slot_id INT NOT NULL,
        enseignant_id INT NOT NULL,
        matiere_id INT NOT NULL,
        filiere_id INT NOT NULL,
        niveau_id INT NULL,
        annee_id INT NOT NULL,
        date_seance DATE NOT NULL,
        heure_debut TIME NOT NULL,
        heure_fin TIME NOT NULL,
        duree_heures DECIMAL(4,2) NOT NULL DEFAULT 2.00,
        notes TEXT,
        marked_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_seance_slot (emploi_slot_id),
        FOREIGN KEY (emploi_slot_id) REFERENCES emplois_slots(id) ON DELETE CASCADE,
        FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
        FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE,
        FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE CASCADE,
        FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $results[] = ['success', 'Table <code>seances_cours</code> créée ou déjà existante.'];
} catch (PDOException $e) {
    $results[] = ['danger', 'seances_cours : ' . h($e->getMessage())];
}

// demandes_paiement_enseignant
try {
    $db->exec("CREATE TABLE IF NOT EXISTS demandes_paiement_enseignant (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enseignant_id INT NOT NULL,
        coordinateur_id INT NOT NULL,
        annee_id INT NULL,
        date_debut DATE NOT NULL,
        date_fin DATE NOT NULL,
        total_heures DECIMAL(6,2) NOT NULL DEFAULT 0,
        details_matieres TEXT,
        statut ENUM('en_attente','traite','rejete') DEFAULT 'en_attente',
        notes_coordinateur TEXT,
        notes_comptable TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        traite_at TIMESTAMP NULL,
        traite_par INT NULL,
        FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
        FOREIGN KEY (coordinateur_id) REFERENCES users(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $results[] = ['success', 'Table <code>demandes_paiement_enseignant</code> créée ou déjà existante.'];
} catch (PDOException $e) {
    $results[] = ['danger', 'demandes_paiement_enseignant : ' . h($e->getMessage())];
}

$pageTitle  = 'Migration — Suivi des Cours';
$breadcrumb = ['Installation' => null, 'Migration Suivi Cours' => null];
include APP_ROOT . '/includes/header.php';
?>
<div class="page-header">
  <h2><i class="fas fa-database me-2 text-primary"></i>Migration — Suivi des Cours</h2>
</div>
<?php foreach ($results as [$type, $msg]): ?>
<div class="alert alert-<?= $type ?> d-flex align-items-center gap-2">
  <i class="fas fa-<?= $type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
  <span><?= $msg ?></span>
</div>
<?php endforeach; ?>
<a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary">
  <i class="fas fa-home me-2"></i>Retour au tableau de bord
</a>
<?php include APP_ROOT . '/includes/footer.php'; ?>
