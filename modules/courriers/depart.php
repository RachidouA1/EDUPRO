<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'assistante']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$errors  = [];

// Inline migration : créer la table si elle n'existe pas encore
try {
    $db->exec("CREATE TABLE IF NOT EXISTS courriers_depart (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        nbre_pieces INT NOT NULL DEFAULT 1,
        date_depart DATE NOT NULL,
        destinataire VARCHAR(255) NOT NULL,
        objet       TEXT NOT NULL,
        n_archives  VARCHAR(100) DEFAULT NULL,
        observations TEXT DEFAULT NULL,
        created_by  INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// Suppression — réservée aux admin et assistante
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    if (!hasRole(['admin', 'assistante'])) {
        setFlash('error', 'Action non autorisée.');
        redirect('/modules/courriers/depart.php');
    }
    $db->prepare("DELETE FROM courriers_depart WHERE id=?")->execute([(int)$_GET['id']]);
    setFlash('success', 'Courrier départ supprimé.');
    redirect('/modules/courriers/depart.php');
}

// Enregistrement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $editId      = (int)($_POST['edit_id'] ?? 0);
        $nbre_pieces = max(1, (int)($_POST['nbre_pieces'] ?? 1));
        $date_depart = sanitize($_POST['date_depart'] ?? '');
        $destinataire = sanitize($_POST['destinataire'] ?? '');
        $objet       = sanitize($_POST['objet'] ?? '');
        $n_archives  = sanitize($_POST['n_archives'] ?? '');
        $observations = sanitize($_POST['observations'] ?? '');

        if (empty($date_depart))   $errors[] = 'La date de départ est obligatoire.';
        if (empty($destinataire))  $errors[] = 'Le destinataire est obligatoire.';
        if (empty($objet))         $errors[] = 'L\'objet est obligatoire.';

        if (empty($errors)) {
            if ($editId) {
                // Admin peut modifier tout enregistrement; les autres uniquement le leur
                $canEdit = hasRole('admin');
                if (!$canEdit) {
                    $chk = $db->prepare("SELECT id FROM courriers_depart WHERE id=? AND created_by=?");
                    $chk->execute([$editId, $user['id']]);
                    $canEdit = $chk->rowCount() > 0;
                }
                if (!$canEdit) {
                    $errors[] = 'Vous ne pouvez modifier que vos propres courriers.';
                } else {
                    $db->prepare("UPDATE courriers_depart SET nbre_pieces=?,date_depart=?,destinataire=?,objet=?,n_archives=?,observations=? WHERE id=?")
                       ->execute([$nbre_pieces, $date_depart, $destinataire, $objet, $n_archives ?: null, $observations ?: null, $editId]);
                    setFlash('success', 'Courrier modifié.');
                    redirect('/modules/courriers/depart.php');
                }
            } else {
                if ($ecoleId > 0) {
                    $db->prepare("INSERT INTO courriers_depart (nbre_pieces,date_depart,destinataire,objet,n_archives,observations,created_by,ecole_id) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$nbre_pieces, $date_depart, $destinataire, $objet, $n_archives ?: null, $observations ?: null, $user['id'], $ecoleId]);
                } else {
                    $db->prepare("INSERT INTO courriers_depart (nbre_pieces,date_depart,destinataire,objet,n_archives,observations,created_by) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$nbre_pieces, $date_depart, $destinataire, $objet, $n_archives ?: null, $observations ?: null, $user['id']]);
                }
                setFlash('success', 'Courrier départ enregistré.');
                redirect('/modules/courriers/depart.php');
            }
        }
    }
}

// Chargement pour édition
$editRow = null;
if (isset($_GET['edit'])) {
    $editRow = $db->prepare("SELECT * FROM courriers_depart WHERE id=?");
    $editRow->execute([(int)$_GET['edit']]);
    $editRow = $editRow->fetch();
}

// Filtres
$search = sanitize($_GET['q'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(destinataire LIKE ? OR objet LIKE ? OR n_archives LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($ecoleId > 0) { $where[] = 'cd.ecole_id = ?'; $params[] = $ecoleId; }
$stmt = $db->prepare("SELECT cd.*, u.nom, u.prenom FROM courriers_depart cd
    LEFT JOIN users u ON u.id = cd.created_by
    WHERE " . implode(' AND ', $where) . " ORDER BY cd.date_depart DESC, cd.id DESC");
$stmt->execute($params);
$courriers = $stmt->fetchAll();

$pageTitle  = 'Courriers Départ';
$breadcrumb = ['Courriers' => null, 'Départ' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-paper-plane me-2 text-primary"></i>Courriers Départ</h2>
  <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#formPanel">
    <i class="fas fa-plus me-2"></i><?= $editRow ? 'Modifier' : 'Nouveau courrier' ?>
  </button>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<!-- Formulaire -->
<div class="collapse <?= ($errors || $editRow) ? 'show' : '' ?>" id="formPanel">
  <div class="card mb-4">
    <div class="card-header fw-600">
      <i class="fas fa-edit me-2"></i><?= $editRow ? 'Modifier le courrier' : 'Enregistrer un courrier départ' ?>
    </div>
    <div class="card-body">
      <form method="POST" novalidate>
        <input type="hidden" name="csrf"    value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Nbre de pièces *</label>
            <input type="number" name="nbre_pieces" class="form-control" min="1"
                   value="<?= h((string)($editRow['nbre_pieces'] ?? 1)) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Date de départ *</label>
            <input type="date" name="date_depart" class="form-control"
                   value="<?= h($editRow['date_depart'] ?? date('Y-m-d')) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Destinataire *</label>
            <input type="text" name="destinataire" class="form-control" maxlength="255"
                   value="<?= h($editRow['destinataire'] ?? '') ?>" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Objet *</label>
            <input type="text" name="objet" class="form-control"
                   value="<?= h($editRow['objet'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">N° Archives</label>
            <input type="text" name="n_archives" class="form-control" maxlength="100"
                   value="<?= h($editRow['n_archives'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Observations</label>
            <textarea name="observations" class="form-control" rows="2"><?= h($editRow['observations'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i><?= $editRow ? 'Enregistrer les modifications' : 'Enregistrer' ?>
          </button>
          <?php if ($editRow): ?>
            <a href="depart.php" class="btn btn-light">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Recherche -->
<form method="GET" class="mb-3 d-flex gap-2" style="max-width:400px">
  <input type="text" name="q" class="form-control form-control-sm" placeholder="Rechercher destinataire, objet, N° archives…"
         value="<?= h($search) ?>">
  <button class="btn btn-sm btn-outline-secondary">Filtrer</button>
  <?php if ($search): ?><a href="depart.php" class="btn btn-sm btn-light">×</a><?php endif; ?>
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
          <th>Date départ</th>
          <th>Pièces</th>
          <th>Destinataire</th>
          <th>Objet</th>
          <th>N° Archives</th>
          <th>Observations</th>
          <th>Saisi par</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$courriers): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">Aucun courrier départ enregistré.</td></tr>
        <?php endif; ?>
        <?php foreach ($courriers as $i => $c): ?>
        <tr>
          <td class="text-muted fs-sm"><?= $i + 1 ?></td>
          <td><?= formatDate($c['date_depart']) ?></td>
          <td><span class="badge bg-secondary"><?= (int)$c['nbre_pieces'] ?></span></td>
          <td class="fw-500"><?= h($c['destinataire']) ?></td>
          <td><?= h($c['objet']) ?></td>
          <td><?= h($c['n_archives'] ?? '–') ?></td>
          <td class="text-muted fs-sm"><?= h($c['observations'] ?? '–') ?></td>
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
