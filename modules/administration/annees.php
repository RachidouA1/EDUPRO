<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur']);

$db      = getDB();
$ecoleId = getEcoleId();
$errors  = [];

// Migrations
try { $db->exec("ALTER TABLE semestres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE semestres ADD COLUMN semestre_num TINYINT NULL"); } catch (PDOException $e) {}

/**
 * Crée automatiquement les semestres manquants pour une année.
 * $type : 'standard'   → Semestre 1 & 2 uniquement
 *         'superieur'  → S1 à S6 niveau supérieur uniquement
 *         'both'       → Les deux types
 */
function createSemestresForAnnee(PDO $db, int $anneeId, string $type = 'standard'): void {
    $existing = $db->prepare("SELECT nom FROM semestres WHERE annee_id=?");
    $existing->execute([$anneeId]);
    $existingNames = array_column($existing->fetchAll(), 'nom');

    $standard = [
        ['nom' => 'Semestre 1', 'niv_sup' => 0, 'num' => null],
        ['nom' => 'Semestre 2', 'niv_sup' => 0, 'num' => null],
    ];
    $superieur = [
        ['nom' => 'S1 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 1],
        ['nom' => 'S2 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 2],
        ['nom' => 'S3 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 3],
        ['nom' => 'S4 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 4],
        ['nom' => 'S5 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 5],
        ['nom' => 'S6 – Niveau Supérieur', 'niv_sup' => 1, 'num' => 6],
    ];

    $toCreate = match ($type) {
        'superieur' => $superieur,
        'both'      => array_merge($standard, $superieur),
        default     => $standard,   // 'standard'
    };

    $ins = $db->prepare("
        INSERT INTO semestres (annee_id, nom, niveau_superieur, semestre_num, actif)
        VALUES (?, ?, ?, ?, 0)
    ");
    foreach ($toCreate as $s) {
        if (!in_array($s['nom'], $existingNames)) {
            $ins->execute([$anneeId, $s['nom'], $s['niv_sup'], $s['num']]);
        }
    }
}

// Delete annee
if (isset($_GET['delete_annee']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['delete_annee'];
    $anneeWhere = $ecoleId > 0 ? "WHERE id=? AND ecole_id=?" : "WHERE id=?";
    $anneeParams = $ecoleId > 0 ? [$id, $ecoleId] : [$id];
    $row = $db->prepare("SELECT actif FROM annees_academiques $anneeWhere");
    $row->execute($anneeParams);
    $ann = $row->fetch();
    if (!$ann) {
        setFlash('error', 'Année introuvable.');
    } elseif ($ann['actif']) {
        setFlash('error', 'Impossible de supprimer l\'année active.');
    } else {
        // Vérifier si des notes existent pour cette année
        $chkN = $db->prepare("SELECT COUNT(*) FROM notes WHERE annee_id=?");
        $chkN->execute([$id]);
        $nbNotes = (int)$chkN->fetchColumn();
        if ($nbNotes > 0) {
            setFlash('error', "Impossible de supprimer : {$nbNotes} note(s) enregistrée(s) pour cette année.");
        } else {
            // Supprimer les semestres puis l'année (semestres liés à cette année qui appartient à l'école)
            $db->prepare("DELETE FROM semestres WHERE annee_id=?")->execute([$id]);
            if ($ecoleId > 0) {
                $db->prepare("DELETE FROM annees_academiques WHERE id=? AND ecole_id=?")->execute([$id, $ecoleId]);
            } else {
                $db->prepare("DELETE FROM annees_academiques WHERE id=?")->execute([$id]);
            }
            setFlash('success', 'Année académique et ses semestres supprimés.');
        }
    }
    redirect('/modules/administration/annees.php');
}

// Delete semestre
if (isset($_GET['delete_sem']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['delete_sem'];
    // Vérifier que le semestre appartient à une année de cette école
    if ($ecoleId > 0) {
        $chkOwn = $db->prepare("SELECT s.id FROM semestres s JOIN annees_academiques a ON a.id=s.annee_id WHERE s.id=? AND a.ecole_id=?");
        $chkOwn->execute([$id, $ecoleId]);
        if (!$chkOwn->fetch()) {
            setFlash('error', 'Semestre introuvable.');
            redirect('/modules/administration/annees.php');
        }
    }
    $chkN = $db->prepare("SELECT COUNT(*) FROM notes WHERE semestre_id=?");
    $chkN->execute([$id]);
    $nbNotes = (int)$chkN->fetchColumn();
    if ($nbNotes > 0) {
        setFlash('error', "Impossible de supprimer : {$nbNotes} note(s) liée(s) à ce semestre.");
    } else {
        $db->prepare("DELETE FROM semestres WHERE id=?")->execute([$id]);
        setFlash('success', 'Semestre supprimé.');
    }
    redirect('/modules/administration/annees.php');
}

// Activate annee
if (isset($_GET['activate']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['activate'];
    // Scope deactivation to current school only
    if ($ecoleId > 0) {
        $db->prepare("UPDATE annees_academiques SET actif=0 WHERE ecole_id=?")->execute([$ecoleId]);
    } else {
        $db->exec("UPDATE annees_academiques SET actif=0");
    }
    $db->prepare("UPDATE annees_academiques SET actif=1 WHERE id=?")->execute([$id]);
    // Infer type from semesters already attached to this year
    $qInfer = $db->prepare("SELECT COUNT(*) FROM semestres WHERE annee_id=? AND niveau_superieur=1");
    $qInfer->execute([$id]);
    $inferType = ((int)$qInfer->fetchColumn() > 0) ? 'both' : 'standard';
    createSemestresForAnnee($db, $id, $inferType);
    setFlash('success', 'Année académique activée — semestres vérifiés/créés automatiquement.');
    redirect('/modules/administration/annees.php');
}

// Deactivate annee
if (isset($_GET['deactivate']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['deactivate'];
    $db->prepare("UPDATE annees_academiques SET actif=0 WHERE id=?")->execute([$id]);
    setFlash('success', 'Année académique désactivée.');
    redirect('/modules/administration/annees.php');
}

// Set active semestre
if (isset($_GET['activate_sem']) && isset($_GET['csrf']) && hasRole('admin') && verifyCsrfToken($_GET['csrf'])) {
    $id = (int)$_GET['activate_sem'];
    // Scope deactivation to current school's semestres only (via annees_academiques)
    if ($ecoleId > 0) {
        $db->prepare("UPDATE semestres SET actif=0 WHERE annee_id IN (SELECT id FROM annees_academiques WHERE ecole_id=?)")->execute([$ecoleId]);
    } else {
        $db->exec("UPDATE semestres SET actif=0");
    }
    $db->prepare("UPDATE semestres SET actif=1 WHERE id=?")->execute([$id]);
    setFlash('success', 'Semestre activé.');
    redirect('/modules/administration/annees.php');
}

// Save annee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'annee' && hasRole('admin')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $libelle   = sanitize($_POST['libelle']    ?? '');
        $dateDebut = sanitize($_POST['date_debut'] ?? '');
        $dateFin   = sanitize($_POST['date_fin']   ?? '');
        $editId    = (int)($_POST['edit_id']        ?? 0);

        if (empty($libelle)) $errors[] = 'Le libellé est obligatoire.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE annees_academiques SET libelle=?,date_debut=?,date_fin=? WHERE id=?")
                   ->execute([$libelle, $dateDebut ?: null, $dateFin ?: null, $editId]);
                setFlash('success', 'Année modifiée.');
            } else {
                if ($ecoleId > 0) {
                    $db->prepare("INSERT INTO annees_academiques (libelle,date_debut,date_fin,actif,ecole_id) VALUES (?,?,?,0,?)")
                       ->execute([$libelle, $dateDebut ?: null, $dateFin ?: null, $ecoleId]);
                } else {
                    $db->prepare("INSERT INTO annees_academiques (libelle,date_debut,date_fin,actif) VALUES (?,?,?,0)")
                       ->execute([$libelle, $dateDebut ?: null, $dateFin ?: null]);
                }
                $newId = (int)$db->lastInsertId();
                $typeSem = in_array($_POST['type_semestres'] ?? '', ['standard','superieur','both'])
                           ? $_POST['type_semestres'] : 'standard';
                createSemestresForAnnee($db, $newId, $typeSem);
                setFlash('success', 'Année créée — semestres initialisés automatiquement.');
            }
            redirect('/modules/administration/annees.php');
        }
    }
}

// Save semestre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'semestre' && hasRole('admin')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $anneeId   = (int)($_POST['annee_id']      ?? 0);
        $nom       = sanitize($_POST['sem_nom']    ?? '');
        $dateDebut = sanitize($_POST['sem_debut']  ?? '');
        $dateFin   = sanitize($_POST['sem_fin']    ?? '');
        $editId    = (int)($_POST['sem_edit_id']   ?? 0);

        if (empty($nom) || !$anneeId) $errors[] = 'Nom et année obligatoires.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE semestres SET annee_id=?,nom=?,date_debut=?,date_fin=? WHERE id=?")
                   ->execute([$anneeId, $nom, $dateDebut ?: null, $dateFin ?: null, $editId]);
            } else {
                $db->prepare("INSERT INTO semestres (annee_id,nom,date_debut,date_fin,actif) VALUES (?,?,?,?,0)")
                   ->execute([$anneeId, $nom, $dateDebut ?: null, $dateFin ?: null]);
            }
            setFlash('success', 'Semestre enregistré.');
            redirect('/modules/administration/annees.php');
        }
    }
}

if ($ecoleId > 0) {
    $aStmt = $db->prepare("SELECT a.*, COUNT(s.id) as nb_semestres FROM annees_academiques a LEFT JOIN semestres s ON s.annee_id=a.id WHERE a.ecole_id=? GROUP BY a.id ORDER BY a.libelle DESC");
    $aStmt->execute([$ecoleId]);
    $annees = $aStmt->fetchAll();
    $sStmt = $db->prepare("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id=s.annee_id WHERE a.ecole_id=? ORDER BY a.libelle DESC, s.niveau_superieur ASC, COALESCE(s.semestre_num, 0) ASC, s.id");
    $sStmt->execute([$ecoleId]);
    $semestres = $sStmt->fetchAll();
} else {
    $annees    = $db->query("SELECT a.*, COUNT(s.id) as nb_semestres FROM annees_academiques a LEFT JOIN semestres s ON s.annee_id=a.id GROUP BY a.id ORDER BY a.libelle DESC")->fetchAll();
    $semestres = $db->query("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id=s.annee_id ORDER BY a.libelle DESC, s.niveau_superieur ASC, COALESCE(s.semestre_num, 0) ASC, s.id")->fetchAll();
}

$pageTitle  = 'Années académiques';
$breadcrumb = ['Administration' => null, 'Années académiques' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-calendar-alt me-2 text-primary"></i>Années Académiques & Semestres</h2>
  <?php if (hasRole('admin')): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#anneeModal">
      <i class="fas fa-plus me-2"></i>Nouvelle année
    </button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#semestreModal">
      <i class="fas fa-plus me-2"></i>Nouveau semestre
    </button>
  </div>
  <?php endif; ?>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<div class="d-flex flex-column gap-4">

  <!-- ── Années académiques ───────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><i class="fas fa-calendar me-2 text-primary"></i>Années académiques</div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <colgroup>
          <col style="width:20%">
          <col style="width:24%">
          <col style="width:10%">
          <col style="width:12%">
          <col style="width:34%">
        </colgroup>
        <thead class="table-light">
          <tr>
            <th>Libellé</th>
            <th>Période</th>
            <th class="text-center">Semestres</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($annees as $a): ?>
          <tr class="<?= $a['actif'] ? 'table-success' : '' ?>">
            <td class="fw-bold"><?= h($a['libelle']) ?></td>
            <td class="text-muted" style="font-size:.85rem">
              <?php if ($a['date_debut'] || $a['date_fin']): ?>
                <i class="fas fa-calendar-day me-1 text-secondary"></i>
                <?= formatDate($a['date_debut']) ?> &rarr; <?= formatDate($a['date_fin']) ?>
              <?php else: ?>
                <span class="fst-italic">Non définie</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge bg-primary bg-opacity-10 text-primary fw-600"><?= $a['nb_semestres'] ?></span>
            </td>
            <td>
              <?php if ($a['actif']): ?>
                <span class="badge bg-success px-2 py-1">
                  <i class="fas fa-check-circle me-1"></i>Active
                </span>
              <?php else: ?>
                <span class="badge bg-secondary px-2 py-1">
                  <i class="fas fa-circle me-1" style="font-size:.6rem"></i>Inactive
                </span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <?php if (hasRole('admin')): ?>
              <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($a['actif']): ?>
                  <a href="?deactivate=<?= $a['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                     class="btn btn-sm btn-outline-secondary"
                     onclick="return confirm('Désactiver l\'année «<?= h(addslashes($a['libelle'])) ?>» ?')">
                    <i class="fas fa-toggle-off me-1"></i>Désactiver
                  </a>
                <?php else: ?>
                  <a href="?activate=<?= $a['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                     class="btn btn-sm btn-success"
                     onclick="return confirm('Activer l\'année «<?= h(addslashes($a['libelle'])) ?>» ?')">
                    <i class="fas fa-toggle-on me-1"></i>Activer
                  </a>
                <?php endif; ?>
                <button class="btn btn-sm btn-warning text-white" title="Modifier"
                        onclick='editAnnee(<?= json_encode($a) ?>)'>
                  <i class="fas fa-edit me-1"></i>Modifier
                </button>
                <?php if (!$a['actif']): ?>
                <a href="?delete_annee=<?= $a['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('Supprimer l\'année «<?= h(addslashes($a['libelle'])) ?>» et tous ses semestres ?')">
                  <i class="fas fa-trash me-1"></i>Supprimer
                </a>
                <?php else: ?>
                <button class="btn btn-sm btn-danger" disabled style="opacity:.4;cursor:not-allowed">
                  <i class="fas fa-trash me-1"></i>Supprimer
                </button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($annees)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox d-block mb-1"></i>Aucune année enregistrée</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Semestres ────────────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-sliders-h me-2 text-primary"></i>Semestres</span>
      <span class="badge bg-info text-dark" style="font-size:.72rem">
        <i class="fas fa-magic me-1"></i>Auto-créés à l'activation
      </span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <colgroup>
          <col style="width:18%">
          <col style="width:25%">
          <col style="width:14%">
          <col style="width:15%">
          <col style="width:28%">
        </colgroup>
        <thead class="table-light">
          <tr>
            <th>Année</th>
            <th>Semestre</th>
            <th>Type</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($semestres as $s): ?>
          <tr>
            <td class="text-muted" style="font-size:.85rem"><?= h($s['annee_libelle']) ?></td>
            <td class="fw-600" style="font-size:.88rem"><?= h($s['nom']) ?></td>
            <td>
              <?php if (!empty($s['niveau_superieur'])): ?>
                <span class="badge" style="background:#e8eaf6;color:#1a237e;font-size:.72rem">
                  <i class="fas fa-graduation-cap me-1"></i>Niv. Sup. S<?= $s['semestre_num'] ?>
                </span>
              <?php else: ?>
                <span class="badge bg-light text-secondary border" style="font-size:.72rem">Standard</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['actif']): ?>
                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Actif</span>
              <?php elseif (hasRole('admin')): ?>
                <a href="?activate_sem=<?= $s['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="badge bg-secondary text-decoration-none"
                   onclick="return confirm('Activer ce semestre ?')">
                  <i class="fas fa-toggle-off me-1"></i>Inactif
                </a>
              <?php else: ?>
                <span class="badge bg-secondary">Inactif</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <?php if (hasRole('admin')): ?>
              <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-sm btn-warning text-white" title="Modifier"
                        onclick='editSemestre(<?= json_encode($s) ?>)'>
                  <i class="fas fa-edit me-1"></i>Modifier
                </button>
                <?php if (!$s['actif']): ?>
                <a href="?delete_sem=<?= $s['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn btn-sm btn-danger" title="Supprimer"
                   onclick="return confirm('Supprimer le semestre «<?= h(addslashes($s['nom'])) ?>» ?')">
                  <i class="fas fa-trash me-1"></i>Supprimer
                </a>
                <?php else: ?>
                <button class="btn btn-sm btn-danger" disabled title="Semestre actif — non supprimable"
                        style="opacity:.45;cursor:not-allowed">
                  <i class="fas fa-trash me-1"></i>Supprimer
                </button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($semestres)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox d-block mb-1"></i>Aucun semestre enregistré</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Info box: auto-génération -->
<?php if (hasRole('admin')): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mt-3" style="font-size:.87rem">
  <i class="fas fa-info-circle fa-lg"></i>
  <div>
    <strong>Semestres automatiques :</strong> lors de la création d'une année, choisissez le type de semestres à générer —
    <strong>Standard</strong> (Semestre 1 &amp; 2), <strong>Niveau supérieur</strong> (S1 à S6) ou <strong>les deux</strong>.
    À l'activation, seuls les semestres manquants du type déjà présent sont ajoutés.
  </div>
</div>
<?php endif; ?>

<!-- Annee Modal -->
<div class="modal fade" id="anneeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="anneeModalTitle">Nouvelle année académique</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="form_type" value="annee">
        <input type="hidden" name="edit_id" id="an_edit_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Libellé * <small class="text-muted">(Ex: 2024-2025)</small></label>
            <input type="text" name="libelle" id="an_libelle" class="form-control" placeholder="2025-2026" required>
          </div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Date de début</label><input type="date" name="date_debut" id="an_debut" class="form-control"></div>
            <div class="col-6"><label class="form-label">Date de fin</label><input type="date" name="date_fin" id="an_fin" class="form-control"></div>
          </div>
          <div id="type_sem_block" class="mt-3">
            <label class="form-label">Type de semestres à générer</label>
            <div class="d-flex gap-3 flex-wrap">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="type_semestres" id="ts_standard" value="standard" checked>
                <label class="form-check-label" for="ts_standard">
                  Standard <span class="text-muted" style="font-size:.8rem">(Semestre 1 &amp; 2)</span>
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="type_semestres" id="ts_superieur" value="superieur">
                <label class="form-check-label" for="ts_superieur">
                  Niveau supérieur <span class="text-muted" style="font-size:.8rem">(S1 à S6)</span>
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="type_semestres" id="ts_both" value="both">
                <label class="form-check-label" for="ts_both">
                  Les deux types
                </label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Semestre Modal -->
<div class="modal fade" id="semestreModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="semModalTitle">Nouveau semestre</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="form_type" value="semestre">
        <input type="hidden" name="sem_edit_id" id="sem_edit_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Année académique *</label>
            <select name="annee_id" id="sem_annee_id" class="form-select" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($annees as $a): ?>
                <option value="<?= $a['id'] ?>"><?= h($a['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Nom *</label>
            <input type="text" name="sem_nom" id="sem_nom" class="form-control" placeholder="Ex: Semestre 1" required>
          </div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Début</label><input type="date" name="sem_debut" id="sem_debut" class="form-control"></div>
            <div class="col-6"><label class="form-label">Fin</label><input type="date" name="sem_fin" id="sem_fin" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editAnnee(a) {
  document.getElementById('anneeModalTitle').textContent = 'Modifier l\'année';
  document.getElementById('an_edit_id').value  = a.id;
  document.getElementById('an_libelle').value  = a.libelle;
  document.getElementById('an_debut').value    = a.date_debut || '';
  document.getElementById('an_fin').value      = a.date_fin   || '';
  document.getElementById('type_sem_block').style.display = 'none';
  new bootstrap.Modal(document.getElementById('anneeModal')).show();
}
document.getElementById('anneeModal').addEventListener('hidden.bs.modal', function () {
  document.getElementById('an_edit_id').value = '';
  document.getElementById('type_sem_block').style.display = '';
  document.getElementById('ts_standard').checked = true;
});
function editSemestre(s) {
  document.getElementById('semModalTitle').textContent  = 'Modifier le semestre';
  document.getElementById('sem_edit_id').value   = s.id;
  document.getElementById('sem_annee_id').value  = s.annee_id;
  document.getElementById('sem_nom').value        = s.nom;
  document.getElementById('sem_debut').value      = s.date_debut || '';
  document.getElementById('sem_fin').value        = s.date_fin   || '';
  new bootstrap.Modal(document.getElementById('semestreModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
