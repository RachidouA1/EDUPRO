<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur']);

$db      = getDB();
$ecoleId = getEcoleId();
$errors  = [];
$action  = sanitize($_GET['action'] ?? 'list');
$id      = (int)($_GET['id'] ?? 0);

// Ensure columns exist (idempotent)
try { $db->exec("ALTER TABLE matieres ADD COLUMN ue_id INT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN seuil_reussite INT NOT NULL DEFAULT 12"); } catch (PDOException $e) {}

// Delete (soft)
if ($action === 'delete' && $id && hasRole('admin') && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $check = $db->prepare("SELECT COUNT(*) FROM matieres WHERE ue_id = ? AND ecole_id = ?");
    $check->execute([$id, $ecoleId]);
    if ($check->fetchColumn() > 0) {
        setFlash('error', 'Impossible de supprimer : des matières sont liées à cette UE.');
    } else {
        $db->prepare("DELETE FROM ue WHERE id=? AND ecole_id=?")->execute([$id, $ecoleId]);
        setFlash('success', 'UE supprimée.');
    }
    redirect('/modules/pedagogique/ue.php');
}

// Save (add or edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole('admin')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $data = [
            'nom'          => sanitize($_POST['nom']         ?? ''),
            'code_ue'      => strtoupper(sanitize($_POST['code_ue'] ?? '')),
            'semestre_num' => max(1, min(6, (int)($_POST['semestre_num'] ?? 1))),
            'coefficient'  => max(0.5, (float)($_POST['coefficient']    ?? 1)),
            'credit'       => max(1,   (int)($_POST['credit']           ?? 3)),
            'filiere_id'   => (int)($_POST['filiere_id'] ?? 0) ?: null,
        ];

        if (empty($data['nom']))     $errors[] = 'Le nom est obligatoire.';
        if (empty($data['code_ue'])) $errors[] = 'Le code UE est obligatoire.';

        if (empty($errors)) {
            $editId = (int)($_POST['edit_id'] ?? 0);
            try {
                if ($editId) {
                    $db->prepare("UPDATE ue SET nom=?,code_ue=?,semestre_num=?,coefficient=?,credit=?,filiere_id=? WHERE id=?")
                       ->execute([$data['nom'],$data['code_ue'],$data['semestre_num'],
                                  $data['coefficient'],$data['credit'],$data['filiere_id'],$editId]);
                    setFlash('success', 'UE modifiée avec succès.');
                } else {
                    $db->prepare("INSERT INTO ue (ecole_id,nom,code_ue,semestre_num,coefficient,credit,filiere_id) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$ecoleId,$data['nom'],$data['code_ue'],$data['semestre_num'],
                                  $data['coefficient'],$data['credit'],$data['filiere_id']]);
                    setFlash('success', 'UE ajoutée avec succès.');
                }
            } catch (PDOException $e) {
                $errors[] = $e->getCode() === '23000' ? 'Ce code UE existe déjà pour cette filière.' : 'Erreur : ' . $e->getMessage();
            }
            if (empty($errors)) redirect('/modules/pedagogique/ue.php');
        }
    }
}

// Filters & list
$fFilter = (int)($_GET['filiere_id'] ?? 0);
$sFilter = (int)($_GET['semestre_num'] ?? 0);
$where   = ['u.ecole_id=?'];
$params  = [$ecoleId];
if ($fFilter) { $where[] = 'u.filiere_id=?'; $params[] = $fFilter; }
if ($sFilter) { $where[] = 'u.semestre_num=?'; $params[] = $sFilter; }

