<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'coordinateur']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$errors  = [];

// Slots config (hardcoded per academic schedule)
$JOURS = ['lundi','mardi','mercredi','jeudi','vendredi','samedi'];
$JOUR_LABELS = [
    'lundi'=>'Lundi','mardi'=>'Mardi','mercredi'=>'Mercredi',
    'jeudi'=>'Jeudi','vendredi'=>'Vendredi','samedi'=>'Samedi',
];
// heure_debut => heure_fin  (slot 2 starts at 10h30 après récréation)
$ALL_SLOTS    = ['08:00'=>'10:00', '10:30'=>'12:30', '14:00'=>'16:00'];
$JOURS_COURTS = ['vendredi','samedi']; // only 2 slots (4h)

// Load emploi if editing
$emploi        = null;
$existingSlots = []; // [jour][debut] = slot row
$editId        = (int)($_GET['id'] ?? 0);
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM emplois_du_temps WHERE id=?");
    $stmt->execute([$editId]);
    $emploi = $stmt->fetch();
    if (!$emploi) { setFlash('error', 'Emploi du temps introuvable.'); redirect('/modules/emploi_du_temps/index.php'); }
    if (hasRole('coordinateur') && (int)$emploi['filiere_id'] !== getCoordinateurFiliereId()) {
        setFlash('error', 'Accès refusé.'); redirect('/modules/emploi_du_temps/index.php');
    }
    if (!in_array($emploi['statut'], ['brouillon','rejete'])) {
        setFlash('info', 'Cet emploi du temps ne peut plus être modifié.');
        redirect('/modules/emploi_du_temps/view.php?id='.$editId);
    }
    // Reset rejected to brouillon when coordinator opens it for editing
    if ($emploi['statut'] === 'rejete') {
        $db->prepare("UPDATE emplois_du_temps SET statut='brouillon', commentaire_directeur=NULL WHERE id=?")
           ->execute([$editId]);
        $emploi['statut'] = 'brouillon';
    }
    $sStmt = $db->prepare("SELECT * FROM emplois_slots WHERE emploi_id=?");
    $sStmt->execute([$editId]);
    foreach ($sStmt->fetchAll() as $s) {
        $existingSlots[$s['jour']][substr($s['heure_debut'],0,5)] = $s;
    }
}

$coordFiliereId  = getCoordinateurFiliereId();
$activeFiliereId = $coordFiliereId ?: (int)($emploi['filiere_id'] ?? $_GET['filiere_id'] ?? 0);

// Load matières filtered to filière
$matieres = [];
if ($activeFiliereId) {
    if ($ecoleId > 0) {
        $ms = $db->prepare("SELECT m.id, m.code, m.nom, n.nom as niveau_nom FROM matieres m LEFT JOIN niveaux n ON n.id=m.niveau_id WHERE m.filiere_id=? AND m.ecole_id=? ORDER BY m.nom");
        $ms->execute([$activeFiliereId, $ecoleId]);
    } else {
        $ms = $db->prepare("SELECT m.id, m.code, m.nom, n.nom as niveau_nom FROM matieres m LEFT JOIN niveaux n ON n.id=m.niveau_id WHERE m.filiere_id=? ORDER BY m.nom");
        $ms->execute([$activeFiliereId]);
    }
    $matieres = $ms->fetchAll();
}

$filieres    = getFilieres();
$niveaux     = getNiveaux($activeFiliereId ?: null);
$annees      = getAnneesAcademiques();
if ($ecoleId > 0) {
    $ensStmt = $db->prepare("SELECT id, nom, prenom, specialite FROM enseignants WHERE ecole_id=? AND actif=1 ORDER BY nom");
    $ensStmt->execute([$ecoleId]);
    $enseignants = $ensStmt->fetchAll();
} else {
    $enseignants = $db->query("SELECT id, nom, prenom, specialite FROM enseignants WHERE actif=1 ORDER BY nom")->fetchAll();
}

$classes = [];
if ($activeFiliereId) {
    if ($ecoleId > 0) {
        $cs = $db->prepare("SELECT c.id, c.nom, c.niveau_id, n.nom as niveau_nom, n.ordre FROM classes c LEFT JOIN niveaux n ON n.id = c.niveau_id WHERE c.filiere_id = ? AND n.ecole_id = ? ORDER BY COALESCE(n.ordre, 9999), c.nom");
        $cs->execute([$activeFiliereId, $ecoleId]);
    } else {
        $cs = $db->prepare("SELECT c.id, c.nom, c.niveau_id, n.nom as niveau_nom, n.ordre FROM classes c LEFT JOIN niveaux n ON n.id = c.niveau_id WHERE c.filiere_id = ? ORDER BY COALESCE(n.ordre, 9999), c.nom");
        $cs->execute([$activeFiliereId]);
    }
    $classes = $cs->fetchAll();
}

// ── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $filiereId    = $coordFiliereId ?: (int)($_POST['filiere_id'] ?? 0);
        $niveauId     = (int)($_POST['niveau_id']  ?? 0) ?: null;
        $classeId     = (int)($_POST['classe_id']  ?? 0) ?: null;
        $anneeId      = (int)($_POST['annee_id']   ?? 0) ?: null;
        $semaineDebut = sanitize($_POST['semaine_debut'] ?? '');
        $semaineFin   = sanitize($_POST['semaine_fin']   ?? '');
        $soumettre    = isset($_POST['soumettre']);

        if (!$filiereId)    $errors[] = 'Filière obligatoire.';
        if (!$semaineDebut || !strtotime($semaineDebut)) $errors[] = 'Date de début invalide.';
        if (!$semaineFin   || !strtotime($semaineFin))   $errors[] = 'Date de fin invalide.';
        if (empty($errors) && $semaineFin < $semaineDebut) $errors[] = 'La date de fin doit être postérieure ou égale à la date de début.';

        if (empty($errors)) {
            $semaineDebut = date('Y-m-d', strtotime($semaineDebut));
            $semaineFin   = date('Y-m-d', strtotime($semaineFin));
            $statut       = $soumettre ? 'soumis' : 'brouillon';

            if ($editId) {
                $db->prepare("UPDATE emplois_du_temps SET filiere_id=?,niveau_id=?,classe_id=?,annee_id=?,semaine_debut=?,semaine_fin=?,statut=? WHERE id=?")
                   ->execute([$filiereId, $niveauId, $classeId, $anneeId, $semaineDebut, $semaineFin, $statut, $editId]);
                $emploiId = $editId;
                $db->prepare("DELETE FROM emplois_slots WHERE emploi_id=?")->execute([$emploiId]);
            } else {
                $db->prepare("INSERT INTO emplois_du_temps (filiere_id,niveau_id,classe_id,annee_id,semaine_debut,semaine_fin,statut,created_by) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$filiereId, $niveauId, $classeId, $anneeId, $semaineDebut, $semaineFin, $statut, $user['id']]);
                $emploiId = (int)$db->lastInsertId();
            }

            // Save slots
            $postSlots = $_POST['slots'] ?? [];
            $ins = $db->prepare("INSERT INTO emplois_slots (emploi_id,jour,heure_debut,heure_fin,matiere_id,enseignant_id) VALUES (?,?,?,?,?,?)");
            foreach ($JOURS as $jour) {
                $jourSlots = in_array($jour, $JOURS_COURTS)
                    ? array_slice($ALL_SLOTS, 0, 2, true)
                    : $ALL_SLOTS;
                foreach ($jourSlots as $debut => $fin) {
                    $mId = (int)(($postSlots[$jour][$debut]['matiere_id']    ?? 0)) ?: null;
                    $eId = (int)(($postSlots[$jour][$debut]['enseignant_id'] ?? 0)) ?: null;
                    if ($mId || $eId) {
                        $ins->execute([$emploiId, $jour, $debut.':00', $fin.':00', $mId, $eId]);
                    }
                }
            }

            $msg = $soumettre
                ? 'Emploi du temps soumis pour approbation au directeur.'
                : 'Brouillon enregistré avec succès.';
            setFlash('success', $msg);
            redirect('/modules/emploi_du_temps/view.php?id='.$emploiId);
        }
    }
}

// Reload matieres after POST error (filiere may have changed)
if (!empty($errors) && !$coordFiliereId) {
    $fId = (int)($_POST['filiere_id'] ?? 0);
    if ($fId) {
        if ($ecoleId > 0) {
            $ms2 = $db->prepare("SELECT id, code, nom FROM matieres WHERE filiere_id=? AND ecole_id=? ORDER BY nom");
            $ms2->execute([$fId, $ecoleId]);
        } else {
            $ms2 = $db->prepare("SELECT id, code, nom FROM matieres WHERE filiere_id=? ORDER BY nom");
            $ms2->execute([$fId]);
        }
        $matieres = $ms2->fetchAll();
    }
}

