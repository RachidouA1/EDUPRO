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

        // ── Session 2 : vérifier si l'étudiant a validé toutes ses UE en session 1 ──
        $validatedS1 = false;
        if ($session_num === 2 && !empty($ues)) {
            $ueIds  = implode(',', array_map(fn($u) => (int)$u['id'], $ues));
            $matsS1 = $db->query("SELECT id, coefficient, ue_id FROM matieres WHERE ue_id IN ($ueIds) AND actif=1")->fetchAll();
            if (!empty($matsS1)) {
                $matIdsStr = implode(',', array_map(fn($m) => (int)$m['id'], $matsS1));
                $s1Rows    = $db->query("SELECT matiere_id, note_finale FROM notes
                    WHERE etudiant_id = $etudiant_id AND matiere_id IN ($matIdsStr)
                      AND annee_id = $annee_id AND session = 1")->fetchAll();
                $s1Notes = [];
                foreach ($s1Rows as $r) $s1Notes[(int)$r['matiere_id']] = (float)$r['note_finale'];
                $allUesValS1 = true;
                foreach ($ues as $ue) {
                    $pts = 0; $coef = 0;
                    foreach ($matsS1 as $m) {
                        if ((int)$m['ue_id'] !== (int)$ue['id']) continue;
                        $nf = $s1Notes[(int)$m['id']] ?? null;
                        if ($nf !== null) { $pts += $nf * (float)$m['coefficient']; $coef += (float)$m['coefficient']; }
                    }
                    $moy = $coef > 0 ? $pts / $coef : 0;
                    if ($moy < 10) { $allUesValS1 = false; break; }
                }
                $validatedS1 = $allUesValS1;
            }
        }

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

                $seuil        = (int)($mat['seuil_reussite'] ?? 10);
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

        // Liste des étudiants pour le panneau "Changer d'étudiant" (filtrés par année académique)
        $etudiants_switch = [];
        if (!empty($filieres_avec_ue) && $annee_id) {
            $ids_f = implode(',', array_map(fn($f) => (int)$f['id'], $filieres_avec_ue));
            $sw_stmt = $db->prepare("
                SELECT e.id, e.matricule, e.nom, e.prenom, f.nom as filiere_nom, f.code as filiere_code
                FROM etudiants e
                JOIN filieres f ON f.id = e.filiere_id
                WHERE e.filiere_id IN ($ids_f) AND e.statut = 'actif' AND e.annee_id = ?
                ORDER BY e.nom, e.prenom
            ");
            $sw_stmt->execute([$annee_id]);
            $etudiants_switch = $sw_stmt->fetchAll();
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
        // Étudiants de la filière, à l'année d'étude correspondante, inscrits pour l'année académique sélectionnée
        $stmt = $db->prepare("
            SELECT e.*, n.ordre as annee_etude
            FROM etudiants e
            JOIN niveaux n ON n.id = e.niveau_id
            WHERE e.filiere_id = ? AND n.ordre = ? AND e.statut = 'actif' AND e.annee_id = ?
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([$filiere_id, $annee_etude, $annee_id]);
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

        // ── Session 2 : exclure les étudiants ayant validé toutes leurs UE en session 1 ──
        $nbValidesS1 = 0;
        if ($session_num === 2 && !empty($etudiants) && !empty($ues)) {
            $allMatIds = [];
            foreach ($ues as $ue) {
                foreach ($matieres_by_ue[$ue['id']] ?? [] as $mat) $allMatIds[] = (int)$mat['id'];
            }
            if (!empty($allMatIds)) {
                $etuIds = implode(',', array_map(fn($e) => (int)$e['id'], $etudiants));
                $matIds = implode(',', $allMatIds);
                $s1Rows = $db->query("SELECT etudiant_id, matiere_id, note_finale FROM notes
                    WHERE etudiant_id IN ($etuIds) AND matiere_id IN ($matIds)
                      AND annee_id = $annee_id AND session = 1")->fetchAll();
                $s1Notes = [];
                foreach ($s1Rows as $r) {
                    $s1Notes[(int)$r['etudiant_id']][(int)$r['matiere_id']] = (float)$r['note_finale'];
                }
                $validesS1Ids = [];
                foreach ($etudiants as $e) {
                    $eId = (int)$e['id'];
                    $allUesVal = true;
                    foreach ($ues as $ue) {
                        $pts = 0; $coef = 0;
                        foreach ($matieres_by_ue[$ue['id']] ?? [] as $mat) {
                            $nf = $s1Notes[$eId][(int)$mat['id']] ?? null;
                            if ($nf !== null) { $pts += $nf * (float)$mat['coefficient']; $coef += (float)$mat['coefficient']; }
                        }
                        $moy_ue = $coef > 0 ? $pts / $coef : 0;
                        if ($moy_ue < 10) { $allUesVal = false; break; }
                    }
                    if ($allUesVal) { $validesS1Ids[] = $eId; $nbValidesS1++; }
                }
                $etudiants = array_values(array_filter($etudiants, fn($e) => !in_array((int)$e['id'], $validesS1Ids)));
            }
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

                // Crédits de l'UE
                $cr_req = 0; $cr_acq = 0;
                foreach ($notes_data[$e['id']][$ue['id']]['matieres'] as $md) {
                    $cr_req += floatval($md['coeff']);
                    if ($md['valide']) $cr_acq += floatval($md['coeff']);
                }
                $notes_data[$e['id']][$ue['id']]['credit_requis'] = $cr_req;
                $notes_data[$e['id']][$ue['id']]['credit_acquis'] = $cr_acq;
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

            // Crédits globaux du semestre
            $cr_acq_g = 0; $cr_req_g = 0;
            foreach ($ues as $ue) {
                $cr_acq_g += $notes_data[$e['id']][$ue['id']]['credit_acquis'] ?? 0;
                $cr_req_g += $notes_data[$e['id']][$ue['id']]['credit_requis'] ?? 0;
            }
            $notes_data[$e['id']]['credit_acquis_global'] = $cr_acq_g;
            $notes_data[$e['id']]['credit_requis_global'] = $cr_req_g;

            // Crédits acquis < 30 → semestre non validé même si toutes les UE le sont
            if ($cr_acq_g < 30 && $notes_data[$e['id']]['decision'] === 'VALIDÉ') {
                $notes_data[$e['id']]['decision'] = $session_num === 1 ? 'REPRISE 2ÈME SESSION' : 'NON VALIDÉ';
            }
        }

        include __DIR__ . '/pv_global_view.php';
        exit();
    }
}

$activeAnnee = getActiveAnnee();

// ── Récupération des étudiants supérieurs (pour le sélecteur individuel) ─────
// Filtrés par l'année académique active afin de n'afficher que les étudiants de l'année en cours
$etudiants_sup = [];
if (!empty($filieres_avec_ue)) {
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $filieres_avec_ue));
    $sup_where  = "e.filiere_id IN ($ids) AND e.statut = 'actif'";
    $sup_params = [];
    if ($activeAnnee) {
        $sup_where  .= ' AND e.annee_id = ?';
        $sup_params[] = $activeAnnee['id'];
    }
    $sup_stmt = $db->prepare("
        SELECT e.id, e.matricule, e.nom, e.prenom, f.nom as filiere_nom
        FROM etudiants e
        JOIN filieres f ON f.id = e.filiere_id
        WHERE $sup_where
        ORDER BY e.nom, e.prenom
    ");
    $sup_stmt->execute($sup_params);
    $etudiants_sup = $sup_stmt->fetchAll();
}
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
            <div class="position-relative" id="pvStudWrap">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                  <i class="fas fa-search text-muted" style="font-size:.85rem"></i>
                </span>
                <input type="text" id="pvStudDisplay" class="form-control border-start-0 border-end-0"
                       placeholder="Rechercher par matricule, nom, prénom…"
                       autocomplete="off" style="cursor:text" required>
                <button type="button" id="pvStudClear" class="btn btn-outline-secondary border-start-0"
                        style="display:none" title="Effacer" onclick="pvClearStudent()">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <input type="hidden" name="etudiant_id" id="pvStudHidden" value="" required>
              <div id="pvStudPanel" class="card shadow position-absolute w-100"
                   style="display:none;z-index:1100;top:calc(100% + 4px);max-height:260px;overflow-y:auto">
                <div class="p-2 border-bottom sticky-top bg-white">
                  <small class="text-muted" id="pvStudCount"></small>
                </div>
                <div id="pvStudList">
                  <?php foreach ($etudiants_sup as $e): ?>
                  <div class="pv-stud-opt d-flex align-items-center gap-2 px-3 py-2"
                       data-id="<?= $e['id'] ?>"
                       data-label="<?= h($e['matricule'].' – '.$e['nom'].' '.$e['prenom']) ?>"
                       data-search="<?= strtolower(h($e['matricule'].' '.$e['nom'].' '.$e['prenom'].' '.$e['filiere_nom'])) ?>"
                       style="cursor:pointer;border-bottom:1px solid #f5f5f5">
                    <div style="background:#dc3545;color:#fff;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;font-weight:600">
                      <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                    </div>
                    <div style="min-width:0">
                      <div class="fw-600 fs-sm"><?= h($e['nom'].' '.$e['prenom']) ?></div>
                      <div style="font-size:.73rem;color:#888">
                        <code><?= h($e['matricule']) ?></code>
                        &nbsp;·&nbsp;<?= h($e['filiere_code'] ?? $e['filiere_nom']) ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                  <div id="pvStudEmpty" class="text-center text-muted py-3 d-none" style="font-size:.85rem">
                    <i class="fas fa-search-minus d-block mb-1"></i>Aucun résultat
                  </div>
                </div>
              </div>
            </div>
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

<script>
(function () {
  const wrap    = document.getElementById('pvStudWrap');
  if (!wrap) return;
  const display = document.getElementById('pvStudDisplay');
  const hidden  = document.getElementById('pvStudHidden');
  const panel   = document.getElementById('pvStudPanel');
  const list    = document.getElementById('pvStudList');
  const empty   = document.getElementById('pvStudEmpty');
  const count   = document.getElementById('pvStudCount');
  const clear   = document.getElementById('pvStudClear');
  const opts    = list.querySelectorAll('.pv-stud-opt');
  const total   = opts.length;

  function updateCount(vis) {
    count.textContent = vis + ' / ' + total + ' apprenant(s)';
  }

  function open() {
    panel.style.display = '';
    filterOpts(display.value.trim());
    display.focus();
  }
  function close() { panel.style.display = 'none'; }

  function filterOpts(q) {
    const lq = q.toLowerCase();
    let vis = 0;
    opts.forEach(o => {
      const match = !lq || o.dataset.search.includes(lq);
      o.style.display = match ? '' : 'none';
      if (match) vis++;
    });
    empty.classList.toggle('d-none', vis > 0);
    updateCount(vis);
  }

  display.addEventListener('focus', open);
  display.addEventListener('input', function () { filterOpts(this.value.trim()); });

  list.addEventListener('click', function (ev) {
    const opt = ev.target.closest('.pv-stud-opt');
    if (!opt) return;
    hidden.value  = opt.dataset.id;
    display.value = opt.dataset.label;
    display.readOnly = true;
    clear.style.display = '';
    close();
  });

  list.addEventListener('mouseover', ev => { const o = ev.target.closest('.pv-stud-opt'); if (o) o.style.background = '#f0f4f8'; });
  list.addEventListener('mouseout',  ev => { const o = ev.target.closest('.pv-stud-opt'); if (o) o.style.background = ''; });

  document.addEventListener('click', ev => { if (!wrap.contains(ev.target)) close(); });

  updateCount(total);
})();

function pvClearStudent() {
  document.getElementById('pvStudDisplay').value = '';
  document.getElementById('pvStudDisplay').readOnly = false;
  document.getElementById('pvStudHidden').value = '';
  document.getElementById('pvStudClear').style.display = 'none';
  document.getElementById('pvStudPanel').style.display = 'none';
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
