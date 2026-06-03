<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'comptable']);

$db = getDB();
$errors = [];

// Inline migration : colonne photo
try { $db->exec("ALTER TABLE etudiants ADD COLUMN photo VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}

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
            'statut'            => 'actif',
        ];

        if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
        if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';
        if (empty($data['sexe']))   $errors[] = 'Le sexe est obligatoire.';
        if (!$data['filiere_id'])   $errors[] = 'La filière est obligatoire.';
        if (!$data['niveau_id'])    $errors[] = 'Le niveau est obligatoire.';
        if (!$data['annee_id'])     $errors[] = 'L\'année académique est obligatoire.';

        // Validation de la photo (si fournie)
        $photoFile = $_FILES['photo'] ?? null;
        $hasPhoto  = $photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['name']);
        if ($hasPhoto) {
            if ($photoFile['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Erreur lors de l\'envoi de la photo (code ' . $photoFile['error'] . ').';
                $hasPhoto = false;
            } elseif ($photoFile['size'] > 2 * 1024 * 1024) {
                $errors[] = 'La photo ne doit pas dépasser 2 Mo.';
                $hasPhoto = false;
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($photoFile['tmp_name']);
                if (!in_array($mimeType, ['image/jpeg','image/jpg','image/png','image/gif','image/webp'])) {
                    $errors[] = 'Format de photo invalide. Acceptés : JPG, PNG, GIF, WEBP.';
                    $hasPhoto = false;
                }
            }
        }

        if (empty($errors)) {
            $matricule = generateMatricule('ETU');

            // INSERT étudiant (sans photo d'abord)
            $stmt = $db->prepare("
                INSERT INTO etudiants (matricule, nom, prenom, sexe, date_naissance, lieu_naissance,
                    telephone, email, adresse, nom_tuteur, telephone_tuteur,
                    filiere_id, niveau_id, annee_id, statut)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $matricule,
                $data['nom'], $data['prenom'], $data['sexe'],
                $data['date_naissance'] ?: null,
                $data['lieu_naissance'] ?: null,
                $data['telephone'] ?: null,
                $data['email'] ?: null,
                $data['adresse'] ?: null,
                $data['nom_tuteur'] ?: null,
                $data['telephone_tuteur'] ?: null,
                $data['filiere_id'] ?: null,
                $data['niveau_id'] ?: null,
                $data['annee_id'] ?: null,
                'actif',
            ]);
            $newId = $db->lastInsertId();

            // Sauvegarde de la photo
            if ($hasPhoto) {
                $ext       = strtolower(pathinfo($photoFile['name'], PATHINFO_EXTENSION));
                $uploadDir = APP_ROOT . '/assets/uploads/etudiants/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName  = 'etu_' . $newId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($photoFile['tmp_name'], $uploadDir . $fileName)) {
                    $db->prepare("UPDATE etudiants SET photo=? WHERE id=?")
                       ->execute(['uploads/etudiants/' . $fileName, $newId]);
                }
            }

            // Créer le compte utilisateur étudiant
            $userEmail = !empty($data['email']) ? $data['email'] : strtolower($matricule) . '@epsi.local';
            $tempPass  = password_hash('Etudiant@2025', PASSWORD_DEFAULT);
            $uStmt = $db->prepare("INSERT IGNORE INTO users (nom, prenom, email, password, role, reference_id) VALUES (?,?,?,?,'etudiant',?)");
            $uStmt->execute([$data['nom'], $data['prenom'], $userEmail, $tempPass, $newId]);

            setFlash('success', "Étudiant {$data['prenom']} {$data['nom']} enregistré avec le matricule <strong>{$matricule}</strong>. Vous pouvez maintenant générer sa carte d'identité scolaire.");
            redirect('/modules/etudiants/view.php?id=' . $newId);
        }
    }
}

$filieres = getFilieres();
$annees   = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

