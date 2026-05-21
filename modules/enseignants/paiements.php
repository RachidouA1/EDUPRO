<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable']);

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM enseignants WHERE id = ?");
$stmt->execute([$id]);
$ens = $stmt->fetch();

if (!$ens) {
    setFlash('error', 'Enseignant introuvable.');
    redirect('/modules/enseignants/index.php');
}

$errors = [];
$user   = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } elseif ($_POST['action'] === 'add') {
        $libelle  = sanitize($_POST['libelle']       ?? '');
        $type     = sanitize($_POST['type']          ?? 'salaire');
        $montant  = (float)($_POST['montant']        ?? 0);
        $datePay  = sanitize($_POST['date_paiement'] ?? date('Y-m-d'));
        $mode     = sanitize($_POST['mode_paiement'] ?? 'virement');
        $anneeId  = (int)($_POST['annee_id']         ?? 0);

        if (empty($libelle) || $montant <= 0) {
            $errors[] = 'Libellé et montant obligatoires.';
        } else {
            $db->prepare("INSERT INTO paiements_enseignants (enseignant_id, annee_id, libelle, type, montant, date_paiement, mode_paiement) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id, $anneeId ?: null, $libelle, $type, $montant, $datePay, $mode]);

            // Record as depense
            $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement) VALUES (?,?,?,?,?,?,?)")
               ->execute([$anneeId ?: null, $datePay, $libelle . ' – ' . $ens['nom'].' '.$ens['prenom'], 'salaire', $montant, $ens['nom'].' '.$ens['prenom'], $mode]);

            setFlash('success', 'Paiement enregistré.');
            redirect('/modules/enseignants/paiements.php?id=' . $id);
        }
    } elseif ($_POST['action'] === 'delete') {
        $db->prepare("DELETE FROM paiements_enseignants WHERE id=? AND enseignant_id=?")->execute([(int)$_POST['pay_id'], $id]);
        setFlash('success', 'Paiement supprimé.');
        redirect('/modules/enseignants/paiements.php?id=' . $id);
    }
}

$paiements = $db->prepare("SELECT p.*, a.libelle as annee_libelle FROM paiements_enseignants p LEFT JOIN annees_academiques a ON a.id=p.annee_id WHERE p.enseignant_id=? ORDER BY p.date_paiement DESC");
$paiements->execute([$id]);
$allPaiements = $paiements->fetchAll();

$totalPaye = array_sum(array_column($allPaiements, 'montant'));
$annees    = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

$pageTitle = 'Paiements – ' . h($ens['prenom'].' '.$ens['nom']);
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

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Libellé</th><th>Type</th><th>Année</th><th>Montant</th><th>Date</th><th>Mode</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($allPaiements)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">Aucun paiement</td></tr>
        <?php endif; ?>
        <?php foreach ($allPaiements as $p): ?>
        <tr>
          <td class="fw-600 fs-sm"><?= h($p['libelle']) ?></td>
          <td><span class="badge bg-primary"><?= ucfirst(h($p['type'])) ?></span></td>
          <td class="text-muted fs-sm"><?= h($p['annee_libelle'] ?? '-') ?></td>
          <td class="fw-600 text-success"><?= formatMontant($p['montant']) ?></td>
          <td class="text-muted fs-sm"><?= formatDate($p['date_paiement']) ?></td>
          <td class="fs-sm"><?= ucfirst(h($p['mode_paiement'])) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="pay_id" value="<?= $p['id'] ?>">
              <button type="button" class="btn btn-icon btn-sm btn-outline-danger" onclick="confirmDelete(this.form,'ce paiement')"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="addPayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Nouveau paiement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Libellé *</label>
              <input type="text" name="libelle" class="form-control" placeholder="Ex: Salaire Octobre 2024..." required>
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
              <label class="form-label">Montant (FCFA) *</label>
              <input type="number" name="montant" class="form-control" min="0" step="500" value="<?= $ens['salaire_base'] ?>" required>
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
            <div class="col-12">
              <label class="form-label">Année académique</label>
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
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
