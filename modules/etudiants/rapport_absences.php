<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$isCoord = hasRole('coordinateur');

// ── FILTRES ───────────────────────────────────────────────────────────────────
$dateDebut  = sanitize($_GET['date_debut'] ?? date('Y-m-01'));   // défaut : 1er du mois
$dateFin    = sanitize($_GET['date_fin']   ?? date('Y-m-d'));
$filiereId  = (int)($_GET['filiere_id']   ?? 0);
$niveauId   = (int)($_GET['niveau_id']    ?? 0);
$anneeIdF   = (int)($_GET['annee_id']     ?? 0);
$filtreJust = sanitize($_GET['justifie']  ?? '');
$searchEtu  = sanitize($_GET['search']    ?? '');

// ── REQUÊTE GLOBALE (détail par occurrence) ───────────────────────────────────
$where  = ["a.date_absence BETWEEN ? AND ?"];
$params = [$dateDebut, $dateFin];

if ($ecoleId > 0) { $where[] = 'a.ecole_id = ?'; $params[] = $ecoleId; }
if ($isCoord) { $where[] = coordSectionWhere('e', $params); }
if ($filiereId) { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)  { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($anneeIdF)  { $where[] = 'a.annee_id = ?';   $params[] = $anneeIdF; }
if ($filtreJust !== '') { $where[] = 'a.justifie = ?'; $params[] = (int)$filtreJust; }
if ($searchEtu) {
    $where[]  = '(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)';
    $params   = array_merge($params, ["%$searchEtu%", "%$searchEtu%", "%$searchEtu%"]);
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

// Requête synthèse PAR ÉTUDIANT
$sqlSynth = "
    SELECT
        e.id, e.nom, e.prenom, e.matricule, e.sexe,
        f.code AS filiere_code, f.nom AS filiere_nom,
        n.nom  AS niveau_nom,
        COUNT(a.id)                                      AS total_abs,
        SUM(CASE WHEN a.justifie=1 THEN 1 ELSE 0 END)   AS nb_just,
        SUM(CASE WHEN a.justifie=0 THEN 1 ELSE 0 END)   AS nb_non_just,
        COALESCE(SUM(a.duree_heures), 0)                 AS total_h,
        MAX(a.date_absence)                              AS derniere_abs
    FROM absences a
    JOIN etudiants e ON e.id = a.etudiant_id
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux  n ON n.id = e.niveau_id
    $whereStr
    GROUP BY e.id, e.nom, e.prenom, e.matricule, e.sexe,
             f.code, f.nom, n.nom
    ORDER BY total_abs DESC, total_h DESC, e.nom
";
$stmtS = $db->prepare($sqlSynth);
$stmtS->execute($params);
$synthese = $stmtS->fetchAll();

// Requête détail (toutes les occurrences, pour le tableau détaillé et l'export)
$sqlDetail = "
    SELECT a.*,
           e.nom AS e_nom, e.prenom AS e_prenom, e.matricule,
           f.code AS filiere_code, f.nom AS filiere_nom,
           n.nom  AS niveau_nom
    FROM absences a
    JOIN etudiants e ON e.id = a.etudiant_id
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux  n ON n.id = e.niveau_id
    $whereStr
    ORDER BY a.date_absence DESC, e.nom
";
$stmtD = $db->prepare($sqlDetail);
$stmtD->execute($params);
$details = $stmtD->fetchAll();

// ── STATS GLOBALES ────────────────────────────────────────────────────────────
$totalOcc   = count($details);
$totalJust  = array_sum(array_column($details, 'justifie'));
$totalNonJ  = $totalOcc - $totalJust;
$totalH     = array_sum(array_column($details, 'duree_heures'));
$nbEtudiants = count($synthese);

// Données formulaire
$filieres    = $isCoord
    ? array_values(array_unique(array_map(fn($s) => ['id'=>$s['filiere_id'],'nom'=>$s['filiere_nom'],'code'=>$s['filiere_code']], getCoordinateurSections()), SORT_REGULAR))
    : getFilieres();
$niveaux     = getNiveaux();
$annees      = getAnneesAcademiques();

// Infos école pour l'en-tête
$ecole        = getCurrentEcole();
$nomEcole     = $ecole['nom']       ?? getParam('etablissement_nom', 'E-EDU PRO');
$adresseEcole = $ecole['adresse']   ?? getParam('etablissement_adresse', '');
$villeEcole   = $ecole['ville']     ?? getParam('etablissement_ville', '');
$telEcole     = $ecole['telephone'] ?? getParam('etablissement_telephone', '');
$logoPath     = $ecole['logo_path'] ?? '';

$pageTitle  = 'Rapport des Absences';
$breadcrumb = [
    'Étudiants' => APP_URL . '/modules/etudiants/index.php',
    'Absences'  => APP_URL . '/modules/etudiants/absences.php',
    'Rapport'   => null,
];
include APP_ROOT . '/includes/header.php';
?>

<!-- En-tête imprimable -->
<div class="print-only text-center mb-3" style="border-bottom:2px solid #1565c0;padding-bottom:12px;">
  <?php if ($logoPath): ?>
  <img src="<?= APP_URL . '/' . h($logoPath) ?>" alt="Logo" style="height:68px;object-fit:contain;display:block;margin:0 auto 8px;">
  <?php endif; ?>
  <div style="font-size:1.3rem;font-weight:700;"><?= h($nomEcole) ?></div>
  <?php if ($adresseEcole || $villeEcole): ?>
  <div style="font-size:.88rem;color:#555;"><?= h(trim($adresseEcole . ($villeEcole ? ' – ' . $villeEcole : ''))) ?></div>
  <?php endif; ?>
  <?php if ($telEcole): ?><div style="font-size:.85rem;color:#555;">Tél : <?= h($telEcole) ?></div><?php endif; ?>
  <div style="font-size:1.1rem;font-weight:700;margin-top:10px;">RAPPORT DES ABSENCES</div>
  <div style="font-size:.9rem;font-weight:600;color:#1565c0;">
    Période : <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?>
  </div>
  <?php if ($filiereId || $niveauId): ?>
  <div style="font-size:.82rem;color:#555;">
    <?php if ($filiereId): ?><?php
    $flt = array_filter($filieres, fn($f) => $f['id'] == $filiereId); $flt = reset($flt);
    echo 'Filière : ' . h($flt['code'] ?? $flt['nom'] ?? '');
    ?><?php endif; ?>
    <?php if ($niveauId): ?><?php
    $nlt = array_filter($niveaux, fn($n) => $n['id'] == $niveauId); $nlt = reset($nlt);
    echo ' | Niveau : ' . h($nlt['nom'] ?? '');
    ?><?php endif; ?>
  </div>
  <?php endif; ?>
  <div style="font-size:.78rem;color:#888;">Édité le <?= date('d/m/Y') ?></div>
</div>

<!-- Page header -->
<div class="page-header no-print">
  <div>
    <h2><i class="fas fa-chart-bar me-2 text-primary"></i>Rapport des Absences</h2>
    <div class="text-muted fs-sm">Synthèse par intervalle de dates</div>
  </div>
  <a href="<?= APP_URL ?>/modules/etudiants/absences.php" class="btn btn-outline-secondary no-print">
    <i class="fas fa-arrow-left me-1"></i>Retour
  </a>
</div>

<?php showFlash(); ?>

<!-- ══ FILTRES ════════════════════════════════════════════════════════════════ -->
<div class="card mb-4 no-print">
  <div class="card-header"><i class="fas fa-filter me-2"></i>Filtres du rapport</div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <!-- Période -->
      <div class="col-md-2">
        <label class="form-label fw-600">Date début <span class="text-danger">*</span></label>
        <input type="date" name="date_debut" class="form-control" value="<?= h($dateDebut) ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-600">Date fin <span class="text-danger">*</span></label>
        <input type="date" name="date_fin" class="form-control" value="<?= h($dateFin) ?>" required>
      </div>
      <!-- Étudiant -->
      <div class="col-md-3">
        <label class="form-label">Apprenant</label>
        <div class="search-bar">
          <i class="fas fa-search search-icon"></i>
          <input type="text" name="search" class="form-control" placeholder="Nom, prénom, matricule..." value="<?= h($searchEtu) ?>">
        </div>
      </div>
      <?php if (!$isCoord): ?>
      <!-- Filière -->
      <div class="col-md-2">
        <label class="form-label">Filière</label>
        <select name="filiere_id" class="form-select">
          <option value="">Toutes</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $filiereId == $f['id'] ? 'selected' : '' ?>><?= h($f['code'] ?? $f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Niveau -->
      <div class="col-md-2">
        <label class="form-label">Niveau</label>
        <select name="niveau_id" class="form-select">
          <option value="">Tous</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $niveauId == $n['id'] ? 'selected' : '' ?>><?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <!-- Statut justification -->
      <div class="col-md-2">
        <label class="form-label">Justification</label>
        <select name="justifie" class="form-select">
          <option value="">Toutes</option>
          <option value="0" <?= $filtreJust === '0' ? 'selected' : '' ?>>Non justifiées</option>
          <option value="1" <?= $filtreJust === '1' ? 'selected' : '' ?>>Justifiées</option>
        </select>
      </div>
      <!-- Boutons -->
      <div class="col-auto d-flex gap-2 mt-auto">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Générer</button>
        <a href="<?= APP_URL ?>/modules/etudiants/rapport_absences.php" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
    <!-- Raccourcis périodes -->
    <div class="mt-3 d-flex gap-2 flex-wrap">
      <span class="text-muted fs-sm me-1">Raccourcis :</span>
      <?php
        $shortcuts = [
            'Ce mois'       => [date('Y-m-01'), date('Y-m-d')],
            'Mois dernier'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            'Ce trimestre'  => [date('Y-m-01', mktime(0,0,0, floor((date('n')-1)/3)*3+1, 1)), date('Y-m-d')],
            'Cette année'   => [date('Y-01-01'), date('Y-12-31')],
        ];
        foreach ($shortcuts as $label => [$d1, $d2]):
            $q = http_build_query(array_merge($_GET, ['date_debut'=>$d1,'date_fin'=>$d2]));
      ?>
        <a href="?<?= $q ?>" class="btn btn-sm btn-outline-secondary"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══ STATS GLOBALES ════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-user-times"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbEtudiants ?></div><div class="stat-label">Apprenants concernés</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalOcc ?></div><div class="stat-label">Total absences</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalJust ?> <span style="font-size:.85rem;opacity:.7">/ <?= $totalNonJ ?></span></div>
        <div class="stat-label">Justifiées / Non just.</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalH, 1) ?>h</div><div class="stat-label">Heures totales</div></div>
    </div>
  </div>
</div>

<!-- ══ ONGLETS ════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-3 no-print" id="rapportTabs">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSynthese">
      <i class="fas fa-users me-1"></i>Par apprenant
      <span class="badge bg-secondary ms-1"><?= $nbEtudiants ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDetail">
      <i class="fas fa-list me-1"></i>Détail
      <span class="badge bg-secondary ms-1"><?= $totalOcc ?></span>
    </button>
  </li>
</ul>

<div class="tab-content">

<!-- ══ ONGLET 1 : Synthèse par apprenant ═══════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabSynthese">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><strong><?= $nbEtudiants ?></strong> apprenant(s) — Période : <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></span>
      <div class="d-flex gap-2 no-print">
        <a href="<?= APP_URL ?>/modules/etudiants/export_absences_excel.php?<?= h(http_build_query(array_merge($_GET, ['mode'=>'synthese']))) ?>"
           class="btn btn-sm btn-outline-success">
          <i class="fas fa-file-excel me-1"></i>Excel
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
          <i class="fas fa-print me-1"></i>Imprimer
        </button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Apprenant</th>
            <th>Filière / Niveau</th>
            <th class="text-center">Absences</th>
            <th class="text-center">Justifiées</th>
            <th class="text-center">Non just.</th>
            <th class="text-center">Total heures</th>
            <th>Dernière absence</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($synthese)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-check-circle d-block mb-2 text-success" style="font-size:2rem;opacity:.5"></i>
            Aucune absence pour cette période
          </td></tr>
          <?php endif; ?>
          <?php foreach ($synthese as $i => $s): ?>
          <?php
            $pct = $s['total_abs'] > 0 ? min(100, round($s['nb_non_just'] / max($s['total_abs'],1) * 100)) : 0;
            $barColor = $pct >= 75 ? '#ea4335' : ($pct >= 40 ? '#fbbc04' : '#34a853');
          ?>
          <tr>
            <td class="text-muted fs-sm"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-circle" style="background:<?= $s['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:34px;height:34px;font-size:.78rem;flex-shrink:0">
                  <?= strtoupper(substr($s['prenom'],0,1) . substr($s['nom'],0,1)) ?>
                </div>
                <div>
                  <div class="fw-600"><?= h($s['nom'] . ' ' . $s['prenom']) ?></div>
                  <code class="text-muted fs-sm"><?= h($s['matricule']) ?></code>
                </div>
              </div>
            </td>
            <td>
              <?php if ($s['filiere_nom']): ?>
                <div class="fw-600 fs-sm"><?= h($s['filiere_code']) ?></div>
                <div class="text-muted fs-sm"><?= h($s['niveau_nom'] ?? '-') ?></div>
              <?php else: ?><span class="text-muted">-</span><?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge bg-secondary fs-sm px-3"><?= $s['total_abs'] ?></span>
              <!-- Barre progression non-justifiées -->
              <div style="height:4px;background:#e0e0e0;border-radius:4px;margin-top:4px;min-width:60px">
                <div style="height:4px;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:4px"></div>
              </div>
            </td>
            <td class="text-center"><span class="badge bg-success"><?= $s['nb_just'] ?></span></td>
            <td class="text-center"><span class="badge <?= $s['nb_non_just'] > 0 ? 'bg-danger' : 'bg-light text-muted' ?>"><?= $s['nb_non_just'] ?></span></td>
            <td class="text-center fw-600"><?= $s['total_h'] > 0 ? number_format($s['total_h'],1) . 'h' : '—' ?></td>
            <td class="text-muted fs-sm"><?= date('d/m/Y', strtotime($s['derniere_abs'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (!empty($synthese)): ?>
        <tfoot>
          <tr class="fw-bold" style="background:#f5f7fa">
            <td colspan="3" class="text-end">Totaux</td>
            <td class="text-center"><?= $totalOcc ?></td>
            <td class="text-center text-success"><?= $totalJust ?></td>
            <td class="text-center text-danger"><?= $totalNonJ ?></td>
            <td class="text-center"><?= number_format($totalH, 1) ?>h</td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- ══ ONGLET 2 : Détail ════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabDetail">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><strong><?= $totalOcc ?></strong> absence(s)</span>
      <div class="d-flex gap-2 no-print">
        <a href="<?= APP_URL ?>/modules/etudiants/export_absences_excel.php?<?= h(http_build_query(array_merge($_GET, ['mode'=>'detail']))) ?>"
           class="btn btn-sm btn-outline-success">
          <i class="fas fa-file-excel me-1"></i>Excel
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle fs-sm">
        <thead>
          <tr>
            <th>#</th>
            <th>Apprenant</th>
            <th>Filière / Niveau</th>
            <th>Date</th>
            <th>Durée</th>
            <th>Séance / Motif</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($details)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">Aucune absence</td></tr>
          <?php endif; ?>
          <?php foreach ($details as $i => $d): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td>
              <div class="fw-600"><?= h($d['e_nom'] . ' ' . $d['e_prenom']) ?></div>
              <code class="text-muted"><?= h($d['matricule']) ?></code>
            </td>
            <td>
              <?= $d['filiere_nom'] ? '<span class="fw-600">' . h($d['filiere_code']) . '</span><br><span class="text-muted">' . h($d['niveau_nom'] ?? '-') . '</span>' : '<span class="text-muted">-</span>' ?>
            </td>
            <td><?= date('d/m/Y', strtotime($d['date_absence'])) ?>
              <?php if ($d['heure_debut']): ?><br><span class="text-muted"><?= substr($d['heure_debut'],0,5) ?>–<?= substr($d['heure_fin']??'',0,5) ?></span><?php endif; ?>
            </td>
            <td><?= $d['duree_heures'] ? number_format($d['duree_heures'],1).'h' : '—' ?></td>
            <td>
              <?= $d['seance'] ? '<span class="fw-600">'.h($d['seance']).'</span>' : '' ?>
              <?= ($d['seance'] && $d['motif']) ? '<br>' : '' ?>
              <?= $d['motif'] ? '<span class="text-muted">'.h($d['motif']).'</span>' : '' ?>
              <?= (!$d['seance'] && !$d['motif']) ? '—' : '' ?>
            </td>
            <td>
              <?php if ((int)$d['justifie'] === 1): ?>
                <span class="badge bg-success">Justifiée</span>
              <?php else: ?>
                <span class="badge bg-danger">Non just.</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /tab-content -->

<?php include APP_ROOT . '/includes/footer.php'; ?>
