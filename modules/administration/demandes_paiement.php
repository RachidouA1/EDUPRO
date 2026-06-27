<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['coordinateur', 'admin', 'comptable']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$role    = $user['role'];

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
        redirect('/modules/administration/demandes_paiement.php');
    }

    $action = sanitize($_POST['action'] ?? '');

    // Comptable: traiter une demande
    if ($action === 'traiter' && in_array($role, ['comptable', 'admin'])) {
        $demandeId  = (int)($_POST['demande_id'] ?? 0);
        $montant    = (float)($_POST['montant'] ?? 0);
        $mode       = sanitize($_POST['mode_paiement'] ?? 'virement');
        $libelle    = sanitize($_POST['libelle'] ?? '');
        $notesCpt   = sanitize($_POST['notes_comptable'] ?? '');
        $datePay    = sanitize($_POST['date_paiement'] ?? date('Y-m-d'));
        $anneeId    = (int)($_POST['annee_id'] ?? 0) ?: null;

        if (!$demandeId || $montant <= 0 || empty($libelle)) {
            setFlash('error', 'Libellé et montant obligatoires.');
        } else {
            // Load demande
            $d = $db->prepare("SELECT d.*, CONCAT(e.prenom,' ',e.nom) as ens_nom, e.id as ens_id
                                FROM demandes_paiement_enseignant d
                                JOIN enseignants e ON e.id = d.enseignant_id
                                WHERE d.id = ? AND d.statut = 'en_attente'")->execute([$demandeId]);
            $demande = $db->prepare("SELECT d.*, CONCAT(e.prenom,' ',e.nom) as ens_nom, e.id as ens_id
                                     FROM demandes_paiement_enseignant d
                                     JOIN enseignants e ON e.id = d.enseignant_id
                                     WHERE d.id = ? AND d.statut = 'en_attente'");
            $demande->execute([$demandeId]);
            $dem = $demande->fetch();

            if (!$dem) {
                setFlash('error', 'Demande introuvable ou déjà traitée.');
            } else {
                try {
                    $db->beginTransaction();

                    // Create paiement_enseignant
                    $db->prepare("INSERT INTO paiements_enseignants
                                    (enseignant_id, annee_id, libelle, type, montant, date_paiement, mode_paiement)
                                  VALUES (?,?,?,'vacation',?,?,?)")
                       ->execute([$dem['ens_id'], $anneeId, $libelle, $montant, $datePay, $mode]);

                    // Create depense
                    if ($ecoleId > 0) {
                        $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement, notes, ecole_id) VALUES (?,?,?,'salaire',?,?,?,?,?)")
                           ->execute([$anneeId, $datePay, $libelle . ' – ' . $dem['ens_nom'], $montant, $dem['ens_nom'], $mode,
                                      'Vacation – ' . $dem['total_heures'] . 'h du ' . formatDate($dem['date_debut']) . ' au ' . formatDate($dem['date_fin']), $ecoleId]);
                    } else {
                        $db->prepare("INSERT INTO depenses (annee_id, date_depense, libelle, categorie, montant, beneficiaire, mode_paiement, notes) VALUES (?,?,?,'salaire',?,?,?,?)")
                           ->execute([$anneeId, $datePay, $libelle . ' – ' . $dem['ens_nom'], $montant, $dem['ens_nom'], $mode,
                                      'Vacation – ' . $dem['total_heures'] . 'h du ' . formatDate($dem['date_debut']) . ' au ' . formatDate($dem['date_fin'])]);
                    }

                    // Mark demande as traite
                    $db->prepare("UPDATE demandes_paiement_enseignant
                                  SET statut='traite', notes_comptable=?, traite_at=NOW(), traite_par=?
                                  WHERE id=?")
                       ->execute([$notesCpt, $user['id'], $demandeId]);

                    $db->commit();
                    setFlash('success', 'Paiement enregistré et demande clôturée.');
                } catch (PDOException $e) {
                    $db->rollBack();
                    setFlash('error', 'Erreur : ' . $e->getMessage());
                }
            }
        }
        redirect('/modules/administration/demandes_paiement.php');
    }

    // Comptable / Admin: rejeter
    if ($action === 'rejeter' && in_array($role, ['comptable', 'admin'])) {
        $demandeId = (int)($_POST['demande_id'] ?? 0);
        $notesCpt  = sanitize($_POST['notes_comptable'] ?? '');
        if ($demandeId) {
            $db->prepare("UPDATE demandes_paiement_enseignant
                          SET statut='rejete', notes_comptable=?, traite_at=NOW(), traite_par=?
                          WHERE id=? AND statut='en_attente'")
               ->execute([$notesCpt, $user['id'], $demandeId]);
            setFlash('warning', 'Demande rejetée.');
        }
        redirect('/modules/administration/demandes_paiement.php');
    }

    // Coordinateur / Admin: supprimer une demande en attente
    if ($action === 'supprimer' && in_array($role, ['coordinateur', 'admin'])) {
        $demandeId = (int)($_POST['demande_id'] ?? 0);
        if ($demandeId) {
            // Filtrer par école via la table enseignants
            $ecoleClause = ($ecoleId > 0)
                ? "AND d.enseignant_id IN (SELECT id FROM enseignants WHERE ecole_id={$ecoleId})"
                : '';
            $coordClause = ($role === 'coordinateur') ? "AND d.coordinateur_id = {$user['id']}" : '';
            $db->prepare("DELETE d FROM demandes_paiement_enseignant d
                          WHERE d.id=? AND d.statut='en_attente' {$coordClause} {$ecoleClause}")
               ->execute([$demandeId]);
            setFlash('success', 'Demande supprimée.');
        }
        redirect('/modules/administration/demandes_paiement.php');
    }
}

// ── Load demands ───────────────────────────────────────────────────────────
$demandesParams = [];
if ($role === 'coordinateur') {
    $demandesWhere = "WHERE d.coordinateur_id = ?";
    $demandesParams[] = $user['id'];
} elseif ($role === 'comptable') {
    $demandesWhere = "WHERE d.statut = 'en_attente'"; // comptable sees only pending
} else {
    $demandesWhere = ""; // admin sees all
}

$stmt = $db->prepare("
    SELECT d.*,
           CONCAT(e.prenom,' ',e.nom) as ens_nom, e.matricule as ens_matricule,
           CONCAT(u.prenom,' ',u.nom) as coord_nom,
           CONCAT(t.prenom,' ',t.nom) as traite_par_nom,
           a.libelle as annee_libelle
    FROM demandes_paiement_enseignant d
    JOIN enseignants e ON e.id = d.enseignant_id
    JOIN users u ON u.id = d.coordinateur_id
    LEFT JOIN users t ON t.id = d.traite_par
    LEFT JOIN annees_academiques a ON a.id = d.annee_id
    {$demandesWhere}
    ORDER BY d.created_at DESC
");
$stmt->execute($demandesParams);
$demandes = $stmt->fetchAll();

// Counts
$nbEnAttente = count(array_filter($demandes, fn($d) => $d['statut'] === 'en_attente'));
$nbTraite    = count(array_filter($demandes, fn($d) => $d['statut'] === 'traite'));
$nbRejete    = count(array_filter($demandes, fn($d) => $d['statut'] === 'rejete'));

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();
$csrfToken   = generateCsrfToken();

$statusCfg = [
    'en_attente' => ['label' => 'En attente', 'class' => 'warning',   'icon' => 'fa-clock'],
    'traite'     => ['label' => 'Traité',      'class' => 'success',   'icon' => 'fa-check-circle'],
    'rejete'     => ['label' => 'Rejeté',      'class' => 'danger',    'icon' => 'fa-times-circle'],
];

$pageTitle  = 'Demandes de paiement — Enseignants';
$breadcrumb = ['Administration' => null, 'Demandes de paiement' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Demandes de Paiement Enseignants</h2>
    <div class="text-muted fs-sm">
      <?php if ($role === 'comptable'): ?>
        Demandes en attente de règlement
      <?php elseif ($role === 'coordinateur'): ?>
        Vos demandes envoyées à la comptabilité
      <?php else: ?>
        Toutes les demandes
      <?php endif; ?>
    </div>
  </div>
  <?php if (in_array($role, ['coordinateur', 'admin'])): ?>
  <a href="<?= APP_URL ?>/modules/administration/suivi_cours.php" class="btn btn-primary">
    <i class="fas fa-tasks me-2"></i>Suivi des cours
  </a>
  <?php endif; ?>
</div>

<?php showFlash(); ?>

<!-- ── Stats cards ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbEnAttente ?></div><div class="stat-label">En attente</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbTraite ?></div><div class="stat-label">Traitées</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card" style="background:linear-gradient(135deg,#ea4335,#c62828);color:#fff">
      <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbRejete ?></div><div class="stat-label">Rejetées</div></div>
    </div>
  </div>
</div>

<!-- ── Table ───────────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="fas fa-list me-2 text-primary"></i>
      <?php if ($role === 'comptable'): ?>
        Demandes en attente de paiement
      <?php else: ?>
        Liste des demandes
      <?php endif; ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Enseignant</th>
          <?php if ($role !== 'coordinateur'): ?>
          <th>Coordinateur</th>
          <?php endif; ?>
          <th>Période</th>
          <th>Heures</th>
          <th>Détail matières</th>
          <th>Statut</th>
          <th>Date demande</th>
          <th style="width:160px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($demandes)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-inbox d-block mb-2" style="font-size:2.5rem;opacity:.25"></i>
              Aucune demande<?= $role === 'comptable' ? ' en attente' : '' ?>.
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($demandes as $d):
          $sc   = $statusCfg[$d['statut']] ?? ['label'=>$d['statut'],'class'=>'secondary','icon'=>'fa-question'];
          $isDet = !empty($d['details_matieres']);
        ?>
        <tr>
          <td>
            <div class="fw-600"><i class="fas fa-user-tie me-1 text-muted"></i><?= h($d['ens_nom']) ?></div>
            <small class="text-muted"><code><?= h($d['ens_matricule']) ?></code></small>
          </td>
          <?php if ($role !== 'coordinateur'): ?>
          <td class="text-muted fs-sm"><?= h($d['coord_nom']) ?></td>
          <?php endif; ?>
          <td class="text-nowrap fs-sm">
            <i class="fas fa-calendar me-1 text-muted"></i>
            <?= formatDate($d['date_debut']) ?><br>
            <span class="text-muted">→</span> <?= formatDate($d['date_fin']) ?>
          </td>
          <td>
            <span class="badge bg-primary fs-sm"><?= $d['total_heures'] ?>h</span>
          </td>
          <td style="max-width:200px">
            <?php if ($isDet): ?>
            <div class="fs-sm text-muted" style="white-space:pre-line;max-height:60px;overflow:hidden"><?= h($d['details_matieres']) ?></div>
            <?php else: ?>
            <span class="text-muted fs-sm">–</span>
            <?php endif; ?>
            <?php if ($d['notes_coordinateur']): ?>
            <div class="fs-sm mt-1 fst-italic text-muted"><?= h(mb_substr($d['notes_coordinateur'],0,60)) ?>…</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-<?= $sc['class'] ?>">
              <i class="fas <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
            </span>
            <?php if ($d['statut'] === 'traite' && $d['traite_par_nom']): ?>
            <div class="fs-sm text-muted mt-1">Par <?= h($d['traite_par_nom']) ?><br><?= formatDate(substr($d['traite_at'],0,10)) ?></div>
            <?php elseif ($d['statut'] === 'rejete' && $d['notes_comptable']): ?>
            <div class="fs-sm text-danger mt-1 fst-italic">"<?= h(mb_substr($d['notes_comptable'],0,50)) ?>"</div>
            <?php endif; ?>
          </td>
          <td class="text-muted fs-sm text-nowrap"><?= formatDate(substr($d['created_at'],0,10)) ?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <!-- Comptable / Admin : traiter -->
              <?php if ($d['statut'] === 'en_attente' && in_array($role, ['comptable','admin'])): ?>
              <button class="btn btn-sm btn-success btn-traiter"
                data-id="<?= $d['id'] ?>"
                data-ens="<?= h($d['ens_nom']) ?>"
                data-heures="<?= $d['total_heures'] ?>"
                data-debut="<?= $d['date_debut'] ?>"
                data-fin="<?= $d['date_fin'] ?>"
                data-details="<?= h($d['details_matieres']) ?>"
                data-notes="<?= h($d['notes_coordinateur']) ?>"
                data-annee="<?= $d['annee_id'] ?>">
                <i class="fas fa-check me-1"></i>Traiter
              </button>
              <button class="btn btn-sm btn-outline-danger btn-rejeter"
                data-id="<?= $d['id'] ?>"
                data-ens="<?= h($d['ens_nom']) ?>">
                <i class="fas fa-times me-1"></i>Rejeter
              </button>
              <?php endif; ?>

              <!-- Coordinateur : supprimer si en attente -->
              <?php if ($d['statut'] === 'en_attente' && in_array($role, ['coordinateur','admin'])): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="demande_id" value="<?= $d['id'] ?>">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                  onclick="confirmDelete(this.form, 'cette demande')">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>

              <?php if ($d['statut'] === 'traite'): ?>
              <span class="badge bg-success-subtle text-success border border-success fs-sm py-2 px-3">
                <i class="fas fa-check-double"></i> Payé
              </span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal : Traiter la demande ─────────────────────────────────────────── -->
<div class="modal fade" id="modalTraiter" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Traiter la demande de paiement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="traiter">
        <input type="hidden" name="demande_id" id="t_demande_id">
        <input type="hidden" name="annee_id" id="t_annee_id">

        <div class="modal-body">
          <!-- Info demande -->
          <div class="bg-light rounded p-3 mb-3">
            <div class="row g-2">
              <div class="col-md-6">
                <div class="text-muted fs-sm">Enseignant</div>
                <div class="fw-bold" id="t_ens_nom">—</div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-sm">Heures déclarées</div>
                <div class="fw-bold text-primary" id="t_heures">—</div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-sm">Période</div>
                <div class="fw-bold fs-sm" id="t_periode">—</div>
              </div>
            </div>
            <div class="mt-2" id="t_details_wrap" style="display:none">
              <div class="text-muted fs-sm mb-1">Détail par matière :</div>
              <pre id="t_details" class="bg-white border rounded p-2 fs-sm mb-0" style="white-space:pre-wrap"></pre>
            </div>
            <div class="mt-2" id="t_notes_wrap" style="display:none">
              <div class="text-muted fs-sm">Notes du coordinateur :</div>
              <em class="fs-sm" id="t_notes"></em>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-600">Libellé du paiement <span class="text-danger">*</span></label>
              <input type="text" name="libelle" id="t_libelle" class="form-control"
                     placeholder="Ex: Vacation Juin 2025 – Informatique" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Montant (FCFA) <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant" id="t_montant" class="form-control"
                       min="0" step="500" required placeholder="0">
                <span class="input-group-text">FCFA</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="virement">Virement</option>
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date de paiement</label>
              <input type="date" name="date_paiement" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Année académique</label>
              <select name="annee_id" id="t_annee_sel" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>>
                    <?= h($a['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes du comptable</label>
              <input type="text" name="notes_comptable" class="form-control"
                     placeholder="Observations, référence de virement...">
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-check me-2"></i>Valider le paiement
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal : Rejeter ───────────────────────────────────────────────────── -->
<div class="modal fade" id="modalRejeter" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Rejeter la demande</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="rejeter">
        <input type="hidden" name="demande_id" id="r_demande_id">
        <div class="modal-body">
          <p class="text-muted">Demande de <strong id="r_ens_nom"></strong></p>
          <div class="mb-3">
            <label class="form-label">Motif de rejet <span class="text-danger">*</span></label>
            <textarea name="notes_comptable" class="form-control" rows="3"
                      placeholder="Expliquez la raison du rejet..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-times me-2"></i>Confirmer le rejet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.btn-traiter').forEach(btn => {
    btn.addEventListener('click', function () {
        const heures = this.dataset.heures;
        document.getElementById('t_demande_id').value = this.dataset.id;
        document.getElementById('t_ens_nom').textContent  = this.dataset.ens;
        document.getElementById('t_heures').textContent  = heures + 'h';
        document.getElementById('t_periode').textContent =
            formatDateFR(this.dataset.debut) + ' → ' + formatDateFR(this.dataset.fin);
        document.getElementById('t_annee_id').value = this.dataset.annee || '';

        const det = this.dataset.details;
        if (det) {
            document.getElementById('t_details').textContent = det;
            document.getElementById('t_details_wrap').style.display = '';
        } else {
            document.getElementById('t_details_wrap').style.display = 'none';
        }

        const notes = this.dataset.notes;
        if (notes) {
            document.getElementById('t_notes').textContent = notes;
            document.getElementById('t_notes_wrap').style.display = '';
        } else {
            document.getElementById('t_notes_wrap').style.display = 'none';
        }

        // Pre-fill libellé
        document.getElementById('t_libelle').value =
            'Vacation – ' + this.dataset.ens + ' – ' + heures + 'h';

        // Pre-select annee if available
        const anneeId = this.dataset.annee;
        if (anneeId) {
            const sel = document.getElementById('t_annee_sel');
            if (sel) sel.value = anneeId;
        }

        new bootstrap.Modal(document.getElementById('modalTraiter')).show();
    });
});

document.querySelectorAll('.btn-rejeter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('r_demande_id').value = this.dataset.id;
        document.getElementById('r_ens_nom').textContent = this.dataset.ens;
        new bootstrap.Modal(document.getElementById('modalRejeter')).show();
    });
});

function formatDateFR(ymd) {
    if (!ymd) return '–';
    const [y, m, d] = ymd.split('-');
    return d + '/' + m + '/' + y;
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
