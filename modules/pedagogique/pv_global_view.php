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

    // Compter toutes les colonnes matières + moy_ue + N° + Matricule + Étudiant + Moy + Décision
    $nb_mat_total = 0;
    foreach ($ues as $ue) { $nb_mat_total += count($matieres_by_ue[$ue['id']] ?? []); }
    $nb_cols = $nb_mat_total + count($ues) + 5;

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:11px">';

    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center;font-size:14px;font-weight:bold">';
    echo 'ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD – PROCÈS VERBAL GLOBAL<br>';
    echo htmlspecialchars($filiere['nom']??'') . ' – Semestre ' . $semestre_num . ' (Année ' . $annee_etude . ')<br>';
    echo ($session_num == 1 ? '1ÈRE SESSION' : '2ÈME SESSION') . ' – ' . htmlspecialchars($annee_label??'');
    echo '</td></tr>';

    // En-tête UE
    echo '<tr style="background:#1a5276;color:white">';
    echo '<th rowspan="2">N°</th><th rowspan="2">Matricule</th><th rowspan="2">Étudiant</th>';
    foreach ($ues as $ue) {
        $nb = count($matieres_by_ue[$ue['id']] ?? []);
        echo '<th colspan="' . ($nb + 1) . '">' . htmlspecialchars($ue['code_ue']) . ' – ' . htmlspecialchars($ue['nom']) . '</th>';
    }
    echo '<th rowspan="2">Moy. Gén.</th><th rowspan="2">Décision</th></tr>';

    // En-tête matières
    echo '<tr style="background:#2e86ab;color:white">';
    foreach ($ues as $ue) {
        foreach ($matieres_by_ue[$ue['id']]??[] as $m) {
            echo '<th style="font-size:9px">' . htmlspecialchars($m['code']) . '<br>' . htmlspecialchars($m['nom']) . '<br>(Coef.' . $m['coefficient'] . ')</th>';
        }
        if (empty($matieres_by_ue[$ue['id']])) echo '<th>–</th>';
        echo '<th>Moy UE</th>';
    }
    echo '</tr>';

    // Données
    foreach ($etudiants as $idx => $e) {
        $row_bg = ($idx % 2 === 0) ? '#fff' : '#f5f5f5';
        echo '<tr style="background:' . $row_bg . '">';
        echo '<td style="text-align:center">' . ($idx+1) . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($e['matricule']??'-') . '</td>';
        echo '<td>' . strtoupper($e['nom']??'') . ' ' . ucfirst($e['prenom']??'') . '</td>';

        foreach ($ues as $ue) {
            $moy_ue = $notes_data[$e['id']][$ue['id']]['moyenne_ue'] ?? 0;
            $ue_val = $notes_data[$e['id']][$ue['id']]['valide']     ?? false;
            foreach ($matieres_by_ue[$ue['id']]??[] as $m) {
                $note_m = null; $note_v = false;
                foreach ($notes_data[$e['id']][$ue['id']]['matieres']??[] as $nd) {
                    if ($nd['nom'] === $m['nom']) { $note_m = $nd['note']; $note_v = $nd['valide']; break; }
                }
                $bg = $note_v ? '#d4edda' : ($note_m !== null ? '#f8d7da' : '#fff');
                echo '<td style="text-align:center;background:' . $bg . '">' . ($note_m !== null ? number_format($note_m,1) : '–') . '</td>';
            }
            if (empty($matieres_by_ue[$ue['id']])) echo '<td>–</td>';
            $ue_bg = $ue_val ? '#d4edda' : '#f8d7da';
            echo '<td style="text-align:center;font-weight:bold;background:' . $ue_bg . '">' . number_format($moy_ue,1) . '</td>';
        }
        $moy_g = $notes_data[$e['id']]['moyenne_generale'] ?? 0;
        $dec   = $notes_data[$e['id']]['decision'] ?? '';
        $dec_bg = $dec === 'VALIDÉ' ? '#d4edda' : '#f8d7da';
        echo '<td style="text-align:center;font-weight:bold;background:#e3f2fd">' . number_format($moy_g,1) . '</td>';
        echo '<td style="text-align:center;font-weight:bold;background:' . $dec_bg . '">' . $dec . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background:#0f2d5c;color:white"><td colspan="' . $nb_cols . '" style="text-align:center">PV global établi le ' . date('d/m/Y à H:i') . ' – Cachet et signature du Directeur</td></tr>';
    echo '</table></body></html>';
    exit();
}

