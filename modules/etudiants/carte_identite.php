<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'directeur', 'etudiant', 'coordinateur']);

$db   = getDB();
$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

// Un étudiant ne peut voir que sa propre carte
if ($user['role'] === 'etudiant') {
    $id = (int)($user['reference_id'] ?? 0);
}

$stmt = $db->prepare("
    SELECT e.*, f.nom AS filiere_nom, f.code AS filiere_code,
           n.nom AS niveau_nom, a.libelle AS annee_libelle
    FROM etudiants e
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux  n ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$e = $stmt->fetch();

if (!$e) {
    setFlash('error', 'Étudiant introuvable.');
    redirect('/modules/etudiants/index.php');
}

$logoUrl       = getLogoUrl();
$etablissement = getParam('etablissement_nom', 'EPSI');
$slogan        = getParam('etablissement_slogan', 'École Privée de Santé Ibn Rochd');
$ville         = getParam('etablissement_ville', 'Tahoua');
$pays          = getParam('etablissement_pays', 'Niger');

$cachetPath = getParam('cachet_dg_path');
$cachetUrl  = ($cachetPath && file_exists(APP_ROOT . '/assets/' . $cachetPath))
              ? APP_URL . '/assets/' . $cachetPath
              : '';

$initiales    = strtoupper(mb_substr($e['prenom'], 0, 1) . mb_substr($e['nom'], 0, 1));
$couleurAvatar = $e['sexe'] === 'F' ? '#c0392b' : '#1a73e8';
$anneeValid   = $e['annee_libelle'] ?? date('Y') . '-' . (date('Y') + 1);
$photoUrl     = ($e['photo'] && file_exists(APP_ROOT . '/assets/' . $e['photo']))
                ? APP_URL . '/assets/' . $e['photo']
                : '';

$nom_fichier = 'carte_' . preg_replace('/[^a-z0-9]/i', '_', $e['matricule']) . '.jpg';

$pageTitle  = 'Carte d\'identité — ' . h($e['prenom'] . ' ' . $e['nom']);
$breadcrumb = [
    'Étudiants'         => APP_URL . '/modules/etudiants/index.php',
    h($e['nom'])        => APP_URL . '/modules/etudiants/view.php?id=' . $e['id'],
    'Carte d\'identité' => null,
];

$extraHead = <<<HTML
<style>
/* ── Wrapper page ─────────────────────────────────────────── */
.id-card-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.5rem;
  padding: 2rem 1rem;
}

/* ── Carte ─────────────────────────────────────────────────── */
.id-card {
  width: 620px;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,.22);
  position: relative;
  font-family: 'Inter', Arial, sans-serif;
  background: #ffffff;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
}

