<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$db    = getDB();
$user  = getCurrentUser();
$role  = $user['role'] ?? 'etudiant';
$annee = getActiveAnnee();
$anneeId = $annee['id'] ?? 0;

// ===== Stats communes =====
$stats = [];
$stats['etudiants']  = $db->query("SELECT COUNT(*) FROM etudiants WHERE statut='actif'")->fetchColumn();
$stats['enseignants'] = $db->query("SELECT COUNT(*) FROM enseignants WHERE actif=1")->fetchColumn();
$stats['filieres']   = $db->query("SELECT COUNT(*) FROM filieres WHERE actif=1")->fetchColumn();

// ===== Stats financières (admin, directeur, comptable) =====
$showFinance = in_array($role, ['admin', 'directeur', 'comptable']);
if ($showFinance) {
    $stats['recettes_mois'] = $db->query("SELECT COALESCE(SUM(montant),0) FROM recettes WHERE MONTH(date_recette)=MONTH(NOW()) AND YEAR(date_recette)=YEAR(NOW())")->fetchColumn();
    $stats['depenses_mois'] = $db->query("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE statut='approuvee' AND MONTH(date_depense)=MONTH(NOW()) AND YEAR(date_depense)=YEAR(NOW())")->fetchColumn();
    $stats['solde_mois']    = $stats['recettes_mois'] - $stats['depenses_mois'];

    $chartData = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        [$yr, $mn] = explode('-', $m);
        $rec = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM recettes WHERE YEAR(date_recette)=? AND MONTH(date_recette)=?");
        $rec->execute([$yr, $mn]); $r = (float)$rec->fetchColumn();
        $dep = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE statut='approuvee' AND YEAR(date_depense)=? AND MONTH(date_depense)=?");
        $dep->execute([$yr, $mn]); $d = (float)$dep->fetchColumn();
        $chartData[] = ['mois' => date('M Y', mktime(0,0,0,$mn,1,$yr)), 'recettes' => $r, 'depenses' => $d];
    }
}

// ===== Stats pédagogiques (admin, directeur, scolarite, enseignant) =====
$showPedagogie = in_array($role, ['admin', 'directeur', 'scolarite', 'enseignant', 'coordinateur']);
if ($showPedagogie) {
    $stats['paiements_attente'] = $db->query("SELECT COUNT(*) FROM paiements_etudiants WHERE statut IN ('en_attente','partiel')")->fetchColumn();
}

// ===== Données coordinateur =====
if ($role === 'coordinateur') {
    $coordFiliereId = getCoordinateurFiliereId();
    $coordFiliere = null;
    if ($coordFiliereId) {
        $cf = $db->prepare("SELECT * FROM filieres WHERE id=?");
        $cf->execute([$coordFiliereId]);
        $coordFiliere = $cf->fetch();
    }
    $coordStats = [];
    if ($coordFiliereId) {
        $cs = $db->prepare("SELECT COUNT(*) FROM etudiants WHERE filiere_id=? AND statut='actif'");
        $cs->execute([$coordFiliereId]);
        $coordStats['etudiants'] = $cs->fetchColumn();
        $cs2 = $db->prepare("SELECT COUNT(*) FROM matieres WHERE filiere_id=? AND actif=1");
        $cs2->execute([$coordFiliereId]);
        $coordStats['matieres'] = $cs2->fetchColumn();
    }
    $coordRecentEtudiants = [];
    if ($coordFiliereId) {
        $cre = $db->prepare("SELECT e.*, n.nom as niveau_nom FROM etudiants e LEFT JOIN niveaux n ON n.id=e.niveau_id WHERE e.filiere_id=? AND e.statut='actif' ORDER BY e.created_at DESC LIMIT 5");
        $cre->execute([$coordFiliereId]);
        $coordRecentEtudiants = $cre->fetchAll();
    }
}

