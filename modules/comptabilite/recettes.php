<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable']);

$db        = getDB();
$user      = getCurrentUser();
$errors    = [];
$errorForm = '';

// Ensure numero_recu column exists on paiements_etudiants
try { $db->exec("ALTER TABLE paiements_etudiants ADD COLUMN numero_recu VARCHAR(20) NULL"); } catch (PDOException) {}

// ===== POST: Versement de frais de formation =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'frais_formation') {
    $errorForm = 'frais_formation';
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } elseif (!hasRole(['admin', 'comptable'])) {
        $errors[] = 'Accès refusé.';
    } else {
        $etuId     = (int)($_POST['etudiant_id']     ?? 0);
        $typeFrais = sanitize($_POST['type_frais']    ?? '');
        $montant   = (float)($_POST['montant']        ?? 0);
        $verse     = (float)($_POST['montant_paye']   ?? 0);
        $date      = sanitize($_POST['date_recette']  ?? date('Y-m-d'));
        $mode      = sanitize($_POST['mode_paiement'] ?? 'especes');
        $ref       = sanitize($_POST['reference']     ?? '');
        $anneeId   = (int)($_POST['annee_id']         ?? 0);

        $libellesMap = [
            'inscription' => "Frais d'inscription",
            'scolarite'   => 'Frais de formation',
            'examen'      => "Frais d'examen",
        ];

        if (!$etuId)                                      $errors[] = 'Veuillez sélectionner un apprenant.';
        if (!array_key_exists($typeFrais, $libellesMap))  $errors[] = 'Sélectionnez un type de frais.';
        if ($montant <= 0)                                $errors[] = 'Le montant dû doit être supérieur à 0.';
        if ($verse < 0)                                    $errors[] = 'Montant versé invalide.';

        if (empty($errors)) {
            $libelle = $libellesMap[$typeFrais];
            $statut  = 'en_attente';
            if ($verse >= $montant) $statut = 'complet';
            elseif ($verse > 0)    $statut = 'partiel';

            // Generate receipt number (sequential per year)
            $year  = date('Y', strtotime($date));
            $stmtN = $db->prepare("SELECT COUNT(*) FROM paiements_etudiants WHERE YEAR(date_paiement)=? AND numero_recu IS NOT NULL AND numero_recu != ''");
            $stmtN->execute([$year]);
            $numRecu = 'REC-' . $year . '-' . str_pad((int)$stmtN->fetchColumn() + 1, 5, '0', STR_PAD_LEFT);

            $db->prepare("INSERT INTO paiements_etudiants
                (etudiant_id, annee_id, libelle, montant, montant_paye, date_paiement, mode_paiement, reference, statut, numero_recu, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$etuId, $anneeId ?: null, $libelle, $montant, $verse, $date, $mode, $ref ?: null, $statut, $numRecu, $user['id']]);

            $payId = (int)$db->lastInsertId();

            // Sync to recettes table for accounting
            if ($verse > 0) {
                $etuRow = $db->prepare("SELECT nom, prenom FROM etudiants WHERE id=?");
                $etuRow->execute([$etuId]);
                $etuRow = $etuRow->fetch();
                $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $date,
                       $libelle . ' – ' . ($etuRow['prenom'] ?? '') . ' ' . ($etuRow['nom'] ?? ''),
                       $typeFrais, $verse, $mode, $numRecu, $user['id']]);
            }

            redirect('/modules/comptabilite/recu.php?print=' . $payId . '&auto_print=1');
        }
    }
}

// ===== POST: Autre recette =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $errorForm = 'add';
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } elseif (!hasRole(['admin', 'comptable'])) {
        $errors[] = 'Accès refusé.';
    } else {
        $libelle = sanitize($_POST['libelle']      ?? '');
        $source  = sanitize($_POST['source']       ?? '');
        $montant = (float)($_POST['montant']       ?? 0);
        $date    = sanitize($_POST['date_recette'] ?? date('Y-m-d'));
        $mode    = sanitize($_POST['mode_paiement'] ?? 'especes');
        $ref     = sanitize($_POST['reference']    ?? '');
        $anneeId = (int)($_POST['annee_id']        ?? 0);

        if (empty($libelle) || $montant <= 0) $errors[] = 'Libellé et montant obligatoires.';
        if (empty($source))                   $errors[] = 'La source est obligatoire.';

        if (empty($errors)) {
            $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$anneeId ?: null, $date, $libelle, 'autre', $montant, $mode, $ref ?: null, $source, $user['id']]);
            setFlash('success', 'Recette enregistrée.');
            redirect('/modules/comptabilite/recettes.php');
        }
    }
}

