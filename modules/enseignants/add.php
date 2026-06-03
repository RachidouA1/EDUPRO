<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $data = [
            'nom'              => sanitize($_POST['nom']             ?? ''),
            'prenom'           => sanitize($_POST['prenom']          ?? ''),
            'sexe'             => in_array($_POST['sexe'] ?? '', ['M','F']) ? $_POST['sexe'] : '',
            'date_naissance'   => sanitize($_POST['date_naissance']  ?? ''),
            'telephone'        => sanitize($_POST['telephone']       ?? ''),
            'email'            => filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL),
            'adresse'          => sanitize($_POST['adresse']         ?? ''),
            'specialite'       => sanitize($_POST['specialite']      ?? ''),
            'type_contrat'     => in_array($_POST['type_contrat'] ?? '', ['permanent','vacataire']) ? $_POST['type_contrat'] : 'permanent',
            'salaire_base'     => (float)($_POST['salaire_base']     ?? 0),
            'date_recrutement' => sanitize($_POST['date_recrutement']?? ''),
        ];

        if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
        if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';
        if (empty($data['sexe']))   $errors[] = 'Le sexe est obligatoire.';

        if (empty($errors)) {
            $matricule = generateMatricule('ENS');
            if ($ecoleId > 0) {
                $db->prepare("INSERT INTO enseignants (matricule, nom, prenom, sexe, date_naissance, telephone, email, adresse, specialite, type_contrat, salaire_base, date_recrutement, ecole_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                        $matricule, $data['nom'], $data['prenom'], $data['sexe'],
                        $data['date_naissance'] ?: null, $data['telephone'] ?: null,
                        $data['email'] ?: null, $data['adresse'] ?: null,
                        $data['specialite'] ?: null, $data['type_contrat'],
                        $data['salaire_base'], $data['date_recrutement'] ?: null, $ecoleId,
                   ]);
            } else {
                $db->prepare("INSERT INTO enseignants (matricule, nom, prenom, sexe, date_naissance, telephone, email, adresse, specialite, type_contrat, salaire_base, date_recrutement)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                        $matricule, $data['nom'], $data['prenom'], $data['sexe'],
                        $data['date_naissance'] ?: null, $data['telephone'] ?: null,
                        $data['email'] ?: null, $data['adresse'] ?: null,
                        $data['specialite'] ?: null, $data['type_contrat'],
                        $data['salaire_base'], $data['date_recrutement'] ?: null,
                   ]);
            }
            $newId = $db->lastInsertId();

            // Create teacher user account
            if (!empty($data['email'])) {
                $tmpPass = password_hash('Enseignant@2025', PASSWORD_DEFAULT);
                $db->prepare("INSERT IGNORE INTO users (nom, prenom, email, password, role, reference_id) VALUES (?,?,?,?,'enseignant',?)")
                   ->execute([$data['nom'], $data['prenom'], $data['email'], $tmpPass, $newId]);
            }

            setFlash('success', "Enseignant {$data['prenom']} {$data['nom']} enregistré (matricule: {$matricule}).");
            redirect('/modules/enseignants/index.php');
        }
    }
}

$pageTitle = 'Nouvel enseignant';
$breadcrumb = ['Enseignants' => APP_URL . '/modules/enseignants/index.php', 'Ajouter' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-tie me-2 text-primary"></i>Enregistrer un enseignant</h2>
</div>

<form method="POST" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>Informations personnelles</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" value="<?= h($_POST['nom'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Prénom(s) <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control" value="<?= h($_POST['prenom'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sexe <span class="text-danger">*</span></label>
              <select name="sexe" class="form-select" required>
                <option value="">-- Sélectionner --</option>
                <option value="M" <?= ($_POST['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= ($_POST['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naissance" class="form-control" value="<?= h($_POST['date_naissance'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Téléphone</label>
              <input type="tel" name="telephone" class="form-control" value="<?= h($_POST['telephone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Spécialité / Matières enseignées</label>
              <input type="text" name="specialite" class="form-control" placeholder="Ex : Anatomie, Soins infirmiers..." value="<?= h($_POST['specialite'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Adresse</label>
              <textarea name="adresse" class="form-control" rows="2"><?= h($_POST['adresse'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><i class="fas fa-briefcase me-2 text-primary"></i>Informations professionnelles</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Type de contrat</label>
            <select name="type_contrat" class="form-select">
              <option value="permanent"  <?= ($_POST['type_contrat'] ?? '') === 'permanent'  ? 'selected' : '' ?>>Permanent</option>
              <option value="vacataire"  <?= ($_POST['type_contrat'] ?? '') === 'vacataire'  ? 'selected' : '' ?>>Vacataire</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Salaire de base (FCFA)</label>
            <input type="number" name="salaire_base" class="form-control" min="0" step="1000" value="<?= h($_POST['salaire_base'] ?? '0') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Date de recrutement</label>
            <input type="date" name="date_recrutement" class="form-control" value="<?= h($_POST['date_recrutement'] ?? date('Y-m-d')) ?>">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/modules/enseignants/index.php" class="btn btn-light px-4"><i class="fas fa-times me-2"></i>Annuler</a>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
