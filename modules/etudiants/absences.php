<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'coordinateur']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$isCoord = hasRole('coordinateur');
$canWrite = hasRole(['admin', 'scolarite']);

// ── Migration inline ──────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS absences (
        id            INT PRIMARY KEY AUTO_INCREMENT,
        etudiant_id   INT NOT NULL,
        date_absence  DATE NOT NULL,
        heure_debut   TIME NULL,
        heure_fin     TIME NULL,
        duree_heures  DECIMAL(4,1) NULL,
        seance        VARCHAR(200) NULL,
        motif         VARCHAR(255) NULL,
        justifie      TINYINT(1) DEFAULT 0,
        justification TEXT NULL,
        annee_id      INT NULL,
        created_by    INT NULL,
        ecole_id      INT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$errors = [];

// ── AJOUT ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $canWrite) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide.';
    } else {
        $etudiantId   = (int)($_POST['etudiant_id']  ?? 0);
        $dateAbsence  = sanitize($_POST['date_absence'] ?? date('Y-m-d'));
        $heureDebut   = sanitize($_POST['heure_debut'] ?? '') ?: null;
        $heureFin     = sanitize($_POST['heure_fin']   ?? '') ?: null;
        $dureeManuel  = (float)($_POST['duree_heures'] ?? 0);
        $seance       = sanitize($_POST['seance']      ?? '');
        $motif        = sanitize($_POST['motif']       ?? '');
        $justifie     = isset($_POST['justifie']) ? 1 : 0;
        $justification = sanitize($_POST['justification'] ?? '');
        $anneeId      = (int)($_POST['annee_id'] ?? 0);

        // Calculer durée depuis heures de début/fin
        $duree = null;
        if ($heureDebut && $heureFin) {
            $ts1 = strtotime($heureDebut);
            $ts2 = strtotime($heureFin);
            if ($ts2 > $ts1) $duree = round(($ts2 - $ts1) / 3600, 1);
        } elseif ($dureeManuel > 0) {
            $duree = $dureeManuel;
        }

        if (!$etudiantId) {
            $errors[] = 'Veuillez sélectionner un apprenant.';
        } elseif (!$dateAbsence) {
            $errors[] = 'La date est obligatoire.';
        } else {
            // Vérifier que l'étudiant appartient à l'école
            $chk = $db->prepare("SELECT id FROM etudiants WHERE id=?" . ($ecoleId > 0 ? " AND ecole_id=?" : ""));
            $chkP = [$etudiantId];
            if ($ecoleId > 0) $chkP[] = $ecoleId;
            $chk->execute($chkP);
            if (!$chk->fetch()) {
                $errors[] = 'Apprenant introuvable.';
            } else {
                $db->prepare("INSERT INTO absences
                    (etudiant_id, date_absence, heure_debut, heure_fin, duree_heures,
                     seance, motif, justifie, justification, annee_id, created_by, ecole_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $etudiantId, $dateAbsence, $heureDebut, $heureFin, $duree,
                       $seance ?: null, $motif ?: null, $justifie,
                       ($justifie && $justification) ? $justification : null,
                       $anneeId ?: null, $user['id'], $ecoleId > 0 ? $ecoleId : null
                   ]);
                setFlash('success', 'Absence enregistrée avec succès.');
                redirect('/modules/etudiants/absences.php?' . http_build_query(array_filter($_GET)));
            }
        }
    }
}

// ── TOGGLE JUSTIFICATION ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle' && $canWrite) {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $absId  = (int)($_POST['abs_id'] ?? 0);
        $newVal = (int)($_POST['new_val'] ?? 0);
        $whereE = $ecoleId > 0 ? " AND ecole_id=?" : "";
        $pToggle = [$newVal, $absId];
        if ($ecoleId > 0) $pToggle[] = $ecoleId;
        $db->prepare("UPDATE absences SET justifie=? WHERE id=?" . $whereE)->execute($pToggle);
        setFlash('success', $newVal ? 'Absence marquée justifiée.' : 'Absence marquée non justifiée.');
        redirect('/modules/etudiants/absences.php?' . http_build_query(array_filter($_GET)));
    }
}

// ── FILTRES ───────────────────────────────────────────────────────────────────
$search     = sanitize($_GET['search']     ?? '');
$filiereId  = (int)($_GET['filiere_id']    ?? 0);
$niveauId   = (int)($_GET['niveau_id']     ?? 0);
$anneeIdF   = (int)($_GET['annee_id']      ?? 0);
$dateDebut  = sanitize($_GET['date_debut'] ?? '');
$dateFin    = sanitize($_GET['date_fin']   ?? '');
$filtreJust = sanitize($_GET['justifie']   ?? '');

