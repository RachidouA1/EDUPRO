<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$id      = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM enseignants WHERE id = ?");
$stmt->execute([$id]);
$ens = $stmt->fetch();

if (!$ens) {
    setFlash('error', 'Enseignant introuvable.');
    redirect('/modules/enseignants/index.php');
}

$errors = [];

// Matières actuellement assignées à cet enseignant
$qAssigned = $db->prepare("SELECT id FROM matieres WHERE enseignant_id = ?");
$qAssigned->execute([$id]);
$assignedIds = array_column($qAssigned->fetchAll(), 'id');
$assignedIds = array_map('intval', $assignedIds);

// Toutes les matières disponibles pour cette école
if ($ecoleId > 0) {
    $qMat = $db->prepare("
        SELECT m.id, m.code, m.nom,
               f.nom AS filiere_nom, n.nom AS niveau_nom,
               m.enseignant_id,
               CONCAT(e.prenom, ' ', e.nom) AS ens_actuel
        FROM matieres m
        LEFT JOIN filieres f ON f.id = m.filiere_id
        LEFT JOIN niveaux n ON n.id = m.niveau_id
        LEFT JOIN enseignants e ON e.id = m.enseignant_id
        WHERE m.actif = 1 AND m.ecole_id = ?
        ORDER BY f.nom, m.nom
    ");
    $qMat->execute([$ecoleId]);
} else {
    $qMat = $db->query("
        SELECT m.id, m.code, m.nom,
               f.nom AS filiere_nom, n.nom AS niveau_nom,
               m.enseignant_id,
               CONCAT(e.prenom, ' ', e.nom) AS ens_actuel
        FROM matieres m
        LEFT JOIN filieres f ON f.id = m.filiere_id
        LEFT JOIN niveaux n ON n.id = m.niveau_id
        LEFT JOIN enseignants e ON e.id = m.enseignant_id
        WHERE m.actif = 1
        ORDER BY f.nom, m.nom
    ");
}
$allMatieres = $qMat->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $data = [
            'nom'              => sanitize($_POST['nom']             ?? ''),
            'prenom'           => sanitize($_POST['prenom']          ?? ''),
            'sexe'             => in_array($_POST['sexe'] ?? '', ['M','F']) ? $_POST['sexe'] : '',
            'date_naissance'   => sanitize($_POST['date_naissance']  ?? ''),
            'telephone'        => sanitize($_POST['telephone']       ?? ''),
            'email'            => filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL),
            'adresse'          => sanitize($_POST['adresse']         ?? ''),
            'type_contrat'     => in_array($_POST['type_contrat'] ?? '', ['permanent','vacataire']) ? $_POST['type_contrat'] : 'permanent',
            'date_recrutement' => sanitize($_POST['date_recrutement']?? ''),
            'actif'            => isset($_POST['actif']) ? 1 : 0,
        ];

        if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
        if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';

        if (empty($errors)) {
            $db->prepare("UPDATE enseignants
                SET nom=?,prenom=?,sexe=?,date_naissance=?,telephone=?,email=?,adresse=?,
                    type_contrat=?,date_recrutement=?,actif=?
                WHERE id=?")
               ->execute([
                    $data['nom'], $data['prenom'], $data['sexe'],
                    $data['date_naissance'] ?: null, $data['telephone'] ?: null,
                    $data['email'] ?: null, $data['adresse'] ?: null,
                    $data['type_contrat'], $data['date_recrutement'] ?: null,
                    $data['actif'], $id,
               ]);

            // Réassigner les matières
            // 1. Désassigner toutes les matières actuellement liées à cet enseignant
            $db->prepare("UPDATE matieres SET enseignant_id = NULL WHERE enseignant_id = ?")
               ->execute([$id]);

            // 2. Assigner les matières sélectionnées
            $selectedMatieres = array_values(array_filter(
                array_map('intval', $_POST['matieres'] ?? []),
                fn($v) => $v > 0
            ));
            if (!empty($selectedMatieres)) {
                $ph = implode(',', array_fill(0, count($selectedMatieres), '?'));
                $db->prepare("UPDATE matieres SET enseignant_id = ? WHERE id IN ($ph)")
                   ->execute(array_merge([$id], $selectedMatieres));
                // Mettre à jour le champ specialite
                $qNames = $db->prepare("SELECT GROUP_CONCAT(nom ORDER BY nom SEPARATOR ', ') FROM matieres WHERE id IN ($ph)");
                $qNames->execute($selectedMatieres);
                $db->prepare("UPDATE enseignants SET specialite = ? WHERE id = ?")
                   ->execute([$qNames->fetchColumn(), $id]);
            } else {
                $db->prepare("UPDATE enseignants SET specialite = NULL WHERE id = ?")
                   ->execute([$id]);
            }

            setFlash('success', 'Enseignant mis à jour.');
            redirect('/modules/enseignants/index.php');
        }

        // En cas d'erreur, conserver la sélection soumise
        $assignedIds = array_map('intval', $_POST['matieres'] ?? []);
    }
}

