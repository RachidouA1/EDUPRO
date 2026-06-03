<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

$db     = getDB();
$id     = (int)($_GET['id'] ?? 0);
$errors = [];
$ecole  = null;

if ($id) {
    $stmt = $db->prepare("SELECT * FROM ecoles WHERE id = ?");
    $stmt->execute([$id]);
    $ecole = $stmt->fetch();
    if (!$ecole) { setFlash('error', 'École introuvable.'); redirect('/modules/superadmin/index.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $code    = strtoupper(sanitize($_POST['code'] ?? ''));
        $nom     = sanitize($_POST['nom'] ?? '');
        $slogan  = sanitize($_POST['slogan'] ?? '');
        $adresse = sanitize($_POST['adresse'] ?? '');
        $ville   = sanitize($_POST['ville'] ?? '');
        $pays    = sanitize($_POST['pays'] ?? 'Niger');
        $tel     = sanitize($_POST['telephone'] ?? '');
        $email   = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $primary = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['theme_primaire'] ?? '') ? $_POST['theme_primaire'] : '#1a73e8';
        $sidebar = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['theme_sidebar']  ?? '') ? $_POST['theme_sidebar']  : '#0f2d5c';
        $actif   = isset($_POST['actif']) ? 1 : 0;

        if (empty($code)) $errors[] = 'Le code est obligatoire.';
        if (empty($nom))  $errors[] = 'Le nom est obligatoire.';

        // Unique code check
        if (empty($errors)) {
            $chk = $db->prepare("SELECT id FROM ecoles WHERE code = ? AND id != ?");
            $chk->execute([$code, $id]);
            if ($chk->fetch()) $errors[] = 'Ce code école est déjà utilisé.';
        }

        // Logo upload
        $logoPath = $ecole['logo_path'] ?? '';
        if (!empty($_FILES['logo']['name'])) {
            $file    = $_FILES['logo'];
            $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Erreur upload logo.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Logo trop volumineux (max 2 Mo).';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!in_array($mime, $allowed)) {
                    $errors[] = 'Format logo non autorisé.';
                } else {
                    $uploadDir = APP_ROOT . '/assets/uploads/ecoles/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $fname = 'logo_' . ($id ?: 'new') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
                        if ($logoPath && file_exists(APP_ROOT . '/assets/' . $logoPath)) @unlink(APP_ROOT . '/assets/' . $logoPath);
                        $logoPath = 'uploads/ecoles/' . $fname;
                    }
                }
            }
        }

        if (empty($errors)) {
            if ($id) {
                $db->prepare("UPDATE ecoles SET code=?,nom=?,slogan=?,adresse=?,ville=?,pays=?,telephone=?,email=?,logo_path=?,theme_couleur_primaire=?,theme_couleur_sidebar=?,actif=? WHERE id=?")
                   ->execute([$code,$nom,$slogan,$adresse,$ville,$pays,$tel,$email,$logoPath,$primary,$sidebar,$actif,$id]);
                setFlash('success', 'École mise à jour.');
            } else {
                $db->prepare("INSERT INTO ecoles (code,nom,slogan,adresse,ville,pays,telephone,email,logo_path,theme_couleur_primaire,theme_couleur_sidebar,actif) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$code,$nom,$slogan,$adresse,$ville,$pays,$tel,$email,$logoPath,$primary,$sidebar,$actif]);
                setFlash('success', 'École créée avec succès.');
            }
            redirect('/modules/superadmin/index.php');
        }
    }
}

