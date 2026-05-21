<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur']);

$db     = getDB();
$errors = [];

// Set active annee
if (isset($_GET['activate']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['activate'];
    $db->exec("UPDATE annees_academiques SET actif=0");
    $db->prepare("UPDATE annees_academiques SET actif=1 WHERE id=?")->execute([$id]);
    setFlash('success', 'Année académique activée.');
    redirect('/modules/administration/annees.php');
}

// Set active semestre
if (isset($_GET['activate_sem']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['activate_sem'];
    $db->exec("UPDATE semestres SET actif=0");
    $db->prepare("UPDATE semestres SET actif=1 WHERE id=?")->execute([$id]);
    setFlash('success', 'Semestre activé.');
    redirect('/modules/administration/annees.php');
}

// Save annee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'annee' && hasRole('admin')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $libelle   = sanitize($_POST['libelle']    ?? '');
        $dateDebut = sanitize($_POST['date_debut'] ?? '');
        $dateFin   = sanitize($_POST['date_fin']   ?? '');
        $editId    = (int)($_POST['edit_id']        ?? 0);

        if (empty($libelle)) $errors[] = 'Le libellé est obligatoire.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE annees_academiques SET libelle=?,date_debut=?,date_fin=? WHERE id=?")
                   ->execute([$libelle, $dateDebut ?: null, $dateFin ?: null, $editId]);
                setFlash('success', 'Année modifiée.');
            } else {
                $db->prepare("INSERT INTO annees_academiques (libelle,date_debut,date_fin,actif) VALUES (?,?,?,0)")
                   ->execute([$libelle, $dateDebut ?: null, $dateFin ?: null]);
                setFlash('success', 'Année créée.');
            }
            redirect('/modules/administration/annees.php');
        }
    }
}

// Save semestre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'semestre' && hasRole('admin')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $anneeId   = (int)($_POST['annee_id']      ?? 0);
        $nom       = sanitize($_POST['sem_nom']    ?? '');
        $dateDebut = sanitize($_POST['sem_debut']  ?? '');
        $dateFin   = sanitize($_POST['sem_fin']    ?? '');
        $editId    = (int)($_POST['sem_edit_id']   ?? 0);

        if (empty($nom) || !$anneeId) $errors[] = 'Nom et année obligatoires.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE semestres SET annee_id=?,nom=?,date_debut=?,date_fin=? WHERE id=?")
                   ->execute([$anneeId, $nom, $dateDebut ?: null, $dateFin ?: null, $editId]);
            } else {
                $db->prepare("INSERT INTO semestres (annee_id,nom,date_debut,date_fin,actif) VALUES (?,?,?,?,0)")
                   ->execute([$anneeId, $nom, $dateDebut ?: null, $dateFin ?: null]);
            }
            setFlash('success', 'Semestre enregistré.');
            redirect('/modules/administration/annees.php');
        }
    }
}

$annees   = $db->query("SELECT a.*, COUNT(s.id) as nb_semestres FROM annees_academiques a LEFT JOIN semestres s ON s.annee_id=a.id GROUP BY a.id ORDER BY a.libelle DESC")->fetchAll();
$semestres = $db->query("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id=s.annee_id ORDER BY a.libelle DESC, s.id")->fetchAll();

