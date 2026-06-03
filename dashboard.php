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

// ===== Stats financières (admin, comptable uniquement — directeur exclu) =====
$showFinance = in_array($role, ['admin', 'comptable']);
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
    $coordSections = getCoordinateurSections();
    $coordStats    = ['etudiants' => 0, 'matieres' => 0, 'sections' => count($coordSections)];
    $coordRecentEtudiants = [];

    if (!empty($coordSections)) {
        $cwParams = [];
        $coordWhere = coordSectionWhere('e', $cwParams);

        $csStmt = $db->prepare("SELECT COUNT(*) FROM etudiants e WHERE e.statut='actif' AND $coordWhere");
        $csStmt->execute($cwParams);
        $coordStats['etudiants'] = (int)$csStmt->fetchColumn();

        $filiereIds   = array_values(array_unique(array_map(fn($s) => (int)$s['filiere_id'], $coordSections)));
        $placeholders = implode(',', array_fill(0, count($filiereIds), '?'));
        $cmStmt = $db->prepare("SELECT COUNT(*) FROM matieres WHERE filiere_id IN ($placeholders) AND actif=1");
        $cmStmt->execute($filiereIds);
        $coordStats['matieres'] = (int)$cmStmt->fetchColumn();

        $creParams = $cwParams;
        $creStmt = $db->prepare("
            SELECT e.*, f.nom as filiere_nom, f.code as filiere_code, n.nom as niveau_nom
            FROM etudiants e
            LEFT JOIN filieres f ON f.id = e.filiere_id
            LEFT JOIN niveaux  n ON n.id = e.niveau_id
            WHERE e.statut='actif' AND $coordWhere
            ORDER BY e.created_at DESC LIMIT 5
        ");
        $creStmt->execute($creParams);
        $coordRecentEtudiants = $creStmt->fetchAll();
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

// ===== Stats par sexe (scolarite) =====
if ($role === 'scolarite') {
    $sexeRows = $db->query("SELECT sexe, COUNT(*) as nb FROM etudiants WHERE statut='actif' GROUP BY sexe")->fetchAll();
    $stats['masculin'] = 0;
    $stats['feminin']  = 0;
    foreach ($sexeRows as $sx) {
        if ($sx['sexe'] === 'M') $stats['masculin'] = (int)$sx['nb'];
        else $stats['feminin'] = (int)$sx['nb'];
    }
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

// ===== Stats courriers (assistante de direction) =====
if ($role === 'assistante') {
    try {
        $stats['depart_total']  = (int)$db->query("SELECT COUNT(*) FROM courriers_depart")->fetchColumn();
        $stats['arrivee_total'] = (int)$db->query("SELECT COUNT(*) FROM courriers_arrivee")->fetchColumn();
        $stats['depart_mois']   = (int)$db->query("SELECT COUNT(*) FROM courriers_depart WHERE MONTH(date_depart)=MONTH(NOW()) AND YEAR(date_depart)=YEAR(NOW())")->fetchColumn();
        $stats['arrivee_mois']  = (int)$db->query("SELECT COUNT(*) FROM courriers_arrivee WHERE MONTH(date_arrivee)=MONTH(NOW()) AND YEAR(date_arrivee)=YEAR(NOW())")->fetchColumn();
        $stats['sans_reponse']  = (int)$db->query("SELECT COUNT(*) FROM courriers_arrivee WHERE date_reponse IS NULL")->fetchColumn();

        $recentDepart  = $db->query("SELECT * FROM courriers_depart ORDER BY date_depart DESC, id DESC LIMIT 5")->fetchAll();
        $recentArrivee = $db->query("SELECT * FROM courriers_arrivee ORDER BY date_arrivee DESC, id DESC LIMIT 5")->fetchAll();

        $courriersChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-$i months"));
            [$yr, $mn] = explode('-', $m);
            $d = $db->prepare("SELECT COUNT(*) FROM courriers_depart  WHERE YEAR(date_depart)=?  AND MONTH(date_depart)=?");
            $d->execute([$yr, $mn]);
            $a = $db->prepare("SELECT COUNT(*) FROM courriers_arrivee WHERE YEAR(date_arrivee)=? AND MONTH(date_arrivee)=?");
            $a->execute([$yr, $mn]);
            $courriersChart[] = ['mois' => date('M Y', mktime(0,0,0,(int)$mn,1,(int)$yr)), 'depart' => (int)$d->fetchColumn(), 'arrivee' => (int)$a->fetchColumn()];
        }
    } catch (PDOException $e) {
        $stats['depart_total'] = $stats['arrivee_total'] = $stats['depart_mois'] = $stats['arrivee_mois'] = $stats['sans_reponse'] = 0;
        $recentDepart = $recentArrivee = $courriersChart = [];
    }
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

<?php if ($role === 'enseignant'): /* ===== VUE ENSEIGNANT ===== */ ?>

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
        <div class="d-flex align-items-start gap-3">
          <div class="avatar-circle" style="background:#2e7d32;width:52px;height:52px;font-size:1.2rem;flex-shrink:0">
            <i class="fas fa-sitemap"></i>
          </div>
          <div class="flex-grow-1">
            <h5 class="mb-1 fw-bold"><?= h(($user['prenom']??'').' '.($user['nom']??'')) ?></h5>
            <div class="text-muted mb-2" style="font-size:.85rem">Coordinateur de section</div>
            <?php if (!empty($coordSections)): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($coordSections as $s): ?>
                  <span class="badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7;font-size:.8rem;font-weight:500;padding:.4em .75em">
                    <i class="fas fa-layer-group me-1" style="opacity:.7"></i>
                    <strong><?= h($s['filiere_code']) ?></strong>
                    <?= $s['niveau_nom'] ? ' &mdash; ' . h($s['niveau_nom']) : ' <em style="opacity:.7">(tous niveaux)</em>' ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-warning fs-sm"><i class="fas fa-exclamation-triangle me-1"></i>Aucune section assignée à ce compte.</div>
            <?php endif; ?>
          </div>
          <div class="text-end">
            <div class="fw-bold text-success" style="font-size:1.6rem"><?= $coordStats['sections'] ?></div>
            <div class="text-muted" style="font-size:.75rem">section(s)</div>
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
        <div class="stat-value"><?= number_format($coordStats['etudiants']) ?></div>
        <div class="stat-label">Étudiants actifs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($coordStats['matieres']) ?></div>
        <div class="stat-label">Matières actives</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#1b5e20,#2e7d32)">
      <div class="stat-icon"><i class="fas fa-sitemap"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($coordStats['sections']) ?></div>
        <div class="stat-label">Sections gérées</div>
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
        <span><i class="fas fa-user-graduate me-2 text-success"></i>Étudiants de mes sections</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-success">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière / Niveau</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($coordRecentEtudiants as $e): ?>
            <tr>
              <td><code class="fs-sm"><?= h($e['matricule']) ?></code></td>
              <td><?= h($e['nom'].' '.$e['prenom']) ?></td>
              <td>
                <div class="fw-600 fs-sm"><?= h($e['filiere_code'] ?? '-') ?></div>
                <div class="text-muted fs-sm"><?= h($e['niveau_nom'] ?? '-') ?></div>
              </td>
              <td><?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']] ?? 'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($coordRecentEtudiants)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun étudiant trouvé</td></tr><?php endif; ?>
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

<!-- Stat Cards Scolarité -->
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
        <div class="stat-label">Filières actives</div>
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

<!-- Répartition par sexe -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#0d47a1,#1565c0)">
      <div class="stat-icon"><i class="fas fa-mars"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['masculin'] ?? 0) ?></div>
        <div class="stat-label">Masculin</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#880e4f,#ad1457)">
      <div class="stat-icon"><i class="fas fa-venus"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= number_format($stats['feminin'] ?? 0) ?></div>
        <div class="stat-label">Féminin</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header py-2"><i class="fas fa-venus-mars me-2 text-primary"></i>Répartition H / F</div>
      <div class="card-body d-flex flex-column justify-content-center py-2">
        <?php
          $totSexe = max(1, (int)$stats['etudiants']);
          $pctM    = round(($stats['masculin'] ?? 0) / $totSexe * 100);
          $pctF    = round(($stats['feminin']  ?? 0) / $totSexe * 100);
        ?>
        <div class="d-flex justify-content-between mb-1">
          <small><i class="fas fa-mars me-1" style="color:#1565c0"></i>Masculin</small>
          <strong><?= ($stats['masculin'] ?? 0) ?> <span class="text-muted fw-normal">(<?= $pctM ?>%)</span></strong>
        </div>
        <div class="progress mb-3" style="height:10px">
          <div class="progress-bar" style="width:<?= $pctM ?>%;background:#1565c0"></div>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <small><i class="fas fa-venus me-1" style="color:#ad1457"></i>Féminin</small>
          <strong><?= ($stats['feminin'] ?? 0) ?> <span class="text-muted fw-normal">(<?= $pctF ?>%)</span></strong>
        </div>
        <div class="progress" style="height:10px">
          <div class="progress-bar" style="width:<?= $pctF ?>%;background:#ad1457"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row Scolarité -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-pie me-2 text-primary"></i>Étudiants par filière</div>
      <div class="card-body">
        <div class="chart-container" style="height:200px"><canvas id="filiereChart"></canvas></div>
        <div class="mt-3">
          <?php foreach ($etudiantsFilieres as $ef): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <small class="text-muted"><?= h($ef['code']) ?></small>
              <div class="flex-grow-1 mx-2">
                <div class="progress" style="height:6px">
                  <?php $pct = $stats['etudiants'] > 0 ? round($ef['total']/$stats['etudiants']*100) : 0; ?>
                  <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
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
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/etudiants/add.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-user-plus d-block mb-1"></i><small>Nouvel étudiant</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-users d-block mb-1"></i><small>Liste étudiants</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/etudiants/promotion.php" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-arrow-up d-block mb-1"></i><small>Promotion</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Saisir notes</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-dark w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/pv.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-file-contract d-block mb-1"></i><small>PV Supérieur</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Derniers inscrits Scolarité -->
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-primary"></i>Derniers inscrits</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière</th><th>Niveau</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($recentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:32px;height:32px;font-size:.75rem">
                    <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                  </div>
                  <div class="fw-600 fs-sm"><?= h($e['nom'].' '.$e['prenom']) ?></div>
                </div>
              </td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['filiere_nom']??'-') ?></span></td>
              <td><small class="text-muted"><?= h($e['niveau_nom']??'-') ?></small></td>
              <td><?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEtudiants)): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucun étudiant enregistré</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($role === 'directeur'): /* ===== VUE DIRECTEUR ===== */ ?>

