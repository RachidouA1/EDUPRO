<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur']);

$db     = getDB();
$errors = [];

// Inline migrations
try { $db->exec("ALTER TABLE filieres ADD COLUMN tronc_commun TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE filieres ADD COLUMN tronc_commun_id INT NULL"); } catch (PDOException $e) {}

// Create LSIO/TC if absent
$tcExists = (int)$db->query("SELECT COUNT(*) FROM filieres WHERE code='LSIO/TC'")->fetchColumn();
if (!$tcExists) {
    $db->exec("INSERT INTO filieres (code, nom, description, duree_annees, tronc_commun, actif)
               VALUES ('LSIO/TC', 'Tronc Commun — Infirmier & Sage-Femme',
                       'Première année commune aux filières Infirmier et Sage-Femme (niveau supérieur)', 1, 1, 1)");
    $tcId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO niveaux (filiere_id, nom, ordre) VALUES (?, 'Tronc Commun', 1)")->execute([$tcId]);
}

// Delete filiere
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $db->prepare("UPDATE filieres SET actif=0 WHERE id=?")->execute([(int)$_GET['id']]);
    setFlash('success', 'Filière désactivée.');
    redirect('/modules/administration/filieres.php');
}

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole(['admin', 'directeur'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $code           = strtoupper(sanitize($_POST['code']        ?? ''));
        $nom            = sanitize($_POST['nom']                     ?? '');
        $description    = sanitize($_POST['description']            ?? '');
        $duree          = (int)($_POST['duree_annees']              ?? 1);
        $editId         = (int)($_POST['edit_id']                   ?? 0);
        $troncCommun    = isset($_POST['tronc_commun']) ? 1 : 0;
        $troncCommunId  = (int)($_POST['tronc_commun_id'] ?? 0) ?: null;

        if ($troncCommun) $troncCommunId = null; // un tronc commun ne peut pas en référencer un autre

        if (empty($code)) $errors[] = 'Le code est obligatoire.';
        if (empty($nom))  $errors[] = 'Le nom est obligatoire.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE filieres SET code=?,nom=?,description=?,duree_annees=?,tronc_commun=?,tronc_commun_id=? WHERE id=?")
                   ->execute([$code, $nom, $description ?: null, $duree, $troncCommun, $troncCommunId, $editId]);
                setFlash('success', 'Filière modifiée.');
            } else {
                try {
                    $db->prepare("INSERT INTO filieres (code,nom,description,duree_annees,tronc_commun,tronc_commun_id) VALUES (?,?,?,?,?,?)")
                       ->execute([$code, $nom, $description ?: null, $duree, $troncCommun, $troncCommunId]);
                    $newId = $db->lastInsertId();

                    // Auto-create levels
                    $stmtN = $db->prepare("INSERT INTO niveaux (filiere_id, nom, ordre) VALUES (?,?,?)");
                    if ($troncCommun) {
                        $stmtN->execute([$newId, 'Tronc Commun', 1]);
                    } elseif ($troncCommunId) {
                        // Année 1 = tronc commun → on crée à partir de l'Année 2
                        for ($i = 2; $i <= $duree + 1; $i++) {
                            $stmtN->execute([$newId, 'Année ' . $i, $i]);
                        }
                    } else {
                        for ($i = 1; $i <= $duree; $i++) {
                            $stmtN->execute([$newId, 'Année ' . $i, $i]);
                        }
                    }
                    $totalAns = $troncCommunId ? ($duree + 1) : $duree;
                    $msg = $troncCommun
                        ? "Filière tronc commun créée (1 an)."
                        : ($troncCommunId
                            ? "Filière créée : {$duree} an(s) propres + 1 an de tronc commun = {$totalAns} ans au total."
                            : "Filière créée avec {$duree} niveau(x).");
                    setFlash('success', $msg);
                } catch (PDOException $e) {
                    $errors[] = 'Ce code existe déjà.';
                }
            }
            if (empty($errors)) redirect('/modules/administration/filieres.php');
        }
    }
}