$pageTitle  = 'Années académiques';
$breadcrumb = ['Administration' => null, 'Années académiques' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-calendar-alt me-2 text-primary"></i>Années Académiques & Semestres</h2>
  <?php if (hasRole('admin')): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#anneeModal">
      <i class="fas fa-plus me-2"></i>Nouvelle année
    </button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#semestreModal">
      <i class="fas fa-plus me-2"></i>Nouveau semestre
    </button>
  </div>
  <?php endif; ?>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<div class="row g-4">
  <!-- Annees -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-calendar me-2 text-primary"></i>Années académiques</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Libellé</th><th>Période</th><th>Semestres</th><th>Statut</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($annees as $a): ?>
            <tr>
              <td class="fw-bold"><?= h($a['libelle']) ?></td>
              <td class="fs-sm text-muted">
                <?= formatDate($a['date_debut']) ?> – <?= formatDate($a['date_fin']) ?>
              </td>
              <td class="text-center"><?= $a['nb_semestres'] ?></td>
              <td>
                <?php if ($a['actif']): ?>
                  <span class="badge bg-success">Active</span>
                <?php elseif (hasRole('admin')): ?>
                  <a href="?activate=<?= $a['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>" class="badge bg-secondary text-decoration-none" onclick="return confirm('Activer cette année ?')">Inactif</a>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactif</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (hasRole('admin')): ?>
                <button class="btn btn-icon btn-sm btn-outline-warning" onclick='editAnnee(<?= json_encode($a) ?>)'><i class="fas fa-edit"></i></button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Semestres -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-sliders-h me-2 text-primary"></i>Semestres</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Année</th><th>Semestre</th><th>Période</th><th>Statut</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($semestres as $s): ?>
            <tr>
              <td class="fs-sm text-muted"><?= h($s['annee_libelle']) ?></td>
              <td class="fw-600"><?= h($s['nom']) ?></td>
              <td class="fs-sm text-muted"><?= formatDate($s['date_debut']) ?> – <?= formatDate($s['date_fin']) ?></td>
              <td>
                <?php if ($s['actif']): ?>
                  <span class="badge bg-success">Actif</span>
                <?php elseif (hasRole('admin')): ?>
                  <a href="?activate_sem=<?= $s['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>" class="badge bg-secondary text-decoration-none" onclick="return confirm('Activer ce semestre ?')">Inactif</a>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactif</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (hasRole('admin')): ?>
                <button class="btn btn-icon btn-sm btn-outline-warning" onclick='editSemestre(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Annee Modal -->
<div class="modal fade" id="anneeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="anneeModalTitle">Nouvelle année académique</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="form_type" value="annee">
        <input type="hidden" name="edit_id" id="an_edit_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Libellé * <small class="text-muted">(Ex: 2024-2025)</small></label>
            <input type="text" name="libelle" id="an_libelle" class="form-control" placeholder="2025-2026" required>
          </div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Date de début</label><input type="date" name="date_debut" id="an_debut" class="form-control"></div>
            <div class="col-6"><label class="form-label">Date de fin</label><input type="date" name="date_fin" id="an_fin" class="form-control"></div>
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

<!-- Semestre Modal -->
<div class="modal fade" id="semestreModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="semModalTitle">Nouveau semestre</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="form_type" value="semestre">
        <input type="hidden" name="sem_edit_id" id="sem_edit_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Année académique *</label>
            <select name="annee_id" id="sem_annee_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>"><?= h($a['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Nom *</label>
            <input type="text" name="sem_nom" id="sem_nom" class="form-control" placeholder="Ex: Semestre 1" required>
          </div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Début</label><input type="date" name="sem_debut" id="sem_debut" class="form-control"></div>
            <div class="col-6"><label class="form-label">Fin</label><input type="date" name="sem_fin" id="sem_fin" class="form-control"></div>
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
function editAnnee(a) {
  document.getElementById('anneeModalTitle').textContent = 'Modifier l\'année';
  document.getElementById('an_edit_id').value  = a.id;
  document.getElementById('an_libelle').value  = a.libelle;
  document.getElementById('an_debut').value    = a.date_debut || '';
  document.getElementById('an_fin').value      = a.date_fin   || '';
  new bootstrap.Modal(document.getElementById('anneeModal')).show();
}
function editSemestre(s) {
  document.getElementById('semModalTitle').textContent  = 'Modifier le semestre';
  document.getElementById('sem_edit_id').value   = s.id;
  document.getElementById('sem_annee_id').value  = s.annee_id;
  document.getElementById('sem_nom').value        = s.nom;
  document.getElementById('sem_debut').value      = s.date_debut || '';
  document.getElementById('sem_fin').value        = s.date_fin   || '';
  new bootstrap.Modal(document.getElementById('semestreModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
