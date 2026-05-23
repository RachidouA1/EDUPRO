<?php
/**
 * Bulletin global – vue impression standalone
 * Paramètres GET : filiere_id, niveau_id, annee_id, semestre_id, session (1|2)
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$db = getDB();

// Migration idempotente : ajouter niveau_id aux matieres pour les filières sans semestre
try { $db->exec("ALTER TABLE matieres ADD COLUMN niveau_id INT NULL"); } catch (PDOException $e) {}
// Peupler niveau_id pour les matieres ASB/VP dont le code contient l'année (ex: ASB-A2-...)
try {
    $db->exec("
        UPDATE matieres m
        JOIN filieres f ON f.id = m.filiere_id
        JOIN niveaux n ON n.filiere_id = m.filiere_id
        SET m.niveau_id = n.id
        WHERE m.niveau_id IS NULL
          AND f.code IN ('ASB','VP')
          AND m.code LIKE CONCAT(f.code, '-A', n.ordre, '-%')
    ");
} catch (PDOException $e) {}

$filiere_id  = (int)($_GET['filiere_id']  ?? 0);
$annee_id    = (int)($_GET['annee_id']    ?? 0);
$semestre_id = (int)($_GET['semestre_id'] ?? 0);
$niveau_id   = (int)($_GET['niveau_id']   ?? 0);
$session_num = in_array((int)($_GET['session']??1),[1,2]) ? (int)$_GET['session'] : 1;

$SANS_SEMESTRE_CODES = ['ASB', 'VP'];

if (!$filiere_id || !$annee_id) {
    die('<p style="font-family:sans-serif;padding:2rem">Paramètres manquants.</p>');
}

// Filière
$stmt = $db->prepare("SELECT * FROM filieres WHERE id = ?");
$stmt->execute([$filiere_id]);
$filiere = $stmt->fetch();
if (!$filiere) {
    die('<p style="font-family:sans-serif;padding:2rem">Filière introuvable.</p>');
}

$isSansSemestre = in_array($filiere['code'] ?? '', $SANS_SEMESTRE_CODES);

if (!$isSansSemestre && !$semestre_id) {
    die('<p style="font-family:sans-serif;padding:2rem">Paramètres manquants.</p>');
}
if ($isSansSemestre && !$niveau_id) {
    die('<p style="font-family:sans-serif;padding:2rem">Veuillez sélectionner un niveau pour cette filière.</p>');
}

// Charger le niveau si applicable
$niveau = null;
if ($isSansSemestre && $niveau_id) {
    $stmt = $db->prepare("SELECT * FROM niveaux WHERE id = ? AND filiere_id = ?");
    $stmt->execute([$niveau_id, $filiere_id]);
    $niveau = $stmt->fetch();
    if (!$niveau) {
        die('<p style="font-family:sans-serif;padding:2rem">Niveau introuvable.</p>');
    }
}

// Semestre + année académique
$semestre = null;
if ($semestre_id) {
    $stmt = $db->prepare("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id = s.annee_id WHERE s.id = ?");
    $stmt->execute([$semestre_id]);
    $semestre = $stmt->fetch();
}
$stmt = $db->prepare("SELECT libelle FROM annees_academiques WHERE id = ?");
$stmt->execute([$annee_id]);
$annee_row = $stmt->fetch();
$annee_libelle = $semestre['annee_libelle'] ?? ($annee_row['libelle'] ?? '');

// Matières
if ($isSansSemestre) {
    // Filtre par niveau_id (colonne peuplée par la migration au-dessus)
    $stmt = $db->prepare("
        SELECT m.id, m.nom, m.code, m.coefficient
        FROM matieres m
        WHERE m.filiere_id = ? AND m.niveau_id = ? AND m.actif = 1
        ORDER BY m.nom
    ");
    $stmt->execute([$filiere_id, $niveau_id]);
} else {
    $stmt = $db->prepare("SELECT m.id, m.nom, m.code, m.coefficient FROM matieres m WHERE m.filiere_id = ? AND m.semestre_id = ? AND m.actif = 1 ORDER BY m.nom");
    $stmt->execute([$filiere_id, $semestre_id]);
}
$matieres = $stmt->fetchAll();

// Étudiants actifs (filtrés par niveau pour les filières sans semestre)
if ($isSansSemestre) {
    $stmt = $db->prepare("
        SELECT e.id, e.matricule, e.nom, e.prenom
        FROM etudiants e
        WHERE e.filiere_id = ? AND e.niveau_id = ? AND e.statut = 'actif'
        ORDER BY e.nom, e.prenom
    ");
    $stmt->execute([$filiere_id, $niveau_id]);
} else {
    $stmt = $db->prepare("SELECT e.id, e.matricule, e.nom, e.prenom FROM etudiants e WHERE e.filiere_id = ? AND e.statut = 'actif' ORDER BY e.nom, e.prenom");
    $stmt->execute([$filiere_id]);
}
$etudiants = $stmt->fetchAll();

// Charger toutes les notes
$notes_index = [];
if (!empty($etudiants) && !empty($matieres)) {
    $mat_ids = implode(',', array_map(fn($m) => (int)$m['id'], $matieres));
    $etu_ids = implode(',', array_map(fn($e) => (int)$e['id'], $etudiants));
    $rows = $db->query("
        SELECT etudiant_id, matiere_id, note_finale
        FROM notes
        WHERE etudiant_id IN ($etu_ids) AND matiere_id IN ($mat_ids)
          AND annee_id = $annee_id AND session = $session_num
    ")->fetchAll();
    foreach ($rows as $r) {
        $notes_index[$r['etudiant_id']][$r['matiere_id']] = $r['note_finale'];
    }
}

// Calculer moyennes
$moyennes = [];
foreach ($etudiants as $e) {
    $total_pts  = 0;
    $total_coef = 0;
    foreach ($matieres as $m) {
        $note = $notes_index[$e['id']][$m['id']] ?? null;
        if ($note !== null) {
            $total_pts  += floatval($note) * floatval($m['coefficient']);
            $total_coef += floatval($m['coefficient']);
        }
    }
    $moyennes[$e['id']] = $total_coef > 0 ? $total_pts / $total_coef : null;
}

// Export Excel
if (isset($_GET['export_excel'])) {
    header('Content-Type: application/vnd.ms-excel');
    $fname = 'Bulletin_Global_' . ($filiere['code']??'filiere') . '_' . date('Y-m-d') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');

    $nb_cols = count($matieres) + 6;
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:11px">';

    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center;font-size:14px;font-weight:bold">';
    echo 'ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD – BULLETIN DE NOTES GLOBAL<br>';
    echo htmlspecialchars($filiere['nom']??'');
    if ($niveau) echo ' – ' . htmlspecialchars($niveau['nom']??'');
    echo ($semestre ? ' – ' . htmlspecialchars($semestre['nom']??'') : '') . '<br>';
    echo ($session_num == 1 ? '1ÈRE SESSION' : '2ÈME SESSION (RATTRAPAGE)') . ' – ' . htmlspecialchars($annee_libelle);
    echo '</td></tr>';

    echo '<tr style="background:#1a5276;color:white">';
    echo '<th>N°</th><th>Matricule</th><th>Nom &amp; Prénom</th>';
    foreach ($matieres as $m) {
        echo '<th>' . htmlspecialchars($m['nom']??'') . '<br><small>(' . $m['coefficient'] . ')</small></th>';
    }
    echo '<th>Moyenne</th><th>Mention</th><th>Décision</th></tr>';

    foreach ($etudiants as $idx => $e) {
        $row_bg = ($idx % 2 === 0) ? '#fff' : '#f5f5f5';
        echo '<tr style="background:' . $row_bg . '">';
        echo '<td style="text-align:center">' . ($idx+1) . '</td>';
        echo '<td>' . htmlspecialchars($e['matricule']??'') . '</td>';
        echo '<td>' . strtoupper($e['nom']??'') . ' ' . ucfirst($e['prenom']??'') . '</td>';
        foreach ($matieres as $m) {
            $note = $notes_index[$e['id']][$m['id']] ?? null;
            $bg   = ($note !== null && $note >= 12) ? '#d4edda' : ($note !== null ? '#f8d7da' : '#fff');
            echo '<td style="text-align:center;background:' . $bg . '">' . ($note !== null ? number_format($note,1) : '–') . '</td>';
        }
        $moy = $moyennes[$e['id']];
        $mbg = ($moy !== null && $moy >= 12) ? '#d4edda' : ($moy !== null ? '#f8d7da' : '#fff');
        echo '<td style="text-align:center;font-weight:bold;background:' . $mbg . '">' . ($moy !== null ? number_format($moy,2) : '–') . '</td>';
        if ($moy === null)       $mention_xls = '–';
        elseif ($moy >= 16)      $mention_xls = 'Très Bien';
        elseif ($moy >= 14)      $mention_xls = 'Bien';
        elseif ($moy >= 12)      $mention_xls = 'Assez Bien';
        elseif ($moy >= 10)      $mention_xls = 'Passable';
        else                     $mention_xls = 'Insuffisant';
        $decision_xls = $moy === null ? '–' : ($moy >= 12 ? 'VALIDÉ' : 'AJOURNÉ');
        echo '<td style="text-align:center;font-weight:bold">' . $mention_xls . '</td>';
        echo '<td style="text-align:center;font-weight:bold;background:' . $mbg . '">' . $decision_xls . '</td>';
        echo '</tr>';
    }

    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center">Bulletin global édité le ' . date('d/m/Y à H:i') . ' – Cachet et signature du Directeur</td></tr>';
    echo '</table></body></html>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulletin Global – <?= h($filiere['nom']??'') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#eef1f7;padding:20px;font-size:12px;color:#212529}
    .bul-wrap{max-width:1400px;margin:0 auto}

    .doc-header{background:linear-gradient(135deg,#0f2d5c 0%,#1a5276 100%);border-radius:12px;padding:22px 28px;color:#fff;display:flex;align-items:center;gap:20px;margin-bottom:16px}
    .doc-header img{width:76px;height:76px;object-fit:cover;border-radius:50%;flex-shrink:0}
    .doc-header-info h1{font-size:17px;font-weight:700;margin:0 0 3px}
    .doc-header-info h2{font-size:12px;font-weight:400;opacity:.85;margin:0 0 7px}
    .doc-header-meta{font-size:12px;opacity:.9;margin-bottom:7px}
    .badge-sess{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600}
    .s1{background:#28a745;color:#fff}.s2{background:#fd7e14;color:#fff}

    .info-bar{background:#fff;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:12px;box-shadow:0 1px 6px rgba(0,0,0,.07);display:flex;gap:24px;align-items:center}
    .info-bar strong{color:#0f2d5c}

    .tbl-wrap{overflow-x:auto;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);background:#fff}
    .tbl{width:100%;border-collapse:collapse;font-size:11px}
    .tbl thead th{background:#0f2d5c;color:#fff;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;padding:9px 6px;text-align:center;border-right:1px solid rgba(255,255,255,.12)}
    .tbl thead th.etu-col{text-align:left;padding-left:10px}
    .tbl td{padding:8px 6px;border-bottom:1px solid #f0f0f0;text-align:center;vertical-align:middle}
    .tbl tbody tr:hover{background:#f8fbff}
    .tbl tbody tr:last-child td{border-bottom:none}
    .tbl td.etu-col{text-align:left;padding-left:10px;font-weight:500;min-width:150px}
    .nv{background:#d4edda;color:#155724;font-weight:600}
    .nnv{background:#f8d7da;color:#721c24;font-weight:600}
    .moy-cell{background:#e3f2fd!important;font-weight:700;color:#0c5460}

    .doc-footer{text-align:center;font-size:11px;color:#aaa;margin-top:14px;padding-top:10px;border-top:1px solid #e0e0e0}

    .btn-actions{position:fixed;bottom:20px;right:16px;display:flex;gap:10px;z-index:999}
    .btn-actions button,.btn-actions a{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:7px;box-shadow:0 3px 12px rgba(0,0,0,.2);transition:opacity .15s}
    .btn-actions button:hover,.btn-actions a:hover{opacity:.87}
    .btn-print{background:#1a73e8;color:#fff}
    .btn-excel{background:linear-gradient(135deg,#1e7e34,#28a745);color:#fff}
    .btn-back{background:#6c757d;color:#fff}

    @media print{
      body{background:#fff;padding:0}
      .btn-actions{display:none!important}
      .doc-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .tbl thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .nv{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .nnv{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .moy-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      img{max-width:100%!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
  </style>
</head>
<body>
<div class="bul-wrap">

  <div class="doc-header">
    <div class="doc-header-info">
      <h1>ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD</h1>
      <h2>BULLETIN DE NOTES GLOBAL</h2>
      <div class="doc-header-meta">
        <?= h($filiere['nom']??'') ?>
        <?php if ($niveau): ?> &nbsp;|&nbsp; <?= h($niveau['nom']??'') ?><?php endif; ?>
        <?= $semestre ? ' &nbsp;|&nbsp; ' . h($semestre['nom']??'') : '' ?>
        &nbsp;|&nbsp; <?= h($annee_libelle) ?>
      </div>
      <div class="badge-sess s<?= $session_num ?>">
        <?= $session_num == 1 ? 'PREMIÈRE SESSION' : 'DEUXIÈME SESSION (RATTRAPAGE)' ?>
      </div>
    </div>
  </div>

  <div class="info-bar">
    <span><strong>Effectif :</strong>&nbsp;<?= count($etudiants) ?> étudiant(s)</span>
    <?php if ($niveau): ?>
    <span><strong>Niveau :</strong>&nbsp;<?= h($niveau['nom']??'') ?></span>
    <?php endif; ?>
    <span><strong>Date :</strong>&nbsp;<?= date('d/m/Y') ?></span>
  </div>

  <?php if (empty($etudiants) || empty($matieres)): ?>
    <div style="text-align:center;color:#999;padding:40px;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06)">
      <i class="fas fa-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>
      <?= empty($etudiants) ? 'Aucun étudiant actif dans cette filière.' : 'Aucune matière définie pour ce semestre.' ?>
    </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th style="min-width:30px">N°</th>
          <th style="min-width:90px">Matricule</th>
          <th class="etu-col">Nom &amp; Prénom</th>
          <?php foreach ($matieres as $m): ?>
            <th style="min-width:80px;word-break:break-word;white-space:normal">
              <?= h($m['nom']??'') ?>
              <br><span style="font-weight:400;font-size:9px;opacity:.8">(<?= $m['coefficient'] ?>)</span>
            </th>
          <?php endforeach; ?>
          <th style="min-width:60px">Moy.</th>
          <th style="min-width:80px">Mention</th>
          <th style="min-width:80px">Décision</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($etudiants as $idx => $e): ?>
        <tr>
          <td><?= $idx + 1 ?></td>
          <td style="font-family:monospace;font-size:10px"><?= h($e['matricule']) ?></td>
          <td class="etu-col"><?= strtoupper(h($e['nom'])) ?> <?= ucfirst(h($e['prenom'])) ?></td>
          <?php foreach ($matieres as $m):
            $note = $notes_index[$e['id']][$m['id']] ?? null;
            $cls  = ($note !== null && $note >= 12) ? 'nv' : ($note !== null ? 'nnv' : '');
          ?>
            <td class="<?= $cls ?>">
              <?= $note !== null ? number_format($note,1) : '–' ?>
            </td>
          <?php endforeach; ?>
          <?php
            $moy     = $moyennes[$e['id']];
            $moy_cls = ($moy !== null && $moy >= 12) ? 'nv' : ($moy !== null ? 'nnv' : '');
            if ($moy === null)       $mention = '–';
            elseif ($moy >= 16)      $mention = 'Très Bien';
            elseif ($moy >= 14)      $mention = 'Bien';
            elseif ($moy >= 12)      $mention = 'Assez Bien';
            elseif ($moy >= 10)      $mention = 'Passable';
            else                     $mention = 'Insuffisant';
            $decision = $moy === null ? '–' : ($moy >= 12 ? 'VALIDÉ' : 'AJOURNÉ');
          ?>
          <td class="<?= $moy_cls ?> moy-cell">
            <?= $moy !== null ? number_format($moy,2) : '–' ?>
          </td>
          <td class="moy-cell" style="font-size:10px">
            <?= $mention ?>
          </td>
          <td class="<?= $moy_cls ?> moy-cell">
            <?= $decision ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="doc-footer">
    Bulletin global édité le <?= date('d/m/Y à H:i') ?> &nbsp;|&nbsp; EPSI – École Privée de Santé Ibn Rochd &nbsp;|&nbsp; Cachet et signature du Directeur
  </div>
</div>

<div class="btn-actions">
  <button class="btn-print" onclick="window.print()">
    <i class="fas fa-print"></i> Imprimer
  </button>
  <button class="btn-excel" onclick="window.location.href='?<?= http_build_query(array_merge($_GET,['export_excel'=>1])) ?>'">
    <i class="fas fa-file-excel"></i> Excel
  </button>
  <a href="bulletins.php" class="btn-back">
    <i class="fas fa-arrow-left"></i> Retour
  </a>
</div>
</body>
</html>