// IDs affichés (sélection POST ou données actuelles)
$displaySelected = $assignedIds;

$pageTitle  = 'Modifier ' . h($ens['prenom'] . ' ' . $ens['nom']);
$breadcrumb = ['Enseignants' => APP_URL . '/modules/enseignants/index.php', 'Modifier' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-edit me-2 text-primary"></i>Modifier l'enseignant</h2>
</div>

<form method="POST" novalidate>
  <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">

    <!-- Informations personnelles -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>Informations personnelles</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nom *</label>
              <input type="text" name="nom" class="form-control"
                     value="<?= h($_POST['nom'] ?? $ens['nom']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Prénom(s) *</label>
              <input type="text" name="prenom" class="form-control"
                     value="<?= h($_POST['prenom'] ?? $ens['prenom']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sexe</label>
              <select name="sexe" class="form-select">
                <option value="M" <?= ($_POST['sexe'] ?? $ens['sexe']) === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= ($_POST['sexe'] ?? $ens['sexe']) === 'F' ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naissance" class="form-control"
                     value="<?= h($_POST['date_naissance'] ?? $ens['date_naissance']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Téléphone</label>
              <input type="tel" name="telephone" class="form-control"
                     value="<?= h($_POST['telephone'] ?? $ens['telephone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($_POST['email'] ?? $ens['email']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Adresse</label>
              <input type="text" name="adresse" class="form-control"
                     value="<?= h($_POST['adresse'] ?? $ens['adresse']) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Sélection des matières -->
      <div class="card mt-4">
        <div class="card-header">
          <i class="fas fa-book-open me-2 text-primary"></i>Matières enseignées
          <small class="text-muted ms-2">
            <?= count($displaySelected) ?> assignée(s)
          </small>
        </div>
        <div class="card-body">
          <?php if (empty($allMatieres)): ?>
            <div class="alert alert-warning mb-0">
              <i class="fas fa-exclamation-triangle me-2"></i>
              Aucune matière enregistrée.
              <a href="<?= APP_URL ?>/modules/pedagogique/matieres.php" class="alert-link">Créer des matières d'abord.</a>
            </div>
          <?php else: ?>
            <div class="mb-3">
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="matiere-search" class="form-control"
                       placeholder="Rechercher par nom, code ou filière…"
                       oninput="filterMatieres(this.value)">
                <button type="button" class="btn btn-outline-secondary" onclick="filterMatieres(''); document.getElementById('matiere-search').value=''">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <div class="d-flex gap-3 mt-2 fs-sm text-muted">
                <span id="mat-count-total"><?= count($allMatieres) ?> matière(s)</span>
                <span id="mat-count-selected">0 sélectionnée(s)</span>
                <span id="mat-count-visible" class="d-none"></span>
              </div>
            </div>
            <div class="row g-2" id="matieres-grid">
              <?php foreach ($allMatieres as $m):
                $isChecked  = in_array((int)$m['id'], $displaySelected);
                $isOtherEns = $m['enseignant_id'] && (int)$m['enseignant_id'] !== $id && !$isChecked;
                $cardCls    = $isChecked ? 'border-primary bg-primary bg-opacity-10' : 'border';
              ?>
              <div class="col-md-6 col-lg-4">
                <label class="d-block rounded p-2 <?= $cardCls ?>" style="cursor:pointer;transition:all .15s"
                       id="lbl_<?= $m['id'] ?>">
                  <div class="d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1 flex-shrink-0" type="checkbox"
                           name="matieres[]" value="<?= $m['id'] ?>"
                           <?= $isChecked ? 'checked' : '' ?>
                           onchange="toggleCard(this)">
                    <div>
                      <span class="badge bg-primary me-1"><?= h($m['code']) ?></span>
                      <strong class="fs-sm"><?= h($m['nom']) ?></strong>
                      <?php if ($m['filiere_nom'] || $m['niveau_nom']): ?>
                        <div class="text-muted" style="font-size:.73rem">
                          <?= h(implode(' · ', array_filter([$m['filiere_nom'], $m['niveau_nom']]))) ?>
                        </div>
                      <?php endif; ?>
                      <?php if ($isOtherEns): ?>
                        <div class="text-warning" style="font-size:.7rem">
                          <i class="fas fa-exclamation-triangle me-1"></i>Assigné à <?= h($m['ens_actuel']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-3 d-flex align-items-center gap-3">
              <span class="text-muted fs-sm">
                <i class="fas fa-info-circle me-1 text-primary"></i>
                Décocher une matière la désassigne de cet enseignant.
              </span>
              <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                      onclick="clearMatieres()">
                <i class="fas fa-times me-1"></i>Tout décocher
              </button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Informations professionnelles -->
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><i class="fas fa-briefcase me-2 text-primary"></i>Poste &amp; Statut</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Type de contrat</label>
            <select name="type_contrat" class="form-select">
              <option value="permanent" <?= ($_POST['type_contrat'] ?? $ens['type_contrat']) === 'permanent' ? 'selected' : '' ?>>Permanent</option>
              <option value="vacataire" <?= ($_POST['type_contrat'] ?? $ens['type_contrat']) === 'vacataire' ? 'selected' : '' ?>>Vacataire</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Date de recrutement</label>
            <input type="date" name="date_recrutement" class="form-control"
                   value="<?= h($_POST['date_recrutement'] ?? $ens['date_recrutement']) ?>">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="actif" id="actif" value="1"
                   <?= ($_POST['actif'] ?? $ens['actif']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="actif">Actif</label>
          </div>
          <div class="alert alert-info py-2 fs-sm mb-3">
            <i class="fas fa-id-badge me-1"></i>
            Matricule : <strong><?= h($ens['matricule']) ?></strong>
          </div>
          <div class="alert alert-info py-2 fs-sm mb-0">
            <i class="fas fa-calculator me-2"></i>
            <strong>Salaire calculé par le coordinateur</strong>
            <div class="mt-1">En fonction des heures de cours effectuées.</div>
            <a href="<?= APP_URL ?>/modules/administration/salaires_enseignants.php"
               class="btn btn-sm btn-outline-primary mt-2 w-100">
              <i class="fas fa-money-bill-wave me-1"></i>Calcul des salaires
            </a>
          </div>
        </div>
      </div>
    </div>

  </div>

  <div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary px-4">
      <i class="fas fa-save me-2"></i>Enregistrer
    </button>
    <a href="<?= APP_URL ?>/modules/enseignants/index.php" class="btn btn-light px-4">
      <i class="fas fa-times me-2"></i>Annuler
    </a>
  </div>
</form>

<script>
function toggleCard(checkbox) {
    const lbl = checkbox.closest('label');
    lbl.classList.toggle('border-primary', checkbox.checked);
    lbl.classList.toggle('bg-primary',     checkbox.checked);
    lbl.classList.toggle('bg-opacity-10',  checkbox.checked);
    updateSelectedCount();
}
function clearMatieres() {
    document.querySelectorAll('#matieres-grid input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        toggleCard(cb);
    });
}
function filterMatieres(query) {
    const q = query.toLowerCase().trim();
    const cols = document.querySelectorAll('#matieres-grid > .col-md-6, #matieres-grid > .col-lg-4');
    let visible = 0;
    cols.forEach(col => {
        const text = col.textContent.toLowerCase();
        const show = !q || text.includes(q);
        col.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const total = cols.length;
    const countEl = document.getElementById('mat-count-visible');
    if (q) {
        countEl.textContent = visible + ' résultat(s) sur ' + total;
        countEl.classList.remove('d-none');
    } else {
        countEl.classList.add('d-none');
    }
}
function updateSelectedCount() {
    const n = document.querySelectorAll('#matieres-grid input[type=checkbox]:checked').length;
    document.getElementById('mat-count-selected').textContent = n + ' sélectionnée(s)';
}
document.addEventListener('DOMContentLoaded', updateSelectedCount);
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