<!-- Stat Cards Directeur -->
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
      <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body">
        <?php $anneeDir = getActiveAnnee(); ?>
        <div class="stat-value" style="font-size:1rem"><?= $anneeDir ? h($anneeDir['libelle']) : '—' ?></div>
        <div class="stat-label">Année en cours</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts & Lists Row Directeur -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-5">
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
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-user-graduate d-block mb-1"></i><small>Étudiants</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/enseignants/index.php" class="btn btn-outline-success w-100 text-start py-3">
              <i class="fas fa-chalkboard-teacher d-block mb-1"></i><small>Enseignants</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/bulletins.php" class="btn btn-outline-secondary w-100 text-start py-3">
              <i class="fas fa-file-alt d-block mb-1"></i><small>Bulletins</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/pv_global_view.php" class="btn btn-outline-info w-100 text-start py-3">
              <i class="fas fa-table d-block mb-1"></i><small>PV Supérieur</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/etudiants/promotion.php" class="btn btn-outline-warning w-100 text-start py-3">
              <i class="fas fa-arrow-up d-block mb-1"></i><small>Promotion</small>
            </a>
          </div>
          <div class="col-6 col-md-4">
            <a href="<?= APP_URL ?>/modules/pedagogique/notes.php" class="btn btn-outline-dark w-100 text-start py-3">
              <i class="fas fa-edit d-block mb-1"></i><small>Notes</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Derniers inscrits Directeur -->
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-graduate me-2 text-primary"></i>Derniers inscrits</span>
        <a href="<?= APP_URL ?>/modules/etudiants/index.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Matricule</th><th>Nom & Prénom</th><th>Filière</th><th>Niveau</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($recentEtudiants as $e): ?>
            <tr>
              <td><code><?= h($e['matricule']) ?></code></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar-circle" style="background:<?= $e['sexe']==='M' ? '#1a73e8' : '#e91e63' ?>;width:32px;height:32px;font-size:.75rem">
                    <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                  </div>
                  <div class="fw-600 fs-sm"><?= h($e['nom'].' '.$e['prenom']) ?></div>
                </div>
              </td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($e['filiere_nom']??'-') ?></span></td>
              <td><small class="text-muted"><?= h($e['niveau_nom']??'-') ?></small></td>
              <td>
                <?php $sc=['actif'=>'success','transfere'=>'warning','exclu'=>'danger','diplome'=>'info'][$e['statut']]??'secondary'; ?>
                <span class="badge bg-<?= $sc ?>"><?= ucfirst(h($e['statut'])) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEtudiants)): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucun étudiant enregistré</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($role === 'admin'): /* ===== VUE ADMIN (full dashboard) ===== */ ?>

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

