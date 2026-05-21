<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

// Students can only see their own profile
$user = getCurrentUser();
if ($user['role'] === 'etudiant' && $user['reference_id'] != $id) {
    $id = (int)$user['reference_id'];
}

$stmt = $db->prepare("
    SELECT e.*, f.nom as filiere_nom, f.code as filiere_code,
           n.nom as niveau_nom, a.libelle as annee_libelle
    FROM etudiants e
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$etudiant = $stmt->fetch();

if (!$etudiant) {
    setFlash('error', 'Étudiant introuvable.');
    redirect('/modules/etudiants/index.php');
}

// Payments summary
$pStmt = $db->prepare("
    SELECT COALESCE(SUM(montant),0) as total_du,
           COALESCE(SUM(montant_paye),0) as total_paye
    FROM paiements_etudiants WHERE etudiant_id = ?
");
$pStmt->execute([$id]);
$paymentSummary = $pStmt->fetch();

// Recent payments
$recentPay = $db->prepare("
    SELECT * FROM paiements_etudiants
    WHERE etudiant_id = ?
    ORDER BY date_paiement DESC LIMIT 5
");
$recentPay->execute([$id]);
$recentPaiements = $recentPay->fetchAll();

// Notes/moyennes
$notesStmt = $db->prepare("
    SELECT n.*, m.nom as matiere_nom, m.coefficient, s.nom as semestre_nom
    FROM notes n
    JOIN matieres m ON m.id = n.matiere_id
    JOIN semestres s ON s.id = n.semestre_id
    WHERE n.etudiant_id = ?
    ORDER BY s.id, m.nom
");
$notesStmt->execute([$id]);
$notes = $notesStmt->fetchAll();

// Group by semester
$notesBySemestre = [];
foreach ($notes as $n) {
    $notesBySemestre[$n['semestre_nom']][] = $n;
}

$pageTitle = h($etudiant['nom'] . ' ' . $etudiant['prenom']);
$breadcrumb = ['Étudiants' => APP_URL . '/modules/etudiants/index.php', $pageTitle => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">
  <!-- Profile Card -->
  <div class="col-lg-4">
    <div class="card overflow-hidden">
      <div class="profile-header">
        <div class="profile-avatar">
          <?= strtoupper(substr($etudiant['prenom'],0,1) . substr($etudiant['nom'],0,1)) ?>
        </div>
        <h5 class="mb-1"><?= h($etudiant['prenom'] . ' ' . $etudiant['nom']) ?></h5>
        <code style="color:rgba(255,255,255,.8)"><?= h($etudiant['matricule']) ?></code>
        <div class="mt-2">
          <?php
            $sc = ['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$etudiant['statut']] ?? 'secondary';
          ?>
          <span class="badge bg-<?= $sc ?> px-3 py-2"><?= ucfirst(h($etudiant['statut'])) ?></span>
        </div>
      </div>
      <div class="card-body p-0">
        <ul class="info-list px-3 py-2">
          <li><i class="fas fa-venus-mars"></i><span class="label">Sexe</span><span class="value"><?= $etudiant['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></span></li>
          <li><i class="fas fa-birthday-cake"></i><span class="label">Date de naissance</span><span class="value"><?= formatDate($etudiant['date_naissance']) ?></span></li>
          <li><i class="fas fa-map-marker-alt"></i><span class="label">Lieu de naissance</span><span class="value"><?= h($etudiant['lieu_naissance'] ?? '-') ?></span></li>
          <li><i class="fas fa-phone"></i><span class="label">Téléphone</span><span class="value"><?= h($etudiant['telephone'] ?? '-') ?></span></li>
          <li><i class="fas fa-envelope"></i><span class="label">Email</span><span class="value"><?= h($etudiant['email'] ?? '-') ?></span></li>
          <li><i class="fas fa-home"></i><span class="label">Adresse</span><span class="value"><?= h($etudiant['adresse'] ?? '-') ?></span></li>
          <li><i class="fas fa-user-friends"></i><span class="label">Tuteur</span><span class="value"><?= h($etudiant['nom_tuteur'] ?? '-') ?></span></li>
          <?php if ($etudiant['telephone_tuteur']): ?>
          <li><i class="fas fa-phone-alt"></i><span class="label">Tél. tuteur</span><span class="value"><?= h($etudiant['telephone_tuteur']) ?></span></li>
          <?php endif; ?>
        </ul>
      </div>
      <?php if (hasRole(['admin', 'scolarite', 'directeur'])): ?>
      <div class="card-footer d-flex gap-2">
        <?php if (hasRole(['admin', 'scolarite'])): ?>
        <a href="<?= APP_URL ?>/modules/etudiants/edit.php?id=<?= $etudiant['id'] ?>" class="btn btn-primary btn-sm flex-fill">
          <i class="fas fa-edit me-1"></i>Modifier
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $etudiant['id'] ?>" class="btn btn-success btn-sm flex-fill">
          <i class="fas fa-receipt me-1"></i>Paiements
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Scolarité Card -->
    <div class="card mt-3">
      <div class="card-header"><i class="fas fa-graduation-cap me-2 text-primary"></i>Scolarité</div>
      <div class="card-body">
        <ul class="info-list p-0">
          <li><i class="fas fa-layer-group"></i><span class="label">Filière</span><span class="value"><?= h($etudiant['filiere_nom'] ?? '-') ?></span></li>
          <li><i class="fas fa-book"></i><span class="label">Niveau</span><span class="value"><?= h($etudiant['niveau_nom'] ?? '-') ?></span></li>
          <li><i class="fas fa-calendar"></i><span class="label">Année</span><span class="value"><?= h($etudiant['annee_libelle'] ?? '-') ?></span></li>
        </ul>
      </div>
    </div>

    <!-- Paiements Summary -->
    <div class="card mt-3">
      <div class="card-header"><i class="fas fa-money-bill me-2 text-primary"></i>Situation financière</div>
      <div class="card-body">
        <?php
          $du    = (float)$paymentSummary['total_du'];
          $paye  = (float)$paymentSummary['total_paye'];
          $reste = $du - $paye;
          $pct   = $du > 0 ? min(100, round($paye / $du * 100)) : 0;
        ?>
        <div class="d-flex justify-content-between mb-1">
          <small>Payé : <strong><?= formatMontant($paye) ?></strong></small>
          <small><?= $pct ?>%</small>
        </div>
        <div class="progress mb-2" style="height:8px">
          <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="d-flex justify-content-between">
          <small class="text-muted">Total dû : <?= formatMontant($du) ?></small>
          <small class="text-danger fw-bold">Reste : <?= formatMontant(max(0,$reste)) ?></small>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Column: Notes & Payments -->
  <div class="col-lg-8">
    <!-- Notes by semester -->
    <?php if (!empty($notesBySemestre)): ?>
      <?php foreach ($notesBySemestre as $sem => $semNotes): ?>
        <?php $moy = calculateMoyenne($semNotes); ?>
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-book-open me-2 text-primary"></i><?= h($sem) ?></span>
            <?php if ($moy !== null): ?>
              <span>Moyenne : <strong><?= number_format($moy,2) ?>/20</strong> <?= getMentionBadge($moy) ?></span>
            <?php endif; ?>
          </div>
          <div class="table-responsive">
            <table class="table mb-0">
              <thead><tr><th>Matière</th><th>Coef.</th><th>CC (40%)</th><th>Examen (60%)</th><th>Finale</th><th>Mention</th></tr></thead>
              <tbody>
                <?php foreach ($semNotes as $n): ?>
                <tr>
                  <td class="fw-600 fs-sm"><?= h($n['matiere_nom']) ?></td>
                  <td><?= $n['coefficient'] ?></td>
                  <td><?= noteBadge($n['note_cc']) ?></td>
                  <td><?= noteBadge($n['note_exam']) ?></td>
                  <td><?= noteBadge($n['note_finale']) ?></td>
                  <td><?= getMentionBadge($n['note_finale']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="card mb-3">
        <div class="card-body empty-state">
          <i class="fas fa-edit"></i>
          <h5>Aucune note enregistrée</h5>
          <p class="text-muted">Les notes seront affichées ici après leur saisie.</p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Recent Payments -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-receipt me-2 text-primary"></i>Derniers paiements</span>
        <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $etudiant['id'] ?>" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Libellé</th><th>Montant dû</th><th>Payé</th><th>Date</th><th>Statut</th></tr></thead>
          <tbody>
            <?php if (empty($recentPaiements)): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Aucun paiement</td></tr>
            <?php endif; ?>
            <?php foreach ($recentPaiements as $p): ?>
            <tr>
              <td class="fs-sm"><?= h($p['libelle'] ?? '-') ?></td>
              <td class="fw-600"><?= formatMontant($p['montant']) ?></td>
              <td class="text-success"><?= formatMontant($p['montant_paye']) ?></td>
              <td class="text-muted fs-sm"><?= formatDate($p['date_paiement']) ?></td>
              <td>
                <?php
                  $ps = ['en_attente'=>'warning','partiel'=>'info','complet'=>'success'][$p['statut']] ?? 'secondary';
                  $pl = ['en_attente'=>'En attente','partiel'=>'Partiel','complet'=>'Complet'][$p['statut']] ?? $p['statut'];
                ?>
                <span class="badge bg-<?= $ps ?>"><?= $pl ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Actions Bar -->
    <?php if (hasRole(['admin', 'enseignant'])): ?>
    <div class="d-flex gap-2 mt-3">
      <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php?etudiant_id=<?= $etudiant['id'] ?>" class="btn btn-outline-primary">
        <i class="fas fa-file-alt me-2"></i>Générer Bulletin
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
