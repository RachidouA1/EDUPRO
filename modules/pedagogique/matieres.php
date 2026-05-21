<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'enseignant', 'coordinateur']);

$db     = getDB();
$errors = [];
try { $db->exec("ALTER TABLE matieres ADD COLUMN formule_calcul VARCHAR(20) NOT NULL DEFAULT 'pondere'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN seuil_reussite INT NOT NULL DEFAULT 12"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN ue_id INT NULL"); } catch (PDOException $e) {}
$action = sanitize($_GET['action'] ?? 'list');
$id     = (int)($_GET['id'] ?? 0);

// Delete
if ($action === 'delete' && $id && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $canDel = hasRole('admin');
    if (!$canDel && hasRole('coordinateur')) {
        $chk = $db->prepare("SELECT filiere_id FROM matieres WHERE id=?");
        $chk->execute([$id]);
        $chkRow = $chk->fetch();
        $canDel = $chkRow && (int)$chkRow['filiere_id'] === getCoordinateurFiliereId();
    }
    if ($canDel) {
        $db->prepare("UPDATE matieres SET actif=0 WHERE id=?")->execute([$id]);
        setFlash('success', 'Matière désactivée.');
    }
    redirect('/modules/pedagogique/matieres.php');
}

// Save (add or edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (hasRole('admin') || hasRole('coordinateur'))) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $data = [
            'code'            => strtoupper(sanitize($_POST['code']         ?? '')),
            'nom'             => sanitize($_POST['nom']                      ?? ''),
            'filiere_id'      => (int)($_POST['filiere_id']                 ?? 0),
            'niveau_id'       => (int)($_POST['niveau_id']                  ?? 0),
            'semestre_id'     => (int)($_POST['semestre_id']                ?? 0),
            'coefficient'     => (float)($_POST['coefficient']              ?? 1),
            'volume_horaire'  => (int)($_POST['volume_horaire']             ?? 0),
            'enseignant_id'   => (int)($_POST['enseignant_id']              ?? 0),
            'ue_id'           => (int)($_POST['ue_id']                      ?? 0) ?: null,
            'seuil_reussite'  => max(0, min(20, (int)($_POST['seuil_reussite'] ?? 12))),
            'formule_calcul'  => in_array($_POST['formule_calcul'] ?? '', ['pondere','demi_somme','exam_seul'])
                                 ? $_POST['formule_calcul'] : 'pondere',
        ];

        if (empty($data['code'])) $errors[] = 'Le code est obligatoire.';
        if (empty($data['nom']))  $errors[] = 'Le nom est obligatoire.';

        // Coordinateur can only manage their own filière
        if (hasRole('coordinateur')) {
            $coordFId = getCoordinateurFiliereId();
            $data['filiere_id'] = $coordFId;
            if (!$coordFId) $errors[] = 'Filière non définie pour ce coordinateur.';
            $editId2 = (int)($_POST['edit_id'] ?? 0);
            if ($editId2 && empty($errors)) {
                $chk2 = $db->prepare("SELECT filiere_id FROM matieres WHERE id=?");
                $chk2->execute([$editId2]);
                $r2 = $chk2->fetch();
                if ($r2 && (int)$r2['filiere_id'] !== $coordFId) $errors[] = 'Accès refusé.';
            }
        }

        if (empty($errors)) {
            $editId = (int)($_POST['edit_id'] ?? 0);
            if ($editId) {
                $db->prepare("UPDATE matieres SET code=?,nom=?,filiere_id=?,niveau_id=?,semestre_id=?,coefficient=?,volume_horaire=?,enseignant_id=?,ue_id=?,seuil_reussite=?,formule_calcul=? WHERE id=?")
                   ->execute([$data['code'], $data['nom'], $data['filiere_id'] ?: null, $data['niveau_id'] ?: null, $data['semestre_id'] ?: null, $data['coefficient'], $data['volume_horaire'], $data['enseignant_id'] ?: null, $data['ue_id'], $data['seuil_reussite'], $data['formule_calcul'], $editId]);
                setFlash('success', 'Matière modifiée.');
            } else {
                try {
                    $db->prepare("INSERT INTO matieres (code, nom, filiere_id, niveau_id, semestre_id, coefficient, volume_horaire, enseignant_id, ue_id, seuil_reussite, formule_calcul) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$data['code'], $data['nom'], $data['filiere_id'] ?: null, $data['niveau_id'] ?: null, $data['semestre_id'] ?: null, $data['coefficient'], $data['volume_horaire'], $data['enseignant_id'] ?: null, $data['ue_id'], $data['seuil_reussite'], $data['formule_calcul']]);
                    setFlash('success', 'Matière ajoutée.');
                } catch (PDOException $e) {
                    $errors[] = 'Ce code existe déjà.';
                }
            }
            if (empty($errors)) redirect('/modules/pedagogique/matieres.php');
        }
    }
}

