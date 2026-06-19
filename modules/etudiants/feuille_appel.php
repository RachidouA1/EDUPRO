<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'coordinateur']);

$db       = getDB();
$user     = getCurrentUser();
$ecoleId  = getEcoleId();
$isCoord  = hasRole('coordinateur');

try { $db->exec("ALTER TABLE absences ADD COLUMN matiere_id INT NULL"); } catch (PDOException $e) {}

$errors = [];

// ── ENREGISTREMENT EN LOT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide.';
    } else {
        $dateAbs   = sanitize($_POST['date_absence'] ?? date('Y-m-d'));
        $heureD    = sanitize($_POST['heure_debut']  ?? '') ?: null;
        $heureF    = sanitize($_POST['heure_fin']    ?? '') ?: null;
        $matiereId = (int)($_POST['matiere_id']      ?? 0);
        $anneeId   = (int)($_POST['annee_id']         ?? 0);
        $motif     = sanitize($_POST['motif']         ?? '');
        $absents   = array_values(array_filter(array_map('intval', (array)($_POST['absents'] ?? []))));

        $duree = null;
        if ($heureD && $heureF) {
            $d = (strtotime($heureF) - strtotime($heureD)) / 3600;
            if ($d > 0) $duree = round($d, 1);
        }

        $seance = '';
        if ($matiereId) {
            $mr = $db->prepare("SELECT code, nom FROM matieres WHERE id=?");
            $mr->execute([$matiereId]);
            $m = $mr->fetch();
            if ($m) $seance = $m['code'] . ' – ' . $m['nom'];
        }

        if (!$dateAbs) {
            $errors[] = 'La date est obligatoire.';
        } elseif (empty($absents)) {
            $errors[] = 'Aucun apprenant absent n\'a été coché.';
        } else {
            $chk = $db->prepare(
                "SELECT id FROM absences WHERE etudiant_id=? AND date_absence=? AND COALESCE(matiere_id,0)=?"
                . ($ecoleId > 0 ? " AND ecole_id=?" : "")
            );
            $ins = $db->prepare(
                "INSERT INTO absences
                    (etudiant_id, date_absence, heure_debut, heure_fin, duree_heures,
                     seance, motif, justifie, annee_id, created_by, ecole_id, matiere_id)
                 VALUES (?,?,?,?,?,?,?,0,?,?,?,?)"
            );
            $saved = $skipped = 0;
            foreach ($absents as $eid) {
                $cp = [$eid, $dateAbs, $matiereId ?: 0];
                if ($ecoleId > 0) $cp[] = $ecoleId;
                $chk->execute($cp);
                if ($chk->fetch()) { $skipped++; continue; }
                $ins->execute([
                    $eid, $dateAbs, $heureD, $heureF, $duree,
                    $seance ?: null, $motif ?: null,
                    $anneeId ?: null, $user['id'],
                    $ecoleId > 0 ? $ecoleId : null,
                    $matiereId ?: null,
                ]);
                $saved++;
            }
            $msg = "$saved absence(s) enregistrée(s)";
            if ($skipped) $msg .= " ($skipped déjà existante(s) ignorée(s))";
            setFlash($saved > 0 ? 'success' : 'info', $msg . '.');
            redirect('/modules/etudiants/feuille_appel.php?' . http_build_query(array_filter([
                'filiere_id'   => (int)($_POST['filiere_id'] ?? 0),
                'niveau_id'    => (int)($_POST['niveau_id']  ?? 0),
                'date_absence' => $dateAbs,
                'heure_debut'  => $heureD ?? '',
                'heure_fin'    => $heureF ?? '',
                'matiere_id'   => $matiereId ?: '',
                'annee_id'     => $anneeId  ?: '',
                'motif'        => $motif,
            ])));
        }
    }
}

