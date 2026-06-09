<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

$db   = getDB();
$user = getCurrentUser();
$id   = (int)$user['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $nom    = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $mdp    = $_POST['password'] ?? '';
    $mdp2   = $_POST['password_confirm'] ?? '';

    $errors = [];
    if (!$nom)   $errors[] = 'Le nom est obligatoire.';
    if (!$prenom) $errors[] = 'Le prénom est obligatoire.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';

    // Check email not used by another user
    $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->execute([$email, $id]);
    if ($check->fetch()) $errors[] = 'Cet email est déjà utilisé par un autre compte.';

    if ($mdp !== '' || $mdp2 !== '') {
        if (strlen($mdp) < 8) $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        if ($mdp !== $mdp2)   $errors[] = 'Les mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        if ($mdp !== '') {
            $hash = password_hash($mdp, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET nom=?, prenom=?, email=?, password=? WHERE id=?")
               ->execute([$nom, $prenom, $email, $hash, $id]);
        } else {
            $db->prepare("UPDATE users SET nom=?, prenom=?, email=? WHERE id=?")
               ->execute([$nom, $prenom, $email, $id]);
        }
        // Refresh session data
        $_SESSION['user']['nom']    = $nom;
        $_SESSION['user']['prenom'] = $prenom;
        $_SESSION['user']['email']  = $email;

        setFlash('success', 'Profil mis à jour avec succès.');
        redirect('/modules/superadmin/profil.php');
    } else {
        foreach ($errors as $e) setFlash('error', $e);
    }
}

// Reload fresh data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$profil = $stmt->fetch();

$pageTitle  = 'Mon profil';
$breadcrumb = ['SuperAdmin' => null, 'Mon profil' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-circle me-2 text-primary"></i>Mon profil</h2>
</div>

<?php showFlash(); ?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#6200ea,#9c27b0);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-crown" style="color:#fff;font-size:.95rem"></i>
        </div>
        <div>
          <div class="fw-600"><?= h($profil['prenom'] . ' ' . $profil['nom']) ?></div>
          <small class="text-muted">Super Administrateur</small>
        </div>
      </div>
      <div class="card-body">
        <form method="POST" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control"
                     value="<?= h($profil['nom']) ?>" required maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Prénom <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control"
                     value="<?= h($profil['prenom']) ?>" required maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Adresse email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($profil['email']) ?>" required maxlength="150">
            </div>
          </div>

          <hr class="my-4">
          <h6 class="text-muted mb-3"><i class="fas fa-lock me-1"></i>Changer le mot de passe <small class="fw-normal">(laisser vide pour ne pas modifier)</small></h6>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Nouveau mot de passe</label>
              <div class="input-group">
                <input type="password" name="password" id="pwd1" class="form-control"
                       minlength="8" placeholder="8 caractères minimum">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd1',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Confirmer le mot de passe</label>
              <div class="input-group">
                <input type="password" name="password_confirm" id="pwd2" class="form-control"
                       placeholder="Répéter le mot de passe">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd2',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save me-1"></i>Enregistrer les modifications
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Info compte -->
    <div class="card mt-3">
      <div class="card-body py-3">
        <div class="row g-2 text-muted fs-sm">
          <div class="col-6">
            <i class="fas fa-id-badge me-1"></i>ID compte : <strong>#<?= $profil['id'] ?></strong>
          </div>
          <div class="col-6">
            <i class="fas fa-calendar me-1"></i>Créé le :
            <strong><?= date('d/m/Y', strtotime($profil['created_at'])) ?></strong>
          </div>
          <div class="col-6">
            <i class="fas fa-circle me-1 text-success"></i>Statut :
            <strong><?= $profil['actif'] ? 'Actif' : 'Inactif' ?></strong>
          </div>
          <div class="col-6">
            <i class="fas fa-crown me-1" style="color:#6200ea"></i>Rôle :
            <strong style="color:#6200ea">Super Administrateur</strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        inp.type = 'password';
        btn.innerHTML = '<i class="fas fa-eye"></i>';
    }
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
