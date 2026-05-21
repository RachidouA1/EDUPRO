<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

$db = getDB();

// Migration inline : créer la table si absente
try {
    $db->exec("CREATE TABLE IF NOT EXISTS parametres (
        id INT PRIMARY KEY AUTO_INCREMENT,
        cle VARCHAR(100) UNIQUE NOT NULL,
        valeur TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    // Insérer les valeurs par défaut manquantes
    $defaults = [
        ['etablissement_nom',       'École Privée de Santé Ibn Rochd'],
        ['etablissement_slogan',    'Excellence – Santé – Service'],
        ['etablissement_adresse',   'Dakar, Sénégal'],
        ['etablissement_telephone', ''],
        ['etablissement_email',     ''],
        ['theme_couleur_primaire',  '#1a73e8'],
        ['theme_couleur_sidebar',   '#0f2d5c'],
        ['logo_path',               ''],
    ];
    $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?,?)");
    foreach ($defaults as $d) $ins->execute($d);
} catch (PDOException $e) {}

$errors  = [];
$success = false;

// ─── Suppression du logo ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_logo'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide.';
    } else {
        $old = getParam('logo_path');
        if ($old && file_exists(APP_ROOT . '/assets/' . $old)) {
            unlink(APP_ROOT . '/assets/' . $old);
        }
        setParam('logo_path', '');
        setFlash('success', 'Logo supprimé.');
        redirect('/modules/administration/parametres.php');
    }
}

// ─── Sauvegarde des paramètres ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_params'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide.';
    } else {

        // Informations établissement
        setParam('etablissement_nom',       sanitize($_POST['etablissement_nom']       ?? ''));
        setParam('etablissement_slogan',    sanitize($_POST['etablissement_slogan']    ?? ''));
        setParam('etablissement_adresse',   sanitize($_POST['etablissement_adresse']   ?? ''));
        setParam('etablissement_telephone', sanitize($_POST['etablissement_telephone'] ?? ''));
        setParam('etablissement_email',     sanitize($_POST['etablissement_email']     ?? ''));

        // Couleurs (validation format hex)
        $primary = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['theme_couleur_primaire'] ?? '') ? $_POST['theme_couleur_primaire'] : '#1a73e8';
        $sidebar = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['theme_couleur_sidebar']  ?? '') ? $_POST['theme_couleur_sidebar']  : '#0f2d5c';
        setParam('theme_couleur_primaire', $primary);
        setParam('theme_couleur_sidebar',  $sidebar);

        // Upload logo
        if (!empty($_FILES['logo']['name'])) {
            $file    = $_FILES['logo'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $maxSize = 2 * 1024 * 1024;

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Erreur lors de l\'upload du fichier.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Le fichier est trop volumineux (max 2 Mo).';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!in_array($mime, $allowed)) {
                    $errors[] = 'Format non autorisé. Utilisez JPG, PNG, GIF, WebP ou SVG.';
                } else {
                    $uploadDir = APP_ROOT . '/assets/uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                    // Supprimer l'ancien logo
                    $old = getParam('logo_path');
                    if ($old && file_exists(APP_ROOT . '/assets/' . $old)) {
                        unlink(APP_ROOT . '/assets/' . $old);
                    }

                    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $name = 'logo.' . $ext;
                    move_uploaded_file($file['tmp_name'], $uploadDir . $name);
                    setParam('logo_path', 'uploads/' . $name);
                }
            }
        }

        if (empty($errors)) {
            setFlash('success', 'Paramètres enregistrés avec succès.');
            redirect('/modules/administration/parametres.php');
        }
    }
}

$logoUrl = getLogoUrl();

