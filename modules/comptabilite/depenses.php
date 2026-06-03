<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'comptable']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$role    = $user['role'];
$errors  = [];

// Ensure columns exist (migration guard)
try { $db->exec("ALTER TABLE depenses ADD COLUMN statut ENUM('en_attente','approuvee','rejetee') NOT NULL DEFAULT 'approuvee' AFTER mode_paiement"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE depenses ADD COLUMN approuve_par INT NULL AFTER statut"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE depenses ADD COLUMN approuve_at DATETIME NULL AFTER approuve_par"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE depenses ADD COLUMN note_rejet TEXT NULL AFTER approuve_at"); } catch (PDOException $e) {}

// ── ACTIONS ──────────────────────────────────────────────────────────────────

// Ajouter une dépense (admin → approuvée directement ; comptable → en attente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $libelle      = sanitize($_POST['libelle']       ?? '');
        $categorie    = sanitize($_POST['categorie']     ?? 'autre');
        $montant      = (float)($_POST['montant']        ?? 0);
        $date         = sanitize($_POST['date_depense']  ?? date('Y-m-d'));
        $mode         = sanitize($_POST['mode_paiement'] ?? 'especes');
        $beneficiaire = sanitize($_POST['beneficiaire']  ?? '');
        $anneeId      = (int)($_POST['annee_id']         ?? 0);
        $justification = sanitize($_POST['justification'] ?? '');

        if (empty($libelle) || $montant <= 0) {
            $errors[] = 'Libellé et montant obligatoires.';
        } else {
            if (in_array($role, ['admin', 'directeur'])) {
                // Approbation immédiate
                $statut     = 'approuvee';
                $approuvePar = $user['id'];
                $approuveAt  = date('Y-m-d H:i:s');
            } else {
                // Comptable → demande en attente
                $statut     = 'en_attente';
                $approuvePar = null;
                $approuveAt  = null;
            }

            if ($ecoleId > 0) {
                $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement, notes, statut, approuve_par, approuve_at, created_by, ecole_id)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $date, $libelle, $categorie, $montant,
                              $beneficiaire ?: null, $mode, $justification ?: null,
                              $statut, $approuvePar, $approuveAt, $user['id'], $ecoleId]);
            } else {
                $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement, notes, statut, approuve_par, approuve_at, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $date, $libelle, $categorie, $montant,
                              $beneficiaire ?: null, $mode, $justification ?: null,
                              $statut, $approuvePar, $approuveAt, $user['id']]);
            }

            if ($statut === 'en_attente') {
                setFlash('success', 'Demande de dépense soumise. En attente d\'autorisation de l\'administrateur.');
            } else {
                setFlash('success', 'Dépense enregistrée et approuvée.');
            }
            redirect('/modules/comptabilite/depenses.php');
        }
    }
}

// Approuver une dépense (admin/directeur uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approuver' && in_array($role, ['admin', 'directeur'])) {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $db->prepare("UPDATE depenses SET statut='approuvee', approuve_par=?, approuve_at=NOW(), note_rejet=NULL WHERE id=?")
           ->execute([$user['id'], (int)$_POST['dep_id']]);
        setFlash('success', 'Dépense approuvée.');
        redirect('/modules/comptabilite/depenses.php');
    }
}

// Rejeter une dépense (admin/directeur uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rejeter' && in_array($role, ['admin', 'directeur'])) {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $noteRejet = sanitize($_POST['note_rejet'] ?? '');
        $db->prepare("UPDATE depenses SET statut='rejetee', approuve_par=?, approuve_at=NOW(), note_rejet=? WHERE id=?")
           ->execute([$user['id'], $noteRejet ?: null, (int)$_POST['dep_id']]);
        setFlash('warning', 'Dépense rejetée.');
        redirect('/modules/comptabilite/depenses.php');
    }
}

// Supprimer (admin : toutes ; comptable : uniquement ses propres demandes en attente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $depId = (int)$_POST['dep_id'];
        if (in_array($role, ['admin', 'directeur'])) {
            $db->prepare("DELETE FROM depenses WHERE id=?")->execute([$depId]);
            setFlash('success', 'Dépense supprimée.');
        } elseif ($role === 'comptable') {
            // Le comptable ne peut supprimer que ses propres demandes en attente
            $db->prepare("DELETE FROM depenses WHERE id=? AND created_by=? AND statut='en_attente'")
               ->execute([$depId, $user['id']]);
            setFlash('success', 'Demande annulée.');
        }
        redirect('/modules/comptabilite/depenses.php');
    }
}

// ── FILTRES ───────────────────────────────────────────────────────────────────