$pageTitle = 'Nouvel étudiant';
$breadcrumb = ['Étudiants' => APP_URL . '/modules/etudiants/index.php', 'Ajouter' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-plus me-2 text-primary"></i>Enregistrer un étudiant</h2>
</div>

<form method="POST" enctype="multipart/form-data" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <!-- Informations personnelles -->
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
              <label class="form-label">Lieu de naissance</label>
              <input type="text" name="lieu_naissance" class="form-control" value="<?= h($_POST['lieu_naissance'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Téléphone</label>
              <input type="tel" name="telephone" class="form-control" value="<?= h($_POST['telephone'] ?? '') ?>" placeholder="77 XXX XX XX">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>" placeholder="exemple@mail.com">
            </div>
            <div class="col-12">
              <label class="form-label">Adresse</label>
              <textarea name="adresse" class="form-control" rows="2"><?= h($_POST['adresse'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Tuteur -->
      <div class="card mt-4">
        <div class="card-header"><i class="fas fa-users me-2 text-primary"></i>Informations du tuteur / parent</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nom du tuteur</label>
              <input type="text" name="nom_tuteur" class="form-control" value="<?= h($_POST['nom_tuteur'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Téléphone du tuteur</label>
              <input type="tel" name="telephone_tuteur" class="form-control" value="<?= h($_POST['telephone_tuteur'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Scolarité + Photo -->
    <div class="col-lg-4">

      <!-- Photo -->
      <div class="card mb-4">
        <div class="card-header"><i class="fas fa-camera me-2 text-primary"></i>Photo de l'étudiant</div>
        <div class="card-body text-center">
          <div id="photoPreviewWrap" style="margin-bottom:.75rem;display:none">
            <img id="photoPreview" src="" alt="Aperçu"
                 style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #dee2e6;box-shadow:0 2px 8px rgba(0,0,0,.1)">
          </div>
          <label class="form-label d-block">Photo (optionnelle)</label>
          <input type="file" name="photo" id="photoInput" class="form-control form-control-sm"
                 accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                 onchange="previewPhoto(this)">
          <small class="text-muted d-block mt-1">JPG, PNG, WEBP — max 2 Mo</small>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="fas fa-graduation-cap me-2 text-primary"></i>Scolarité</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Filière <span class="text-danger">*</span></label>
            <select name="filiere_id" id="filiere_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($filieres as $f): ?>
                <option value="<?= $f['id'] ?>"
                        data-tc-id="<?= (int)($f['tronc_commun_id'] ?? 0) ?>"
                        <?= ($_POST['filiere_id'] ?? '') == $f['id'] ? 'selected' : '' ?>>
                  <?= h($f['code']) ?> – <?= h($f['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Niveau <span class="text-danger">*</span></label>
            <select name="niveau_id" id="niveau_id" class="form-select" required>
              <option value="">-- Sélectionner une filière d'abord --</option>
              <?php if (!empty($_POST['filiere_id'])):
                $filPost = null;
                foreach ($filieres as $_f) { if ($_f['id'] == $_POST['filiere_id']) { $filPost = $_f; break; } }
                $niveauxPost = getNiveaux((int)$_POST['filiere_id']);
                if ($filPost && !empty($filPost['tronc_commun_id'])) {
                    $niveauxPost = array_values(array_filter($niveauxPost, fn($n) => (int)$n['ordre'] >= 2));
                }
              ?>
                <?php foreach ($niveauxPost as $n): ?>
                  <option value="<?= $n['id'] ?>" <?= ($_POST['niveau_id'] ?? '') == $n['id'] ? 'selected' : '' ?>>
                    <?= h($n['nom']) ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Année académique <span class="text-danger">*</span></label>
            <select name="annee_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>" <?= (($_POST['annee_id'] ?? $anneeActive['id'] ?? 0)) == $a['id'] ? 'selected' : '' ?>>
                  <?= h($a['libelle']) ?> <?= $a['actif'] ? '(En cours)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-body bg-light rounded">
          <h6 class="fw-bold mb-2"><i class="fas fa-info-circle text-primary me-2"></i>Matricule automatique</h6>
          <p class="text-muted mb-0" style="font-size:.85rem">
            Un matricule unique sera généré automatiquement au format <strong>ETUAAnnnn</strong>.
          </p>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary px-4">
      <i class="fas fa-save me-2"></i>Enregistrer
    </button>
    <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-light px-4">
      <i class="fas fa-times me-2"></i>Annuler
    </a>
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
</script>
JS;
include APP_ROOT . '/includes/footer.php';
?>