// ===== POST: Delete =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && hasRole(['admin', 'comptable'])) {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $db->prepare("DELETE FROM recettes WHERE id=?")->execute([(int)$_POST['rec_id']]);
        setFlash('success', 'Recette supprimée.');
        redirect('/modules/comptabilite/recettes.php');
    }
}

// ===== Filters & Data =====
$filterAnneeId = (int)($_GET['annee_id'] ?? getActiveAnnee()['id'] ?? 0);
$cat           = sanitize($_GET['cat']   ?? '');
$mois          = sanitize($_GET['mois']  ?? '');

$where  = ['1=1'];
$params = [];
if ($filterAnneeId) { $where[] = 'r.annee_id=?';                          $params[] = $filterAnneeId; }
if ($cat)           { $where[] = 'r.categorie=?';                         $params[] = $cat; }
if ($mois)          { $where[] = 'DATE_FORMAT(r.date_recette,"%Y-%m")=?'; $params[] = $mois; }

$stmt = $db->prepare("SELECT r.*, a.libelle as annee_libelle FROM recettes r LEFT JOIN annees_academiques a ON a.id=r.annee_id WHERE " . implode(' AND ', $where) . " ORDER BY r.date_recette DESC, r.id DESC");
$stmt->execute($params);
$recettes = $stmt->fetchAll();

$totalRecettes = array_sum(array_column($recettes, 'montant'));
$annees        = getAnneesAcademiques();
$anneeActive   = getActiveAnnee();

