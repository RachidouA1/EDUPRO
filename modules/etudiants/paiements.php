<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$id      = (int)($_GET['id'] ?? 0);

// Migrations
try { $db->exec("ALTER TABLE paiements_etudiants ADD COLUMN numero_recu VARCHAR(20) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try {
    $db->exec("CREATE TABLE IF NOT EXISTS versements (
        id              INT PRIMARY KEY AUTO_INCREMENT,
        paiement_id     INT NOT NULL,
        etudiant_id     INT NOT NULL,
        num_versement   TINYINT NOT NULL DEFAULT 1,
        montant         DECIMAL(10,2) NOT NULL,
        date_versement  DATE NOT NULL,
        mode_paiement   VARCHAR(50) DEFAULT 'especes',
        reference       VARCHAR(100) NULL,
        created_by      INT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (paiement_id) REFERENCES paiements_etudiants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

if (!function_exists('genNumeroRecu')) {
    function genNumeroRecu(PDO $db, string $date): string {
        $year = substr($date, 0, 4);
        $stmt = $db->prepare("SELECT COUNT(*) FROM paiements_etudiants WHERE numero_recu IS NOT NULL AND numero_recu LIKE ?");
        $stmt->execute(["REC-{$year}-%"]);
        $n = (int)$stmt->fetchColumn() + 1;
        return 'REC-' . $year . '-' . str_pad($n, 5, '0', STR_PAD_LEFT);
    }
}

if ($user['role'] === 'etudiant') {
    $id = (int)$user['reference_id'];
}

$stmt = $db->prepare("SELECT e.*, f.nom as filiere_nom FROM etudiants e LEFT JOIN filieres f ON f.id=e.filiere_id WHERE e.id=?");
$stmt->execute([$id]);
$etudiant = $stmt->fetch();

if (!$etudiant) {
    setFlash('error', 'Étudiant introuvable.');
    redirect('/modules/etudiants/index.php');
}

$errors = [];

// ── NOUVEAU PAIEMENT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && hasRole(['admin', 'comptable'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $typeFrais   = sanitize($_POST['type_frais']      ?? '');
        $libellesMap = [
            'inscription' => "Frais d'inscription",
            'scolarite'   => 'Frais de formation',
            'examen'      => "Frais d'examen",
        ];
        $libelle     = $libellesMap[$typeFrais] ?? '';
        $montant     = (float)($_POST['montant']         ?? 0);
        $montantPaye = (float)($_POST['montant_paye']    ?? 0);
        $datePay     = sanitize($_POST['date_paiement']  ?? date('Y-m-d'));
        $mode        = sanitize($_POST['mode_paiement']  ?? 'especes');
        $reference   = sanitize($_POST['reference']      ?? '');
        $anneeId     = (int)($_POST['annee_id']          ?? 0);

        if (empty($libelle) || $montant <= 0) {
            $errors[] = 'Sélectionnez un type de frais et saisissez le montant dû.';
        } else {
            $statut = 'en_attente';
            if ($montantPaye >= $montant) $statut = 'complet';
            elseif ($montantPaye > 0)     $statut = 'partiel';

            $numRecu = genNumeroRecu($db, $datePay);

            $db->prepare("INSERT INTO paiements_etudiants (etudiant_id, annee_id, libelle, montant, montant_paye, date_paiement, mode_paiement, reference, statut, numero_recu, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$id, $anneeId ?: null, $libelle, $montant, $montantPaye, $datePay, $mode, $reference ?: null, $statut, $numRecu, $user['id']]);

            $newPayId = (int)$db->lastInsertId();

            // Tracer le versement initial si montant payé > 0
            if ($montantPaye > 0) {
                $db->prepare("INSERT INTO versements (paiement_id, etudiant_id, num_versement, montant, date_versement, mode_paiement, reference, created_by)
                    VALUES (?,?,1,?,?,?,?,?)")
                   ->execute([$newPayId, $id, $montantPaye, $datePay, $mode, $reference ?: null, $user['id']]);

                if ($ecoleId > 0) {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, created_by, ecole_id) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$anneeId ?: null, $datePay, $libelle . ' – ' . $etudiant['nom'] . ' ' . $etudiant['prenom'], 'scolarite', $montantPaye, $mode, $user['id'], $ecoleId]);
                } else {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, created_by) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$anneeId ?: null, $datePay, $libelle . ' – ' . $etudiant['nom'] . ' ' . $etudiant['prenom'], 'scolarite', $montantPaye, $mode, $user['id']]);
                }
            }

            redirect('/modules/comptabilite/recu.php?print=' . $newPayId . '&auto_print=1');
        }
    }
}

