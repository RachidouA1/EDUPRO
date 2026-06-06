<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['coordinateur', 'admin', 'comptable']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$role    = $user['role'];

// Créer la table demandes_paiement_enseignant si elle n'existe pas encore
try {
    $db->exec("CREATE TABLE IF NOT EXISTS demandes_paiement_enseignant (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enseignant_id INT NOT NULL,
        coordinateur_id INT NOT NULL,
        annee_id INT NULL,
        semestre_id INT NULL,
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
        FOREIGN KEY (enseignant_id) REFERENCES enseignants(id),
        FOREIGN KEY (coordinateur_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

// Filtres actifs
$anneeId    = (int)($_GET['annee_id']    ?? ($anneeActive['id'] ?? 0));
$semestreId = (int)($_GET['semestre_id'] ?? 0);

// Liste des semestres pour l'année sélectionnée
$semestres = [];
if ($anneeId) {
    $qSem = $db->prepare("SELECT * FROM semestres WHERE annee_id = ? ORDER BY id");
    $qSem->execute([$anneeId]);
    $semestres = $qSem->fetchAll();
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
        redirect('/modules/administration/salaires_enseignants.php?' . http_build_query(['annee_id' => $anneeId, 'semestre_id' => $semestreId]));
    }

    $action = sanitize($_POST['action'] ?? '');

    // ── Sauvegarder/mettre à jour les heures d'une matière ──
    if ($action === 'save_heures' && in_array($role, ['coordinateur', 'admin'])) {
        $ensId    = (int)$_POST['enseignant_id'];
        $matId    = (int)$_POST['matiere_id'];
        $aId      = (int)$_POST['annee_id'];
        $sId      = (int)$_POST['semestre_id'];
        $hPrev    = max(0, (int)$_POST['heures_prevues']);
        $hEff     = max(0, (int)$_POST['heures_effectuees']);
        $taux     = max(0, (float)str_replace(',', '.', $_POST['taux_horaire'] ?? '0'));

        if ($ensId && $matId && $aId && $sId) {
            $existing = $db->prepare("SELECT id FROM heures_cours WHERE enseignant_id=? AND matiere_id=? AND annee_id=? AND semestre_id=?");
            $existing->execute([$ensId, $matId, $aId, $sId]);
            if ($existing->fetch()) {
                $db->prepare("UPDATE heures_cours SET heures_prevues=?, heures_effectuees=?, taux_horaire=?
                              WHERE enseignant_id=? AND matiere_id=? AND annee_id=? AND semestre_id=?")
                   ->execute([$hPrev, $hEff, $taux, $ensId, $matId, $aId, $sId]);
            } else {
                $db->prepare("INSERT INTO heures_cours (enseignant_id, matiere_id, annee_id, semestre_id, heures_prevues, heures_effectuees, taux_horaire)
                              VALUES (?,?,?,?,?,?,?)")
                   ->execute([$ensId, $matId, $aId, $sId, $hPrev, $hEff, $taux]);
            }
            setFlash('success', 'Heures enregistrées.');
        }
        redirect('/modules/administration/salaires_enseignants.php?' . http_build_query(['annee_id' => $aId, 'semestre_id' => $sId]));
    }

    // ── Créer une demande de paiement ──
    if ($action === 'create_demande' && in_array($role, ['coordinateur', 'admin'])) {
        $ensId    = (int)$_POST['enseignant_id'];
        $aId      = (int)$_POST['annee_id'] ?: null;
        $sId      = (int)$_POST['semestre_id'] ?: null;
        $debut    = sanitize($_POST['date_debut'] ?? '');
        $fin      = sanitize($_POST['date_fin']   ?? date('Y-m-d'));
        $total    = (float)str_replace(',', '.', $_POST['total_heures'] ?? '0');
        $details  = sanitize($_POST['details_matieres']  ?? '');
        $notes    = sanitize($_POST['notes_coordinateur'] ?? '');

        if (!$ensId || empty($debut) || $total <= 0) {
            setFlash('error', 'Données incomplètes pour créer la demande.');
        } else {
            $db->prepare("INSERT INTO demandes_paiement_enseignant
                (enseignant_id, coordinateur_id, annee_id, semestre_id, date_debut, date_fin,
                 total_heures, details_matieres, notes_coordinateur)
                VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$ensId, $user['id'], $aId, $sId, $debut, $fin, $total, $details, $notes]);
            setFlash('success', 'Demande de paiement créée et transmise à la comptabilité.');
        }
        redirect('/modules/administration/salaires_enseignants.php?' . http_build_query(['annee_id' => $aId ?? 0, 'semestre_id' => $sId ?? 0]));
    }
}

// ── Charger les données ────────────────────────────────────────────────────────
// Enseignants avec leurs matières et heures pour l'année/semestre sélectionnés
$whereEcole = $ecoleId > 0 ? "AND e.ecole_id = $ecoleId" : '';
$semFilter  = $semestreId ? "AND (hc.semestre_id = $semestreId OR hc.semestre_id IS NULL)" : '';

$qData = $db->prepare("
    SELECT
        e.id AS ens_id, e.nom, e.prenom, e.matricule, e.type_contrat,
        m.id AS mat_id, m.code AS mat_code, m.nom AS mat_nom,
        f.nom AS filiere_nom, n.nom AS niveau_nom,
        COALESCE(hc.id, 0)                  AS hc_id,
        COALESCE(hc.heures_prevues, 0)       AS heures_prevues,
        COALESCE(hc.heures_effectuees, 0)    AS heures_effectuees,
        COALESCE(hc.taux_horaire, 0)         AS taux_horaire,
        COALESCE(hc.semestre_id, 0)          AS hc_semestre_id
    FROM matieres m
    JOIN enseignants e ON e.id = m.enseignant_id
    LEFT JOIN filieres f ON f.id = m.filiere_id
    LEFT JOIN niveaux n ON n.id = m.niveau_id
    LEFT JOIN heures_cours hc ON hc.enseignant_id = e.id
        AND hc.matiere_id = m.id
        AND hc.annee_id = :anneeId
        " . ($semestreId ? "AND hc.semestre_id = :semId" : "") . "
    WHERE m.actif = 1 AND e.actif = 1
    $whereEcole
    ORDER BY e.nom, e.prenom, m.nom
");

$params = [':anneeId' => $anneeId ?: 0];
if ($semestreId) $params[':semId'] = $semestreId;
$qData->execute($params);
$rows = $qData->fetchAll();

// Grouper par enseignant
$enseignants = [];
foreach ($rows as $row) {
    $eid = $row['ens_id'];
    if (!isset($enseignants[$eid])) {
        $enseignants[$eid] = [
            'id'           => $eid,
            'nom'          => $row['nom'],
            'prenom'       => $row['prenom'],
            'matricule'    => $row['matricule'],
            'type_contrat' => $row['type_contrat'],
            'matieres'     => [],
        ];
    }
    $enseignants[$eid]['matieres'][] = [
        'mat_id'           => $row['mat_id'],
        'mat_code'         => $row['mat_code'],
        'mat_nom'          => $row['mat_nom'],
        'filiere_nom'      => $row['filiere_nom'],
        'niveau_nom'       => $row['niveau_nom'],
        'hc_id'            => $row['hc_id'],
        'heures_prevues'   => (int)$row['heures_prevues'],
        'heures_effectuees'=> (int)$row['heures_effectuees'],
        'taux_horaire'     => (float)$row['taux_horaire'],
        'hc_semestre_id'   => (int)$row['hc_semestre_id'],
        'montant'          => (int)$row['heures_effectuees'] * (float)$row['taux_horaire'],
    ];
}

// Statistiques globales
$totalEns    = count($enseignants);
$totalHeures = 0;
$totalMontant = 0;
foreach ($enseignants as $e) {
    foreach ($e['matieres'] as $m) {
        $totalHeures  += $m['heures_effectuees'];
        $totalMontant += $m['montant'];
    }
}

$csrfToken = generateCsrfToken();

$pageTitle  = 'Salaires Enseignants';
$breadcrumb = ['Administration' => null, 'Salaires Enseignants' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-money-bill-wave me-2 text-primary"></i>Calcul des Salaires — Enseignants</h2>
    <div class="text-muted fs-sm">Calculez les salaires en fonction des heures de cours effectuées</div>
  </div>
  <a href="<?= APP_URL ?>/modules/administration/demandes_paiement.php" class="btn btn-outline-primary">
    <i class="fas fa-file-invoice-dollar me-2"></i>Demandes de paiement
  </a>
</div>

<?php showFlash(); ?>

<!-- ── Filtres ───────────────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label form-label-sm">Année académique</label>
        <select name="annee_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0">— Toutes les années —</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $a['id'] == $anneeId ? 'selected' : '' ?>>
              <?= h($a['libelle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label form-label-sm">Semestre</label>
        <select name="semestre_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0">— Tous les semestres —</option>
          <?php foreach ($semestres as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $semestreId ? 'selected' : '' ?>>
              <?= h($s['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-auto">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="fas fa-filter me-1"></i>Filtrer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Statistiques ───────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalEns ?></div>
        <div class="stat-label">Enseignant(s) avec matières</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalHeures ?>h</div>
        <div class="stat-label">Total heures effectuées</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-coins"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.1rem"><?= formatMontant($totalMontant) ?></div>
        <div class="stat-label">Montant total calculé</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Liste des enseignants ──────────────────────────────────────────────────── -->
<?php if (empty($enseignants)): ?>
  <div class="card">
    <div class="card-body text-center py-5 text-muted">
      <i class="fas fa-user-tie d-block mb-3" style="font-size:3rem;opacity:.2"></i>
      <p class="mb-1">Aucun enseignant avec des matières assignées.</p>
      <small>
        Assignez des matières aux enseignants depuis
        <a href="<?= APP_URL ?>/modules/enseignants/index.php">la liste des enseignants</a>.
      </small>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($enseignants as $ens):
    $totalEnsHeures  = array_sum(array_column($ens['matieres'], 'heures_effectuees'));
    $totalEnsMontant = array_sum(array_column($ens['matieres'], 'montant'));
    $badgeCls = $ens['type_contrat'] === 'vacataire' ? 'warning text-dark' : 'primary';
    $collapseId = 'ens_' . $ens['id'];
?>
<div class="card mb-3">
  <!-- En-tête enseignant -->
  <div class="card-header d-flex align-items-center justify-content-between"
       style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
    <div class="d-flex align-items-center gap-3">
      <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0">
        <?= mb_strtoupper(mb_substr($ens['prenom'], 0, 1) . mb_substr($ens['nom'], 0, 1)) ?>
      </div>
      <div>
        <div class="fw-600"><?= h($ens['prenom'] . ' ' . $ens['nom']) ?></div>
        <small class="text-muted">
          <code><?= h($ens['matricule']) ?></code>
          <span class="badge bg-<?= $badgeCls ?> ms-2"><?= ucfirst($ens['type_contrat']) ?></span>
          <span class="ms-2"><?= count($ens['matieres']) ?> matière(s)</span>
        </small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="text-end">
        <div class="fw-600 text-primary"><?= $totalEnsHeures ?>h effectuées</div>
        <div class="text-success fw-600 fs-sm"><?= formatMontant($totalEnsMontant) ?></div>
      </div>
      <i class="fas fa-chevron-down text-muted"></i>
    </div>
  </div>

  <div class="collapse show" id="<?= $collapseId ?>">
    <!-- Tableau des matières -->
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Matière</th>
            <th>Section</th>
            <th class="text-center">H. Prévues</th>
            <th class="text-center">H. Effectuées</th>
            <th class="text-end">Taux/h (FCFA)</th>
            <th class="text-end">Montant</th>
            <?php if (in_array($role, ['coordinateur', 'admin'])): ?>
            <th style="width:80px"></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ens['matieres'] as $mat): ?>
          <tr>
            <td>
              <span class="badge bg-primary me-1"><?= h($mat['mat_code']) ?></span>
              <strong class="fs-sm"><?= h($mat['mat_nom']) ?></strong>
            </td>
            <td class="text-muted fs-sm">
              <?= h(implode(' · ', array_filter([$mat['filiere_nom'], $mat['niveau_nom']]))) ?>
            </td>
            <td class="text-center fs-sm"><?= $mat['heures_prevues'] ?>h</td>
            <td class="text-center">
              <?php if ($mat['heures_effectuees'] > 0): ?>
                <span class="badge bg-success"><?= $mat['heures_effectuees'] ?>h</span>
              <?php else: ?>
                <span class="text-muted fs-sm">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end fs-sm">
              <?= $mat['taux_horaire'] > 0 ? number_format($mat['taux_horaire'], 0, ',', ' ') . ' FCFA' : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-end fw-600 <?= $mat['montant'] > 0 ? 'text-success' : 'text-muted' ?>">
              <?= $mat['montant'] > 0 ? formatMontant($mat['montant']) : '—' ?>
            </td>
            <?php if (in_array($role, ['coordinateur', 'admin'])): ?>
            <td>
              <button type="button" class="btn btn-xs btn-outline-primary"
                      title="Modifier les heures"
                      onclick="openHeuresModal(<?= json_encode([
                          'ens_id'      => $ens['id'],
                          'ens_nom'     => $ens['prenom'] . ' ' . $ens['nom'],
                          'mat_id'      => $mat['mat_id'],
                          'mat_nom'     => $mat['mat_nom'],
                          'annee_id'    => $anneeId,
                          'semestre_id' => $semestreId ?: ($mat['hc_semestre_id'] ?: 0),
                          'h_prev'      => $mat['heures_prevues'],
                          'h_eff'       => $mat['heures_effectuees'],
                          'taux'        => $mat['taux_horaire'],
                      ]) ?>)">
                <i class="fas fa-edit"></i>
              </button>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-600">
          <tr>
            <td colspan="3" class="text-end">Totaux</td>
            <td class="text-center">
              <span class="badge bg-primary"><?= $totalEnsHeures ?>h</span>
            </td>
            <td></td>
            <td class="text-end text-success"><?= formatMontant($totalEnsMontant) ?></td>
            <?php if (in_array($role, ['coordinateur', 'admin'])): ?><td></td><?php endif; ?>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Actions -->
    <?php if (in_array($role, ['coordinateur', 'admin'])): ?>
    <div class="card-footer d-flex justify-content-end gap-2 py-2">
      <button type="button" class="btn btn-sm btn-success"
              <?= $totalEnsHeures <= 0 ? 'disabled title="Aucune heure effectuée"' : '' ?>
              onclick="openDemandeModal(<?= json_encode([
                  'ens_id'      => $ens['id'],
                  'ens_nom'     => $ens['prenom'] . ' ' . $ens['nom'],
                  'annee_id'    => $anneeId,
                  'semestre_id' => $semestreId,
                  'total'       => $totalEnsHeures,
                  'montant'     => $totalEnsMontant,
                  'details'     => implode("\n", array_filter(array_map(fn($m) =>
                      $m['heures_effectuees'] > 0
                          ? $m['mat_nom'] . ' : ' . $m['heures_effectuees'] . 'h × ' . number_format($m['taux_horaire'], 0, ',', ' ') . ' FCFA = ' . number_format($m['montant'], 0, ',', ' ') . ' FCFA'
                          : null,
                      $ens['matieres']
                  ))),
              ]) ?>)">
        <i class="fas fa-paper-plane me-1"></i>Créer demande de paiement
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>


<!-- ════════════════════════════════════════════════════════════════ -->
<!-- Modal : Modifier les heures d'une matière                       -->
<!-- ════════════════════════════════════════════════════════════════ -->
<?php if (in_array($role, ['coordinateur', 'admin'])): ?>
<div class="modal fade" id="modalHeures" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-clock me-2 text-primary"></i>Heures de cours</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf"           value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action"         value="save_heures">
        <input type="hidden" name="enseignant_id"  id="hEnsId">
        <input type="hidden" name="matiere_id"     id="hMatId">
        <input type="hidden" name="annee_id"       id="hAnneeId">
        <div class="modal-body">
          <div class="bg-light rounded p-3 mb-3 fs-sm">
            <strong id="hEnsNom"></strong>
            <div class="text-muted" id="hMatNom"></div>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Semestre <span class="text-danger">*</span></label>
              <select name="semestre_id" id="hSemId" class="form-select" required>
                <option value="">— Sélectionner —</option>
                <?php foreach ($semestres as $s): ?>
                  <option value="<?= $s['id'] ?>">
                    <?= h($s['nom']) ?>
                  </option>
                <?php endforeach; ?>
                <?php if (empty($semestres)): ?>
                  <option value="" disabled>Sélectionnez une année d'abord</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Heures prévues</label>
              <div class="input-group">
                <input type="number" name="heures_prevues" id="hPrev"
                       class="form-control" min="0" step="1" value="0">
                <span class="input-group-text">h</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Heures effectuées</label>
              <div class="input-group">
                <input type="number" name="heures_effectuees" id="hEff"
                       class="form-control" min="0" step="1" value="0"
                       oninput="updateCalc()">
                <span class="input-group-text">h</span>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Taux horaire (FCFA/h)</label>
              <div class="input-group">
                <input type="number" name="taux_horaire" id="hTaux"
                       class="form-control" min="0" step="100" value="0"
                       oninput="updateCalc()">
                <span class="input-group-text">FCFA/h</span>
              </div>
            </div>
            <div class="col-12">
              <div class="alert alert-success py-2 mb-0 d-flex justify-content-between">
                <span><i class="fas fa-calculator me-1"></i>Montant calculé :</span>
                <strong id="hCalc">0 FCFA</strong>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>Enregistrer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════════════════ -->
<!-- Modal : Créer une demande de paiement                           -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDemande" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Créer une demande de paiement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf"          value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action"        value="create_demande">
        <input type="hidden" name="enseignant_id" id="dEnsId">
        <input type="hidden" name="annee_id"      id="dAnneeId">
        <input type="hidden" name="semestre_id"   id="dSemId">
        <input type="hidden" name="total_heures"  id="dTotalH">
        <div class="modal-body">
          <div class="bg-light rounded p-3 mb-3">
            <div class="row g-2">
              <div class="col-md-6">
                <div class="text-muted fs-sm">Enseignant</div>
                <div class="fw-bold" id="dEnsNom"></div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-sm">Heures effectuées</div>
                <div class="fw-bold text-primary" id="dTotalHDisplay"></div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-sm">Montant calculé</div>
                <div class="fw-bold text-success" id="dMontantCalc"></div>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fs-sm text-muted">Détail par matière</label>
            <pre id="dDetails" class="bg-white border rounded p-2 fs-sm mb-0"
                 style="white-space:pre-wrap;max-height:120px;overflow:auto"></pre>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Date de début de la période <span class="text-danger">*</span></label>
              <input type="date" name="date_debut" id="dDebut" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date de fin de la période</label>
              <input type="date" name="date_fin" id="dFin" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Notes pour la comptabilité</label>
              <textarea name="notes_coordinateur" class="form-control" rows="2"
                        placeholder="Observations, période concernée..."></textarea>
            </div>
            <div class="col-12">
              <input type="hidden" name="details_matieres" id="dDetailsMatieres">
            </div>
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
function openHeuresModal(d) {
    document.getElementById('hEnsId').value   = d.ens_id;
    document.getElementById('hMatId').value   = d.mat_id;
    document.getElementById('hAnneeId').value = d.annee_id;
    document.getElementById('hEnsNom').textContent = d.ens_nom;
    document.getElementById('hMatNom').textContent = d.mat_nom;
    document.getElementById('hPrev').value  = d.h_prev;
    document.getElementById('hEff').value   = d.h_eff;
    document.getElementById('hTaux').value  = d.taux;
    // Pré-sélectionner le semestre
    const semSel = document.getElementById('hSemId');
    if (d.semestre_id) {
        semSel.value = d.semestre_id;
    }
    updateCalc();
    new bootstrap.Modal(document.getElementById('modalHeures')).show();
}

function updateCalc() {
    const h = parseFloat(document.getElementById('hEff').value)  || 0;
    const t = parseFloat(document.getElementById('hTaux').value) || 0;
    const total = h * t;
    document.getElementById('hCalc').textContent =
        new Intl.NumberFormat('fr-FR').format(total) + ' FCFA';
}

function openDemandeModal(d) {
    document.getElementById('dEnsId').value          = d.ens_id;
    document.getElementById('dAnneeId').value        = d.annee_id;
    document.getElementById('dSemId').value          = d.semestre_id;
    document.getElementById('dTotalH').value         = d.total;
    document.getElementById('dEnsNom').textContent   = d.ens_nom;
    document.getElementById('dTotalHDisplay').textContent = d.total + 'h';
    document.getElementById('dMontantCalc').textContent =
        new Intl.NumberFormat('fr-FR').format(d.montant) + ' FCFA';
    document.getElementById('dDetails').textContent  = d.details;
    document.getElementById('dDetailsMatieres').value = d.details;
    // Dates par défaut : début = 1er du mois, fin = aujourd'hui
    const now = new Date();
    document.getElementById('dFin').value   = now.toISOString().slice(0, 10);
    document.getElementById('dDebut').value = now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-01';
    new bootstrap.Modal(document.getElementById('modalDemande')).show();
}
</script>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
