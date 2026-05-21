<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable', 'directeur']);

$db = getDB();

$anneeId = (int)($_GET['annee_id'] ?? getActiveAnnee()['id'] ?? 0);
$mois    = sanitize($_GET['mois']  ?? '');

// Build date filter
$rWhere  = '1=1';
$dWhere  = '1=1';
$rParams = [];
$dParams = [];

if ($anneeId) {
    $rWhere .= ' AND annee_id=?'; $rParams[] = $anneeId;
    $dWhere .= ' AND annee_id=?'; $dParams[] = $anneeId;
}
if ($mois) {
    $rWhere .= ' AND DATE_FORMAT(date_recette,"%Y-%m")=?';  $rParams[] = $mois;
    $dWhere .= ' AND DATE_FORMAT(date_depense,"%Y-%m")=?';  $dParams[] = $mois;
}

// Totals
$totalR = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM recettes WHERE $rWhere");
$totalR->execute($rParams);
$totalRecettes = (float)$totalR->fetchColumn();

$totalD = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE $dWhere AND statut='approuvee'");
$totalD->execute($dParams);
$totalDepenses = (float)$totalD->fetchColumn();

$solde = $totalRecettes - $totalDepenses;

// Recettes by category
$rcStmt = $db->prepare("SELECT categorie, COUNT(*) as nb, SUM(montant) as total FROM recettes WHERE $rWhere GROUP BY categorie ORDER BY total DESC");
$rcStmt->execute($rParams);
$recettesParCat = $rcStmt->fetchAll();

// Dépenses by category
$dcStmt = $db->prepare("SELECT categorie, COUNT(*) as nb, SUM(montant) as total FROM depenses WHERE $dWhere AND statut='approuvee' GROUP BY categorie ORDER BY total DESC");
$dcStmt->execute($dParams);
$depensesParCat = $dcStmt->fetchAll();

// Monthly summary (12 months)
$monthlySummary = [];
for ($i = 11; $i >= 0; $i--) {
    $m    = date('Y-m', strtotime("-$i months"));
    $yr   = substr($m, 0, 4);
    $mn   = substr($m, 5, 2);
    $rs   = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM recettes WHERE YEAR(date_recette)=? AND MONTH(date_recette)=?" . ($anneeId ? " AND annee_id=?" : ""));
    $rp   = $anneeId ? [$yr, $mn, $anneeId] : [$yr, $mn];
    $rs->execute($rp);
    $rTotal = (float)$rs->fetchColumn();

    $ds   = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE YEAR(date_depense)=? AND MONTH(date_depense)=? AND statut='approuvee'" . ($anneeId ? " AND annee_id=?" : ""));
    $dp   = $anneeId ? [$yr, $mn, $anneeId] : [$yr, $mn];
    $ds->execute($dp);
    $dTotal = (float)$ds->fetchColumn();

    $monthlySummary[] = [
        'mois'     => date('M Y', mktime(0, 0, 0, $mn, 1, $yr)),
        'recettes' => $rTotal,
        'depenses' => $dTotal,
        'solde'    => $rTotal - $dTotal,
    ];
}

// Paiements étudiants summary
$payStmt = $db->prepare("SELECT COALESCE(SUM(montant),0) as du, COALESCE(SUM(montant_paye),0) as paye FROM paiements_etudiants" . ($anneeId ? " WHERE annee_id=?" : ""));
$payStmt->execute($anneeId ? [$anneeId] : []);
$paySummary = $payStmt->fetch();

$annees = getAnneesAcademiques();

$pageTitle  = 'Rapports Financiers';
$breadcrumb = ['Comptabilité' => null, 'Rapports' => null];

$chartLabels   = json_encode(array_column($monthlySummary, 'mois'));
$chartRecettes = json_encode(array_column($monthlySummary, 'recettes'));
$chartDepenses = json_encode(array_column($monthlySummary, 'depenses'));
$chartSolde    = json_encode(array_column($monthlySummary, 'solde'));

$rcLabels = json_encode(array_column($recettesParCat, 'categorie'));
$rcVals   = json_encode(array_column($recettesParCat, 'total'));
$dcLabels = json_encode(array_column($depensesParCat, 'categorie'));
$dcVals   = json_encode(array_column($depensesParCat, 'total'));

include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-chart-bar me-2 text-primary"></i>Rapports Financiers</h2>
  <button onclick="window.print()" class="btn btn-outline-secondary no-print"><i class="fas fa-print me-2"></i>Imprimer</button>
</div>

<!-- Filters -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <select name="annee_id" class="form-select">
          <option value="">Toutes années</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <input type="month" name="mois" class="form-control" value="<?= h($mois) ?>" placeholder="Filtrer par mois">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i>Actualiser</button>
        <a href="?" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatMontant($totalRecettes) ?></div><div class="stat-label">Total Recettes</div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatMontant($totalDepenses) ?></div><div class="stat-label">Total Dépenses</div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card <?= $solde >= 0 ? 'stat-teal' : 'stat-red' ?>">
      <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatMontant($solde) ?></div><div class="stat-label">Solde <?= $solde >= 0 ? 'excédentaire' : 'déficitaire' ?></div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.2rem"><?= formatMontant(max(0, $paySummary['du'] - $paySummary['paye'])) ?></div>
        <div class="stat-label">Scolarités impayées</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-line me-2 text-primary"></i>Évolution sur 12 mois</div>
      <div class="card-body"><div class="chart-container"><canvas id="evolutionChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-pie me-2 text-primary"></i>Répartition des dépenses</div>
      <div class="card-body"><div class="chart-container"><canvas id="depPieChart"></canvas></div></div>
    </div>
  </div>