// ── REQUÊTE PRINCIPALE ────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($ecoleId > 0) {
    $where[]  = 'a.ecole_id = ?';
    $params[] = $ecoleId;
}
if ($isCoord) {
    $where[] = coordSectionWhere('e', $params);
}
if ($search) {
    $where[]  = '(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)';
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filiereId) { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)  { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($anneeIdF)  { $where[] = 'a.annee_id = ?';   $params[] = $anneeIdF; }
if ($dateDebut) { $where[] = 'a.date_absence >= ?'; $params[] = $dateDebut; }
if ($dateFin)   { $where[] = 'a.date_absence <= ?'; $params[] = $dateFin; }
if ($filtreJust !== '') { $where[] = 'a.justifie = ?'; $params[] = (int)$filtreJust; }

$whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("
    SELECT a.*,
           e.nom AS e_nom, e.prenom AS e_prenom, e.matricule, e.sexe,
           f.code AS filiere_code, f.nom AS filiere_nom,
           n.nom  AS niveau_nom,
           CONCAT(u.prenom,' ',u.nom) AS agent
    FROM absences a
    JOIN etudiants e ON e.id = a.etudiant_id
    LEFT JOIN filieres  f ON f.id = e.filiere_id
    LEFT JOIN niveaux   n ON n.id = e.niveau_id
    LEFT JOIN users     u ON u.id = a.created_by
    $whereStr
    ORDER BY a.date_absence DESC, e.nom, e.prenom
");
$stmt->execute($params);
$absences = $stmt->fetchAll();

// ── STATS ─────────────────────────────────────────────────────────────────────
$total      = count($absences);
$nbJust     = count(array_filter($absences, fn($a) => (int)$a['justifie'] === 1));
$nbNonJust  = $total - $nbJust;
$totalH     = array_sum(array_column($absences, 'duree_heures'));

// ── DONNÉES POUR LE FORMULAIRE ────────────────────────────────────────────────
$filieres    = $isCoord
    ? array_values(array_unique(array_map(fn($s) => ['id'=>$s['filiere_id'],'nom'=>$s['filiere_nom'],'code'=>$s['filiere_code']], getCoordinateurSections()), SORT_REGULAR))
    : getFilieres();
$niveaux     = getNiveaux();
$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

$pageTitle  = 'Gestion des Absences';
$breadcrumb = ['Étudiants' => APP_URL . '/modules/etudiants/index.php', 'Absences' => null];
include APP_ROOT . '/includes/header.php';
?>

<!-- En-tête imprimable -->
<?php
$ecole        = getCurrentEcole();
$nomEcole     = $ecole['nom']       ?? getParam('etablissement_nom', 'E-EDU PRO');
$adresseEcole = $ecole['adresse']   ?? getParam('etablissement_adresse', '');
$villeEcole   = $ecole['ville']     ?? getParam('etablissement_ville', '');
$telEcole     = $ecole['telephone'] ?? getParam('etablissement_telephone', '');
$logoPath     = $ecole['logo_path'] ?? '';
?>
<div class="print-only text-center mb-3" style="border-bottom:2px solid #1565c0;padding-bottom:12px;">
  <?php if ($logoPath): ?>
  <img src="<?= APP_URL . '/' . h($logoPath) ?>" alt="Logo" style="height:68px;object-fit:contain;display:block;margin:0 auto 8px;">
  <?php endif; ?>
  <div style="font-size:1.3rem;font-weight:700;"><?= h($nomEcole) ?></div>
  <?php if ($adresseEcole || $villeEcole): ?>
  <div style="font-size:.88rem;color:#555;"><?= h(trim($adresseEcole . ($villeEcole ? ' – ' . $villeEcole : ''))) ?></div>
  <?php endif; ?>
  <?php if ($telEcole): ?><div style="font-size:.85rem;color:#555;">Tél : <?= h($telEcole) ?></div><?php endif; ?>
  <div style="font-size:1.05rem;font-weight:600;margin-top:10px;">Registre des Absences</div>
  <?php if ($dateDebut || $dateFin): ?>
  <div style="font-size:.82rem;color:#888;">
    Période :
    <?= $dateDebut ? date('d/m/Y', strtotime($dateDebut)) : '...' ?>
    au
    <?= $dateFin ? date('d/m/Y', strtotime($dateFin)) : '...' ?>
  </div>
  <?php endif; ?>
  <div style="font-size:.82rem;color:#888;">Édité le <?= date('d/m/Y') ?></div>
</div>

<!-- Page header -->
<div class="page-header no-print">
  <h2><i class="fas fa-calendar-times me-2 text-primary"></i>Gestion des Absences</h2>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/modules/etudiants/rapport_absences.php" class="btn btn-outline-info">
      <i class="fas fa-chart-bar me-1"></i>Rapport
    </a>
    <?php if ($canWrite): ?>
    <a href="<?= APP_URL ?>/modules/etudiants/feuille_appel.php" class="btn btn-outline-primary">
      <i class="fas fa-clipboard-list me-2"></i>Feuille d'appel
    </a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAbsModal">
      <i class="fas fa-plus me-2"></i>Absence individuelle
    </button>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>
<?php showFlash(); ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total absences</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbNonJust ?></div><div class="stat-label">Non justifiées</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbJust ?></div><div class="stat-label">Justifiées</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalH, 1) ?>h</div><div class="stat-label">Total heures</div></div>
    </div>
  </div>
</div>

<!-- Filtres -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <div class="search-bar">
          <i class="fas fa-search search-icon"></i>
          <input type="text" name="search" class="form-control" placeholder="Nom, prénom, matricule..." value="<?= h($search) ?>">
        </div>
      </div>
      <?php if (!$isCoord): ?>
      <div class="col-md-2">
        <select name="filiere_id" class="form-select">
          <option value="">Toutes filières</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $filiereId == $f['id'] ? 'selected' : '' ?>><?= h($f['code'] ?? $f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="niveau_id" class="form-select">
          <option value="">Tous niveaux</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $niveauId == $n['id'] ? 'selected' : '' ?>><?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <input type="date" name="date_debut" class="form-control" value="<?= h($dateDebut) ?>" title="Date début">
      </div>
      <div class="col-md-2">
        <input type="date" name="date_fin" class="form-control" value="<?= h($dateFin) ?>" title="Date fin">
      </div>
      <div class="col-md-2">
        <select name="justifie" class="form-select">
          <option value="">Toutes</option>
          <option value="0" <?= $filtreJust === '0' ? 'selected' : '' ?>>Non justifiées</option>
          <option value="1" <?= $filtreJust === '1' ? 'selected' : '' ?>>Justifiées</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
        <a href="<?= APP_URL ?>/modules/etudiants/absences.php" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><strong><?= number_format($total) ?></strong> absence(s) trouvée(s)</span>
    <div class="d-flex gap-2 no-print">
      <a href="<?= APP_URL ?>/modules/etudiants/export_absences_excel.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-sm btn-outline-success">
        <i class="fas fa-file-excel me-1"></i>Excel
      </a>
      <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-print me-1"></i>Imprimer
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table align-middle" id="dataTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Apprenant</th>
          <th>Filière / Niveau</th>
          <th>Date</th>
          <th>Durée</th>
          <th>Séance / Motif</th>
          <th>Statut</th>
          <?php if ($canWrite): ?><th class="no-print">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($absences)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-calendar-check d-block mb-2" style="font-size:2rem;opacity:.3"></i>
            Aucune absence trouvée
          </td></tr>
        <?php endif; ?>
        <?php foreach ($absences as $i => $a): ?>
        <tr>
          <td class="text-muted fs-sm"><?= $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle" style="background:<?= $a['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:34px;height:34px;font-size:.78rem;flex-shrink:0">
                <?= strtoupper(substr($a['e_prenom'],0,1) . substr($a['e_nom'],0,1)) ?>
              </div>
              <div>
                <div class="fw-600"><?= h($a['e_nom'] . ' ' . $a['e_prenom']) ?></div>
                <div class="text-muted fs-sm"><code><?= h($a['matricule']) ?></code></div>
              </div>
            </div>
          </td>
          <td>
            <?php if ($a['filiere_nom']): ?>
              <div class="fw-600 fs-sm"><?= h($a['filiere_code']) ?></div>
              <div class="text-muted fs-sm"><?= h($a['niveau_nom'] ?? '-') ?></div>
            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
          </td>
          <td class="fw-600 fs-sm"><?= date('d/m/Y', strtotime($a['date_absence'])) ?>
            <?php if ($a['heure_debut']): ?>
              <div class="text-muted" style="font-size:.78rem"><?= substr($a['heure_debut'],0,5) ?>–<?= substr($a['heure_fin']??'',0,5) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($a['duree_heures']): ?>
              <span class="badge bg-secondary"><?= number_format($a['duree_heures'],1) ?>h</span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($a['seance']): ?>
              <div class="fw-600 fs-sm"><?= h($a['seance']) ?></div>
            <?php endif; ?>
            <?php if ($a['motif']): ?>
              <div class="text-muted fs-sm"><?= h($a['motif']) ?></div>
            <?php elseif (!$a['seance']): ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$a['justifie'] === 1): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Justifiée</span>
              <?php if ($a['justification']): ?>
                <div class="text-muted fs-sm mt-1" style="max-width:160px;white-space:normal"><?= h($a['justification']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Non justifiée</span>
            <?php endif; ?>
          </td>
          <?php if ($canWrite): ?>
          <td class="no-print">
            <div class="d-flex gap-1">
              <!-- Toggle justification -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf"    value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action"  value="toggle">
                <input type="hidden" name="abs_id"  value="<?= $a['id'] ?>">
                <input type="hidden" name="new_val" value="<?= (int)$a['justifie'] === 1 ? 0 : 1 ?>">
                <?php foreach ($_GET as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-icon btn-sm <?= (int)$a['justifie'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= (int)$a['justifie'] ? 'Marquer non justifiée' : 'Marquer justifiée' ?>" data-bs-toggle="tooltip">
                  <i class="fas <?= (int)$a['justifie'] ? 'fa-times' : 'fa-check' ?>"></i>
                </button>
              </form>
              <!-- Supprimer -->
              <form method="POST" action="<?= APP_URL ?>/modules/etudiants/absence_delete.php" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="abs_id" value="<?= $a['id'] ?>">
                <button type="button" class="btn btn-icon btn-sm btn-outline-danger" title="Supprimer"
                        onclick="confirmDelete(this.form,'cette absence')" data-bs-toggle="tooltip">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canWrite): ?>
<!-- ══ MODAL : Nouvelle absence ══════════════════════════════════════════════ -->
<div class="modal fade" id="addAbsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Enregistrer une absence</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="absForm">
        <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">

        <div class="modal-body">

          <!-- Recherche étudiant -->
          <div class="mb-3">
            <label class="form-label fw-600">Apprenant <span class="text-danger">*</span></label>
            <div class="position-relative">
              <input type="text" id="etudiantSearch" class="form-control"
                     placeholder="Rechercher par nom, prénom ou matricule..." autocomplete="off">
              <input type="hidden" name="etudiant_id" id="etudiantId" required>
              <div id="etudiantSuggestions" class="list-group position-absolute w-100 shadow-sm" style="z-index:1060;display:none;max-height:220px;overflow-y:auto"></div>
            </div>
            <div id="etudiantChoisi" class="mt-2 d-none">
              <span class="badge bg-primary fs-sm py-2 px-3 d-flex align-items-center gap-2" style="width:fit-content">
                <i class="fas fa-user-graduate"></i>
                <span id="etudiantNomAffiche"></span>
                <button type="button" class="btn-close btn-close-white" style="font-size:.65rem" onclick="resetEtudiant()"></button>
              </span>
            </div>
          </div>

          <div class="row g-3">
            <!-- Date -->
            <div class="col-md-4">
              <label class="form-label fw-600">Date absence <span class="text-danger">*</span></label>
              <input type="date" name="date_absence" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <!-- Heure début -->
            <div class="col-md-4">
              <label class="form-label">Heure début</label>
              <input type="time" name="heure_debut" id="heureDebut" class="form-control">
            </div>
            <!-- Heure fin -->
            <div class="col-md-4">
              <label class="form-label">Heure fin</label>
              <input type="time" name="heure_fin" id="heureFin" class="form-control">
            </div>
            <!-- Durée calculée / manuelle -->
            <div class="col-md-4">
              <label class="form-label">Durée (heures)</label>
              <input type="number" name="duree_heures" id="dureeHeures" class="form-control"
                     min="0.5" max="24" step="0.5" placeholder="Auto ou saisie">
              <div class="form-text">Calculée automatiquement si heures renseignées.</div>
            </div>
            <!-- Séance -->
            <div class="col-md-8">
              <label class="form-label">Séance / Cours</label>
              <input type="text" name="seance" class="form-control" placeholder="Ex. : Mathématiques, Informatique...">
            </div>
            <!-- Motif -->
            <div class="col-12">
              <label class="form-label">Motif déclaré</label>
              <input type="text" name="motif" class="form-control" placeholder="Motif de l'absence (facultatif)">
            </div>
            <!-- Année académique -->
            <div class="col-md-6">
              <label class="form-label">Année académique</label>
              <select name="annee_id" class="form-select">
                <option value="">— Sélectionner —</option>
                <?php foreach ($annees as $an): ?>
                  <option value="<?= $an['id'] ?>" <?= ($an['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>>
                    <?= h($an['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Justifiée -->
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="justifie" id="justifieCheck"
                       role="switch" onchange="toggleJustifInput(this)">
                <label class="form-check-label fw-600" for="justifieCheck">Absence justifiée</label>
              </div>
            </div>
            <!-- Justification -->
            <div class="col-12" id="justificationBlock" style="display:none">
              <label class="form-label">Pièce / Justification</label>
              <textarea name="justification" class="form-control" rows="2"
                        placeholder="Description de la justification (certificat médical, etc.)"></textarea>
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
<?php endif; ?>

<script>
// ── Autocomplete étudiant ─────────────────────────────────────────────────────
const searchInput  = document.getElementById('etudiantSearch');
const hiddenId     = document.getElementById('etudiantId');
const suggestions  = document.getElementById('etudiantSuggestions');
const choisiDiv    = document.getElementById('etudiantChoisi');
const nomAffiche   = document.getElementById('etudiantNomAffiche');
let abortCtrl      = null;

searchInput?.addEventListener('input', function () {
    const q = this.value.trim();
    if (q.length < 2) { suggestions.style.display = 'none'; return; }
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    fetch('<?= APP_URL ?>/api/search_etudiants.php?q=' + encodeURIComponent(q), { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(data => {
            suggestions.innerHTML = '';
            if (!data.length) {
                suggestions.innerHTML = '<div class="list-group-item text-muted fs-sm">Aucun résultat</div>';
            } else {
                data.forEach(e => {
                    const btn = document.createElement('button');
                    btn.type      = 'button';
                    btn.className = 'list-group-item list-group-item-action fs-sm';
                    btn.innerHTML = `<i class="fas fa-user-graduate me-2 text-primary"></i><strong>${e.nom} ${e.prenom}</strong> <span class="text-muted ms-2">${e.matricule}</span>`;
                    btn.onclick   = () => selectEtudiant(e.id, e.nom + ' ' + e.prenom + ' (' + e.matricule + ')');
                    suggestions.appendChild(btn);
                });
            }
            suggestions.style.display = 'block';
        })
        .catch(() => {});
});

function selectEtudiant(id, label) {
    hiddenId.value          = id;
    searchInput.value       = '';
    nomAffiche.textContent  = label;
    choisiDiv.classList.remove('d-none');
    searchInput.style.display = 'none';
    suggestions.style.display = 'none';
}

function resetEtudiant() {
    hiddenId.value            = '';
    nomAffiche.textContent    = '';
    choisiDiv.classList.add('d-none');
    searchInput.style.display = '';
    searchInput.value         = '';
    searchInput.focus();
}

document.addEventListener('click', e => {
    if (!suggestions.contains(e.target) && e.target !== searchInput) {
        suggestions.style.display = 'none';
    }
});

// ── Calcul auto durée ─────────────────────────────────────────────────────────
function calcDuree() {
    const hd = document.getElementById('heureDebut')?.value;
    const hf = document.getElementById('heureFin')?.value;
    if (hd && hf) {
        const [h1, m1] = hd.split(':').map(Number);
        const [h2, m2] = hf.split(':').map(Number);
        const diff = (h2 * 60 + m2) - (h1 * 60 + m1);
        if (diff > 0) {
            document.getElementById('dureeHeures').value = (diff / 60).toFixed(1);
        }
    }
}
document.getElementById('heureDebut')?.addEventListener('change', calcDuree);
document.getElementById('heureFin')?.addEventListener('change', calcDuree);

// ── Toggle champ justification ────────────────────────────────────────────────
function toggleJustifInput(cb) {
    document.getElementById('justificationBlock').style.display = cb.checked ? '' : 'none';
}

// Ré-ouvrir le modal avec erreurs si needed
<?php if ($errors && $_POST): ?>
new bootstrap.Modal(document.getElementById('addAbsModal')).show();
<?php endif; ?>
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