<?php elseif ($role === 'assistante'): /* ===== VUE ASSISTANTE DE DIRECTION ===== */ ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#922b21,#c0392b)">
      <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['depart_total'] ?></div>
        <div class="stat-label">Courriers départ</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-inbox"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['arrivee_total'] ?></div>
        <div class="stat-label">Courriers arrivée</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['depart_mois'] + $stats['arrivee_mois'] ?></div>
        <div class="stat-label">Ce mois</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $stats['sans_reponse'] ?></div>
        <div class="stat-label">Sans réponse</div>
      </div>
    </div>
  </div>
</div>

<!-- Détail mois courant -->
<div class="row g-3 mb-4">
  <div class="col-12 col-md-6">
    <div class="card" style="border-left:4px solid #c0392b">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-muted fs-sm mb-1"><i class="fas fa-paper-plane me-1" style="color:#c0392b"></i>Départ ce mois</div>
          <div style="font-size:2rem;font-weight:700;color:#c0392b;line-height:1"><?= $stats['depart_mois'] ?></div>
        </div>
        <a href="<?= APP_URL ?>/modules/courriers/depart.php" class="btn btn-sm btn-outline-danger">Gérer</a>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="card" style="border-left:4px solid #1a73e8">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-muted fs-sm mb-1"><i class="fas fa-inbox me-1" style="color:#1a73e8"></i>Arrivée ce mois</div>
          <div style="font-size:2rem;font-weight:700;color:#1a73e8;line-height:1"><?= $stats['arrivee_mois'] ?></div>
        </div>
        <a href="<?= APP_URL ?>/modules/courriers/arrivee.php" class="btn btn-sm btn-outline-primary">Gérer</a>
      </div>
    </div>
  </div>
