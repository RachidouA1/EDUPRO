<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

$db      = getDB();
$current = getCurrentUser();

// ── Toggle actif ──────────────────────────────────────────────────────────────
if (isset($_GET['toggle']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $tid = (int)$_GET['toggle'];
    if ($tid === (int)$current['id']) {
        setFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
    } else {
        $db->prepare("UPDATE users SET actif = 1 - actif WHERE id = ? AND role = 'superadmin'")
           ->execute([$tid]);
        setFlash('success', 'Statut mis à jour.');
    }
    redirect('/modules/superadmin/superadmins.php');
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $did = (int)$_GET['delete'];
    if ($did === (int)$current['id']) {
        setFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
    } else {
        $db->prepare("DELETE FROM users WHERE id = ? AND role = 'superadmin'")
           ->execute([$did]);
        setFlash('success', 'Compte superadmin supprimé.');
    }
    redirect('/modules/superadmin/superadmins.php');
}

// ── Add / Edit ────────────────────────────────────────────────────────────────
$editUser = null;
$editId   = (int)($_GET['edit'] ?? 0);
if ($editId) {
    $s = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'superadmin'");
    $s->execute([$editId]);
    $editUser = $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $action  = $_POST['action'] ?? '';
    $pid     = (int)($_POST['user_id'] ?? 0);
    $nom     = trim($_POST['nom'] ?? '');
    $prenom  = trim($_POST['prenom'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $mdp     = $_POST['password'] ?? '';
    $mdp2    = $_POST['password_confirm'] ?? '';

    $errors = [];
    if (!$nom)    $errors[] = 'Le nom est obligatoire.';
    if (!$prenom) $errors[] = 'Le prénom est obligatoire.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';

    $dupCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $dupCheck->execute([$email, $pid]);
    if ($dupCheck->fetch()) $errors[] = 'Cet email est déjà utilisé.';

    if ($action === 'add' || $mdp !== '') {
        if ($action === 'add' && $mdp === '') $errors[] = 'Le mot de passe est obligatoire pour un nouveau compte.';
        if ($mdp !== '' && strlen($mdp) < 8)  $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        if ($mdp !== $mdp2)                   $errors[] = 'Les mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        if ($action === 'add') {
            $hash = password_hash($mdp, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (nom, prenom, email, password, role, actif) VALUES (?,?,?,?,'superadmin',1)")
               ->execute([$nom, $prenom, $email, $hash]);
            setFlash('success', "Compte superadmin « {$prenom} {$nom} » créé.");
        } else {
            // edit — only allow editing non-self through this page, self goes to profil
            if ($pid === (int)$current['id']) {
                redirect('/modules/superadmin/profil.php');
            }
            if ($mdp !== '') {
                $hash = password_hash($mdp, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET nom=?,prenom=?,email=?,password=? WHERE id=? AND role='superadmin'")
                   ->execute([$nom, $prenom, $email, $hash, $pid]);
            } else {
                $db->prepare("UPDATE users SET nom=?,prenom=?,email=? WHERE id=? AND role='superadmin'")
                   ->execute([$nom, $prenom, $email, $pid]);
            }
            setFlash('success', 'Compte mis à jour.');
        }
        redirect('/modules/superadmin/superadmins.php');
    } else {
        foreach ($errors as $e) setFlash('error', $e);
        // Keep form open with posted data
        $editUser = ['id' => $pid, 'nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'actif' => 1];
        if ($pid === 0) $editUser = null; // new
    }
}

$superadmins = $db->query("SELECT * FROM users WHERE role = 'superadmin' ORDER BY created_at ASC")->fetchAll();

$pageTitle  = 'Comptes Super Administrateurs';
$breadcrumb = ['SuperAdmin' => null, 'Super Administrateurs' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-crown me-2" style="color:#6200ea"></i>Super Administrateurs</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
    <i class="fas fa-plus me-1"></i>Nouveau superadmin
  </button>
</div>

<?php showFlash(); ?>

<!-- ── Tableau ─────────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header"><strong><?= count($superadmins) ?></strong> compte(s) super administrateur</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Identité</th>
          <th>Email</th>
          <th>Créé le</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($superadmins as $sa): ?>
        <?php $isSelf = ((int)$sa['id'] === (int)$current['id']); ?>
        <tr>
          <td><code class="text-muted">#<?= $sa['id'] ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6200ea,#9c27b0);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-crown" style="color:#fff;font-size:.75rem"></i>
              </div>
              <div>
                <div class="fw-600"><?= h($sa['prenom'] . ' ' . $sa['nom']) ?></div>
                <?php if ($isSelf): ?>
                  <small class="badge" style="background:#6200ea22;color:#6200ea;font-size:.65rem">Vous</small>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-muted"><?= h($sa['email']) ?></td>
          <td class="text-muted fs-sm"><?= date('d/m/Y', strtotime($sa['created_at'])) ?></td>
          <td>
            <?php if ($sa['actif']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Actif</span>
            <?php else: ?>
              <span class="badge bg-secondary">Inactif</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <?php if ($isSelf): ?>
                <!-- Self: go to profile page -->
                <a href="<?= APP_URL ?>/modules/superadmin/profil.php"
                   class="btn btn-sm btn-outline-primary" title="Modifier mon profil">
                  <i class="fas fa-user-edit"></i>
                </a>
              <?php else: ?>
                <!-- Edit other superadmin -->
                <a href="?edit=<?= $sa['id'] ?>"
                   class="btn btn-sm btn-outline-warning" title="Modifier">
                  <i class="fas fa-edit"></i>
                </a>
                <!-- Toggle actif -->
                <a href="?toggle=<?= $sa['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn btn-sm <?= $sa['actif'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                   title="<?= $sa['actif'] ? 'Désactiver' : 'Activer' ?>"
                   onclick="return confirm('<?= $sa['actif'] ? 'Désactiver' : 'Activer' ?> ce compte ?')">
                  <i class="fas fa-<?= $sa['actif'] ? 'ban' : 'check' ?>"></i>
                </a>
                <!-- Delete -->
                <a href="?delete=<?= $sa['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn btn-sm btn-outline-danger" title="Supprimer"
                   onclick="return confirm('Supprimer ce compte superadmin ?')">
                  <i class="fas fa-trash"></i>
                </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal : Ajouter ────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="user_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-crown me-2" style="color:#6200ea"></i>Nouveau Super Administrateur</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" required maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Prénom <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control" required maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Mot de passe <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password" id="addPwd1" class="form-control"
                       required minlength="8" placeholder="8 car. min.">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('addPwd1',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Confirmer <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password_confirm" id="addPwd2" class="form-control"
                       required placeholder="Répéter">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('addPwd2',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i>Créer le compte
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal : Modifier (inline si ?edit=N) ───────────────────────────────── -->
<?php if ($editUser && (int)$editUser['id'] !== (int)$current['id']): ?>
<div class="modal fade show d-block" id="modalEdit" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Modifier le superadmin</h5>
          <a href="<?= APP_URL ?>/modules/superadmin/superadmins.php" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control"
                     value="<?= h($editUser['nom']) ?>" required maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Prénom <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control"
                     value="<?= h($editUser['prenom']) ?>" required maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($editUser['email']) ?>" required maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Nouveau mot de passe</label>
              <div class="input-group">
                <input type="password" name="password" id="editPwd1" class="form-control"
                       minlength="8" placeholder="Laisser vide = inchangé">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('editPwd1',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Confirmer</label>
              <div class="input-group">
                <input type="password" name="password_confirm" id="editPwd2" class="form-control"
                       placeholder="Répéter">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('editPwd2',this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="<?= APP_URL ?>/modules/superadmin/superadmins.php" class="btn btn-secondary">Annuler</a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Enregistrer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

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
