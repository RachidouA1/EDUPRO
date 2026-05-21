<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'scolarite']);

$db          = getDB();
$activeAnnee = getActiveAnnee();
$annees      = getAnneesAcademiques();
$filieres    = getFilieres();
$errors      = [];

// ─── Calcul validation : filières sans UE (ASB, VP) ──────────────────────────
function validationSimple(PDO $db, int $eid, int $annee_id, int $niveau_id): array
{
    $stmt = $db->prepare("
        SELECT m.coefficient,
               COALESCE(
                 (SELECT note_finale FROM notes WHERE etudiant_id=? AND matiere_id=m.id AND annee_id=? AND session=2 LIMIT 1),
                 (SELECT note_finale FROM notes WHERE etudiant_id=? AND matiere_id=m.id AND annee_id=? AND session=1 LIMIT 1)
               ) AS note
        FROM matieres m
        WHERE m.niveau_id=? AND m.actif=1
    ");
    $stmt->execute([$eid, $annee_id, $eid, $annee_id, $niveau_id]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return ['moyenne' => null, 'validated' => false, 'has_notes' => false, 'label' => 'Aucune matière', 'type' => 'simple'];
    }

    $pts = 0; $coef = 0; $saisies = 0;
    foreach ($rows as $r) {
        if ($r['note'] !== null) {
            $pts   += (float)$r['note'] * (float)$r['coefficient'];
            $coef  += (float)$r['coefficient'];
            $saisies++;
        }
    }

    $moy       = $coef > 0 ? round($pts / $coef, 2) : null;
    $validated = $moy !== null && $moy >= 10;

    return [
        'moyenne'   => $moy,
        'validated' => $validated,
        'has_notes' => $saisies > 0,
        'label'     => !$saisies ? 'Notes non saisies' : ($validated ? 'Admis(e)' : 'Insuffisant'),
        'type'      => 'simple',
    ];
}

// ─── Calcul validation : filières avec UE (INF, SF) ──────────────────────────
function validationUE(PDO $db, int $eid, int $annee_id, int $filiere_id, int $niveau_ordre): array
{
    $sem_a = $niveau_ordre * 2 - 1;
    $sem_b = $niveau_ordre * 2;

    $ue_total = 0; $ue_val = 0;
    $pts_gen  = 0; $coef_gen = 0;
    $has_notes = false;

    foreach ([$sem_a, $sem_b] as $sn) {
        $stmt = $db->prepare("SELECT * FROM ue WHERE filiere_id=? AND semestre_num=? AND actif=1 ORDER BY id");
        $stmt->execute([$filiere_id, $sn]);
        $ues = $stmt->fetchAll();

        foreach ($ues as $ue) {
            $ue_total++;
            $stmt2 = $db->prepare("SELECT id, coefficient FROM matieres WHERE ue_id=? AND actif=1");
            $stmt2->execute([$ue['id']]);
            $mats = $stmt2->fetchAll();

            $pts_ue = 0; $coef_ue = 0;
            foreach ($mats as $mat) {
                // Session 2 prioritaire, sinon session 1
                $stmt3 = $db->prepare("SELECT note_finale FROM notes WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND session=2 LIMIT 1");
                $stmt3->execute([$eid, $mat['id'], $annee_id]);
                $note = $stmt3->fetchColumn();
                if ($note === false) {
                    $stmt3 = $db->prepare("SELECT note_finale FROM notes WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND session=1 LIMIT 1");
                    $stmt3->execute([$eid, $mat['id'], $annee_id]);
                    $note = $stmt3->fetchColumn();
                }
                if ($note !== false && $note !== null) {
                    $pts_ue  += (float)$note  * (float)$mat['coefficient'];
                    $coef_ue += (float)$mat['coefficient'];
                    $has_notes = true;
                }
            }

            $moy_ue    = $coef_ue > 0 ? $pts_ue / $coef_ue : 0;
            if ($moy_ue >= 10) $ue_val++;

            $pts_gen  += $moy_ue * (float)$ue['coefficient'];
            $coef_gen += (float)$ue['coefficient'];
        }
    }

    $moy       = $coef_gen > 0 ? round($pts_gen / $coef_gen, 2) : null;
    $validated = $ue_total > 0 && $ue_val === $ue_total;

    return [
        'moyenne'   => $moy,
        'validated' => $validated,
        'has_notes' => $has_notes,
        'ue_val'    => $ue_val,
        'ue_total'  => $ue_total,
        'label'     => !$has_notes
            ? 'Notes non saisies'
            : ($validated ? "Admis(e) – $ue_val/$ue_total UE" : "Refusé(e) – $ue_val/$ue_total UE"),
        'type'      => 'ue',
    ];
}

// ─── POST : Promotion ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promouvoir') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $ids        = array_filter(array_map('intval', (array)($_POST['etudiant_ids'] ?? [])));
        $annee_dest = (int)($_POST['annee_destination_id'] ?? 0);

        if (empty($ids)) {
            $errors[] = 'Aucun étudiant sélectionné.';
        } elseif (!$annee_dest) {
            $errors[] = 'Veuillez sélectionner l\'année académique de destination.';
        } else {
            $promoted = 0; $diplomes = 0;
            foreach ($ids as $eid) {
                $stmt = $db->prepare("
                    SELECT e.filiere_id, n.ordre AS niv_ordre
                    FROM etudiants e
                    JOIN niveaux n ON n.id = e.niveau_id
                    WHERE e.id=? AND e.statut='actif'
                ");
                $stmt->execute([$eid]);
                $row = $stmt->fetch();
                if (!$row) continue;

                $stmt = $db->prepare("SELECT id FROM niveaux WHERE filiere_id=? AND ordre=? LIMIT 1");
                $stmt->execute([$row['filiere_id'], $row['niv_ordre'] + 1]);
                $next_id = $stmt->fetchColumn();

                if ($next_id) {
                    $db->prepare("UPDATE etudiants SET niveau_id=?, annee_id=? WHERE id=?")
                       ->execute([$next_id, $annee_dest, $eid]);
                    $promoted++;
                } else {
                    $db->prepare("UPDATE etudiants SET statut='diplome' WHERE id=?")
                       ->execute([$eid]);
                    $diplomes++;
                }
            }

            $parts = [];
            if ($promoted) $parts[] = "$promoted étudiant(s) promu(s) au niveau suivant";
            if ($diplomes) $parts[] = "$diplomes étudiant(s) diplômé(s)";
            if ($parts) setFlash('success', implode(', ', $parts) . '.');

            redirect('/modules/etudiants/promotion.php?' . http_build_query(array_filter([
                'filiere_id' => (int)($_POST['back_filiere'] ?? 0),
                'niveau_id'  => (int)($_POST['back_niveau']  ?? 0),
                'annee_id'   => (int)($_POST['back_annee']   ?? 0),
                'filtre'     => sanitize($_POST['back_filtre'] ?? 'admis'),
            ])));
        }
    }
}

