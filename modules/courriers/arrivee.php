<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'assistante']);

$db   = getDB();
$user = getCurrentUser();
$errors = [];

// Inline migration : créer la table si elle n'existe pas encore
try {
    $db->exec("CREATE TABLE IF NOT EXISTS courriers_arrivee (
        id                  INT PRIMARY KEY AUTO_INCREMENT,
        date_arrivee        DATE NOT NULL,
        date_correspondance DATE DEFAULT NULL,
        n_correspondance    VARCHAR(100) DEFAULT NULL,
        expediteur          VARCHAR(255) NOT NULL,
        objet               TEXT NOT NULL,
        date_reponse        DATE DEFAULT NULL,
        n_reponse           VARCHAR(100) DEFAULT NULL,
        created_by          INT NOT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// Suppression
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $db->prepare("DELETE FROM courriers_arrivee WHERE id=?")->execute([(int)$_GET['id']]);
    setFlash('success', 'Courrier arrivée supprimé.');
    redirect('/modules/courriers/arrivee.php');
}

// Enregistrement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $editId             = (int)($_POST['edit_id'] ?? 0);
        $date_arrivee       = sanitize($_POST['date_arrivee'] ?? '');
        $date_correspondance = sanitize($_POST['date_correspondance'] ?? '');
        $n_correspondance   = sanitize($_POST['n_correspondance'] ?? '');
        $expediteur         = sanitize($_POST['expediteur'] ?? '');
        $objet              = sanitize($_POST['objet'] ?? '');
        $date_reponse       = sanitize($_POST['date_reponse'] ?? '');
        $n_reponse          = sanitize($_POST['n_reponse'] ?? '');

        if (empty($date_arrivee))  $errors[] = 'La date d\'arrivée est obligatoire.';
        if (empty($expediteur))    $errors[] = 'L\'expéditeur est obligatoire.';
        if (empty($objet))         $errors[] = 'L\'objet est obligatoire.';

        if (empty($errors)) {
            $params = [
                $date_arrivee,
                $date_correspondance ?: null,
                $n_correspondance ?: null,
                $expediteur,
                $objet,
                $date_reponse ?: null,
                $n_reponse ?: null,
            ];
            if ($editId) {
                $db->prepare("UPDATE courriers_arrivee SET date_arrivee=?,date_correspondance=?,n_correspondance=?,
                    expediteur=?,objet=?,date_reponse=?,n_reponse=? WHERE id=?")
                   ->execute(array_merge($params, [$editId]));
                setFlash('success', 'Courrier modifié.');
            } else {
                $db->prepare("INSERT INTO courriers_arrivee
                    (date_arrivee,date_correspondance,n_correspondance,expediteur,objet,date_reponse,n_reponse,created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                   ->execute(array_merge($params, [$user['id']]));
                setFlash('success', 'Courrier arrivée enregistré.');
            }
            redirect('/modules/courriers/arrivee.php');
        }
    }
}

// Chargement pour édition
$editRow = null;
if (isset($_GET['edit'])) {
    $editRow = $db->prepare("SELECT * FROM courriers_arrivee WHERE id=?");
    $editRow->execute([(int)$_GET['edit']]);
    $editRow = $editRow->fetch();
}

// Filtres
$search = sanitize($_GET['q'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(expediteur LIKE ? OR objet LIKE ? OR n_correspondance LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
$stmt = $db->prepare("SELECT ca.*, u.nom, u.prenom FROM courriers_arrivee ca
    LEFT JOIN users u ON u.id = ca.created_by
    WHERE " . implode(' AND ', $where) . " ORDER BY ca.date_arrivee DESC, ca.id DESC");
$stmt->execute($params);
$courriers = $stmt->fetchAll();

$pageTitle  = 'Courriers Arrivée';
$breadcrumb = ['Courriers' => null, 'Arrivée' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-inbox me-2 text-success"></i>Courriers Arrivée</h2>
  <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#formPanel">
    <i class="fas fa-plus me-2"></i><?= $editRow ? 'Modifier' : 'Nouveau courrier' ?>
  </button>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<!-- Formulaire -->
<div class="collapse <?= ($errors || $editRow) ? 'show' : '' ?>" id="formPanel">
  <div class="card mb-4">
    <div class="card-header fw-600">
      <i class="fas fa-edit me-2"></i><?= $editRow ? 'Modifier le courrier' : 'Enregistrer un courrier arrivée' ?>
    </div>
    <div class="card-body">
      <form method="POST" novalidate>
        <input type="hidden" name="csrf"    value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">

        <h6 class="text-muted mb-3 border-bottom pb-2">Informations du courrier reçu</h6>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Date d'arrivée *</label>
            <input type="date" name="date_arrivee" class="form-control"
                   value="<?= h($editRow['date_arrivee'] ?? date('Y-m-d')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date de la correspondance</label>
            <input type="date" name="date_correspondance" class="form-control"
                   value="<?= h($editRow['date_correspondance'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">N° de la correspondance</label>
            <input type="text" name="n_correspondance" class="form-control" maxlength="100"
                   value="<?= h($editRow['n_correspondance'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Expéditeur *</label>
            <input type="text" name="expediteur" class="form-control" maxlength="255"
                   value="<?= h($editRow['expediteur'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Objet *</label>
            <input type="text" name="objet" class="form-control"
                   value="<?= h($editRow['objet'] ?? '') ?>" required>
          </div>
        </div>

        <h6 class="text-muted mt-4 mb-3 border-bottom pb-2">Réponse (optionnel)</h6>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Date de la réponse</label>
            <input type="date" name="date_reponse" class="form-control"
                   value="<?= h($editRow['date_reponse'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">N° de la réponse</label>
            <input type="text" name="n_reponse" class="form-control" maxlength="100"
                   value="<?= h($editRow['n_reponse'] ?? '') ?>">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save me-1"></i><?= $editRow ? 'Enregistrer les modifications' : 'Enregistrer' ?>
          </button>
          <?php if ($editRow): ?>
            <a href="arrivee.php" class="btn btn-light">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Recherche -->
<form method="GET" class="mb-3 d-flex gap-2" style="max-width:400px">
  <input type="text" name="q" class="form-control form-control-sm" placeholder="Rechercher expéditeur, objet, N°…"
         value="<?= h($search) ?>">
  <button class="btn btn-sm btn-outline-secondary">Filtrer</button>
  <?php if ($search): ?><a href="arrivee.php" class="btn btn-sm btn-light">×</a><?php endif; ?>
</form>

<!-- Liste -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <strong><?= count($courriers) ?> courrier(s)</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="dataTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Date arrivée</th>
          <th>Date corresp.</th>
          <th>N° corresp.</th>
          <th>Expéditeur</th>
          <th>Objet</th>
          <th>Date réponse</th>
          <th>N° réponse</th>
          <th>Saisi par</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$courriers): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">Aucun courrier arrivée enregistré.</td></tr>
        <?php endif; ?>
        <?php foreach ($courriers as $i => $c): ?>
        <tr>
          <td class="text-muted fs-sm"><?= $i + 1 ?></td>
          <td><?= formatDate($c['date_arrivee']) ?></td>
          <td><?= $c['date_correspondance'] ? formatDate($c['date_correspondance']) : '–' ?></td>
          <td><?= h($c['n_correspondance'] ?? '–') ?></td>
          <td class="fw-500"><?= h($c['expediteur']) ?></td>
          <td><?= h($c['objet']) ?></td>
          <td>
            <?php if ($c['date_reponse']): ?>
              <span class="badge bg-success"><?= formatDate($c['date_reponse']) ?></span>
            <?php else: ?>
              <span class="badge bg-light text-muted">–</span>
            <?php endif; ?>
          </td>
          <td><?= h($c['n_reponse'] ?? '–') ?></td>
          <td class="text-muted fs-sm"><?= h($c['prenom'] . ' ' . $c['nom']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="?edit=<?= $c['id'] ?>" class="btn btn-icon btn-sm btn-outline-warning" title="Modifier">
                <i class="fas fa-edit"></i>
              </a>
              <?php if (hasRole(['admin', 'assistante'])): ?>
              <a href="?action=delete&id=<?= $c['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-icon btn-sm btn-outline-danger" title="Supprimer"
                 onclick="return confirm('Supprimer ce courrier ?')">
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

<?php include APP_ROOT . '/includes/footer.php'; ?>
