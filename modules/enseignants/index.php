<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'comptable', 'enseignant', 'coordinateur']);

$db     = getDB();
$search = sanitize($_GET['search'] ?? '');
$type   = sanitize($_GET['type']   ?? '');

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = "(nom LIKE ? OR prenom LIKE ? OR matricule LIKE ? OR specialite LIKE ?)";
    $params   = ["%$search%", "%$search%", "%$search%", "%$search%"];
}
if ($type) { $where[] = 'type_contrat = ?'; $params[] = $type; }
$where[] = 'actif = 1';

$stmt = $db->prepare("SELECT * FROM enseignants WHERE " . implode(' AND ', $where) . " ORDER BY nom, prenom");
$stmt->execute($params);
$enseignants = $stmt->fetchAll();

$pageTitle = 'Enseignants';
$breadcrumb = ['Enseignants' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Gestion des Enseignants</h2>
  <?php if (hasRole('admin')): ?><a href="<?= APP_URL ?>/modules/enseignants/add.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nouvel enseignant</a><?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-6">
        <div class="search-bar">
          <i class="fas fa-search search-icon"></i>
          <input type="text" name="search" class="form-control" placeholder="Nom, prénom, matricule, spécialité..." value="<?= h($search) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="type" class="form-select">
          <option value="">Tous types</option>
          <option value="permanent"  <?= $type==='permanent'  ? 'selected' : '' ?>>Permanent</option>
          <option value="vacataire"  <?= $type==='vacataire'  ? 'selected' : '' ?>>Vacataire</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i>Filtrer</button>
        <a href="?" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header"><strong><?= count($enseignants) ?></strong> enseignant(s)</div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <thead>
        <tr><th>#</th><th>Matricule</th><th>Nom & Prénom</th><th>Spécialité</th><th>Contact</th><th>Type</th><th>Salaire base</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-chalkboard-teacher d-block mb-2" style="font-size:2rem;opacity:.3"></i>Aucun enseignant</td></tr>
        <?php endif; ?>
        <?php foreach ($enseignants as $i => $e): ?>
        <tr>
          <td class="text-muted fs-sm"><?= $i+1 ?></td>
          <td><code class="fs-sm"><?= h($e['matricule']) ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:36px;height:36px;font-size:.8rem;">
                <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
              </div>
              <div>
                <div class="fw-600"><?= h($e['nom'].' '.$e['prenom']) ?></div>
                <div class="text-muted fs-sm"><?= $e['sexe']==='M' ? 'Homme' : 'Femme' ?></div>
              </div>
            </div>
          </td>
          <td class="fs-sm"><?= h($e['specialite'] ?? '-') ?></td>
          <td class="fs-sm">
            <?= h($e['telephone'] ?? '-') ?>
            <?php if ($e['email']): ?><div class="text-muted"><?= h($e['email']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge bg-<?= $e['type_contrat']==='permanent' ? 'primary' : 'secondary' ?>"><?= ucfirst(h($e['type_contrat'])) ?></span></td>
          <td class="fw-600"><?= formatMontant($e['salaire_base']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if (hasRole('admin')): ?>
              <a href="<?= APP_URL ?>/modules/enseignants/edit.php?id=<?= $e['id'] ?>" class="btn btn-icon btn-sm btn-outline-warning" title="Modifier" data-bs-toggle="tooltip"><i class="fas fa-edit"></i></a>
              <?php endif; ?>
              <?php if (hasRole(['admin', 'comptable'])): ?>
              <a href="<?= APP_URL ?>/modules/enseignants/paiements.php?id=<?= $e['id'] ?>" class="btn btn-icon btn-sm btn-outline-success" title="Paiements" data-bs-toggle="tooltip"><i class="fas fa-money-bill"></i></a>
              <?php endif; ?>
              <?php if (hasRole('admin')): ?>
              <form method="POST" action="<?= APP_URL ?>/modules/enseignants/delete.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button type="button" class="btn btn-icon btn-sm btn-outline-danger" onclick="confirmDelete(this.form,<?= htmlspecialchars(json_encode($e['nom'].' '.$e['prenom'], JSON_HEX_TAG|JSON_HEX_AMP), ENT_COMPAT) ?>)" title="Supprimer" data-bs-toggle="tooltip"><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
