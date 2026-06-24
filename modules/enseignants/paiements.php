<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable']);

$db      = getDB();
$ecoleId = getEcoleId();
$id      = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM enseignants WHERE id = ?");
$stmt->execute([$id]);
$ens = $stmt->fetch();

if (!$ens) {
    setFlash('error', 'Enseignant introuvable.');
    redirect('/modules/enseignants/index.php');
}

// Migration : ajout colonne matiere_id si absente
try { $db->exec("ALTER TABLE paiements_enseignants ADD COLUMN matiere_id INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE paiements_enseignants ADD INDEX idx_pe_matiere (matiere_id)"); } catch (PDOException $e) {}

$errors = [];
$user   = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } elseif ($_POST['action'] === 'add') {
        $libelle   = sanitize($_POST['libelle']       ?? '');
        $type      = sanitize($_POST['type']          ?? 'salaire');
        $montant   = (float)($_POST['montant']        ?? 0);
        $datePay   = sanitize($_POST['date_paiement'] ?? date('Y-m-d'));
        $mode      = sanitize($_POST['mode_paiement'] ?? 'virement');
        $anneeId   = (int)($_POST['annee_id']         ?? 0);
        $matiereId = (int)($_POST['matiere_id']       ?? 0) ?: null;

        if (empty($libelle) || $montant <= 0) {
            $errors[] = 'Libellé et montant obligatoires.';
        } elseif ($matiereId) {
            // Contrôle : vérifier si l'enseignant a déjà été payé pour cette matière cette année
            if ($anneeId) {
                $chk = $db->prepare("SELECT COUNT(*) FROM paiements_enseignants WHERE enseignant_id=? AND matiere_id=? AND annee_id=?");
                $chk->execute([$id, $matiereId, $anneeId]);
            } else {
                $chk = $db->prepare("SELECT COUNT(*) FROM paiements_enseignants WHERE enseignant_id=? AND matiere_id=? AND annee_id IS NULL");
                $chk->execute([$id, $matiereId]);
            }
            if ((int)$chk->fetchColumn() > 0) {
                $matNomStmt = $db->prepare("SELECT CONCAT(code,' – ',nom) FROM matieres WHERE id=?");
                $matNomStmt->execute([$matiereId]);
                $matNom = $matNomStmt->fetchColumn() ?: 'cette matière';
                $errors[] = 'Cet enseignant a déjà été payé pour la matière « ' . $matNom . ' » sur cette année académique. Supprimez l\'ancien paiement avant d\'en créer un nouveau.';
            }
        }

        if (empty($errors)) {
            $db->prepare("INSERT INTO paiements_enseignants (enseignant_id, annee_id, libelle, type, montant, date_paiement, mode_paiement, matiere_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$id, $anneeId ?: null, $libelle, $type, $montant, $datePay, $mode, $matiereId]);

            // Enregistrer comme dépense
            if ($ecoleId > 0) {
                $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement, ecole_id) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $datePay, $libelle . ' – ' . $ens['nom'].' '.$ens['prenom'], 'salaire', $montant, $ens['nom'].' '.$ens['prenom'], $mode, $ecoleId]);
            } else {
                $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $datePay, $libelle . ' – ' . $ens['nom'].' '.$ens['prenom'], 'salaire', $montant, $ens['nom'].' '.$ens['prenom'], $mode]);
            }

            setFlash('success', 'Paiement enregistré.');
            redirect('/modules/enseignants/paiements.php?id=' . $id);
        }
    } elseif ($_POST['action'] === 'delete') {
        $db->prepare("DELETE FROM paiements_enseignants WHERE id=? AND enseignant_id=?")->execute([(int)$_POST['pay_id'], $id]);
        setFlash('success', 'Paiement supprimé.');
        redirect('/modules/enseignants/paiements.php?id=' . $id);
    }
}

