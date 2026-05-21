<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'scolarite', 'coordinateur']);

$db = getDB();

// ── Données statiques pour les formulaires ───────────────────────────────────

// Filières ayant des UE définies
$pvQuery = "
    SELECT DISTINCT f.id, f.nom, f.code
    FROM filieres f
    JOIN ue u ON u.filiere_id = f.id AND u.actif = 1
    WHERE f.actif = 1";
$pvParams = [];
if (hasRole('coordinateur')) {
    $pvQuery  .= " AND f.id = ?";
    $pvParams[] = getCoordinateurFiliereId();
}
$pvQuery .= " ORDER BY f.nom";
$pvStmt = $db->prepare($pvQuery);
$pvStmt->execute($pvParams);
$filieres_avec_ue = $pvStmt->fetchAll();

$annees = getAnneesAcademiques();

// ── Calcul PV individuel ─────────────────────────────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'pv_individuel' && isset($_GET['etudiant_id'])) {
    $etudiant_id  = (int)$_GET['etudiant_id'];
    $semestre_num = max(1, min(6, (int)($_GET['semestre_num'] ?? 1)));
    $session_num  = in_array((int)($_GET['session_num'] ?? 1), [1, 2]) ? (int)$_GET['session_num'] : 1;
    $annee_id     = (int)($_GET['annee_id'] ?? 0);
    $session_type = $session_num === 1 ? 'premiere' : 'deuxieme';

    $stmt = $db->prepare("
        SELECT e.*, f.nom as filiere_nom, f.code as filiere_code
        FROM etudiants e
        JOIN filieres f ON f.id = e.filiere_id
        WHERE e.id = ?
    ");
    $stmt->execute([$etudiant_id]);
    $etudiant = $stmt->fetch();

    // Année académique label
    $annee_label = '';
    foreach ($annees as $a) {
        if ($a['id'] == $annee_id) { $annee_label = $a['libelle']; break; }
    }

    if ($etudiant) {
        // UE du semestre pour cette filière
        $stmt = $db->prepare("
            SELECT * FROM ue WHERE filiere_id = ? AND semestre_num = ? AND actif = 1
            ORDER BY id
        ");
        $stmt->execute([$etudiant['filiere_id'], $semestre_num]);
        $ues = $stmt->fetchAll();

        $ues_data = [];
        $total_points_ue = 0;
        $total_coeff_ue  = 0;
        $toutes_notes_saisies = true;

        foreach ($ues as $ue) {
            $stmt = $db->prepare("
                SELECT * FROM matieres WHERE ue_id = ? AND actif = 1 ORDER BY nom
            ");
            $stmt->execute([$ue['id']]);
            $matieres = $stmt->fetchAll();

            $matieres_data       = [];
            $total_pts_mat       = 0;
            $total_coeff_mat     = 0;

            foreach ($matieres as $mat) {
                $stmt = $db->prepare("
                    SELECT note_finale FROM notes
                    WHERE etudiant_id = ? AND matiere_id = ? AND annee_id = ? AND session = ?
                    LIMIT 1
                ");
                $stmt->execute([$etudiant_id, $mat['id'], $annee_id, $session_num]);
                $note_val = $stmt->fetchColumn();
                $note_val = ($note_val !== false) ? floatval($note_val) : null;

                if ($note_val === null) $toutes_notes_saisies = false;

                $seuil        = (int)($mat['seuil_reussite'] ?? 12);
                $mat_valide   = ($note_val !== null && $note_val >= $seuil);

                $matieres_data[] = [
                    'nom'         => $mat['nom'],
                    'code'        => $mat['code'],
                    'coefficient' => $mat['coefficient'],
                    'seuil'       => $seuil,
                    'note'        => $note_val,
                    'valide'      => $mat_valide,
                ];

                if ($note_val !== null) {
                    $total_pts_mat   += $note_val * floatval($mat['coefficient']);
                    $total_coeff_mat += floatval($mat['coefficient']);
                }
            }

            $moyenne_ue = $total_coeff_mat > 0 ? $total_pts_mat / $total_coeff_mat : 0;
            $ue_valide  = $moyenne_ue >= 10;

            $ues_data[] = [
                'id'          => $ue['id'],
                'nom'         => $ue['nom'],
                'code_ue'     => $ue['code_ue'],
                'coefficient' => $ue['coefficient'],
                'credit'      => $ue['credit'],
                'matieres'    => $matieres_data,
                'moyenne'     => $moyenne_ue,
                'valide'      => $ue_valide,
            ];

            if ($moyenne_ue > 0) {
                $total_points_ue += $moyenne_ue * floatval($ue['coefficient']);
                $total_coeff_ue  += floatval($ue['coefficient']);
            }
        }

        $moyenne_generale = $total_coeff_ue > 0 ? $total_points_ue / $total_coeff_ue : 0;
        $ue_validees      = count(array_filter($ues_data, fn($u) => $u['valide']));
        $ue_non_validees  = count($ues_data) - $ue_validees;

        if (!$toutes_notes_saisies) {
            $decision = 'EN ATTENTE – Notes non saisies';
            $decision_class = 'attente';
        } elseif ($ue_non_validees === 0) {
            $decision = $session_num === 1 ? 'VALIDÉ – Toutes les UE sont validées' : 'VALIDÉ (2ème session)';
            $decision_class = 'valide';
        } elseif ($session_num === 1) {
            $decision = 'REPRISE 2ÈME SESSION – ' . $ue_non_validees . ' UE(s) à repasser';
            $decision_class = 'reprise';
        } else {
            $decision = 'NON VALIDÉ – ' . $ue_non_validees . ' UE(s) non validée(s)';
            $decision_class = 'non-valide';
        }

        include __DIR__ . '/pv_individuel_view.php';
        exit();
    }
}

// ── Calcul PV global ─────────────────────────────────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'pv_global' && isset($_GET['filiere_id'])) {
    $filiere_id   = (int)$_GET['filiere_id'];
    $semestre_num = max(1, min(6, (int)($_GET['semestre_num'] ?? 1)));
    $session_num  = in_array((int)($_GET['session_num'] ?? 1), [1, 2]) ? (int)$_GET['session_num'] : 1;
    $annee_id     = (int)($_GET['annee_id'] ?? 0);
    $session_type = $session_num === 1 ? 'premiere' : 'deuxieme';
    $annee_etude  = (int)ceil($semestre_num / 2);

    // Infos filière
    $stmt = $db->prepare("SELECT * FROM filieres WHERE id = ?");
    $stmt->execute([$filiere_id]);
    $filiere = $stmt->fetch();

    // Année label
    $annee_label = '';
    foreach ($annees as $a) {
        if ($a['id'] == $annee_id) { $annee_label = $a['libelle']; break; }
    }

    if ($filiere) {
        // Étudiants de la filière, à l'année d'étude correspondante
        $stmt = $db->prepare("
            SELECT e.*, n.ordre as annee_etude
            FROM etudiants e
            JOIN niveaux n ON n.id = e.niveau_id
            WHERE e.filiere_id = ? AND n.ordre = ? AND e.statut = 'actif'
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([$filiere_id, $annee_etude]);
        $etudiants = $stmt->fetchAll();

        // UE du semestre
        $stmt = $db->prepare("
            SELECT * FROM ue WHERE filiere_id = ? AND semestre_num = ? AND actif = 1
            ORDER BY id
        ");
        $stmt->execute([$filiere_id, $semestre_num]);
        $ues = $stmt->fetchAll();

        // Matières par UE
        $matieres_by_ue = [];
        foreach ($ues as $ue) {
            $stmt = $db->prepare("SELECT * FROM matieres WHERE ue_id = ? AND actif = 1 ORDER BY nom");
            $stmt->execute([$ue['id']]);
            $matieres_by_ue[$ue['id']] = $stmt->fetchAll();
        }

        // Notes par étudiant
        $notes_data = [];
        foreach ($etudiants as $e) {
            $notes_data[$e['id']] = [];
            foreach ($ues as $ue) {
                $notes_data[$e['id']][$ue['id']] = [
                    'matieres'  => [],
                    'moyenne_ue'=> 0,
                    'valide'    => false,
                ];
                $total_pts  = 0;
                $total_coef = 0;

                foreach ($matieres_by_ue[$ue['id']] as $mat) {
                    $stmt = $db->prepare("
                        SELECT note_finale FROM notes
                        WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND session=?
                        LIMIT 1
                    ");
                    $stmt->execute([$e['id'], $mat['id'], $annee_id, $session_num]);
                    $note_val = $stmt->fetchColumn();
                    $note_val = ($note_val !== false) ? floatval($note_val) : null;

                    $seuil    = (int)($mat['seuil_reussite'] ?? 12);
                    $notes_data[$e['id']][$ue['id']]['matieres'][] = [
                        'nom'    => $mat['nom'],
                        'coeff'  => $mat['coefficient'],
                        'note'   => $note_val,
                        'seuil'  => $seuil,
                        'valide' => ($note_val !== null && $note_val >= $seuil),
                    ];

                    if ($note_val !== null) {
                        $total_pts  += $note_val * floatval($mat['coefficient']);
                        $total_coef += floatval($mat['coefficient']);
                    }
                }

                $moy_ue = $total_coef > 0 ? $total_pts / $total_coef : 0;
                $notes_data[$e['id']][$ue['id']]['moyenne_ue'] = $moy_ue;
                $notes_data[$e['id']][$ue['id']]['valide']     = $moy_ue >= 10;
            }

            // Moyenne générale + décision
            $total_pts_ue  = 0;
            $total_coef_ue = 0;
            $ue_non_val    = 0;
            foreach ($ues as $ue) {
                $m = $notes_data[$e['id']][$ue['id']]['moyenne_ue'];
                $total_pts_ue  += $m * floatval($ue['coefficient']);
                $total_coef_ue += floatval($ue['coefficient']);
                if (!$notes_data[$e['id']][$ue['id']]['valide']) $ue_non_val++;
            }
            $notes_data[$e['id']]['moyenne_generale'] = $total_coef_ue > 0 ? $total_pts_ue / $total_coef_ue : 0;
            if ($session_num === 1) {
                $notes_data[$e['id']]['decision'] = $ue_non_val === 0 ? 'VALIDÉ' : 'REPRISE 2ÈME SESSION';
            } else {
                $notes_data[$e['id']]['decision'] = $ue_non_val === 0 ? 'VALIDÉ' : 'NON VALIDÉ';
            }
        }

        include __DIR__ . '/pv_global_view.php';
        exit();
    }
}

// ── Récupération des étudiants supérieurs (pour le sélecteur individuel) ─────
$etudiants_sup = [];
if (!empty($filieres_avec_ue)) {
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $filieres_avec_ue));
    $etudiants_sup = $db->query("
        SELECT e.id, e.matricule, e.nom, e.prenom, f.nom as filiere_nom
        FROM etudiants e
        JOIN filieres f ON f.id = e.filiere_id
        WHERE e.filiere_id IN ($ids) AND e.statut = 'actif'
        ORDER BY e.nom, e.prenom
    ")->fetchAll();
}

$activeAnnee = getActiveAnnee();
$pageTitle   = 'PV – Niveau Supérieur';
$breadcrumb  = ['Pédagogie' => null, 'PV Supérieur' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-file-alt me-2 text-danger"></i>Procès Verbal – Niveau Supérieur</h2>
</div>

<?php if (empty($filieres_avec_ue)): ?>
<div class="alert alert-warning">
  <i class="fas fa-exclamation-triangle me-2"></i>
  Aucune filière avec des UE définies. <a href="ue.php" class="alert-link">Créez d'abord des UE</a> puis assignez-les aux matières.
</div>
<?php else: ?>

<div class="row g-4">

  <!-- PV Individuel -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header" style="background:linear-gradient(135deg,#dc3545,#fd7e14);color:#fff">
        <h5 class="mb-0"><i class="fas fa-user me-2"></i>PV Individuel</h5>
      </div>
      <div class="card-body">
        <form method="GET" action="" target="_blank">
          <input type="hidden" name="action" value="pv_individuel">

          <div class="mb-3">
            <label class="form-label fw-600">Étudiant</label>
            <select name="etudiant_id" class="form-select" required>
              <option value="">-- Sélectionner un étudiant --</option>
              <?php foreach ($etudiants_sup as $e): ?>
                <option value="<?= $e['id'] ?>">
                  <?= h($e['matricule']) ?> – <?= h($e['nom']) ?> <?= h($e['prenom']) ?>
                  (<?= h($e['filiere_nom']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Semestre du programme</label>
            <select name="semestre_num" class="form-select" required>
              <?php for ($s = 1; $s <= 6; $s++): ?>
                <option value="<?= $s ?>">Semestre <?= $s ?> (Année <?= ceil($s/2) ?>)</option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Session</label>
            <select name="session_num" class="form-select" required>
              <option value="1">1ère Session</option>
              <option value="2">2ème Session (Rattrapage)</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Année académique</label>
            <select name="annee_id" class="form-select">
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($activeAnnee && $a['id'] == $activeAnnee['id']) ? 'selected' : '' ?>>
                  <?= h($a['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-danger w-100">
            <i class="fas fa-file-alt me-2"></i>Générer le PV Individuel
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- PV Global -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header" style="background:linear-gradient(135deg,#6f42c1,#e83e8c);color:#fff">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>PV Global</h5>
      </div>
      <div class="card-body">
        <form method="GET" action="" target="_blank">
          <input type="hidden" name="action" value="pv_global">

          <div class="mb-3">
            <label class="form-label fw-600">Filière</label>
            <select name="filiere_id" class="form-select" required>
              <option value="">-- Sélectionner une filière --</option>
              <?php foreach ($filieres_avec_ue as $f): ?>
                <option value="<?= $f['id'] ?>"><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Semestre du programme</label>
            <select name="semestre_num" class="form-select" required>
              <?php for ($s = 1; $s <= 6; $s++): ?>
                <option value="<?= $s ?>">Semestre <?= $s ?> (Année <?= ceil($s/2) ?>)</option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Session</label>
            <select name="session_num" class="form-select" required>
              <option value="1">1ère Session</option>
              <option value="2">2ème Session (Rattrapage)</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600">Année académique</label>
            <select name="annee_id" class="form-select">
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($activeAnnee && $a['id'] == $activeAnnee['id']) ? 'selected' : '' ?>>
                  <?= h($a['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-purple w-100" style="background:linear-gradient(135deg,#6f42c1,#e83e8c);color:#fff;border:none">
            <i class="fas fa-table me-2"></i>Générer le PV Global
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<div class="alert alert-info mt-4">
  <i class="fas fa-info-circle me-2"></i>
  <strong>Règles de validation :</strong>
  <ul class="mb-0 mt-2">
    <li>Chaque matière est validée si sa note &ge; son seuil (défaut <strong>12/20</strong>)</li>
    <li>Chaque UE est validée si sa moyenne pondérée &ge; <strong>10/20</strong></li>
    <li>Le semestre est validé si <strong>toutes les UE</strong> sont validées</li>
  </ul>
</div>

<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
