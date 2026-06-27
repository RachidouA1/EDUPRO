<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'directeur', 'enseignant', 'comptable', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$isCoord = hasRole('coordinateur');

// Filters
$search    = sanitize($_GET['search']   ?? '');
$filiereId = (int)($_GET['filiere_id']  ?? 0);
$niveauId  = (int)($_GET['niveau_id']   ?? 0);
$anneeId   = (int)($_GET['annee_id']    ?? 0);
$statut    = sanitize($_GET['statut']   ?? '');
$sexe      = sanitize($_GET['sexe']     ?? '');

// Build query
$where  = $ecoleId > 0 ? ['e.ecole_id = ?'] : ['1=1'];
$params = $ecoleId > 0 ? [$ecoleId] : [];

if ($isCoord) {
    $where[] = coordSectionWhere('e', $params);
}
if ($search) {
    $where[]  = "(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ? OR e.telephone LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($filiereId) { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)  { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($anneeId)   { $where[] = 'e.annee_id = ?';   $params[] = $anneeId; }
if ($statut)    { $where[] = 'e.statut = ?';      $params[] = $statut; }
if ($sexe)      { $where[] = 'e.sexe = ?';        $params[] = $sexe; }

$whereStr = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT e.*, f.nom as filiere_nom, f.code as filiere_code, n.nom as niveau_nom, a.libelle as annee_libelle
    FROM etudiants e
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    WHERE $whereStr
    ORDER BY e.nom, e.prenom
");
$stmt->execute($params);
$etudiants = $stmt->fetchAll();
$total = count($etudiants);

$filieres = $isCoord
    ? array_map(fn($s) => ['id'=>$s['filiere_id'],'nom'=>$s['filiere_nom'],'code'=>$s['filiere_code']], getCoordinateurSections())
    : getFilieres();
// Deduplicate filières for coordinator
if ($isCoord) {
    $seen = []; $filieres = array_filter($filieres, function($f) use (&$seen) {
        if (isset($seen[$f['id']])) return false;
        return $seen[$f['id']] = true;
    });
    $filieres = array_values($filieres);
}
$niveaux  = getNiveaux();
$annees   = getAnneesAcademiques();

$pageTitle = 'Étudiants';
$breadcrumb = ['Étudiants' => null];
include APP_ROOT . '/includes/header.php';
?>

<?php
$ecole        = getCurrentEcole();
$nomEcole     = $ecole['nom']      ?? getParam('etablissement_nom', 'E-EDU PRO');
$adresseEcole = $ecole['adresse']  ?? getParam('etablissement_adresse', '');
$villeEcole   = $ecole['ville']    ?? getParam('etablissement_ville', '');
$telEcole     = $ecole['telephone']?? getParam('etablissement_telephone', '');
$logoPath     = $ecole['logo_path']?? '';
?>

<!-- En-tête imprimable (masqué à l'écran) -->
<div class="print-only text-center mb-3" style="border-bottom:2px solid #1565c0;padding-bottom:12px;">
  <?php if ($logoPath): ?>
  <img src="<?= APP_URL . '/' . h($logoPath) ?>" alt="Logo" style="height:72px;object-fit:contain;display:block;margin:0 auto 8px;">
  <?php endif; ?>
  <div style="font-size:1.3rem;font-weight:700;letter-spacing:.5px;"><?= h($nomEcole) ?></div>
  <?php if ($adresseEcole || $villeEcole): ?>
  <div style="font-size:.9rem;color:#555;"><?= h(trim($adresseEcole . ($villeEcole ? ' – ' . $villeEcole : ''))) ?></div>
  <?php endif; ?>
  <?php if ($telEcole): ?>
  <div style="font-size:.85rem;color:#555;">Tél : <?= h($telEcole) ?></div>
  <?php endif; ?>
  <div style="font-size:1.1rem;font-weight:600;margin-top:10px;">Liste des Étudiants</div>
  <div style="font-size:.82rem;color:#888;">Édité le <?= date('d/m/Y') ?></div>
</div>

<div class="page-header no-print">
  <h2><i class="fas fa-user-graduate me-2 text-primary"></i>
    <?= $isCoord ? 'Étudiants de ma section' : 'Gestion des Étudiants' ?>
  </h2>
  <?php if (!$isCoord): ?>
  <a href="<?= APP_URL ?>/modules/etudiants/add.php" class="btn btn-primary">
    <i class="fas fa-plus me-2"></i>Nouvel étudiant
  </a>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <div class="search-bar">
          <i class="fas fa-search search-icon"></i>
          <input type="text" name="search" class="form-control" placeholder="Nom, prénom, matricule..." value="<?= h($search) ?>">
        </div>
      </div>
      <?php if (!$isCoord): ?>
      <div class="col-md-2">
        <select name="filiere_id" class="form-select">
          <option value="">Toutes filières</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $filiereId == $f['id'] ? 'selected' : '' ?>><?= h($f['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <select name="niveau_id" class="form-select">
          <option value="">Tous niveaux</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $niveauId == $n['id'] ? 'selected' : '' ?>><?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="annee_id" class="form-select">
          <option value="">Toutes années</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="sexe" class="form-select">
          <option value="">Tous sexes</option>
          <option value="M" <?= $sexe==='M' ? 'selected' : '' ?>>Masculin</option>
          <option value="F" <?= $sexe==='F' ? 'selected' : '' ?>>Féminin</option>
        </select>
      </div>
      <div class="col-md-2">
        <select name="statut" class="form-select">
          <option value="">Tous statuts</option>
          <option value="actif"    <?= $statut==='actif'    ? 'selected' : '' ?>>Actif</option>
          <option value="transfere"<?= $statut==='transfere'? 'selected' : '' ?>>Transféré</option>
          <option value="exclu"    <?= $statut==='exclu'    ? 'selected' : '' ?>>Exclu</option>
          <option value="diplome"  <?= $statut==='diplome'  ? 'selected' : '' ?>>Diplômé</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><strong><?= number_format($total) ?></strong> étudiant(s) trouvé(s)</span>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <?php
        $totalM = count(array_filter($etudiants, fn($e) => $e['sexe'] === 'M'));
        $totalF = count(array_filter($etudiants, fn($e) => $e['sexe'] === 'F'));
      ?>
      <span class="badge" style="background:#1565c0;font-size:.8rem"><i class="fas fa-mars me-1"></i><?= $totalM ?> Masculin</span>
      <span class="badge" style="background:#ad1457;font-size:.8rem"><i class="fas fa-venus me-1"></i><?= $totalF ?> Féminin</span>
      <a href="<?= APP_URL ?>/modules/etudiants/export_excel.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-sm btn-outline-success no-print">
        <i class="fas fa-file-excel me-1"></i>Excel
      </a>
      <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
        <i class="fas fa-print me-1"></i>Imprimer
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Matricule</th>
          <th>Nom & Prénom</th>
          <th>Filière / Niveau</th>
          <th>Contact</th>
          <th>Statut</th>
          <th class="no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($etudiants)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-user-graduate d-block mb-2" style="font-size:2rem;opacity:.3"></i>Aucun étudiant trouvé</td></tr>
        <?php endif; ?>
        <?php foreach ($etudiants as $i => $e): ?>
        <tr>
          <td class="text-muted fs-sm"><?= $i+1 ?></td>
          <td><code class="fs-sm"><?= h($e['matricule']) ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:36px;height:36px;font-size:.8rem;">
                <?= strtoupper(substr($e['prenom'],0,1) . substr($e['nom'],0,1)) ?>
              </div>
              <div>
                <div class="fw-600"><?= h($e['nom'] . ' ' . $e['prenom']) ?></div>
                <div class="text-muted fs-sm"><?= $e['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></div>
              </div>
            </div>
          </td>
          <td>
            <?php if ($e['filiere_nom']): ?>
              <div class="fw-600 fs-sm"><?= h($e['filiere_code']) ?></div>
              <div class="text-muted fs-sm"><?= h($e['niveau_nom'] ?? '-') ?></div>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="fs-sm"><?= h($e['telephone'] ?? '-') ?></div>
            <?php if ($e['email']): ?><div class="text-muted fs-sm"><?= h($e['email']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php
              $sc = ['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span>
          </td>
          <td class="no-print">
            <div class="d-flex gap-1">
              <a href="<?= APP_URL ?>/modules/etudiants/view.php?id=<?= $e['id'] ?>" class="btn btn-icon btn-sm btn-outline-primary" title="Voir" data-bs-toggle="tooltip">
                <i class="fas fa-eye"></i>
              </a>
              <?php if (!$isCoord): ?>
              <a href="<?= APP_URL ?>/modules/etudiants/edit.php?id=<?= $e['id'] ?>" class="btn btn-icon btn-sm btn-outline-warning" title="Modifier" data-bs-toggle="tooltip">
                <i class="fas fa-edit"></i>
              </a>
              <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $e['id'] ?>" class="btn btn-icon btn-sm btn-outline-success" title="Paiements" data-bs-toggle="tooltip">
                <i class="fas fa-receipt"></i>
              </a>
              <?php endif; ?>
              <?php if ($user['role'] === 'admin'): ?>
              <form method="POST" action="<?= APP_URL ?>/modules/etudiants/delete.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button type="button" class="btn btn-icon btn-sm btn-outline-danger" title="Supprimer"
                        onclick="confirmDelete(this.form,<?= htmlspecialchars(json_encode($e['nom'].' '.$e['prenom'], JSON_HEX_TAG|JSON_HEX_AMP), ENT_COMPAT) ?>)" data-bs-toggle="tooltip">
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

<?php include APP_ROOT . '/includes/footer.php'; ?>