// ===== Répartition filières =====
$showFilieres = in_array($role, ['admin', 'directeur', 'scolarite', 'enseignant', 'comptable']);
if ($showFilieres) {
    $etudiantsFilieres = $db->query("
        SELECT f.nom, f.code, COUNT(e.id) as total
        FROM filieres f
        LEFT JOIN etudiants e ON e.filiere_id=f.id AND e.statut='actif'
        WHERE f.actif=1
        GROUP BY f.id
        ORDER BY total DESC
    ")->fetchAll();
}

// ===== Derniers étudiants =====
$showRecentEtudiants = in_array($role, ['admin', 'directeur', 'scolarite', 'enseignant', 'comptable']);
if ($showRecentEtudiants) {
    $recentEtudiants = $db->query("
        SELECT e.*, f.nom as filiere_nom, n.nom as niveau_nom
        FROM etudiants e
        LEFT JOIN filieres f ON f.id=e.filiere_id
        LEFT JOIN niveaux n ON n.id=e.niveau_id
        ORDER BY e.created_at DESC LIMIT 5
    ")->fetchAll();
}

// ===== Données étudiant (rôle etudiant) =====
if ($role === 'etudiant') {
    $refId = (int)($user['reference_id'] ?? 0);
    $etudiant = null;
    if ($refId) {
        $st = $db->prepare("SELECT e.*, f.nom as filiere_nom, n.nom as niveau_nom FROM etudiants e LEFT JOIN filieres f ON f.id=e.filiere_id LEFT JOIN niveaux n ON n.id=e.niveau_id WHERE e.id=?");
        $st->execute([$refId]);
        $etudiant = $st->fetch();
    }
    $paieTotal  = 0; $paieVerse  = 0;
    if ($etudiant) {
        $ps = $db->prepare("SELECT COALESCE(SUM(montant),0), COALESCE(SUM(montant_paye),0) FROM paiements_etudiants WHERE etudiant_id=?");
        $ps->execute([$refId]);
        [$paieTotal, $paieVerse] = $ps->fetch(PDO::FETCH_NUM);
    }
    $paiePct = $paieTotal > 0 ? min(100, round($paieVerse / $paieTotal * 100)) : 0;
}

// ===== Données enseignant =====
if ($role === 'enseignant') {
    $refId = (int)($user['reference_id'] ?? 0);
    $nbMatieres = 0;
    if ($refId) {
        $ms = $db->prepare("SELECT COUNT(*) FROM matieres WHERE enseignant_id=?");
        $ms->execute([$refId]);
        $nbMatieres = $ms->fetchColumn();
    }
}

$pageTitle = 'Tableau de bord';
include APP_ROOT . '/includes/header.php';
?>

<?php if ($role === 'etudiant'): /* ===== VUE ÉTUDIANT ===== */ ?>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card" style="border-left:4px solid #ea4335">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3">
          <div class="avatar-circle" style="background:#ea4335;width:60px;height:60px;font-size:1.4rem;flex-shrink:0">
            <?= strtoupper(substr($user['prenom']??'E',0,1).substr($user['nom']??'',0,1)) ?>
          </div>
          <div>
            <h5 class="mb-0 fw-bold"><?= h(($user['prenom']??'').' '.($user['nom']??'')) ?></h5>
            <?php if ($etudiant ?? null): ?>
              <div class="text-muted"><?= h($etudiant['matricule']??'') ?> &bull; <?= h($etudiant['filiere_nom']??'') ?> – <?= h($etudiant['niveau_nom']??'') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-receipt"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.1rem"><?= formatMontant($paieVerse ?? 0) ?></div>
        <div class="stat-label">Versé</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.1rem"><?= formatMontant(($paieTotal??0) - ($paieVerse??0)) ?></div>
        <div class="stat-label">Reste à payer</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-percent"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $paiePct ?? 0 ?>%</div>
        <div class="stat-label">Taux de paiement</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><i class="fas fa-check-circle"></i></div>
        <div class="stat-label">Bulletins dispos</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-md-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <?php $refId = (int)($user['reference_id'] ?? 0); ?>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/view.php?id=<?= $refId ?>" class="btn btn-outline-danger w-100 text-start py-3">
              <i class="fas fa-id-card d-block mb-1"></i><small>Mon Profil</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $refId ?>" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-receipt d-block mb-1"></i><small>Mes Paiements</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php?etudiant_id=<?= $refId ?>" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Mes Bulletins</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php if ($etudiant ?? null): ?>
  <div class="col-12 col-md-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-wallet me-2 text-primary"></i>Scolarité</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
          <small class="text-muted">Progression</small>
          <strong><?= $paiePct ?>%</strong>
        </div>
        <div class="progress mb-3" style="height:10px">
          <div class="progress-bar bg-<?= $paiePct >= 100 ? 'success' : ($paiePct >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $paiePct ?>%"></div>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Total scolarité</span>
          <strong><?= formatMontant($paieTotal ?? 0) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Versé</span>
          <strong class="text-success"><?= formatMontant($paieVerse ?? 0) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Reste</span>
          <strong class="text-danger"><?= formatMontant(($paieTotal??0) - ($paieVerse??0)) ?></strong>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($role === 'enseignant'): /* ===== VUE ENSEIGNANT ===== */ ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['etudiants']) ?></div>
        <div class="stat-label">Étudiants actifs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($nbMatieres ?? 0) ?></div>
        <div class="stat-label">Mes matières</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['filieres'] ?></div>
        <div class="stat-label">Filières</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-purple">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['paiements_attente'] ?? 0) ?></div>
        <div class="stat-label">Paiements en attente</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-primary"></i>Derniers inscrits</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($recentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td><?= h($e['nom'].' '.$e['prenom']) ?></td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['filiere_nom']??'-') ?></span></td>
              <td><?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEtudiants)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun étudiant</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Saisir notes</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/matieres.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-book-open d-block mb-1"></i><small>Mes matières</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-users d-block mb-1"></i><small>Mes étudiants</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($role === 'comptable'): /* ===== VUE COMPTABLE ===== */ ?>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-arrow-circle-down"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['recettes_mois']) ?></div>
        <div class="stat-label">Recettes ce mois</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-arrow-circle-up"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['depenses_mois']) ?></div>
        <div class="stat-label">Dépenses ce mois</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card <?= $stats['solde_mois'] >= 0 ? 'stat-teal' : 'stat-red' ?>">
      <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['solde_mois']) ?></div>
        <div class="stat-label">Solde ce mois</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-line me-2 text-primary"></i>Recettes vs Dépenses (6 mois)</div>
      <div class="card-body"><div class="chart-container"><canvas id="financeChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/comptabilite/recettes.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-arrow-circle-down d-block mb-1"></i><small>Recettes</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/comptabilite/depenses.php" class="btn btn-outline-danger w-100 text-start py-3">
              <i class="fas fa-arrow-circle-up d-block mb-1"></i><small>Dépenses</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/comptabilite/rapports.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-chart-bar d-block mb-1"></i><small>Rapports</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/enseignants/index.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-chalkboard-teacher d-block mb-1"></i><small>Paiements ens.</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($role === 'coordinateur'): /* ===== VUE COORDINATEUR ===== */ ?>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card" style="border-left:4px solid #2e7d32">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3">
          <div class="avatar-circle" style="background:#2e7d32;width:52px;height:52px;font-size:1.2rem;flex-shrink:0">
            <i class="fas fa-sitemap"></i>
          </div>
          <div>
            <h5 class="mb-0 fw-bold"><?= h(($user['prenom']??'').' '.($user['nom']??'')) ?></h5>
            <?php if ($coordFiliere ?? null): ?>
              <div class="text-muted">Coordinateur — <?= h($coordFiliere['code']) ?> : <?= h($coordFiliere['nom']) ?></div>
            <?php else: ?>
              <div class="text-warning">Aucune filière associée à ce compte.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($coordStats['etudiants'] ?? 0) ?></div>
        <div class="stat-label">Étudiants actifs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($coordStats['matieres'] ?? 0) ?></div>
        <div class="stat-label">Matières actives</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-purple">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['paiements_attente'] ?? 0) ?></div>
        <div class="stat-label">Paiements en attente</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['enseignants']) ?></div>
        <div class="stat-label">Enseignants</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-success"></i>Étudiants de ma section</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-success">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Niveau</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($coordRecentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td><?= h($e['nom'].' '.$e['prenom']) ?></td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['niveau_nom']??'-') ?></span></td>
              <td><?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($coordRecentEtudiants)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun étudiant</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-user-graduate d-block mb-1"></i><small>Mes étudiants</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/matieres.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-book-open d-block mb-1"></i><small>Matières</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Saisir notes</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/enseignants/add.php" class="btn btn-outline-dark w-100 text-start py-3">
              <i class="fas fa-user-tie d-block mb-1"></i><small>Ajouter enseignant</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/pv.php" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-file-contract d-block mb-1"></i><small>PV Supérieur</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($role === 'scolarite'): /* ===== VUE SCOLARITÉ ===== */ ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['etudiants']) ?></div>
        <div class="stat-label">Étudiants actifs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['filieres'] ?></div>
        <div class="stat-label">Filières</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-purple">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['paiements_attente']) ?></div>
        <div class="stat-label">Paiements en attente</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['enseignants']) ?></div>
        <div class="stat-label">Enseignants</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-primary"></i>Derniers inscrits</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($recentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td><?= h($e['nom'].' '.$e['prenom']) ?></td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['filiere_nom']??'-') ?></span></td>
              <td><?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEtudiants)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun étudiant</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/add.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-user-plus d-block mb-1"></i><small>Nouvel étudiant</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/paiements.php" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-receipt d-block mb-1"></i><small>Paiements</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Saisir notes</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: /* ===== VUE ADMIN / DIRECTEUR (full dashboard) ===== */ ?>