</div>

<!-- Monthly Table -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-table me-2 text-primary"></i>Récapitulatif mensuel (12 mois)</div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr style="background:#0f2d5c;color:#fff">
          <th>Mois</th>
          <th class="text-end">Recettes</th>
          <th class="text-end">Dépenses</th>
          <th class="text-end">Solde</th>
          <th class="text-center">Bilan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($monthlySummary as $ms): ?>
        <tr>
          <td class="fw-600"><?= h($ms['mois']) ?></td>
          <td class="text-end text-success fw-600"><?= formatMontant($ms['recettes']) ?></td>
          <td class="text-end text-danger"><?= formatMontant($ms['depenses']) ?></td>
          <td class="text-end fw-bold <?= $ms['solde'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($ms['solde']) ?></td>
          <td class="text-center">
            <?php if ($ms['recettes'] == 0 && $ms['depenses'] == 0): ?>
              <span class="badge bg-secondary">Aucune activité</span>
            <?php elseif ($ms['solde'] >= 0): ?>
              <span class="badge bg-success">Excédent</span>
            <?php else: ?>
              <span class="badge bg-danger">Déficit</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td>TOTAL</td>
          <td class="text-end text-success"><?= formatMontant(array_sum(array_column($monthlySummary,'recettes'))) ?></td>
          <td class="text-end text-danger"><?= formatMontant(array_sum(array_column($monthlySummary,'depenses'))) ?></td>
          <td class="text-end <?= (array_sum(array_column($monthlySummary,'solde'))) >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= formatMontant(array_sum(array_column($monthlySummary,'solde'))) ?>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Category breakdowns -->
<div class="row g-4">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header text-success"><i class="fas fa-arrow-down me-2"></i>Recettes par catégorie</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Catégorie</th><th class="text-end">Nombre</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php foreach ($recettesParCat as $rc): ?>
            <tr>
              <td><?= ucfirst(h($rc['categorie'])) ?></td>
              <td class="text-end"><?= $rc['nb'] ?></td>
              <td class="text-end fw-bold text-success"><?= formatMontant($rc['total']) ?></td>
              <td class="text-end text-muted fs-sm"><?= $totalRecettes > 0 ? round($rc['total']/$totalRecettes*100,1) : 0 ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header text-danger"><i class="fas fa-arrow-up me-2"></i>Dépenses par catégorie</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Catégorie</th><th class="text-end">Nombre</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php foreach ($depensesParCat as $dc): ?>
            <tr>
              <td><?= ucfirst(h($dc['categorie'])) ?></td>
              <td class="text-end"><?= $dc['nb'] ?></td>
              <td class="text-end fw-bold text-danger"><?= formatMontant($dc['total']) ?></td>
              <td class="text-end text-muted fs-sm"><?= $totalDepenses > 0 ? round($dc['total']/$totalDepenses*100,1) : 0 ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<JS
<script>
// Evolution chart
const evCtx = document.getElementById('evolutionChart')?.getContext('2d');
if (evCtx) new Chart(evCtx, {
  type: 'bar',
  data: {
    labels: {$chartLabels},
    datasets: [
      { label:'Recettes', data:{$chartRecettes}, backgroundColor:'rgba(52,168,83,.7)', borderColor:'#34a853', borderWidth:1 },
      { label:'Dépenses', data:{$chartDepenses}, backgroundColor:'rgba(234,67,53,.7)', borderColor:'#ea4335', borderWidth:1 },
      { label:'Solde',    data:{$chartSolde},    type:'line', borderColor:'#1a73e8', backgroundColor:'rgba(26,115,232,.1)', tension:.4, fill:true, yAxisID:'y1' }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    plugins: { legend:{ position:'top' } },
    scales: {
      y:  { beginAtZero:true, ticks:{ callback: v => (v/1000).toFixed(0)+'k' } },
      y1: { position:'right', grid:{ drawOnChartArea:false }, ticks:{ callback: v => (v/1000).toFixed(0)+'k' } }
    }
  }
});

// Pie chart dépenses
const dpCtx = document.getElementById('depPieChart')?.getContext('2d');
if (dpCtx) new Chart(dpCtx, {
  type:'doughnut',
  data: {
    labels: {$dcLabels},
    datasets:[{ data:{$dcVals}, backgroundColor:['#ea4335','#f57c00','#fbbc04','#34a853','#1a73e8','#9c27b0','#00bcd4','#795548'] }]
  },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:11 } } } } }
});
</script>
JS;

include APP_ROOT . '/includes/footer.php';
?>