$anneeId    = (int)($_GET['annee_id'] ?? getActiveAnnee()['id'] ?? 0);
$cat        = sanitize($_GET['cat']   ?? '');
$mois       = sanitize($_GET['mois']  ?? '');
$filtreStatut = sanitize($_GET['statut'] ?? '');

$where  = ['1=1'];
$params = [];
if ($anneeId)      { $where[] = 'd.annee_id=?';                             $params[] = $anneeId; }
if ($cat)          { $where[] = 'd.categorie=?';                             $params[] = $cat; }
if ($mois)         { $where[] = 'DATE_FORMAT(d.date_depense,"%Y-%m")=?';    $params[] = $mois; }
if ($filtreStatut) { $where[] = 'd.statut=?';                                $params[] = $filtreStatut; }
if ($ecoleId > 0)  { $where[] = 'd.ecole_id=?';                             $params[] = $ecoleId; }

$stmt = $db->prepare("
    SELECT d.*, a.libelle as annee_libelle,
           CONCAT(u.prenom,' ',u.nom) as createur,
           CONCAT(ap.prenom,' ',ap.nom) as approbateur
    FROM depenses d
    LEFT JOIN annees_academiques a ON a.id=d.annee_id
    LEFT JOIN users u  ON u.id=d.created_by
    LEFT JOIN users ap ON ap.id=d.approuve_par
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.date_depense DESC, d.id DESC
");
$stmt->execute($params);
$depenses = $stmt->fetchAll();

// Totaux (uniquement les dépenses approuvées)
$totalApprouve = array_sum(array_column(array_filter($depenses, fn($d) => $d['statut'] === 'approuvee'), 'montant'));

// Compteur de demandes en attente (pour l'alerte admin)
$nbAttSql = "SELECT COUNT(*) FROM depenses WHERE statut='en_attente'";
$nbAttParams = [];
if ($ecoleId > 0) { $nbAttSql .= " AND ecole_id=?"; $nbAttParams[] = $ecoleId; }
$nbAttStmt = $db->prepare($nbAttSql);
$nbAttStmt->execute($nbAttParams);
$nbEnAttente = (int)$nbAttStmt->fetchColumn();

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

$pageTitle  = 'Dépenses';
$breadcrumb = ['Comptabilité' => null, 'Dépenses' => null];
$extraHead  = '<style>
@media screen { .print-only { display: none !important; } }
@media print  { .print-only { display: block !important; } }
</style>';
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header no-print">
  <h2><i class="fas fa-arrow-circle-up me-2 text-danger"></i>Gestion des Dépenses</h2>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addDepModal">
    <i class="fas fa-plus me-2"></i><?= $role === 'comptable' ? 'Soumettre une demande' : 'Nouvelle dépense' ?>
  </button>
</div>

<?php if ($role === 'comptable'): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3 no-print">
  <i class="fas fa-info-circle"></i>
  <span>En tant que comptable, vos demandes de dépenses doivent être <strong>autorisées par l'administrateur</strong> avant d'être comptabilisées.</span>
</div>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'directeur']) && $nbEnAttente > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3 no-print">
  <i class="fas fa-clock"></i>
  <strong><?= $nbEnAttente ?> demande(s) de dépense en attente</strong> d'autorisation.
  <a href="?statut=en_attente" class="ms-2 btn btn-sm btn-warning">Voir les demandes</a>
</div>
<?php endif; ?>

<!-- Statistiques -->
<div class="row g-3 mb-4 no-print">
  <div class="col-md-4">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-wallet"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.3rem"><?= formatMontant($totalApprouve) ?></div>
        <div class="stat-label">Total approuvé (filtre)</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($depenses) ?></div><div class="stat-label">Transactions</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <?php
      $tmSql = "SELECT COALESCE(SUM(montant),0) FROM depenses WHERE statut='approuvee' AND MONTH(date_depense)=MONTH(NOW()) AND YEAR(date_depense)=YEAR(NOW())";
      $tmParams = [];
      if ($ecoleId > 0) { $tmSql .= " AND ecole_id=?"; $tmParams[] = $ecoleId; }
      $tmStmt = $db->prepare($tmSql); $tmStmt->execute($tmParams);
      $thisMonth = $tmStmt->fetchColumn();
    ?>
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatMontant($thisMonth) ?></div><div class="stat-label">Ce mois (approuvé)</div></div>
    </div>
  </div>
</div>