// Historique des paiements (avec nom de matière)
$paiements = $db->prepare("
    SELECT p.*, a.libelle as annee_libelle,
           m.code as matiere_code, m.nom as matiere_nom
    FROM paiements_enseignants p
    LEFT JOIN annees_academiques a ON a.id = p.annee_id
    LEFT JOIN matieres m ON m.id = p.matiere_id
    WHERE p.enseignant_id = ?
    ORDER BY p.date_paiement DESC
");
$paiements->execute([$id]);
$allPaiements = $paiements->fetchAll();

$totalPaye = array_sum(array_column($allPaiements, 'montant'));
$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

// Toutes les matières pour le dropdown
if ($ecoleId > 0) {
    $matStmt = $db->prepare("
        SELECT m.id, m.code, m.nom, f.code as filiere_code
        FROM matieres m
        LEFT JOIN filieres f ON f.id = m.filiere_id
        WHERE m.actif = 1 AND (f.ecole_id = ? OR f.ecole_id IS NULL)
        ORDER BY f.nom, m.nom
    ");
    $matStmt->execute([$ecoleId]);
} else {
    $matStmt = $db->query("
        SELECT m.id, m.code, m.nom, f.code as filiere_code
        FROM matieres m
        LEFT JOIN filieres f ON f.id = m.filiere_id
        WHERE m.actif = 1
        ORDER BY f.nom, m.nom
    ");
}
$allMatieres = $matStmt->fetchAll();

// Combinaisons (matiere_id → [annee_id, ...]) déjà payées pour cet enseignant
$paidStmt = $db->prepare("
    SELECT matiere_id, annee_id
    FROM paiements_enseignants
    WHERE enseignant_id = ? AND matiere_id IS NOT NULL
");
$paidStmt->execute([$id]);
$paidCombos = [];
foreach ($paidStmt->fetchAll() as $row) {
    $key = (int)$row['matiere_id'];
    $paidCombos[$key][] = (int)($row['annee_id'] ?? 0);
}

$pageTitle  = 'Paiements – ' . h($ens['prenom'].' '.$ens['nom']);
$breadcrumb = ['Enseignants' => APP_URL.'/modules/enseignants/index.php', 'Paiements' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-money-bill me-2 text-primary"></i>Paiements Enseignant</h2>
    <div class="text-muted fs-sm"><?= h($ens['prenom'].' '.$ens['nom']) ?> – <code><?= h($ens['matricule']) ?></code></div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPayModal">
    <i class="fas fa-plus me-2"></i>Nouveau paiement
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant($totalPaye) ?></div><div class="stat-label">Total payé</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant($ens['salaire_base']) ?></div><div class="stat-label">Salaire de base mensuel</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($allPaiements) ?></div><div class="stat-label">Transactions</div></div>
    </div>
  </div>
</div>

<?php showFlash(); ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="fas fa-exclamation-circle"></i> <?= h($err) ?>
  </div>
<?php endforeach; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Libellé</th>
          <th>Type</th>
          <th>Matière</th>
          <th>Année</th>
          <th>Montant</th>
          <th>Date</th>
          <th>Mode</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($allPaiements)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">Aucun paiement</td></tr>
        <?php endif; ?>
        <?php foreach ($allPaiements as $p): ?>
        <tr>
          <td class="fw-600 fs-sm"><?= h($p['libelle']) ?></td>
          <td><span class="badge bg-primary"><?= ucfirst(h($p['type'])) ?></span></td>
          <td class="fs-sm">
            <?php if ($p['matiere_code']): ?>
              <span class="badge bg-info text-dark" title="<?= h($p['matiere_nom']) ?>">
                <?= h($p['matiere_code']) ?>
              </span>
              <span class="text-muted ms-1"><?= h($p['matiere_nom']) ?></span>
            <?php else: ?>
              <span class="text-muted">–</span>
            <?php endif; ?>
          </td>
          <td class="text-muted fs-sm"><?= h($p['annee_libelle'] ?? '-') ?></td>
          <td class="fw-600 text-success"><?= formatMontant($p['montant']) ?></td>
          <td class="text-muted fs-sm"><?= formatDate($p['date_paiement']) ?></td>
          <td class="fs-sm"><?= ucfirst(h($p['mode_paiement'])) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="pay_id" value="<?= $p['id'] ?>">
              <button type="button" class="btn btn-icon btn-sm btn-outline-danger" onclick="confirmDelete(this.form,'ce paiement')">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Nouveau paiement -->
<div class="modal fade" id="addPayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-primary"></i>Nouveau paiement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="payForm">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label">Libellé <span class="text-danger">*</span></label>
              <input type="text" name="libelle" class="form-control" placeholder="Ex: Vacation Anatomie S1 2024…" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <option value="salaire">Salaire</option>
                <option value="prime">Prime</option>
                <option value="vacation">Vacation</option>
                <option value="autre">Autre</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Montant (FCFA) <span class="text-danger">*</span></label>
              <input type="number" name="montant" class="form-control" min="0" step="500" value="<?= $ens['salaire_base'] ?>" required>
            </div>

            <div class="col-12">
              <label class="form-label">Année académique</label>
              <select name="annee_id" id="sel_annee" class="form-select" onchange="checkDoublon()">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Matière liée <small class="text-muted">(facultatif — pour paiements de vacation/cours)</small></label>
              <select name="matiere_id" id="sel_matiere" class="form-select" onchange="checkDoublon()">
                <option value="">— Aucune matière spécifique —</option>
                <?php foreach ($allMatieres as $m): ?>
                  <option value="<?= $m['id'] ?>"
                          data-paid="<?= isset($paidCombos[(int)$m['id']]) ? htmlspecialchars(json_encode($paidCombos[(int)$m['id']]), ENT_QUOTES) : '[]' ?>">
                    <?= h($m['filiere_code'] ? '['.$m['filiere_code'].'] ' : '') ?><?= h($m['code']) ?> – <?= h($m['nom']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <!-- Alerte doublon -->
              <div id="doublon_alert" class="alert alert-warning d-flex align-items-center gap-2 mt-2 py-2" style="display:none!important">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="doublon_msg"></span>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Date</label>
              <input type="date" name="date_paiement" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label">Mode</label>
              <select name="mode_paiement" class="form-select">
                <option value="virement">Virement</option>
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
              </select>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" id="btn_submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>Enregistrer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Combinaisons matiere_id → [annee_id, ...] déjà payées
const paidCombos = <?= json_encode($paidCombos, JSON_UNESCAPED_UNICODE) ?>;

function checkDoublon() {
  const selMat   = document.getElementById('sel_matiere');
  const selAnnee = document.getElementById('sel_annee');
  const alert    = document.getElementById('doublon_alert');
  const msg      = document.getElementById('doublon_msg');
  const btn      = document.getElementById('btn_submit');

  const matiereId = parseInt(selMat.value) || 0;
  const anneeId   = parseInt(selAnnee.value) || 0;

  if (!matiereId) {
    alert.style.display = 'none';
    btn.disabled = false;
    return;
  }

  const paidYears = paidCombos[matiereId] || [];
  const dejaPaye  = paidYears.includes(anneeId) || (anneeId === 0 && paidYears.includes(0));

  if (dejaPaye) {
    const optMat   = selMat.options[selMat.selectedIndex];
    const optAnnee = selAnnee.options[selAnnee.selectedIndex];
    msg.textContent = 'Cet enseignant a déjà été payé pour « '
      + (optMat ? optMat.text.trim() : 'cette matière') + ' »'
      + (optAnnee ? ' sur l\'année ' + optAnnee.text.trim() : '') + '.'
      + ' Veuillez supprimer l\'ancien paiement si vous souhaitez le remplacer.';
    alert.style.removeProperty('display');
    btn.disabled = true;
  } else {
    alert.style.display = 'none';
    btn.disabled = false;
  }
}

// Vérifier au chargement si la modal s'ouvre avec une erreur (form re-ouvert côté serveur)
<?php if (!empty($errors)): ?>
document.addEventListener('DOMContentLoaded', function() {
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addPayModal'));
  modal.show();
});
<?php endif; ?>
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