// ─── GET : Filtres + Calcul des résultats ────────────────────────────────────
$filiere_id = (int)($_GET['filiere_id'] ?? 0);
$niveau_id  = (int)($_GET['niveau_id']  ?? 0);
$annee_id   = (int)($_GET['annee_id']   ?? ($activeAnnee['id'] ?? 0));
$filtre     = in_array($_GET['filtre'] ?? '', ['admis', 'refus', 'tous']) ? $_GET['filtre'] : 'admis';

$niveaux_select = [];
if ($filiere_id) {
    $stmt = $db->prepare("SELECT * FROM niveaux WHERE filiere_id=? ORDER BY ordre");
    $stmt->execute([$filiere_id]);
    $niveaux_select = $stmt->fetchAll();
}

$resultats    = [];
$filiere_info = null;
$niveau_info  = null;
$has_ue       = false;
$next_niveau  = null;

if ($filiere_id && $niveau_id && $annee_id) {
    $stmt = $db->prepare("SELECT * FROM filieres WHERE id=?");
    $stmt->execute([$filiere_id]);
    $filiere_info = $stmt->fetch();

    $stmt = $db->prepare("SELECT * FROM niveaux WHERE id=?");
    $stmt->execute([$niveau_id]);
    $niveau_info = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) FROM ue WHERE filiere_id=? AND actif=1");
    $stmt->execute([$filiere_id]);
    $has_ue = (int)$stmt->fetchColumn() > 0;

    $stmt = $db->prepare("SELECT * FROM niveaux WHERE filiere_id=? AND ordre=? LIMIT 1");
    $stmt->execute([$filiere_id, ($niveau_info['ordre'] ?? 0) + 1]);
    $next_niveau = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT e.id, e.matricule, e.nom, e.prenom
        FROM etudiants e
        WHERE e.filiere_id=? AND e.niveau_id=? AND e.annee_id=? AND e.statut='actif'
        ORDER BY e.nom, e.prenom
    ");
    $stmt->execute([$filiere_id, $niveau_id, $annee_id]);
    $etudiants_raw = $stmt->fetchAll();

    foreach ($etudiants_raw as $etu) {
        $val = $has_ue
            ? validationUE($db, $etu['id'], $annee_id, $filiere_id, (int)$niveau_info['ordre'])
            : validationSimple($db, $etu['id'], $annee_id, $niveau_id);
        $resultats[] = array_merge($etu, $val);
    }

    // Tri : admis d'abord, puis refusés, puis en attente
    usort($resultats, fn($a, $b) =>
        (int)$b['validated'] - (int)$a['validated'] ?:
        (int)$b['has_notes'] - (int)$a['has_notes'] ?:
        strcmp($a['nom'], $b['nom'])
    );
}

