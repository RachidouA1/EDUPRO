<?php
// Variables requises : $filiere, $annee_label, $semestre_num, $session_num,
//                      $session_type, $annee_etude, $etudiants, $ues,
//                      $matieres_by_ue, $notes_data

// Export Excel
if (isset($_GET['export_excel'])) {
    header('Content-Type: application/vnd.ms-excel');
    $fname = 'PV_Global_' . ($filiere['code']??'filiere') . '_S' . $semestre_num . '_' . date('Y-m-d') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    $nb_cols = count($ues) * 2 + 4;

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:11px">';

    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center;font-size:14px;font-weight:bold">';
    echo 'ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD – PROCÈS VERBAL GLOBAL<br>';
    echo htmlspecialchars($filiere['nom']??'') . ' – Semestre ' . $semestre_num . ' (Année ' . $annee_etude . ')<br>';
    echo ($session_num == 1 ? '1ÈRE SESSION' : '2ÈME SESSION') . ' – ' . htmlspecialchars($annee_label??'');
    echo '</td></tr>';

    echo '<tr style="background:#1a5276;color:white"><th rowspan="2">N°</th><th rowspan="2">Matricule</th><th rowspan="2">Étudiant</th>';
    foreach ($ues as $ue) {
        $nb = isset($matieres_by_ue[$ue['id']]) ? count($matieres_by_ue[$ue['id']]) : 0;
        echo '<th colspan="' . ($nb + 1) . '">' . htmlspecialchars($ue['code_ue']) . '</th>';
    }
    echo '<th rowspan="2">Moyenne</th><th rowspan="2">Décision</th></tr>';

    echo '<tr style="background:#2e86ab;color:white">';
    foreach ($ues as $ue) {
        if (!empty($matieres_by_ue[$ue['id']])) {
            foreach ($matieres_by_ue[$ue['id']] as $m) {
                echo '<th style="font-size:9px">' . htmlspecialchars($m['nom']) . '<br>(' . $m['coefficient'] . ')</th>';
            }
        } else {
            echo '<th>–</th>';
        }
        echo '<th>Moy UE</th>';
    }
    echo '</tr>';

    foreach ($etudiants as $idx => $e) {
        $row_bg = ($idx % 2 === 0) ? '#fff' : '#f5f5f5';
        echo '<tr style="background:' . $row_bg . '">';
        echo '<td style="text-align:center">' . ($idx+1) . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($e['matricule']??'-') . '</td>';
        echo '<td>' . strtoupper($e['nom']??'') . ' ' . ucfirst($e['prenom']??'') . '</td>';

        foreach ($ues as $ue) {
            $moy_ue  = $notes_data[$e['id']][$ue['id']]['moyenne_ue'] ?? 0;
            $ue_val  = $notes_data[$e['id']][$ue['id']]['valide']     ?? false;
            if (!empty($matieres_by_ue[$ue['id']])) {
                foreach ($matieres_by_ue[$ue['id']] as $m) {
                    $note_m = null;
                    $note_v = false;
                    foreach ($notes_data[$e['id']][$ue['id']]['matieres']??[] as $nd) {
                        if ($nd['nom'] === $m['nom']) { $note_m = $nd['note']; $note_v = $nd['valide']; break; }
                    }
                    $bg = $note_v ? '#d4edda' : ($note_m !== null ? '#f8d7da' : '#fff');
                    echo '<td style="text-align:center;background:' . $bg . '">' . ($note_m !== null ? number_format($note_m,1) : '–') . '</td>';
                }
            } else {
                echo '<td>–</td>';
            }
            $ue_bg = $ue_val ? '#d4edda' : '#f8d7da';
            echo '<td style="text-align:center;font-weight:bold;background:' . $ue_bg . '">' . number_format($moy_ue,1) . '</td>';
        }
        $moy_g  = $notes_data[$e['id']]['moyenne_generale'] ?? 0;
        $dec    = $notes_data[$e['id']]['decision'] ?? '';
        $dec_bg = $dec === 'VALIDÉ' ? '#d4edda' : '#f8d7da';
        echo '<td style="text-align:center;font-weight:bold;background:#e3f2fd">' . number_format($moy_g,1) . '</td>';
        echo '<td style="text-align:center;font-weight:bold;background:' . $dec_bg . '">' . $dec . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center">PV global établi le ' . date('d/m/Y à H:i') . ' – Cachet et signature du Directeur</td></tr>';
    echo '</table></body></html>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PV Global – <?= h($filiere['nom']??'') ?> – S<?= $semestre_num ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#eef1f7;padding:20px;font-size:12px;color:#212529}
    .pv-global{max-width:1400px;margin:0 auto}

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
    .tbl-pv{width:100%;border-collapse:collapse;font-size:11px}
    .tbl-pv thead tr:first-child th{background:#0f2d5c;color:#fff;font-size:11px;font-weight:600;padding:9px 6px;text-align:center;border-right:1px solid rgba(255,255,255,.15)}
    .tbl-pv thead tr.sub-head th{background:#1a5276;color:#fff;font-size:10px;font-weight:500;padding:7px 5px;text-align:center;border-right:1px solid rgba(255,255,255,.1)}
    .tbl-pv td{padding:7px 6px;border-bottom:1px solid #f0f0f0;text-align:center;vertical-align:middle}
    .tbl-pv tbody tr:hover{background:#f8fbff}
    .tbl-pv tbody tr:last-child td{border-bottom:none}
    .tbl-pv td.etu-col{text-align:left;padding-left:10px;font-weight:500;min-width:140px}
    .nv{background:#d4edda;color:#155724;font-weight:600}
    .nnv{background:#f8d7da;color:#721c24;font-weight:600}
    .moy-cell{background:#e3f2fd!important;font-weight:700;color:#0c5460}

    .legende{background:#fff;border-radius:8px;padding:8px 14px;margin-top:12px;font-size:10px;box-shadow:0 1px 4px rgba(0,0,0,.05);display:flex;gap:16px;align-items:center;flex-wrap:wrap}
    .legende span{display:inline-flex;align-items:center;gap:5px}
    .leg-v{background:#d4edda;color:#155724;padding:2px 7px;border-radius:4px}
    .leg-nv{background:#f8d7da;color:#721c24;padding:2px 7px;border-radius:4px}

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
      .tbl-pv thead tr:first-child th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .tbl-pv thead tr.sub-head th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .nv{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .nnv{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .moy-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      img{max-width:100%!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
  </style>
</head>
<body>
<div class="pv-global">

  <div class="doc-header">
    <div class="doc-header-info">
      <h1>ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD</h1>
      <h2>PROCÈS VERBAL GLOBAL – NIVEAU SUPÉRIEUR</h2>
      <div class="doc-header-meta">
        <?= h($filiere['nom']??'') ?> &nbsp;|&nbsp; Semestre <?= $semestre_num ?> (Année <?= $annee_etude ?>) &nbsp;|&nbsp; <?= h($annee_label??'') ?>
      </div>
      <div class="badge-sess s<?= $session_num ?>">
        <?= $session_num == 1 ? 'PREMIÈRE SESSION' : 'DEUXIÈME SESSION (RATTRAPAGE)' ?>
      </div>
    </div>
  </div>

  <div class="info-bar">
    <span><strong>Effectif :</strong>&nbsp;<?= count($etudiants) ?> étudiant(s)</span>
    <span><strong>Date :</strong>&nbsp;<?= date('d/m/Y') ?></span>
    <span><strong>Filière :</strong>&nbsp;<?= h($filiere['nom']??'') ?></span>
  </div>

  <?php if (empty($etudiants)): ?>
    <div style="text-align:center;color:#999;padding:40px;background:#fff;border-radius:10px">
      <i class="fas fa-users" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>
      Aucun étudiant trouvé pour cette filière et cette année d'étude.
    </div>
  <?php elseif (empty($ues)): ?>
    <div style="text-align:center;color:#999;padding:40px;background:#fff;border-radius:10px">
      <i class="fas fa-book" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>
      Aucune UE définie pour ce semestre.
    </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="tbl-pv">
      <thead>
        <tr>
          <th rowspan="2" style="min-width:30px">N°</th>
          <th rowspan="2" style="min-width:90px">Matricule</th>
          <th rowspan="2" style="text-align:left;padding-left:10px;min-width:150px">Étudiant</th>
          <?php foreach ($ues as $ue):
            $nb_mat = isset($matieres_by_ue[$ue['id']]) ? count($matieres_by_ue[$ue['id']]) : 0; ?>
            <th colspan="<?= $nb_mat + 1 ?>"><?= h($ue['code_ue']) ?></th>
          <?php endforeach; ?>
          <th rowspan="2">Moy.</th>
          <th rowspan="2">Décision</th>
        </tr>
        <tr class="sub-head">
          <?php foreach ($ues as $ue): ?>
            <?php foreach ($matieres_by_ue[$ue['id']]??[] as $m): ?>
              <th style="word-break:break-word;max-width:90px;white-space:normal">
                <?= h($m['nom']) ?>
              </th>
            <?php endforeach; ?>
            <?php if (empty($matieres_by_ue[$ue['id']])): ?><th>–</th><?php endif; ?>
            <th>Moy UE</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($etudiants as $idx => $e): ?>
        <tr>
          <td><?= $idx + 1 ?></td>
          <td style="font-family:monospace;font-size:10px"><?= h($e['matricule']??'-') ?></td>
          <td class="etu-col"><?= strtoupper(h($e['nom']??'')) ?> <?= ucfirst(h($e['prenom']??'')) ?></td>

          <?php foreach ($ues as $ue): ?>
            <?php
              $moy_ue = $notes_data[$e['id']][$ue['id']]['moyenne_ue'] ?? 0;
              $ue_val = $notes_data[$e['id']][$ue['id']]['valide']     ?? false;
            ?>
            <?php foreach ($matieres_by_ue[$ue['id']]??[] as $m): ?>
              <?php
                $note_m = null; $note_v = false;
                foreach ($notes_data[$e['id']][$ue['id']]['matieres']??[] as $nd) {
                    if ($nd['nom'] === $m['nom']) { $note_m = $nd['note']; $note_v = $nd['valide']; break; }
                }
              ?>
              <td class="<?= $note_v ? 'nv' : ($note_m !== null ? 'nnv' : '') ?>">
                <?= ($note_m !== null && $note_m > 0) ? number_format($note_m,1) : '–' ?>
              </td>
            <?php endforeach; ?>
            <?php if (empty($matieres_by_ue[$ue['id']])): ?><td>–</td><?php endif; ?>
            <td class="<?= $ue_val ? 'nv' : 'nnv' ?> moy-cell">
              <?= number_format($moy_ue,1) ?>
            </td>
          <?php endforeach; ?>

          <?php
            $moy_g = $notes_data[$e['id']]['moyenne_generale'] ?? 0;
            $dec   = $notes_data[$e['id']]['decision'] ?? '';
          ?>
          <td class="moy-cell"><strong><?= number_format($moy_g,1) ?></strong></td>
          <td class="<?= $dec === 'VALIDÉ' ? 'nv' : 'nnv' ?>">
            <strong><?= h($dec) ?></strong>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="legende">
    <strong>Légende :</strong>
    <span><span class="leg-v">✓ Note ≥ seuil / UE validée</span></span>
    <span><span class="leg-nv">✗ Note insuffisante / UE non validée</span></span>
  </div>
  <?php endif; ?>

  <div class="doc-footer">
    PV global établi le <?= date('d/m/Y à H:i') ?> &nbsp;|&nbsp; EPSI – École Privée de Santé Ibn Rochd &nbsp;|&nbsp; Cachet et signature du Directeur
  </div>
</div>

<div class="btn-actions">
  <button class="btn-print" onclick="window.print()">
    <i class="fas fa-print"></i> Imprimer
  </button>
  <button class="btn-excel" onclick="window.location.href='?<?= http_build_query(array_merge($_GET,['export_excel'=>1])) ?>'">
    <i class="fas fa-file-excel"></i> Excel
  </button>
  <a href="pv.php" class="btn-back">
    <i class="fas fa-arrow-left"></i> Retour
  </a>
</div>
</body>
</html>
