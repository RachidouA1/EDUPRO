<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite']);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

// Inline migration : colonne photo
try { $db->exec("ALTER TABLE etudiants ADD COLUMN photo VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}

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
            'matricule'         => strtoupper(sanitize($_POST['matricule']         ?? '')),
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

        if (empty($data['matricule'])) $errors[] = 'Le matricule est obligatoire.';
        if (empty($data['nom']))       $errors[] = 'Le nom est obligatoire.';
        if (empty($data['prenom']))    $errors[] = 'Le prénom est obligatoire.';
        if (empty($data['sexe']))      $errors[] = 'Le sexe est obligatoire.';

        // Vérifier l'unicité du matricule (en excluant l'étudiant courant)
        if (!empty($data['matricule']) && $data['matricule'] !== strtoupper($etudiant['matricule'])) {
            $chk = $db->prepare("SELECT id FROM etudiants WHERE matricule = ? AND id != ?");
            $chk->execute([$data['matricule'], $id]);
            if ($chk->fetch()) $errors[] = 'Ce matricule est déjà utilisé par un autre étudiant.';
        }

        // Gestion photo
        $photoFile    = $_FILES['photo'] ?? null;
        $hasNewPhoto  = $photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['name']);
        $deletePhoto  = !empty($_POST['delete_photo']);

        if ($hasNewPhoto) {
            if ($photoFile['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Erreur lors de l\'envoi de la photo.';
                $hasNewPhoto = false;
            } elseif ($photoFile['size'] > 2 * 1024 * 1024) {
                $errors[] = 'La photo ne doit pas dépasser 2 Mo.';
                $hasNewPhoto = false;
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($photoFile['tmp_name']);
                if (!in_array($mimeType, ['image/jpeg','image/jpg','image/png','image/gif','image/webp'])) {
                    $errors[] = 'Format invalide. Acceptés : JPG, PNG, GIF, WEBP.';
                    $hasNewPhoto = false;
                }
            }
        }

        if (empty($errors)) {
            // Déterminer la nouvelle valeur de la photo
            $newPhoto = $etudiant['photo']; // par défaut : conserver

            if ($deletePhoto) {
                // Supprimer l'ancien fichier physique
                if ($etudiant['photo'] && file_exists(APP_ROOT . '/assets/' . $etudiant['photo'])) {
                    @unlink(APP_ROOT . '/assets/' . $etudiant['photo']);
                }
                $newPhoto = null;
            }

            if ($hasNewPhoto) {
                // Supprimer l'ancienne photo si elle existe
                if ($etudiant['photo'] && file_exists(APP_ROOT . '/assets/' . $etudiant['photo'])) {
                    @unlink(APP_ROOT . '/assets/' . $etudiant['photo']);
                }
                $ext       = strtolower(pathinfo($photoFile['name'], PATHINFO_EXTENSION));
                $uploadDir = APP_ROOT . '/assets/uploads/etudiants/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName  = 'etu_' . $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($photoFile['tmp_name'], $uploadDir . $fileName)) {
                    $newPhoto = 'uploads/etudiants/' . $fileName;
                }
            }

            $stmt = $db->prepare("
                UPDATE etudiants SET
                    matricule=?, nom=?, prenom=?, sexe=?, date_naissance=?, lieu_naissance=?,
                    telephone=?, email=?, adresse=?, nom_tuteur=?, telephone_tuteur=?,
                    filiere_id=?, niveau_id=?, annee_id=?, statut=?, photo=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['matricule'],
                $data['nom'], $data['prenom'], $data['sexe'],
                $data['date_naissance'] ?: null, $data['lieu_naissance'] ?: null,
                $data['telephone'] ?: null, $data['email'] ?: null,
                $data['adresse'] ?: null, $data['nom_tuteur'] ?: null,
                $data['telephone_tuteur'] ?: null,
                $data['filiere_id'] ?: null, $data['niveau_id'] ?: null,
                $data['annee_id'] ?: null, $data['statut'],
                $newPhoto,
                $id
            ]);

            // Synchroniser le compte utilisateur étudiant
            $uSel = $db->prepare("SELECT id, email FROM users WHERE role='etudiant' AND reference_id=?");
            $uSel->execute([$id]);
            $userRow = $uSel->fetch();
            if ($userRow) {
                $newUserEmail = !empty($data['email']) ? $data['email'] : $userRow['email'];
                $db->prepare("UPDATE users SET nom=?, prenom=?, email=? WHERE id=?")
                   ->execute([$data['nom'], $data['prenom'], $newUserEmail, $userRow['id']]);
            } else {
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

$filieres   = getFilieres();
$annees     = getAnneesAcademiques();
$niveauxFil = getNiveaux($etudiant['filiere_id']);
$filCurrent = null;
foreach ($filieres as $_f) { if ($_f['id'] == $etudiant['filiere_id']) { $filCurrent = $_f; break; } }
if ($filCurrent && !empty($filCurrent['tronc_commun_id'])) {
    $niveauxFil = array_values(array_filter($niveauxFil, fn($n) => (int)$n['ordre'] >= 2));
}

$currentPhotoUrl = $etudiant['photo'] ? APP_URL . '/assets/' . $etudiant['photo'] : '';

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

<form method="POST" enctype="multipart/form-data" novalidate>
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

      <!-- Photo -->
      <div class="card mb-4">
        <div class="card-header"><i class="fas fa-camera me-2 text-primary"></i>Photo de l'étudiant</div>
        <div class="card-body text-center">

          <!-- Photo actuelle -->
          <div id="photoCurrentWrap" style="margin-bottom:.75rem;<?= $currentPhotoUrl ? '' : 'display:none' ?>">
            <img id="photoCurrent" src="<?= h($currentPhotoUrl) ?>" alt="Photo actuelle"
                 style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #dee2e6;box-shadow:0 2px 8px rgba(0,0,0,.1)">
            <div class="mt-2">
              <small class="text-muted">Photo actuelle</small>
            </div>
            <?php if ($etudiant['photo']): ?>
            <div class="form-check mt-2 justify-content-center d-flex gap-2 align-items-center">
              <input class="form-check-input" type="checkbox" name="delete_photo" id="deletePhoto" value="1">
              <label class="form-check-label text-danger" for="deletePhoto" style="font-size:.82rem">
                <i class="fas fa-trash me-1"></i>Supprimer la photo
              </label>
            </div>
            <?php endif; ?>
          </div>

          <!-- Aperçu nouvelle photo -->
          <div id="photoPreviewWrap" style="margin-bottom:.75rem;display:none">
            <img id="photoPreview" src="" alt="Aperçu"
                 style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px dashed #1a73e8;box-shadow:0 2px 8px rgba(0,0,0,.1)">
            <div class="mt-1"><small class="text-primary">Nouvelle photo</small></div>
          </div>

          <label class="form-label d-block"><?= $currentPhotoUrl ? 'Remplacer la photo' : 'Ajouter une photo' ?></label>
          <input type="file" name="photo" id="photoInput" class="form-control form-control-sm"
                 accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                 onchange="previewPhoto(this)">
          <small class="text-muted d-block mt-1">JPG, PNG, WEBP — max 2 Mo</small>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="fas fa-graduation-cap me-2 text-primary"></i>Scolarité & Statut</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Filière <span class="text-danger">*</span></label>
            <select name="filiere_id" id="filiere_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($filieres as $f): ?>
                <option value="<?= $f['id'] ?>"
                        data-tc-id="<?= (int)($f['tronc_commun_id'] ?? 0) ?>"
                        <?= ($_POST['filiere_id'] ?? $etudiant['filiere_id']) == $f['id'] ? 'selected' : '' ?>>
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
          <div class="mb-0">
            <label class="form-label">Matricule <span class="text-danger">*</span></label>
            <input type="text" name="matricule" class="form-control"
                   value="<?= h($_POST['matricule'] ?? $etudiant['matricule']) ?>"
                   style="text-transform:uppercase"
                   oninput="this.value=this.value.toUpperCase()"
                   required>
            <div class="form-text">Modifiable — doit être unique.</div>
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

<?php
$extraScripts = <<<'JS'
<script>
function previewPhoto(input) {
    const wrap = document.getElementById('photoPreviewWrap');
    const img  = document.getElementById('photoPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; wrap.style.display = ''; };
        reader.readAsDataURL(input.files[0]);
    } else {
        wrap.style.display = 'none';
    }
}
// Si on coche "Supprimer la photo", désactiver le champ d'upload
const delCb = document.getElementById('deletePhoto');
if (delCb) {
    delCb.addEventListener('change', function() {
        document.getElementById('photoInput').disabled = this.checked;
        document.getElementById('photoPreviewWrap').style.display = 'none';
        const cur = document.getElementById('photoCurrent');
        if (cur) cur.style.opacity = this.checked ? '.3' : '1';
    });
}
</script>
JS;
include APP_ROOT . '/includes/footer.php';
?>
