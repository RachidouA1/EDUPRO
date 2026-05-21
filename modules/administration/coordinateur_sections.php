<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('admin');

$db = getDB();

try { $db->exec("CREATE TABLE IF NOT EXISTS coordinateur_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    filiere_id INT NOT NULL,
    niveau_id INT NULL,
    UNIQUE KEY uk_coord_sec (user_id, filiere_id, niveau_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (PDOException $e) {}

$coordId = (int)($_GET['user_id'] ?? 0);
if (!$coordId) redirect('/modules/administration/utilisateurs.php');

$cStmt = $db->prepare("SELECT * FROM users WHERE id=? AND role='coordinateur'");
$cStmt->execute([$coordId]);
$coordinateur = $cStmt->fetch();
if (!$coordinateur) {
    setFlash('error', 'Coordinateur introuvable.');
    redirect('/modules/administration/utilisateurs.php');
}

// Remove section
if (isset($_GET['action']) && $_GET['action'] === 'delete' && verifyCsrfToken($_GET['csrf'] ?? '')) {
    $secId = (int)($_GET['sec_id'] ?? 0);
    $db->prepare("DELETE FROM coordinateur_sections WHERE id=? AND user_id=?")->execute([$secId, $coordId]);
    setFlash('success', 'Section retirée.');
    redirect('/modules/administration/coordinateur_sections.php?user_id='.$coordId);
}

// Add section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $filiereId = (int)($_POST['filiere_id'] ?? 0);
    $niveauId  = (int)($_POST['niveau_id']  ?? 0) ?: null;

    if (!$filiereId) {
        setFlash('error', 'Veuillez sélectionner une filière.');
    } else {
        try {
            $db->prepare("INSERT INTO coordinateur_sections (user_id, filiere_id, niveau_id) VALUES (?,?,?)")
               ->execute([$coordId, $filiereId, $niveauId]);
            setFlash('success', 'Section ajoutée avec succès.');
        } catch (PDOException $e) {
            setFlash('error', 'Cette section est déjà assignée à ce coordinateur.');
        }
    }
    redirect('/modules/administration/coordinateur_sections.php?user_id='.$coordId);
}

// Load current sections
$secStmt = $db->prepare("
    SELECT cs.id, cs.filiere_id, cs.niveau_id,
           f.nom as filiere_nom, f.code as filiere_code,
           n.nom as niveau_nom, COALESCE(n.ordre, 0) as niveau_ordre
    FROM coordinateur_sections cs
    JOIN filieres f ON f.id = cs.filiere_id
    LEFT JOIN niveaux n ON n.id = cs.niveau_id
    WHERE cs.user_id = ?
    ORDER BY f.nom, n.ordre
");
$secStmt->execute([$coordId]);
$sections = $secStmt->fetchAll();

$filieres = getFilieres();
$niveaux  = getNiveaux();

$pageTitle  = 'Sections — ' . $coordinateur['prenom'] . ' ' . $coordinateur['nom'];
$breadcrumb = [
    'Administration' => null,
    'Utilisateurs'   => APP_URL . '/modules/administration/utilisateurs.php',
    'Sections'       => null,
];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2>
    <i class="fas fa-sitemap me-2 text-primary"></i>
    Sections de <?= h($coordinateur['prenom'] . ' ' . $coordinateur['nom']) ?>
  </h2>
  <a href="<?= APP_URL ?>/modules/administration/utilisateurs.php" class="btn btn-light">
    <i class="fas fa-arrow-left me-2"></i>Retour
  </a>
</div>

<?php showFlash(); ?>

<div class="row g-4">

  <!-- ── Liste des sections actuelles ── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <strong><?= count($sections) ?></strong> section(s) assignée(s)
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Filière</th>
              <th>Niveau</th>
              <th style="width:100px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sections)): ?>
              <tr>
                <td colspan="3" class="text-center text-muted py-5">
                  <i class="fas fa-sitemap d-block mb-2" style="font-size:2rem;opacity:.3"></i>
                  Aucune section assignée
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($sections as $s): ?>
            <tr>
              <td>
                <span class="badge bg-primary me-2"><?= h($s['filiere_code']) ?></span>
                <?= h($s['filiere_nom']) ?>
              </td>
              <td>
                <?php if ($s['niveau_nom']): ?>
                  <?= h($s['niveau_nom']) ?>
                <?php else: ?>
                  <span class="badge bg-secondary">Tous niveaux</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="?action=delete&user_id=<?= $coordId ?>&sec_id=<?= $s['id'] ?>&csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Retirer cette section de ce coordinateur ?')">
                  <i class="fas fa-times me-1"></i>Retirer
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Formulaire d'ajout ── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><strong>Ajouter une section</strong></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
          <div class="mb-3">
            <label class="form-label">Filière <span class="text-danger">*</span></label>
            <select name="filiere_id" id="sec_filiere" class="form-select" onchange="filterNiveaux()" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($filieres as $f): ?>
                <option value="<?= $f['id'] ?>"><?= h($f['code']) ?> – <?= h($f['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">
              Niveau
              <small class="text-muted">(vide = accès à tous les niveaux)</small>
            </label>
            <select name="niveau_id" id="sec_niveau" class="form-select">
              <option value="">-- Tous niveaux --</option>
              <?php foreach ($niveaux as $n): ?>
                <option value="<?= $n['id'] ?>" data-filiere="<?= $n['filiere_id'] ?>">
                  <?= h($n['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="fas fa-plus me-2"></i>Ajouter la section
          </button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body text-muted fs-sm">
        <i class="fas fa-info-circle me-1 text-primary"></i>
        <strong>Note :</strong> Si vous assignez une filière <em>sans niveau</em>, le coordinateur aura accès
        à tous les niveaux de cette filière. Si vous assignez avec un niveau précis, l'accès est limité à ce seul niveau.
      </div>
    </div>
  </div>

</div>

<script>
function filterNiveaux() {
    const fId = document.getElementById('sec_filiere').value;
    const sel = document.getElementById('sec_niveau');
    sel.querySelectorAll('option[data-filiere]').forEach(opt => {
        opt.style.display = (!fId || opt.getAttribute('data-filiere') === fId) ? '' : 'none';
    });
    sel.value = '';
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