// ── VERSEMENT COMPLÉMENTAIRE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'versement' && hasRole(['admin', 'comptable'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $payId        = (int)($_POST['pay_id']         ?? 0);
        $montantVerse = (float)($_POST['montant_verse'] ?? 0);
        $datePay      = sanitize($_POST['date_paiement'] ?? date('Y-m-d'));
        $mode         = sanitize($_POST['mode_paiement'] ?? 'especes');
        $reference    = sanitize($_POST['reference']     ?? '');

        $stmtP = $db->prepare("SELECT * FROM paiements_etudiants WHERE id=? AND etudiant_id=? AND statut='partiel'");
        $stmtP->execute([$payId, $id]);
        $existing = $stmtP->fetch();

        if (!$existing) {
            $errors[] = 'Paiement introuvable ou déjà soldé.';
        } elseif ($montantVerse <= 0) {
            $errors[] = 'Le montant du versement doit être supérieur à 0.';
        } else {
            $reste = (float)$existing['montant'] - (float)$existing['montant_paye'];
            if ($montantVerse > $reste) {
                $errors[] = 'Le montant versé (' . number_format($montantVerse, 0, ',', ' ') . ' FCFA) dépasse le reste dû (' . number_format($reste, 0, ',', ' ') . ' FCFA).';
            } else {
                $newMontantPaye = (float)$existing['montant_paye'] + $montantVerse;
                $newStatut      = $newMontantPaye >= (float)$existing['montant'] ? 'complet' : 'partiel';

                $db->prepare("UPDATE paiements_etudiants SET montant_paye=?, statut=?, date_paiement=?, mode_paiement=?, reference=? WHERE id=? AND etudiant_id=?")
                   ->execute([$newMontantPaye, $newStatut, $datePay, $mode, $reference ?: null, $payId, $id]);

                // Numéro de versement (compter les versements existants + 1)
                $numV = (int)$db->prepare("SELECT COUNT(*) FROM versements WHERE paiement_id=?")->execute([$payId]) ? 0 : 0;
                $stmtNV = $db->prepare("SELECT COUNT(*) FROM versements WHERE paiement_id=?");
                $stmtNV->execute([$payId]);
                $numV = (int)$stmtNV->fetchColumn() + 1;

                $db->prepare("INSERT INTO versements (paiement_id, etudiant_id, num_versement, montant, date_versement, mode_paiement, reference, created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$payId, $id, $numV, $montantVerse, $datePay, $mode, $reference ?: null, $user['id']]);

                // Recette
                if ($ecoleId > 0) {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, created_by, ecole_id) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$existing['annee_id'], $datePay,
                                  'Versement ' . $numV . ' – ' . $existing['libelle'] . ' – ' . $etudiant['nom'] . ' ' . $etudiant['prenom'],
                                  'scolarite', $montantVerse, $mode, $user['id'], $ecoleId]);
                } else {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, created_by) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$existing['annee_id'], $datePay,
                                  'Versement ' . $numV . ' – ' . $existing['libelle'] . ' – ' . $etudiant['nom'] . ' ' . $etudiant['prenom'],
                                  'scolarite', $montantVerse, $mode, $user['id']]);
                }

                $msg = $newStatut === 'complet'
                    ? 'Versement enregistré. Le paiement est maintenant soldé.'
                    : 'Versement enregistré. Reste : ' . number_format($existing['montant'] - $newMontantPaye, 0, ',', ' ') . ' FCFA.';
                setFlash('success', $msg);
                redirect('/modules/etudiants/paiements.php?id=' . $id);
            }
        }
    }
}