</div>

<!-- Graphique + Accès rapide -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-bar me-2 text-primary"></i>Courriers par mois (6 derniers mois)</div>
      <div class="card-body"><div class="chart-container"><canvas id="courriersChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Accès rapide</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/courriers/depart.php" class="btn btn-outline-danger w-100 text-start py-3">
              <i class="fas fa-paper-plane d-block mb-1"></i><small>Courriers départ</small>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= APP_URL ?>/modules/courriers/arrivee.php" class="btn btn-outline-primary w-100 text-start py-3">
              <i class="fas fa-inbox d-block mb-1"></i><small>Courriers arrivée</small>
            </a>
          </div>
          <div class="col-12">
            <a href="<?= APP_URL ?>/modules/courriers/depart.php" class="btn btn-danger w-100 text-start py-3">
              <i class="fas fa-plus me-2"></i><small>Nouveau courrier départ</small>
            </a>
          </div>
          <div class="col-12">
            <a href="<?= APP_URL ?>/modules/courriers/arrivee.php" class="btn btn-primary w-100 text-start py-3">
              <i class="fas fa-plus me-2"></i><small>Nouveau courrier arrivée</small>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tableaux récents -->
<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-paper-plane me-2" style="color:#c0392b"></i>Derniers courriers départ</span>
        <a href="<?= APP_URL ?>/modules/courriers/depart.php" class="btn btn-sm btn-outline-danger">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Date</th><th>Destinataire</th><th>Objet</th><th>Pièces</th></tr></thead>
          <tbody>
            <?php if (empty($recentDepart)): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucun courrier départ</td></tr>
            <?php endif; ?>
            <?php foreach ($recentDepart as $c): ?>
            <tr>
              <td class="fs-sm text-muted"><?= formatDate($c['date_depart']) ?></td>
              <td class="fw-500 fs-sm"><?= h($c['destinataire']) ?></td>
              <td class="fs-sm text-truncate" style="max-width:120px"><?= h($c['objet']) ?></td>
              <td><span class="badge bg-secondary"><?= (int)$c['nbre_pieces'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-inbox me-2" style="color:#1a73e8"></i>Derniers courriers arrivée</span>
        <a href="<?= APP_URL ?>/modules/courriers/arrivee.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Date arr.</th><th>Expéditeur</th><th>Objet</th><th>Réponse</th></tr></thead>
          <tbody>
            <?php if (empty($recentArrivee)): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucun courrier arrivée</td></tr>
            <?php endif; ?>
            <?php foreach ($recentArrivee as $c): ?>
            <tr>
              <td class="fs-sm text-muted"><?= formatDate($c['date_arrivee']) ?></td>
              <td class="fw-500 fs-sm"><?= h($c['expediteur']) ?></td>
              <td class="fs-sm text-truncate" style="max-width:120px"><?= h($c['objet']) ?></td>
              <td>
                <?php if ($c['date_reponse']): ?>
                  <span class="badge bg-success" title="<?= formatDate($c['date_reponse']) ?>"><i class="fas fa-check"></i></span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">En attente</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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

if ($role === 'assistante' && !empty($courriersChart)):
    $ccLabels  = json_encode(array_column($courriersChart, 'mois'));
    $ccDepart  = json_encode(array_column($courriersChart, 'depart'));
    $ccArrivee = json_encode(array_column($courriersChart, 'arrivee'));
    $extraScripts .= <<<JS
<script>
const cCtx = document.getElementById('courriersChart')?.getContext('2d');
if (cCtx) new Chart(cCtx, {
  type:'bar',
  data:{
    labels:{$ccLabels},
    datasets:[
      {label:'Départ',data:{$ccDepart},backgroundColor:'rgba(192,57,43,.75)',borderRadius:4},
      {label:'Arrivée',data:{$ccArrivee},backgroundColor:'rgba(26,115,232,.75)',borderRadius:4}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
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
