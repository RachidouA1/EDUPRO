<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['coordinateur', 'admin']);

$db   = getDB();
$user = getCurrentUser();

// Auto-create tables if migration not yet run
try {
    $db->exec("CREATE TABLE IF NOT EXISTS seances_cours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        emploi_slot_id INT NOT NULL,
        enseignant_id INT NOT NULL,
        matiere_id INT NOT NULL,
        filiere_id INT NOT NULL,
        niveau_id INT NULL,
        annee_id INT NOT NULL,
        date_seance DATE NOT NULL,
        heure_debut TIME NOT NULL,
        heure_fin TIME NOT NULL,
        duree_heures DECIMAL(4,2) NOT NULL DEFAULT 2.00,
        notes TEXT,
        marked_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_seance_slot (emploi_slot_id),
        FOREIGN KEY (emploi_slot_id) REFERENCES emplois_slots(id) ON DELETE CASCADE,
        FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
        FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE,
        FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE CASCADE,
        FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS demandes_paiement_enseignant (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enseignant_id INT NOT NULL,
        coordinateur_id INT NOT NULL,
        annee_id INT NULL,
        date_debut DATE NOT NULL,
        date_fin DATE NOT NULL,
        total_heures DECIMAL(6,2) NOT NULL DEFAULT 0,
        details_matieres TEXT,
        statut ENUM('en_attente','traite','rejete') DEFAULT 'en_attente',
        notes_coordinateur TEXT,
        notes_comptable TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        traite_at TIMESTAMP NULL,
        traite_par INT NULL,
        FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
        FOREIGN KEY (coordinateur_id) REFERENCES users(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// ── POST: envoyer demande de paiement ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'demande_paiement') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
    } else {
        $ensId     = (int)($_POST['enseignant_id'] ?? 0);
        $dateDebut = sanitize($_POST['date_debut'] ?? '');
        $dateFin   = sanitize($_POST['date_fin']   ?? '');
        $heures    = (float)($_POST['total_heures'] ?? 0);
        $details   = sanitize($_POST['details_matieres'] ?? '');
        $notes     = sanitize($_POST['notes_coordinateur'] ?? '');
        $anneeId   = (int)($_POST['annee_id'] ?? 0) ?: null;

        if (!$ensId || !$dateDebut || !$dateFin || $heures <= 0) {
            setFlash('error', 'Enseignant, période et heures sont obligatoires.');
        } else {
            try {
                $db->prepare("
                    INSERT INTO demandes_paiement_enseignant
                        (enseignant_id, coordinateur_id, annee_id, date_debut, date_fin,
                         total_heures, details_matieres, notes_coordinateur)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$ensId, $user['id'], $anneeId, $dateDebut, $dateFin, $heures, $details, $notes]);
                setFlash('success', 'Demande de paiement envoyée à la comptabilité.');
            } catch (PDOException $e) {
                setFlash('error', 'Erreur : ' . $e->getMessage());
            }
        }
    }
    redirect('/modules/administration/suivi_cours.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ── Filters ────────────────────────────────────────────────────────────────
$anneeActive = getActiveAnnee();
$anneeId     = (int)($_GET['annee_id'] ?? ($anneeActive['id'] ?? 0));
$annees      = getAnneesAcademiques();
$isCoord     = hasRole('coordinateur');

// ── Load approved emplois accessible to this user ─────────────────────────
$coordParams = [];
if ($isCoord) {
    $coordWhere = 'AND ' . coordSectionWhere('edt', $coordParams);
} else {
    $coordWhere = '';
}

$emploiSQL = "
    SELECT edt.id, edt.filiere_id, edt.niveau_id, edt.annee_id,
           edt.semaine_debut, edt.semaine_fin,
           f.nom as filiere_nom, f.code as filiere_code,
           n.nom as niveau_nom
    FROM emplois_du_temps edt
    JOIN filieres f ON f.id = edt.filiere_id
    LEFT JOIN niveaux n ON n.id = edt.niveau_id
    WHERE edt.statut = 'approuve'
    " . ($anneeId ? " AND edt.annee_id = ?" : "") . "
    {$coordWhere}
    ORDER BY edt.semaine_debut DESC
";
$bindParams = $anneeId ? array_merge([$anneeId], $coordParams) : $coordParams;
$emploiStmt = $db->prepare($emploiSQL);
$emploiStmt->execute($bindParams);
$emplois    = $emploiStmt->fetchAll();

// ── Load all slots from those emplois ─────────────────────────────────────
$allSlots       = [];
$matiereProgress = [];
$teacherSummary = [];
$jourOffset     = ['lundi'=>0,'mardi'=>1,'mercredi'=>2,'jeudi'=>3,'vendredi'=>4,'samedi'=>5];
$JOUR_LABELS    = ['lundi'=>'Lundi','mardi'=>'Mardi','mercredi'=>'Mercredi','jeudi'=>'Jeudi','vendredi'=>'Vendredi','samedi'=>'Samedi'];

if (!empty($emplois)) {
    $emploiIds = array_column($emplois, 'id');
    $plh       = implode(',', array_fill(0, count($emploiIds), '?'));

    $slotStmt = $db->prepare("
        SELECT
            es.id as slot_id, es.emploi_id, es.jour, es.heure_debut, es.heure_fin,
            es.matiere_id, es.enseignant_id,
            m.nom as matiere_nom, m.code as matiere_code, m.volume_horaire,
            CONCAT(e.prenom,' ',e.nom) as enseignant_nom,
            e.id as ens_id, e.matricule as ens_matricule,
            edt.semaine_debut, edt.filiere_id as edt_filiere_id,
            edt.niveau_id as edt_niveau_id, edt.annee_id as edt_annee_id,
            sc.id as seance_id, sc.created_at as seance_at,
            mu.nom as marked_nom, mu.prenom as marked_prenom
        FROM emplois_slots es
        JOIN emplois_du_temps edt ON edt.id = es.emploi_id
        LEFT JOIN matieres m ON m.id = es.matiere_id
        LEFT JOIN enseignants e ON e.id = es.enseignant_id
        LEFT JOIN seances_cours sc ON sc.emploi_slot_id = es.id
        LEFT JOIN users mu ON mu.id = sc.marked_by
        WHERE es.emploi_id IN ({$plh}) AND es.enseignant_id IS NOT NULL AND es.matiere_id IS NOT NULL
        ORDER BY edt.semaine_debut, FIELD(es.jour,'lundi','mardi','mercredi','jeudi','vendredi','samedi'), es.heure_debut
    ");
    $slotStmt->execute($emploiIds);
    $rawSlots = $slotStmt->fetchAll();

    foreach ($rawSlots as $s) {
        $offset    = $jourOffset[$s['jour']] ?? 0;
        $dateSeance = date('Y-m-d', strtotime($s['semaine_debut']) + $offset * 86400);
        $s['date_seance'] = $dateSeance;
        $allSlots[]       = $s;

        // Progress per matière
        $mid = $s['matiere_id'];
        if (!isset($matiereProgress[$mid])) {
            $matiereProgress[$mid] = [
                'nom'              => $s['matiere_nom'],
                'code'             => $s['matiere_code'],
                'volume_horaire'   => (float)($s['volume_horaire'] ?? 0),
                'heures_planifiees'=> 0,
                'heures_effectuees'=> 0,
                'enseignant_nom'   => $s['enseignant_nom'],
                'ens_id'           => $s['ens_id'],
            ];
        }
        $matiereProgress[$mid]['heures_planifiees'] += 2;
        if ($s['seance_id']) $matiereProgress[$mid]['heures_effectuees'] += 2;

        // Summary per teacher
        $eid = $s['ens_id'];
        if (!isset($teacherSummary[$eid])) {
            $teacherSummary[$eid] = [
                'nom'              => $s['enseignant_nom'],
                'matricule'        => $s['ens_matricule'],
                'heures_planifiees'=> 0,
                'heures_effectuees'=> 0,
                'dates'            => [],
                'matieres'         => [],
            ];
        }
        $teacherSummary[$eid]['heures_planifiees'] += 2;
        if ($s['seance_id']) {
            $teacherSummary[$eid]['heures_effectuees'] += 2;
            $teacherSummary[$eid]['dates'][] = $dateSeance;
        }
        if (!isset($teacherSummary[$eid]['matieres'][$mid])) {
            $teacherSummary[$eid]['matieres'][$mid] = ['nom' => $s['matiere_nom'], 'code' => $s['matiere_code'], 'heures' => 0];
        }
        if ($s['seance_id']) $teacherSummary[$eid]['matieres'][$mid]['heures'] += 2;
    }

    // Compute period per teacher
    foreach ($teacherSummary as &$t) {
        if (!empty($t['dates'])) {
            sort($t['dates']);
            $t['date_debut'] = reset($t['dates']);
            $t['date_fin']   = end($t['dates']);
        } else {
            $t['date_debut'] = date('Y-m-d');
            $t['date_fin']   = date('Y-m-d');
        }
    }
    unset($t);
}

// ── Stats ──────────────────────────────────────────────────────────────────
$totalSlots     = count($allSlots);
$totalEffectues = count(array_filter($allSlots, fn($s) => $s['seance_id']));
$totalHPlan     = $totalSlots * 2;
$totalHEff      = $totalEffectues * 2;
$globalPct      = $totalSlots > 0 ? round($totalEffectues / $totalSlots * 100) : 0;

$csrfToken      = generateCsrfToken();
$anneeSelecte   = null;
foreach ($annees as $a) { if ($a['id'] == $anneeId) { $anneeSelecte = $a; break; } }

$pageTitle  = 'Suivi des Cours';
$breadcrumb = ['Administration' => null, 'Suivi des Cours' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-tasks me-2 text-primary"></i>Suivi des Cours</h2>
    <div class="text-muted fs-sm">
      <?= $anneeSelecte ? h($anneeSelecte['libelle']) : 'Toutes les années' ?>
      — <?= $totalEffectues ?> / <?= $totalSlots ?> séances effectuées
    </div>
  </div>
  <a href="<?= APP_URL ?>/modules/administration/demandes_paiement.php" class="btn btn-success">
    <i class="fas fa-paper-plane me-2"></i>Demandes de paiement
  </a>
</div>

<?php showFlash(); ?>

<!-- ── Filtre année ────────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-600 mb-0 text-nowrap">Année académique :</label>
      <select name="annee_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="">— Toutes —</option>
        <?php foreach ($annees as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $a['id'] == $anneeId ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<!-- ── Statistiques ───────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSlots ?></div><div class="stat-label">Séances programmées</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEffectues ?></div><div class="stat-label">Séances effectuées</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalHPlan ?>h</div><div class="stat-label">Heures planifiées</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#7b2ff7,#6c1fc2);color:#fff">
      <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalHEff ?>h</div><div class="stat-label">Heures effectuées</div></div>
    </div>
  </div>
</div>

<?php if (empty($emplois)): ?>
<div class="card">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-calendar-times d-block mb-3" style="font-size:3rem;opacity:.25"></i>
    <p class="mb-1 fw-600">Aucun emploi du temps approuvé trouvé</p>
    <p class="fs-sm">Les emplois du temps doivent être <strong>approuvés</strong> par le directeur pour apparaître ici.</p>
    <a href="<?= APP_URL ?>/modules/emploi_du_temps/index.php" class="btn btn-outline-primary mt-2">
      <i class="fas fa-calendar-week me-2"></i>Voir les emplois du temps
    </a>
  </div>
</div>
<?php else: ?>

<!-- ── Tabs ──────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="suiviTabs">
  <li class="nav-item">
    <a class="nav-link active" data-bs-toggle="tab" href="#tab-matieres">
      <i class="fas fa-chart-bar me-2"></i>Progression par Matière
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-seances">
      <i class="fas fa-list-check me-2"></i>Toutes les séances
      <span class="badge bg-secondary ms-1"><?= $totalSlots ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-enseignants">
      <i class="fas fa-chalkboard-teacher me-2"></i>Par Enseignant
      <span class="badge bg-secondary ms-1"><?= count($teacherSummary) ?></span>
    </a>
  </li>
</ul>

<div class="tab-content">

  <!-- ── Tab 1 : Progression par matière ───────────────────────────────── -->
  <div class="tab-pane fade show active" id="tab-matieres">
    <?php if (empty($matiereProgress)): ?>
      <div class="card"><div class="card-body text-center text-muted py-4">Aucune matière avec enseignant assigné.</div></div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($matiereProgress as $mid => $mp):
        $vh  = $mp['volume_horaire'] ?: max($mp['heures_planifiees'], 1);
        $pct = min(100, $mp['heures_effectuees'] > 0 ? round($mp['heures_effectuees'] / $vh * 100) : 0);
        $planPct = min(100, $mp['heures_planifiees'] > 0 ? round($mp['heures_planifiees'] / $vh * 100) : 0);
        $color = $pct >= 100 ? 'success' : ($pct >= 50 ? 'primary' : ($pct >= 25 ? 'warning' : 'danger'));
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-bold">
                  <span class="badge bg-secondary me-1 fs-sm"><?= h($mp['code']) ?></span>
                  <?= h($mp['nom']) ?>
                </div>
                <div class="text-muted fs-sm mt-1">
                  <i class="fas fa-user-tie me-1"></i><?= h($mp['enseignant_nom'] ?? '–') ?>
                </div>
              </div>
              <?php if ($pct >= 100): ?>
                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Terminé</span>
              <?php else: ?>
                <span class="badge bg-<?= $color ?>"><?= $pct ?>%</span>
              <?php endif; ?>
            </div>

            <div class="mb-1 d-flex justify-content-between fs-sm text-muted">
              <span>Effectué</span>
              <span class="fw-600 text-dark"><?= $mp['heures_effectuees'] ?>h / <?= $vh ?>h</span>
            </div>
            <div class="progress mb-2" style="height:10px" title="<?= $pct ?>% effectué">
              <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
            </div>

            <?php if ($mp['heures_planifiees'] > $mp['heures_effectuees']): ?>
            <div class="fs-sm text-muted">
              <span class="badge bg-light text-dark border">
                <i class="fas fa-calendar me-1"></i><?= $mp['heures_planifiees'] ?>h planifiées au total
              </span>
            </div>
            <?php endif; ?>

            <?php if ($mp['volume_horaire'] > 0): ?>
            <div class="fs-sm text-muted mt-1">
              Volume horaire curriculum : <strong><?= $mp['volume_horaire'] ?>h</strong>
            </div>
            <?php endif; ?>
          </div>
          <div class="card-footer py-2 bg-transparent d-flex justify-content-between align-items-center">
            <span class="fs-sm text-muted"><?= $mp['heures_effectuees'] ?>h effectuées / <?= $mp['heures_planifiees'] ?>h programmées</span>
            <span class="badge bg-<?= $color ?> bg-opacity-10 text-<?= $color ?> border border-<?= $color ?>">
              <?= $pct ?>%
            </span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Tab 2 : Toutes les séances ────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-seances">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2 text-primary"></i>Séances à pointer</span>
        <small class="text-muted">Cliquez sur le bouton pour marquer/démarquer une séance comme effectuée</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" id="tableSeances">
          <thead>
            <tr>
              <th>Date</th>
              <th>Créneau</th>
              <th>Matière</th>
              <th>Enseignant</th>
              <th>Semaine</th>
              <th style="width:130px;text-align:center">Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allSlots)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Aucune séance à afficher.</td></tr>
            <?php endif; ?>
            <?php foreach ($allSlots as $s): ?>
            <?php
              $isDone = !empty($s['seance_id']);
              $jourLabel = $JOUR_LABELS[$s['jour']] ?? $s['jour'];
            ?>
            <tr id="row-<?= $s['slot_id'] ?>" class="<?= $isDone ? 'table-success' : '' ?>">
              <td class="fw-600 text-nowrap">
                <?= $jourLabel ?><br>
                <small class="text-muted"><?= formatDate($s['date_seance']) ?></small>
              </td>
              <td class="text-nowrap fs-sm">
                <?= substr($s['heure_debut'],0,5) ?> – <?= substr($s['heure_fin'],0,5) ?>
              </td>
              <td>
                <span class="badge bg-secondary me-1"><?= h($s['matiere_code']) ?></span>
                <?= h($s['matiere_nom']) ?>
              </td>
              <td>
                <i class="fas fa-user-tie me-1 text-muted"></i>
                <?= h($s['enseignant_nom']) ?>
              </td>
              <td class="fs-sm text-muted">
                <?= formatDate($s['semaine_debut']) ?>
                <?php
                  // Find emploi info
                  foreach ($emplois as $emp) {
                      if ($emp['id'] == $s['emploi_id']) {
                          echo ' · ' . h($emp['filiere_code']);
                          if ($emp['niveau_nom']) echo ' ' . h($emp['niveau_nom']);
                          break;
                      }
                  }
                ?>
              </td>
              <td style="text-align:center">
                <button
                  class="btn btn-sm seance-toggle <?= $isDone ? 'btn-success' : 'btn-outline-secondary' ?>"
                  data-slot="<?= $s['slot_id'] ?>"
                  data-done="<?= $isDone ? '1' : '0' ?>"
                  data-mid="<?= $s['matiere_id'] ?>"
                  data-eid="<?= $s['ens_id'] ?>"
                  title="<?= $isDone ? 'Marquer comme non effectué' : 'Marquer comme effectué' ?>">
                  <i class="fas <?= $isDone ? 'fa-check-circle' : 'fa-circle' ?> me-1"></i>
                  <?= $isDone ? 'Effectué' : 'Planifié' ?>
                </button>
                <?php if ($isDone && $s['seance_at']): ?>
                <div class="fs-sm text-muted mt-1" style="font-size:.7rem">
                  <?= formatDate(substr($s['seance_at'],0,10)) ?>
                  <?php if ($s['marked_prenom']): ?>
                  · <?= h($s['marked_prenom'].' '.$s['marked_nom']) ?>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Tab 3 : Par enseignant ─────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-enseignants">
    <?php if (empty($teacherSummary)): ?>
      <div class="card"><div class="card-body text-center text-muted py-4">Aucun enseignant assigné dans les emplois du temps approuvés.</div></div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($teacherSummary as $eid => $t):
        $tPct = $t['heures_planifiees'] > 0
              ? round($t['heures_effectuees'] / $t['heures_planifiees'] * 100) : 0;
        $tColor = $tPct >= 100 ? 'success' : ($tPct >= 50 ? 'primary' : 'warning');

        // Build details JSON for modal
        $detailsArr = [];
        foreach ($t['matieres'] as $mmat) {
            $detailsArr[] = $mmat['code'] . ' – ' . $mmat['nom'] . ' : ' . $mmat['heures'] . 'h';
        }
        $detailsJson = implode("\n", $detailsArr);
      ?>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="fw-bold fs-5">
                  <i class="fas fa-user-tie me-2 text-primary"></i><?= h($t['nom']) ?>
                </div>
                <div class="text-muted fs-sm"><code><?= h($t['matricule']) ?></code></div>
              </div>
              <span class="badge bg-<?= $tColor ?> fs-sm"><?= $tPct ?>%</span>
            </div>

            <!-- Heures summary -->
            <div class="row g-2 mb-3">
              <div class="col-6">
                <div class="bg-light rounded p-2 text-center">
                  <div class="fw-bold text-success fs-5"><?= $t['heures_effectuees'] ?>h</div>
                  <div class="text-muted" style="font-size:.72rem">effectuées</div>
                </div>
              </div>
              <div class="col-6">
                <div class="bg-light rounded p-2 text-center">
                  <div class="fw-bold text-primary fs-5"><?= $t['heures_planifiees'] ?>h</div>
                  <div class="text-muted" style="font-size:.72rem">planifiées</div>
                </div>
              </div>
            </div>

            <!-- Progress bar -->
            <div class="progress mb-3" style="height:8px">
              <div class="progress-bar bg-<?= $tColor ?>" style="width:<?= $tPct ?>%"></div>
            </div>

            <!-- Matières breakdown -->
            <?php if (!empty($t['matieres'])): ?>
            <div class="mb-3">
              <div class="fs-sm fw-600 text-muted mb-1">Détail par matière :</div>
              <?php foreach ($t['matieres'] as $mmat): ?>
              <div class="d-flex justify-content-between fs-sm py-1 border-bottom">
                <span>
                  <code class="me-1"><?= h($mmat['code']) ?></code>
                  <?= h($mmat['nom']) ?>
                </span>
                <span class="fw-600 text-success"><?= $mmat['heures'] ?>h</span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Période -->
            <div class="fs-sm text-muted mb-3">
              <i class="fas fa-calendar-alt me-1"></i>
              Période : <?= formatDate($t['date_debut']) ?> → <?= formatDate($t['date_fin']) ?>
            </div>

          </div>
          <div class="card-footer py-2 bg-transparent">
            <?php if ($t['heures_effectuees'] > 0): ?>
            <button class="btn btn-success w-100 btn-envoyer-paiement"
              data-ens-id="<?= $eid ?>"
              data-ens-nom="<?= h($t['nom']) ?>"
              data-heures="<?= $t['heures_effectuees'] ?>"
              data-debut="<?= $t['date_debut'] ?>"
              data-fin="<?= $t['date_fin'] ?>"
              data-details="<?= h($detailsJson) ?>"
              data-annee="<?= $anneeId ?>">
              <i class="fas fa-paper-plane me-2"></i>Envoyer en paiement
            </button>
            <?php else: ?>
            <button class="btn btn-outline-secondary w-100" disabled>
              <i class="fas fa-paper-plane me-2"></i>Aucune séance effectuée
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /tab-content -->
<?php endif; ?>

<!-- ── Modal : Envoyer en paiement ──────────────────────────────────────── -->
<div class="modal fade" id="modalPaiement" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Envoyer en paiement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="demande_paiement">
        <input type="hidden" name="annee_id" value="<?= $anneeId ?>">

        <div class="modal-body">
          <div class="alert alert-info fs-sm mb-3">
            <i class="fas fa-info-circle me-2"></i>
            Cette demande sera envoyée à la comptabilité pour règlement du salaire de vacation.
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Enseignant</label>
            <input type="hidden" name="enseignant_id" id="modal_ens_id">
            <input type="text" class="form-control" id="modal_ens_nom" readonly>
          </div>

          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Date de début <span class="text-danger">*</span></label>
              <input type="date" name="date_debut" id="modal_date_debut" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Date de fin <span class="text-danger">*</span></label>
              <input type="date" name="date_fin" id="modal_date_fin" class="form-control" required>
            </div>
          </div>

          <div class="mb-3 mt-3">
            <label class="form-label">Total heures effectuées <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="number" name="total_heures" id="modal_heures" class="form-control"
                     min="1" step="0.5" required>
              <span class="input-group-text">heures</span>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Détail par matière</label>
            <textarea name="details_matieres" id="modal_details" class="form-control" rows="4"
                      placeholder="Détail des heures par matière..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Notes pour le comptable</label>
            <input type="text" name="notes_coordinateur" class="form-control"
                   placeholder="Instructions, précisions...">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-paper-plane me-2"></i>Envoyer à la comptabilité
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const MARK_URL = <?= json_encode(APP_URL . '/api/mark_seance.php') ?>;

// ── Toggle séance ──────────────────────────────────────────────────────────
document.querySelectorAll('.seance-toggle').forEach(btn => {
    btn.addEventListener('click', function () {
        const slotId = this.dataset.slot;
        const isDone = this.dataset.done === '1';
        const action = isDone ? 'unmark' : 'mark';
        const row    = document.getElementById('row-' + slotId);

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>';

        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('slot_id', slotId);
        fd.append('action', action);

        fetch(MARK_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Erreur.');
                    this.disabled = false;
                    this.innerHTML = isDone
                        ? '<i class="fas fa-check-circle me-1"></i>Effectué'
                        : '<i class="fas fa-circle me-1"></i>Planifié';
                    return;
                }
                const nowDone = data.done;
                this.dataset.done = nowDone ? '1' : '0';
                this.className = 'btn btn-sm seance-toggle ' + (nowDone ? 'btn-success' : 'btn-outline-secondary');
                this.innerHTML = nowDone
                    ? '<i class="fas fa-check-circle me-1"></i>Effectué'
                    : '<i class="fas fa-circle me-1"></i>Planifié';
                this.disabled = false;

                if (row) row.classList.toggle('table-success', nowDone);

                // Update progress bars
                updateProgressMatiere(this.dataset.mid, data.heures_matiere);
                updateProgressEnseignant(this.dataset.eid, data.heures_enseignant);
            })
            .catch(() => {
                alert('Erreur réseau.');
                this.disabled = false;
                this.innerHTML = isDone
                    ? '<i class="fas fa-check-circle me-1"></i>Effectué'
                    : '<i class="fas fa-circle me-1"></i>Planifié';
            });
    });
});

function updateProgressMatiere(mid, heures) {
    // Reload page to reflect updated progress bars (simpler than DOM update)
    // For real-time update we'd need volume_horaire available in JS
}

function updateProgressEnseignant(eid, heures) {
    const btn = document.querySelector('[data-ens-id="' + eid + '"]');
    if (btn) btn.dataset.heures = heures;
}

// ── Modal paiement ─────────────────────────────────────────────────────────
document.querySelectorAll('.btn-envoyer-paiement').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('modal_ens_id').value    = this.dataset.ensId;
        document.getElementById('modal_ens_nom').value   = this.dataset.ensNom;
        document.getElementById('modal_heures').value    = this.dataset.heures;
        document.getElementById('modal_date_debut').value = this.dataset.debut;
        document.getElementById('modal_date_fin').value   = this.dataset.fin;
        document.getElementById('modal_details').value   = this.dataset.details;
        new bootstrap.Modal(document.getElementById('modalPaiement')).show();
    });
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