<!-- Filtres -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <select name="annee_id" class="form-select form-select-sm">
          <option value="">Toutes années</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="cat" class="form-select form-select-sm">
          <option value="">Toutes catégories</option>
          <?php foreach (['salaire','fournitures','equipement','loyer','electricite','eau','internet','autre'] as $c): ?>
            <option value="<?= $c ?>" <?= $cat===$c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="month" name="mois" class="form-control form-control-sm" value="<?= h($mois) ?>">
      </div>
      <div class="col-md-2">
        <select name="statut" class="form-select form-select-sm">
          <option value="">Tous statuts</option>
          <option value="en_attente"  <?= $filtreStatut==='en_attente'  ? 'selected':'' ?>>En attente</option>
          <option value="approuvee"   <?= $filtreStatut==='approuvee'   ? 'selected':'' ?>>Approuvée</option>
          <option value="rejetee"     <?= $filtreStatut==='rejetee'     ? 'selected':'' ?>>Rejetée</option>
        </select>
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search"></i> Filtrer</button>
        <a href="?" class="btn btn-light btn-sm"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger no-print"><?= h($e) ?></div><?php endforeach; ?>

<?php
$_pParts = [];
if ($anneeId) { foreach ($annees as $_a) { if ($_a['id'] == $anneeId) { $_pParts[] = h($_a['libelle']); break; } } }
if ($cat)          $_pParts[] = ucfirst($cat);
if ($mois)         $_pParts[] = date('m/Y', strtotime($mois . '-01'));
if ($filtreStatut) { $_sl = ['en_attente'=>'En attente','approuvee'=>'Approuvée','rejetee'=>'Rejetée']; $_pParts[] = $_sl[$filtreStatut] ?? $filtreStatut; }
$_pFiltreStr = $_pParts ? implode(' · ', $_pParts) : 'Aucun filtre';
?>
<div class="print-only mb-3">
  <div class="text-center">
    <div style="font-size:16pt;font-weight:700;letter-spacing:.3px">École Privée de Santé Ibn Rochd (EPSI)</div>
    <div style="font-size:9pt;color:#555;margin-bottom:5px">Tahoua, Région de Tahoua – Niger</div>
    <div style="font-size:13pt;font-weight:600;text-decoration:underline;margin-bottom:4px">Liste des Dépenses</div>
    <div style="font-size:9pt">Filtre&nbsp;: <?= $_pFiltreStr ?> &nbsp;|&nbsp; Édité le <?= date('d/m/Y à H:i') ?></div>
    <div style="font-size:9pt">Total approuvé&nbsp;: <strong><?= formatMontant($totalApprouve) ?></strong> &nbsp;|&nbsp; <?= count($depenses) ?> entrée(s)</div>
  </div>
  <hr style="border-top:2px solid #333;margin-top:8px;margin-bottom:0">
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><strong><?= count($depenses) ?></strong> entrée(s) – Total approuvé : <strong class="text-danger"><?= formatMontant($totalApprouve) ?></strong></span>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print"><i class="fas fa-print"></i></button>
  </div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr>
          <th>Date</th>
          <th>Libellé</th>
          <th>Catégorie</th>
          <th>Bénéficiaire</th>
          <th>Montant</th>
          <th>Mode</th>
          <th>Statut</th>
          <?php if (in_array($role, ['admin', 'directeur'])): ?><th>Demandé par</th><?php endif; ?>
          <th class="no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($depenses)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">Aucune dépense</td></tr>
        <?php endif; ?>
        <?php foreach ($depenses as $d): ?>
        <?php
          $statutBadge = [
            'en_attente' => ['warning', 'fa-clock',        'En attente'],
            'approuvee'  => ['success', 'fa-check-circle', 'Approuvée'],
            'rejetee'    => ['danger',  'fa-times-circle',  'Rejetée'],
          ][$d['statut']] ?? ['secondary', 'fa-question', $d['statut']];
          $rowClass = $d['statut'] === 'en_attente' ? 'table-warning' : ($d['statut'] === 'rejetee' ? 'table-danger bg-opacity-25' : '');
        ?>
        <tr class="<?= $rowClass ?>">
          <td class="text-muted fs-sm"><?= formatDate($d['date_depense']) ?></td>
          <td class="fw-600">
            <?= h($d['libelle']) ?>
            <?php if ($d['note_rejet']): ?>
              <div class="text-danger fs-sm mt-1"><i class="fas fa-comment-slash me-1"></i><?= h($d['note_rejet']) ?></div>
            <?php endif; ?>
            <?php if ($d['notes']): ?>
              <div class="text-muted fs-sm mt-1"><i class="fas fa-sticky-note me-1"></i><?= h($d['notes']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-danger bg-opacity-75"><?= ucfirst(h($d['categorie'])) ?></span></td>
          <td class="text-muted fs-sm"><?= h($d['beneficiaire'] ?? '-') ?></td>
          <td class="fw-bold text-danger"><?= formatMontant($d['montant']) ?></td>
          <td class="fs-sm"><?= ucfirst(h($d['mode_paiement'])) ?></td>
          <td>
            <span class="badge bg-<?= $statutBadge[0] ?>">
              <i class="fas <?= $statutBadge[1] ?> me-1"></i><?= $statutBadge[2] ?>
            </span>
            <?php if ($d['statut'] === 'approuvee' && $d['approbateur']): ?>
              <div class="text-muted" style="font-size:.72rem">par <?= h($d['approbateur']) ?></div>
            <?php endif; ?>
          </td>
          <?php if (in_array($role, ['admin', 'directeur'])): ?>
          <td class="fs-sm text-muted"><?= h($d['createur'] ?? '-') ?></td>
          <?php endif; ?>
          <td class="no-print">
            <div class="d-flex gap-1 flex-wrap">
              <?php if (in_array($role, ['admin', 'directeur']) && $d['statut'] === 'en_attente'): ?>
                <!-- Bouton Approuver -->
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
                  <input type="hidden" name="action" value="approuver">
                  <input type="hidden" name="dep_id" value="<?= $d['id'] ?>">
                  <button type="submit" class="btn btn-icon btn-sm btn-success" title="Approuver"
                          onclick="return confirm('Approuver cette dépense ?')">
                    <i class="fas fa-check"></i>
                  </button>
                </form>
                <!-- Bouton Rejeter -->
                <button type="button" class="btn btn-icon btn-sm btn-warning"
                        title="Rejeter"
                        onclick="showRejectModal(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['libelle'], JSON_HEX_TAG|JSON_HEX_AMP), ENT_COMPAT) ?>)">
                  <i class="fas fa-times"></i>
                </button>
              <?php endif; ?>

              <?php
                $canDelete = in_array($role, ['admin', 'directeur'])
                          || ($role === 'comptable' && $d['created_by'] == $user['id'] && $d['statut'] === 'en_attente');
              ?>
              <?php if ($canDelete): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="dep_id" value="<?= $d['id'] ?>">
                  <button type="button" class="btn btn-icon btn-sm btn-outline-danger"
                          onclick="confirmDelete(this.form,'<?= $role==='comptable' ? 'cette demande' : 'cette dépense' ?>')">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal : Rejeter -->
<?php if (in_array($role, ['admin', 'directeur'])): ?>
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-25">
        <h6 class="modal-title"><i class="fas fa-times-circle me-2 text-warning"></i>Rejeter la demande</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="rejeter">
        <input type="hidden" name="dep_id" id="rejectDepId">
        <div class="modal-body">
          <p class="text-muted fs-sm mb-2">Dépense : <strong id="rejectDepLabel"></strong></p>
          <label class="form-label">Motif du rejet <span class="text-muted">(optionnel)</span></label>
          <textarea name="note_rejet" class="form-control" rows="3" placeholder="Expliquer le motif..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-warning btn-sm">Rejeter</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal : Nouvelle dépense / Demande -->
<div class="modal fade" id="addDepModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-plus me-2"></i>
          <?= $role === 'comptable' ? 'Soumettre une demande de dépense' : 'Nouvelle dépense' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <?php if ($role === 'comptable'): ?>
      <div class="alert alert-info m-3 mb-0 py-2 fs-sm">
        <i class="fas fa-info-circle me-1"></i>
        Votre demande sera soumise à l'administrateur pour autorisation avant d'être enregistrée.
      </div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Libellé *</label>
              <input type="text" name="libelle" class="form-control" required placeholder="Ex: Facture électricité Janvier...">
            </div>
            <div class="col-md-6">
              <label class="form-label">Catégorie</label>
              <select name="categorie" class="form-select">
                <?php foreach (['salaire','fournitures','equipement','loyer','electricite','eau','internet','autre'] as $c): ?>
                  <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Montant *</label>
              <input type="number" name="montant" class="form-control" min="0" step="500" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date</label>
              <input type="date" name="date_depense" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Mode</label>
              <select name="mode_paiement" class="form-select">
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Bénéficiaire</label>
              <input type="text" name="beneficiaire" class="form-control" placeholder="Nom du bénéficiaire">
            </div>
            <div class="col-md-6">
              <label class="form-label">Année académique</label>
              <select name="annee_id" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Justification</label>
              <textarea name="justification" class="form-control" rows="2"
                        placeholder="Raison de la dépense (optionnel)"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-danger">
            <i class="fas fa-<?= $role === 'comptable' ? 'paper-plane' : 'save' ?> me-2"></i>
            <?= $role === 'comptable' ? 'Soumettre la demande' : 'Enregistrer' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showRejectModal(id, label) {
  document.getElementById('rejectDepId').value    = id;
  document.getElementById('rejectDepLabel').textContent = label;
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
