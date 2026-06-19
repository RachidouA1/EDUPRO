<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable', 'directeur']);

$db      = getDB();
$ecoleId = getEcoleId();

// Filtres
$anneeId   = (int)($_GET['annee_id']   ?? getActiveAnnee()['id'] ?? 0);
$filiereId = (int)($_GET['filiere_id'] ?? 0);
$niveauId  = (int)($_GET['niveau_id']  ?? 0);
$search    = sanitize($_GET['search']  ?? '');

// Données pour les selects
$filieres = getFilieres();
$annees   = getAnneesAcademiques();
$niveaux  = $filiereId ? getNiveaux($filiereId) : [];

// Construction de la requête
$where  = ["e.statut = 'actif'"];
$params = [];

if ($ecoleId > 0) { $where[] = 'e.ecole_id = ?'; $params[] = $ecoleId; }
if ($filiereId)   { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)    { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($search)      {
    $where[]  = "(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

// Paramètre année pour le JOIN paiements
$pJoinCond = $anneeId ? "p.etudiant_id = e.id AND p.annee_id = ?" : "p.etudiant_id = e.id";
$pParams   = $anneeId ? array_merge($params, [$anneeId]) : $params;
// annee_id doit être en premier dans le JOIN (placeholders positionnels)
if ($anneeId) {
    $pParamsOrdered = [];
    foreach ($params as $p2) $pParamsOrdered[] = $p2;
    // annee_id placeholder is in JOIN condition — rebuild with proper order
    $pJoinCond      = "p.etudiant_id = e.id AND p.annee_id = ?";
    $pParamsOrdered = array_merge([$anneeId], $params);
} else {
    $pParamsOrdered = $params;
}

$whereStr = implode(' AND ', $where);

$sql = "
    SELECT
        e.id, e.matricule, e.nom, e.prenom, e.sexe, e.telephone, e.statut,
        f.nom  AS filiere_nom,
        f.code AS filiere_code,
        n.nom  AS niveau_nom,
        a.libelle AS annee_libelle,
        COALESCE(SUM(p.montant),      0) AS total_du,
        COALESCE(SUM(p.montant_paye), 0) AS total_paye,
        COALESCE(SUM(p.montant),0) - COALESCE(SUM(p.montant_paye),0) AS reste
    FROM etudiants e
    LEFT JOIN filieres f            ON f.id = e.filiere_id
    LEFT JOIN niveaux  n            ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a  ON a.id = e.annee_id
    LEFT JOIN paiements_etudiants p ON $pJoinCond
    WHERE $whereStr
    GROUP BY e.id, e.matricule, e.nom, e.prenom, e.sexe, e.telephone, e.statut,
             f.nom, f.code, n.nom, a.libelle
    HAVING total_du > 0 AND reste > 0
    ORDER BY e.nom, e.prenom
";

$stmt = $db->prepare($sql);
$stmt->execute($pParamsOrdered);
$impayes = $stmt->fetchAll();

$totalReste = array_sum(array_column($impayes, 'reste'));
$totalDu    = array_sum(array_column($impayes, 'total_du'));
$totalPaye  = array_sum(array_column($impayes, 'total_paye'));

// Filtre annee label
$anneeLabel = '';
foreach ($annees as $a) { if ($a['id'] == $anneeId) { $anneeLabel = $a['libelle']; break; } }

$pageTitle  = 'Apprenants non soldés';
$breadcrumb = ['Comptabilité' => APP_URL . '/modules/comptabilite/rapports.php', 'Impayés' => null];
include APP_ROOT . '/includes/header.php';
?>

<style>
@media print {
  .no-print { display: none !important; }
  .card { border: 1px solid #ccc !important; box-shadow: none !important; }
  .badge { border: 1px solid #999; }
  body { font-size: 11pt; }
  .table th { background-color: #1565c0 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
}
</style>

<div class="page-header no-print">
  <h2><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Apprenants non soldés</h2>
</div>

<!-- Filtres -->
<div class="card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold">Année académique</label>
        <select name="annee_id" class="form-select form-select-sm">
          <option value="">— Toutes —</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $a['id'] == $anneeId ? 'selected' : '' ?>>
              <?= h($a['libelle']) ?> <?= $a['actif'] ? '(En cours)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-select form-select-sm">
          <option value="">— Toutes —</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $f['id'] == $filiereId ? 'selected' : '' ?>>
              <?= h($f['code']) ?> – <?= h($f['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Niveau</label>
        <select name="niveau_id" id="niveau_id" class="form-select form-select-sm">
          <option value="">— Tous —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $n['id'] == $niveauId ? 'selected' : '' ?>>
              <?= h($n['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Recherche</label>
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Nom, prénom ou matricule…" value="<?= h($search) ?>">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="fas fa-search"></i>
        </button>
        <a href="<?= APP_URL ?>/modules/comptabilite/impayes.php" class="btn btn-light btn-sm">
          <i class="fas fa-times"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- En-tête impression -->
<div class="d-none d-print-block text-center mb-3">
  <h4 class="fw-bold"><?= h(getParam('etablissement_nom', 'E-EDU PRO')) ?></h4>
  <p class="mb-0">LISTE DES APPRENANTS NON SOLDÉS<?= $anneeLabel ? ' — ' . h($anneeLabel) : '' ?></p>
  <p class="text-muted small">Édité le <?= date('d/m/Y à H:i') ?></p>
</div>

<!-- Résumé -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card border-0 bg-danger bg-opacity-10">
      <div class="card-body text-center py-3">
        <div class="fs-4 fw-bold text-danger"><?= count($impayes) ?></div>
        <div class="text-muted small">Apprenants concernés</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 bg-warning bg-opacity-10">
      <div class="card-body text-center py-3">
        <div class="fs-4 fw-bold text-warning"><?= formatMontant($totalDu) ?></div>
        <div class="text-muted small">Total dû</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 bg-success bg-opacity-10">
      <div class="card-body text-center py-3">
        <div class="fs-4 fw-bold text-danger"><?= formatMontant($totalReste) ?></div>
        <div class="text-muted small">Reste à payer</div>
      </div>
    </div>
  </div>
</div>

<!-- Actions -->
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <span class="text-muted small"><strong><?= count($impayes) ?></strong> apprenant(s) trouvé(s)</span>
  <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-print me-1"></i>Imprimer
    </button>
    <a href="<?= APP_URL ?>/modules/comptabilite/impayes_export.php?<?= h(http_build_query($_GET)) ?>"
       class="btn btn-success btn-sm">
      <i class="fas fa-file-excel me-1"></i>Exporter Excel
    </a>
  </div>
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-bordered mb-0" id="dataTable">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Matricule</th>
            <th>Nom & Prénom</th>
            <th>Filière / Niveau</th>
            <th class="text-end">Total dû</th>
            <th class="text-end">Payé</th>
            <th class="text-end">Reste</th>
            <th class="text-center no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($impayes)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">
                <i class="fas fa-check-circle text-success me-2"></i>Aucun impayé trouvé pour les critères sélectionnés.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($impayes as $i => $e): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><code><?= h($e['matricule']) ?></code></td>
                <td>
                  <strong><?= h($e['nom']) ?></strong> <?= h($e['prenom']) ?>
                  <?php if ($e['telephone']): ?>
                    <br><small class="text-muted"><i class="fas fa-phone fa-xs me-1"></i><?= h($e['telephone']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-primary bg-opacity-75"><?= h($e['filiere_code'] ?? '') ?></span>
                  <?php if ($e['niveau_nom']): ?>
                    <small class="text-muted ms-1"><?= h($e['niveau_nom']) ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= formatMontant($e['total_du']) ?></td>
                <td class="text-end text-success"><?= formatMontant($e['total_paye']) ?></td>
                <td class="text-end fw-bold text-danger"><?= formatMontant($e['reste']) ?></td>
                <td class="text-center no-print">
                  <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $e['id'] ?>"
                     class="btn btn-sm btn-outline-primary" title="Voir les paiements">
                    <i class="fas fa-money-bill-wave"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($impayes)): ?>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="4" class="text-end">TOTAUX</td>
            <td class="text-end"><?= formatMontant($totalDu) ?></td>
            <td class="text-end text-success"><?= formatMontant($totalPaye) ?></td>
            <td class="text-end text-danger"><?= formatMontant($totalReste) ?></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// Recharger les niveaux quand la filière change
document.getElementById('filiere_id')?.addEventListener('change', function () {
  const niv = document.getElementById('niveau_id');
  niv.innerHTML = '<option value="">Chargement...</option>';
  if (!this.value) { niv.innerHTML = '<option value="">— Tous —</option>'; return; }
  fetch(window.APP_URL + '/api/niveaux.php?filiere_id=' + this.value)
    .then(r => r.json())
    .then(data => {
      niv.innerHTML = '<option value="">— Tous —</option>';
      data.forEach(n => {
        const o = document.createElement('option');
        o.value = n.id; o.textContent = n.nom;
        niv.appendChild(o);
      });
    });
});
</script>
JS;
include APP_ROOT . '/includes/footer.php';
?>