<!-- Stat Cards Row 1 -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['etudiants']) ?></div>
        <div class="stat-label">Étudiants actifs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['enseignants']) ?></div>
        <div class="stat-label">Enseignants</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['filieres'] ?></div>
        <div class="stat-label">Filières actives</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-purple">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['paiements_attente'] ?? 0) ?></div>
        <div class="stat-label">Paiements en attente</div>
      </div>
    </div>
  </div>
</div>

<!-- Stat Cards Row 2 (Finance) -->
<div class="row g-3 mb-4">
  <div class="col-12 col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-arrow-circle-down"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['recettes_mois']) ?></div>
        <div class="stat-label">Recettes ce mois</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card stat-red">
      <div class="stat-icon"><i class="fas fa-arrow-circle-up"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['depenses_mois']) ?></div>
        <div class="stat-label">Dépenses ce mois</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card <?= ($stats['solde_mois'] ?? 0) >= 0 ? 'stat-teal' : 'stat-red' ?>">
      <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.4rem"><?= formatMontant($stats['solde_mois'] ?? 0) ?></div>
        <div class="stat-label">Solde ce mois</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts & Lists Row -->
<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-line me-2 text-primary"></i>Recettes vs Dépenses (6 mois)</div>
      <div class="card-body"><div class="chart-container"><canvas id="financeChart"></canvas></div></div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-pie me-2 text-primary"></i>Étudiants par filière</div>
      <div class="card-body">
        <div class="chart-container" style="height:220px"><canvas id="filiereChart"></canvas></div>
        <div class="mt-3">
          <?php foreach ($etudiantsFilieres as $ef): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <small class="text-muted"><?= h($ef['code']) ?></small>
              <div class="flex-grow-1 mx-2">
                <div class="progress" style="height:6px">
                  <?php $pct = $stats['etudiants'] > 0 ? round($ef['total']/$stats['etudiants']*100) : 0; ?>
                  <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                </div>
              </div>
              <strong style="font-size:.85rem"><?= $ef['total'] ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-primary"></i>Derniers inscrits</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($recentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:32px;height:32px;font-size:.75rem">
                    <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                  </div>
                  <div>
                    <div class="fw-600 fs-sm"><?= h($e['nom'].' '.$e['prenom']) ?></div>
                    <div class="text-muted fs-sm"><?= h($e['niveau_nom']??'-') ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['filiere_nom']??'-') ?></span></td>
              <td>
                <?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEtudiants)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun étudiant enregistré</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <?php if ($role === 'admin'): ?>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/etudiants/add.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-user-plus d-block mb-1"></i><small>Nouvel étudiant</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/enseignants/add.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-user-tie d-block mb-1"></i><small>Nouvel enseignant</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Saisir notes</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/comptabilite/recettes.php" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-cash-register d-block mb-1"></i><small>Ajouter recette</small>
            </a>
          </div>
          <?php endif; ?>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/comptabilite/rapports.php" class="btn btn-outline-danger w-100 text-start py-3">
              <i class="fas fa-chart-bar d-block mb-1"></i><small>Rapports</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