// ── PARAMÈTRES GET ────────────────────────────────────────────────────────────
$selFil  = (int)($_GET['filiere_id']    ?? 0);
$selNiv  = (int)($_GET['niveau_id']     ?? 0);
$selMat  = (int)($_GET['matiere_id']    ?? 0);
$selDate = sanitize($_GET['date_absence'] ?? date('Y-m-d'));
$selHD   = sanitize($_GET['heure_debut']  ?? '');
$selHF   = sanitize($_GET['heure_fin']    ?? '');
$selAnn  = (int)($_GET['annee_id']         ?? 0);
$selMot  = sanitize($_GET['motif']          ?? '');

// ── DONNÉES ───────────────────────────────────────────────────────────────────
if ($isCoord) {
    $coordFIds = getCoordinateurFiliereIds();
    if ($coordFIds) {
        $ph = implode(',', array_fill(0, count($coordFIds), '?'));
        $fs = $db->prepare("SELECT id, code, nom FROM filieres WHERE id IN ($ph) AND actif=1 ORDER BY nom");
        $fs->execute($coordFIds);
        $filieres = $fs->fetchAll();
    } else {
        $filieres = [];
    }
} else {
    $filieres = getFilieres();
}

// Tous les niveaux des filières disponibles
$allNiveaux = [];
if (!empty($filieres)) {
    $fIds = array_column($filieres, 'id');
    $ph   = implode(',', array_fill(0, count($fIds), '?'));
    $ns   = $db->prepare("SELECT id, nom, filiere_id, ordre FROM niveaux WHERE filiere_id IN ($ph) ORDER BY filiere_id, ordre");
    $ns->execute($fIds);
    $allNiveaux = $ns->fetchAll();
}