// Fetch for edit
$editMatiere = null;
if ($action === 'edit' && $id) {
    $editMatiere = $db->prepare("SELECT * FROM matieres WHERE id=?");
    $editMatiere->execute([$id]);
    $editMatiere = $editMatiere->fetch();
}

// List — coordinateur is locked to their own filière
$fFilter = (int)($_GET['filiere_id'] ?? 0);
$nFilter = (int)($_GET['niveau_id']  ?? 0);
if (hasRole('coordinateur')) {
    $fFilter = getCoordinateurFiliereId();
}
$where   = ['m.actif=1'];
$params  = [];
if ($fFilter) { $where[] = 'm.filiere_id=?'; $params[] = $fFilter; }
if ($nFilter) { $where[] = 'm.niveau_id=?';  $params[] = $nFilter; }
$stmt = $db->prepare("
    SELECT m.*, f.nom as filiere_nom, f.code as filiere_code, n.nom as niveau_nom,
           s.nom as semestre_nom, CONCAT(e.prenom,' ',e.nom) as enseignant_nom,
           u.nom as ue_nom, u.code_ue
    FROM matieres m
    LEFT JOIN filieres f ON f.id = m.filiere_id
    LEFT JOIN niveaux n ON n.id = m.niveau_id
    LEFT JOIN semestres s ON s.id = m.semestre_id
    LEFT JOIN enseignants e ON e.id = m.enseignant_id
    LEFT JOIN ue u ON u.id = m.ue_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY f.nom, n.ordre, m.nom
");
$stmt->execute($params);
$matieres = $stmt->fetchAll();

$filieres    = getFilieres();
$niveaux     = getNiveaux();
$semestres   = getSemestres();
$enseignants = $db->query("SELECT id, nom, prenom, specialite FROM enseignants WHERE actif=1 ORDER BY nom")->fetchAll();
// UEs pour le formulaire (table peut ne pas exister encore)
$ues_list = [];
try {
    $ues_list = $db->query("SELECT id, nom, code_ue, semestre_num, filiere_id FROM ue WHERE actif=1 ORDER BY filiere_id, semestre_num, code_ue")->fetchAll();
} catch (PDOException $e) {}

$pageTitle  = 'Matières & Modules';
$breadcrumb = ['Pédagogie' => null, 'Matières' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-book-open me-2 text-primary"></i>Matières & Modules</h2>
  <?php if (hasRole('admin') || hasRole('coordinateur')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#matiereModal" onclick="setFormMode('add')">
    <i class="fas fa-plus me-2"></i>Nouvelle matière
  </button>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <select name="filiere_id" class="form-select" onchange="this.form.submit()">
          <option value="">Toutes filières</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $fFilter == $f['id'] ? 'selected' : '' ?>><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="niveau_id" class="form-select" onchange="this.form.submit()">
          <option value="">Tous niveaux</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $nFilter == $n['id'] ? 'selected' : '' ?>><?= h($n['filiere_nom']) ?> – <?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <a href="?" class="btn btn-light w-100"><i class="fas fa-times me-1"></i>Effacer</a>
      </div>
    </form>
  </div>
</div>

<!-- Errors -->
<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<!-- Table -->
<div class="card">
  <div class="card-header"><strong><?= count($matieres) ?></strong> matière(s)</div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <thead>
        <tr><th>Code</th><th>Nom</th><th>Filière / Niveau</th><th>Semestre</th><th>UE</th><th>Seuil</th><th>Formule</th><th>Coef.</th><th>Enseignant</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($matieres)): ?>
          <tr><td colspan="10" class="text-center py-4 text-muted">Aucune matière</td></tr>
        <?php endif; ?>
        <?php foreach ($matieres as $m): ?>
        <tr>
          <td><code class="fs-sm"><?= h($m['code']) ?></code></td>
          <td class="fw-600"><?= h($m['nom']) ?></td>
          <td class="fs-sm">
            <?= h($m['filiere_code'] ?? '-') ?>
            <?php if ($m['niveau_nom']): ?> / <?= h($m['niveau_nom']) ?><?php endif; ?>
          </td>
          <td class="fs-sm text-muted"><?= h($m['semestre_nom'] ?? '-') ?></td>
          <td class="fs-sm">
            <?= $m['ue_nom'] ? '<span class="badge bg-info text-dark">'.h($m['code_ue']).'</span>' : '<span class="text-muted">–</span>' ?>
          </td>
          <td class="text-center fs-sm"><?= (int)($m['seuil_reussite'] ?? 12) ?>/20</td>
          <td>
            <?php
              $fc = $m['formule_calcul'] ?? 'pondere';
              // ASB/VP always use demi_somme regardless of stored value
              if (in_array(strtoupper($m['filiere_code'] ?? ''), ['ASB','VP'])) $fc = 'demi_somme';
              [$fLabel, $fClass] = match($fc) {
                  'demi_somme' => ['(CC+E)÷2',  'info'],
                  'exam_seul'  => ['Exam seul', 'secondary'],
                  default      => ['CC×40%+E×60%', 'primary'],
              };
            ?>
            <span class="badge bg-<?= $fClass ?> fs-sm"><?= $fLabel ?></span>
          </td>
          <td><span class="badge bg-primary"><?= $m['coefficient'] ?></span></td>
          <td class="fs-sm"><?= h($m['enseignant_nom'] ?? '-') ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php
                $canEditMat = hasRole('admin') || (hasRole('coordinateur') && (int)($m['filiere_id'] ?? 0) === getCoordinateurFiliereId());
              ?>
              <?php if ($canEditMat): ?>
              <button class="btn btn-icon btn-sm btn-outline-warning" onclick='editMatiere(<?= json_encode($m) ?>)' title="Modifier" data-bs-toggle="tooltip"><i class="fas fa-edit"></i></button>
              <a href="?action=delete&id=<?= $m['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>" class="btn btn-icon btn-sm btn-outline-danger" onclick="return confirm('Supprimer cette matière ?')" title="Supprimer" data-bs-toggle="tooltip"><i class="fas fa-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="matiereModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="matiereModalTitle">Nouvelle matière</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" id="edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Code <span class="text-danger">*</span></label>
              <input type="text" name="code" id="f_code" class="form-control" placeholder="EX: ANT101" style="text-transform:uppercase" required>
            </div>
            <div class="col-md-9">
              <label class="form-label">Nom de la matière <span class="text-danger">*</span></label>
              <input type="text" name="nom" id="f_nom" class="form-control" placeholder="Ex: Anatomie Générale" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Filière</label>
              <select name="filiere_id" id="f_filiere_id" class="form-select" onchange="onFiliereChange()">
                <option value="">-- Toutes --</option>
                <?php foreach ($filieres as $f): ?>
                  <option value="<?= $f['id'] ?>" data-code="<?= h($f['code']) ?>"><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Niveau</label>
              <select name="niveau_id" id="f_niveau_id" class="form-select">
                <option value="">-- Tous --</option>
                <?php foreach ($niveaux as $n): ?>
                  <option value="<?= $n['id'] ?>" data-filiere="<?= $n['filiere_id'] ?>"><?= h($n['filiere_nom']) ?> – <?= h($n['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4" id="semestre_row_wrap">
              <label class="form-label">Semestre</label>
              <select name="semestre_id" id="f_semestre_id" class="form-select">
                <option value="">-- Aucun --</option>
                <?php foreach ($semestres as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= h($s['annee_libelle'] ?? '') ?> – <?= h($s['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8" id="formule_row_wrap">
              <label class="form-label">Formule de calcul de la moyenne <small class="text-muted">(UE)</small></label>
              <select name="formule_calcul" id="f_formule_calcul" class="form-select">
                <option value="pondere">CC × 40 % + Examen × 60 % (pondéré)</option>
                <option value="demi_somme">(Note de classe + Examen) ÷ 2 (moyenne simple)</option>
                <option value="exam_seul">Examen seul (sans CC)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Coefficient</label>
              <input type="number" name="coefficient" id="f_coefficient" class="form-control" min="0.5" max="10" step="0.5" value="1">
            </div>
            <div class="col-md-4">
              <label class="form-label">Volume horaire (h)</label>
              <input type="number" name="volume_horaire" id="f_volume_horaire" class="form-control" min="0" step="1" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">Enseignant assigné</label>
              <select name="enseignant_id" id="f_enseignant_id" class="form-select">
                <option value="">-- Aucun --</option>
                <?php foreach ($enseignants as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= h($e['prenom'].' '.$e['nom']) ?><?= $e['specialite'] ? ' ('.$e['specialite'].')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Seuil réussite /20 <small class="text-muted">(défaut 12)</small></label>
              <input type="number" name="seuil_reussite" id="f_seuil_reussite" class="form-control" min="0" max="20" step="1" value="12">
            </div>
            <div class="col-md-3"></div>
            <?php if (!empty($ues_list)): ?>
            <div class="col-12">
              <label class="form-label">Unité d'Enseignement (UE) <small class="text-muted">– Niveau supérieur uniquement</small></label>
              <select name="ue_id" id="f_ue_id" class="form-select">
                <option value="">-- Aucune UE --</option>
                <?php foreach ($ues_list as $ue): ?>
                  <option value="<?= $ue['id'] ?>" data-filiere="<?= $ue['filiere_id'] ?>">
                    <?= h($ue['code_ue']) ?> – <?= h($ue['nom']) ?> (S<?= $ue['semestre_num'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
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
// Codes de filières sans notion de semestre
const NO_SEMESTRE_CODES = ['ASB', 'VP'];

function getSelectedFiliereCode() {
  const sel = document.getElementById('f_filiere_id');
  const opt = sel.options[sel.selectedIndex];
  return opt ? (opt.getAttribute('data-code') || '').toUpperCase() : '';
}

function updateSemestreRow() {
  const fId  = document.getElementById('f_filiere_id').value;
  const code = getSelectedFiliereCode();
  const isNoSem = fId !== '' && NO_SEMESTRE_CODES.includes(code);

  // Semestre: hide for ASB/VP
  const semWrap = document.getElementById('semestre_row_wrap');
  semWrap.style.display = isNoSem ? 'none' : '';
  if (isNoSem) document.getElementById('f_semestre_id').value = '';

  // Formule: hide for ASB/VP (formula is automatic: demi_somme)
  const fmWrap = document.getElementById('formule_row_wrap');
  if (fmWrap) {
    fmWrap.style.display = isNoSem ? 'none' : '';
    if (isNoSem) document.getElementById('f_formule_calcul').value = 'demi_somme';
  }
}

function filterNiveaux(selectedId) {
  const fId    = document.getElementById('f_filiere_id').value;
  const sel    = document.getElementById('f_niveau_id');
  const opts   = sel.querySelectorAll('option[data-filiere]');
  opts.forEach(opt => {
    opt.style.display = (!fId || opt.getAttribute('data-filiere') === fId) ? '' : 'none';
  });
  // Keep or reset selection
  if (selectedId) {
    sel.value = selectedId;
  } else if (fId) {
    const current = sel.value;
    const visible = sel.querySelector('option[data-filiere="' + fId + '"]');
    // Reset if current option belongs to a different filière
    const currentOpt = sel.querySelector('option[value="' + current + '"][data-filiere]');
    if (currentOpt && currentOpt.getAttribute('data-filiere') !== fId) sel.value = '';
  }
}

function onFiliereChange() {
  filterNiveaux(null);
  updateSemestreRow();
}

function setFormMode(mode) {
  document.getElementById('matiereModalTitle').textContent = mode === 'add' ? 'Nouvelle matière' : 'Modifier la matière';
  document.getElementById('edit_id').value = '';
  ['code','nom','coefficient','volume_horaire'].forEach(f => {
    const el = document.getElementById('f_'+f);
    if (el) el.value = f === 'coefficient' ? '1' : (f === 'volume_horaire' ? '0' : '');
  });
  ['filiere_id','niveau_id','semestre_id','enseignant_id','ue_id'].forEach(f => {
    const el = document.getElementById('f_'+f);
    if (el) el.value = '';
  });
  const fc = document.getElementById('f_formule_calcul');
  if (fc) fc.value = 'pondere';
  const sr = document.getElementById('f_seuil_reussite');
  if (sr) sr.value = '12';
  filterNiveaux(null);
  updateSemestreRow();
}

function editMatiere(m) {
  document.getElementById('matiereModalTitle').textContent = 'Modifier la matière';
  document.getElementById('edit_id').value = m.id;
  document.getElementById('f_code').value = m.code || '';
  document.getElementById('f_nom').value = m.nom || '';
  document.getElementById('f_filiere_id').value = m.filiere_id || '';
  document.getElementById('f_coefficient').value = m.coefficient || '1';
  document.getElementById('f_volume_horaire').value = m.volume_horaire || '0';
  document.getElementById('f_enseignant_id').value = m.enseignant_id || '';
  filterNiveaux(m.niveau_id || null);
  updateSemestreRow();
  document.getElementById('f_semestre_id').value = m.semestre_id    || '';
  const fc = document.getElementById('f_formule_calcul');
  if (fc) fc.value = m.formule_calcul || 'pondere';
  const sr = document.getElementById('f_seuil_reussite');
  if (sr) sr.value = m.seuil_reussite ?? '12';
  const ue = document.getElementById('f_ue_id');
  if (ue) ue.value = m.ue_id || '';
  new bootstrap.Modal(document.getElementById('matiereModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