$cnt_admis   = count(array_filter($resultats, fn($r) => $r['validated']));
$cnt_refus   = count(array_filter($resultats, fn($r) => !$r['validated'] && $r['has_notes']));
$cnt_attente = count(array_filter($resultats, fn($r) => !$r['has_notes']));

// Filtre d'affichage
$affichage = match ($filtre) {
    'admis' => array_filter($resultats, fn($r) => $r['validated']),
    'refus' => array_filter($resultats, fn($r) => !$r['validated']),
    default => $resultats,
};

$pageTitle  = 'Passage en Classe Supérieure';
$breadcrumb = ['Étudiants' => APP_URL . '/modules/etudiants/index.php', 'Promotion' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-level-up-alt me-2 text-success"></i>Passage en Classe Supérieure</h2>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($e) ?></div>
<?php endforeach; ?>

<!-- ── Filtres ──────────────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-filter me-2"></i>Sélectionner une promotion</h6></div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <input type="hidden" name="filtre" value="<?= h($filtre) ?>">

      <div class="col-md-3">
        <label class="form-label fw-600">Filière</label>
        <select name="filiere_id" id="sel_filiere" class="form-select" required>
          <option value="">-- Filière --</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $filiere_id == $f['id'] ? 'selected' : '' ?>>
              <?= h($f['code']) ?> – <?= h($f['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label fw-600">Niveau actuel</label>
        <select name="niveau_id" id="sel_niveau" class="form-select" required>
          <option value="">-- Niveau --</option>
          <?php foreach ($niveaux_select as $n): ?>
            <option value="<?= $n['id'] ?>" <?= $niveau_id == $n['id'] ? 'selected' : '' ?>>
              <?= h($n['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label fw-600">Année académique</label>
        <select name="annee_id" class="form-select">
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $annee_id == $a['id'] ? 'selected' : '' ?>>
              <?= h($a['libelle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <button type="submit" class="btn btn-primary w-100">
          <i class="fas fa-search me-2"></i>Afficher les résultats
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($filiere_info && $niveau_info): ?>

<!-- ── Statistiques ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card text-center border-0" style="background:#e8f5e9">
      <div class="card-body py-3">
        <div class="fw-bold" style="font-size:2rem;color:#2e7d32"><?= $cnt_admis ?></div>
        <div class="text-muted fs-sm">Admis</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center border-0" style="background:#fce4ec">
      <div class="card-body py-3">
        <div class="fw-bold" style="font-size:2rem;color:#c62828"><?= $cnt_refus ?></div>
        <div class="text-muted fs-sm">Non admis</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center border-0" style="background:#fff8e1">
      <div class="card-body py-3">
        <div class="fw-bold" style="font-size:2rem;color:#f57f17"><?= $cnt_attente ?></div>
        <div class="text-muted fs-sm">En attente de notes</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center border-0" style="background:#e3f2fd">
      <div class="card-body py-3">
        <div class="fw-bold" style="font-size:2rem;color:#1565c0"><?= count($resultats) ?></div>
        <div class="text-muted fs-sm">Total étudiants</div>
      </div>
    </div>
  </div>
</div>

<!-- Contexte -->
<div class="alert alert-info py-2 mb-3 d-flex align-items-center gap-3 flex-wrap">
  <span>
    <i class="fas fa-info-circle me-1"></i>
    <strong><?= h($filiere_info['code']) ?></strong> — <?= h($niveau_info['nom']) ?> — <?= h((array_filter($annees, fn($a) => $a['id'] == $annee_id)[array_key_first(array_filter($annees, fn($a) => $a['id'] == $annee_id))]['libelle'] ?? '')) ?>
  </span>
  <?php if ($next_niveau): ?>
    <span class="badge bg-success">Promotion vers : <?= h($next_niveau['nom']) ?></span>
  <?php else: ?>
    <span class="badge bg-primary">Dernière année → Diplômé</span>
  <?php endif; ?>
  <span class="badge <?= $has_ue ? 'bg-purple' : 'bg-secondary' ?>" style="<?= $has_ue ? 'background:#6f42c1!important' : '' ?>">
    <?= $has_ue ? 'Système UE (niveaux supérieurs)' : 'Système simple (moyenne ≥ 10)' ?>
  </span>
</div>

<?php if (!empty($resultats)): ?>

<!-- ── Onglets de filtre ─────────────────────────────────────────────────────── -->
<ul class="nav nav-pills mb-3 gap-2">
  <?php
  $tabs = [
    'admis' => ['label' => "Admis ($cnt_admis)",     'color' => 'success'],
    'refus' => ['label' => "Non admis ($cnt_refus)", 'color' => 'danger'],
    'tous'  => ['label' => "Tous (" . count($resultats) . ")", 'color' => 'secondary'],
  ];
  foreach ($tabs as $k => $t):
    $active = $filtre === $k;
    $qs = http_build_query(['filiere_id' => $filiere_id, 'niveau_id' => $niveau_id, 'annee_id' => $annee_id, 'filtre' => $k]);
  ?>
    <li class="nav-item">
      <a class="nav-link <?= $active ? 'active bg-' . $t['color'] : 'text-' . $t['color'] . ' border border-' . $t['color'] ?>"
         href="?<?= $qs ?>">
        <?= $t['label'] ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<!-- ── Formulaire de promotion ───────────────────────────────────────────────── -->
<form method="POST" id="formPromotion">
  <input type="hidden" name="action"       value="promouvoir">
  <input type="hidden" name="csrf"         value="<?= h(generateCsrfToken()) ?>">
  <input type="hidden" name="back_filiere" value="<?= $filiere_id ?>">
  <input type="hidden" name="back_niveau"  value="<?= $niveau_id ?>">
  <input type="hidden" name="back_annee"   value="<?= $annee_id ?>">
  <input type="hidden" name="back_filtre"  value="admis">

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <div class="form-check mb-0">
          <input type="checkbox" class="form-check-input" id="selectAll">
          <label class="form-check-label fw-600" for="selectAll">Tout sélectionner</label>
        </div>
        <span class="badge bg-secondary" id="selCount">0 sélectionné(s)</span>
      </div>
      <?php if ($filtre === 'admis' || $filtre === 'tous'): ?>
      <button type="button" class="btn btn-sm btn-outline-success" id="btnSelectAdmis">
        <i class="fas fa-check-circle me-1"></i>Sélectionner tous les admis
      </button>
      <?php endif; ?>
    </div>

    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:40px"></th>
            <th>Matricule</th>
            <th>Nom & Prénom</th>
            <th class="text-center">Moyenne</th>
            <?php if ($has_ue): ?><th class="text-center">UE validées</th><?php endif; ?>
            <th>Résultat</th>
            <th class="text-center">Destination</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($affichage as $r): ?>
          <?php
            $rowClass = $r['validated'] ? 'table-success' : ($r['has_notes'] ? 'table-danger' : '');
            // Find next niveau name
            $dest_label = $next_niveau ? h($next_niveau['nom']) : 'Diplômé';
            $dest_badge = $next_niveau ? 'bg-success' : 'bg-primary';
          ?>
          <tr class="<?= $rowClass ?>">
            <td>
              <?php if ($r['validated']): ?>
              <input type="checkbox" class="form-check-input chk-etu" name="etudiant_ids[]" value="<?= $r['id'] ?>">
              <?php else: ?>
              <span class="text-muted" title="Non admis – ne peut pas être promu"><i class="fas fa-ban"></i></span>
              <?php endif; ?>
            </td>
            <td><a href="<?= APP_URL ?>/modules/etudiants/view.php?id=<?= $r['id'] ?>" class="fw-600 text-decoration-none"><?= h($r['matricule']) ?></a></td>
            <td><?= h($r['nom']) ?> <?= h($r['prenom']) ?></td>
            <td class="text-center">
              <?php if ($r['moyenne'] !== null): ?>
                <span class="fw-bold <?= $r['validated'] ? 'text-success' : 'text-danger' ?>"><?= number_format($r['moyenne'], 2) ?>/20</span>
              <?php else: ?>
                <span class="text-muted">–</span>
              <?php endif; ?>
            </td>
            <?php if ($has_ue): ?>
            <td class="text-center">
              <span class="badge <?= $r['validated'] ? 'bg-success' : 'bg-danger' ?>">
                <?= $r['ue_val'] ?? 0 ?>/<?= $r['ue_total'] ?? 0 ?>
              </span>
            </td>
            <?php endif; ?>
            <td>
              <?php if ($r['validated']): ?>
                <span class="badge bg-success"><i class="fas fa-check me-1"></i><?= h($r['label']) ?></span>
              <?php elseif ($r['has_notes']): ?>
                <span class="badge bg-danger"><i class="fas fa-times me-1"></i><?= h($r['label']) ?></span>
              <?php else: ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i><?= h($r['label']) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($r['validated']): ?>
                <span class="badge <?= $dest_badge ?>"><?= $dest_label ?></span>
              <?php else: ?>
                <span class="text-muted fs-sm">–</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($affichage)): ?>
          <tr><td colspan="<?= $has_ue ? 7 : 6 ?>" class="text-center text-muted py-4">Aucun étudiant dans cette catégorie.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pied : action de promotion -->
    <div class="card-footer">
      <div class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label fw-600 mb-1">Année académique de destination</label>
          <select name="annee_destination_id" class="form-select" required>
            <option value="">-- Sélectionner l'année de destination --</option>
            <?php foreach ($annees as $a): ?>
              <option value="<?= $a['id'] ?>" <?= ($activeAnnee && $a['id'] == $activeAnnee['id']) ? 'selected' : '' ?>>
                <?= h($a['libelle']) ?> <?= ($activeAnnee && $a['id'] == $activeAnnee['id']) ? '(active)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <button type="submit" class="btn btn-success w-100" id="btnPromouvoir" disabled>
            <i class="fas fa-level-up-alt me-2"></i>
            Promouvoir les sélectionnés
            <?php if ($next_niveau): ?>
              → <?= h($next_niveau['nom']) ?>
            <?php else: ?>
              → Diplômés
            <?php endif; ?>
          </button>
        </div>
        <div class="col-md-3">
          <div class="alert alert-warning py-2 mb-0 fs-sm">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Action <strong>irréversible</strong>. Vérifiez la sélection.
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php else: ?>
<div class="card"><div class="card-body empty-state">
  <i class="fas fa-user-graduate"></i>
  <h5>Aucun étudiant trouvé</h5>
  <p class="text-muted">Aucun étudiant actif dans cette filière / niveau / année.</p>
</div></div>
<?php endif; ?>

<?php elseif ($filiere_id || $niveau_id): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Veuillez sélectionner une filière, un niveau et une année académique.</div>
<?php else: ?>
<div class="card"><div class="card-body empty-state">
  <i class="fas fa-level-up-alt" style="color:#34a853"></i>
  <h5>Passage en Classe Supérieure</h5>
  <p class="text-muted">Sélectionnez une filière, un niveau et une année académique pour afficher les résultats et promouvoir les étudiants admis.</p>
</div></div>
<?php endif; ?>

<script>
// Filière → Niveau (dépendant)
document.getElementById('sel_filiere').addEventListener('change', function () {
  const fid = this.value;
  const sel  = document.getElementById('sel_niveau');
  sel.innerHTML = '<option value="">-- Niveau --</option>';
  if (!fid) return;
  fetch('<?= APP_URL ?>/api/niveaux.php?filiere_id=' + fid)
    .then(r => r.json())
    .then(data => {
      data.forEach(n => {
        const opt = new Option(n.nom, n.id);
        sel.add(opt);
      });
    });
});

// Sélection des checkboxes
const chks = () => document.querySelectorAll('.chk-etu');
const countSel = () => [...chks()].filter(c => c.checked).length;

function updateUI() {
  const n = countSel();
  const el = document.getElementById('selCount');
  if (el) el.textContent = n + ' sélectionné(s)';
  const btn = document.getElementById('btnPromouvoir');
  if (btn) btn.disabled = n === 0;
  const all = document.getElementById('selectAll');
  if (all) {
    const total = chks().length;
    all.indeterminate = n > 0 && n < total;
    all.checked = total > 0 && n === total;
  }
}

document.getElementById('selectAll')?.addEventListener('change', function () {
  chks().forEach(c => c.checked = this.checked);
  updateUI();
});

document.querySelectorAll('.chk-etu').forEach(c => c.addEventListener('change', updateUI));

document.getElementById('btnSelectAdmis')?.addEventListener('click', function () {
  chks().forEach(c => c.checked = true);
  document.getElementById('selectAll').checked = true;
  updateUI();
});

// Confirmation avant soumission
document.getElementById('formPromotion')?.addEventListener('submit', function (e) {
  const n    = countSel();
  const dest = document.querySelector('[name="annee_destination_id"]')?.selectedOptions[0]?.text ?? '';
  if (!confirm(`Promouvoir ${n} étudiant(s) vers l'année : ${dest} ?\n\nCette action est irréversible.`)) {
    e.preventDefault();
  }
});

updateUI();
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