$isEdit     = $editId > 0;
$pageTitle  = $isEdit ? 'Modifier l\'emploi du temps' : 'Nouvel emploi du temps';
$breadcrumb = [
    'Pédagogie'        => null,
    'Emplois du temps' => APP_URL.'/modules/emploi_du_temps/index.php',
    $pageTitle         => null,
];

$extraHead = '<style>
.slot-cell { min-width: 200px; vertical-align: middle; }
.slot-cell select { font-size: .8rem; }
.slot-header { background: #1a1a2e; color: #fff; }
.slot-time { background: #f8f9fa; font-weight: 600; font-size: .85rem; text-align:center; vertical-align:middle; white-space:nowrap; }
.slot-disabled { background: #e9ecef; text-align: center; color: #aaa; vertical-align: middle; }
@media print {
  .no-print { display: none !important; }
  .card { border: none !important; box-shadow: none !important; }
}
</style>';
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header no-print">
  <h2>
    <i class="fas fa-<?= $isEdit ? 'edit' : 'calendar-plus' ?> me-2 text-primary"></i>
    <?= h($pageTitle) ?>
  </h2>
  <a href="<?= APP_URL ?>/modules/emploi_du_temps/index.php" class="btn btn-light">
    <i class="fas fa-arrow-left me-2"></i>Retour
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<form method="POST" id="emploiForm">
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <!-- ── Informations générales ── -->
  <div class="card mb-4">
    <div class="card-header"><i class="fas fa-info-circle me-2 text-primary"></i>Informations générales</div>
    <div class="card-body">
      <div class="row g-3">
        <?php if (!$coordFiliereId): ?>
        <div class="col-md-4">
          <label class="form-label">Filière <span class="text-danger">*</span></label>
          <select name="filiere_id" id="f_filiere" class="form-select" required onchange="updateMatieres(this.value)">
            <option value="">-- Sélectionner --</option>
            <?php foreach ($filieres as $f): ?>
              <option value="<?= $f['id'] ?>" <?= $activeFiliereId == $f['id'] ? 'selected':'' ?>>
                <?= h($f['code']) ?> – <?= h($f['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="filiere_id" value="<?= $coordFiliereId ?>">
        <div class="col-md-4">
          <label class="form-label">Filière</label>
          <?php
            $myF = null;
            foreach ($filieres as $f) { if ($f['id'] == $coordFiliereId) { $myF = $f; break; } }
          ?>
          <div class="form-control bg-light"><?= h(($myF['code']??'').' – '.($myF['nom']??'')) ?></div>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Niveau</label>
          <select name="niveau_id" id="f_niveau" class="form-select" onchange="filterClasses(parseInt(this.value)||0)">
            <option value="">-- Tous niveaux --</option>
            <?php foreach ($niveaux as $n): ?>
              <option value="<?= $n['id'] ?>" <?= ($emploi['niveau_id'] ?? 0) == $n['id'] ? 'selected':'' ?>>
                <?= h($n['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!empty($classes)): ?>
        <div class="col-md-3">
          <label class="form-label">
            <i class="fas fa-chalkboard me-1 text-primary" style="font-size:.85rem"></i>Classe
          </label>
          <select name="classe_id" id="f_classe" class="form-select">
            <option value="">— Toutes les classes —</option>
            <?php foreach ($classes as $c): ?>
              <option value="<?= $c['id'] ?>"
                      data-niveau="<?= (int)$c['niveau_id'] ?>"
                      <?= ($emploi['classe_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                <?= h($c['nom']) ?>
                <?= $c['niveau_nom'] ? ' · '.h($c['niveau_nom']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">La filière a des classes définies.</div>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Année académique</label>
          <select name="annee_id" class="form-select">
            <option value="">-- Sélectionner --</option>
            <?php foreach ($annees as $a): ?>
              <option value="<?= $a['id'] ?>"
                <?= ($emploi['annee_id'] ?? 0) == $a['id'] ? 'selected' : ($a['actif'] && !$emploi ? 'selected' : '') ?>>
                <?= h($a['libelle']) ?><?= $a['actif'] ? ' ★' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Date de début <span class="text-danger">*</span></label>
          <input type="date" name="semaine_debut" id="semaine_debut" class="form-control" required
                 value="<?= h($emploi['semaine_debut'] ?? '') ?>"
                 onchange="updateRangeDisplay()">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Date de fin <span class="text-danger">*</span></label>
          <input type="date" name="semaine_fin" id="semaine_fin" class="form-control" required
                 value="<?= h($emploi['semaine_fin'] ?? '') ?>"
                 onchange="updateRangeDisplay()">
        </div>

        <div class="col-12">
          <div id="range_display" class="text-muted" style="font-size:.83rem;min-height:1.2em">–</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Légende ── -->
  <div class="d-flex gap-3 mb-3 flex-wrap no-print">
    <div class="d-flex align-items-center gap-2">
      <div style="width:16px;height:16px;background:#0d6efd;border-radius:3px"></div>
      <small>Matière assignée</small>
    </div>
    <div class="badge bg-primary align-self-center">Lun – Jeu&nbsp;&nbsp;3 × 2h = <strong>6h</strong></div>
    <div class="badge bg-secondary align-self-center">Ven – Sam&nbsp;&nbsp;2 × 2h = <strong>4h</strong></div>
    <small class="text-muted align-self-center">Sélectionnez la matière et l'enseignant pour chaque créneau</small>
  </div>

  <!-- ── Grille horaire ── -->
  <div class="card mb-4">
    <div class="card-header">
      <i class="fas fa-table me-2 text-primary"></i>Grille horaire
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered mb-0" id="emploiTable">
          <thead>
            <tr class="slot-header">
              <th style="width:90px" class="text-center">Créneau</th>
              <?php foreach ($JOURS as $i => $jour):
                $dateLabel = '';
                if ($emploi && $emploi['semaine_debut']) {
                    $ts = strtotime($emploi['semaine_debut']) + $i * 86400;
                    $dateLabel = date('d/m', $ts);
                }
              ?>
              <th class="text-center day-header" data-idx="<?= $i ?>">
                <span class="day-name"><?= $JOUR_LABELS[$jour] ?></span>
                <?php if ($dateLabel): ?><div class="fw-normal" style="font-size:.75rem;opacity:.7"><?= $dateLabel ?></div><?php endif; ?>
                <?php if (in_array($jour, $JOURS_COURTS)): ?>
                  <div style="font-size:.7rem;opacity:.6">(4h)</div>
                <?php else: ?>
                  <div style="font-size:.7rem;opacity:.6">(6h)</div>
                <?php endif; ?>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ALL_SLOTS as $debut => $fin):
              // Insert récréation row before the 10:30 slot
              if ($debut === '10:30'):
            ?>
            <tr>
              <td colspan="<?= count($JOURS) + 1 ?>"
                  style="background:#fff3cd;text-align:center;padding:6px;font-size:.82rem;font-weight:600;color:#856404;border:1px solid #ffc107">
                <i class="fas fa-coffee me-2"></i>Récréation — 10h00 à 10h30 (30 min)
              </td>
            </tr>
            <?php endif;
              if ($debut === '14:00'):
            ?>
            <tr>
              <td colspan="<?= count($JOURS) + 1 ?>"
                  style="background:#e3f2fd;text-align:center;padding:6px;font-size:.82rem;font-weight:600;color:#1565c0;border:1px solid #90caf9">
                <i class="fas fa-utensils me-2"></i>Pause déjeuner — 12h30 à 14h00 (1h30)
              </td>
            </tr>
            <?php endif; ?>
            <tr>
              <td class="slot-time">
                <div><?= $debut ?></div>
                <div class="text-muted" style="font-size:.7rem">↕ 2h</div>
                <div><?= $fin ?></div>
              </td>
              <?php foreach ($JOURS as $jour):
                $disabled = in_array($jour, $JOURS_COURTS) && $debut === '14:00';
                $existing = $existingSlots[$jour][$debut] ?? null;
              ?>
              <td class="slot-cell <?= $disabled ? 'slot-disabled' : '' ?> p-2">
                <?php if ($disabled): ?>
                  <span title="Pas de cours en fin d'après-midi le vendredi/samedi">–</span>
                <?php else: ?>
                  <select name="slots[<?= $jour ?>][<?= $debut ?>][matiere_id]"
                          class="form-select form-select-sm mb-1 matiere-sel"
                          onchange="highlightCell(this)">
                    <option value="">— Matière —</option>
                    <?php foreach ($matieres as $m): ?>
                      <option value="<?= $m['id'] ?>"
                        <?= ($existing['matiere_id'] ?? 0) == $m['id'] ? 'selected':'' ?>>
                        <?= h($m['code']) ?> – <?= h($m['nom']) ?>
                        <?= $m['niveau_nom'] ? '('.$m['niveau_nom'].')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <select name="slots[<?= $jour ?>][<?= $debut ?>][enseignant_id]"
                          class="form-select form-select-sm">
                    <option value="">— Enseignant —</option>
                    <?php foreach ($enseignants as $e): ?>
                      <option value="<?= $e['id'] ?>"
                        <?= ($existing['enseignant_id'] ?? 0) == $e['id'] ? 'selected':'' ?>>
                        <?= h($e['prenom'].' '.$e['nom']) ?>
                        <?= $e['specialite'] ? ' · '.$e['specialite'] : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Boutons ── -->
  <div class="d-flex gap-3 justify-content-between align-items-center no-print">
    <a href="<?= APP_URL ?>/modules/emploi_du_temps/index.php" class="btn btn-light">
      <i class="fas fa-times me-2"></i>Annuler
    </a>
    <div class="d-flex gap-2">
      <button type="submit" name="save" class="btn btn-outline-secondary">
        <i class="fas fa-save me-2"></i>Enregistrer brouillon
      </button>
      <button type="submit" name="soumettre" class="btn btn-primary"
              onclick="return confirm('Soumettre pour approbation au directeur ?\n\nUne fois soumis, vous ne pourrez plus modifier cet emploi du temps.')">
        <i class="fas fa-paper-plane me-2"></i>Soumettre pour approbation
      </button>
    </div>
  </div>
</form>

<script>
const JOURS_ORDER = <?= json_encode(array_values($JOURS)) ?>;

function updateRangeDisplay() {
    const debVal = document.getElementById('semaine_debut').value;
    const finVal = document.getElementById('semaine_fin').value;
    const display = document.getElementById('range_display');
    const finInput = document.getElementById('semaine_fin');

    if (debVal) finInput.min = debVal;

    if (!debVal) { display.innerHTML = '–'; return; }
    const debDate = new Date(debVal + 'T00:00:00');

    // Mise à jour des en-têtes de colonnes avec les dates de la première semaine
    document.querySelectorAll('.day-header').forEach((th, i) => {
        const d = new Date(debDate.getTime() + i * 86400000);
        const dayFmt = d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit'});
        const extra = th.querySelector('div:last-child');
        const name  = th.querySelector('.day-name');
        if (name) {
            th.innerHTML = '<span class="day-name">' + name.textContent + '</span>'
                         + '<div style="font-size:.75rem;opacity:.7">' + dayFmt + '</div>'
                         + (extra ? extra.outerHTML : '');
        }
    });

    const fmt = d => d.toLocaleDateString('fr-FR', {day:'2-digit', month:'long', year:'numeric'});
    if (!finVal) {
        display.innerHTML = '<i class="fas fa-calendar-day me-1"></i>Début : <strong>' + fmt(debDate) + '</strong>';
        return;
    }
    const finDate  = new Date(finVal + 'T00:00:00');
    const diffDays = Math.round((finDate - debDate) / 86400000) + 1;
    if (finDate < debDate) {
        display.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>La date de fin doit être après la date de début.</span>';
        return;
    }
    display.innerHTML = '<i class="fas fa-calendar-alt me-1 text-primary"></i>'
        + 'Du <strong>' + fmt(debDate) + '</strong> au <strong>' + fmt(finDate) + '</strong>'
        + ' &nbsp;<span class="badge bg-secondary">' + diffDays + ' jour' + (diffDays > 1 ? 's' : '') + '</span>';
}

function highlightCell(sel) {
    const cell = sel.closest('td');
    if (sel.value) {
        cell.style.background = '#e8f5e9';
    } else {
        cell.style.background = '';
    }
}

function filterClasses(niveauId) {
    const sel = document.getElementById('f_classe');
    if (!sel) return;
    sel.querySelectorAll('option[data-niveau]').forEach(opt => {
        const optNiv = parseInt(opt.getAttribute('data-niveau')) || 0;
        opt.hidden = !!(niveauId && optNiv && optNiv !== niveauId);
    });
    if (sel.selectedOptions[0]?.hidden) sel.value = '';
}

function updateMatieres(filiereId) {
    if (!filiereId) return;
    // Reload page with new filière
    const url = new URL(window.location.href);
    url.searchParams.set('filiere_id', filiereId);
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    updateRangeDisplay();
    document.querySelectorAll('.matiere-sel').forEach(highlightCell);
    const niveauSel = document.getElementById('f_niveau');
    if (niveauSel) filterClasses(parseInt(niveauSel.value) || 0);
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
