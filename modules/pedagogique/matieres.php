<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'enseignant', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$errors  = [];
try { $db->exec("ALTER TABLE matieres ADD COLUMN formule_calcul VARCHAR(20) NOT NULL DEFAULT 'exam_seul'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN seuil_reussite INT NOT NULL DEFAULT 12"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN ue_id INT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN semestre_num TINYINT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE filieres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("UPDATE filieres SET niveau_superieur=1 WHERE tronc_commun=1 OR tronc_commun_id IS NOT NULL"); } catch (PDOException $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS ue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id INT NOT NULL,
    filiere_id INT NOT NULL,
    semestre_id INT NULL,
    semestre_num TINYINT NULL,
    code_ue VARCHAR(20) NOT NULL,
    nom VARCHAR(150) NOT NULL,
    coefficient DECIMAL(4,2) NOT NULL DEFAULT 1,
    credit INT NOT NULL DEFAULT 3,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ue_code (ecole_id, filiere_id, code_ue),
    INDEX idx_ecole_id (ecole_id),
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (PDOException $e) {}
// Colonnes ajoutées après-coup si table existait déjà
try { $db->exec("ALTER TABLE ue ADD COLUMN ecole_id INT NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ue ADD COLUMN semestre_id INT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ue ADD COLUMN semestre_num TINYINT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ue ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ue ADD COLUMN coefficient DECIMAL(4,2) NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ue ADD COLUMN credit INT NOT NULL DEFAULT 3"); } catch (PDOException $e) {}
// Mettre à jour ecole_id pour les UE existantes sans école
if ($ecoleId > 0) {
    try { $db->prepare("UPDATE ue SET ecole_id=? WHERE ecole_id=0")->execute([$ecoleId]); } catch (PDOException $e) {}
}

$action = sanitize($_GET['action'] ?? 'list');
$id     = (int)($_GET['id'] ?? 0);

// Delete
if ($action === 'delete' && $id && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $canDel = hasRole('admin');
    if (!$canDel && hasRole('coordinateur')) {
        $chk = $db->prepare("SELECT filiere_id FROM matieres WHERE id=?");
        $chk->execute([$id]);
        $chkRow = $chk->fetch();
        $canDel = $chkRow && coordinateurCanAccess((int)$chkRow['filiere_id']);
    }
    if ($canDel) {
        $db->prepare("DELETE FROM matieres WHERE id=?")->execute([$id]);
        setFlash('success', 'Matière supprimée.');
    }
    redirect('/modules/pedagogique/matieres.php');
}

// Save (add or edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (hasRole('admin') || hasRole('coordinateur'))) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $filId    = (int)($_POST['filiere_id'] ?? 0);
        $isFilSup = false;
        $isNoSem  = false;  // filières sans semestre ni UE (ex: ASB, VP)
        $filCode  = '';
        if ($filId) {
            $supChk = $db->prepare("SELECT niveau_superieur, code FROM filieres WHERE id=?");
            $supChk->execute([$filId]);
            $filRow   = $supChk->fetch();
            $isFilSup = (bool)($filRow['niveau_superieur'] ?? false);
            $filCode  = strtoupper($filRow['code'] ?? '');
            $isNoSem  = in_array($filCode, ['ASB', 'VP']);
            if ($isNoSem) $isFilSup = false; // ASB/VP ont niveau_superieur=1 mais n'ont pas de semestres
        }

        $data = [
            'code'           => strtoupper(sanitize($_POST['code']      ?? '')),
            'nom'            => sanitize($_POST['nom']                   ?? ''),
            'filiere_id'     => $filId,
            'niveau_id'      => (int)($_POST['niveau_id']               ?? 0),
            'semestre_id'    => ($isFilSup || $isNoSem) ? null : ((int)($_POST['semestre_id']  ?? 0) ?: null),
            'semestre_num'   => ($isFilSup && !$isNoSem) ? ((int)($_POST['semestre_num'] ?? 0) ?: null) : null,
            'coefficient'    => max(0.25, (float)($_POST['coefficient']  ?? 1)),
            'volume_horaire' => (int)($_POST['volume_horaire']          ?? 0),
            'enseignant_id'  => (int)($_POST['enseignant_id']           ?? 0),
            'ue_id'          => (int)($_POST['ue_id']                   ?? 0) ?: null,
            'seuil_reussite' => max(0, min(20, (int)($_POST['seuil_reussite'] ?? 12))),
            'formule_calcul' => in_array($_POST['formule_calcul'] ?? '', ['pondere','demi_somme','exam_seul'])
                                ? $_POST['formule_calcul'] : 'exam_seul',
        ];

        if (empty($data['code'])) $errors[] = 'Le code est obligatoire.';
        if (empty($data['nom']))  $errors[] = 'Le nom est obligatoire.';

        // ── Vérifications de cohérence ────────────────────────────────────────
        // 1) Filières sans semestre (ASB, VP) : aucune UE autorisée
        if ($isNoSem && $data['ue_id']) {
            $errors[] = 'La filière ' . $filCode . ' ne peut pas être associée à une UE. Décochez l\'UE avant d\'enregistrer.';
            $data['ue_id'] = null;
        }

        // 2) Filières sans semestre : pas de semestre numéroté (double sécurité)
        if ($isNoSem && (int)($_POST['semestre_num'] ?? 0)) {
            $errors[] = 'La filière ' . $filCode . ' n\'utilise pas de semestres.';
        }

        // 3) L'UE sélectionnée doit appartenir à la même filière
        if ($data['ue_id'] && $data['filiere_id']) {
            $ueChk = $db->prepare("SELECT filiere_id FROM ue WHERE id=?");
            $ueChk->execute([$data['ue_id']]);
            $ueRow = $ueChk->fetch();
            if (!$ueRow) {
                $errors[] = 'L\'UE sélectionnée est introuvable.';
                $data['ue_id'] = null;
            } elseif ((int)$ueRow['filiere_id'] !== (int)$data['filiere_id']) {
                $errors[] = 'L\'UE sélectionnée n\'appartient pas à la filière choisie. Vérifiez la correspondance filière / UE.';
                $data['ue_id'] = null;
            }
        }

        // 4) semestre_num fourni pour une filière non-supérieure
        if (!$isFilSup && !$isNoSem && (int)($_POST['semestre_num'] ?? 0)) {
            $errors[] = 'Le semestre numéroté (S1, S2…) est réservé aux filières de niveau supérieur.';
        }

        // 5) niveau_id doit appartenir à la filière sélectionnée
        if ($data['niveau_id'] && $data['filiere_id']) {
            $nChk = $db->prepare("SELECT filiere_id FROM niveaux WHERE id=?");
            $nChk->execute([$data['niveau_id']]);
            $nRow = $nChk->fetch();
            if ($nRow && (int)$nRow['filiere_id'] !== (int)$data['filiere_id']) {
                $errors[] = 'Le niveau sélectionné n\'appartient pas à la filière choisie.';
                $data['niveau_id'] = 0;
            }
        }

        // Coordinateur : verifie acces a la filiere selectionnee
        if (hasRole('coordinateur')) {
            $selFId = (int)($data['filiere_id'] ?? 0);
            if (!$selFId) {
                $errors[] = 'Veuillez selectionner une filiere.';
            } elseif (!coordinateurCanAccess($selFId)) {
                $errors[] = "Vous n'avez pas acces a cette filiere.";
            }
            $editId2 = (int)($_POST['edit_id'] ?? 0);
            if ($editId2 && empty($errors)) {
                $chk2 = $db->prepare("SELECT filiere_id FROM matieres WHERE id=?");
                $chk2->execute([$editId2]);
                $r2 = $chk2->fetch();
                if ($r2 && !coordinateurCanAccess((int)$r2['filiere_id'])) $errors[] = "Acces refuse.";
            }
        }

        if (empty($errors)) {
            $editId = (int)($_POST['edit_id'] ?? 0);
            if ($editId) {
                $db->prepare("UPDATE matieres SET code=?,nom=?,filiere_id=?,niveau_id=?,semestre_id=?,semestre_num=?,coefficient=?,volume_horaire=?,enseignant_id=?,ue_id=?,seuil_reussite=?,formule_calcul=? WHERE id=?")
                   ->execute([$data['code'], $data['nom'], $data['filiere_id'] ?: null, $data['niveau_id'] ?: null, $data['semestre_id'], $data['semestre_num'], $data['coefficient'], $data['volume_horaire'], $data['enseignant_id'] ?: null, $data['ue_id'], $data['seuil_reussite'], $data['formule_calcul'], $editId]);
                setFlash('success', 'Matière modifiée.');
            } else {
                try {
                    $db->prepare("INSERT INTO matieres (ecole_id, code, nom, filiere_id, niveau_id, semestre_id, semestre_num, coefficient, volume_horaire, enseignant_id, ue_id, seuil_reussite, formule_calcul) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$ecoleId, $data['code'], $data['nom'], $data['filiere_id'] ?: null, $data['niveau_id'] ?: null, $data['semestre_id'], $data['semestre_num'], $data['coefficient'], $data['volume_horaire'], $data['enseignant_id'] ?: null, $data['ue_id'], $data['seuil_reussite'], $data['formule_calcul']]);
                    setFlash('success', 'Matière ajoutée.');
                } catch (PDOException $e) {
                    $errors[] = $e->getCode() === '23000' ? 'Ce code existe déjà pour cette filière.' : 'Erreur : ' . $e->getMessage();
                }
            }
            if (empty($errors)) redirect('/modules/pedagogique/matieres.php');
        }
    }
}

// ── UE : save ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ue_action']) && hasRole(['admin','coordinateur'])) {
    if (!verifyCsrfToken($_POST['csrf_ue'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
    } else {
        $ueCode   = strtoupper(sanitize($_POST['ue_code']    ?? ''));
        $ueNom    = sanitize($_POST['ue_nom']                ?? '');
        $ueFil    = (int)($_POST['ue_filiere_id']            ?? 0);
        $ueSemNum = (int)($_POST['ue_semestre_num']          ?? 0) ?: null;
        $ueCoef   = max(0.5, (float)($_POST['ue_coefficient'] ?? 1));
        $ueCredit = max(1,   (int)($_POST['ue_credit']        ?? 3));
        $ueEdit   = (int)($_POST['ue_edit_id']               ?? 0);

        if (!$ueCode || !$ueNom || !$ueFil) {
            setFlash('error', 'Code, nom et filière sont obligatoires.');
        } else {
            try {
                if ($ueEdit) {
                    $db->prepare("UPDATE ue SET code_ue=?,nom=?,filiere_id=?,semestre_num=?,coefficient=?,credit=? WHERE id=?")
                       ->execute([$ueCode, $ueNom, $ueFil, $ueSemNum, $ueCoef, $ueCredit, $ueEdit]);
                    setFlash('success', 'UE modifiée.');
                } else {
                    $db->prepare("INSERT INTO ue (ecole_id,code_ue,nom,filiere_id,semestre_num,coefficient,credit) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$ecoleId, $ueCode, $ueNom, $ueFil, $ueSemNum, $ueCoef, $ueCredit]);
                    setFlash('success', 'UE créée.');
                }
            } catch (PDOException $e) {
                setFlash('error', $e->getCode() === '23000' ? 'Ce code UE existe déjà pour cette filière.' : 'Erreur : ' . $e->getMessage());
            }
        }
        redirect('/modules/pedagogique/matieres.php');
    }
}

// ── UE : delete ───────────────────────────────────────────────────────────────
if ($action === 'delete_ue' && isset($_GET['ue_id']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $db->prepare("DELETE FROM ue WHERE id=?")->execute([(int)$_GET['ue_id']]);
    setFlash('success', 'UE supprimée.');
    redirect('/modules/pedagogique/matieres.php');
}

// Fetch for edit
$editMatiere = null;
if ($action === 'edit' && $id) {
    $editMatiere = $db->prepare("SELECT * FROM matieres WHERE id=?");
    $editMatiere->execute([$id]);
    $editMatiere = $editMatiere->fetch();
}

// Données à réinjecter dans la modal si le formulaire a des erreurs
$reopenModal = !empty($errors) && isset($_POST['csrf']) && !isset($_POST['ue_action']);
$formData    = $reopenModal ? [
    'edit_id'        => (int)($_POST['edit_id']        ?? 0),
    'code'           => strtoupper(sanitize($_POST['code'] ?? '')),
    'nom'            => sanitize($_POST['nom']          ?? ''),
    'filiere_id'     => (int)($_POST['filiere_id']      ?? 0),
    'niveau_id'      => (int)($_POST['niveau_id']       ?? 0),
    'semestre_id'    => (int)($_POST['semestre_id']     ?? 0),
    'semestre_num'   => (int)($_POST['semestre_num']    ?? 0),
    'coefficient'    => (float)($_POST['coefficient']   ?? 1),
    'volume_horaire' => (int)($_POST['volume_horaire']  ?? 0),
    'seuil_reussite' => (int)($_POST['seuil_reussite']  ?? 12),
    'formule_calcul' => $_POST['formule_calcul']        ?? 'exam_seul',
    'ue_id'          => (int)($_POST['ue_id']           ?? 0),
] : null;

// List — coordinateur is locked to their own filière
$fFilter = (int)($_GET['filiere_id'] ?? 0);
$nFilter = (int)($_GET['niveau_id']  ?? 0);
$search  = trim($_GET['q'] ?? '');
if (hasRole('coordinateur')) {
    $fFilter = 0; // Coordinateur voit toutes ses filieres
}
$where   = ['m.actif=1'];
$params  = [];
if ($fFilter)     { $where[] = 'm.filiere_id=?';  $params[] = $fFilter; }
if ($nFilter)     { $where[] = 'm.niveau_id=?';   $params[] = $nFilter; }
if ($search !== '') { $where[] = '(m.code LIKE ? OR m.nom LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
if ($ecoleId > 0) { $where[] = 'f.ecole_id=?';    $params[] = $ecoleId; }
$stmt = $db->prepare("
    SELECT m.*, f.nom as filiere_nom, f.code as filiere_code, n.nom as niveau_nom,
           s.nom as semestre_nom, CONCAT(e.prenom,' ',e.nom) as enseignant_nom,
           u.nom as ue_nom, u.code_ue
    FROM matieres m
    LEFT JOIN filieres f ON f.id = m.filiere_id
    LEFT JOIN niveaux n ON n.id = m.niveau_id
    LEFT JOIN semestres s ON s.id = m.semestre_id
    LEFT JOIN enseignants e ON e.id = m.enseignant_id
    LEFT JOIN ue u ON u.id = m.ue_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY f.nom, n.ordre, m.nom
");
$stmt->execute($params);
$matieres = $stmt->fetchAll();

$filieres    = getFilieres();
if (hasRole('coordinateur')) {
    $coordFIds = getCoordinateurFiliereIds();
    $filieres  = array_values(array_filter($filieres, fn($f) => in_array((int)$f['id'], $coordFIds)));
}
$niveaux     = getNiveaux();
$semestres   = getSemestres();
if ($ecoleId > 0) {
    $ensStmt = $db->prepare("SELECT id, nom, prenom, specialite FROM enseignants WHERE actif=1 AND ecole_id=? ORDER BY nom");
    $ensStmt->execute([$ecoleId]);
    $enseignants = $ensStmt->fetchAll();
    $fsStmt = $db->prepare("SELECT * FROM filieres WHERE niveau_superieur=1 AND actif=1 AND ecole_id=? ORDER BY nom");
    $fsStmt->execute([$ecoleId]);
    $filieresSup = $fsStmt->fetchAll();
    $ulStmt = $db->prepare("SELECT u.id, u.nom, u.code_ue, u.semestre_num, u.filiere_id FROM ue u JOIN filieres f ON f.id=u.filiere_id WHERE u.actif=1 AND f.ecole_id=? ORDER BY u.filiere_id, u.semestre_num, u.code_ue");
    $ulStmt->execute([$ecoleId]);
    $ues_list = $ulStmt->fetchAll();
    $uaStmt = $db->prepare("
        SELECT u.*, f.nom as filiere_nom, f.code as filiere_code,
               (SELECT COUNT(*) FROM matieres m WHERE m.ue_id=u.id AND m.actif=1) as nb_matieres
        FROM ue u
        JOIN filieres f ON f.id = u.filiere_id
        WHERE u.actif = 1 AND f.ecole_id = ?
        ORDER BY f.nom, u.semestre_num, u.code_ue
    ");
    $uaStmt->execute([$ecoleId]);
    $ues_all = $uaStmt->fetchAll();
} else {
    $enseignants = $db->query("SELECT id, nom, prenom, specialite FROM enseignants WHERE actif=1 ORDER BY nom")->fetchAll();
    $filieresSup = $db->query("SELECT * FROM filieres WHERE niveau_superieur=1 AND actif=1 ORDER BY nom")->fetchAll();
    $ues_list    = $db->query("SELECT id, nom, code_ue, semestre_num, filiere_id FROM ue WHERE actif=1 ORDER BY filiere_id, semestre_num, code_ue")->fetchAll();
    $ues_all     = $db->query("
        SELECT u.*, f.nom as filiere_nom, f.code as filiere_code,
               (SELECT COUNT(*) FROM matieres m WHERE m.ue_id=u.id AND m.actif=1) as nb_matieres
        FROM ue u
        JOIN filieres f ON f.id = u.filiere_id
        WHERE u.actif = 1
        ORDER BY f.nom, u.semestre_num, u.code_ue
    ")->fetchAll();
}

$pageTitle  = 'Matières & Modules';
$breadcrumb = ['Pédagogie' => null, 'Matières' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-book-open me-2 text-primary"></i>Matières & Modules</h2>
  <?php if (hasRole('admin') || hasRole('coordinateur')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#matiereModal" onclick="setFormMode('add')">
    <i class="fas fa-plus me-2"></i>Nouvelle matière
  </button>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <select name="filiere_id" class="form-select" onchange="this.form.submit()">
          <option value="">Toutes filières</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $fFilter == $f['id'] ? 'selected' : '' ?>><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="niveau_id" class="form-select" onchange="this.form.submit()">
          <option value="">Tous niveaux</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $nFilter == $n['id'] ? 'selected' : '' ?>><?= h($n['filiere_nom']) ?> – <?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <div class="input-group">
          <input type="text" name="q" class="form-control" placeholder="Rechercher code ou nom…" value="<?= h($search) ?>">
          <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-search"></i></button>
        </div>
      </div>
      <div class="col-md-2">
        <a href="?" class="btn btn-light w-100"><i class="fas fa-times me-1"></i>Effacer</a>
      </div>
    </form>
  </div>
</div>

<?php showFlash(); ?>
<!-- Errors -->
<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<!-- Table -->
<div class="card">
  <div class="card-header"><strong><?= count($matieres) ?></strong> matière(s)</div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <?php
        $formuleBadges = [
            'exam_seul'  => ['label' => 'Examen seul',  'class' => 'bg-secondary'],
            'pondere'    => ['label' => 'NC 40%+Ex 60%', 'class' => 'bg-primary'],
            'demi_somme' => ['label' => '(NC+Ex) ÷ 2',  'class' => 'bg-success'],
        ];
      ?>
      <thead>
        <tr><th>Code</th><th>Nom &amp; Formule</th><th>Filière / Niveau</th><th>Semestre</th><th>UE</th><th>Seuil</th><th>Coef.</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($matieres)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">Aucune matière</td></tr>
        <?php endif; ?>
        <?php foreach ($matieres as $m):
          $fCode  = $m['formule_calcul'] ?? 'exam_seul';
          $fBadge = $formuleBadges[$fCode] ?? $formuleBadges['exam_seul'];
        ?>
        <tr>
          <td><code class="fs-sm"><?= h($m['code']) ?></code></td>
          <td>
            <div class="fw-600"><?= h($m['nom']) ?></div>
            <span class="badge <?= $fBadge['class'] ?> mt-1" style="font-size:.7rem;font-weight:500"><?= $fBadge['label'] ?></span>
          </td>
          <td class="fs-sm">
            <?= h($m['filiere_code'] ?? '-') ?>
            <?php if ($m['niveau_nom']): ?> / <?= h($m['niveau_nom']) ?><?php endif; ?>
          </td>
          <td class="fs-sm text-muted">
            <?php if ($m['semestre_num']): ?>
              <span class="badge bg-info text-dark">S<?= $m['semestre_num'] ?></span>
            <?php else: ?>
              <?= h($m['semestre_nom'] ?? '–') ?>
            <?php endif; ?>
          </td>
          <td class="fs-sm">
            <?= $m['ue_nom'] ? '<span class="badge bg-info text-dark">'.h($m['code_ue']).'</span>' : '<span class="text-muted">–</span>' ?>
          </td>
          <td class="text-center fs-sm"><?= (int)($m['seuil_reussite'] ?? 12) ?>/20</td>
          <td><span class="badge bg-primary"><?= $m['coefficient'] ?></span></td>
          <td>
            <div class="d-flex gap-1">
              <?php
                $canEditMat = hasRole('admin') || (hasRole('coordinateur') && coordinateurCanAccess((int)($m['filiere_id'] ?? 0)));
              ?>
              <?php if ($canEditMat): ?>
              <button type="button"
                      class="btn btn-sm btn-outline-warning btn-edit-matiere"
                      data-matiere="<?= htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                      title="Modifier">
                <i class="fas fa-edit"></i>
              </button>
              <a href="?action=delete&id=<?= $m['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('Supprimer cette matière ?')"
                 title="Supprimer">
                <i class="fas fa-trash"></i>
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ UE Management ══ -->
<?php if (!empty($filieresSup)): ?>
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-layer-group me-2 text-warning"></i><strong>Unités d'Enseignement — Niveau Supérieur</strong></span>
    <?php if (hasRole(['admin','coordinateur'])): ?>
    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#ueModal" onclick="resetUeForm()">
      <i class="fas fa-plus me-1"></i>Nouvelle UE
    </button>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr><th>Code UE</th><th>Nom</th><th>Filière</th><th>Semestre</th><th class="text-center">Coef.</th><th class="text-center">Crédits</th><th>Matières</th><?php if (hasRole(['admin','coordinateur'])): ?><th>Actions</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php if (empty($ues_all)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <i class="fas fa-layer-group d-block mb-2" style="font-size:2rem;opacity:.3"></i>
            Aucune UE créée — créez une UE puis assignez-y des matières.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($ues_all as $ue): ?>
        <tr>
          <td><span class="badge bg-warning text-dark"><?= h($ue['code_ue']) ?></span></td>
          <td class="fw-600"><?= h($ue['nom']) ?></td>
          <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($ue['filiere_code']) ?></span></td>
          <td><span class="badge bg-info text-dark"><?= $ue['semestre_num'] ? 'S'.$ue['semestre_num'] : '–' ?></span></td>
          <td class="text-center"><span class="badge bg-primary"><?= $ue['coefficient'] ?></span></td>
          <td class="text-center"><span class="badge bg-success"><?= $ue['credit'] ?> ECTS</span></td>
          <td><span class="badge bg-secondary"><?= $ue['nb_matieres'] ?> matière(s)</span></td>
          <?php if (hasRole(['admin','coordinateur'])): ?>
          <td>
            <div class="d-flex gap-1">
              <button type="button"
                      class="btn btn-sm btn-outline-warning btn-edit-ue"
                      data-ue="<?= htmlspecialchars(json_encode($ue, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                      title="Modifier">
                <i class="fas fa-edit"></i>
              </button>
              <a href="?action=delete_ue&ue_id=<?= $ue['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('Supprimer cette UE ?')">
                <i class="fas fa-trash"></i>
              </a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="matiereModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="matiereModalTitle">Nouvelle matière</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="edit_id" id="edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Code <span class="text-danger">*</span></label>
              <input type="text" name="code" id="f_code" class="form-control" placeholder="EX: ANT101" style="text-transform:uppercase" required>
            </div>
            <div class="col-md-9">
              <label class="form-label">Nom de la matière <span class="text-danger">*</span></label>
              <input type="text" name="nom" id="f_nom" class="form-control" placeholder="Ex: Anatomie Générale" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Filière</label>
              <select name="filiere_id" id="f_filiere_id" class="form-select" onchange="onFiliereChange()">
                <option value="">-- Toutes --</option>
                <?php foreach ($filieres as $f): ?>
                  <option value="<?= $f['id'] ?>"
                          data-code="<?= h($f['code']) ?>"
                          data-sup="<?= (int)($f['niveau_superieur'] ?? 0) ?>"
                          data-tronc="<?= (int)($f['tronc_commun'] ?? 0) ?>"
                          data-tc-id="<?= (int)($f['tronc_commun_id'] ?? 0) ?>">
                    <?= h($f['code']) ?> – <?= h($f['nom']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Niveau</label>
              <select name="niveau_id" id="f_niveau_id" class="form-select">
                <option value="">-- Tous --</option>
                <?php foreach ($niveaux as $n): ?>
                  <option value="<?= $n['id'] ?>" data-filiere="<?= $n['filiere_id'] ?>">Année <?= (int)($n['ordre'] ?? 1) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Semestre standard (filières non niveau sup) -->
            <div class="col-md-4" id="semestre_row_wrap">
              <label class="form-label">Semestre</label>
              <select name="semestre_id" id="f_semestre_id" class="form-select">
                <option value="">-- Aucun --</option>
                <?php foreach ($semestres as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= h($s['annee_libelle'] ?? '') ?> – <?= h($s['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Semestre numéroté (filières niveau supérieur) -->
            <div class="col-md-4" id="semestre_num_row_wrap" style="display:none">
              <label class="form-label">Semestre</label>
              <select name="semestre_num" id="f_semestre_num" class="form-select">
                <option value="">-- Sélectionner --</option>
                <option value="1" data-for="tc">Semestre 1</option>
                <option value="2" data-for="tc">Semestre 2</option>
                <option value="3" data-for="inf_sf">Semestre 3</option>
                <option value="4" data-for="inf_sf">Semestre 4</option>
                <option value="5" data-for="inf_sf">Semestre 5</option>
                <option value="6" data-for="inf_sf">Semestre 6</option>
              </select>
            </div>
            <div class="col-md-8" id="formule_row_wrap">
              <label class="form-label">Formule de calcul de la moyenne</label>
              <select name="formule_calcul" id="f_formule_calcul" class="form-select" onchange="updateFormulePreview()">
                <option value="exam_seul" selected>Examen seul (100 %) — sans note de classe</option>
                <option value="pondere">Note de classe 40 % + Examen 60 %</option>
                <option value="demi_somme">(Note de classe + Examen) ÷ 2 — moyenne égale</option>
              </select>
              <div id="formule_preview" class="form-text mt-1"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Coefficient</label>
              <input type="number" name="coefficient" id="f_coefficient" class="form-control" min="0.25" max="10" step="0.25" value="1">
            </div>
            <div class="col-md-4">
              <label class="form-label">Volume horaire (h)</label>
              <input type="number" name="volume_horaire" id="f_volume_horaire" class="form-control" min="0" step="1" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label">Seuil réussite /20 <small class="text-muted">(défaut 12)</small></label>
              <input type="number" name="seuil_reussite" id="f_seuil_reussite" class="form-control" min="0" max="20" step="1" value="12">
            </div>
            <div class="col-md-3"></div>
            <?php if (!empty($ues_list)): ?>
            <div class="col-12" id="ue_field_wrap">
              <label class="form-label">Unité d'Enseignement (UE) <small class="text-muted">– Niveau supérieur uniquement</small></label>
              <select name="ue_id" id="f_ue_id" class="form-select">
                <option value="">-- Aucune UE --</option>
                <?php foreach ($ues_list as $ue): ?>
                  <option value="<?= $ue['id'] ?>" data-filiere="<?= $ue['filiere_id'] ?>">
                    <?= h($ue['code_ue']) ?> – <?= h($ue['nom']) ?> (S<?= $ue['semestre_num'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div id="ue_locked_msg" class="form-text text-warning" style="display:none">
                <i class="fas fa-lock me-1"></i>Non applicable pour cette filière (ASB / VP)
              </div>
            </div>
            <?php endif; ?>
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

<!-- UE Modal -->
<div class="modal fade" id="ueModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ueModalTitle">Nouvelle Unité d'Enseignement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf_ue" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="ue_action" value="1">
        <input type="hidden" name="ue_edit_id" id="ue_edit_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Code UE <span class="text-danger">*</span></label>
              <input type="text" name="ue_code" id="ue_code" class="form-control" placeholder="Ex: UE1" style="text-transform:uppercase" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Nom de l'UE <span class="text-danger">*</span></label>
              <input type="text" name="ue_nom" id="ue_nom" class="form-control" placeholder="Ex: Sciences Biologiques Fondamentales" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Filière (niveau supérieur) <span class="text-danger">*</span></label>
              <select name="ue_filiere_id" id="ue_filiere_id" class="form-select" required>
                <option value="">-- Sélectionner --</option>
                <?php foreach ($filieresSup as $f): ?>
                  <option value="<?= $f['id'] ?>"><?= h($f['code']) ?> — <?= h($f['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Semestre du programme <span class="text-danger">*</span></label>
              <select name="ue_semestre_num" id="ue_semestre_num" class="form-select" required>
                <option value="">-- Sélectionner --</option>
                <option value="1">S1 — Semestre 1 (Année 1)</option>
                <option value="2">S2 — Semestre 2 (Année 1)</option>
                <option value="3">S3 — Semestre 3 (Année 2)</option>
                <option value="4">S4 — Semestre 4 (Année 2)</option>
                <option value="5">S5 — Semestre 5 (Année 3)</option>
                <option value="6">S6 — Semestre 6 (Année 3)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Coefficient <span class="text-danger">*</span></label>
              <input type="number" name="ue_coefficient" id="ue_coefficient" class="form-control"
                     min="0.5" max="10" step="0.5" value="1" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Crédits ECTS <span class="text-danger">*</span></label>
              <input type="number" name="ue_credit" id="ue_credit" class="form-control"
                     min="1" max="30" step="1" value="3" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="alert alert-info py-2 fs-sm mb-0 w-100">
                <i class="fas fa-info-circle me-1"></i>
                Non lié à une année scolaire.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-save me-2"></i>Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Codes de filières sans notion de semestre
const NO_SEMESTRE_CODES = ['ASB', 'VP'];

function updateFormulePreview() {
  const sel  = document.getElementById('f_formule_calcul');
  const prev = document.getElementById('formule_preview');
  if (!sel || !prev) return;
  const msgs = {
    'exam_seul':  '<i class="fas fa-file-alt text-secondary me-1"></i>Colonne saisie : <strong>Examen</strong> uniquement',
    'pondere':    '<i class="fas fa-balance-scale text-primary me-1"></i>Colonnes saisie : <strong>Note de classe</strong> + <strong>Examen</strong> &mdash; Moy = NC×40% + Exam×60%',
    'demi_somme': '<i class="fas fa-divide text-success me-1"></i>Colonnes saisie : <strong>Note de classe</strong> + <strong>Examen</strong> &mdash; Moy = (NC + Exam) ÷ 2',
  };
  prev.innerHTML = msgs[sel.value] || '';
}

function getSelectedFiliereOpt() {
  const sel = document.getElementById('f_filiere_id');
  return sel.options[sel.selectedIndex] || null;
}
function getSelectedFiliereCode() {
  const opt = getSelectedFiliereOpt();
  return opt ? (opt.getAttribute('data-code') || '').toUpperCase() : '';
}
function isSelectedFiliereSup() {
  const opt = getSelectedFiliereOpt();
  return opt ? opt.getAttribute('data-sup') === '1' : false;
}

// Filtre les options de semestre selon le type de filière sup :
//   'tc'     → LSIO/TC  : S1, S2 uniquement
//   'inf_sf' → INF / SF : S3, S4, S5, S6 uniquement
function filterSemestreNumOptions() {
  const opt    = getSelectedFiliereOpt();
  const isTc   = opt && opt.getAttribute('data-tronc') === '1';
  const hasTcId= opt && parseInt(opt.getAttribute('data-tc-id') || '0') > 0;
  const type   = isTc ? 'tc' : (hasTcId ? 'inf_sf' : 'all');

  const sel = document.getElementById('f_semestre_num');
  if (!sel) return;
  sel.querySelectorAll('option[data-for]').forEach(o => {
    const show = type === 'all' || o.getAttribute('data-for') === type;
    o.style.display = show ? '' : 'none';
  });
  // Réinitialiser si la valeur courante n'est plus visible
  const cur = sel.options[sel.selectedIndex];
  if (cur && cur.style && cur.style.display === 'none') sel.value = '';
}

function updateSemestreRow() {
  const fId     = document.getElementById('f_filiere_id').value;
  const code    = getSelectedFiliereCode();
  const isSup   = isSelectedFiliereSup();
  const isNoSem = fId !== '' && NO_SEMESTRE_CODES.includes(code);

  const semWrap    = document.getElementById('semestre_row_wrap');
  const semNumWrap = document.getElementById('semestre_num_row_wrap');

  if (isNoSem) {
    semWrap.style.display    = 'none';
    semNumWrap.style.display = 'none';
    document.getElementById('f_semestre_id').value  = '';
    document.getElementById('f_semestre_num').value = '';
  } else if (isSup) {
    semWrap.style.display    = 'none';
    semNumWrap.style.display = '';
    document.getElementById('f_semestre_id').value = '';
    filterSemestreNumOptions();
  } else {
    semWrap.style.display    = '';
    semNumWrap.style.display = 'none';
    document.getElementById('f_semestre_num').value = '';
  }

  const fmWrap = document.getElementById('formule_row_wrap');
  if (fmWrap) {
    fmWrap.style.display = isNoSem ? 'none' : '';
    // ASB/VP = examen seul obligatoirement (pas de note de classe)
    if (isNoSem) document.getElementById('f_formule_calcul').value = 'exam_seul';
  }

  // Verrouiller le champ UE pour ASB / VP
  const ueField   = document.getElementById('f_ue_id');
  const ueWrap    = document.getElementById('ue_field_wrap');
  const ueMsg     = document.getElementById('ue_locked_msg');
  if (ueField) {
    ueField.disabled = isNoSem;
    if (isNoSem) ueField.value = '';
  }
  if (ueWrap)  ueWrap.style.opacity = isNoSem ? '0.5' : '';
  if (ueMsg)   ueMsg.style.display  = isNoSem ? '' : 'none';
}

function filterNiveaux(selectedId) {
  const fId    = document.getElementById('f_filiere_id').value;
  const sel    = document.getElementById('f_niveau_id');
  const opts   = sel.querySelectorAll('option[data-filiere]');
  opts.forEach(opt => {
    opt.style.display = (!fId || opt.getAttribute('data-filiere') === fId) ? '' : 'none';
  });
  // Keep or reset selection
  if (selectedId) {
    sel.value = selectedId;
  } else if (fId) {
    const current = sel.value;
    const visible = sel.querySelector('option[data-filiere="' + fId + '"]');
    // Reset if current option belongs to a different filière
    const currentOpt = sel.querySelector('option[value="' + current + '"][data-filiere]');
    if (currentOpt && currentOpt.getAttribute('data-filiere') !== fId) sel.value = '';
  }
}

function onFiliereChange() {
  filterNiveaux(null);
  updateSemestreRow();
}

function setFormMode(mode) {
  document.getElementById('matiereModalTitle').textContent = mode === 'add' ? 'Nouvelle matière' : 'Modifier la matière';
  document.getElementById('edit_id').value = '';
  ['code','nom','coefficient','volume_horaire'].forEach(f => {
    const el = document.getElementById('f_'+f);
    if (el) el.value = f === 'coefficient' ? '1' : (f === 'volume_horaire' ? '0' : '');
  });
  ['filiere_id','niveau_id','semestre_id','semestre_num','ue_id'].forEach(f => {
    const el = document.getElementById('f_'+f);
    if (el) el.value = '';
  });
  const fc = document.getElementById('f_formule_calcul');
  if (fc) { fc.value = 'exam_seul'; updateFormulePreview(); }
  const sr = document.getElementById('f_seuil_reussite');
  if (sr) sr.value = '12';
  filterNiveaux(null);
  updateSemestreRow();
}

function editMatiere(m) {
  document.getElementById('matiereModalTitle').textContent = 'Modifier la matière';
  document.getElementById('edit_id').value            = m.id;
  document.getElementById('f_code').value             = m.code            || '';
  document.getElementById('f_nom').value              = m.nom             || '';
  document.getElementById('f_filiere_id').value       = m.filiere_id      || '';
  document.getElementById('f_coefficient').value      = m.coefficient     || '1';
  document.getElementById('f_volume_horaire').value   = m.volume_horaire  || '0';
  filterNiveaux(m.niveau_id || null);
  updateSemestreRow();
  if (isSelectedFiliereSup()) {
    document.getElementById('f_semestre_num').value = m.semestre_num || '';
  } else {
    document.getElementById('f_semestre_id').value  = m.semestre_id  || '';
  }
  // Toujours appliquer la formule APRÈS updateSemestreRow() car ASB/VP peut écraser la valeur
  const savedFormule = m.formule_calcul || 'exam_seul';
  const fc = document.getElementById('f_formule_calcul');
  if (fc) {
    fc.value = savedFormule;
    // Forcer si la valeur n'a pas changé (option potentiellement absente)
    if (fc.value !== savedFormule) fc.selectedIndex = 0;
    updateFormulePreview();
  }
  const sr = document.getElementById('f_seuil_reussite');
  if (sr) sr.value = m.seuil_reussite ?? '12';
  const ue = document.getElementById('f_ue_id');
  if (ue) ue.value = m.ue_id || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('matiereModal')).show();
}

function resetUeForm() {
  document.getElementById('ueModalTitle').textContent = 'Nouvelle Unité d\'Enseignement';
  ['ue_edit_id','ue_code','ue_nom'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  ['ue_filiere_id','ue_semestre_num'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  const c  = document.getElementById('ue_coefficient'); if (c)  c.value  = '1';
  const cr = document.getElementById('ue_credit');      if (cr) cr.value = '3';
}

function editUe(u) {
  document.getElementById('ueModalTitle').textContent   = 'Modifier l\'UE';
  document.getElementById('ue_edit_id').value           = u.id;
  document.getElementById('ue_code').value              = u.code_ue;
  document.getElementById('ue_nom').value               = u.nom;
  document.getElementById('ue_filiere_id').value        = u.filiere_id;
  document.getElementById('ue_semestre_num').value      = u.semestre_num  || '';
  document.getElementById('ue_coefficient').value       = u.coefficient   || '1';
  document.getElementById('ue_credit').value            = u.credit        || '3';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('ueModal')).show();
}

// ── Délégation d'événements (évite les conflits avec tooltip/DOM) ─────────────
document.addEventListener('click', function(e) {
  const btnMat = e.target.closest('.btn-edit-matiere');
  if (btnMat) {
    e.preventDefault();
    try { editMatiere(JSON.parse(btnMat.getAttribute('data-matiere'))); }
    catch(err) { console.error('editMatiere parse error', err); }
    return;
  }
  const btnUe = e.target.closest('.btn-edit-ue');
  if (btnUe) {
    e.preventDefault();
    try { editUe(JSON.parse(btnUe.getAttribute('data-ue'))); }
    catch(err) { console.error('editUe parse error', err); }
  }
});

<?php if ($reopenModal && $formData): ?>
// ── Réouverture automatique de la modal après erreur de validation ────────────
document.addEventListener('DOMContentLoaded', function() {
  const fd = <?= json_encode($formData, JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('matiereModalTitle').textContent = fd.edit_id ? 'Modifier la matière' : 'Nouvelle matière';
  document.getElementById('edit_id').value          = fd.edit_id       || '';
  document.getElementById('f_code').value           = fd.code          || '';
  document.getElementById('f_nom').value            = fd.nom           || '';
  document.getElementById('f_filiere_id').value     = fd.filiere_id    || '';
  document.getElementById('f_coefficient').value    = fd.coefficient   || '1';
  document.getElementById('f_volume_horaire').value = fd.volume_horaire|| '0';
  filterNiveaux(fd.niveau_id || null);
  updateSemestreRow();
  if (isSelectedFiliereSup()) {
    document.getElementById('f_semestre_num').value = fd.semestre_num || '';
  } else {
    document.getElementById('f_semestre_id').value  = fd.semestre_id  || '';
  }
  const fc = document.getElementById('f_formule_calcul');
  if (fc) { fc.value = fd.formule_calcul || 'exam_seul'; updateFormulePreview(); }
  const sr = document.getElementById('f_seuil_reussite');
  if (sr) sr.value = fd.seuil_reussite !== undefined ? fd.seuil_reussite : 12;
  const ue = document.getElementById('f_ue_id');
  if (ue) ue.value = fd.ue_id || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('matiereModal')).show();
});
<?php endif; ?>
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