/* ── En-tête ─────────────────────────────────────────────── */
.id-card__header {
  background: linear-gradient(135deg, #0f2d5c 0%, #1a73e8 100%);
  padding: 14px 20px 12px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.id-card__logo {
  width: 50px; height: 50px;
  border-radius: 8px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
}
.id-card__logo img { width: 50px; height: 50px; object-fit: contain; }
.id-card__logo-text { font-size:1.1rem; font-weight:800; color:#fff; }
.id-card__school { flex:1; min-width:0; }
.id-card__school-name {
  font-size: .9rem; font-weight: 700; color: #fff; line-height:1.2;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.id-card__school-sub { font-size:.68rem; color:rgba(255,255,255,.75); margin-top:2px; }
.id-card__title-badge {
  background: rgba(255,255,255,.18);
  border: 1px solid rgba(255,255,255,.35);
  border-radius: 6px;
  padding: 4px 10px;
  font-size: .65rem; font-weight: 700; color: #fff;
  letter-spacing: .05em; text-transform: uppercase; white-space: nowrap;
}

/* ── Corps ─────────────────────────────────────────────────── */
.id-card__body {
  display: flex;
  gap: 0;
  padding: 16px 20px;
  flex: 1;
  align-items: flex-start;
}
.id-card__photo {
  width: 90px; height: 115px;
  border-radius: 10px;
  background: <?= $couleurAvatar ?>;
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; font-weight: 800; color: #fff;
  flex-shrink: 0;
  border: 3px solid #e8eef6;
  letter-spacing: -.03em;
  overflow: hidden;
}
.id-card__photo img { width:100%; height:100%; object-fit:cover; display:block; }
.id-card__info { flex:1; padding-left: 18px; }
.id-card__name {
  font-size: 1.05rem; font-weight: 800; color: #0f2d5c; line-height:1.25; margin-bottom:4px;
}
.id-card__matricule {
  font-size: .72rem; font-weight: 700; color: #1a73e8;
  background: #e8f0fe; display: inline-block;
  padding: 2px 8px; border-radius: 4px; margin-bottom: 10px; letter-spacing:.04em;
}
.id-card__rows { display:flex; flex-direction:column; gap:4px; }
.id-card__row  { display:flex; gap:6px; align-items:baseline; }
.id-card__row-label {
  font-size:.63rem; font-weight:700; color:#6c757d;
  text-transform:uppercase; letter-spacing:.03em; width:68px; flex-shrink:0;
}
.id-card__row-value { font-size:.75rem; font-weight:600; color:#1c2d4a; line-height:1.3; }

/* ── Séparateur ─────────────────────────────────────────────── */
.id-card__divider {
  height: 1px;
  background: linear-gradient(90deg, #e8eef6 0%, #c8d8f0 50%, #e8eef6 100%);
  margin: 0 20px;
}

/* ── Bas de carte : cachet + validité ────────────────────────── */
.id-card__bottom {
  display: flex;
  min-height: 90px;
}
.id-card__stamp {
  flex: 1;
  border-right: 1px solid #e8eef6;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 10px 14px;
  gap: 4px;
}
.id-card__stamp img {
  max-width: 110px;
  max-height: 65px;
  object-fit: contain;
}
.id-card__stamp-placeholder {
  width: 100px; height: 60px;
  border: 1.5px dashed #c0ccd8;
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  color: #b0bec5; font-size: .62rem; text-align: center; line-height:1.4;
}
.id-card__stamp-label {
  font-size: .6rem; color: #9aa5b1;
  text-align: center; text-transform: uppercase; letter-spacing: .05em;
}
.id-card__validity {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 10px 14px;
  gap: 5px;
  background: linear-gradient(135deg, #f8faff 0%, #eef3fd 100%);
}
.id-card__validity-title {
  font-size: .6rem; font-weight:700; color:#6c757d;
  text-transform:uppercase; letter-spacing:.06em;
}
.id-card__validity-year {
  font-size: 1rem; font-weight: 800; color: #0f2d5c; line-height:1;
}
.id-card__validity-badge {
  background: #0f2d5c;
  color: #fff;
  border-radius: 4px;
  padding: 3px 10px;
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .04em;
}

/* ── Liseré latéral ─────────────────────────────────────────── */
.id-card__stripe {
  position: absolute;
  top: 0; right: 0;
  width: 6px; height: 100%;
  background: linear-gradient(180deg, #fbbc04 0%, #f57c00 100%);
}

/* ── Actions ────────────────────────────────────────────────── */
.card-actions {
  display: flex; gap: .75rem; flex-wrap: wrap; justify-content: center;
}

@media (max-width:660px) {
  .id-card { width:100%; max-width:400px; }
  .id-card__photo { width:72px; height:92px; font-size:1.5rem; }
  .id-card__name  { font-size:.9rem; }
}
</style>
HTML;

include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-id-card me-2 text-primary"></i>Carte d'identité scolaire</h2>
  <a href="<?= APP_URL ?>/modules/etudiants/view.php?id=<?= $e['id'] ?>" class="btn btn-light">
    <i class="fas fa-arrow-left me-1"></i>Fiche étudiant
  </a>
</div>

<div class="id-card-wrap">

  <!-- ══════════════════════ CARTE ══════════════════════════ -->
  <div class="id-card" id="idCard">

    <!-- En-tête -->
    <div class="id-card__header">
      <div class="id-card__logo">
        <?php if ($logoUrl): ?>
          <img src="<?= h($logoUrl) ?>" alt="Logo" crossorigin="anonymous">
        <?php else: ?>
          <span class="id-card__logo-text"><?= h(mb_strtoupper(mb_substr($etablissement, 0, 2))) ?></span>
        <?php endif; ?>
      </div>
      <div class="id-card__school">
        <div class="id-card__school-name"><?= h(mb_strtoupper($etablissement)) ?></div>
        <div class="id-card__school-sub"><?= h($slogan) ?> &mdash; <?= h($ville) ?>, <?= h($pays) ?></div>
      </div>
      <div class="id-card__title-badge">Carte scolaire</div>
    </div>

    <!-- Corps : photo + informations -->
    <div class="id-card__body">
      <div class="id-card__photo">
        <?php if ($photoUrl): ?>
          <img src="<?= h($photoUrl) ?>" alt="Photo" crossorigin="anonymous">
        <?php else: ?>
          <?= $initiales ?>
        <?php endif; ?>
      </div>

      <div class="id-card__info">
        <div class="id-card__name"><?= h(mb_strtoupper($e['nom'])) ?> <?= h($e['prenom']) ?></div>
        <div class="id-card__matricule"><?= h($e['matricule']) ?></div>
        <div class="id-card__rows">
          <?php if ($e['filiere_nom']): ?>
          <div class="id-card__row">
            <span class="id-card__row-label">Filière</span>
            <span class="id-card__row-value"><?= h($e['filiere_code']) ?> &ndash; <?= h($e['filiere_nom']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($e['niveau_nom']): ?>
          <div class="id-card__row">
            <span class="id-card__row-label">Niveau</span>
            <span class="id-card__row-value"><?= h($e['niveau_nom']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($e['date_naissance']): ?>
          <div class="id-card__row">
            <span class="id-card__row-label">Né(e) le</span>
            <span class="id-card__row-value"><?= formatDate($e['date_naissance']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($e['lieu_naissance']): ?>
          <div class="id-card__row">
            <span class="id-card__row-label">À</span>
            <span class="id-card__row-value"><?= h($e['lieu_naissance']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($e['telephone']): ?>
          <div class="id-card__row">
            <span class="id-card__row-label">Tél.</span>
            <span class="id-card__row-value"><?= h($e['telephone']) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Séparateur -->
    <div class="id-card__divider"></div>

    <!-- Bas de carte : cachet DG + validité -->
    <div class="id-card__bottom">

      <!-- Zone cachet & signature DG -->
      <div class="id-card__stamp">
        <?php if ($cachetUrl): ?>
          <img src="<?= h($cachetUrl) ?>" alt="Cachet DG" crossorigin="anonymous">
        <?php else: ?>
          <div class="id-card__stamp-placeholder">Cachet &amp;<br>Signature DG</div>
        <?php endif; ?>
        <div class="id-card__stamp-label">Le Directeur Général</div>
      </div>

      <!-- Zone validité & année scolaire -->
      <div class="id-card__validity">
        <div class="id-card__validity-title">Année scolaire</div>
        <div class="id-card__validity-year"><?= h($anneeValid) ?></div>
        <div class="id-card__validity-badge">Valide <?= h($anneeValid) ?></div>
      </div>

    </div>

    <!-- Liseré latéral décoratif -->
    <div class="id-card__stripe"></div>
  </div>
  <!-- ═══════════════════════════════════════════════════════ -->

  <!-- Boutons d'action -->
  <div class="card-actions">
    <button id="btnDownload" class="btn btn-primary px-4">
      <i class="fas fa-download me-2"></i>Télécharger en JPG
    </button>
    <?php if (!$cachetUrl && hasRole('admin')): ?>
    <a href="<?= APP_URL ?>/modules/administration/parametres.php" class="btn btn-outline-secondary px-4">
      <i class="fas fa-stamp me-2"></i>Configurer le cachet DG
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-light px-4">
      <i class="fas fa-list me-1"></i>Retour à la liste
    </a>
  </div>

  <?php if (!$cachetUrl): ?>
  <p class="text-warning" style="font-size:.8rem;text-align:center;max-width:440px">
    <i class="fas fa-exclamation-triangle me-1"></i>
    Le cachet / signature du DG n'est pas encore configuré.
    <?php if (hasRole('admin')): ?>
      <a href="<?= APP_URL ?>/modules/administration/parametres.php">Configurer dans les Paramètres</a>.
    <?php else: ?>
      Contactez l'administrateur.
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <p class="text-muted" style="font-size:.8rem;text-align:center;max-width:420px">
    <i class="fas fa-info-circle me-1"></i>
    Cliquez sur <strong>Télécharger en JPG</strong> pour enregistrer la carte (haute résolution).
  </p>
</div>

<?php
$extraScripts = <<<HTML
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"
        integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
document.getElementById('btnDownload').addEventListener('click', function () {
    const btn  = this;
    const card = document.getElementById('idCard');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Génération…';
    html2canvas(card, {
        scale: 3,
        useCORS: true,
        allowTaint: false,
        backgroundColor: '#ffffff',
        logging: false
    }).then(function (canvas) {
        var link = document.createElement('a');
        link.download = '{$nom_fichier}';
        link.href = canvas.toDataURL('image/jpeg', 0.95);
        link.click();
    }).finally(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download me-2"></i>Télécharger en JPG';
    });
});
</script>
HTML;

include APP_ROOT . '/includes/footer.php';
?>