$pageTitle  = 'Paramètres';
$breadcrumb = ['Administration' => null, 'Paramètres' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-cog me-2 text-primary"></i>Paramètres de l'application</h2>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($err) ?></div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
  <input type="hidden" name="save_params" value="1">

  <div class="row g-4">

    <!-- ══ Colonne gauche ══════════════════════════════════════════════════════ -->
    <div class="col-lg-7">

      <!-- Identité de l'établissement -->
      <div class="card mb-4">
        <div class="card-header">
          <i class="fas fa-school me-2 text-primary"></i>Identité de l'établissement
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-600">Nom de l'établissement</label>
              <input type="text" name="etablissement_nom" class="form-control"
                     value="<?= h(getParam('etablissement_nom', 'École Privée de Santé Ibn Rochd')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Slogan</label>
              <input type="text" name="etablissement_slogan" class="form-control"
                     placeholder="Ex : Excellence – Santé – Service"
                     value="<?= h(getParam('etablissement_slogan')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Adresse</label>
              <input type="text" name="etablissement_adresse" class="form-control"
                     value="<?= h(getParam('etablissement_adresse', 'Dakar, Sénégal')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Téléphone</label>
              <input type="text" name="etablissement_telephone" class="form-control"
                     placeholder="77 XXX XX XX"
                     value="<?= h(getParam('etablissement_telephone')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Email</label>
              <input type="email" name="etablissement_email" class="form-control"
                     placeholder="contact@epsi.sn"
                     value="<?= h(getParam('etablissement_email')) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Thème visuel -->
      <div class="card">
        <div class="card-header">
          <i class="fas fa-palette me-2 text-primary"></i>Thème visuel
        </div>
        <div class="card-body">
          <div class="row g-4 align-items-start">
            <div class="col-md-6">
              <label class="form-label fw-600">Couleur principale</label>
              <div class="d-flex align-items-center gap-3">
                <input type="color" name="theme_couleur_primaire" id="colorPrimaire"
                       class="form-control form-control-color" style="width:56px;height:42px;cursor:pointer"
                       value="<?= h(getParam('theme_couleur_primaire', '#1a73e8')) ?>">
                <input type="text" id="hexPrimaire" class="form-control font-monospace"
                       placeholder="#1a73e8" maxlength="7"
                       value="<?= h(getParam('theme_couleur_primaire', '#1a73e8')) ?>">
              </div>
              <div class="form-text">Boutons, liens, éléments actifs</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Couleur de la barre latérale</label>
              <div class="d-flex align-items-center gap-3">
                <input type="color" name="theme_couleur_sidebar" id="colorSidebar"
                       class="form-control form-control-color" style="width:56px;height:42px;cursor:pointer"
                       value="<?= h(getParam('theme_couleur_sidebar', '#0f2d5c')) ?>">
                <input type="text" id="hexSidebar" class="form-control font-monospace"
                       placeholder="#0f2d5c" maxlength="7"
                       value="<?= h(getParam('theme_couleur_sidebar', '#0f2d5c')) ?>">
              </div>
              <div class="form-text">Fond du menu de navigation</div>
            </div>
          </div>

          <!-- Aperçu live -->
          <div class="mt-4 p-3 rounded-3" id="preview-zone" style="background:#f0f4f8;border:1px solid #dee2e6">
            <div class="fw-600 mb-3 text-muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.8px">
              <i class="fas fa-eye me-1"></i> Aperçu
            </div>
            <div class="d-flex gap-3 flex-wrap">
              <div id="prev-sidebar" style="width:140px;border-radius:10px;padding:14px 12px;display:flex;flex-direction:column;gap:8px">
                <div style="font-size:.7rem;color:rgba(255,255,255,.5);letter-spacing:1px;text-transform:uppercase;padding:0 4px">Menu</div>
                <div id="prev-nav-item" style="padding:7px 10px;border-radius:6px;font-size:.8rem;color:#fff;font-weight:600">
                  <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
                </div>
                <div style="padding:7px 10px;border-radius:6px;font-size:.8rem;color:rgba(255,255,255,.65)">
                  <i class="fas fa-user-graduate me-2"></i>Étudiants
                </div>
              </div>
              <div class="d-flex flex-column gap-2">
                <button type="button" id="prev-btn-primary" class="btn" style="font-size:.85rem">
                  <i class="fas fa-save me-1"></i> Enregistrer
                </button>
                <button type="button" id="prev-btn-outline" class="btn" style="font-size:.85rem;background:transparent">
                  Voir plus
                </button>
                <span id="prev-badge" class="badge" style="font-size:.8rem;padding:.4rem .8rem;align-self:start">
                  Actif
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ Colonne droite ═════════════════════════════════════════════════════ -->
    <div class="col-lg-5">

      <!-- Logo -->
      <div class="card">
        <div class="card-header">
          <i class="fas fa-image me-2 text-primary"></i>Logo de l'établissement
        </div>
        <div class="card-body">

          <!-- Aperçu logo actuel -->
          <div class="mb-3 text-center">
            <?php if ($logoUrl): ?>
              <img src="<?= h($logoUrl) ?>?v=<?= time() ?>" alt="Logo actuel"
                   style="max-height:120px;max-width:240px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:8px;background:#fff">
              <div class="mt-2">
                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Logo défini</span>
              </div>
            <?php else: ?>
              <div style="height:120px;width:240px;display:inline-flex;align-items:center;justify-content:center;background:#f8f9fa;border:2px dashed #dee2e6;border-radius:8px;margin:0 auto">
                <div class="text-center text-muted">
                  <i class="fas fa-image fa-2x mb-2 d-block" style="opacity:.4"></i>
                  <small>Aucun logo</small>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Upload -->
          <div class="mb-3">
            <label class="form-label fw-600">Choisir un nouveau logo</label>
            <input type="file" name="logo" id="logoInput" class="form-control"
                   accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
            <div class="form-text">JPG, PNG, GIF, WebP ou SVG — max 2 Mo</div>
          </div>

          <!-- Prévisualisation avant upload -->
          <div id="logoPreviewWrap" class="mb-3 text-center" style="display:none">
            <div class="text-muted mb-1" style="font-size:.78rem">Aperçu avant enregistrement :</div>
            <img id="logoPreviewImg" src="" alt="Aperçu"
                 style="max-height:100px;max-width:200px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:6px;background:#fff">
          </div>

          <?php if ($logoUrl): ?>
          <!-- Suppression logo -->
          <hr class="my-3">
          <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer le logo ?')">
            <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="delete_logo" value="1">
            <button type="submit" class="btn btn-outline-danger btn-sm w-100">
              <i class="fas fa-trash me-2"></i>Supprimer le logo
            </button>
          </form>
          <?php endif; ?>

          <!-- Info logo utilisation -->
          <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:.8rem">
            <i class="fas fa-info-circle me-1"></i>
            Le logo apparaît dans la barre latérale, les bulletins, les PV et les reçus de paiement.
          </div>
        </div>
      </div>

      <!-- Réinitialisation thème -->
      <div class="card mt-4">
        <div class="card-header"><i class="fas fa-undo me-2 text-warning"></i>Réinitialisation</div>
        <div class="card-body">
          <p class="text-muted" style="font-size:.85rem">Remettre les couleurs aux valeurs par défaut EPSI.</p>
          <button type="button" class="btn btn-outline-warning btn-sm w-100" id="resetColorsBtn">
            <i class="fas fa-undo me-2"></i>Réinitialiser les couleurs
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary px-5">
      <i class="fas fa-save me-2"></i>Enregistrer tous les paramètres
    </button>
    <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-light px-4">
      <i class="fas fa-times me-2"></i>Annuler
    </a>
  </div>
</form>

<script>
// ─── Sync color picker ↔ hex input ──────────────────────────────────────────
function syncColor(pickerId, hexId) {
  const picker = document.getElementById(pickerId);
  const hex    = document.getElementById(hexId);
  picker.addEventListener('input', () => { hex.value = picker.value; updatePreview(); });
  hex.addEventListener('input', () => {
    if (/^#[0-9A-Fa-f]{6}$/.test(hex.value)) { picker.value = hex.value; updatePreview(); }
  });
}
syncColor('colorPrimaire', 'hexPrimaire');
syncColor('colorSidebar',  'hexSidebar');

// ─── Live preview ────────────────────────────────────────────────────────────
function updatePreview() {
  const primary = document.getElementById('colorPrimaire').value;
  const sidebar = document.getElementById('colorSidebar').value;

  document.getElementById('prev-sidebar').style.background = sidebar;
  document.getElementById('prev-nav-item').style.background = primary;
  document.getElementById('prev-btn-primary').style.background   = primary;
  document.getElementById('prev-btn-primary').style.borderColor  = primary;
  document.getElementById('prev-btn-primary').style.color        = '#fff';
  document.getElementById('prev-btn-outline').style.borderColor  = primary;
  document.getElementById('prev-btn-outline').style.border       = '1px solid ' + primary;
  document.getElementById('prev-btn-outline').style.color        = primary;
  document.getElementById('prev-badge').style.background         = primary;
}
updatePreview();

// ─── Réinitialisation ────────────────────────────────────────────────────────
document.getElementById('resetColorsBtn').addEventListener('click', () => {
  document.getElementById('colorPrimaire').value = '#1a73e8';
  document.getElementById('hexPrimaire').value   = '#1a73e8';
  document.getElementById('colorSidebar').value  = '#0f2d5c';
  document.getElementById('hexSidebar').value    = '#0f2d5c';
  updatePreview();
});

// ─── Prévisualisation logo avant upload ─────────────────────────────────────
document.getElementById('logoInput').addEventListener('change', function () {
  const file = this.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('logoPreviewImg').src = e.target.result;
    document.getElementById('logoPreviewWrap').style.display = '';
  };
  reader.readAsDataURL(file);
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
