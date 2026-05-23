<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$db   = getDB();
$user = getCurrentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('/modules/emploi_du_temps/index.php'); }

// Load emploi
$stmt = $db->prepare("
    SELECT e.*,
           f.nom as filiere_nom, f.code as filiere_code,
           n.nom as niveau_nom,
           cl.nom as classe_nom,
           a.libelle as annee_libelle,
           u.nom as createur_nom, u.prenom as createur_prenom,
           ap.nom as approbateur_nom, ap.prenom as approbateur_prenom
    FROM emplois_du_temps e
    JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n ON n.id = e.niveau_id
    LEFT JOIN classes cl ON cl.id = e.classe_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    LEFT JOIN users u ON u.id = e.created_by
    LEFT JOIN users ap ON ap.id = e.approved_by
    WHERE e.id = ?
");
$stmt->execute([$id]);
$emploi = $stmt->fetch();
if (!$emploi) {
    setFlash('error', 'Emploi du temps introuvable.');
    redirect('/modules/emploi_du_temps/index.php');
}

// Access control
if (hasRole('coordinateur') && (int)$emploi['filiere_id'] !== getCoordinateurFiliereId()) {
    setFlash('error', 'Accès refusé.');
    redirect('/modules/emploi_du_temps/index.php');
}

// ── Approval/Rejection (directeur + admin) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (hasRole('directeur') || hasRole('admin'))) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
    } else {
        $action      = sanitize($_POST['decision'] ?? '');
        $commentaire = sanitize($_POST['commentaire'] ?? '');
        if ($action === 'approuver' && $emploi['statut'] === 'soumis') {
            $db->prepare("UPDATE emplois_du_temps SET statut='approuve', approved_by=?, approved_at=NOW(), commentaire_directeur=? WHERE id=?")
               ->execute([$user['id'], $commentaire ?: null, $id]);
            setFlash('success', 'Emploi du temps approuvé et validé.');
        } elseif ($action === 'rejeter' && $emploi['statut'] === 'soumis') {
            if (empty($commentaire)) {
                setFlash('error', 'Un motif de rejet est obligatoire.');
                redirect('/modules/emploi_du_temps/view.php?id='.$id);
            }
            $db->prepare("UPDATE emplois_du_temps SET statut='rejete', approved_by=?, approved_at=NOW(), commentaire_directeur=? WHERE id=?")
               ->execute([$user['id'], $commentaire, $id]);
            setFlash('warning', 'Emploi du temps rejeté.');
        }
        redirect('/modules/emploi_du_temps/view.php?id='.$id);
    }
    // Reload after POST
    $stmt->execute([$id]);
    $emploi = $stmt->fetch();
}