// Toutes les matières des filières disponibles (filtrage côté JS)
$allMatieres = [];
if (!empty($filieres)) {
    $fIds = array_column($filieres, 'id');
    $ph   = implode(',', array_fill(0, count($fIds), '?'));
    $mw   = ["m.actif=1", "m.filiere_id IN ($ph)"];
    $mp   = $fIds;
    if ($ecoleId > 0) { $mw[] = 'f.ecole_id=?'; $mp[] = $ecoleId; }
    $ms = $db->prepare("SELECT m.id, m.code, m.nom, m.filiere_id, m.niveau_id FROM matieres m
        LEFT JOIN filieres f ON f.id=m.filiere_id
        WHERE " . implode(' AND ', $mw) . " ORDER BY m.nom");
    $ms->execute($mp);
    $allMatieres = $ms->fetchAll();
}

// Étudiants (si filière + niveau sélectionnés)
$etudiants    = [];
$canShowList  = $selFil && $selNiv;
if ($canShowList) {
    $ew = ["e.statut='actif'", 'e.filiere_id=?', 'e.niveau_id=?'];
    $ep = [$selFil, $selNiv];
    if ($ecoleId > 0) { $ew[] = 'e.ecole_id=?'; $ep[] = $ecoleId; }
    $es = $db->prepare("SELECT e.id, e.nom, e.prenom, e.matricule, e.sexe
        FROM etudiants e WHERE " . implode(' AND ', $ew) . " ORDER BY e.nom, e.prenom");
    $es->execute($ep);
    $etudiants = $es->fetchAll();
}

// Absences déjà enregistrées pour cette date + matière (pré-cochage)
$dejaAbsIds = [];
if ($canShowList && $selDate) {
    $dq = "SELECT etudiant_id FROM absences WHERE date_absence=? AND COALESCE(matiere_id,0)=?"
          . ($ecoleId > 0 ? " AND ecole_id=?" : "");
    $dp = [$selDate, $selMat ?: 0];
    if ($ecoleId > 0) $dp[] = $ecoleId;
    $ds = $db->prepare($dq);
    $ds->execute($dp);
    $dejaAbsIds = array_column($ds->fetchAll(), 'etudiant_id');
}

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();
if (!$selAnn && $anneeActive) $selAnn = (int)$anneeActive['id'];

// Nom de la matière sélectionnée
$matNomSel = '';
if ($selMat) {
    foreach ($allMatieres as $mm) {
        if ((int)$mm['id'] === $selMat) { $matNomSel = $mm['code'] . ' – ' . $mm['nom']; break; }
    }
}

$pageTitle  = 'Feuille d\'appel';
$breadcrumb = [
    'Étudiants' => APP_URL . '/modules/etudiants/index.php',
    'Absences'  => APP_URL . '/modules/etudiants/absences.php',
    'Feuille d\'appel' => null,
];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header no-print">
  <h2><i class="fas fa-clipboard-list me-2 text-primary"></i>Feuille d'appel</h2>
  <a href="<?= APP_URL ?>/modules/etudiants/absences.php" class="btn btn-light">
    <i class="fas fa-arrow-left me-1"></i>Retour aux absences
  </a>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>
<?php showFlash(); ?>

<!-- ── Sélection de la séance ─────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <i class="fas fa-sliders-h me-2 text-primary"></i><strong>Paramètres de la séance</strong>
  </div>
  <div class="card-body">
    <form method="GET" id="sessionForm" class="row g-3 align-items-end">

      <div class="col-md-2">
        <label class="form-label fw-600">Date <span class="text-danger">*</span></label>
        <input type="date" name="date_absence" class="form-control" value="<?= h($selDate) ?>" required>
      </div>

      <div class="col-md-2">
        <label class="form-label fw-600">Filière <span class="text-danger">*</span></label>
        <select name="filiere_id" id="sel_filiere" class="form-select" onchange="onFiliereChange(this.value)">
          <option value="">— Filière —</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $selFil == $f['id'] ? 'selected' : '' ?>><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label fw-600">Niveau <span class="text-danger">*</span></label>
        <select name="niveau_id" id="sel_niveau" class="form-select" onchange="onNiveauChange(this.value)">
          <option value="">— Niveau —</option>
          <?php foreach ($allNiveaux as $n): ?>
            <option value="<?= $n['id'] ?>" data-filiere="<?= $n['filiere_id'] ?>"
              <?= $selNiv == $n['id'] ? 'selected' : '' ?>
              <?= (!$selFil || $n['filiere_id'] != $selFil) ? 'style="display:none"' : '' ?>>
              <?= h($n['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Matière</label>
        <select name="matiere_id" id="sel_matiere" class="form-select">
          <option value="">— Toutes matières —</option>
          <?php foreach ($allMatieres as $m): ?>
            <option value="<?= $m['id'] ?>"
                    data-filiere="<?= (int)$m['filiere_id'] ?>"
                    data-niveau="<?= (int)$m['niveau_id'] ?>"
                    <?= $selMat == $m['id'] ? 'selected' : '' ?>
                    style="display:none">
              <?= h($m['code']) ?> – <?= h($m['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-1">
        <label class="form-label">Heure début</label>
        <input type="time" name="heure_debut" class="form-control" value="<?= h($selHD) ?>">
      </div>

      <div class="col-md-1">
        <label class="form-label">Heure fin</label>
        <input type="time" name="heure_fin" class="form-control" value="<?= h($selHF) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Motif général</label>
        <input type="text" name="motif" class="form-control" placeholder="Facultatif" value="<?= h($selMot) ?>">
      </div>

      <?php foreach ($annees as $an): ?>
        <?php if ((int)$an['id'] === $selAnn): ?>
          <input type="hidden" name="annee_id" value="<?= $an['id'] ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$selAnn && $anneeActive): ?>
        <input type="hidden" name="annee_id" value="<?= $anneeActive['id'] ?>">
      <?php endif; ?>

      <div class="col-md-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-users me-2"></i>Charger les apprenants
        </button>
        <a href="<?= APP_URL ?>/modules/etudiants/feuille_appel.php" class="btn btn-light">
          <i class="fas fa-times me-1"></i>Réinitialiser
        </a>
      </div>

    </form>
  </div>
</div>

<!-- ── Liste des apprenants ───────────────────────────────────────────────── -->
<?php if ($canShowList): ?>
<form method="POST" id="appelForm">
  <input type="hidden" name="csrf"         value="<?= h(generateCsrfToken()) ?>">
  <input type="hidden" name="filiere_id"   value="<?= $selFil ?>">
  <input type="hidden" name="niveau_id"    value="<?= $selNiv ?>">
  <input type="hidden" name="date_absence" value="<?= h($selDate) ?>">
  <input type="hidden" name="heure_debut"  value="<?= h($selHD) ?>">
  <input type="hidden" name="heure_fin"    value="<?= h($selHF) ?>">
  <input type="hidden" name="matiere_id"   value="<?= $selMat ?>">
  <input type="hidden" name="annee_id"     value="<?= $selAnn ?>">
  <input type="hidden" name="motif"        value="<?= h($selMot) ?>">

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <i class="fas fa-clipboard-check me-2 text-primary"></i>
        <strong>
          <?= $matNomSel ? h($matNomSel) : 'Toutes matières' ?>
          —
          <?= date('d/m/Y', strtotime($selDate)) ?>
          <?php if ($selHD): ?> · <?= h(substr($selHD,0,5)) ?><?= $selHF ? '–'.h(substr($selHF,0,5)) : '' ?><?php endif; ?>
        </strong>
        <span class="text-muted ms-2 fs-sm"><?= count($etudiants) ?> apprenant(s)</span>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <span class="badge bg-danger fs-sm" id="countBadge">0 absent(s)</span>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="toggleAll(true)">
          <i class="fas fa-times-circle me-1"></i>Tout absent
        </button>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="toggleAll(false)">
          <i class="fas fa-check-circle me-1"></i>Tout présent
        </button>
      </div>
    </div>

    <?php if (empty($etudiants)): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-users d-block mb-2" style="font-size:2rem;opacity:.3"></i>
        Aucun apprenant trouvé pour cette filière et ce niveau.
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle mb-0" id="appelTable">
        <thead class="table-light">
          <tr>
            <th style="width:52px" class="text-center">Absent</th>
            <th>#</th>
            <th>Apprenant</th>
            <th>Matricule</th>
            <th class="text-center">Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($etudiants as $i => $e):
            $dejaAbs = in_array($e['id'], $dejaAbsIds);
          ?>
          <tr id="row-<?= $e['id'] ?>" class="<?= $dejaAbs ? 'table-warning' : '' ?>" onclick="toggleRow(<?= $e['id'] ?>)" style="cursor:pointer">
            <td class="text-center" onclick="event.stopPropagation()">
              <input type="checkbox" name="absents[]" value="<?= $e['id'] ?>"
                     id="chk-<?= $e['id'] ?>"
                     class="form-check-input abs-check"
                     style="width:1.3rem;height:1.3rem"
                     <?= $dejaAbs ? 'checked' : '' ?>
                     onchange="updateCount()">
            </td>
            <td class="text-muted fs-sm"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:34px;height:34px;font-size:.78rem;flex-shrink:0">
                  <?= strtoupper(substr($e['prenom'],0,1) . substr($e['nom'],0,1)) ?>
                </div>
                <div class="fw-600"><?= h($e['nom'] . ' ' . $e['prenom']) ?></div>
              </div>
            </td>
            <td><code class="fs-sm"><?= h($e['matricule']) ?></code></td>
            <td class="text-center" id="stat-<?= $e['id'] ?>">
              <?php if ($dejaAbs): ?>
                <span class="badge bg-warning text-dark">Déjà absent</span>
              <?php else: ?>
                <span class="badge bg-success">Présent</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
      <span class="text-muted fs-sm">
        Cliquez sur une ligne ou cochez la case pour marquer un apprenant absent.
        <?php if (!empty($dejaAbsIds)): ?>
          <span class="text-warning ms-2"><i class="fas fa-exclamation-triangle me-1"></i><?= count($dejaAbsIds) ?> absence(s) déjà enregistrée(s) pour cette séance.</span>
        <?php endif; ?>
      </span>
      <button type="submit" class="btn btn-danger" id="saveBtn">
        <i class="fas fa-save me-2"></i>Enregistrer les absences (<span id="saveCount">0</span>)
      </button>
    </div>
    <?php endif; ?>
  </div>
</form>
<?php elseif ($selFil && !$selNiv): ?>
<div class="alert alert-info">
  <i class="fas fa-info-circle me-2"></i>Sélectionnez également un <strong>niveau</strong> puis cliquez sur <em>Charger les apprenants</em>.
</div>
<?php elseif (!$selFil): ?>
<div class="alert alert-light border text-muted text-center py-4">
  <i class="fas fa-hand-point-up d-block mb-2" style="font-size:1.8rem;opacity:.4"></i>
  Sélectionnez une <strong>filière</strong> et un <strong>niveau</strong> pour afficher la liste des apprenants.
</div>
<?php endif; ?>

<script>
const niveauxData  = <?= json_encode(array_map(fn($n) => ['id'=>(int)$n['id'],'filiere_id'=>(int)$n['filiere_id']], $allNiveaux), JSON_UNESCAPED_UNICODE) ?>;
const matieresData = <?= json_encode(array_map(fn($m) => ['id'=>(int)$m['id'],'niveau_id'=>(int)$m['niveau_id']], $allMatieres), JSON_UNESCAPED_UNICODE) ?>;

function onFiliereChange(fId) {
    fId = parseInt(fId) || 0;
    const selNiv = document.getElementById('sel_niveau');
    const selMat = document.getElementById('sel_matiere');
    // Filtrer les niveaux
    Array.from(selNiv.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!fId || parseInt(opt.dataset.filiere) === fId) ? '' : 'none';
    });
    selNiv.value = '';
    // Masquer toutes les matières tant qu'aucun niveau n'est choisi
    Array.from(selMat.options).forEach(opt => { if (opt.value) opt.style.display = 'none'; });
    selMat.value = '';
}

function onNiveauChange(nId) {
    nId = parseInt(nId) || 0;
    const fId    = parseInt(document.getElementById('sel_filiere').value) || 0;
    const selMat = document.getElementById('sel_matiere');
    Array.from(selMat.options).forEach(opt => {
        if (!opt.value) return;
        const oFil = parseInt(opt.dataset.filiere) || 0;
        const oNiv = parseInt(opt.dataset.niveau)  || 0;
        // Afficher si la filière correspond ET (pas de niveau OU niveau correspond OU matière sans niveau)
        const ok = (oFil === fId) && (!nId || oNiv === 0 || oNiv === nId);
        opt.style.display = ok ? '' : 'none';
    });
    selMat.value = '';
}

function toggleRow(eid) {
    const chk = document.getElementById('chk-' + eid);
    chk.checked = !chk.checked;
    updateRowStyle(eid, chk.checked);
    updateCount();
}

function updateRowStyle(eid, isAbsent) {
    const row  = document.getElementById('row-' + eid);
    const stat = document.getElementById('stat-' + eid);
    if (isAbsent) {
        row.classList.add('table-danger');
        row.classList.remove('table-warning');
        stat.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Absent</span>';
    } else {
        row.classList.remove('table-danger', 'table-warning');
        stat.innerHTML = '<span class="badge bg-success">Présent</span>';
    }
}

function updateCount() {
    const n = document.querySelectorAll('.abs-check:checked').length;
    document.getElementById('countBadge').textContent = n + ' absent(s)';
    document.getElementById('saveCount').textContent  = n;
    document.getElementById('saveBtn').disabled = n === 0;
    // Update row styles
    document.querySelectorAll('.abs-check').forEach(chk => {
        updateRowStyle(parseInt(chk.value), chk.checked);
    });
}

function toggleAll(absent) {
    document.querySelectorAll('.abs-check').forEach(chk => { chk.checked = absent; });
    updateCount();
}

// Init on load
document.addEventListener('DOMContentLoaded', () => {
    updateCount();
    <?php if ($selFil): ?>
    // Restaurer la visibilité des options selon filière/niveau sélectionnés
    onFiliereChange(<?= $selFil ?>);
    <?php if ($selNiv): ?>
    document.getElementById('sel_niveau').value = '<?= $selNiv ?>';
    onNiveauChange(<?= $selNiv ?>);
    <?php endif; ?>
    // Re-sélectionner la matière après filtrage
    const selMat = document.getElementById('sel_matiere');
    if (selMat && '<?= $selMat ?>') selMat.value = '<?= $selMat ?>';
    <?php endif; ?>
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