// ── SUPPRESSION ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && hasRole(['admin', 'comptable'])) {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $db->prepare("DELETE FROM paiements_etudiants WHERE id=? AND etudiant_id=?")->execute([(int)$_POST['pay_id'], $id]);
        setFlash('success', 'Paiement supprimé.');
        redirect('/modules/etudiants/paiements.php?id=' . $id);
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$paiements = $db->prepare("SELECT p.*, a.libelle as annee_libelle FROM paiements_etudiants p LEFT JOIN annees_academiques a ON a.id=p.annee_id WHERE p.etudiant_id=? ORDER BY p.date_paiement DESC, p.id DESC");
$paiements->execute([$id]);
$allPaiements = $paiements->fetchAll();

$totalDu    = array_sum(array_column($allPaiements, 'montant'));
$totalPaye  = array_sum(array_column($allPaiements, 'montant_paye'));
$totalReste = $totalDu - $totalPaye;

// Historique de tous les versements de cet étudiant
$stmtV = $db->prepare("
    SELECT v.*, p.libelle as frais_libelle, p.montant as frais_montant, p.numero_recu,
           CONCAT(u.prenom, ' ', u.nom) as agent
    FROM versements v
    JOIN paiements_etudiants p ON p.id = v.paiement_id
    LEFT JOIN users u ON u.id = v.created_by
    WHERE v.etudiant_id = ?
    ORDER BY v.date_versement DESC, v.id DESC
");
$stmtV->execute([$id]);
$allVersements = $stmtV->fetchAll();

// Nombre de versements par paiement_id (pour afficher le badge)
$versementsParPaiement = [];
foreach ($allVersements as $v) {
    $versementsParPaiement[$v['paiement_id']] = ($versementsParPaiement[$v['paiement_id']] ?? 0) + 1;
}

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

$pageTitle  = 'Paiements – ' . h($etudiant['prenom'] . ' ' . $etudiant['nom']);
$breadcrumb = [
    'Étudiants'                                 => APP_URL . '/modules/etudiants/index.php',
    h($etudiant['prenom'].' '.$etudiant['nom']) => APP_URL . '/modules/etudiants/view.php?id=' . $id,
    'Paiements'                                 => null,
];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-receipt me-2 text-primary"></i>Paiements</h2>
    <div class="text-muted fs-sm"><?= h($etudiant['prenom'] . ' ' . $etudiant['nom']) ?> – <code><?= h($etudiant['matricule']) ?></code></div>
  </div>
  <?php if (hasRole(['admin', 'comptable'])): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPayModal">
    <i class="fas fa-plus me-2"></i>Nouveau paiement
  </button>
  <?php endif; ?>
</div>

<!-- Résumé -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant($totalDu) ?></div><div class="stat-label">Total dû</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant($totalPaye) ?></div><div class="stat-label">Total payé</div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card <?= $totalReste > 0 ? 'stat-red' : 'stat-teal' ?>">
      <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.3rem"><?= formatMontant(max(0,$totalReste)) ?></div><div class="stat-label">Reste à payer</div></div>
    </div>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" id="payTabs">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPaiements">
      <i class="fas fa-list me-1"></i>Frais enregistrés
      <span class="badge bg-secondary ms-1"><?= count($allPaiements) ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabVersements">
      <i class="fas fa-history me-1"></i>Historique des versements
      <span class="badge bg-primary ms-1"><?= count($allVersements) ?></span>
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- ══ ONGLET 1 : Frais enregistrés ══════════════════════════════════════════ -->
  <div class="tab-pane fade show active" id="tabPaiements">
    <div class="card">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>N° Reçu</th><th>Libellé</th><th>Année</th>
              <th>Montant dû</th><th>Montant payé</th><th>Reste</th>
              <th>Dernière date</th><th>Mode</th><th>Statut</th>
              <th>Versements</th>
              <?php if (hasRole(['admin', 'comptable'])): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allPaiements)): ?>
              <tr><td colspan="11" class="text-center py-4 text-muted">Aucun paiement enregistré</td></tr>
            <?php endif; ?>
            <?php foreach ($allPaiements as $p): ?>
            <?php
              $reste    = max(0, (float)$p['montant'] - (float)$p['montant_paye']);
              $statutBg = ['en_attente' => 'warning', 'partiel' => 'info', 'complet' => 'success'][$p['statut']] ?? 'secondary';
              $statutLb = ['en_attente' => 'En attente', 'partiel' => 'Partiel', 'complet' => 'Complet'][$p['statut']] ?? $p['statut'];
              $nbV      = $versementsParPaiement[$p['id']] ?? 0;
            ?>
            <tr>
              <td class="fs-sm"><code><?= h($p['numero_recu'] ?? '-') ?></code></td>
              <td class="fw-600 fs-sm"><?= h($p['libelle']) ?></td>
              <td class="text-muted fs-sm"><?= h($p['annee_libelle'] ?? '-') ?></td>
              <td><?= formatMontant($p['montant']) ?></td>
              <td class="text-success fw-600"><?= formatMontant($p['montant_paye']) ?></td>
              <td class="<?= $reste > 0 ? 'text-danger fw-600' : 'text-muted' ?>"><?= formatMontant($reste) ?></td>
              <td class="text-muted fs-sm"><?= formatDate($p['date_paiement']) ?></td>
              <td class="fs-sm"><?= ucfirst(str_replace('_', ' ', h($p['mode_paiement']))) ?></td>
              <td><span class="badge bg-<?= $statutBg ?>"><?= $statutLb ?></span></td>
              <td class="text-center">
                <?php if ($nbV > 0): ?>
                  <button class="btn btn-sm btn-outline-primary py-0 px-2"
                          onclick="filtrerVersements(<?= $p['id'] ?>)"
                          title="Voir les versements de ce frais">
                    <i class="fas fa-history me-1"></i><?= $nbV ?>
                  </button>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <?php if (hasRole(['admin', 'comptable'])): ?>
              <td>
                <div class="d-flex gap-1 flex-wrap">
                  <a href="<?= APP_URL ?>/modules/comptabilite/recu.php?print=<?= $p['id'] ?>" target="_blank"
                     class="btn btn-icon btn-sm btn-outline-primary" title="Imprimer le reçu">
                    <i class="fas fa-print"></i>
                  </a>
                  <?php if ($p['statut'] === 'partiel'): ?>
                  <button type="button"
                          class="btn btn-sm btn-outline-success"
                          title="Enregistrer un versement"
                          onclick="ouvrirVersement(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['libelle'])) ?>, <?= (float)$p['montant'] ?>, <?= (float)$p['montant_paye'] ?>)">
                    <i class="fas fa-hand-holding-usd me-1"></i>Verser
                  </button>
                  <?php endif; ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="pay_id" value="<?= $p['id'] ?>">
                    <button type="button" class="btn btn-icon btn-sm btn-outline-danger"
                            onclick="confirmDelete(this.form,'ce paiement')">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ ONGLET 2 : Historique des versements ══════════════════════════════════ -->
  <div class="tab-pane fade" id="tabVersements">

    <!-- Filtre par frais -->
    <?php if (!empty($allPaiements)): ?>
    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
      <span class="text-muted fs-sm">Filtrer par frais :</span>
      <button class="btn btn-sm btn-primary" onclick="filtrerVersements(null)">Tous</button>
      <?php foreach ($allPaiements as $p): if (($versementsParPaiement[$p['id']] ?? 0) === 0) continue; ?>
        <button class="btn btn-sm btn-outline-secondary filtre-frais" data-pid="<?= $p['id'] ?>"
                onclick="filtrerVersements(<?= $p['id'] ?>)">
          <?= h($p['libelle']) ?>
          <span class="badge bg-secondary ms-1"><?= $versementsParPaiement[$p['id']] ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($allVersements)): ?>
      <div class="card">
        <div class="card-body text-center py-5 text-muted">
          <i class="fas fa-history fa-3x mb-3 opacity-25"></i>
          <p class="mb-0">Aucun versement enregistré pour cet apprenant.</p>
          <p class="fs-sm">Les versements effectués apparaîtront ici.</p>
        </div>
      </div>
    <?php else: ?>

    <!-- Résumé rapide -->
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card border-0 bg-light">
          <div class="card-body py-2 px-3">
            <div class="text-muted fs-sm">Nombre de versements</div>
            <div class="fw-bold fs-5"><?= count($allVersements) ?></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 bg-light">
          <div class="card-body py-2 px-3">
            <div class="text-muted fs-sm">Total versé</div>
            <div class="fw-bold fs-5 text-success"><?= formatMontant(array_sum(array_column($allVersements, 'montant'))) ?></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 bg-light">
          <div class="card-body py-2 px-3">
            <div class="text-muted fs-sm">Dernier versement</div>
            <div class="fw-bold fs-5"><?= formatDate($allVersements[0]['date_versement']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Timeline des versements -->
    <div class="card">
      <div class="table-responsive">
        <table class="table align-middle" id="tableVersements">
          <thead>
            <tr style="background:#0f2d5c;color:#fff">
              <th>#</th>
              <th>Date</th>
              <th>Frais concerné</th>
              <th>N° versement</th>
              <th>Montant versé</th>
              <th>Mode</th>
              <th>Référence</th>
              <th>Enregistré par</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allVersements as $i => $v): ?>
            <tr class="versement-row" data-pid="<?= $v['paiement_id'] ?>">
              <td class="text-muted fs-sm"><?= count($allVersements) - $i ?></td>
              <td class="fw-600 fs-sm"><?= formatDate($v['date_versement']) ?></td>
              <td>
                <div class="fw-600"><?= h($v['frais_libelle']) ?></div>
                <div class="text-muted fs-sm">Total dû : <?= formatMontant($v['frais_montant']) ?></div>
              </td>
              <td class="text-center">
                <span class="badge bg-primary rounded-pill px-3">
                  <?= $v['num_versement'] ?><sup><?= $v['num_versement'] == 1 ? 'er' : 'ème' ?></sup> versement
                </span>
              </td>
              <td class="fw-bold text-success fs-5"><?= formatMontant($v['montant']) ?></td>
              <td class="fs-sm"><?= ucfirst(str_replace('_', ' ', h($v['mode_paiement']))) ?></td>
              <td class="text-muted fs-sm"><?= $v['reference'] ? h($v['reference']) : '—' ?></td>
              <td class="text-muted fs-sm"><?= $v['agent'] ? h($v['agent']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>
  </div><!-- /tabVersements -->

</div><!-- /tab-content -->

<?php if (hasRole(['admin', 'comptable'])): ?>

<!-- ══ MODAL : Nouveau paiement / Versement ══════════════════════════════════ -->
<div class="modal fade" id="addPayModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" id="modalPayHeader" style="background:rgba(26,115,232,.07);border-bottom:2px solid #1a73e8">
        <h5 class="modal-title" id="modalPayTitle"><i class="fas fa-graduation-cap me-2 text-primary"></i>Nouveau paiement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="payForm">
        <input type="hidden" name="csrf"   value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" id="payAction" value="add">
        <input type="hidden" name="pay_id" id="payId"     value="">

        <div class="modal-body">

          <!-- Alerte versement complémentaire -->
          <div id="versementAlert" class="alert alert-info d-flex gap-2 align-items-start mb-3" style="display:none">
            <i class="fas fa-info-circle mt-1"></i>
            <div>
              <strong>Montant total dû :</strong> <span id="vMontantTotal"></span><br>
              <strong>Déjà payé :</strong> <span id="vMontantPaye"></span><br>
              <strong class="text-danger">Reste à payer : <span id="vMontantReste"></span></strong>
            </div>
          </div>

          <!-- Type de frais (mode nouveau paiement) -->
          <div class="mb-4" id="typeFraisBlock">
            <label class="form-label fw-bold mb-2">Type de frais <span class="text-danger">*</span></label>
            <div class="row g-2">
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeInscription" value="inscription" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeInscription" style="font-size:.85rem">
                  <i class="fas fa-id-card fa-lg"></i>Inscription
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeScolarite" value="scolarite" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeScolarite" style="font-size:.85rem">
                  <i class="fas fa-book-open fa-lg"></i>Formation
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="typeExamen" value="examen" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="typeExamen" style="font-size:.85rem">
                  <i class="fas fa-file-alt fa-lg"></i>Examen
                </label>
              </div>
            </div>
          </div>

          <!-- Libellé figé (mode versement complémentaire) -->
          <div class="mb-3" id="libelleFigeBlock" style="display:none">
            <label class="form-label fw-bold">Libellé</label>
            <input type="text" id="libelleFigeText" class="form-control" readonly style="background:#f8f9fa">
          </div>

          <div class="row g-3">
            <!-- Montant dû (nouveau paiement) -->
            <div class="col-6" id="montantDuBlock">
              <label class="form-label fw-bold">Montant dû <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant" id="montantDu" class="form-control" min="0" required placeholder="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <!-- Montant versé (nouveau paiement) -->
            <div class="col-6" id="montantPayeBlock">
              <label class="form-label fw-bold">Montant versé</label>
              <div class="input-group">
                <input type="number" name="montant_paye" id="montantPaye" class="form-control" min="0" placeholder="0" value="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <!-- Montant à verser (versement complémentaire) -->
            <div class="col-12" id="montantVerseBlock" style="display:none">
              <label class="form-label fw-bold">Montant à verser <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant_verse" id="montantVerse"
                       class="form-control form-control-lg" min="0" step="any" placeholder="0">
                <span class="input-group-text">FCFA</span>
              </div>
              <div class="form-text" id="resteHint"></div>
            </div>
            <!-- Date -->
            <div class="col-6">
              <label class="form-label fw-bold">Date du versement</label>
              <input type="date" name="date_paiement" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <!-- Mode -->
            <div class="col-6">
              <label class="form-label fw-bold">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </div>
            <!-- Référence -->
            <div class="col-6">
              <label class="form-label fw-bold">Référence</label>
              <input type="text" name="reference" class="form-control" placeholder="N° chèque, transaction…">
            </div>
            <!-- Année -->
            <div class="col-6" id="anneeBlock">
              <label class="form-label fw-bold">Année académique</label>
              <select name="annee_id" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>>
                    <?= h($a['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Alerte auto-impression (mode nouveau paiement) -->
          <div class="alert alert-info mt-3 py-2 mb-0" id="alertAutoprint" style="font-size:.83rem">
            <i class="fas fa-print me-1"></i>
            Le reçu sera généré et imprimé automatiquement après l'enregistrement.
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary" id="paySubmitBtn">
            <i class="fas fa-save me-1"></i>Enregistrer &amp; Imprimer le reçu
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
function formatMontantJS(n) {
  return new Intl.NumberFormat('fr-FR').format(n) + ' FCFA';
}

// ── Filtrer les versements par paiement_id ───────────────────────────────────
function filtrerVersements(pid) {
  const tabBtn = document.querySelector('[data-bs-target="#tabVersements"]');
  bootstrap.Tab.getOrCreateInstance(tabBtn).show();
  document.querySelectorAll('.versement-row').forEach(tr => {
    tr.style.display = (!pid || tr.dataset.pid == pid) ? '' : 'none';
  });
  document.querySelectorAll('.filtre-frais').forEach(btn => {
    btn.classList.toggle('btn-primary', btn.dataset.pid == pid);
    btn.classList.toggle('btn-outline-secondary', btn.dataset.pid != pid);
  });
}

// ── Mode versement complémentaire ────────────────────────────────────────────
function setModeVersement(payId, libelle, montant, montantPaye) {
  const reste = montant - montantPaye;

  document.getElementById('typeFraisBlock').style.display   = 'none';
  document.getElementById('libelleFigeBlock').style.display = '';
  document.getElementById('libelleFigeText').value          = libelle;

  document.getElementById('versementAlert').style.display = '';
  document.getElementById('vMontantTotal').textContent = formatMontantJS(montant);
  document.getElementById('vMontantPaye').textContent  = formatMontantJS(montantPaye);
  document.getElementById('vMontantReste').textContent = formatMontantJS(reste);

  document.getElementById('montantDuBlock').style.display    = 'none';
  document.getElementById('montantPayeBlock').style.display  = 'none';
  document.getElementById('montantVerseBlock').style.display = '';
  document.getElementById('anneeBlock').style.display        = 'none';
  document.getElementById('alertAutoprint').style.display    = 'none';

  document.getElementById('payAction').value = 'versement';
  document.getElementById('payId').value     = payId;

  document.getElementById('modalPayHeader').style.background   = 'rgba(25,135,84,.07)';
  document.getElementById('modalPayHeader').style.borderBottom = '2px solid #198754';
  document.getElementById('modalPayTitle').innerHTML = '<i class="fas fa-hand-holding-usd me-2 text-success"></i>Versement complémentaire';
  document.getElementById('paySubmitBtn').innerHTML  = '<i class="fas fa-check me-1"></i>Enregistrer le versement';
  document.getElementById('paySubmitBtn').className  = 'btn btn-success';

  document.getElementById('montantDu').removeAttribute('required');
  document.getElementById('montantVerse').setAttribute('required', 'required');

  const vi = document.getElementById('montantVerse');
  vi.removeAttribute('max');
  vi._resteMax = reste;
  vi.oninput = function () {
    const val  = parseFloat(this.value) || 0;
    const hint = document.getElementById('resteHint');
    if (val > this._resteMax) {
      hint.textContent = '⚠ Montant trop élevé. Maximum : ' + formatMontantJS(this._resteMax);
      hint.className   = 'form-text text-danger fw-bold';
      document.getElementById('paySubmitBtn').disabled = true;
    } else {
      hint.textContent = 'Montant maximum : ' + formatMontantJS(this._resteMax);
      hint.className   = 'form-text text-muted';
      document.getElementById('paySubmitBtn').disabled = false;
    }
  };
  document.getElementById('resteHint').textContent = 'Montant maximum : ' + formatMontantJS(reste);
  document.getElementById('resteHint').className   = 'form-text text-muted';
}

// ── Mode nouveau paiement ────────────────────────────────────────────────────
function setModeNouveauPaiement() {
  document.getElementById('typeFraisBlock').style.display    = '';
  document.getElementById('libelleFigeBlock').style.display  = 'none';
  document.getElementById('versementAlert').style.display    = 'none';
  document.getElementById('montantDuBlock').style.display    = '';
  document.getElementById('montantPayeBlock').style.display  = '';
  document.getElementById('montantVerseBlock').style.display = 'none';
  document.getElementById('anneeBlock').style.display        = '';
  document.getElementById('alertAutoprint').style.display    = '';

  document.getElementById('payAction').value = 'add';
  document.getElementById('payId').value     = '';

  document.getElementById('modalPayHeader').style.background   = 'rgba(26,115,232,.07)';
  document.getElementById('modalPayHeader').style.borderBottom = '2px solid #1a73e8';
  document.getElementById('modalPayTitle').innerHTML = '<i class="fas fa-graduation-cap me-2 text-primary"></i>Nouveau paiement';
  document.getElementById('paySubmitBtn').innerHTML  = '<i class="fas fa-save me-1"></i>Enregistrer &amp; Imprimer le reçu';
  document.getElementById('paySubmitBtn').className  = 'btn btn-primary';
  document.getElementById('paySubmitBtn').disabled   = false;

  document.getElementById('montantDu').setAttribute('required', 'required');
  document.getElementById('montantVerse').removeAttribute('required');

  const vi = document.getElementById('montantVerse');
  vi.oninput = null;
  vi.value   = '';
  document.getElementById('resteHint').textContent = '';
}

// ── Ouvrir depuis le bouton "Verser" du tableau ──────────────────────────────
function ouvrirVersement(payId, libelle, montant, montantPaye) {
  setModeVersement(payId, libelle, montant, montantPaye);
  new bootstrap.Modal(document.getElementById('addPayModal')).show();
}

// ── Réinitialiser à la fermeture du modal ────────────────────────────────────
document.getElementById('addPayModal')?.addEventListener('hidden.bs.modal', function () {
  setModeNouveauPaiement();
  document.querySelectorAll('input[name="type_frais"]').forEach(r => r.checked = false);
  document.getElementById('montantDu').value   = '';
  document.getElementById('montantPaye').value = '0';
});

// ── Auto-remplir montant versé quand montant dû perd le focus ───────────────
(function () {
  var du    = document.getElementById('montantDu');
  var verse = document.getElementById('montantPaye');
  if (du && verse) {
    du.addEventListener('blur', function () {
      if (!parseFloat(verse.value)) verse.value = this.value;
    });
  }
})();
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