// Load slots
$sStmt = $db->prepare("
    SELECT s.*,
           m.nom as matiere_nom, m.code as matiere_code,
           CONCAT(e.prenom,' ',e.nom) as enseignant_nom,
           e.specialite as enseignant_specialite
    FROM emplois_slots s
    LEFT JOIN matieres m ON m.id = s.matiere_id
    LEFT JOIN enseignants e ON e.id = s.enseignant_id
    WHERE s.emploi_id = ?
    ORDER BY FIELD(s.jour,'lundi','mardi','mercredi','jeudi','vendredi','samedi'), s.heure_debut
");
$sStmt->execute([$id]);
$slotsData = [];
foreach ($sStmt->fetchAll() as $s) {
    $slotsData[$s['jour']][substr($s['heure_debut'],0,5)] = $s;
}

// Slots config
$JOURS        = ['lundi','mardi','mercredi','jeudi','vendredi','samedi'];
$JOUR_LABELS  = ['lundi'=>'Lundi','mardi'=>'Mardi','mercredi'=>'Mercredi','jeudi'=>'Jeudi','vendredi'=>'Vendredi','samedi'=>'Samedi'];
$ALL_SLOTS    = ['08:00'=>'10:00', '10:30'=>'12:30', '14:00'=>'16:00'];
$JOURS_COURTS = ['vendredi','samedi'];

$statusCfg = [
    'brouillon' => ['label'=>'Brouillon',   'class'=>'secondary',   'icon'=>'fa-pencil-alt'],
    'soumis'    => ['label'=>'En attente',  'class'=>'warning',     'icon'=>'fa-clock'],
    'approuve'  => ['label'=>'Approuvé',    'class'=>'success',     'icon'=>'fa-check-circle'],
    'rejete'    => ['label'=>'Rejeté',      'class'=>'danger',      'icon'=>'fa-times-circle'],
];
$sc = $statusCfg[$emploi['statut']] ?? ['label'=>$emploi['statut'],'class'=>'secondary','icon'=>'fa-question'];

// Compute day dates
$dayDates = [];
$monTs = strtotime($emploi['semaine_debut']);
foreach ($JOURS as $i => $j) {
    $dayDates[$j] = [
        'short' => date('d/m', $monTs + $i * 86400),
        'long'  => date('d/m/Y', $monTs + $i * 86400),
    ];
}

$print      = isset($_GET['print']);
$pageTitle  = 'Emploi du temps';
$breadcrumb = [
    'Pédagogie'        => null,
    'Emplois du temps' => APP_URL.'/modules/emploi_du_temps/index.php',
    'Détail'           => null,
];

$extraHead = '
<style>
  .et-table { border-collapse: collapse; width: 100%; }
  .et-table th, .et-table td { border: 1px solid #dee2e6; padding: 6px 8px; vertical-align: middle; }
  .et-header { background: #1a1a2e; color: #fff; text-align: center; }
  .et-time   { background: #f8f9fa; font-weight: 700; font-size: .85rem; text-align: center; white-space: nowrap; min-width: 80px; }
  .et-slot   { min-width: 150px; font-size: .82rem; }
  .et-empty  { background: #f0f0f0; text-align: center; color: #aaa; }
  .et-filled { background: #e8f4fd; }
  .matiere-name  { font-weight: 600; color: #0d6efd; }
  .ens-name      { color: #555; font-size: .78rem; }
  .print-only    { display: none; }
  .approval-stamp {
    border: 3px solid #198754; border-radius: 8px; padding: 10px 18px;
    display: inline-block; color: #198754; text-align: center;
    margin-top: 12px;
  }
  @media print {
    .no-print  { display: none !important; }
    .print-only { display: block !important; }
    #sidebar, .topbar, .sidebar-footer { display: none !important; }
    #main-content { margin: 0 !important; }
    .content-area { padding: 0 !important; }
    .wrapper { display: block !important; }
    .card { border: none !important; box-shadow: none !important; break-inside: avoid; }
    .et-table th, .et-table td { font-size: .75rem; padding: 4px 5px; }
    .et-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .et-filled { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .et-pause  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>';
include APP_ROOT . '/includes/header.php';
?>

<!-- Print header (hidden on screen) -->
<div class="print-only mb-3">
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <?php $printLogo = getLogoUrl(); if ($printLogo): ?>
        <img src="<?= h($printLogo) ?>" alt="Logo" style="height:55px;object-fit:contain">
      <?php endif; ?>
    </div>
    <div class="text-center flex-grow-1">
      <h5 class="mb-0"><?= h(getParam('etablissement_nom','EPSI')) ?></h5>
      <small><?= h(getParam('etablissement_slogan','École Privée de Santé Ibn Rochd')) ?></small>
      <h6 class="mt-1 mb-0">EMPLOI DU TEMPS</h6>
    </div>
    <?php if ($emploi['statut'] === 'approuve'): ?>
    <div class="approval-stamp text-center" style="font-size:.75rem">
      <div style="font-size:1.1rem;font-weight:700">✓ APPROUVÉ</div>
      <div><?= h(($emploi['approbateur_prenom']??'').' '.($emploi['approbateur_nom']??'')) ?></div>
      <div><?= formatDate($emploi['approved_at']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <hr style="border-color:#333;margin:6px 0">
  <div style="font-size:.85rem">
    <strong>Filière :</strong> <?= h($emploi['filiere_code'].' – '.$emploi['filiere_nom']) ?>
    <?php if ($emploi['niveau_nom']): ?> &nbsp;|&nbsp; <strong>Niveau :</strong> <?= h($emploi['niveau_nom']) ?><?php endif; ?>
    <?php if ($emploi['classe_nom']): ?> &nbsp;|&nbsp; <strong>Classe :</strong> <?= h($emploi['classe_nom']) ?><?php endif; ?>
    &nbsp;|&nbsp; <strong>Semaine du</strong> <?= formatDate($emploi['semaine_debut']) ?> <strong>au</strong> <?= formatDate($emploi['semaine_fin']) ?>
    <?php if ($emploi['annee_libelle']): ?> &nbsp;|&nbsp; <strong>Année :</strong> <?= h($emploi['annee_libelle']) ?><?php endif; ?>
    &nbsp;|&nbsp; <strong>Coordinateur :</strong> <?= h(($emploi['createur_prenom']??'').' '.($emploi['createur_nom']??'')) ?>
  </div>
</div>

<!-- ── Toolbar ── -->
<div class="page-header no-print">
  <div>
    <h2><i class="fas fa-calendar-week me-2 text-primary"></i>Emploi du temps</h2>
    <div class="d-flex align-items-center gap-2 mt-1">
      <span class="badge bg-<?= $sc['class'] ?> fs-sm">
        <i class="fas <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
      </span>
      <span class="text-muted fs-sm">
        <?= h($emploi['filiere_code'].' – '.$emploi['filiere_nom']) ?>
        <?= $emploi['niveau_nom'] ? ' · '.h($emploi['niveau_nom']) : '' ?>
        <?= $emploi['classe_nom'] ? ' · <i class="fas fa-chalkboard" style="font-size:.75rem"></i> '.h($emploi['classe_nom']) : '' ?>
        — Semaine du <?= formatDate($emploi['semaine_debut']) ?>
      </span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($emploi['statut'] === 'approuve'): ?>
    <button onclick="window.print()" class="btn btn-success">
      <i class="fas fa-print me-2"></i>Imprimer l'emploi du temps validé
    </button>
    <?php endif; ?>
    <?php if (in_array($emploi['statut'], ['brouillon','rejete']) && ($emploi['created_by'] == $user['id'] || hasRole('admin'))): ?>
    <a href="<?= APP_URL ?>/modules/emploi_du_temps/form.php?id=<?= $id ?>" class="btn btn-outline-warning">
      <i class="fas fa-edit me-2"></i>Modifier
    </a>
    <?php endif; ?>
    <?php if ($emploi['statut'] !== 'approuve'): ?>
    <button onclick="window.print()" class="btn btn-outline-secondary">
      <i class="fas fa-print me-2"></i>Imprimer
    </button>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/emploi_du_temps/index.php" class="btn btn-light">
      <i class="fas fa-arrow-left me-2"></i>Retour
    </a>
  </div>
</div>

<!-- ── Status Banner ── -->
<?php if ($emploi['statut'] === 'approuve'): ?>
<div class="alert alert-success d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-check-circle fa-2x"></i>
  <div>
    <strong>Emploi du temps approuvé</strong>
    <div class="fs-sm">
      Par <?= h(($emploi['approbateur_prenom']??'').' '.($emploi['approbateur_nom']??'')) ?>
      le <?= formatDate($emploi['approved_at']) ?>
    </div>
    <?php if ($emploi['commentaire_directeur']): ?>
      <div class="mt-1 fs-sm fst-italic"><?= h($emploi['commentaire_directeur']) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($emploi['statut'] === 'rejete'): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-times-circle fa-2x"></i>
  <div>
    <strong>Emploi du temps rejeté</strong>
    <div class="fs-sm">
      Par <?= h(($emploi['approbateur_prenom']??'').' '.($emploi['approbateur_nom']??'')) ?>
      le <?= formatDate($emploi['approved_at']) ?>
    </div>
    <?php if ($emploi['commentaire_directeur']): ?>
      <div class="mt-1"><em>"<?= h($emploi['commentaire_directeur']) ?>"</em></div>
    <?php endif; ?>
    <?php if ($emploi['created_by'] == $user['id'] || hasRole('admin')): ?>
    <div class="mt-2">
      <a href="<?= APP_URL ?>/modules/emploi_du_temps/form.php?id=<?= $id ?>" class="btn btn-sm btn-danger">
        <i class="fas fa-redo me-1"></i>Modifier et re-soumettre
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($emploi['statut'] === 'soumis'): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-clock fa-2x"></i>
  <div>
    <strong>En attente d'approbation par le directeur</strong>
    <div class="fs-sm">Soumis par <?= h(($emploi['createur_prenom']??'').' '.($emploi['createur_nom']??'')) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Info cards ── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="text-muted fs-sm">Filière</div>
        <div class="fw-bold"><?= h($emploi['filiere_code'].' – '.$emploi['filiere_nom']) ?></div>
        <?php if ($emploi['niveau_nom']): ?><div class="text-muted fs-sm"><?= h($emploi['niveau_nom']) ?></div><?php endif; ?>
        <?php if ($emploi['classe_nom']): ?>
          <div class="text-muted fs-sm"><i class="fas fa-chalkboard me-1" style="font-size:.72rem"></i><?= h($emploi['classe_nom']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="text-muted fs-sm">Semaine</div>
        <div class="fw-bold">Du <?= formatDate($emploi['semaine_debut']) ?></div>
        <div class="text-muted fs-sm">au <?= formatDate($emploi['semaine_fin']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="text-muted fs-sm">Coordinateur</div>
        <div class="fw-bold"><?= h(($emploi['createur_prenom']??'').' '.($emploi['createur_nom']??'')) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="text-muted fs-sm">Année académique</div>
        <div class="fw-bold"><?= h($emploi['annee_libelle'] ?? '–') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Timetable ── -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-table me-2 text-primary"></i>Grille horaire</span>
    <div class="d-flex gap-2 no-print">
      <span class="badge bg-primary">Lun-Jeu : 3 créneaux × 2h = 6h</span>
      <span class="badge bg-secondary">Ven-Sam : 2 créneaux × 2h = 4h</span>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="et-table">
        <thead>
          <tr>
            <th class="et-header" style="width:80px">Horaire</th>
            <?php foreach ($JOURS as $jour): ?>
            <th class="et-header">
              <div><?= $JOUR_LABELS[$jour] ?></div>
              <div style="font-size:.75rem;opacity:.75"><?= $dayDates[$jour]['short'] ?></div>
              <div style="font-size:.7rem;opacity:.6">
                <?= in_array($jour, $JOURS_COURTS) ? '4h' : '6h' ?>
              </div>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ALL_SLOTS as $debut => $fin): ?>
          <?php if ($debut === '10:30'): ?>
          <tr class="et-pause">
            <td colspan="<?= count($JOURS) + 1 ?>"
                style="background:#fff3cd;text-align:center;padding:7px 8px;font-weight:600;font-size:.82rem;color:#856404;border:1px solid #ffc107">
              <i class="fas fa-coffee me-2"></i>Récréation &mdash; 10h00 à 10h30 &nbsp;(30 min)
            </td>
          </tr>
          <?php endif; ?>
          <?php if ($debut === '14:00'): ?>
          <tr class="et-pause">
            <td colspan="<?= count($JOURS) + 1 ?>"
                style="background:#e3f2fd;text-align:center;padding:7px 8px;font-weight:600;font-size:.82rem;color:#1565c0;border:1px solid #90caf9">
              <i class="fas fa-utensils me-2"></i>Pause déjeuner &mdash; 12h30 à 14h00 &nbsp;(1h30)
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td class="et-time"><?= $debut ?><br><small>↕</small><br><?= $fin ?></td>
            <?php foreach ($JOURS as $jour):
              $disabled = in_array($jour, $JOURS_COURTS) && $debut === '14:00';
              $slot     = $slotsData[$jour][$debut] ?? null;
            ?>
            <td class="et-slot <?= $disabled ? 'et-empty' : ($slot ? 'et-filled' : '') ?>">
              <?php if ($disabled): ?>
                <span class="text-muted">–</span>
              <?php elseif ($slot): ?>
                <div class="matiere-name">
                  <i class="fas fa-book-open me-1" style="font-size:.7rem"></i>
                  <?= h($slot['matiere_code'].' – '.$slot['matiere_nom']) ?>
                </div>
                <?php if ($slot['enseignant_nom']): ?>
                <div class="ens-name">
                  <i class="fas fa-user-tie me-1" style="font-size:.7rem"></i>
                  <?= h($slot['enseignant_nom']) ?>
                  <?= $slot['enseignant_specialite'] ? '<small class="text-muted"> · '.$slot['enseignant_specialite'].'</small>' : '' ?>
                </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted" style="font-size:.8rem">— Libre —</span>
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

<!-- ── Approval form (directeur / admin, status = soumis) ── -->
<?php if ($emploi['statut'] === 'soumis' && (hasRole('directeur') || hasRole('admin'))): ?>
<div class="card border-warning mb-4 no-print">
  <div class="card-header bg-warning bg-opacity-10 border-warning">
    <h5 class="mb-0"><i class="fas fa-gavel me-2 text-warning"></i>Décision du directeur</h5>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
      <div class="mb-3">
        <label class="form-label">Commentaire <small class="text-muted">(obligatoire pour le rejet)</small></label>
        <textarea name="commentaire" class="form-control" rows="3"
                  placeholder="Remarques, suggestions ou motif de rejet..."></textarea>
      </div>
      <div class="d-flex gap-3">
        <button type="submit" name="decision" value="approuver" class="btn btn-success btn-lg"
                onclick="return confirm('Approuver et valider cet emploi du temps ?')">
          <i class="fas fa-check-circle me-2"></i>Approuver
        </button>
        <button type="submit" name="decision" value="rejeter" class="btn btn-danger btn-lg"
                onclick="return confirm('Rejeter cet emploi du temps ?\n\nLe coordinateur devra le corriger et le resoumettre.')">
          <i class="fas fa-times-circle me-2"></i>Rejeter
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Récapitulatif des enseignants programmés ── -->
<?php
$enseignantRecap = [];
foreach ($slotsData as $jour => $heures) {
    foreach ($heures as $debut => $s) {
        if ($s['enseignant_nom']) {
            $key = $s['enseignant_id'];
            if (!isset($enseignantRecap[$key])) {
                $enseignantRecap[$key] = ['nom' => $s['enseignant_nom'], 'heures' => 0, 'matieres' => []];
            }
            $enseignantRecap[$key]['heures'] += 2;
            $mCode = $s['matiere_code'] ?? '';
            if ($mCode && !in_array($mCode, $enseignantRecap[$key]['matieres'])) {
                $enseignantRecap[$key]['matieres'][] = $mCode;
            }
        }
    }
}
?>
<?php if (!empty($enseignantRecap)): ?>
<div class="card">
  <div class="card-header"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Récapitulatif enseignants programmés</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Enseignant</th><th>Heures programmées</th><th>Matières</th></tr></thead>
      <tbody>
        <?php foreach ($enseignantRecap as $e): ?>
        <tr>
          <td class="fw-600"><i class="fas fa-user-tie me-2 text-muted"></i><?= h($e['nom']) ?></td>
          <td><span class="badge bg-primary"><?= $e['heures'] ?>h</span></td>
          <td><?= implode(' ', array_map(fn($m) => '<code class="fs-sm">'.$m.'</code>', $e['matieres'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
