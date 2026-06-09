<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

$db = getDB();

// Toggle actif
if (isset($_GET['toggle']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['toggle'];
    $db->prepare("UPDATE ecoles SET actif = 1 - actif WHERE id = ?")->execute([$id]);
    setFlash('success', 'Statut de l\'école mis à jour.');
    redirect('/modules/superadmin/index.php');
}

// Delete
if (isset($_GET['delete']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    try {
        $db->prepare("DELETE FROM ecoles WHERE id = ?")->execute([$id]);
        setFlash('success', 'École supprimée.');
    } catch (PDOException $e) {
        setFlash('error', 'Impossible de supprimer : des données y sont rattachées.');
    }
    redirect('/modules/superadmin/index.php');
}

// Auto-expire licences
try {
    $db->exec("UPDATE licences SET statut = 'expiree' WHERE statut = 'active' AND date_expiration IS NOT NULL AND date_expiration < CURDATE()");
} catch (PDOException $e) {}

$ecoles = $db->query("
    SELECT e.*,
           (SELECT COUNT(*) FROM users u WHERE u.ecole_id = e.id) AS nb_users,
           (SELECT COUNT(*) FROM etudiants et WHERE et.ecole_id = e.id) AS nb_etudiants,
           l.statut         AS lic_statut,
           l.date_expiration AS lic_expiration,
           l.id             AS lic_id
    FROM ecoles e
    LEFT JOIN licences l ON l.id = (
        SELECT id FROM licences WHERE ecole_id = e.id ORDER BY created_at DESC LIMIT 1
    )
    ORDER BY e.nom
")->fetchAll();

$pageTitle  = 'Gestion des écoles';
$breadcrumb = ['SuperAdmin' => null, 'Écoles' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-school me-2 text-primary"></i>Gestion des établissements</h2>
  <a href="<?= APP_URL ?>/modules/superadmin/ecole_form.php" class="btn btn-primary">
    <i class="fas fa-plus me-2"></i>Nouvelle école
  </a>
</div>

<!-- Statistiques globales -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-school"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($ecoles) ?></div>
        <div class="stat-label">Établissements</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count(array_filter($ecoles, fn($e) => $e['actif'])) ?></div>
        <div class="stat-label">Actives</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-users"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= array_sum(array_column($ecoles, 'nb_users')) ?></div>
        <div class="stat-label">Utilisateurs total</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= array_sum(array_column($ecoles, 'nb_etudiants')) ?></div>
        <div class="stat-label">Étudiants total</div>
      </div>
    </div>
  </div>
</div>

<!-- Tableau des écoles -->
<div class="card">
  <div class="card-header"><strong><?= count($ecoles) ?></strong> établissement(s)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Code</th>
          <th>Établissement</th>
          <th>Ville / Pays</th>
          <th>Utilisateurs</th>
          <th>Étudiants</th>
          <th>Statut</th>
          <th>Licence</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$ecoles): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun établissement. <a href="<?= APP_URL ?>/modules/superadmin/ecole_form.php">Créer le premier</a>.</td></tr>
        <?php endif; ?>
        <?php foreach ($ecoles as $e): ?>
        <tr>
          <td><code class="text-primary"><?= h($e['code']) ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if ($e['logo_path'] && file_exists(APP_ROOT . '/assets/' . $e['logo_path'])): ?>
                <img src="<?= APP_URL ?>/assets/<?= h($e['logo_path']) ?>" alt="Logo"
                     style="width:34px;height:34px;object-fit:contain;border-radius:6px;border:1px solid #dee2e6;background:#fff;padding:2px">
              <?php else: ?>
                <div style="width:34px;height:34px;border-radius:6px;background:linear-gradient(135deg,#1a73e8,#0f2d5c);display:flex;align-items:center;justify-content:center">
                  <i class="fas fa-school" style="color:#fff;font-size:.75rem"></i>
                </div>
              <?php endif; ?>
              <div>
                <div class="fw-600"><?= h($e['nom']) ?></div>
                <?php if ($e['slogan']): ?><small class="text-muted"><?= h($e['slogan']) ?></small><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-muted fs-sm"><?= h($e['ville'] ?? '–') ?>, <?= h($e['pays'] ?? '–') ?></td>
          <td><span class="badge bg-secondary"><?= (int)$e['nb_users'] ?></span></td>
          <td><span class="badge bg-info"><?= (int)$e['nb_etudiants'] ?></span></td>
          <td>
            <?php if ($e['actif']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span>
            <?php else: ?>
              <span class="badge bg-secondary">Inactive</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $licData = $e['lic_id'] ? ['statut' => $e['lic_statut']] : null;
              echo getLicenceBadge($licData);
              if ($e['lic_expiration'] && ($e['lic_statut'] ?? '') === 'active') {
                  $diff = (int)(new DateTime($e['lic_expiration']))->diff(new DateTime())->days;
                  $past = $e['lic_expiration'] < date('Y-m-d');
                  if (!$past && $diff <= 30) {
                      echo '<br><small class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>' . $diff . 'j</small>';
                  }
              }
            ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <!-- Entrer dans cette école -->
              <a href="<?= APP_URL ?>/modules/superadmin/switch_ecole.php?id=<?= $e['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm btn-primary" title="Gérer cette école">
                <i class="fas fa-sign-in-alt"></i>
              </a>
              <!-- Licences -->
              <a href="<?= APP_URL ?>/modules/superadmin/licences.php?ecole_id=<?= $e['id'] ?>"
                 class="btn btn-sm btn-outline-<?= $e['lic_id'] && $e['lic_statut'] === 'active' ? 'success' : 'danger' ?>"
                 title="<?= $e['lic_id'] ? 'Gérer la licence' : 'Générer une licence' ?>">
                <i class="fas fa-key"></i>
              </a>
              <!-- Éditer -->
              <a href="<?= APP_URL ?>/modules/superadmin/ecole_form.php?id=<?= $e['id'] ?>"
                 class="btn btn-sm btn-outline-warning" title="Modifier">
                <i class="fas fa-edit"></i>
              </a>
              <!-- Activer / Désactiver -->
              <a href="?toggle=<?= $e['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm <?= $e['actif'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                 title="<?= $e['actif'] ? 'Désactiver' : 'Activer' ?>"
                 onclick="return confirm('<?= $e['actif'] ? 'Désactiver' : 'Activer' ?> cette école ?')">
                <i class="fas fa-<?= $e['actif'] ? 'ban' : 'check' ?>"></i>
              </a>
              <!-- Supprimer -->
              <a href="?delete=<?= $e['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm btn-outline-danger" title="Supprimer"
                 onclick="return confirm('Supprimer définitivement cet établissement et toutes ses données ?')">
                <i class="fas fa-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