$filieres = $db->query("
    SELECT f.*, tc.code as tc_code, tc.nom as tc_nom,
           COUNT(DISTINCT n.id) as nb_niveaux, COUNT(DISTINCT e.id) as nb_etudiants
    FROM filieres f
    LEFT JOIN filieres tc ON tc.id = f.tronc_commun_id
    LEFT JOIN niveaux n ON n.filiere_id = f.id
    LEFT JOIN etudiants e ON e.filiere_id = f.id AND e.statut = 'actif'
    WHERE f.actif = 1
    GROUP BY f.id
    ORDER BY f.tronc_commun DESC, f.nom
")->fetchAll();

// Build reverse map: tronc_commun_id → [filieres qui l'utilisent]
$troncCommuns = $db->query("SELECT id, code, nom FROM filieres WHERE tronc_commun=1 AND actif=1 ORDER BY nom")->fetchAll();
$filieresParTronc = []; // [tronc_commun_id => [filiere, ...]]
foreach ($filieres as $f) {
    if ($f['tronc_commun_id']) {
        $filieresParTronc[(int)$f['tronc_commun_id']][] = $f;
    }
}

$pageTitle  = 'Filières & Niveaux';
$breadcrumb = ['Administration' => null, 'Filières' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-layer-group me-2 text-primary"></i>Filières & Niveaux</h2>
  <?php if (hasRole(['admin', 'directeur'])): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filiereModal" onclick="setMode('add')">
    <i class="fas fa-plus me-2"></i>Nouvelle filière
  </button>
  <?php endif; ?>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<div class="row g-4">
  <?php foreach ($filieres as $f): ?>
  <div class="col-md-6">
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge bg-primary px-3 py-2 fs-6"><?= h($f['code']) ?></span>
              <h5 class="mb-0"><?= h($f['nom']) ?></h5>
              <?php if ($f['tronc_commun']): ?>
                <span class="badge" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80;font-size:.75rem">
                  <i class="fas fa-code-branch me-1"></i>Tronc Commun
                </span>
              <?php elseif ($f['tronc_commun_id']): ?>
                <span class="badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7;font-size:.75rem">
                  <i class="fas fa-link me-1"></i><?= h($f['tc_code']) ?>
                </span>
              <?php endif; ?>
            </div>
            <?php if ($f['description']): ?>
              <p class="text-muted fs-sm mt-1 mb-0"><?= h($f['description']) ?></p>
            <?php endif;
            // Filières qui utilisent ce tronc commun
            if ($f['tronc_commun'] && !empty($filieresParTronc[$f['id']])): ?>
              <p class="fs-sm mt-1 mb-0" style="color:#e65100">
                <i class="fas fa-arrow-right me-1"></i>Alimente :
                <?php foreach ($filieresParTronc[$f['id']] as $lf): ?>
                  <span class="badge bg-warning text-dark me-1"><?= h($lf['code']) ?></span>
                <?php endforeach; ?>
              </p>
            <?php endif; ?>
          </div>
          <?php if (hasRole(['admin', 'directeur'])): ?>
          <div class="d-flex gap-1">
            <button class="btn btn-icon btn-sm btn-outline-warning"
                    data-filiere="<?= h(json_encode($f)) ?>"
                    onclick="editFiliere(this)"
                    title="Modifier"><i class="fas fa-edit"></i></button>
            <?php if (hasRole('admin')): ?>
            <a href="?action=delete&id=<?= $f['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>" class="btn btn-icon btn-sm btn-outline-danger" onclick="return confirm('Désactiver cette filière ?')"><i class="fas fa-trash"></i></a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-4 text-center p-2" style="background:#f0f4f8;border-radius:8px">
            <?php if ($f['tronc_commun_id']): ?>
              <div class="fw-bold" style="font-size:1.4rem;color:#1a73e8"><?= $f['duree_annees'] + 1 ?></div>
              <div class="text-muted fs-sm">Ans total</div>
              <div style="font-size:.7rem;color:#e65100">dont 1 an TC + <?= $f['duree_annees'] ?> ans</div>
            <?php else: ?>
              <div class="fw-bold" style="font-size:1.4rem;color:#1a73e8"><?= $f['duree_annees'] ?></div>
              <div class="text-muted fs-sm">An(s) de formation</div>
            <?php endif; ?>
          </div>
          <div class="col-4 text-center p-2" style="background:#f0f4f8;border-radius:8px">
            <div class="fw-bold" style="font-size:1.4rem;color:#34a853"><?= $f['nb_niveaux'] ?></div>
            <div class="text-muted fs-sm">Niveaux</div>
          </div>
          <div class="col-4 text-center p-2" style="background:#f0f4f8;border-radius:8px">
            <div class="fw-bold" style="font-size:1.4rem;color:#f57c00"><?= $f['nb_etudiants'] ?></div>
            <div class="text-muted fs-sm">Étudiants</div>
          </div>
        </div>

        <!-- Niveaux -->
        <?php
          $nStmt = $db->prepare("SELECT n.*, COUNT(e.id) as nb_etu FROM niveaux n LEFT JOIN etudiants e ON e.niveau_id=n.id AND e.statut='actif' WHERE n.filiere_id=? ORDER BY n.ordre");
          $nStmt->execute([$f['id']]);
          $niveaux = $nStmt->fetchAll();
        ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($niveaux as $n): ?>
            <span class="badge bg-light text-dark border py-2 px-3">
              <?= h($n['nom']) ?>
              <span class="ms-1 badge bg-primary"><?= $n['nb_etu'] ?></span>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (empty($filieres)): ?>
  <div class="col-12">
    <div class="card"><div class="card-body empty-state"><i class="fas fa-layer-group"></i><h5>Aucune filière</h5><p class="text-muted">Cliquez sur "Nouvelle filière" pour commencer.</p></div></div>
  </div>
  <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="filiereModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="filiereModalTitle">Nouvelle filière</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" id="fil_edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Code *</label>
              <input type="text" name="code" id="fil_code" class="form-control" placeholder="ASB" style="text-transform:uppercase" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Nom de la filière *</label>
              <input type="text" name="nom" id="fil_nom" class="form-control" placeholder="Ex: Agent de Santé de Base" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="fil_desc" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label" id="fil_duree_label">Durée de formation (années)</label>
              <select name="duree_annees" id="fil_duree" class="form-select">
                <option value="1">1 an</option>
                <option value="2">2 ans</option>
                <option value="3" selected>3 ans</option>
              </select>
              <div class="form-text" id="fil_duree_hint">Les niveaux seront créés automatiquement.</div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="tronc_commun" id="fil_tronc_commun" value="1" onchange="toggleTroncCommunFields()">
                <label class="form-check-label" for="fil_tronc_commun">
                  <i class="fas fa-code-branch me-1 text-warning"></i>Cette filière est un <strong>tronc commun</strong>
                </label>
              </div>
            </div>
            <div class="col-12" id="fil_tc_row">
              <label class="form-label">Rattachée au tronc commun <small class="text-muted">(optionnel)</small></label>
              <select name="tronc_commun_id" id="fil_tronc_commun_id" class="form-select">
                <option value="">— Aucun tronc commun —</option>
                <?php foreach ($troncCommuns as $tc): ?>
                  <option value="<?= $tc['id'] ?>"><?= h($tc['code']) ?> — <?= h($tc['nom']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Indiquer si la 1ère année de cette filière est un tronc commun partagé.</div>
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
function updateDureeLabel() {
  const tcId   = document.getElementById('fil_tronc_commun_id').value;
  const label  = document.getElementById('fil_duree_label');
  const hint   = document.getElementById('fil_duree_hint');
  const duree  = parseInt(document.getElementById('fil_duree').value) || 2;
  if (tcId) {
    label.textContent = 'Durée après le tronc commun (années)';
    hint.innerHTML = '<i class="fas fa-info-circle me-1 text-warning"></i>Durée totale : 1 an (tronc commun) + '
      + duree + ' an' + (duree > 1 ? 's' : '') + ' = <strong>' + (duree + 1) + ' ans</strong>';
  } else {
    label.textContent = 'Durée de formation (années)';
    hint.textContent  = 'Les niveaux seront créés automatiquement.';
  }
}
function toggleTroncCommunFields() {
  const isTc = document.getElementById('fil_tronc_commun').checked;
  const tcRow = document.getElementById('fil_tc_row');
  tcRow.style.display = isTc ? 'none' : '';
  if (isTc) {
    document.getElementById('fil_tronc_commun_id').value = '';
    document.getElementById('fil_duree').value = '1';
  }
  updateDureeLabel();
}
function setMode(mode) {
  document.getElementById('filiereModalTitle').textContent = mode === 'add' ? 'Nouvelle filière' : 'Modifier la filière';
  document.getElementById('fil_edit_id').value = '';
  document.getElementById('fil_code').value = '';
  document.getElementById('fil_nom').value = '';
  document.getElementById('fil_desc').value = '';
  document.getElementById('fil_duree').value = '3';
  document.getElementById('fil_tronc_commun').checked = false;
  document.getElementById('fil_tronc_commun_id').value = '';
  document.getElementById('fil_tc_row').style.display = '';
  updateDureeLabel();
}
function editFiliere(btn) {
  const f = JSON.parse(btn.getAttribute('data-filiere'));
  document.getElementById('filiereModalTitle').textContent = 'Modifier la filière';
  document.getElementById('fil_edit_id').value = f.id;
  document.getElementById('fil_code').value = f.code;
  document.getElementById('fil_nom').value = f.nom;
  document.getElementById('fil_desc').value = f.description || '';
  document.getElementById('fil_duree').value = f.duree_annees;
  document.getElementById('fil_tronc_commun').checked = f.tronc_commun == 1;
  document.getElementById('fil_tronc_commun_id').value = f.tronc_commun_id || '';
  toggleTroncCommunFields();
  updateDureeLabel();
  new bootstrap.Modal(document.getElementById('filiereModal')).show();
}
// Recalculate hint when duration changes
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('fil_duree').addEventListener('change', updateDureeLabel);
  document.getElementById('fil_tronc_commun_id').addEventListener('change', () => {
    const tcId = document.getElementById('fil_tronc_commun_id').value;
    if (tcId && document.getElementById('fil_duree').value > 2) {
      document.getElementById('fil_duree').value = '2';
    }
    updateDureeLabel();
  });
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
