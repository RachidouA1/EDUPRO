<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireSuperAdmin();

$db      = getDB();
$current = getCurrentUser();

// ── Ensure table exists (idempotent) ──────────────────────────────────────────
try {
    $db->query("SELECT 1 FROM licences LIMIT 0");
} catch (PDOException $e) {
    $db->exec("CREATE TABLE licences (
        id               INT PRIMARY KEY AUTO_INCREMENT,
        ecole_id         INT NOT NULL,
        cle_licence      VARCHAR(50) NOT NULL UNIQUE,
        type_licence     ENUM('mensuel','trimestriel','semestriel','annuel','perpetuel') NOT NULL DEFAULT 'annuel',
        date_debut       DATE NOT NULL,
        date_expiration  DATE DEFAULT NULL,
        max_etudiants    INT DEFAULT 0,
        max_utilisateurs INT DEFAULT 0,
        statut           ENUM('active','expiree','suspendue','revoquee') NOT NULL DEFAULT 'active',
        notes            TEXT DEFAULT NULL,
        genere_par       INT DEFAULT NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Auto-expire licences whose date has passed
$db->exec("UPDATE licences SET statut = 'expiree' WHERE statut = 'active' AND date_expiration IS NOT NULL AND date_expiration < CURDATE()");

// ── GET Actions (CSRF protected) ──────────────────────────────────────────────
if (isset($_GET['action']) && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $action = $_GET['action'];
    $lid    = (int)($_GET['id'] ?? 0);

    $statusMap = ['suspend' => 'suspendue', 'revoke' => 'revoquee', 'activate' => 'active'];
    if (isset($statusMap[$action]) && $lid) {
        $db->prepare("UPDATE licences SET statut = ? WHERE id = ?")
           ->execute([$statusMap[$action], $lid]);
        $labels = ['suspend' => 'suspendue', 'revoke' => 'révoquée', 'activate' => 'réactivée'];
        setFlash('success', 'Licence ' . $labels[$action] . '.');
    } elseif ($action === 'delete' && $lid) {
        $db->prepare("DELETE FROM licences WHERE id = ?")->execute([$lid]);
        setFlash('success', 'Licence supprimée.');
    }
    redirect('/modules/superadmin/licences.php');
}

// ── POST: Generate licence ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $ecoleId   = (int)($_POST['ecole_id'] ?? 0);
    $type      = $_POST['type_licence'] ?? 'annuel';
    $dateDebut = $_POST['date_debut'] ?? date('Y-m-d');
    $maxEtu    = max(0, (int)($_POST['max_etudiants']    ?? 0));
    $maxUsers  = max(0, (int)($_POST['max_utilisateurs'] ?? 0));
    $notes     = trim($_POST['notes'] ?? '');

    $validTypes = ['mensuel', 'trimestriel', 'semestriel', 'annuel', 'perpetuel'];
    if (!in_array($type, $validTypes)) $type = 'annuel';

    // Calculate expiration date
    $dateExp = null;
    try {
        $dt = new DateTime($dateDebut);
        switch ($type) {
            case 'mensuel':     $dt->modify('+1 month');  $dateExp = $dt->format('Y-m-d'); break;
            case 'trimestriel': $dt->modify('+3 months'); $dateExp = $dt->format('Y-m-d'); break;
            case 'semestriel':  $dt->modify('+6 months'); $dateExp = $dt->format('Y-m-d'); break;
            case 'annuel':      $dt->modify('+1 year');   $dateExp = $dt->format('Y-m-d'); break;
            case 'perpetuel':   $dateExp = null; break;
        }
    } catch (Exception $e) {
        $dateExp = date('Y-m-d', strtotime('+1 year'));
    }

    $eStmt = $db->prepare("SELECT code, nom FROM ecoles WHERE id = ?");
    $eStmt->execute([$ecoleId]);
    $ecole = $eStmt->fetch();

    if ($ecole && $ecoleId > 0) {
        $cleKey = generateLicenceKey($ecole['code']);
        $db->prepare("INSERT INTO licences (ecole_id, cle_licence, type_licence, date_debut, date_expiration, max_etudiants, max_utilisateurs, statut, notes, genere_par) VALUES (?,?,?,?,?,?,?,'active',?,?)")
           ->execute([$ecoleId, $cleKey, $type, $dateDebut, $dateExp, $maxEtu, $maxUsers, $notes, $current['id']]);
        setFlash('success', 'Licence générée pour « ' . $ecole['nom'] . ' » : ' . $cleKey);
    } else {
        setFlash('error', 'École introuvable.');
    }
    redirect('/modules/superadmin/licences.php');
}

// ── Load data ─────────────────────────────────────────────────────────────────
$ecoles = $db->query("
    SELECT e.*,
           l.id               AS lic_id,
           l.cle_licence,
           l.type_licence,
           l.date_debut,
           l.date_expiration,
           l.statut           AS lic_statut,
           l.max_etudiants,
           l.max_utilisateurs,
           l.notes            AS lic_notes
    FROM ecoles e
    LEFT JOIN licences l ON l.id = (
        SELECT id FROM licences WHERE ecole_id = e.id ORDER BY created_at DESC LIMIT 1
    )
    ORDER BY e.nom
")->fetchAll();

$licHistory = $db->query("
    SELECT l.*, e.nom AS ecole_nom, e.code AS ecole_code,
           CONCAT(u.prenom, ' ', u.nom) AS genere_par_nom
    FROM licences l
    JOIN ecoles e ON l.ecole_id = e.id
    LEFT JOIN users u ON l.genere_par = u.id
    ORDER BY l.created_at DESC
")->fetchAll();

// Stats
$nbTotal   = count($ecoles);
$nbActives = count(array_filter($ecoles, fn($e) => ($e['lic_statut'] ?? '') === 'active'));
$nbSans    = count(array_filter($ecoles, fn($e) => !$e['lic_id']));
$nbExpSoon = count(array_filter($ecoles, function($e) {
    if (($e['lic_statut'] ?? '') !== 'active' || !$e['date_expiration']) return false;
    $diff = (new DateTime($e['date_expiration']))->diff(new DateTime())->days;
    return $diff <= 30 && $e['date_expiration'] >= date('Y-m-d');
}));

$preEcoleId = (int)($_GET['ecole_id'] ?? 0);

$typeLabels = [
    'mensuel'     => ['label' => 'Mensuel',     'months' => 1,  'icon' => 'fa-calendar-day'],
    'trimestriel' => ['label' => 'Trimestriel', 'months' => 3,  'icon' => 'fa-calendar-week'],
    'semestriel'  => ['label' => 'Semestriel',  'months' => 6,  'icon' => 'fa-calendar-alt'],
    'annuel'      => ['label' => 'Annuel',       'months' => 12, 'icon' => 'fa-calendar'],
    'perpetuel'   => ['label' => 'Perpétuel',   'months' => 0,  'icon' => 'fa-infinity'],
];

$pageTitle  = 'Licences d\'exploitation';
$breadcrumb = ['SuperAdmin' => null, 'Licences' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-key me-2" style="color:#6200ea"></i>Licences d'exploitation</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalGenerer">
    <i class="fas fa-plus me-1"></i>Générer une licence
  </button>
</div>

<?php showFlash(); ?>

<!-- ── Stats ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-school"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $nbTotal ?></div>
        <div class="stat-label">Établissements</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $nbActives ?></div>
        <div class="stat-label">Licences actives</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $nbExpSoon ?></div>
        <div class="stat-label">Expirent sous 30j</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $nbSans ?></div>
        <div class="stat-label">Sans licence</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Tableau des écoles + licences ──────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <strong>Licences par établissement</strong>
    <span class="text-muted fs-sm"><?= $nbTotal ?> établissement(s)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Établissement</th>
          <th>Clé de licence</th>
          <th>Type</th>
          <th>Validité</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ecoles as $e): ?>
        <tr>
          <td>
            <div class="fw-600"><?= h($e['nom']) ?></div>
            <small class="text-muted"><code><?= h($e['code']) ?></code></small>
          </td>
          <td>
            <?php if ($e['lic_id']): ?>
              <code class="text-primary fs-sm" style="letter-spacing:.04em"><?= h($e['cle_licence']) ?></code>
            <?php else: ?>
              <span class="text-muted fst-italic fs-sm">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($e['type_licence']): ?>
              <span class="badge bg-secondary"><?= h($typeLabels[$e['type_licence']]['label'] ?? $e['type_licence']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="fs-sm">
            <?php if ($e['date_expiration']): ?>
              <?php
                $exp   = new DateTime($e['date_expiration']);
                $now   = new DateTime();
                $diff  = (int)$now->diff($exp)->days;
                $past  = $exp < $now;
                $soon  = !$past && $diff <= 30;
              ?>
              <div><?= formatDate($e['date_debut']) ?> → <?= formatDate($e['date_expiration']) ?></div>
              <?php if ($past): ?>
                <small class="text-danger"><i class="fas fa-clock me-1"></i>Expirée il y a <?= $diff ?> j</small>
              <?php elseif ($soon): ?>
                <small class="text-warning fw-600"><i class="fas fa-exclamation-triangle me-1"></i>Expire dans <?= $diff ?> j</small>
              <?php else: ?>
                <small class="text-muted">Expire dans <?= $diff ?> j</small>
              <?php endif; ?>
            <?php elseif ($e['lic_id']): ?>
              <span class="text-success fs-sm"><i class="fas fa-infinity me-1"></i>Perpétuelle</span>
            <?php else: ?>
              <span class="text-muted fst-italic">Aucune licence</span>
            <?php endif; ?>
          </td>
          <td><?= getLicenceBadge($e['lic_id'] ? ['statut' => $e['lic_statut']] : null) ?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <!-- Générer / Renouveler -->
              <button class="btn btn-sm btn-primary" title="<?= $e['lic_id'] ? 'Renouveler' : 'Générer' ?> licence"
                      onclick="openGenerer(<?= (int)$e['id'] ?>, '<?= h(addslashes($e['nom'])) ?>')">
                <i class="fas fa-<?= $e['lic_id'] ? 'redo' : 'plus' ?>"></i>
              </button>
              <?php if ($e['lic_id']): ?>
                <?php if ($e['lic_statut'] === 'active'): ?>
                  <!-- Suspendre -->
                  <a href="?action=suspend&id=<?= $e['lic_id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                     class="btn btn-sm btn-outline-secondary" title="Suspendre"
                     onclick="return confirm('Suspendre la licence de <?= h(addslashes($e['nom'])) ?> ?')">
                    <i class="fas fa-pause"></i>
                  </a>
                  <!-- Révoquer -->
                  <a href="?action=revoke&id=<?= $e['lic_id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                     class="btn btn-sm btn-outline-danger" title="Révoquer"
                     onclick="return confirm('Révoquer définitivement la licence de <?= h(addslashes($e['nom'])) ?> ?')">
                    <i class="fas fa-ban"></i>
                  </a>
                <?php elseif (in_array($e['lic_statut'], ['suspendue', 'expiree'])): ?>
                  <!-- Réactiver -->
                  <a href="?action=activate&id=<?= $e['lic_id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                     class="btn btn-sm btn-outline-success" title="Réactiver"
                     onclick="return confirm('Réactiver cette licence ?')">
                    <i class="fas fa-play"></i>
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Historique complet ─────────────────────────────────────────────────── -->
<?php if ($licHistory): ?>
<div class="card">
  <div class="card-header">
    <a class="text-decoration-none fw-600 d-flex align-items-center justify-content-between"
       data-bs-toggle="collapse" href="#historique" style="color:inherit">
      <span><i class="fas fa-history me-2 text-muted"></i>Historique de toutes les licences (<?= count($licHistory) ?>)</span>
      <i class="fas fa-chevron-down text-muted fs-sm"></i>
    </a>
  </div>
  <div class="collapse" id="historique">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>École</th>
            <th>Clé</th>
            <th>Type</th>
            <th>Début</th>
            <th>Expiration</th>
            <th>Statut</th>
            <th>Généré par</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($licHistory as $l): ?>
          <tr>
            <td class="text-muted fs-sm">#<?= $l['id'] ?></td>
            <td>
              <div class="fw-600 fs-sm"><?= h($l['ecole_nom']) ?></div>
              <small class="text-muted"><code><?= h($l['ecole_code']) ?></code></small>
            </td>
            <td><code class="fs-sm text-primary" style="letter-spacing:.03em"><?= h($l['cle_licence']) ?></code></td>
            <td><span class="badge bg-secondary fs-sm"><?= h($typeLabels[$l['type_licence']]['label'] ?? $l['type_licence']) ?></span></td>
            <td class="fs-sm text-muted"><?= formatDate($l['date_debut']) ?></td>
            <td class="fs-sm text-muted"><?= $l['date_expiration'] ? formatDate($l['date_expiration']) : '<i class="fas fa-infinity text-success"></i>' ?></td>
            <td><?= getLicenceBadge($l) ?></td>
            <td class="fs-sm text-muted"><?= h($l['genere_par_nom'] ?? '—') ?></td>
            <td>
              <a href="?action=delete&id=<?= $l['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                 class="btn btn-sm btn-outline-danger" title="Supprimer"
                 onclick="return confirm('Supprimer cette entrée de licence ?')">
                <i class="fas fa-trash"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal : Générer / Renouveler une licence ───────────────────────────── -->
<div class="modal fade" id="modalGenerer" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <div class="modal-header" style="background:linear-gradient(135deg,#6200ea,#9c27b0);color:#fff">
          <h5 class="modal-title"><i class="fas fa-key me-2"></i>Générer une licence d'exploitation</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- École -->
          <div class="mb-3">
            <label class="form-label fw-600">Établissement <span class="text-danger">*</span></label>
            <select name="ecole_id" id="selectEcole" class="form-select" required>
              <option value="">— Choisir un établissement —</option>
              <?php foreach ($ecoles as $e): ?>
                <option value="<?= $e['id'] ?>"
                  <?= $preEcoleId === (int)$e['id'] ? 'selected' : '' ?>>
                  <?= h($e['nom']) ?> (<?= h($e['code']) ?>)
                  <?php if ($e['lic_id'] && $e['lic_statut'] === 'active'): ?> — Renouvellement<?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3">
            <!-- Type de licence -->
            <div class="col-md-6">
              <label class="form-label fw-600">Type de licence <span class="text-danger">*</span></label>
              <select name="type_licence" id="typeLicence" class="form-select" required onchange="updateExpiry()">
                <?php foreach ($typeLabels as $key => $tl): ?>
                  <option value="<?= $key ?>" <?= $key === 'annuel' ? 'selected' : '' ?>>
                    <?= $tl['label'] ?> <?= $tl['months'] > 0 ? '(' . $tl['months'] . ' mois)' : '(sans expiration)' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Date de début -->
            <div class="col-md-6">
              <label class="form-label fw-600">Date de début</label>
              <input type="date" name="date_debut" id="dateDebut" class="form-control"
                     value="<?= date('Y-m-d') ?>" onchange="updateExpiry()">
            </div>
            <!-- Date d'expiration calculée -->
            <div class="col-12">
              <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6">
                <div class="d-flex align-items-center gap-2">
                  <i class="fas fa-calendar-check text-primary"></i>
                  <span class="fw-600">Date d'expiration calculée :</span>
                  <span id="expiryDisplay" class="text-primary fw-600">—</span>
                </div>
              </div>
            </div>
          </div>

          <hr class="my-3">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Max. étudiants <small class="fw-normal text-muted">(0 = illimité)</small></label>
              <input type="number" name="max_etudiants" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Max. utilisateurs <small class="fw-normal text-muted">(0 = illimité)</small></label>
              <input type="number" name="max_utilisateurs" class="form-control" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Notes internes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Observations, conditions particulières…"></textarea>
            </div>
          </div>

          <!-- Aperçu de la clé -->
          <div class="mt-3 p-3 rounded text-center" style="background:#6200ea11;border:1px dashed #6200ea44">
            <small class="text-muted d-block mb-1">La clé sera générée automatiquement :</small>
            <code style="font-size:1rem;color:#6200ea;letter-spacing:.1em">EDUPRO-XXXX-XXXX-XXXX-XXXX</code>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-key me-1"></i>Générer la licence
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const typeDurations = {
    mensuel:     1,
    trimestriel: 3,
    semestriel:  6,
    annuel:      12,
    perpetuel:   0
};

function updateExpiry() {
    const type  = document.getElementById('typeLicence').value;
    const debut = document.getElementById('dateDebut').value;
    const el    = document.getElementById('expiryDisplay');
    if (!debut) { el.textContent = '—'; return; }
    if (type === 'perpetuel') { el.innerHTML = '<i class="fas fa-infinity me-1"></i>Perpétuelle (sans expiration)'; return; }
    const months = typeDurations[type] || 12;
    const d = new Date(debut);
    d.setMonth(d.getMonth() + months);
    el.textContent = d.toLocaleDateString('fr-FR', {day:'2-digit', month:'long', year:'numeric'});
}

function openGenerer(ecoleId, ecoleName) {
    document.getElementById('selectEcole').value = ecoleId;
    document.querySelector('#modalGenerer .modal-title').innerHTML =
        '<i class="fas fa-key me-2"></i>Licence — ' + ecoleName;
    updateExpiry();
    new bootstrap.Modal(document.getElementById('modalGenerer')).show();
}

// Init expiry display on page load
updateExpiry();

<?php if ($preEcoleId): ?>
document.addEventListener('DOMContentLoaded', function() {
    openGenerer(<?= $preEcoleId ?>, document.getElementById('selectEcole').options[document.getElementById('selectEcole').selectedIndex]?.text || '');
});
<?php endif; ?>
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