// Construire liste aplatie des matières dans l'ordre pour la référence
$all_matieres_ref = [];
foreach ($ues as $ue) {
    foreach ($matieres_by_ue[$ue['id']]??[] as $m) {
        $all_matieres_ref[] = ['code' => $m['code'], 'nom' => $m['nom'], 'coeff' => $m['coefficient'], 'ue' => $ue['code_ue']];
    }
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
    /* ── Reset & base ── */
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#eef1f7;padding:20px;font-size:12px;color:#212529}
    .pv-global{max-width:1600px;margin:0 auto}

    /* ── En-tête doc ── */
    .doc-header{background:linear-gradient(135deg,#0f2d5c 0%,#1a5276 100%);border-radius:10px;padding:18px 24px;color:#fff;display:flex;align-items:center;gap:18px;margin-bottom:12px}
    .doc-header-info h1{font-size:15px;font-weight:700;margin:0 0 2px}
    .doc-header-info h2{font-size:11px;font-weight:400;opacity:.85;margin:0 0 6px}
    .doc-header-meta{font-size:11px;opacity:.9;margin-bottom:6px}
    .badge-sess{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600}
    .s1{background:#28a745;color:#fff}.s2{background:#fd7e14;color:#fff}

    /* ── Barre info ── */
    .info-bar{background:#fff;border-radius:8px;padding:8px 14px;margin-bottom:12px;font-size:11px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;gap:20px;align-items:center}
    .info-bar strong{color:#0f2d5c}

    /* ── Tableau principal ── */
    .tbl-wrap{overflow-x:auto;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);background:#fff}
    .tbl-pv{width:100%;border-collapse:collapse;font-size:10.5px}

    /* Ligne UE (1re ligne thead) */
    .tbl-pv thead tr.ue-head th{
      background:#0f2d5c;color:#fff;font-size:10px;font-weight:700;
      padding:7px 4px;text-align:center;
      border-right:2px solid rgba(255,255,255,.25);
      border-bottom:1px solid rgba(255,255,255,.15);
    }
    /* Ligne matières (2e ligne thead) */
    .tbl-pv thead tr.mat-head th{
      background:#1a5276;color:#fff;font-size:9px;font-weight:500;
      text-align:center;border-right:1px solid rgba(255,255,255,.12);
      padding:0;vertical-align:bottom;
    }
    /* Cellule tournée pour les noms de matières */
    .mat-label{
      display:inline-block;
      writing-mode:vertical-rl;
      text-orientation:mixed;
      transform:rotate(180deg);
      white-space:nowrap;
      padding:6px 3px;
      max-height:110px;
      overflow:hidden;
      text-overflow:ellipsis;
      font-size:9px;
      line-height:1.2;
    }
    .mat-coeff{
      display:block;font-size:8px;opacity:.75;
      padding:2px 0 4px;text-align:center;
    }

    /* Colonnes fixes */
    .tbl-pv td{padding:5px 4px;border-bottom:1px solid #f0f0f0;text-align:center;vertical-align:middle;font-size:10px}
    .tbl-pv tbody tr:hover{background:#f0f6ff}
    .tbl-pv td.etu-col{text-align:left;padding-left:8px;font-weight:500;min-width:130px;white-space:nowrap;font-size:10px}
    .tbl-pv .mat-col{min-width:34px;max-width:44px}
    .tbl-pv .moy-ue-col{min-width:46px;font-weight:700;font-size:10px;border-left:1px solid #ddd}
    .tbl-pv .moy-gen-col{min-width:46px;font-weight:700;background:#e3f2fd!important;color:#0c5460;font-size:11px}
    .tbl-pv .dec-col{min-width:80px;font-weight:700;font-size:9.5px}

    /* Couleurs validation */
    .nv{background:#d4edda!important;color:#155724;font-weight:600}
    .nnv{background:#f8d7da!important;color:#721c24;font-weight:600}
    .nd{color:#aaa}

    /* Séparateur UE */
    .ue-sep{border-left:2px solid #6c757d!important}

    /* ── Légende & référence ── */
    .legende{background:#fff;border-radius:8px;padding:8px 14px;margin-top:10px;font-size:10px;box-shadow:0 1px 4px rgba(0,0,0,.05);display:flex;gap:14px;align-items:center;flex-wrap:wrap}
    .legende span{display:inline-flex;align-items:center;gap:4px}
    .leg-v{background:#d4edda;color:#155724;padding:2px 6px;border-radius:3px}
    .leg-nv{background:#f8d7da;color:#721c24;padding:2px 6px;border-radius:3px}
    .leg-nd{background:#f5f5f5;color:#999;padding:2px 6px;border-radius:3px}

    .ref-table-wrap{margin-top:14px;background:#fff;border-radius:8px;padding:12px 16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
    .ref-table-wrap h4{font-size:11px;color:#0f2d5c;margin-bottom:8px;font-weight:700}
    .ref-table{border-collapse:collapse;font-size:10px;width:100%}
    .ref-table th{background:#f0f4fa;color:#0f2d5c;padding:4px 8px;border:1px solid #dde3ed;font-weight:600;text-align:left}
    .ref-table td{padding:4px 8px;border:1px solid #eee}
    .ref-table tr:nth-child(even) td{background:#fafbfc}

    /* ── Pied de page doc ── */
    .doc-footer{text-align:center;font-size:10px;color:#aaa;margin-top:12px;padding-top:8px;border-top:1px solid #e0e0e0}

    /* ── Boutons flottants ── */
    .btn-actions{position:fixed;bottom:20px;right:16px;display:flex;gap:10px;z-index:999}
    .btn-actions button,.btn-actions a{padding:10px 18px;border:none;border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 3px 12px rgba(0,0,0,.2);transition:opacity .15s}
    .btn-actions button:hover,.btn-actions a:hover{opacity:.85}
    .btn-print{background:#1a73e8;color:#fff}
    .btn-excel{background:linear-gradient(135deg,#1e7e34,#28a745);color:#fff}
    .btn-back{background:#6c757d;color:#fff}

    /* ══════════════════════════════════════
       STYLES D'IMPRESSION
       ══════════════════════════════════════ */
    @page{
      size: A4 landscape;
      margin: 8mm 10mm 10mm 10mm;
    }

    @media print{
      *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
      html,body{background:#fff!important;padding:0!important;margin:0!important;font-size:8px}
      .btn-actions{display:none!important}
      .info-bar{display:none}
      .pv-global{max-width:none}

      /* En-tête compact pour l'impression */
      .doc-header{
        background:linear-gradient(135deg,#0f2d5c,#1a5276)!important;
        color:#fff!important;
        border-radius:0;padding:8px 12px;margin-bottom:6px;
      }
      .doc-header-info h1{font-size:11px}
      .doc-header-info h2{font-size:9px}
      .doc-header-meta{font-size:8.5px}
      .badge-sess{font-size:8px;padding:2px 8px}

      /* Conteneur tableau sans scroll */
      .tbl-wrap{
        overflow:visible;box-shadow:none;border-radius:0;
        border:1px solid #ccc;
      }

      /* Tableau principal – très compact */
      .tbl-pv{font-size:7.5px;table-layout:auto;width:100%}
      .tbl-pv thead tr.ue-head th{font-size:7.5px;padding:4px 3px}
      .tbl-pv thead tr.mat-head th{font-size:7px}
      .mat-label{font-size:7.5px;padding:4px 2px;max-height:90px}
      .mat-coeff{font-size:7px}
      .tbl-pv td{padding:3px 2px;font-size:7.5px;border-bottom:1px solid #eee}
      .tbl-pv td.etu-col{min-width:90px;font-size:7.5px;padding-left:4px}
      .tbl-pv .mat-col{min-width:24px;max-width:34px}
      .tbl-pv .moy-ue-col{min-width:30px;font-size:7.5px}
      .tbl-pv .moy-gen-col{min-width:30px;font-size:8px}
      .tbl-pv .dec-col{min-width:55px;font-size:7px}

      /* Répétition de l'en-tête sur chaque page */
      thead{display:table-header-group}
      tfoot{display:table-footer-group}
      /* Éviter coupure au milieu d'une ligne étudiant */
      tbody tr{page-break-inside:avoid}

      /* Légende et référence en pied de page sans coupure */
      .legende{
        page-break-inside:avoid;margin-top:6px;padding:5px 10px;
        box-shadow:none;border:1px solid #ddd;border-radius:0;
      }
      .ref-table-wrap{
        page-break-inside:avoid;margin-top:8px;padding:8px 10px;
        box-shadow:none;border:1px solid #ddd;border-radius:0;
      }
      .ref-table-wrap h4{font-size:8px;margin-bottom:5px}
      .ref-table{font-size:7.5px}
      .ref-table th,.ref-table td{padding:2px 5px}

      .doc-footer{
        page-break-inside:avoid;font-size:8px;margin-top:6px;
        padding-top:5px;
      }
    }
  </style>
</head>
<body>
<div class="pv-global">

  <!-- ── En-tête document ── -->
  <div class="doc-header">
    <div class="doc-header-info">
      <h1>ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD</h1>
      <h2>PROCÈS VERBAL GLOBAL – NIVEAU SUPÉRIEUR</h2>
      <div class="doc-header-meta">
        <?= h($filiere['nom']??'') ?> &nbsp;|&nbsp;
        Semestre <?= $semestre_num ?> (Année <?= $annee_etude ?>) &nbsp;|&nbsp;
        <?= h($annee_label??'') ?>
      </div>
      <div class="badge-sess s<?= $session_num ?>">
        <?= $session_num == 1 ? 'PREMIÈRE SESSION' : 'DEUXIÈME SESSION (RATTRAPAGE)' ?>
      </div>
    </div>
  </div>

  <!-- ── Barre info ── -->
  <div class="info-bar">
    <span><strong>Effectif :</strong>&nbsp;<?= count($etudiants) ?> étudiant(s)</span>
    <span><strong>UE :</strong>&nbsp;<?= count($ues) ?></span>
    <span><strong>Matières :</strong>&nbsp;<?= count($all_matieres_ref) ?></span>
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

  <!-- ── Tableau PV ── -->
  <div class="tbl-wrap">
    <table class="tbl-pv">
      <thead>
        <!-- Ligne 1 : N° / Matricule / Étudiant + en-têtes UE + Résultats -->
        <tr class="ue-head">
          <th rowspan="2" style="min-width:26px;width:26px">N°</th>
          <th rowspan="2" style="min-width:70px">Matricule</th>
          <th rowspan="2" style="text-align:left;padding-left:8px;min-width:130px">Étudiant</th>
          <?php foreach ($ues as $ue):
            $nb_mat = count($matieres_by_ue[$ue['id']] ?? []); ?>
            <th colspan="<?= $nb_mat + 1 ?>" class="ue-sep">
              <?= h($ue['code_ue']) ?> – <?= h($ue['nom']) ?>
            </th>
          <?php endforeach; ?>
          <th colspan="2" class="ue-sep" style="min-width:120px">Résultats</th>
        </tr>
        <!-- Ligne 2 : noms de matières (tournés) + Moy UE + Moy. Gén. + Décision -->
        <tr class="mat-head">
          <?php foreach ($ues as $ueIdx => $ue): ?>
            <?php foreach ($matieres_by_ue[$ue['id']]??[] as $mIdx => $m): ?>
              <th class="mat-col <?= ($mIdx === 0 ? 'ue-sep' : '') ?>">
                <span class="mat-label" title="<?= h($m['nom']) ?>"><?= h($m['code']) ?> – <?= h($m['nom']) ?></span>
                <span class="mat-coeff">C.<?= $m['coefficient'] ?></span>
              </th>
            <?php endforeach; ?>
            <?php if (empty($matieres_by_ue[$ue['id']])): ?>
              <th class="mat-col ue-sep"><span class="mat-label">–</span></th>
            <?php endif; ?>
            <th class="moy-ue-col">Moy<br>UE</th>
          <?php endforeach; ?>
          <th class="moy-gen-col ue-sep" style="min-width:44px;color:#fff;background:#1a5276">Moy.<br>Gén.</th>
          <th class="dec-col" style="min-width:70px;color:#fff;background:#1a5276">Décision</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($etudiants as $idx => $e): ?>
        <tr>
          <td style="color:#888"><?= $idx + 1 ?></td>
          <td style="font-family:monospace;font-size:9px"><?= h($e['matricule']??'-') ?></td>
          <td class="etu-col"><?= strtoupper(h($e['nom']??'')) ?> <?= ucfirst(h($e['prenom']??'')) ?></td>

          <?php foreach ($ues as $mIdx => $ue):
            $moy_ue = $notes_data[$e['id']][$ue['id']]['moyenne_ue'] ?? 0;
            $ue_val = $notes_data[$e['id']][$ue['id']]['valide']     ?? false;
          ?>
            <?php foreach ($matieres_by_ue[$ue['id']]??[] as $mColIdx => $m):
              $note_m = null; $note_v = false;
              foreach ($notes_data[$e['id']][$ue['id']]['matieres']??[] as $nd) {
                  if ($nd['nom'] === $m['nom']) { $note_m = $nd['note']; $note_v = $nd['valide']; break; }
              }
            ?>
              <td class="mat-col <?= ($mColIdx === 0 ? 'ue-sep' : '') ?> <?= $note_m !== null ? ($note_v ? 'nv' : 'nnv') : 'nd' ?>">
                <?= $note_m !== null ? number_format($note_m,1) : '–' ?>
              </td>
            <?php endforeach; ?>
            <?php if (empty($matieres_by_ue[$ue['id']])): ?>
              <td class="mat-col ue-sep nd">–</td>
            <?php endif; ?>
            <td class="moy-ue-col ue-sep <?= $ue_val ? 'nv' : 'nnv' ?>">
              <?= number_format($moy_ue,1) ?>
            </td>
          <?php endforeach; ?>

          <?php
            $moy_g = $notes_data[$e['id']]['moyenne_generale'] ?? 0;
            $dec   = $notes_data[$e['id']]['decision'] ?? '';
          ?>
          <td class="moy-gen-col ue-sep"><strong><?= number_format($moy_g,1) ?></strong></td>
          <td class="dec-col <?= $dec === 'VALIDÉ' ? 'nv' : 'nnv' ?>">
            <?= h($dec) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fa">
          <td colspan="3" style="text-align:left;padding-left:8px;font-style:italic;color:#888;font-size:9px">
            Établi le <?= date('d/m/Y à H:i') ?> — <?= count($etudiants) ?> étudiant(s)
          </td>
          <td colspan="<?php
            $nc = 0;
            foreach ($ues as $ue) { $nc += count($matieres_by_ue[$ue['id']]??[]); }
            echo $nc + count($ues) + 2;
          ?>" style="text-align:right;padding-right:8px;font-style:italic;color:#888;font-size:9px">
            Cachet et signature du Directeur
          </td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ── Légende ── -->
  <div class="legende">
    <strong>Légende :</strong>
    <span><span class="leg-v">✓ Validé / Note ≥ seuil</span></span>
    <span><span class="leg-nv">✗ Non validé / Note insuffisante</span></span>
    <span><span class="leg-nd">– Non saisi</span></span>
    <span style="margin-left:8px;color:#888">Moy UE : moyenne pondérée de l'UE &nbsp;|&nbsp; UE validée si Moy UE ≥ 10</span>
  </div>

  <!-- ── Tableau de référence des matières ── -->
  <?php if (!empty($all_matieres_ref)): ?>
  <div class="ref-table-wrap">
    <h4><i class="fas fa-list me-1"></i> Référence des matières – Semestre <?= $semestre_num ?></h4>
    <table class="ref-table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Matière</th>
          <th>UE</th>
          <th>Coeff.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all_matieres_ref as $mr): ?>
        <tr>
          <td><strong><?= h($mr['code']) ?></strong></td>
          <td><?= h($mr['nom']) ?></td>
          <td><?= h($mr['ue']) ?></td>
          <td style="text-align:center"><?= $mr['coeff'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <div class="doc-footer">
    PV global établi le <?= date('d/m/Y à H:i') ?> &nbsp;|&nbsp; EPSI – École Privée de Santé Ibn Rochd &nbsp;|&nbsp; Cachet et signature du Directeur
  </div>
</div>

<!-- ── Boutons flottants ── -->
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
