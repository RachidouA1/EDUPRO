<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'coordinateur', 'scolarite']);

$db   = getDB();
$user = getCurrentUser();

// Inline migrations
try { $db->exec("
    CREATE TABLE IF NOT EXISTS emplois_du_temps (
        id INT PRIMARY KEY AUTO_INCREMENT,
        filiere_id INT NOT NULL,
        niveau_id INT NULL,
        annee_id INT NULL,
        semaine_debut DATE NOT NULL,
        semaine_fin DATE NOT NULL,
        statut ENUM('brouillon','soumis','approuve','rejete') NOT NULL DEFAULT 'brouillon',
        commentaire_directeur TEXT NULL,
        created_by INT NOT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
"); } catch (PDOException $e) {}
try { $db->exec("
    CREATE TABLE IF NOT EXISTS emplois_slots (
        id INT PRIMARY KEY AUTO_INCREMENT,
        emploi_id INT NOT NULL,
        jour ENUM('lundi','mardi','mercredi','jeudi','vendredi','samedi') NOT NULL,
        heure_debut TIME NOT NULL,
        heure_fin TIME NOT NULL,
        matiere_id INT NULL,
        enseignant_id INT NULL,
        FOREIGN KEY (emploi_id) REFERENCES emplois_du_temps(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE emplois_du_temps ADD COLUMN classe_id INT NULL"); } catch (PDOException $e) {}

// Delete (brouillon only, own or admin)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $delId = (int)($_GET['id'] ?? 0);
    $chk = $db->prepare("SELECT * FROM emplois_du_temps WHERE id=?");
    $chk->execute([$delId]);
    $emp = $chk->fetch();
    if ($emp && in_array($emp['statut'], ['brouillon','rejete']) && (hasRole('admin') || $emp['created_by'] == $user['id'])) {
        $db->prepare("DELETE FROM emplois_du_temps WHERE id=?")->execute([$delId]);
        setFlash('success', 'Emploi du temps supprimé.');
    }
    redirect('/modules/emploi_du_temps/index.php');
}

// Query
$where  = ['1=1'];
$params = [];
if (hasRole('coordinateur')) {
    $where[] = 'e.filiere_id = ?';
    $params[] = getCoordinateurFiliereId();
}
$statusFilter = sanitize($_GET['statut'] ?? '');
if ($statusFilter && in_array($statusFilter, ['brouillon','soumis','approuve','rejete'])) {
    $where[] = 'e.statut = ?';
    $params[] = $statusFilter;
}

$stmt = $db->prepare("
    SELECT e.*, f.nom as filiere_nom, f.code as filiere_code,
           n.nom as niveau_nom, a.libelle as annee_libelle,
           u.nom as createur_nom, u.prenom as createur_prenom,
           cl.nom as classe_nom
    FROM emplois_du_temps e
    JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n ON n.id = e.niveau_id
    LEFT JOIN classes cl ON cl.id = e.classe_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    LEFT JOIN users u ON u.id = e.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.statut = 'soumis' DESC, e.semaine_debut DESC
");
$stmt->execute($params);
$emplois = $stmt->fetchAll();

$statusCfg = [
    'brouillon' => ['label' => 'Brouillon',   'class' => 'secondary', 'icon' => 'fa-pencil-alt'],
    'soumis'    => ['label' => 'En attente',  'class' => 'warning',   'icon' => 'fa-clock'],
    'approuve'  => ['label' => 'Approuvé',    'class' => 'success',   'icon' => 'fa-check-circle'],
    'rejete'    => ['label' => 'Rejeté',      'class' => 'danger',    'icon' => 'fa-times-circle'],
];

$nbAttente = 0;
foreach ($emplois as $e) { if ($e['statut'] === 'soumis') $nbAttente++; }

$pageTitle  = 'Emplois du temps';
$breadcrumb = ['Pédagogie' => null, 'Emplois du temps' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-calendar-week me-2 text-primary"></i>Emplois du temps</h2>
  <?php if (hasRole('coordinateur') || hasRole('admin')): ?>
  <a href="<?= APP_URL ?>/modules/emploi_du_temps/form.php" class="btn btn-primary">
    <i class="fas fa-plus me-2"></i>Nouvel emploi du temps
  </a>
  <?php endif; ?>
</div>

<?php if ((hasRole('directeur') || hasRole('admin')) && $nbAttente > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="fas fa-exclamation-triangle fa-lg"></i>
  <span><strong><?= $nbAttente ?></strong> emploi(s) du temps en attente d'approbation.</span>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <select name="statut" class="form-select" onchange="this.form.submit()">
          <option value="">Tous les statuts</option>
          <option value="brouillon" <?= $statusFilter==='brouillon' ? 'selected':'' ?>>Brouillon</option>
          <option value="soumis"    <?= $statusFilter==='soumis'    ? 'selected':'' ?>>En attente d'approbation</option>
          <option value="approuve"  <?= $statusFilter==='approuve'  ? 'selected':'' ?>>Approuvé</option>
          <option value="rejete"    <?= $statusFilter==='rejete'    ? 'selected':'' ?>>Rejeté</option>
        </select>
      </div>
      <div class="col-auto">
        <a href="?" class="btn btn-light"><i class="fas fa-times me-1"></i>Effacer</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong><?= count($emplois) ?></strong> emploi(s) du temps</div>
  <div class="table-responsive">
    <table class="table" id="dataTable">
      <thead>
        <tr>
          <th>Semaine</th>
          <th>Filière / Niveau</th>
          <th>Année</th>
          <?php if (!hasRole('coordinateur')): ?><th>Coordinateur</th><?php endif; ?>
          <th>Statut</th>
          <th>Créé le</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($emplois)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted">
            <i class="fas fa-calendar-times d-block mb-2" style="font-size:2rem;opacity:.3"></i>
            Aucun emploi du temps
          </td></tr>
        <?php endif; ?>
        <?php foreach ($emplois as $e):
          $sc = $statusCfg[$e['statut']] ?? ['label'=>$e['statut'],'class'=>'secondary','icon'=>'fa-question'];
        ?>
        <tr class="<?= $e['statut']==='soumis' ? 'table-warning' : '' ?>">
          <td>
            <div class="fw-600"><i class="fas fa-calendar-day me-1 text-primary"></i><?= formatDate($e['semaine_debut']) ?></div>
            <div class="text-muted fs-sm">au <?= formatDate($e['semaine_fin']) ?></div>
          </td>
          <td>
            <div class="fw-600"><?= h($e['filiere_code']) ?> – <?= h($e['filiere_nom']) ?></div>
            <?php if ($e['niveau_nom'] || $e['classe_nom']): ?>
            <div class="text-muted fs-sm">
              <?= $e['niveau_nom'] ? h($e['niveau_nom']) : '' ?>
              <?php if ($e['classe_nom']): ?>
                <?= $e['niveau_nom'] ? ' · ' : '' ?><i class="fas fa-chalkboard" style="font-size:.72rem"></i> <?= h($e['classe_nom']) ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="fs-sm text-muted"><?= h($e['annee_libelle'] ?? '–') ?></td>
          <?php if (!hasRole('coordinateur')): ?>
          <td class="fs-sm"><?= h(($e['createur_prenom']??'').' '.($e['createur_nom']??'')) ?></td>
          <?php endif; ?>
          <td>
            <span class="badge bg-<?= $sc['class'] ?>">
              <i class="fas <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
            </span>
          </td>
          <td class="text-muted fs-sm"><?= formatDate($e['created_at']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="<?= APP_URL ?>/modules/emploi_du_temps/view.php?id=<?= $e['id'] ?>"
                 class="btn btn-icon btn-sm btn-outline-primary" title="Voir"><i class="fas fa-eye"></i></a>
              <?php if (in_array($e['statut'], ['brouillon','rejete']) && ($e['created_by'] == $user['id'] || hasRole('admin'))): ?>
              <a href="<?= APP_URL ?>/modules/emploi_du_temps/form.php?id=<?= $e['id'] ?>"
                 class="btn btn-icon btn-sm btn-outline-warning" title="Modifier"><i class="fas fa-edit"></i></a>
              <a href="?action=delete&id=<?= $e['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-icon btn-sm btn-outline-danger" title="Supprimer"
                 onclick="return confirm('Supprimer définitivement cet emploi du temps ?')">
                <i class="fas fa-trash"></i>
              </a>
              <?php endif; ?>
              <?php if ($e['statut'] === 'soumis' && (hasRole('directeur') || hasRole('admin'))): ?>
              <a href="<?= APP_URL ?>/modules/emploi_du_temps/view.php?id=<?= $e['id'] ?>"
                 class="btn btn-sm btn-warning" title="Approuver / Rejeter">
                <i class="fas fa-gavel me-1"></i>Valider
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

<?php include APP_ROOT . '/includes/footer.php'; ?>