$pageTitle  = $id ? 'Modifier l\'école' : 'Nouvelle école';
$breadcrumb = ['SuperAdmin' => APP_URL . '/modules/superadmin/index.php', $pageTitle => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-school me-2 text-primary"></i><?= h($pageTitle) ?></h2>
  <a href="<?= APP_URL ?>/modules/superadmin/index.php" class="btn btn-light">
    <i class="fas fa-arrow-left me-1"></i>Retour
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($err) ?></div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <div class="row g-4">
    <!-- Informations principales -->
    <div class="col-lg-8">
      <div class="card mb-4">
        <div class="card-header"><i class="fas fa-info-circle me-2 text-primary"></i>Informations de l'établissement</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label fw-600">Code <span class="text-danger">*</span></label>
              <input type="text" name="code" class="form-control text-uppercase" maxlength="20"
                     placeholder="ECO001"
                     value="<?= h($_POST['code'] ?? $ecole['code'] ?? '') ?>" required>
              <div class="form-text">Identifiant unique, ex: ECO001</div>
            </div>
            <div class="col-md-9">
              <label class="form-label fw-600">Nom de l'établissement <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control"
                     value="<?= h($_POST['nom'] ?? $ecole['nom'] ?? '') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Slogan / Devise</label>
              <input type="text" name="slogan" class="form-control"
                     value="<?= h($_POST['slogan'] ?? $ecole['slogan'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Adresse</label>
              <input type="text" name="adresse" class="form-control"
                     value="<?= h($_POST['adresse'] ?? $ecole['adresse'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Ville</label>
              <input type="text" name="ville" class="form-control" placeholder="Tahoua"
                     value="<?= h($_POST['ville'] ?? $ecole['ville'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Pays</label>
              <input type="text" name="pays" class="form-control" placeholder="Niger"
                     value="<?= h($_POST['pays'] ?? $ecole['pays'] ?? 'Niger') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Téléphone</label>
              <input type="text" name="telephone" class="form-control"
                     value="<?= h($_POST['telephone'] ?? $ecole['telephone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($_POST['email'] ?? $ecole['email'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Thème couleurs -->
      <div class="card">
        <div class="card-header"><i class="fas fa-palette me-2 text-primary"></i>Thème de couleurs</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Couleur principale</label>
              <div class="input-group">
                <input type="color" name="theme_primaire" class="form-control form-control-color"
                       value="<?= h($_POST['theme_primaire'] ?? $ecole['theme_couleur_primaire'] ?? '#1a73e8') ?>">
                <input type="text" class="form-control" placeholder="#1a73e8"
                       value="<?= h($_POST['theme_primaire'] ?? $ecole['theme_couleur_primaire'] ?? '#1a73e8') ?>">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Couleur sidebar</label>
              <div class="input-group">
                <input type="color" name="theme_sidebar" class="form-control form-control-color"
                       value="<?= h($_POST['theme_sidebar'] ?? $ecole['theme_couleur_sidebar'] ?? '#0f2d5c') ?>">
                <input type="text" class="form-control" placeholder="#0f2d5c"
                       value="<?= h($_POST['theme_sidebar'] ?? $ecole['theme_couleur_sidebar'] ?? '#0f2d5c') ?>">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Logo + Statut -->
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><i class="fas fa-image me-2 text-primary"></i>Logo</div>
        <div class="card-body text-center">
          <?php $lp = $ecole['logo_path'] ?? ''; ?>
          <?php if ($lp && file_exists(APP_ROOT . '/assets/' . $lp)): ?>
            <img src="<?= APP_URL ?>/assets/<?= h($lp) ?>" alt="Logo actuel"
                 style="max-height:100px;max-width:200px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:8px;background:#fff;display:block;margin:0 auto .75rem">
          <?php endif; ?>
          <input type="file" name="logo" class="form-control form-control-sm"
                 accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
          <div class="form-text mt-1">JPG, PNG, SVG — max 2 Mo</div>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-header"><i class="fas fa-toggle-on me-2 text-primary"></i>Statut</div>
        <div class="card-body">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="actif" id="actifSwitch" value="1"
                   <?= ($_POST['actif'] ?? $ecole['actif'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="actifSwitch">École active</label>
          </div>
          <div class="form-text">Une école inactive ne peut pas être sélectionnée lors de la connexion.</div>
        </div>
      </div>

      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save me-2"></i><?= $id ? 'Enregistrer les modifications' : 'Créer l\'école' ?>
        </button>
        <a href="<?= APP_URL ?>/modules/superadmin/index.php" class="btn btn-light">Annuler</a>
      </div>
    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