$allEtudiants = $db->query("
    SELECT e.id, e.nom, e.prenom, e.matricule, f.code as filiere_code, n.nom as niveau_nom
    FROM etudiants e
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n  ON n.id = e.niveau_id
    WHERE e.statut = 'actif'
    ORDER BY e.nom, e.prenom
")->fetchAll();

$pageTitle  = 'Recettes';
$breadcrumb = ['Comptabilité' => null, 'Recettes' => null];
$extraHead  = '<style>
@media screen { .print-only { display: none !important; } }
@media print  { .print-only { display: block !important; } }
</style>';
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header no-print">
  <h2><i class="fas fa-arrow-circle-down me-2 text-success"></i>Gestion des Recettes</h2>
  <?php if (hasRole(['admin', 'comptable'])): ?>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#choiceModal">
    <i class="fas fa-plus me-2"></i>Nouvelle recette
  </button>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4 no-print">
  <div class="col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant($totalRecettes) ?></div><div class="stat-label">Total filtré</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($recettes) ?></div><div class="stat-label">Transactions</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <?php $thisMonth = $db->query("SELECT COALESCE(SUM(montant),0) FROM recettes WHERE MONTH(date_recette)=MONTH(NOW()) AND YEAR(date_recette)=YEAR(NOW())")->fetchColumn(); ?>
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatMontant($thisMonth) ?></div><div class="stat-label">Ce mois</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <select name="annee_id" class="form-select">
          <option value="">Toutes années</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $filterAnneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="cat" class="form-select">
          <option value="">Toutes catégories</option>
          <option value="inscription" <?= $cat==='inscription' ? 'selected' : '' ?>>Inscription</option>
          <option value="scolarite"   <?= $cat==='scolarite'   ? 'selected' : '' ?>>Scolarité / Formation</option>
          <option value="examen"      <?= $cat==='examen'      ? 'selected' : '' ?>>Examen</option>
          <option value="autre"       <?= $cat==='autre'       ? 'selected' : '' ?>>Autre</option>
        </select>
      </div>
      <div class="col-md-3">
        <input type="month" name="mois" class="form-control" value="<?= h($mois) ?>" placeholder="Filtrer par mois">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i>Filtrer</button>
        <a href="?" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Errors -->
<?php foreach ($errors as $e): ?><div class="alert alert-danger no-print"><?= h($e) ?></div><?php endforeach; ?>

<?php
$_pParts = [];
if ($filterAnneeId) { foreach ($annees as $_a) { if ($_a['id'] == $filterAnneeId) { $_pParts[] = h($_a['libelle']); break; } } }
if ($cat)  $_pParts[] = ucfirst($cat);
if ($mois) $_pParts[] = date('m/Y', strtotime($mois . '-01'));
$_pFiltreStr = $_pParts ? implode(' · ', $_pParts) : 'Aucun filtre';
?>
<div class="print-only mb-3">
  <div class="text-center">
    <div style="font-size:16pt;font-weight:700;letter-spacing:.3px">École Privée de Santé Ibn Rochd (EPSI)</div>
    <div style="font-size:9pt;color:#555;margin-bottom:5px">Tahoua, Région de Tahoua – Niger</div>
    <div style="font-size:13pt;font-weight:600;text-decoration:underline;margin-bottom:4px">Liste des Recettes</div>
    <div style="font-size:9pt">Filtre&nbsp;: <?= $_pFiltreStr ?> &nbsp;|&nbsp; Édité le <?= date('d/m/Y à H:i') ?></div>
    <div style="font-size:9pt">Total&nbsp;: <strong><?= formatMontant($totalRecettes) ?></strong> &nbsp;|&nbsp; <?= count($recettes) ?> entrée(s)</div>
  </div>
  <hr style="border-top:2px solid #333;margin-top:8px;margin-bottom:0">
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><strong><?= count($recettes) ?></strong> entrée(s) – Total : <strong class="text-success"><?= formatMontant($totalRecettes) ?></strong></span>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print"><i class="fas fa-print me-1"></i>Imprimer</button>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Libellé</th>
          <th>Catégorie</th>
          <th>Année</th>
          <th>Montant</th>
          <th>Mode</th>
          <th>Référence</th>
          <th class="no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recettes)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">Aucune recette</td></tr>
        <?php endif; ?>
        <?php foreach ($recettes as $r):
          $catColors = ['inscription'=>'primary','scolarite'=>'success','examen'=>'info','autre'=>'secondary'];
          $catLabels = ['inscription'=>'Inscription','scolarite'=>'Formation','examen'=>'Examen','autre'=>'Autre'];
          $badgeColor = $catColors[$r['categorie']] ?? 'secondary';
          $badgeLabel = $catLabels[$r['categorie']] ?? ucfirst(h($r['categorie']));
        ?>
        <tr>
          <td class="text-muted fs-sm"><?= formatDate($r['date_recette']) ?></td>
          <td>
            <span class="fw-600"><?= h($r['libelle']) ?></span>
            <?php if (!empty($r['notes'])): ?>
              <div class="text-muted" style="font-size:.75rem"><i class="fas fa-tag me-1 opacity-50"></i><?= h($r['notes']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-<?= $badgeColor ?> bg-opacity-75"><?= $badgeLabel ?></span></td>
          <td class="text-muted fs-sm"><?= h($r['annee_libelle'] ?? '-') ?></td>
          <td class="fw-bold text-success"><?= formatMontant($r['montant']) ?></td>
          <td class="fs-sm"><?= ucfirst(str_replace('_', ' ', h($r['mode_paiement']))) ?></td>
          <td class="text-muted fs-sm"><?= h($r['reference'] ?? '-') ?></td>
          <td class="no-print">
            <?php if (hasRole(['admin', 'comptable'])): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="rec_id" value="<?= $r['id'] ?>">
              <button type="button" class="btn btn-icon btn-sm btn-outline-danger" onclick="confirmDelete(this.form,'cette recette')"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Choix du type de recette ===== -->
<?php if (hasRole(['admin', 'comptable'])): ?>
<div class="modal fade" id="choiceModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-success"></i>Type de recette</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3 pt-2">
        <div class="d-grid gap-2">

          <!-- Option 1: Frais de formation -->
          <button type="button" id="openFraisBtn" class="btn btn-outline-primary text-start p-3" style="border-radius:10px">
            <div class="d-flex align-items-center gap-3">
              <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10" style="width:46px;height:46px;flex-shrink:0">
                <i class="fas fa-graduation-cap text-primary"></i>
              </div>
              <div>
                <div class="fw-bold">Frais de formation</div>
                <div class="text-muted" style="font-size:.78rem">Inscription · Formation · Examen — lié à un apprenant</div>
              </div>
            </div>
          </button>

          <?php if (hasRole(['admin', 'comptable'])): ?>
          <!-- Option 2: Autre recette -->
          <button type="button" id="openAutreBtn" class="btn btn-outline-success text-start p-3" style="border-radius:10px">
            <div class="d-flex align-items-center gap-3">
              <div class="d-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10" style="width:46px;height:46px;flex-shrink:0">
                <i class="fas fa-coins text-success"></i>
              </div>
              <div>
                <div class="fw-bold">Autre recette</div>
                <div class="text-muted" style="font-size:.78rem">Toute autre source de revenus (don, subvention…)</div>
              </div>
            </div>
          </button>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL: Versement – Frais de formation ===== -->
<div class="modal fade" id="fraisFormationModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:rgba(26,115,232,.07);border-bottom:2px solid #1a73e8">
        <h5 class="modal-title"><i class="fas fa-graduation-cap me-2 text-primary"></i>Versement – Frais de formation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="frais_formation">
        <div class="modal-body">

          <!-- Type de frais -->
          <div class="mb-4">
            <label class="form-label fw-bold mb-2">Type de frais <span class="text-danger">*</span></label>
            <div class="row g-2">
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeInscription" value="inscription" autocomplete="off" required>
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeInscription" style="font-size:.85rem">
                  <i class="fas fa-id-card fa-lg"></i>Inscription
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeScolarite" value="scolarite" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeScolarite" style="font-size:.85rem">
                  <i class="fas fa-book-open fa-lg"></i>Formation
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeExamen" value="examen" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeExamen" style="font-size:.85rem">
                  <i class="fas fa-file-alt fa-lg"></i>Examen
                </label>
              </div>
            </div>
          </div>

          <!-- Sélection apprenant -->
          <div class="mb-3">
            <label class="form-label fw-bold">Apprenant <span class="text-danger">*</span></label>
            <div class="custom-student-select position-relative" id="fraisStudentWrap">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-graduate text-primary" style="font-size:.85rem"></i></span>
                <input type="text" id="fraisStudentDisplay" class="form-control border-start-0 border-end-0"
                       placeholder="Cliquer pour sélectionner un apprenant..." readonly style="cursor:pointer;background:#fff">
                <button type="button" id="fraisStudentClear" class="btn btn-outline-secondary border-start-0" style="display:none" title="Effacer">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <input type="hidden" name="etudiant_id" id="fraisStudentHidden" value="">

              <div id="fraisStudentPanel" class="card shadow position-absolute w-100" style="display:none;z-index:1055;top:calc(100% + 4px)">
                <div class="p-2 border-bottom">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="fraisStudentFilter" class="form-control" placeholder="Filtrer par nom, prénom ou matricule...">
                  </div>
                </div>
                <div id="fraisStudentList" style="max-height:200px;overflow-y:auto">
                  <?php if (empty($allEtudiants)): ?>
                    <div class="text-center text-muted py-3" style="font-size:.85rem"><i class="fas fa-info-circle me-1"></i>Aucun apprenant actif</div>
                  <?php else: ?>
                    <?php foreach ($allEtudiants as $e): ?>
                    <div class="stu-opt d-flex align-items-center gap-2 px-3 py-2"
                         data-id="<?= $e['id'] ?>"
                         data-label="<?= h($e['prenom'].' '.$e['nom'].' ('.$e['matricule'].')') ?>"
                         data-search="<?= strtolower(h($e['nom'].' '.$e['prenom'].' '.$e['matricule'])) ?>"
                         style="cursor:pointer;transition:background .15s">
                      <div class="avatar-circle" style="background:#1a73e8;width:32px;height:32px;font-size:.7rem;flex-shrink:0">
                        <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                      </div>
                      <div style="min-width:0">
                        <div class="fw-600 fs-sm text-truncate"><?= h($e['prenom'].' '.$e['nom']) ?></div>
                        <div style="font-size:.73rem;color:#888">
                          <code><?= h($e['matricule']) ?></code>
                          <?php if ($e['filiere_code']): ?>&nbsp;·&nbsp;<?= h($e['filiere_code']) ?><?php endif; ?>
                          <?php if ($e['niveau_nom']): ?>&nbsp;<?= h($e['niveau_nom']) ?><?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <div id="fraisNoResult" class="text-center text-muted py-3 d-none" style="font-size:.85rem">
                      <i class="fas fa-search-minus d-block mb-1"></i>Aucun résultat
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Montants et détails -->
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-bold">Montant dû <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant" id="fraisMontantDu" class="form-control" min="0" required placeholder="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Montant versé <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant_paye" id="fraisMontantVerse" class="form-control" min="0" placeholder="0" value="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Date du versement</label>
              <input type="date" name="date_recette" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Référence</label>
              <input type="text" name="reference" class="form-control" placeholder="N° chèque, transaction…">
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Année académique</label>
              <select name="annee_id" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="alert alert-info mt-3 py-2 mb-0" style="font-size:.83rem">
            <i class="fas fa-print me-1"></i>
            Le reçu sera généré et imprimé automatiquement après l'enregistrement.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Enregistrer &amp; Imprimer le reçu</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if (hasRole(['admin', 'comptable'])): ?>
<!-- ===== MODAL: Autre recette ===== -->
<div class="modal fade" id="autreRecModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:rgba(25,135,84,.07);border-bottom:2px solid #198754">
        <h5 class="modal-title"><i class="fas fa-coins me-2 text-success"></i>Autre recette</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-bold">Source <span class="text-danger">*</span></label>
              <input type="text" name="source" class="form-control" required
                     placeholder="Ex : Subvention, Don, Location de salle, Amendes…">
              <div class="form-text">Précisez la provenance de cette recette.</div>
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">Libellé <span class="text-danger">*</span></label>
              <input type="text" name="libelle" class="form-control" required placeholder="Description de la recette">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Montant (FCFA) <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant" class="form-control" min="0" step="500" required>
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Date</label>
              <input type="date" name="date_recette" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Référence</label>
              <input type="text" name="reference" class="form-control" placeholder="N° référence…">
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">Année académique</label>
              <select name="annee_id" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; // hasRole(['admin','comptable']) ?>

<?php
$extraScripts = <<<'JS'
<script>
// ===== Choice modal → open sub-modals =====
(function () {
  var choiceEl = document.getElementById('choiceModal');
  if (!choiceEl) return;

  function switchModal(targetId) {
    var choiceM = bootstrap.Modal.getInstance(choiceEl);
    if (!choiceM) return;
    choiceEl.addEventListener('hidden.bs.modal', function handler() {
      choiceEl.removeEventListener('hidden.bs.modal', handler);
      var target = document.getElementById(targetId);
      if (target) new bootstrap.Modal(target).show();
    });
    choiceM.hide();
  }

  var btnFrais = document.getElementById('openFraisBtn');
  var btnAutre = document.getElementById('openAutreBtn');
  if (btnFrais) btnFrais.addEventListener('click', function () { switchModal('fraisFormationModal'); });
  if (btnAutre) btnAutre.addEventListener('click', function () { switchModal('autreRecModal'); });
})();

// ===== Student searchable dropdown (frais formation modal) =====
(function () {
  var wrap    = document.getElementById('fraisStudentWrap');
  if (!wrap) return;
  var display = document.getElementById('fraisStudentDisplay');
  var hidden  = document.getElementById('fraisStudentHidden');
  var panel   = document.getElementById('fraisStudentPanel');
  var filter  = document.getElementById('fraisStudentFilter');
  var list    = document.getElementById('fraisStudentList');
  var clear   = document.getElementById('fraisStudentClear');
  var noRes   = document.getElementById('fraisNoResult');

  function open() {
    panel.style.display = '';
    if (filter) { filter.value = ''; filterOpts(''); setTimeout(function () { filter.focus(); }, 30); }
  }
  function close() { panel.style.display = 'none'; }

  function filterOpts(q) {
    var vis = 0;
    list.querySelectorAll('.stu-opt').forEach(function (o) {
      var ok = !q || o.dataset.search.includes(q.toLowerCase());
      o.style.display = ok ? '' : 'none';
      if (ok) vis++;
    });
    if (noRes) noRes.classList.toggle('d-none', vis > 0);
  }

  display.addEventListener('click', open);
  if (filter) filter.addEventListener('input', function () { filterOpts(filter.value.trim()); });

  list.addEventListener('click', function (e) {
    var opt = e.target.closest('.stu-opt');
    if (!opt) return;
    hidden.value  = opt.dataset.id;
    display.value = opt.dataset.label;
    clear.style.display = '';
    close();
  });

  list.addEventListener('mouseover', function (e) { var o = e.target.closest('.stu-opt'); if (o) o.style.background = '#f0f4f8'; });
  list.addEventListener('mouseout',  function (e) { var o = e.target.closest('.stu-opt'); if (o) o.style.background = ''; });

  clear.addEventListener('click', function () {
    hidden.value  = '';
    display.value = '';
    clear.style.display = 'none';
  });

  document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
})();

// ===== Auto-fill montant_paye when montant_du loses focus =====
(function () {
  var du    = document.getElementById('fraisMontantDu');
  var verse = document.getElementById('fraisMontantVerse');
  if (du && verse) {
    du.addEventListener('blur', function () {
      if (!parseFloat(verse.value)) verse.value = this.value;
    });
  }
})();
</script>
JS;

// Re-open the relevant modal if there were validation errors
if (!empty($errors) && $errorForm):
  $targetModal = $errorForm === 'frais_formation' ? 'fraisFormationModal' : 'autreRecModal';
  $extraScripts .= '<script>document.addEventListener("DOMContentLoaded",function(){var m=document.getElementById("' . $targetModal . '");if(m)new bootstrap.Modal(m).show();});</script>';
endif;

include APP_ROOT . '/includes/footer.php';
?>
