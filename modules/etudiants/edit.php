<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite']);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM etudiants WHERE id = ?");
$stmt->execute([$id]);
$etudiant = $stmt->fetch();

if (!$etudiant) {
    setFlash('error', 'Étudiant introuvable.');
    redirect('/modules/etudiants/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide.';
    } else {
        $data = [
            'nom'               => sanitize($_POST['nom']               ?? ''),
            'prenom'            => sanitize($_POST['prenom']            ?? ''),
            'sexe'              => in_array($_POST['sexe'] ?? '', ['M','F']) ? $_POST['sexe'] : '',
            'date_naissance'    => sanitize($_POST['date_naissance']    ?? ''),
            'lieu_naissance'    => sanitize($_POST['lieu_naissance']    ?? ''),
            'telephone'         => sanitize($_POST['telephone']         ?? ''),
            'email'             => filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL),
            'adresse'           => sanitize($_POST['adresse']           ?? ''),
            'nom_tuteur'        => sanitize($_POST['nom_tuteur']        ?? ''),
            'telephone_tuteur'  => sanitize($_POST['telephone_tuteur']  ?? ''),
            'filiere_id'        => (int)($_POST['filiere_id']           ?? 0),
            'niveau_id'         => (int)($_POST['niveau_id']            ?? 0),
            'annee_id'          => (int)($_POST['annee_id']             ?? 0),
            'statut'            => in_array($_POST['statut'] ?? '', ['actif','transfere','exclu','diplome']) ? $_POST['statut'] : 'actif',
        ];

        if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
        if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';
        if (empty($data['sexe']))   $errors[] = 'Le sexe est obligatoire.';

        if (empty($errors)) {
            $stmt = $db->prepare("
                UPDATE etudiants SET
                    nom=?, prenom=?, sexe=?, date_naissance=?, lieu_naissance=?,
                    telephone=?, email=?, adresse=?, nom_tuteur=?, telephone_tuteur=?,
                    filiere_id=?, niveau_id=?, annee_id=?, statut=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['nom'], $data['prenom'], $data['sexe'],
                $data['date_naissance'] ?: null, $data['lieu_naissance'] ?: null,
                $data['telephone'] ?: null, $data['email'] ?: null,
                $data['adresse'] ?: null, $data['nom_tuteur'] ?: null,
                $data['telephone_tuteur'] ?: null,
                $data['filiere_id'] ?: null, $data['niveau_id'] ?: null,
                $data['annee_id'] ?: null, $data['statut'],
                $id
            ]);

            // Synchroniser le compte utilisateur étudiant (nom, prenom, email)
            $uSel = $db->prepare("SELECT id, email FROM users WHERE role='etudiant' AND reference_id=?");
            $uSel->execute([$id]);
            $userRow = $uSel->fetch();
            if ($userRow) {
                // Mise à jour de l'email réel si fourni, sinon conserver le placeholder
                $newUserEmail = !empty($data['email']) ? $data['email'] : $userRow['email'];
                $db->prepare("UPDATE users SET nom=?, prenom=?, email=? WHERE id=?")
                   ->execute([$data['nom'], $data['prenom'], $newUserEmail, $userRow['id']]);
            } else {
                // Créer le compte si absent (étudiant créé avant la correction)
                $userEmail = !empty($data['email']) ? $data['email'] : strtolower($etudiant['matricule']) . '@epsi.local';
                $tempPass  = password_hash('Etudiant@2025', PASSWORD_DEFAULT);
                $db->prepare("INSERT IGNORE INTO users (nom, prenom, email, password, role, reference_id) VALUES (?,?,?,?,'etudiant',?)")
                   ->execute([$data['nom'], $data['prenom'], $userEmail, $tempPass, $id]);
            }

            setFlash('success', 'Dossier étudiant mis à jour.');
            redirect('/modules/etudiants/view.php?id=' . $id);
        }
    }
}

$filieres = getFilieres();
$annees   = getAnneesAcademiques();
$niveauxFil = getNiveaux($etudiant['filiere_id']);

$pageTitle = 'Modifier ' . h($etudiant['prenom'] . ' ' . $etudiant['nom']);
$breadcrumb = [
    'Étudiants' => APP_URL . '/modules/etudiants/index.php',
    h($etudiant['prenom'] . ' ' . $etudiant['nom']) => APP_URL . '/modules/etudiants/view.php?id=' . $id,
    'Modifier' => null
];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-edit me-2 text-primary"></i>Modifier l'étudiant</h2>
</div>

<form method="POST" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>Informations personnelles</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" value="<?= h($_POST['nom'] ?? $etudiant['nom']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Prénom(s) <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control" value="<?= h($_POST['prenom'] ?? $etudiant['prenom']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sexe <span class="text-danger">*</span></label>
              <select name="sexe" class="form-select" required>
                <option value="">-- Sélectionner --</option>
                <option value="M" <?= ($_POST['sexe'] ?? $etudiant['sexe']) === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= ($_POST['sexe'] ?? $etudiant['sexe']) === 'F' ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naissance" class="form-control" value="<?= h($_POST['date_naissance'] ?? $etudiant['date_naissance']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Lieu de naissance</label>
              <input type="text" name="lieu_naissance" class="form-control" value="<?= h($_POST['lieu_naissance'] ?? $etudiant['lieu_naissance']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Téléphone</label>
              <input type="tel" name="telephone" class="form-control" value="<?= h($_POST['telephone'] ?? $etudiant['telephone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? $etudiant['email']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Adresse</label>
              <textarea name="adresse" class="form-control" rows="2"><?= h($_POST['adresse'] ?? $etudiant['adresse']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nom du tuteur</label>
              <input type="text" name="nom_tuteur" class="form-control" value="<?= h($_POST['nom_tuteur'] ?? $etudiant['nom_tuteur']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Téléphone tuteur</label>
              <input type="tel" name="telephone_tuteur" class="form-control" value="<?= h($_POST['telephone_tuteur'] ?? $etudiant['telephone_tuteur']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><i class="fas fa-graduation-cap me-2 text-primary"></i>Scolarité & Statut</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Filière <span class="text-danger">*</span></label>
            <select name="filiere_id" id="filiere_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($filieres as $f): ?>
                <option value="<?= $f['id'] ?>" <?= ($_POST['filiere_id'] ?? $etudiant['filiere_id']) == $f['id'] ? 'selected' : '' ?>>
                  <?= h($f['code']) ?> – <?= h($f['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Niveau <span class="text-danger">*</span></label>
            <select name="niveau_id" id="niveau_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($niveauxFil as $n): ?>
                <option value="<?= $n['id'] ?>" <?= ($_POST['niveau_id'] ?? $etudiant['niveau_id']) == $n['id'] ? 'selected' : '' ?>>
                  <?= h($n['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Année académique</label>
            <select name="annee_id" class="form-select">
              <option value="">-- Sélectionner --</option>
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($_POST['annee_id'] ?? $etudiant['annee_id']) == $a['id'] ? 'selected' : '' ?>>
                  <?= h($a['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Statut</label>
            <select name="statut" class="form-select">
              <?php foreach (['actif'=>'Actif','transfere'=>'Transféré','exclu'=>'Exclu','diplome'=>'Diplômé'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($_POST['statut'] ?? $etudiant['statut']) === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-info py-2 fs-sm mb-0">
            <i class="fas fa-id-badge me-2"></i>Matricule : <strong><?= h($etudiant['matricule']) ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/modules/etudiants/view.php?id=<?= $id ?>" class="btn btn-light px-4"><i class="fas fa-times me-2"></i>Annuler</a>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