$stmt = $db->prepare("
    SELECT u.*, f.nom as filiere_nom, f.code as filiere_code,
           (SELECT COUNT(*) FROM matieres m WHERE m.ue_id=u.id AND m.ecole_id=u.ecole_id) as nb_matieres
    FROM ue u
    LEFT JOIN filieres f ON f.id = u.filiere_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY f.nom, u.semestre_num, u.code_ue
");
$stmt->execute($params);
$ues = $stmt->fetchAll();

$filieres = getFilieres();

$pageTitle  = 'Unités d\'Enseignement (UE)';
$breadcrumb = ['Pédagogie' => null, 'UE' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-layer-group me-2 text-primary"></i>Unités d'Enseignement (UE)</h2>
  <?php if (hasRole('admin')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ueModal" onclick="setFormMode('add')">
    <i class="fas fa-plus me-2"></i>Nouvelle UE
  </button>
  <?php endif; ?>
</div>

<!-- Filtres -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <select name="filiere_id" class="form-select" onchange="this.form.submit()">
          <option value="">Toutes filières</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $fFilter == $f['id'] ? 'selected' : '' ?>>
              <?= h($f['code']) ?> – <?= h($f['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="semestre_num" class="form-select" onchange="this.form.submit()">
          <option value="">Tous semestres</option>
          <?php for ($s = 1; $s <= 6; $s++): ?>
            <option value="<?= $s ?>" <?= $sFilter == $s ? 'selected' : '' ?>>
              Semestre <?= $s ?> (Année <?= ceil($s/2) ?>)
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <a href="?" class="btn btn-light w-100"><i class="fas fa-times me-1"></i>Effacer</a>
      </div>
    </form>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><strong><?= count($ues) ?></strong> unité(s) d'enseignement</span>
    <small class="text-muted">Seuil de validation UE : 10/20 | Seuil matière : configurable (défaut 12/20)</small>
  </div>
  <div class="table-responsive">
    <table class="table table-hover" id="dataTable">
      <thead>
        <tr>
          <th>Code UE</th>
          <th>Nom</th>
          <th>Filière</th>
          <th>Semestre</th>
          <th class="text-center">Coef.</th>
          <th class="text-center">Crédits ECTS</th>
          <th class="text-center">Matières</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ues)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">
            <i class="fas fa-inbox d-block mb-2" style="font-size:2rem"></i>Aucune UE trouvée
          </td></tr>
        <?php endif; ?>
        <?php foreach ($ues as $u): ?>
        <tr>
          <td><code class="fs-sm"><?= h($u['code_ue']) ?></code></td>
          <td class="fw-600"><?= h($u['nom']) ?></td>
          <td class="fs-sm">
            <?= $u['filiere_code']
                ? h($u['filiere_code']).' – '.h($u['filiere_nom'])
                : '<span class="text-muted">Toutes</span>' ?>
          </td>
          <td>
            <span class="badge bg-info text-dark">
              S<?= $u['semestre_num'] ?> <small>– Année <?= ceil($u['semestre_num']/2) ?></small>
            </span>
          </td>
          <td class="text-center"><span class="badge bg-primary"><?= $u['coefficient'] ?></span></td>
          <td class="text-center"><span class="badge bg-success"><?= $u['credit'] ?> ECTS</span></td>
          <td class="text-center">
            <a href="matieres.php?ue_filter=<?= $u['id'] ?>" class="badge bg-secondary text-decoration-none">
              <?= $u['nb_matieres'] ?> matière(s)
            </a>
          </td>
          <td>
            <?php if (hasRole('admin')): ?>
            <div class="d-flex gap-1">
              <button class="btn btn-icon btn-sm btn-outline-warning"
                      onclick='editUE(<?= json_encode($u) ?>)'
                      title="Modifier" data-bs-toggle="tooltip">
                <i class="fas fa-edit"></i>
              </button>
              <a href="?action=delete&id=<?= $u['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-icon btn-sm btn-outline-danger"
                 onclick="return confirm('Supprimer cette UE ?')"
                 title="Supprimer" data-bs-toggle="tooltip">
                <i class="fas fa-trash"></i>
              </a>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Ajouter/Modifier -->
<div class="modal fade" id="ueModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ueModalTitle">Nouvelle UE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" id="edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Code UE <span class="text-danger">*</span></label>
              <input type="text" name="code_ue" id="f_code_ue" class="form-control"
                     placeholder="Ex: UE101" style="text-transform:uppercase" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Nom de l'UE <span class="text-danger">*</span></label>
              <input type="text" name="nom" id="f_nom" class="form-control"
                     placeholder="Ex: Sciences Biologiques Fondamentales" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Filière</label>
              <select name="filiere_id" id="f_filiere_id" class="form-select">
                <option value="">-- Toutes filières --</option>
                <?php foreach ($filieres as $f): ?>
                  <option value="<?= $f['id'] ?>"><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Semestre du programme</label>
              <select name="semestre_num" id="f_semestre_num" class="form-select">
                <option value="1">Semestre 1 (Année 1)</option>
                <option value="2">Semestre 2 (Année 1)</option>
                <option value="3">Semestre 3 (Année 2)</option>
                <option value="4">Semestre 4 (Année 2)</option>
                <option value="5">Semestre 5 (Année 3)</option>
                <option value="6">Semestre 6 (Année 3)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Coefficient</label>
              <input type="number" name="coefficient" id="f_coefficient" class="form-control"
                     min="0.5" max="10" step="0.5" value="1">
            </div>
            <div class="col-md-4">
              <label class="form-label">Crédits ECTS</label>
              <input type="number" name="credit" id="f_credit" class="form-control"
                     min="1" max="30" step="1" value="3">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setFormMode(mode) {
  document.getElementById('ueModalTitle').textContent = mode === 'add' ? 'Nouvelle UE' : "Modifier l'UE";
  document.getElementById('edit_id').value      = '';
  document.getElementById('f_code_ue').value    = '';
  document.getElementById('f_nom').value        = '';
  document.getElementById('f_filiere_id').value = '';
  document.getElementById('f_semestre_num').value = '1';
  document.getElementById('f_coefficient').value = '1';
  document.getElementById('f_credit').value     = '3';
}
function editUE(u) {
  document.getElementById('ueModalTitle').textContent = "Modifier l'UE";
  document.getElementById('edit_id').value        = u.id;
  document.getElementById('f_code_ue').value      = u.code_ue  || '';
  document.getElementById('f_nom').value          = u.nom      || '';
  document.getElementById('f_filiere_id').value   = u.filiere_id  || '';
  document.getElementById('f_semestre_num').value = u.semestre_num || '1';
  document.getElementById('f_coefficient').value  = u.coefficient  || '1';
  document.getElementById('f_credit').value       = u.credit     || '3';
  new bootstrap.Modal(document.getElementById('ueModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