$extraScripts = '';
if ($showFinance ?? false):
    $chartLabels   = json_encode(array_column($chartData ?? [], 'mois'));
    $chartRecettes = json_encode(array_column($chartData ?? [], 'recettes'));
    $chartDepenses = json_encode(array_column($chartData ?? [], 'depenses'));
    $extraScripts .= <<<JS
<script>
const fCtx = document.getElementById('financeChart')?.getContext('2d');
if (fCtx) new Chart(fCtx, {
  type:'line',
  data:{
    labels:{$chartLabels},
    datasets:[
      {label:'Recettes',data:{$chartRecettes},borderColor:'#34a853',backgroundColor:'rgba(52,168,83,.1)',tension:.4,fill:true},
      {label:'Dépenses',data:{$chartDepenses},borderColor:'#ea4335',backgroundColor:'rgba(234,67,53,.1)',tension:.4,fill:true}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>v.toLocaleString()+' F'}}}}
});
</script>
JS;
endif;

if ($showFilieres ?? false):
    $filiereLabels = json_encode(array_column($etudiantsFilieres ?? [], 'code'));
    $filiereValues = json_encode(array_column($etudiantsFilieres ?? [], 'total'));
    $extraScripts .= <<<JS
<script>
const pCtx = document.getElementById('filiereChart')?.getContext('2d');
if (pCtx) new Chart(pCtx, {
  type:'doughnut',
  data:{
    labels:{$filiereLabels},
    datasets:[{data:{$filiereValues},backgroundColor:['#1a73e8','#34a853','#fbbc04','#ea4335','#9c27b0']}]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
});
</script>
JS;
endif;

include APP_ROOT . '/includes/footer.php';
