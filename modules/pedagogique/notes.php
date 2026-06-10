<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'enseignant', 'scolarite', 'coordinateur']);

$canEdit = hasRole(['admin', 'directeur', 'scolarite']);

$db   = getDB();
$user = getCurrentUser();

// Runtime migrations
try { $db->exec("ALTER TABLE notes    ADD COLUMN session        TINYINT     NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN formule_calcul VARCHAR(20) NOT NULL DEFAULT 'pondere'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE notes MODIFY COLUMN semestre_id INT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE filieres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("UPDATE filieres SET niveau_superieur=1 WHERE tronc_commun=1 OR tronc_commun_id IS NOT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE semestres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE semestres ADD COLUMN semestre_num TINYINT NULL"); } catch (PDOException $e) {}

$anneeId         = (int)($_GET['annee_id']    ?? getActiveAnnee()['id'] ?? 0);
$semestreId      = (int)($_GET['semestre_id'] ?? 0);
$matiereId       = (int)($_GET['matiere_id']  ?? 0);
$sessionNum      = (int)($_GET['session']     ?? 1);
if (!in_array($sessionNum, [1, 2])) $sessionNum = 1;
$filterFiliereId = (int)($_GET['filiere_id']  ?? 0);
$filterNiveauId  = (int)($_GET['niveau_id']   ?? 0);
$filterUeId      = (int)($_GET['ue_id']       ?? 0);
$errors = [];
$saved  = 0;

$NO_SEM_CODES = ['ASB', 'VP'];

// Filières sans CC : toujours examen seul
$EXAM_ONLY_CODES = ['ASB', 'VP'];

function calcFin(?float $cc, ?float $exam, string $f): ?float {
    if ($exam === null) return null;
    if ($f === 'pondere' && $cc !== null) {
        return round($cc * 0.4 + $exam * 0.6, 2);
    }
    if ($f === 'demi_somme' && $cc !== null) {
        return round(($cc + $exam) / 2, 2);
    }
    return $exam;
}

function effectiveFormule(string $fCode, string $matFormule): string {
    global $EXAM_ONLY_CODES;
    if (in_array(strtoupper($fCode), $EXAM_ONLY_CODES)) {
        return 'exam_seul';
    }
    return in_array($matFormule, ['pondere', 'demi_somme', 'exam_seul']) ? $matFormule : 'pondere';
}

// ── Save notes ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notes'])) {
    if (!$canEdit) {
        setFlash('error', 'Accès refusé. La saisie des notes est réservée à la scolarité, l\'administration et la direction.');
        redirect('/modules/pedagogique/notes.php?' . http_build_query($_GET));
    }
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $mId     = (int)($_POST['matiere_id']    ?? 0);
        $aId     = (int)($_POST['annee_id']      ?? 0);
        $sId     = (int)($_POST['semestre_id']   ?? 0) ?: null;
        $sessId  = (int)($_POST['session']       ?? 1);
        if (!in_array($sessId, [1, 2])) $sessId = 1;
        $formule = in_array($_POST['formule_calcul'] ?? '', ['pondere','demi_somme','exam_seul'])
                   ? $_POST['formule_calcul'] : 'pondere';

        foreach ($_POST['notes'] as $etuId => $noteData) {
            $cc   = ($noteData['cc']   ?? '') === '' ? null : min(20, max(0, (float)$noteData['cc']));
            $exam = ($noteData['exam'] ?? '') === '' ? null : min(20, max(0, (float)$noteData['exam']));
            $fin  = calcFin($cc, $exam, $formule);

            if ($sId === null) {
                $check = $db->prepare("SELECT id FROM notes WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND semestre_id IS NULL AND session=?");
                $check->execute([(int)$etuId, $mId, $aId, $sessId]);
            } else {
                $check = $db->prepare("SELECT id FROM notes WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND semestre_id=? AND session=?");
                $check->execute([(int)$etuId, $mId, $aId, $sId, $sessId]);
            }
            $existing = $check->fetchColumn();

            if ($existing) {
                $db->prepare("UPDATE notes SET note_cc=?,note_exam=?,note_finale=?,updated_at=NOW() WHERE id=?")
                   ->execute([$cc, $exam, $fin, $existing]);
            } else {
                $db->prepare("INSERT INTO notes (etudiant_id,matiere_id,annee_id,semestre_id,session,note_cc,note_exam,note_finale) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([(int)$etuId, $mId, $aId, $sId, $sessId, $cc, $exam, $fin]);
            }
            $saved++;
        }
        setFlash('success', "$saved note(s) enregistrée(s) — Session $sessId.");
        redirect('/modules/pedagogique/notes.php?' . http_build_query(array_filter([
            'annee_id'   => $aId,
            'semestre_id'=> $sId ?? '',
            'matiere_id' => $mId,
            'session'    => $sessId,
            'filiere_id' => (int)($_POST['filiere_id'] ?? 0),
            'niveau_id'  => (int)($_POST['niveau_id']  ?? 0),
            'ue_id'      => (int)($_POST['ue_id']      ?? 0),
        ])));
    }
}

// ── Build matière list ────────────────────────────────────────────────────────
$ecoleId = getEcoleId();
$mQuery = "SELECT m.*, f.nom as filiere_nom, f.code as filiere_code,
                  f.niveau_superieur as filiere_niveau_sup,
                  n.nom as niveau_nom, n.ordre as niveau_ordre
           FROM matieres m
           LEFT JOIN filieres f ON f.id=m.filiere_id
           LEFT JOIN niveaux  n ON n.id=m.niveau_id
           WHERE 1=1";
$mParams = [];
if ($ecoleId > 0) {
    $mQuery  .= " AND m.ecole_id=?";
    $mParams[] = $ecoleId;
}
if ($user['role'] === 'enseignant') {
    $mQuery  .= " AND m.enseignant_id IN (SELECT id FROM enseignants WHERE email=?)";
    $mParams[] = $user['email'];
} elseif ($user['role'] === 'coordinateur') {
    $coordFIds = getCoordinateurFiliereIds();
    if ($coordFIds) {
        $ph      = implode(',', array_fill(0, count($coordFIds), '?'));
        $mQuery .= " AND m.filiere_id IN ($ph)";
        $mParams = array_merge($mParams, $coordFIds);
    } else {
        $mQuery .= " AND 1=0";
    }
}
$mQuery .= " ORDER BY f.nom, n.ordre, m.nom";
$mStmt = $db->prepare($mQuery);
$mStmt->execute($mParams);
$matieres = $mStmt->fetchAll();

// ── Build filter datasets (filières / niveaux / UEs) from loaded matières ────
$_filieresMap    = [];
$_niveauxSeen    = [];
$niveauxByFiliereJs = [];

foreach ($matieres as $_m) {
    $fid = (int)$_m['filiere_id'];
    if ($fid && !isset($_filieresMap[$fid])) {
        $_filieresMap[$fid] = [
            'id'               => $fid,
            'code'             => $_m['filiere_code']       ?? '',
            'nom'              => $_m['filiere_nom']        ?? '',
            'niveau_superieur' => !empty($_m['filiere_niveau_sup']),
        ];
    }
    $nid = (int)$_m['niveau_id'];
    if ($fid && $nid && !isset($_niveauxSeen[$fid][$nid])) {
        $_niveauxSeen[$fid][$nid] = true;
        $niveauxByFiliereJs[$fid][] = [
            'id'    => $nid,
            'nom'   => $_m['niveau_nom']   ?? '',
            'ordre' => (int)($_m['niveau_ordre'] ?? 0),
        ];
    }
}
usort($_filieresMap, fn($a, $b) => strcmp($a['nom'], $b['nom']));
$filieresFilter = array_values($_filieresMap);
foreach ($niveauxByFiliereJs as &$_nl) usort($_nl, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
unset($_nl);

// Load UEs present in the loaded matières
$_ueIds = array_values(array_unique(array_filter(array_column($matieres, 'ue_id'))));
$uesForFilter = [];
if (!empty($_ueIds)) {
    $_ph  = implode(',', array_fill(0, count($_ueIds), '?'));
    $_uSt = $db->prepare("SELECT id, code_ue, nom, filiere_id, semestre_num FROM ue WHERE id IN ($_ph) AND actif=1 ORDER BY filiere_id, semestre_num, code_ue");
    $_uSt->execute($_ueIds);
    $uesForFilter = $_uSt->fetchAll();
}
$uesByFiliereJs  = [];
$filiereNivSupJs = [];
foreach ($filieresFilter as $_f) {
    $filiereNivSupJs[(int)$_f['id']] = (bool)$_f['niveau_superieur'];
}
foreach ($uesForFilter as $_u) {
    if ($_u['filiere_id']) {
        $uesByFiliereJs[(int)$_u['filiere_id']][] = [
            'id'          => (int)$_u['id'],
            'code_ue'     => $_u['code_ue'],
            'nom'         => $_u['nom'],
            'semestre_num'=> (int)$_u['semestre_num'],
        ];
    }
}

// Auto-dériver filière/niveau/UE depuis la matière sélectionnée
if ($matiereId && !$filterFiliereId) {
    foreach ($matieres as $_m) {
        if ((int)$_m['id'] === $matiereId) {
            $filterFiliereId = (int)$_m['filiere_id'];
            $filterNiveauId  = (int)$_m['niveau_id'];
            $filterUeId      = (int)($_m['ue_id'] ?? 0);
            break;
        }
    }
}

// ── Semestre logic (ASB/VP et niveau supérieur = no semestre_id) ─────────────
$matiereCodeMap   = [];
$matiereNivSupMap = [];
foreach ($matieres as $_m) {
    $matiereCodeMap[$_m['id']]   = strtoupper($_m['filiere_code'] ?? '');
    $matiereNivSupMap[$_m['id']] = !empty($_m['filiere_niveau_sup']);
}

$needsSemestre = true;
if ($matiereId && isset($matiereCodeMap[$matiereId])) {
    $needsSemestre = !in_array($matiereCodeMap[$matiereId], $NO_SEM_CODES);
}
// Pour le niveau supérieur, le semestre est encodé dans matiere.semestre_num
// On n'utilise pas semestre_id (cohérence avec bulletins.php qui filtre par m.semestre_num)
if ($matiereId && !empty($matiereNivSupMap[$matiereId])) {
    $needsSemestre = false;
}
if (!$needsSemestre) $semestreId = 0;

// ── Load selected matière data ────────────────────────────────────────────────
$selectedMatiere = null;
$etudiants       = [];
$existingNotes   = [];
$prevNotes       = [];   // Session 1 reference when viewing Session 2
$activeFormule   = 'pondere';

if ($matiereId && $anneeId && (!$needsSemestre || $semestreId)) {
    $smStmt = $db->prepare("
        SELECT m.*, f.nom as filiere_nom, f.code as filiere_code,
               f.niveau_superieur as fil_niveau_sup,
               n.id as niv_id, n.nom as niveau_nom,
               u.code_ue as ue_code, u.nom as ue_nom
        FROM matieres m
        LEFT JOIN filieres f ON f.id=m.filiere_id
        LEFT JOIN niveaux  n ON n.id=m.niveau_id
        LEFT JOIN ue u ON u.id=m.ue_id
        WHERE m.id=?
    ");
    $smStmt->execute([$matiereId]);
    $selectedMatiere = $smStmt->fetch();

    if ($selectedMatiere) {
        $isNivSup = !empty($selectedMatiere['fil_niveau_sup']);
        $activeFormule = effectiveFormule(
            $selectedMatiere['filiere_code'] ?? '',
            $selectedMatiere['formule_calcul'] ?? 'pondere'
        );

        $ecoleId = getEcoleId();
        $eSql    = "SELECT e.id, e.nom, e.prenom, e.matricule, e.sexe
                    FROM etudiants e
                    WHERE e.filiere_id=? AND e.niveau_id=? AND e.annee_id=? AND e.statut='actif'";
        $eParams = [$selectedMatiere['filiere_id'], $selectedMatiere['niveau_id'], $anneeId];
        if ($ecoleId > 0) { $eSql .= " AND e.ecole_id=?"; $eParams[] = $ecoleId; }
        $eSql .= " ORDER BY e.nom, e.prenom";
        $eStmt = $db->prepare($eSql);
        $eStmt->execute($eParams);
        $etudiants = $eStmt->fetchAll();

        // Notes for the current session (semestre_id IS NULL pour ASB/VP)
        if ($semestreId) {
            $nStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id=? AND session=?");
            $nStmt->execute([$matiereId, $anneeId, $semestreId, $sessionNum]);
        } else {
            $nStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id IS NULL AND session=?");
            $nStmt->execute([$matiereId, $anneeId, $sessionNum]);
        }
        foreach ($nStmt->fetchAll() as $row) { $existingNotes[$row['etudiant_id']] = $row; }

        // Session 1 reference notes shown alongside Session 2 inputs
        if ($sessionNum === 2) {
            if ($semestreId) {
                $pStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id=? AND session=1");
                $pStmt->execute([$matiereId, $anneeId, $semestreId]);
            } else {
                $pStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id IS NULL AND session=1");
                $pStmt->execute([$matiereId, $anneeId]);
            }
            foreach ($pStmt->fetchAll() as $row) { $prevNotes[$row['etudiant_id']] = $row; }

            // Exclure les étudiants ayant déjà validé la matière en session 1
            // VP >= 10, ASB >= 12, niveau supérieur >= 10
            $defaultSeuil = ($selectedMatiere['filiere_code'] === 'ASB') ? 12 : 10;
            $seuilValid = (float)($selectedMatiere['seuil_reussite'] ?? $defaultSeuil);
            $nbValidesS1 = 0;
            $etudiants = array_values(array_filter($etudiants, function($e) use ($prevNotes, $seuilValid, &$nbValidesS1) {
                $pn = $prevNotes[$e['id']] ?? null;
                if ($pn !== null && $pn['note_finale'] !== null && (float)$pn['note_finale'] >= $seuilValid) {
                    $nbValidesS1++;
                    return false; // validé en S1 → pas de rattrapage
                }
                return true;
            }));
        }
    }
}

$annees    = getAnneesAcademiques();
$semestres = getSemestres($anneeId ?: null);

$pageTitle  = $canEdit ? 'Saisie des notes' : 'Consultation des notes';
$breadcrumb = ['Pédagogie' => null, $canEdit ? 'Saisie des notes' : 'Consultation des notes' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header no-print">
  <div>
    <h2>
      <i class="fas fa-<?= $canEdit ? 'edit' : 'eye' ?> me-2 text-primary"></i>
      <?= $canEdit ? 'Saisie des Notes' : 'Consultation des Notes' ?>
    </h2>
    <?php if (!$canEdit): ?>
    <div class="text-muted fs-sm">
      <i class="fas fa-lock me-1"></i>Mode lecture seule — la saisie est réservée à la scolarité
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$canEdit && $selectedMatiere): ?>
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
    <i class="fas fa-print me-2"></i>Imprimer le PV
  </button>
  <?php endif; ?>
</div>

<!-- ── Filter panel ─────────────────────────────────────────────────────── -->
<div class="card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Année académique</label>
        <select name="annee_id" class="form-select" onchange="this.form.submit()">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2" id="semestre_filter_col">
        <label class="form-label">Semestre</label>
        <select name="semestre_id" id="semestre_id" class="form-select">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($semestres as $s): if (!empty($s['niveau_superieur'])) continue; ?>
            <option value="<?= $s['id'] ?>"
                    <?= $semestreId == $s['id'] ? 'selected' : '' ?>>
              <?= h($s['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2" id="semestre_nivsup_col" style="display:none">
        <label class="form-label">Semestre</label>
        <div id="semestre_nivsup_badge" class="form-control-plaintext">
          <span class="badge bg-info text-dark fs-sm" id="semestre_nivsup_label">–</span>
        </div>
      </div>
      <!-- ── Filière ── -->
      <div class="col-md-3">
        <label class="form-label"><i class="fas fa-layer-group me-1 text-primary" style="font-size:.8rem"></i>Filière</label>
        <select name="filiere_id" id="filter_filiere" class="form-select" onchange="onFiliereChange(this.value, false)">
          <option value="">— Toutes les filières —</option>
          <?php foreach ($filieresFilter as $ff): ?>
            <option value="<?= $ff['id'] ?>"
                    data-niv-sup="<?= $ff['niveau_superieur'] ? '1' : '0' ?>"
                    <?= $filterFiliereId == $ff['id'] ? 'selected' : '' ?>>
              <?= h($ff['code']) ?> – <?= h($ff['nom']) ?>
              <?php if ($ff['niveau_superieur']): ?><small>(Niv. Sup.)</small><?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- ── Niveau ── -->
      <div class="col-md-2" id="filter_niveau_col">
        <label class="form-label"><i class="fas fa-sort-numeric-up me-1 text-primary" style="font-size:.8rem"></i>Niveau</label>
        <select name="niveau_id" id="filter_niveau" class="form-select" onchange="filterMatieres()">
          <option value="">— Tous —</option>
          <?php
            // Pre-populate si filière déjà sélectionnée (rendu serveur)
            if ($filterFiliereId && !empty($niveauxByFiliereJs[$filterFiliereId])):
              foreach ($niveauxByFiliereJs[$filterFiliereId] as $fn):
          ?>
            <option value="<?= $fn['id'] ?>" <?= $filterNiveauId == $fn['id'] ? 'selected' : '' ?>><?= h($fn['nom']) ?></option>
          <?php endforeach; endif; ?>
        </select>
      </div>

      <!-- ── UE (niveau supérieur uniquement) ── -->
      <div class="col-md-3" id="filter_ue_col" style="<?= ($filterFiliereId && !empty($filiereNivSupJs[$filterFiliereId])) ? '' : 'display:none' ?>">
        <label class="form-label"><i class="fas fa-graduation-cap me-1 text-primary" style="font-size:.8rem"></i>Unité d'Enseignement</label>
        <select name="ue_id" id="filter_ue" class="form-select" onchange="filterMatieres()">
          <option value="">— Toutes les UE —</option>
          <?php
            if ($filterFiliereId && !empty($uesByFiliereJs[$filterFiliereId])):
              foreach ($uesByFiliereJs[$filterFiliereId] as $fu):
          ?>
            <option value="<?= $fu['id'] ?>" <?= $filterUeId == $fu['id'] ? 'selected' : '' ?>>
              S<?= $fu['semestre_num'] ?> · <?= h($fu['code_ue']) ?> – <?= h($fu['nom']) ?>
            </option>
          <?php endforeach; endif; ?>
        </select>
      </div>

      <!-- ── Matière ── -->
      <div class="col-md-3">
        <label class="form-label">Matière</label>
        <select name="matiere_id" id="matiere_filter" class="form-select" onchange="onMatiereChange(this)">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($matieres as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $matiereId == $m['id'] ? 'selected' : '' ?>
              data-filiere-code="<?= h(strtoupper($m['filiere_code'] ?? '')) ?>"
              data-niv-sup="<?= !empty($m['filiere_niveau_sup']) ? '1' : '0' ?>"
              data-sem-num="<?= (int)($m['semestre_num'] ?? 0) ?>"
              data-filiere-id="<?= (int)$m['filiere_id'] ?>"
              data-niveau-id="<?= (int)$m['niveau_id'] ?>"
              data-ue-id="<?= (int)($m['ue_id'] ?? 0) ?>">
              <?= h($m['code']) ?> – <?= h($m['nom']) ?> (<?= h($m['filiere_code'] ?? '') ?> <?= h($m['niveau_nom'] ?? '') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Session</label>
        <select name="session" class="form-select">
          <option value="1" <?= $sessionNum == 1 ? 'selected' : '' ?>>Session 1</option>
          <option value="2" <?= $sessionNum == 2 ? 'selected' : '' ?>>Session 2 – Rattrapage</option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Charger</button>
      </div>
    </form>
  </div>
</div>

<!-- Filtres filière / niveau / UE + semestre -->
<script>
const NO_SEM_CODES_JS    = ['ASB', 'VP'];
const NIVEAUX_BY_FILIERE = <?= json_encode($niveauxByFiliereJs,  JSON_UNESCAPED_UNICODE) ?>;
const UES_BY_FILIERE     = <?= json_encode($uesByFiliereJs,      JSON_UNESCAPED_UNICODE) ?>;
const FILIERE_NIV_SUP    = <?= json_encode($filiereNivSupJs) ?>;
const INIT_FILIERE_ID    = <?= $filterFiliereId ?>;
const INIT_NIVEAU_ID     = <?= $filterNiveauId ?>;
const INIT_UE_ID         = <?= $filterUeId ?>;

function onFiliereChange(filiereId, preserveSelection) {
    filiereId = parseInt(filiereId) || 0;
    const niveauSel = document.getElementById('filter_niveau');
    const ueSel     = document.getElementById('filter_ue');
    const ueCol     = document.getElementById('filter_ue_col');

    // Remplir niveaux
    niveauSel.innerHTML = '<option value="">— Tous —</option>';
    (NIVEAUX_BY_FILIERE[filiereId] || []).forEach(n => {
        const opt = document.createElement('option');
        opt.value       = n.id;
        opt.textContent = n.nom;
        niveauSel.appendChild(opt);
    });

    // Remplir UE si filière niveau supérieur
    ueSel.innerHTML = '<option value="">— Toutes les UE —</option>';
    if (filiereId && FILIERE_NIV_SUP[filiereId] && UES_BY_FILIERE[filiereId]) {
        ueCol.style.display = '';
        UES_BY_FILIERE[filiereId].forEach(u => {
            const opt = document.createElement('option');
            opt.value       = u.id;
            opt.textContent = 'S' + u.semestre_num + ' · ' + u.code_ue + ' – ' + u.nom;
            ueSel.appendChild(opt);
        });
    } else {
        ueCol.style.display = 'none';
    }

    if (!preserveSelection) {
        niveauSel.value = '';
        ueSel.value     = '';
    }
    filterMatieres();
}

function filterMatieres() {
    const filiereId = parseInt(document.getElementById('filter_filiere')?.value) || 0;
    const niveauId  = parseInt(document.getElementById('filter_niveau')?.value)  || 0;
    const ueCol     = document.getElementById('filter_ue_col');
    const ueSel     = document.getElementById('filter_ue');
    const ueId      = (ueCol && ueCol.style.display !== 'none') ? (parseInt(ueSel?.value) || 0) : 0;
    const matSel    = document.getElementById('matiere_filter');
    if (!matSel) return;

    Array.from(matSel.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        const mFil = parseInt(opt.getAttribute('data-filiere-id')) || 0;
        const mNiv = parseInt(opt.getAttribute('data-niveau-id'))  || 0;
        const mUe  = parseInt(opt.getAttribute('data-ue-id'))      || 0;
        let show = true;
        if (filiereId && mFil !== filiereId) show = false;
        if (niveauId  && mNiv !== niveauId)  show = false;
        if (ueId      && mUe  !== ueId)      show = false;
        opt.hidden = !show;
    });

    // Réinitialiser la matière si elle est masquée
    if (matSel.selectedOptions[0]?.hidden) {
        matSel.value = '';
        onMatiereChange(matSel);
    }
}

function onMatiereChange(sel) {
  const opt      = sel ? sel.options[sel.selectedIndex] : null;
  const code     = (opt ? opt.getAttribute('data-filiere-code') || '' : '').toUpperCase();
  const nivSup   = opt ? opt.getAttribute('data-niv-sup') === '1' : false;
  const semNum   = opt ? parseInt(opt.getAttribute('data-sem-num') || '0') : 0;
  const hasVal   = sel && sel.value !== '';

  const stdCol   = document.getElementById('semestre_filter_col');
  const nsCol    = document.getElementById('semestre_nivsup_col');
  const nsLabel  = document.getElementById('semestre_nivsup_label');
  const semSel   = document.getElementById('semestre_id');

  const isSansSem = hasVal && NO_SEM_CODES_JS.includes(code);
  const isNivSup  = hasVal && nivSup;

  if (isSansSem || isNivSup) {
    if (stdCol) stdCol.style.display = 'none';
    if (semSel) semSel.value = '';
  } else {
    if (stdCol) stdCol.style.display = '';
  }

  if (isNivSup) {
    if (nsCol)   nsCol.style.display = '';
    if (nsLabel) nsLabel.textContent = semNum ? 'Semestre ' + semNum : '–';
  } else {
    if (nsCol) nsCol.style.display = 'none';
  }
}

document.addEventListener('DOMContentLoaded', function () {
    // Initialiser les dropdowns filière / niveau / UE
    const fSel = document.getElementById('filter_filiere');
    if (fSel && INIT_FILIERE_ID) {
        fSel.value = INIT_FILIERE_ID;
        onFiliereChange(INIT_FILIERE_ID, true); // peuple niveaux + UE
        const nSel = document.getElementById('filter_niveau');
        if (nSel && INIT_NIVEAU_ID) nSel.value = INIT_NIVEAU_ID;
        const uSel = document.getElementById('filter_ue');
        if (uSel && INIT_UE_ID)    uSel.value = INIT_UE_ID;
    }
    filterMatieres();
    const mSel = document.getElementById('matiere_filter');
    if (mSel) onMatiereChange(mSel);
});
</script>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger no-print"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($selectedMatiere && !empty($etudiants)):
  // Labels for the print header
  $anneeLabel    = '';
  foreach ($annees as $_a) { if ($_a['id'] == $anneeId) { $anneeLabel = $_a['libelle']; break; } }
  $semestreLabel = '';
  if ($isNivSup && !empty($selectedMatiere['semestre_num'])) {
      $semestreLabel = 'Semestre ' . $selectedMatiere['semestre_num'];
  } elseif ($needsSemestre && $semestreId) {
      foreach ($semestres as $_s) { if ($_s['id'] == $semestreId) { $semestreLabel = $_s['nom']; break; } }
  }
  $showS1Ref = ($sessionNum === 2);

  $formuleDefs = [
      'exam_seul'  => ['label' => 'Examen seul (100 %)',         'html' => '<strong>Examen seul</strong> (100 %)',                          'showCC' => false, 'ccPct' => '',      'examPct' => '100 %'],
      'pondere'    => ['label' => 'CC 40 % + Examen 60 %',       'html' => '<strong>CC</strong> 40 % + <strong>Examen</strong> 60 %',        'showCC' => true,  'ccPct' => '40 %',  'examPct' => '60 %'],
      'demi_somme' => ['label' => '(CC + Examen) ÷ 2',           'html' => '<strong>(CC + Examen)</strong> ÷ 2',                            'showCC' => true,  'ccPct' => '50 %',  'examPct' => '50 %'],
  ];
  $fd               = $formuleDefs[$activeFormule] ?? $formuleDefs['exam_seul'];
  $formuleLabel     = $fd['label'];
  $formuleLabelHtml = $fd['html'];
  $showCC           = $fd['showCC'];
  $ccPct            = $fd['ccPct'];
  $examPct          = $fd['examPct'];
?>

<!-- ══════════════════ PRINT-ONLY PV ══════════════════ -->
<div class="print-only">
  <div style="text-align:center;border-bottom:2px solid #000;padding-bottom:.6rem;margin-bottom:.8rem">
    <?php $pvLogo = getLogoUrl(); if ($pvLogo): ?>
      <img src="<?= h($pvLogo) ?>" alt="Logo" style="height:60px;object-fit:contain;margin-bottom:.4rem;display:block;margin-left:auto;margin-right:auto">
    <?php endif; ?>
    <div style="font-size:1.3rem;font-weight:700;text-transform:uppercase"><?= h(getParam('etablissement_nom', 'École Privée de Santé Ibn Rochd')) ?></div>
    <?php $slogan = getParam('etablissement_slogan'); if ($slogan): ?>
      <div style="font-size:.85rem;color:#555;margin-top:.1rem"><?= h($slogan) ?></div>
    <?php endif; ?>
    <div style="font-size:1.1rem;font-weight:600;margin-top:.2rem">PROCÈS-VERBAL DE NOTES — Session <?= $sessionNum ?></div>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:.8rem">
    <tr>
      <td style="padding:.2rem .5rem;width:50%"><strong>Matière :</strong> <?= h($selectedMatiere['code']) ?> – <?= h($selectedMatiere['nom']) ?></td>
      <td style="padding:.2rem .5rem"><strong>Filière :</strong> <?= h($selectedMatiere['filiere_nom'] ?? '') ?></td>
    </tr>
    <tr>
      <td style="padding:.2rem .5rem"><strong>Niveau :</strong> <?= h($selectedMatiere['niveau_nom'] ?? '–') ?></td>
      <?php if ($needsSemestre && $semestreLabel): ?>
        <td style="padding:.2rem .5rem"><strong>Semestre :</strong> <?= h($semestreLabel) ?></td>
      <?php else: ?>
        <td style="padding:.2rem .5rem"><strong>Année :</strong> <?= h($anneeLabel) ?></td>
      <?php endif; ?>
    </tr>
    <tr>
      <td style="padding:.2rem .5rem"><strong>Formule :</strong> <?= $formuleLabel ?></td>
      <td style="padding:.2rem .5rem"><strong>Effectif :</strong> <?= count($etudiants) ?> étudiant(s)</td>
    </tr>
    <tr>
      <td colspan="2" style="padding:.2rem .5rem"><strong>Date :</strong> <?= date('d/m/Y à H:i') ?></td>
    </tr>
  </table>

  <table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead>
      <?php if ($showS1Ref): ?>
      <tr>
        <th colspan="3" style="border:1px solid #888;padding:.3rem .4rem"></th>
        <th colspan="2" style="border:1px solid #888;padding:.3rem .4rem;text-align:center;background:#f0f0f0">Session 1 (référence)</th>
        <th colspan="3" style="border:1px solid #888;padding:.3rem .4rem;text-align:center;background:#dce8ff">Session 2</th>
      </tr>
      <?php endif; ?>
      <tr style="background:#e8e8e8">
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center">#</th>
        <th style="border:1px solid #888;padding:.3rem .4rem">Matricule</th>
        <th style="border:1px solid #888;padding:.3rem .4rem">Nom &amp; Prénom</th>
        <?php if ($showS1Ref): ?>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center;background:#f0f0f0">Exam S1</th>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center;background:#f0f0f0;font-weight:700">Moy S1</th>
        <?php endif; ?>
        <?php if ($showCC): ?>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center">CC /20 <small>(<?= $ccPct ?>)</small></th>
        <?php endif; ?>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center">Examen /20 <small>(<?= $examPct ?>)</small></th>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center;font-weight:700">Moy /20</th>
        <th style="border:1px solid #888;padding:.3rem .4rem;text-align:center">Validation</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($etudiants as $pi => $pe):
        $pn   = $existingNotes[$pe['id']] ?? null;
        $ppn  = $prevNotes[$pe['id']]     ?? null;
        $pfin = $pn['note_finale'] ?? null;
        $pSeuil    = (float)($selectedMatiere['seuil_reussite'] ?? 10);
        $pValidation = '–';
        if ($pfin !== null) {
            $pValidation = $pfin >= $pSeuil ? '✓ Validé' : '✗ Non validé';
        }
      ?>
      <tr>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center"><?= $pi+1 ?></td>
        <td style="border:1px solid #ccc;padding:.3rem .4rem"><?= h($pe['matricule']) ?></td>
        <td style="border:1px solid #ccc;padding:.3rem .4rem"><?= h($pe['nom'].' '.$pe['prenom']) ?></td>
        <?php if ($showS1Ref): ?>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center;background:#fafafa"><?= $ppn && $ppn['note_exam']    !== null ? $ppn['note_exam']    : '–' ?></td>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center;background:#fafafa;font-weight:700"><?= $ppn && $ppn['note_finale'] !== null ? $ppn['note_finale'] : '–' ?></td>
        <?php endif; ?>
        <?php if ($showCC): ?>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center"><?= $pn && $pn['note_cc'] !== null ? $pn['note_cc'] : '–' ?></td>
        <?php endif; ?>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center"><?= $pn && $pn['note_exam']    !== null ? $pn['note_exam']    : '–' ?></td>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center;font-weight:700"><?= $pfin !== null ? $pfin : '–' ?></td>
        <td style="border:1px solid #ccc;padding:.3rem .4rem;text-align:center;font-weight:600;color:<?= $pfin !== null ? ($pfin >= $pSeuil ? '#155724' : '#721c24') : '#999' ?>"><?= $pValidation ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="display:flex;justify-content:space-between;margin-top:3rem;font-size:.9rem">
    <div style="text-align:center;min-width:180px"><div style="border-top:1px solid #000;padding-top:.3rem;margin-top:2.5rem">L'Enseignant</div></div>
    <div style="text-align:center;min-width:180px"><div style="border-top:1px solid #000;padding-top:.3rem;margin-top:2.5rem">La Scolarité</div></div>
    <div style="text-align:center;min-width:180px"><div style="border-top:1px solid #000;padding-top:.3rem;margin-top:2.5rem">Le Directeur</div></div>
  </div>
</div>

<!-- ══════════════════ INTERACTIVE CARD (hidden on print) ══════════════════ -->
<div class="card no-print">
  <div class="card-header">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <strong><?= h($selectedMatiere['nom']) ?></strong>
        <span class="badge bg-primary ms-2"><?= h($selectedMatiere['filiere_nom'] ?? '') ?></span>
        <span class="badge bg-secondary ms-1">Coef. <?= $selectedMatiere['coefficient'] ?></span>
        <span class="badge <?= $sessionNum === 2 ? 'bg-warning text-dark' : 'bg-info text-white' ?> ms-1">
          Session <?= $sessionNum ?><?= $sessionNum === 2 ? ' – Rattrapage' : '' ?>
        </span>
      </div>
      <div class="d-flex align-items-center gap-3">
        <span class="text-muted fs-sm"><?= count($etudiants) ?> étudiant(s)</span>
        <?php if (!$canEdit): ?>
          <span class="badge bg-warning text-dark"><i class="fas fa-eye me-1"></i>Lecture seule</span>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Imprimer le PV
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card-body">
    <div class="alert alert-info py-2 fs-sm mb-3">
      <i class="fas fa-info-circle me-2"></i>
      Note finale = <?= $formuleLabelHtml ?>. Toutes les notes sont sur 20.
      <?php if ($selectedMatiere['ue_code'] ?? null): ?>
        &mdash; UE : <strong><?= h($selectedMatiere['ue_code']) ?></strong> <?= h($selectedMatiere['ue_nom']) ?>
      <?php endif; ?>
    </div>
    <?php if ($sessionNum === 2 && ($nbValidesS1 ?? 0) > 0): ?>
    <div class="alert alert-success py-2 fs-sm mb-3 d-flex align-items-center gap-2">
      <i class="fas fa-check-circle"></i>
      <span>
        <strong><?= $nbValidesS1 ?> étudiant(s)</strong> ont validé cette matière en Session 1
        (note ≥ <?= number_format($seuilValid, 0) ?>/20) et sont exclus de la saisie Session 2.
      </span>
    </div>
    <?php endif; ?>
    <?php if ($isNivSup): ?>
    <div class="alert py-2 fs-sm mb-3 d-flex flex-wrap gap-2 align-items-center" style="background:#fff8e1;border:1px solid #ffe082;color:#5d4037">
      <i class="fas fa-graduation-cap me-1"></i><strong>Règles niveau supérieur :</strong>
      <span class="badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7">≥ 10 &rarr; Validé</span>
      <span class="badge" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80">6 – 9 &rarr; Compensable (selon moyenne UE)</span>
      <span class="badge" style="background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a">≤ 5 &rarr; Éliminatoire (non compensable)</span>
      <span class="text-muted ms-1">La validation de l'UE requiert une moyenne UE ≥ 10 sans note éliminatoire.</span>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf"           value="<?= h(generateCsrfToken()) ?>">
      <input type="hidden" name="matiere_id"     value="<?= $matiereId ?>">
      <input type="hidden" name="annee_id"       value="<?= $anneeId ?>">
      <input type="hidden" name="semestre_id"    value="<?= $semestreId ?>">
      <input type="hidden" name="session"        value="<?= $sessionNum ?>">
      <input type="hidden" name="formule_calcul" value="<?= h($activeFormule) ?>">
      <input type="hidden" name="filiere_id"     value="<?= $filterFiliereId ?>">
      <input type="hidden" name="niveau_id"      value="<?= $filterNiveauId ?>">
      <input type="hidden" name="ue_id"          value="<?= $filterUeId ?>">

      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <?php if ($showS1Ref): ?>
            <tr class="table-light">
              <th colspan="3" class="border-0"></th>
              <th colspan="2" class="text-center text-muted border-start" style="font-size:.72rem;letter-spacing:.5px;background:#f5f5f5">SESSION 1 – RÉFÉRENCE</th>
              <th colspan="3" class="text-center border-start" style="font-size:.72rem;letter-spacing:.5px;background:#e8f0fe">SESSION 2 – RATTRAPAGE</th>
            </tr>
            <?php endif; ?>
            <tr>
              <th>#</th>
              <th>Matricule</th>
              <th>Nom &amp; Prénom</th>
              <?php if ($showS1Ref): ?>
              <th class="text-muted fs-sm border-start" style="background:#fafafa">Exam S1</th>
              <th class="text-muted fs-sm"              style="background:#fafafa">Moy S1</th>
              <?php endif; ?>
              <?php if ($showCC): ?>
              <th class="<?= $showS1Ref ? 'border-start' : '' ?>">CC /20 <small class="text-muted">(<?= $ccPct ?>)</small></th>
              <?php endif; ?>
              <th class="<?= ($showS1Ref && !$showCC) ? 'border-start' : '' ?>">Examen /20 <small class="text-muted">(<?= $examPct ?>)</small></th>
              <th>Moyenne /20</th>
              <th>Validation</th>
              <?php if ($isNivSup): ?><th>Statut</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($etudiants as $i => $e):
              $n  = $existingNotes[$e['id']] ?? null;
              $pn = $prevNotes[$e['id']]     ?? null;
            ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td><code class="fs-sm"><?= h($e['matricule']) ?></code></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar-circle" style="background:<?= $e['sexe']==='M'?'#1a73e8':'#e91e63' ?>;width:30px;height:30px;font-size:.7rem;">
                    <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                  </div>
                  <span class="fw-600 fs-sm"><?= h($e['nom'].' '.$e['prenom']) ?></span>
                </div>
              </td>
              <?php if ($showS1Ref): ?>
              <td class="text-muted fs-sm" style="background:#fafafa"><?= $pn && $pn['note_exam']    !== null ? $pn['note_exam']    : '–' ?></td>
              <td class="fs-sm"            style="background:#fafafa">
                <?php if ($pn && $pn['note_finale'] !== null): ?><?= noteBadge((float)$pn['note_finale']) ?><?php else: ?><span class="text-muted">–</span><?php endif; ?>
              </td>
              <?php endif; ?>
              <?php if ($showCC): ?>
              <td>
                <?php if ($canEdit): ?>
                <input type="number" name="notes[<?= $e['id'] ?>][cc]"
                       class="form-control form-control-sm note-cc" style="width:88px"
                       min="0" max="20" step="0.25" placeholder="–"
                       value="<?= $n && $n['note_cc'] !== null ? $n['note_cc'] : '' ?>"
                       data-row="<?= $i ?>">
                <?php else: ?>
                <span class="fw-600 <?= ($n && $n['note_cc'] !== null) ? '' : 'text-muted' ?>">
                  <?= ($n && $n['note_cc'] !== null) ? $n['note_cc'] : '–' ?>
                </span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td>
                <?php if ($canEdit): ?>
                <input type="number" name="notes[<?= $e['id'] ?>][exam]"
                       class="form-control form-control-sm note-exam" style="width:88px"
                       min="0" max="20" step="0.25" placeholder="–"
                       value="<?= $n && $n['note_exam'] !== null ? $n['note_exam'] : '' ?>"
                       data-row="<?= $i ?>">
                <?php else: ?>
                <span class="fw-600 <?= ($n && $n['note_exam'] !== null) ? '' : 'text-muted' ?>">
                  <?= ($n && $n['note_exam'] !== null) ? $n['note_exam'] : '–' ?>
                </span>
                <?php endif; ?>
              </td>
              <td>
                <span class="fw-bold" id="fin_<?= $i ?>">
                  <?php if ($n && $n['note_finale'] !== null): ?><?= noteBadge((float)$n['note_finale']) ?><?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </span>
              </td>
              <td id="mention_<?= $i ?>">
                <?php if ($n && $n['note_finale'] !== null):
                  $iVal = (float)$n['note_finale'] >= (float)($selectedMatiere['seuil_reussite'] ?? 10); ?>
                  <span class="badge bg-<?= $iVal ? 'success' : 'danger' ?>">
                    <?= $iVal ? '✓ Validé' : '✗ Non validé' ?>
                  </span>
                <?php else: ?><span class="text-muted">–</span><?php endif; ?>
              </td>
              <?php if ($isNivSup): ?>
              <td id="statut_<?= $i ?>">
                <?php
                  if ($n && $n['note_finale'] !== null) {
                      $nf = (float)$n['note_finale'];
                      if ($nf >= 10) {
                          echo '<span class="badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7"><i class="fas fa-check me-1"></i>Validé</span>';
                      } elseif ($nf <= 5) {
                          echo '<span class="badge" style="background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a"><i class="fas fa-times me-1"></i>Éliminatoire</span>';
                      } else {
                          echo '<span class="badge" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80"><i class="fas fa-balance-scale me-1"></i>Compensable</span>';
                      }
                  } else { echo '<span class="text-muted">–</span>'; }
                ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($canEdit): ?>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary px-4">
          <i class="fas fa-save me-2"></i>Enregistrer — Session <?= $sessionNum ?>
        </button>
      </div>
      <?php endif; ?>

    </form>
    <?php if (!$canEdit): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mt-3 no-print">
      <i class="fas fa-lock"></i>
      <span>Mode lecture seule — la modification des notes est réservée à la <strong>scolarité</strong>.</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const FORMULE_ACTIVE = '<?= h($activeFormule) ?>';
const IS_NIV_SUP     = <?= ($isNivSup ?? false) ? 'true' : 'false' ?>;
const SEUIL_REUSSITE = <?= (float)($selectedMatiere['seuil_reussite'] ?? 10) ?>;

function calcRow(row) {
  const ccEl   = document.querySelector(`.note-cc[data-row="${row}"]`);
  const examEl = document.querySelector(`.note-exam[data-row="${row}"]`);
  const finEl  = document.getElementById('fin_'     + row);
  const menEl  = document.getElementById('mention_' + row);
  const stEl   = document.getElementById('statut_'  + row);

  const cc   = ccEl   && ccEl.value   !== '' ? parseFloat(ccEl.value)   : null;
  const exam = examEl && examEl.value !== '' ? parseFloat(examEl.value) : null;

  let fin = null;
  if (exam !== null) {
    if (FORMULE_ACTIVE === 'pondere' && cc !== null) {
      fin = Math.round((cc * 0.4 + exam * 0.6) * 100) / 100;
    } else if (FORMULE_ACTIVE === 'demi_somme' && cc !== null) {
      fin = Math.round((cc + exam) / 2 * 100) / 100;
    } else {
      fin = Math.round(exam * 100) / 100;
    }
  }

  if (fin !== null) {
    const cls = fin >= SEUIL_REUSSITE ? 'text-success fw-bold' : 'text-danger fw-bold';
    finEl.innerHTML = `<span class="${cls}">${fin.toFixed(2)}</span>`;
    const valide = fin >= SEUIL_REUSSITE;
    menEl.innerHTML = `<span class="badge bg-${valide ? 'success' : 'danger'}">${valide ? '✓ Validé' : '✗ Non validé'}</span>`;

    // Statut niveau supérieur
    if (IS_NIV_SUP && stEl) {
      if (fin >= 10) {
        stEl.innerHTML = '<span class="badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7"><i class="fas fa-check me-1"></i>Validé</span>';
      } else if (fin <= 5) {
        stEl.innerHTML = '<span class="badge" style="background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a"><i class="fas fa-times me-1"></i>Éliminatoire</span>';
      } else {
        stEl.innerHTML = '<span class="badge" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80"><i class="fas fa-balance-scale me-1"></i>Compensable</span>';
      }
    }
  } else {
    finEl.innerHTML = '<span class="text-muted">–</span>';
    menEl.innerHTML = '<span class="text-muted">–</span>';
    if (IS_NIV_SUP && stEl) stEl.innerHTML = '<span class="text-muted">–</span>';
  }
}

document.querySelectorAll('.note-cc, .note-exam').forEach(el => {
  el.addEventListener('input', () => calcRow(el.dataset.row));
});
</script>

<?php elseif ($matiereId && $anneeId && (!$needsSemestre || $semestreId)): ?>
<div class="card no-print">
  <div class="card-body empty-state">
    <i class="fas fa-<?= ($sessionNum === 2 && ($nbValidesS1 ?? 0) > 0) ? 'check-circle text-success' : 'users' ?>"></i>
    <?php if ($sessionNum === 2 && ($nbValidesS1 ?? 0) > 0): ?>
    <h5>Tous les étudiants ont validé en Session 1</h5>
    <p class="text-muted">Les <?= $nbValidesS1 ?> étudiant(s) ont obtenu une note ≥ <?= number_format($seuilValid ?? 12, 0) ?>/20 en Session 1. Aucune saisie Session 2 nécessaire pour cette matière.</p>
    <?php else: ?>
    <h5>Aucun étudiant</h5>
    <p class="text-muted">Aucun étudiant actif trouvé pour cette matière et cette année.</p>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card no-print">
  <div class="card-body empty-state">
    <i class="fas fa-hand-point-up"></i>
    <h5>Sélectionner une matière</h5>
    <p class="text-muted">
      Choisissez l'année<?= $needsSemestre ? ', le semestre' : '' ?>, la matière et la session pour afficher la liste.
    </p>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
