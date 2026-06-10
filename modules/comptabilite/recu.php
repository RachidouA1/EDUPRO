<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable']);

$db      = getDB();
$user    = getCurrentUser();
$ecoleId = getEcoleId();
$errors  = [];

// Ensure numero_recu column exists (idempotent)
try {
    $db->exec("ALTER TABLE paiements_etudiants ADD COLUMN numero_recu VARCHAR(20) NULL");
} catch (PDOException) { /* already exists */ }

// Generate receipt number: REC-YYYY-NNNNN (sequential per year)
function genNumeroRecu(PDO $db, string $date): string {
    $year = date('Y', strtotime($date));
    $stmt = $db->prepare("SELECT COUNT(*) FROM paiements_etudiants WHERE YEAR(date_paiement)=? AND numero_recu IS NOT NULL AND numero_recu != ''");
    $stmt->execute([$year]);
    $n = (int)$stmt->fetchColumn() + 1;
    return 'REC-' . $year . '-' . str_pad($n, 5, '0', STR_PAD_LEFT);
}

// ===== PRINT MODE =====
$printId = (int)($_GET['print'] ?? 0);
if ($printId) {
    $stmt = $db->prepare("
        SELECT pe.*,
               e.nom as etu_nom, e.prenom as etu_prenom, e.matricule,
               f.nom as filiere_nom, f.code as filiere_code,
               n.nom as niveau_nom,
               a.libelle as annee_libelle
        FROM paiements_etudiants pe
        JOIN etudiants e ON e.id = pe.etudiant_id
        LEFT JOIN filieres f ON f.id = e.filiere_id
        LEFT JOIN niveaux n ON n.id = e.niveau_id
        LEFT JOIN annees_academiques a ON a.id = pe.annee_id
        WHERE pe.id = ?
    ");
    $stmt->execute([$printId]);
    $pay = $stmt->fetch();

    if (!$pay) {
        setFlash('error', 'Reçu introuvable.');
        redirect('/modules/comptabilite/recu.php');
    }

    // Assign receipt number if not yet set
    if (empty($pay['numero_recu'])) {
        $num = genNumeroRecu($db, $pay['date_paiement']);
        $db->prepare("UPDATE paiements_etudiants SET numero_recu=? WHERE id=? AND (numero_recu IS NULL OR numero_recu='')")
           ->execute([$num, $printId]);
        $pay['numero_recu'] = $num;
    }

    $reste     = (float)$pay['montant'] - (float)$pay['montant_paye'];
    $statusMap = [
        'complet'    => ['lbl' => 'PAYÉ INTÉGRALEMENT', 'color' => '#2e7d32', 'bg' => '#e8f5e9', 'border' => '#4caf50'],
        'partiel'    => ['lbl' => 'PAIEMENT PARTIEL',   'color' => '#e65100', 'bg' => '#fff8e1', 'border' => '#ff9800'],
        'en_attente' => ['lbl' => 'EN ATTENTE',          'color' => '#c62828', 'bg' => '#fce4ec', 'border' => '#ef9a9a'],
    ];
    $sm = $statusMap[$pay['statut']] ?? $statusMap['en_attente'];
    $autoPrint = isset($_GET['auto_print']);
    $recuSidebarColor = getParam('theme_couleur_sidebar', '#0f2d5c');
    $recuLogo         = getLogoUrl();
    $recuNom          = getParam('etablissement_nom', 'EPSI');
    $recuSlogan       = getParam('etablissement_slogan', 'École Privée de Santé Ibn Rochd');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu <?= h($pay['numero_recu']) ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:#e8edf2;padding:1.2rem;color:#222}
.wrap{max-width:720px;margin:0 auto}

/* ── Boutons écran ── */
.controls{text-align:center;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:.4rem;justify-content:center}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem 1.1rem;border-radius:6px;font-size:.83rem;font-weight:600;text-decoration:none;border:none;cursor:pointer}
.btn-primary{background:#1a73e8;color:#fff}.btn-primary:hover{background:#1558c0}
.btn-secondary{background:#6c757d;color:#fff}.btn-secondary:hover{background:#5a6268}
.btn-warning{background:#f57c00;color:#fff}.btn-warning:hover{background:#d56800}

/* ── Modal versement complémentaire ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:10px;padding:1.4rem;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25)}
.modal-box h4{font-size:.95rem;color:#0f2d5c;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem}
.fgrp{margin-bottom:.75rem}
.fgrp label{display:block;font-size:.75rem;font-weight:700;color:#555;margin-bottom:.25rem}
.fctl{width:100%;padding:.4rem .65rem;border:1px solid #d0d5dd;border-radius:5px;font-size:.85rem;font-family:Arial,sans-serif}
.fctl:focus{outline:none;border-color:#1a73e8;box-shadow:0 0 0 2px rgba(26,115,232,.15)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}
.mfooter{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.85rem;border-top:1px solid #eee}
.btn-cancel{background:#fff;color:#555;border:1px solid #ccc;padding:.4rem 1rem;border-radius:5px;cursor:pointer;font-size:.83rem;font-weight:600}
.btn-save{background:#1a73e8;color:#fff;border:none;padding:.4rem 1.1rem;border-radius:5px;cursor:pointer;font-size:.83rem;font-weight:700}
.reste-info{background:#fff8e1;border:1px solid #f9a825;border-radius:5px;padding:.4rem .75rem;margin-bottom:.85rem;font-size:.78rem;color:#795548}

/* ══════════════════════════════
   REÇU — format demi-A4
   Cible imprimée : ~200mm × 130mm
═══════════════════════════════ */
.recu{background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.12)}

/* Bandeau supérieur */
.r-head{color:#fff;padding:.55rem .9rem;display:flex;justify-content:space-between;align-items:center;gap:.75rem}
.r-head-left{display:flex;align-items:center;gap:.6rem}
.r-head-left img{width:38px;height:38px;object-fit:contain;background:#fff;border-radius:5px;padding:3px;flex-shrink:0}
.r-school-name{font-size:.82rem;font-weight:700;letter-spacing:.2px;line-height:1.2}
.r-school-sub{font-size:.6rem;opacity:.78;margin-top:.1rem}
.r-num{text-align:right;flex-shrink:0}
.r-num-label{font-size:.55rem;text-transform:uppercase;letter-spacing:1px;opacity:.7}
.r-num-value{font-size:.88rem;font-weight:700;font-family:monospace;letter-spacing:.5px}
.r-num-date{font-size:.62rem;opacity:.82;margin-top:.1rem}

/* Bandeau titre */
.r-title{background:#1a73e8;color:#fff;text-align:center;padding:.28rem;font-size:.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase}

/* Corps */
.r-body{padding:.6rem .9rem}

/* Ligne séparatrice */
.r-sep{border:none;border-top:1px dashed #d8dde5;margin:.45rem 0}

/* Grille infos 2 colonnes */
.r-grid{display:grid;grid-template-columns:1fr 1fr;gap:.18rem .5rem}
.r-item{display:flex;align-items:baseline;gap:.3rem;font-size:.74rem;line-height:1.5}
.r-item .lbl{color:#888;white-space:nowrap;min-width:0;flex-shrink:0}
.r-item .val{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.r-item.full{grid-column:1/-1}

/* Bloc montants */
.r-amounts{background:#f8fafd;border:1px solid #e3eaf4;border-radius:5px;padding:.45rem .7rem;display:flex;align-items:center;gap:0;margin:.45rem 0}
.r-amt-col{flex:1;text-align:center;padding:0 .3rem}
.r-amt-col+.r-amt-col{border-left:1px solid #dde4ef}
.r-amt-label{font-size:.58rem;text-transform:uppercase;letter-spacing:.8px;color:#888;font-weight:700}
.r-amt-value{font-size:.92rem;font-weight:700;margin-top:.1rem}
.amt-due{color:#1a3a6e}
.amt-paid{color:#2e7d32}
.amt-rest{color:#c62828}

/* Badge statut */
.r-status{display:inline-block;padding:.18rem .7rem;border-radius:50px;font-size:.65rem;font-weight:700;border:1px solid;margin:.3rem 0}

/* Signatures */
.r-sigs{display:flex;gap:1rem;margin-top:.55rem;padding-top:.4rem;border-top:1px solid #e5e5e5}
.r-sig{flex:1;text-align:center}
.r-sig-label{font-size:.6rem;color:#888;margin-bottom:.7rem}
.r-sig-line{border-bottom:1px solid #bbb;margin:0 .5rem}

/* Pied */
.r-foot{background:#f5f7fa;border-top:1px solid #e8ecf0;padding:.28rem .9rem;text-align:center;font-size:.58rem;color:#aaa}

/* ── Impression ── */
@media print {
  body{background:#fff;padding:0}
  .controls,.modal-overlay{display:none!important}
  .recu{box-shadow:none;border-radius:0}
  @page{size:A4 portrait;margin:1cm}
}
</style>
</head>
<body>
<div class="wrap">

  <div class="controls">
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimer</button>
    <a href="<?= APP_URL ?>/modules/comptabilite/recu.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour</a>
    <a href="<?= APP_URL ?>/modules/etudiants/paiements.php?id=<?= $pay['etudiant_id'] ?>" class="btn btn-secondary"><i class="fas fa-receipt"></i> Tous les paiements</a>
    <?php if ($pay['statut'] !== 'complet' && hasRole(['admin', 'comptable'])): ?>
    <button class="btn btn-warning" onclick="openVersement()"><i class="fas fa-plus"></i> Ajouter un versement</button>
    <?php endif; ?>
  </div>

  <div class="recu">

    <!-- ── En-tête ── -->
    <div class="r-head" style="background:<?= h($recuSidebarColor) ?>">
      <div class="r-head-left">
        <?php if ($recuLogo): ?>
          <img src="<?= h($recuLogo) ?>" alt="Logo">
        <?php endif; ?>
        <div>
          <div class="r-school-name"><?= h($recuNom) ?></div>
          <div class="r-school-sub"><?= h($recuSlogan) ?> &bull; Tahoua – Niger</div>
        </div>
      </div>
      <div class="r-num">
        <div class="r-num-label">Reçu N°</div>
        <div class="r-num-value"><?= h($pay['numero_recu']) ?></div>
        <div class="r-num-date"><?= date('d/m/Y', strtotime($pay['date_paiement'])) ?></div>
      </div>
    </div>

    <!-- ── Titre ── -->
    <div class="r-title">Reçu de Paiement</div>

    <!-- ── Corps ── -->
    <div class="r-body">

      <!-- Infos étudiant + versement -->
      <div class="r-grid">
        <div class="r-item full">
          <span class="lbl">Apprenant&nbsp;:</span>
          <span class="val"><?= h(strtoupper($pay['etu_nom']).' '.$pay['etu_prenom']) ?>
            &ensp;<span style="font-family:monospace;font-weight:400;color:#555;font-size:.7rem"><?= h($pay['matricule']) ?></span>
          </span>
        </div>
        <?php if ($pay['filiere_nom']): ?>
        <div class="r-item">
          <span class="lbl">Filière&nbsp;:</span>
          <span class="val"><?= h($pay['filiere_code'].' – '.$pay['filiere_nom']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($pay['niveau_nom']): ?>
        <div class="r-item">
          <span class="lbl">Niveau&nbsp;:</span>
          <span class="val"><?= h($pay['niveau_nom']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($pay['annee_libelle']): ?>
        <div class="r-item">
          <span class="lbl">Année&nbsp;:</span>
          <span class="val"><?= h($pay['annee_libelle']) ?></span>
        </div>
        <?php endif; ?>
        <div class="r-item full">
          <span class="lbl">Libellé&nbsp;:</span>
          <span class="val"><?= h($pay['libelle']) ?></span>
        </div>
        <div class="r-item">
          <span class="lbl">Mode&nbsp;:</span>
          <span class="val"><?= ucfirst(str_replace('_',' ',h($pay['mode_paiement']))) ?></span>
        </div>
        <?php if (!empty($pay['reference'])): ?>
        <div class="r-item">
          <span class="lbl">Réf.&nbsp;:</span>
          <span class="val"><?= h($pay['reference']) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <hr class="r-sep">

      <!-- Bloc montants -->
      <div class="r-amounts">
        <div class="r-amt-col">
          <div class="r-amt-label">Montant dû</div>
          <div class="r-amt-value amt-due"><?= formatMontant($pay['montant']) ?></div>
        </div>
        <div class="r-amt-col">
          <div class="r-amt-label">&#10003; Versé</div>
          <div class="r-amt-value amt-paid"><?= formatMontant($pay['montant_paye']) ?></div>
        </div>
        <?php if ($reste > 0): ?>
        <div class="r-amt-col">
          <div class="r-amt-label">Reste à payer</div>
          <div class="r-amt-value amt-rest"><?= formatMontant($reste) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Statut -->
      <div style="text-align:center">
        <span class="r-status" style="color:<?= $sm['color'] ?>;background:<?= $sm['bg'] ?>;border-color:<?= $sm['border'] ?>">
          <?= $sm['lbl'] ?>
        </span>
      </div>

      <!-- Signatures -->
      <div class="r-sigs">
        <div class="r-sig">
          <div class="r-sig-label">Signature du caissier / responsable</div>
          <div class="r-sig-line"></div>
        </div>
        <div class="r-sig">
          <div class="r-sig-label">Cachet et signature de la Direction</div>
          <div class="r-sig-line"></div>
        </div>
      </div>

    </div><!-- /r-body -->

    <div class="r-foot">
      EPSI &ndash; École Privée de Santé Ibn Rochd &nbsp;&bull;&nbsp;
      Ce document tient lieu de reçu officiel &nbsp;&bull;&nbsp;
      Émis le <?= date('d/m/Y à H:i') ?>
    </div>

  </div><!-- /recu -->
</div><!-- /wrap -->

<!-- ── Modal : versement complémentaire ── -->
<?php if ($pay['statut'] !== 'complet' && hasRole(['admin', 'comptable'])): ?>
<div id="versementOverlay" class="modal-overlay" onclick="if(event.target===this)closeVersement()">
  <div class="modal-box">
    <h4><i class="fas fa-plus-circle" style="color:#1a73e8"></i>Versement complémentaire</h4>
    <div class="reste-info">
      <i class="fas fa-clock" style="margin-right:.35rem"></i>
      Solde restant à régler : <strong><?= formatMontant($reste) ?></strong>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/comptabilite/recu.php">
      <input type="hidden" name="csrf"       value="<?= h(generateCsrfToken()) ?>">
      <input type="hidden" name="action"     value="add_versement">
      <input type="hidden" name="etudiant_id" value="<?= $pay['etudiant_id'] ?>">
      <input type="hidden" name="annee_id"   value="<?= h($pay['annee_id'] ?? '') ?>">
      <div class="fgrp">
        <label>Libellé</label>
        <input type="text" name="libelle" class="fctl" value="<?= h($pay['libelle']) ?>" required>
      </div>
      <div class="grid2">
        <div class="fgrp">
          <label>Solde à régler (FCFA)</label>
          <input type="number" name="montant" class="fctl" value="<?= $reste ?>" min="1" required>
        </div>
        <div class="fgrp">
          <label>Montant versé (FCFA)</label>
          <input type="number" name="montant_paye" class="fctl" value="<?= $reste ?>" min="1" required>
        </div>
        <div class="fgrp">
          <label>Date du versement</label>
          <input type="date" name="date_paiement" class="fctl" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="fgrp">
          <label>Mode de paiement</label>
          <select name="mode_paiement" class="fctl">
            <option value="especes">Espèces</option>
            <option value="cheque">Chèque</option>
            <option value="virement">Virement</option>
            <option value="mobile_money">Mobile Money</option>
          </select>
        </div>
      </div>
      <div class="fgrp">
        <label>Référence</label>
        <input type="text" name="reference" class="fctl" placeholder="N° chèque, transaction…">
      </div>
      <div class="mfooter">
        <button type="button" class="btn-cancel" onclick="closeVersement()">Annuler</button>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> &nbsp;Enregistrer &amp; Imprimer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),400));</script>
<?php endif; ?>
<script>
function openVersement()  { document.getElementById('versementOverlay').classList.add('open'); }
function closeVersement() { document.getElementById('versementOverlay').classList.remove('open'); }
document.addEventListener('keydown', e => { if(e.key==='Escape') closeVersement(); });
</script>
</body>
</html>
<?php
    exit;
}

// ===== VERSEMENT COMPLÉMENTAIRE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_versement') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('error', 'Jeton invalide.');
        redirect('/modules/comptabilite/recu.php');
    } elseif (!hasRole(['admin', 'comptable'])) {
        setFlash('error', 'Accès refusé.');
        redirect('/modules/comptabilite/recu.php');
    } else {
        $etuId   = (int)($_POST['etudiant_id']     ?? 0);
        $libelle = sanitize($_POST['libelle']       ?? '');
        $montant = (float)($_POST['montant']        ?? 0);
        $verse   = (float)($_POST['montant_paye']   ?? 0);
        $date    = sanitize($_POST['date_paiement'] ?? date('Y-m-d'));
        $mode    = sanitize($_POST['mode_paiement'] ?? 'especes');
        $ref     = sanitize($_POST['reference']     ?? '');
        $anneeId = (int)($_POST['annee_id']         ?? 0);

        if ($etuId && !empty($libelle) && $montant > 0 && $verse > 0) {
            $statut = ($verse >= $montant) ? 'complet' : 'partiel';

            $year  = date('Y', strtotime($date));
            $stmtN = $db->prepare("SELECT COUNT(*) FROM paiements_etudiants WHERE YEAR(date_paiement)=? AND numero_recu IS NOT NULL AND numero_recu != ''");
            $stmtN->execute([$year]);
            $numRecu = 'REC-' . $year . '-' . str_pad((int)$stmtN->fetchColumn() + 1, 5, '0', STR_PAD_LEFT);

            $db->prepare("INSERT INTO paiements_etudiants
                (etudiant_id, annee_id, libelle, montant, montant_paye, date_paiement, mode_paiement, reference, statut, numero_recu, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$etuId, $anneeId ?: null, $libelle, $montant, $verse, $date, $mode, $ref ?: null, $statut, $numRecu, $user['id']]);

            $payId = (int)$db->lastInsertId();

            $etuRow = $db->prepare("SELECT nom, prenom FROM etudiants WHERE id=?");
            $etuRow->execute([$etuId]);
            $etuRow = $etuRow->fetch();
            if ($ecoleId > 0) {
                $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, created_by, ecole_id) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $date,
                       $libelle . ' – ' . ($etuRow['prenom'] ?? '') . ' ' . ($etuRow['nom'] ?? ''),
                       'scolarite', $verse, $mode, $numRecu, $user['id'], $ecoleId]);
            } else {
                $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, created_by) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$anneeId ?: null, $date,
                       $libelle . ' – ' . ($etuRow['prenom'] ?? '') . ' ' . ($etuRow['nom'] ?? ''),
                       'scolarite', $verse, $mode, $numRecu, $user['id']]);
            }

            redirect('/modules/comptabilite/recu.php?print=' . $payId . '&auto_print=1');
        } else {
            setFlash('error', 'Données du versement invalides.');
            redirect('/modules/comptabilite/recu.php');
        }
    }
}

// ===== SAVE NEW PAYMENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $errors[] = 'Jeton invalide.';
    } else {
        $etuId     = (int)($_POST['etudiant_id']     ?? 0);
        $typeFrais = sanitize($_POST['type_frais']    ?? '');
        $montant   = (float)($_POST['montant']        ?? 0);
        $verse     = (float)($_POST['montant_paye']   ?? 0);
        $date      = sanitize($_POST['date_recette']  ?? date('Y-m-d'));
        $mode      = sanitize($_POST['mode_paiement'] ?? 'especes');
        $ref       = sanitize($_POST['reference']     ?? '');
        $anneeId   = (int)($_POST['annee_id']         ?? 0);

        $libellesMap = [
            'inscription' => "Frais d'inscription",
            'scolarite'   => 'Frais de formation',
            'examen'      => "Frais d'examen",
        ];

        if (!$etuId)                                      $errors[] = 'Veuillez sélectionner un apprenant.';
        if (!array_key_exists($typeFrais, $libellesMap))  $errors[] = 'Sélectionnez un type de frais.';
        if ($montant <= 0)                                $errors[] = 'Le montant dû doit être supérieur à 0.';
        if ($verse < 0)                                   $errors[] = 'Montant versé invalide.';

        if (empty($errors)) {
            $libelle = $libellesMap[$typeFrais];
            $statut  = 'en_attente';
            if ($verse >= $montant) $statut = 'complet';
            elseif ($verse > 0)     $statut = 'partiel';

            $numRecu = genNumeroRecu($db, $date);

            $db->prepare("INSERT INTO paiements_etudiants
                (etudiant_id, annee_id, libelle, montant, montant_paye, date_paiement, mode_paiement, reference, statut, numero_recu, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$etuId, $anneeId ?: null, $libelle, $montant, $verse, $date, $mode, $ref ?: null, $statut, $numRecu, $user['id']]);

            $payId = (int)$db->lastInsertId();

            if ($verse > 0) {
                $etuRow = $db->prepare("SELECT nom, prenom FROM etudiants WHERE id=?");
                $etuRow->execute([$etuId]);
                $etuRow = $etuRow->fetch();
                if ($ecoleId > 0) {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, created_by, ecole_id) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$anneeId ?: null, $date,
                           $libelle . ' – ' . ($etuRow['prenom'] ?? '') . ' ' . ($etuRow['nom'] ?? ''),
                           $typeFrais, $verse, $mode, $numRecu, $user['id'], $ecoleId]);
                } else {
                    $db->prepare("INSERT INTO recettes (annee_id, date_recette, libelle, categorie, montant, mode_paiement, reference, created_by) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$anneeId ?: null, $date,
                           $libelle . ' – ' . ($etuRow['prenom'] ?? '') . ' ' . ($etuRow['nom'] ?? ''),
                           $typeFrais, $verse, $mode, $numRecu, $user['id']]);
                }
            }

            redirect('/modules/comptabilite/recu.php?print=' . $payId . '&auto_print=1');
        }
    }
}

// ===== LOAD DATA =====
// Student dropdown
$allEtudiants = $db->query("
    SELECT e.id, e.nom, e.prenom, e.matricule, f.code as filiere_code, n.nom as niveau_nom
    FROM etudiants e
    LEFT JOIN filieres f ON f.id=e.filiere_id
    LEFT JOIN niveaux n ON n.id=e.niveau_id
    WHERE e.statut='actif'
    ORDER BY e.nom, e.prenom
")->fetchAll();

$annees      = getAnneesAcademiques();
$anneeActive = getActiveAnnee();

// Filters
$filterEtu  = (int)($_GET['etudiant_id'] ?? 0);
$filterMois = sanitize($_GET['mois'] ?? '');
$where  = ['1=1'];
$params = [];
if ($filterEtu)  { $where[] = 'pe.etudiant_id=?'; $params[] = $filterEtu; }
if ($filterMois) { $where[] = "DATE_FORMAT(pe.date_paiement,'%Y-%m')=?"; $params[] = $filterMois; }

$stmt = $db->prepare("
    SELECT pe.*, e.nom as etu_nom, e.prenom as etu_prenom, e.matricule
    FROM paiements_etudiants pe
    JOIN etudiants e ON e.id = pe.etudiant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pe.date_paiement DESC, pe.id DESC
    LIMIT 300
");
$stmt->execute($params);
$recus = $stmt->fetchAll();

$totalVerse = array_sum(array_column($recus, 'montant_paye'));
$nbRecus    = count(array_filter($recus, fn($r) => !empty($r['numero_recu'])));

$pageTitle  = 'Reçus de paiement';
$breadcrumb = ['Comptabilité' => null, 'Reçus' => null];
include APP_ROOT . '/includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-file-invoice me-2 text-primary"></i>Reçus de Paiement</h2>
  <?php if (hasRole(['admin', 'comptable'])): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRecuModal">
    <i class="fas fa-plus me-2"></i>Nouveau versement
  </button>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($recus) ?></div><div class="stat-label">Paiements listés</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <div class="stat-icon"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $nbRecus ?></div><div class="stat-label">Reçus générés</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-teal">
      <div class="stat-icon"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.1rem"><?= formatMontant($totalVerse) ?></div><div class="stat-label">Total versé</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <?php $nbAttente = count(array_filter($recus, fn($r) => $r['statut'] === 'en_attente' || $r['statut'] === 'partiel')); ?>
        <div class="stat-value"><?= $nbAttente ?></div>
        <div class="stat-label">En attente / partiel</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label fw-bold fs-sm">Étudiant</label>
        <select name="etudiant_id" class="form-select">
          <option value="">Tous les étudiants</option>
          <?php foreach ($allEtudiants as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $filterEtu == $e['id'] ? 'selected' : '' ?>>
              <?= h($e['prenom'].' '.$e['nom']) ?> – <?= h($e['matricule']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-bold fs-sm">Mois</label>
        <input type="month" name="mois" class="form-control" value="<?= h($filterMois) ?>">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i>Filtrer</button>
        <a href="?" class="btn btn-light"><i class="fas fa-times"></i></a>
      </div>
    </form>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><strong><?= count($recus) ?></strong> paiement(s) – Versé : <strong class="text-success"><?= formatMontant($totalVerse) ?></strong></span>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print"><i class="fas fa-print me-1"></i>Imprimer liste</button>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>N° Reçu</th>
          <th>Étudiant</th>
          <th>Matricule</th>
          <th>Libellé</th>
          <th>Montant dû</th>
          <th>Versé</th>
          <th>Date</th>
          <th>Mode</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recus)): ?>
          <tr><td colspan="10" class="text-center py-4 text-muted"><i class="fas fa-inbox d-block mb-2" style="font-size:2rem;opacity:.3"></i>Aucun paiement</td></tr>
        <?php endif; ?>
        <?php foreach ($recus as $r): ?>
        <tr>
          <td>
            <?php if (!empty($r['numero_recu'])): ?>
              <code class="text-primary" style="font-size:.8rem"><?= h($r['numero_recu']) ?></code>
            <?php else: ?>
              <span class="text-muted fs-sm">—</span>
            <?php endif; ?>
          </td>
          <td class="fw-bold fs-sm"><?= h($r['etu_prenom'].' '.$r['etu_nom']) ?></td>
          <td><code class="fs-sm"><?= h($r['matricule']) ?></code></td>
          <td class="fs-sm"><?= h($r['libelle']) ?></td>
          <td><?= formatMontant($r['montant']) ?></td>
          <td class="text-success fw-600"><?= formatMontant($r['montant_paye']) ?></td>
          <td class="text-muted fs-sm"><?= formatDate($r['date_paiement']) ?></td>
          <td class="fs-sm"><?= ucfirst(str_replace('_', ' ', h($r['mode_paiement']))) ?></td>
          <td>
            <?php
              $ps = ['en_attente'=>'warning','partiel'=>'info','complet'=>'success'][$r['statut']] ?? 'secondary';
              $pl = ['en_attente'=>'En attente','partiel'=>'Partiel','complet'=>'Complet'][$r['statut']] ?? $r['statut'];
            ?>
            <span class="badge bg-<?= $ps ?>"><?= $pl ?></span>
          </td>
          <td>
            <a href="?print=<?= $r['id'] ?>" target="_blank" class="btn btn-icon btn-sm btn-outline-primary" title="Imprimer le reçu" data-bs-toggle="tooltip">
              <i class="fas fa-print"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Nouveau versement ===== -->
<?php if (hasRole(['admin', 'comptable'])): ?>
<div class="modal fade" id="newRecuModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:rgba(26,115,232,.07);border-bottom:2px solid #1a73e8">
        <h5 class="modal-title"><i class="fas fa-graduation-cap me-2 text-primary"></i>Versement – Frais de formation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">

          <!-- Type de frais -->
          <div class="mb-4">
            <label class="form-label fw-bold mb-2">Type de frais <span class="text-danger">*</span></label>
            <div class="row g-2">
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="recuTypeInscription" value="inscription" autocomplete="off" required>
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="recuTypeInscription" style="font-size:.85rem">
                  <i class="fas fa-id-card fa-lg"></i>Inscription
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="recuTypeScolarite" value="scolarite" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="recuTypeScolarite" style="font-size:.85rem">
                  <i class="fas fa-book-open fa-lg"></i>Formation
                </label>
              </div>
              <div class="col-4">
                <input type="radio" class="btn-check" name="type_frais" id="recuTypeExamen" value="examen" autocomplete="off">
                <label class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1" for="recuTypeExamen" style="font-size:.85rem">
                  <i class="fas fa-file-alt fa-lg"></i>Examen
                </label>
              </div>
            </div>
          </div>

          <!-- Sélection apprenant -->
          <div class="mb-3">
            <label class="form-label fw-bold">Apprenant <span class="text-danger">*</span></label>
            <div class="custom-student-select position-relative" id="recuStudentWrap">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-graduate text-primary" style="font-size:.85rem"></i></span>
                <input type="text" id="recuStudentDisplay" class="form-control border-start-0 border-end-0"
                       placeholder="Cliquer pour sélectionner un apprenant..." readonly style="cursor:pointer;background:#fff">
                <button type="button" id="recuStudentClear" class="btn btn-outline-secondary border-start-0" style="display:none" title="Effacer">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <input type="hidden" name="etudiant_id" id="recuStudentHidden" value="">

              <div id="recuStudentPanel" class="card shadow position-absolute w-100" style="display:none;z-index:1055;top:calc(100% + 4px)">
                <div class="p-2 border-bottom">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="recuStudentFilter" class="form-control" placeholder="Filtrer par nom, prénom ou matricule...">
                  </div>
                </div>
                <div id="recuStudentList" style="max-height:200px;overflow-y:auto">
                  <?php if (empty($allEtudiants)): ?>
                    <div class="text-center text-muted py-3" style="font-size:.85rem"><i class="fas fa-info-circle me-1"></i>Aucun apprenant actif</div>
                  <?php else: ?>
                    <?php foreach ($allEtudiants as $e): ?>
                    <div class="stu-opt d-flex align-items-center gap-2 px-3 py-2"
                         data-id="<?= $e['id'] ?>"
                         data-label="<?= h($e['prenom'].' '.$e['nom'].' ('.$e['matricule'].')') ?>"
                         data-search="<?= strtolower(h($e['nom'].' '.$e['prenom'].' '.$e['matricule'])) ?>"
                         style="cursor:pointer;transition:background .15s">
                      <div class="avatar-circle" style="background:#1a73e8;width:32px;height:32px;font-size:.7rem;flex-shrink:0">
                        <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                      </div>
                      <div style="min-width:0">
                        <div class="fw-600 fs-sm text-truncate"><?= h($e['prenom'].' '.$e['nom']) ?></div>
                        <div style="font-size:.73rem;color:#888">
                          <code><?= h($e['matricule']) ?></code>
                          <?php if ($e['filiere_code']): ?>&nbsp;·&nbsp;<?= h($e['filiere_code']) ?><?php endif; ?>
                          <?php if ($e['niveau_nom']): ?>&nbsp;<?= h($e['niveau_nom']) ?><?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <div id="recuNoResult" class="text-center text-muted py-3 d-none" style="font-size:.85rem">
                      <i class="fas fa-search-minus d-block mb-1"></i>Aucun résultat
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Montants et détails -->
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-bold">Montant dû <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant" id="recuMontantDu" class="form-control" min="0" required placeholder="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Montant versé <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="montant_paye" id="recuMontantVerse" class="form-control" min="0" placeholder="0" value="0">
                <span class="input-group-text text-muted" style="font-size:.8rem">FCFA</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Date du versement</label>
              <input type="date" name="date_recette" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="especes">Espèces</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Référence</label>
              <input type="text" name="reference" class="form-control" placeholder="N° chèque, transaction…">
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">Année académique</label>
              <select name="annee_id" class="form-select">
                <?php foreach ($annees as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($a['id'] == ($anneeActive['id'] ?? 0)) ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="alert alert-info mt-3 py-2 mb-0" style="font-size:.83rem">
            <i class="fas fa-print me-1"></i>
            Le reçu sera généré et imprimé automatiquement après l'enregistrement.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Enregistrer &amp; Imprimer le reçu</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<JS
<script>
// ===== Student searchable dropdown (recu modal) =====
(function() {
  const wrap    = document.getElementById('recuStudentWrap');
  if (!wrap) return;
  const display = document.getElementById('recuStudentDisplay');
  const hidden  = document.getElementById('recuStudentHidden');
  const panel   = document.getElementById('recuStudentPanel');
  const filter  = document.getElementById('recuStudentFilter');
  const list    = document.getElementById('recuStudentList');
  const clear   = document.getElementById('recuStudentClear');
  const noRes   = document.getElementById('recuNoResult');

  function open() {
    panel.style.display = '';
    filter.value = '';
    filterOpts('');
    setTimeout(() => filter.focus(), 30);
  }
  function close() { panel.style.display = 'none'; }

  function filterOpts(q) {
    let vis = 0;
    list.querySelectorAll('.stu-opt').forEach(o => {
      const ok = !q || o.dataset.search.includes(q.toLowerCase());
      o.style.display = ok ? '' : 'none';
      if (ok) vis++;
    });
    noRes.classList.toggle('d-none', vis > 0);
  }

  display.addEventListener('click', open);
  filter.addEventListener('input', () => filterOpts(filter.value.trim()));

  list.addEventListener('click', e => {
    const opt = e.target.closest('.stu-opt');
    if (!opt) return;
    hidden.value  = opt.dataset.id;
    display.value = opt.dataset.label;
    clear.style.display = '';
    close();
  });

  list.addEventListener('mouseover', e => { const o = e.target.closest('.stu-opt'); if (o) o.style.background = '#f0f4f8'; });
  list.addEventListener('mouseout',  e => { const o = e.target.closest('.stu-opt'); if (o) o.style.background = ''; });

  clear.addEventListener('click', () => {
    hidden.value  = '';
    display.value = '';
    clear.style.display = 'none';
  });

  document.addEventListener('click', e => { if (!wrap.contains(e.target)) close(); });
})();

// Auto-fill montant_paye when montant_du loses focus
(function () {
  var du    = document.getElementById('recuMontantDu');
  var verse = document.getElementById('recuMontantVerse');
  if (du && verse) {
    du.addEventListener('blur', function () {
      if (!parseFloat(verse.value)) verse.value = this.value;
    });
  }
})();
</script>
JS;

include APP_ROOT . '/includes/footer.php';
?>
