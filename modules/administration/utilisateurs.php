<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'superadmin']);

$db      = getDB();
$errors  = [];
$user    = getCurrentUser();
$ecoleId = getEcoleId();

// Inline migration: ensure all roles including superadmin and assistante are in the ENUM
try { $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur','assistante') NOT NULL DEFAULT 'enseignant'"); } catch (PDOException $e) {}

$filieres = getFilieres();

// Delete user (cannot delete self)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $delId = (int)$_GET['id'];
    if ($delId !== $user['id']) {
        $db->prepare("UPDATE users SET actif=0 WHERE id=?")->execute([$delId]);
        setFlash('success', 'Utilisateur désactivé.');
    }
    redirect('/modules/administration/utilisateurs.php');
}

// Toggle active
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $togId = (int)$_GET['id'];
    $db->prepare("UPDATE users SET actif = IF(actif=1, 0, 1) WHERE id=? AND id != ?")->execute([$togId, $user['id']]);
    setFlash('success', 'Statut mis à jour.');
    redirect('/modules/administration/utilisateurs.php');
}

// Save user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $nom       = sanitize($_POST['nom']    ?? '');
        $prenom    = sanitize($_POST['prenom'] ?? '');
        $email     = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $allowedRoles = ['admin','directeur','scolarite','enseignant','comptable','coordinateur','assistante'];
        $role = in_array($_POST['role'] ?? '', $allowedRoles) ? $_POST['role'] : 'enseignant';
        $pwd    = $_POST['password'] ?? '';
        $editId = (int)($_POST['edit_id'] ?? 0);

        if (empty($nom))    $errors[] = 'Nom obligatoire.';
        if (empty($prenom)) $errors[] = 'Prénom obligatoire.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';

        if (empty($errors)) {
            if ($editId) {
                $sql = !empty($pwd)
                    ? "UPDATE users SET nom=?,prenom=?,email=?,role=?,password=? WHERE id=?"
                    : "UPDATE users SET nom=?,prenom=?,email=?,role=? WHERE id=?";
                $params = !empty($pwd)
                    ? [$nom, $prenom, $email, $role, password_hash($pwd, PASSWORD_DEFAULT), $editId]
                    : [$nom, $prenom, $email, $role, $editId];
                $db->prepare($sql)->execute($params);
                setFlash('success', 'Utilisateur modifié.');
            } else {
                if (empty($pwd)) $errors[] = 'Mot de passe obligatoire pour un nouvel utilisateur.';
                if (empty($errors)) {
                    try {
                        if ($ecoleId > 0) {
                            $db->prepare("INSERT INTO users (nom,prenom,email,password,role,ecole_id,actif) VALUES (?,?,?,?,?,?,1)")
                               ->execute([$nom, $prenom, $email, password_hash($pwd, PASSWORD_DEFAULT), $role, $ecoleId]);
                        } else {
                            $db->prepare("INSERT INTO users (nom,prenom,email,password,role,actif) VALUES (?,?,?,?,?,1)")
                               ->execute([$nom, $prenom, $email, password_hash($pwd, PASSWORD_DEFAULT), $role]);
                        }
                        setFlash('success', 'Utilisateur créé. Assignez les sections si c\'est un coordinateur.');
                    } catch (PDOException $e) {
                        $errors[] = 'Cet email est déjà utilisé.';
                    }
                }
            }
            if (empty($errors)) redirect('/modules/administration/utilisateurs.php');
        }
    }
}

try {
    if ($ecoleId > 0) {
        $stmt = $db->prepare("SELECT * FROM users WHERE ecole_id = ? ORDER BY role, nom, prenom");
        $stmt->execute([$ecoleId]);
    } else {
        $stmt = $db->query("SELECT * FROM users ORDER BY role, nom, prenom");
    }
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = $db->query("SELECT * FROM users ORDER BY role, nom, prenom")->fetchAll();
}

$pageTitle  = 'Utilisateurs';
$breadcrumb = ['Administration' => null, 'Utilisateurs' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-users-cog me-2 text-primary"></i>Gestion des Utilisateurs</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetForm()">
    <i class="fas fa-plus me-2"></i>Nouvel utilisateur
  </button>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
    $counts = ['admin'=>0,'directeur'=>0,'scolarite'=>0,'enseignant'=>0,'comptable'=>0,'coordinateur'=>0,'assistante'=>0];
    foreach ($users as $u) if (isset($counts[$u['role']])) $counts[$u['role']]++;
  ?>
  <div class="col-6 col-md-2"><div class="stat-card stat-blue"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-user-shield"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['admin'] ?></div><div class="stat-label" style="font-size:.7rem">Admin</div></div></div></div>
  <div class="col-6 col-md-2"><div class="stat-card stat-purple"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-user-tie"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['directeur'] ?></div><div class="stat-label" style="font-size:.7rem">Directeur</div></div></div></div>
  <div class="col-6 col-md-2"><div class="stat-card stat-teal"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-user-cog"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['scolarite'] ?></div><div class="stat-label" style="font-size:.7rem">Scolarité</div></div></div></div>
  <div class="col-6 col-md-2"><div class="stat-card stat-green"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['enseignant'] ?></div><div class="stat-label" style="font-size:.7rem">Enseignant</div></div></div></div>
  <div class="col-6 col-md-2"><div class="stat-card stat-orange"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-calculator"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['comptable'] ?></div><div class="stat-label" style="font-size:.7rem">Comptable</div></div></div></div>
  <div class="col-6 col-md-2"><div class="stat-card stat-dark" style="background:linear-gradient(135deg,#1b5e20,#2e7d32)"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-sitemap"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['coordinateur'] ?></div><div class="stat-label" style="font-size:.7rem">Coordinateur</div></div></div></div>
</div>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat-card" style="background:linear-gradient(135deg,#922b21,#c0392b)"><div class="stat-icon" style="font-size:1rem"><i class="fas fa-envelope-open-text" style="color:#fff"></i></div><div class="stat-body"><div class="stat-value" style="color:#fff"><?= $counts['assistante'] ?></div><div class="stat-label" style="font-size:.7rem;color:rgba(255,255,255,.8)">Assistante Dir.</div></div></div></div>
</div>

<div class="card">
  <div class="card-header"><strong><?= count($users) ?></strong> utilisateur(s)</div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <thead><tr><th>#</th><th>Nom & Prénom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Créé le</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $i => $u): ?>
        <tr class="<?= !$u['actif'] ? 'opacity-50' : '' ?>">
          <td class="text-muted fs-sm"><?= $i+1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle" style="background:<?= ['admin'=>'#0f2d5c','directeur'=>'#5c35a0','scolarite'=>'#0097a7','enseignant'=>'#34a853','comptable'=>'#f57c00','coordinateur'=>'#2e7d32','assistante'=>'#c0392b'][$u['role']] ?? '#aaa' ?>;width:34px;height:34px;font-size:.75rem">
                <?= strtoupper(substr($u['prenom'],0,1).substr($u['nom'],0,1)) ?>
              </div>
              <div>
                <div class="fw-600"><?= h($u['nom'].' '.$u['prenom']) ?></div>
              </div>
            </div>
          </td>
          <td><?= h($u['email']) ?></td>
          <td>
            <?php
              $rc = ['admin'=>'primary','directeur'=>'purple','scolarite'=>'info','enseignant'=>'success','comptable'=>'warning','coordinateur'=>'coord','assistante'=>'ast'][$u['role']] ?? 'secondary';
              $rl = ['admin'=>'Admin','directeur'=>'Directeur','scolarite'=>'Scolarité','enseignant'=>'Enseignant','comptable'=>'Comptable','coordinateur'=>'Coordinateur','assistante'=>'Assistante Dir.'][$u['role']] ?? $u['role'];
              $badgeBg = $rc === 'purple' ? 'style="background:#5c35a0;color:#fff"' : ($rc === 'coord' ? 'style="background:#2e7d32;color:#fff"' : ($rc === 'ast' ? 'style="background:#c0392b;color:#fff"' : ''));
            ?>
            <span class="badge bg-<?= $rc ?>" <?= $badgeBg ?>><?= $rl ?></span>
            <?php if ($u['role'] === 'coordinateur'): ?>
              <a href="<?= APP_URL ?>/modules/administration/coordinateur_sections.php?user_id=<?= $u['id'] ?>"
                 class="d-block mt-1" style="font-size:.75rem">
                <i class="fas fa-sitemap me-1"></i>Gérer sections
              </a>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-<?= $u['actif'] ? 'success' : 'secondary' ?>"><?= $u['actif'] ? 'Actif' : 'Inactif' ?></span></td>
          <td class="text-muted fs-sm"><?= formatDate($u['created_at']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-icon btn-sm btn-outline-warning" onclick='editUser(<?= json_encode($u) ?>)' title="Modifier"><i class="fas fa-edit"></i></button>
              <?php if ($u['id'] !== $user['id']): ?>
                <a href="?action=toggle&id=<?= $u['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>" class="btn btn-icon btn-sm btn-outline-<?= $u['actif'] ? 'secondary' : 'success' ?>" title="<?= $u['actif'] ? 'Désactiver' : 'Activer' ?>">
                  <i class="fas fa-<?= $u['actif'] ? 'ban' : 'check' ?>"></i>
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

<!-- Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Nouvel utilisateur</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" id="u_edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Nom *</label>
              <input type="text" name="nom" id="u_nom" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Prénom *</label>
              <input type="text" name="prenom" id="u_prenom" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Email *</label>
              <input type="email" name="email" id="u_email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Rôle</label>
              <select name="role" id="u_role" class="form-select" onchange="toggleCoordInfo()">
                <option value="enseignant" selected>Enseignant</option>
                <option value="scolarite">Scolarité</option>
                <option value="comptable">Comptable</option>
                <option value="coordinateur">Coordinateur de section</option>
                <option value="assistante">Assistante de Direction</option>
                <option value="directeur">Directeur</option>
                <option value="admin">Administrateur</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mot de passe <small class="text-muted" id="pwdHint">(obligatoire)</small></label>
              <input type="password" name="password" id="u_pwd" class="form-control" placeholder="••••••••">
            </div>
            <div class="col-12" id="coord_info" style="display:none">
              <div class="alert alert-info py-2 mb-0 fs-sm">
                <i class="fas fa-info-circle me-1"></i>
                Après la création, utilisez le lien <strong>«&nbsp;Gérer sections&nbsp;»</strong> dans la liste
                pour assigner les filières et niveaux gérés par ce coordinateur.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleCoordInfo() {
  const role = document.getElementById('u_role').value;
  const el   = document.getElementById('coord_info');
  const isEdit = document.getElementById('u_edit_id').value !== '';
  el.style.display = (role === 'coordinateur' && !isEdit) ? '' : 'none';
}
function resetForm() {
  document.getElementById('userModalTitle').textContent = 'Nouvel utilisateur';
  document.getElementById('u_edit_id').value = '';
  document.getElementById('u_nom').value = '';
  document.getElementById('u_prenom').value = '';
  document.getElementById('u_email').value = '';
  document.getElementById('u_role').value = 'enseignant';
  document.getElementById('u_pwd').value = '';
  document.getElementById('pwdHint').textContent = '(obligatoire)';
  toggleCoordInfo();
}
function editUser(u) {
  document.getElementById('userModalTitle').textContent = 'Modifier l\'utilisateur';
  document.getElementById('u_edit_id').value = u.id;
  document.getElementById('u_nom').value = u.nom;
  document.getElementById('u_prenom').value = u.prenom;
  document.getElementById('u_email').value = u.email;
  document.getElementById('u_role').value = u.role;
  document.getElementById('u_pwd').value = '';
  document.getElementById('pwdHint').textContent = '(laisser vide pour conserver)';
  toggleCoordInfo();
  new bootstrap.Modal(document.getElementById('userModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
