<?php
// Variables requises : $etudiant, $ues_data, $semestre_num, $session_num,
//                      $session_type, $annee_label, $moyenne_generale,
//                      $ue_validees, $ue_non_validees, $decision, $decision_class

// Export Excel
if (isset($_GET['export_excel'])) {
    header('Content-Type: application/vnd.ms-excel');
    $fname = 'PV_' . ($etudiant['nom'] ?? 'etudiant') . '_S' . $semestre_num . '_' . date('Y-m-d') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%">';

    echo '<tr><td colspan="5" style="text-align:center;font-size:16px;font-weight:bold;background:#0f2d5c;color:white">';
    echo 'ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD<br>';
    echo 'PROCÈS VERBAL – NIVEAU SUPÉRIEUR<br>';
    echo 'Semestre ' . $semestre_num . ' (Année ' . ceil($semestre_num/2) . ')<br>';
    echo ($session_num == 1 ? '1ÈRE SESSION' : '2ÈME SESSION (RATTRAPAGE)') . '<br>';
    echo 'Année académique : ' . htmlspecialchars($annee_label ?? '');
    echo '</td></tr>';

    echo '<tr><th colspan="5" style="background:#1a5276;color:white">INFORMATIONS ÉTUDIANT</th></tr>';
    echo '<tr><td><strong>Étudiant :</strong></td><td>' . strtoupper($etudiant['nom']??'') . ' ' . ucfirst($etudiant['prenom']??'') . '</td>';
    echo '<td><strong>Matricule :</strong></td><td>' . ($etudiant['matricule']??'') . '</td><td></td></tr>';
    echo '<tr><td><strong>Filière :</strong></td><td>' . htmlspecialchars($etudiant['filiere_nom']??'') . '</td>';
    echo '<td><strong>Année :</strong></td><td>' . ceil($semestre_num/2) . 'ème année</td><td></td></tr>';

    foreach ($ues_data as $ue) {
        echo '<tr style="background:#1a5276;color:white"><th colspan="5">' . htmlspecialchars($ue['code_ue']) . ' – ' . htmlspecialchars($ue['nom']) . ' (Moy: ' . number_format($ue['moyenne'],2) . '/20)</th></tr>';
        echo '<tr style="background:#2e86ab;color:white"><th>Matière</th><th>Coeff</th><th>Note/20</th><th>Seuil</th><th>Validation</th></tr>';
        if (empty($ue['matieres'])) {
            echo '<tr><td colspan="5">Aucune matière</td></tr>';
        } else {
            foreach ($ue['matieres'] as $m) {
                $bg = $m['valide'] ? '#d4edda' : ($m['note'] !== null ? '#f8d7da' : '#fff');
                echo '<tr style="background:' . $bg . '">';
                echo '<td>' . htmlspecialchars($m['nom']) . '</td>';
                echo '<td>' . $m['coefficient'] . '</td>';
                echo '<td>' . ($m['note'] !== null ? number_format($m['note'],2).'/20' : '-') . '</td>';
                echo '<td>' . $m['seuil'] . '/20</td>';
                echo '<td>' . ($m['valide'] ? '✓ Validée' : ($m['note'] !== null ? '✗ Non validée' : '-')) . '</td>';
                echo '</tr>';
            }
        }
        echo '<tr style="background:#e3f2fd;font-weight:bold"><td colspan="2">Moyenne UE</td><td colspan="3">' . number_format($ue['moyenne'],2) . '/20 – ' . ($ue['valide'] ? 'VALIDÉE' : 'NON VALIDÉE') . '</td></tr>';
    }

    echo '<tr><td colspan="2"><strong>Moyenne générale :</strong></td><td colspan="3"><strong>' . number_format($moyenne_generale,2) . '/20</strong></td></tr>';
    echo '<tr><td colspan="2"><strong>UE validées :</strong></td><td colspan="3"><strong>' . $ue_validees . ' / ' . ($ue_validees + $ue_non_validees) . '</strong></td></tr>';
    echo '<tr><td colspan="2"><strong>Décision :</strong></td><td colspan="3"><strong>' . $decision . '</strong></td></tr>';
    echo '<tr><td colspan="5" style="text-align:center;background:#0f2d5c;color:white">PV établi le ' . date('d/m/Y à H:i') . ' – Cachet et signature du Directeur</td></tr>';
    echo '</table></body></html>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PV – <?= h($etudiant['nom']??'') ?> <?= h($etudiant['prenom']??'') ?> – S<?= $semestre_num ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#eef1f7;padding:24px;font-size:13px;color:#212529}
    .pv-wrap{max-width:900px;margin:0 auto}

    .doc-header{background:linear-gradient(135deg,#0f2d5c 0%,#1a5276 100%);border-radius:12px;padding:22px 28px;color:#fff;display:flex;align-items:center;gap:20px;margin-bottom:18px}
    .doc-header img{width:80px;height:80px;object-fit:contain;border-radius:50%;background:#fff;padding:6px;flex-shrink:0}
    .doc-header-info h1{font-size:17px;font-weight:700;margin:0 0 3px}
    .doc-header-info h2{font-size:12px;font-weight:400;opacity:.85;margin:0 0 7px}
    .doc-header-meta{font-size:12px;opacity:.9;margin-bottom:7px}
    .badge-sess{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600}
    .s1{background:#28a745;color:#fff}.s2{background:#fd7e14;color:#fff}

    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
    .info-card{background:#fff;border-radius:8px;padding:12px 16px;box-shadow:0 1px 6px rgba(0,0,0,.07)}
    .info-card .lbl{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
    .info-card .val{font-size:14px;font-weight:600;color:#1a1a2e}

    .ue-card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);margin-bottom:14px;overflow:hidden}
    .ue-head{padding:11px 18px;display:flex;justify-content:space-between;align-items:center}
    .ue-head.ok{background:linear-gradient(90deg,#1e7e34,#28a745)}
    .ue-head.ko{background:linear-gradient(90deg,#c82333,#dc3545)}
    .ue-head h4{margin:0;font-size:13px;font-weight:600;color:#fff}
    .ue-moy{font-size:16px;font-weight:700;color:#fff;white-space:nowrap}

    .tbl{width:100%;border-collapse:collapse}
    .tbl th{background:#f8f9fa;color:#555;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;padding:8px 10px;border-bottom:2px solid #e9ecef;text-align:center}
    .tbl th:first-child{text-align:left}
    .tbl td{padding:9px 10px;border-bottom:1px solid #f0f0f0;font-size:12px;text-align:center;vertical-align:middle}
    .tbl td:first-child{text-align:left}
    .tbl tbody tr:last-child td{border-bottom:none}
    .tbl tfoot td{background:#f8f9fa;font-weight:600;border-top:2px solid #e9ecef;font-size:12px}
    .cv{color:#1e7e34;font-weight:600}.cnv{color:#c82333;font-weight:600}

    .decision-card{border-radius:10px;padding:20px 24px;margin:16px 0;text-align:center;border:2px solid transparent}
    .decision-card.ok{background:linear-gradient(135deg,#d4edda,#c3e6cb);border-color:#28a745}
    .decision-card.ko{background:linear-gradient(135deg,#f8d7da,#f5c6cb);border-color:#dc3545}
    .decision-stats{display:flex;justify-content:center;gap:40px;margin-bottom:12px}
    .d-stat .dval{font-size:28px;font-weight:700;line-height:1.1}
    .d-stat .dlbl{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
    .decision-text{font-size:18px;font-weight:700}

    .sig-block{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:24px;padding-top:16px;border-top:1px solid #dee2e6}
    .sig-item{text-align:center;font-size:11px;color:#888}
    .sig-line{border-bottom:1px solid #999;margin:40px 10px 8px}

    .doc-footer{text-align:center;font-size:11px;color:#aaa;margin-top:16px;padding-top:12px;border-top:1px solid #e0e0e0}

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
      .ue-head.ok,.ue-head.ko{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .decision-card.ok,.decision-card.ko{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      img{max-width:100%!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
  </style>
</head>
<?php
$_pvLogo = getLogoUrl();
$_pvNom  = strtoupper(getParam('etablissement_nom', 'École Privée de Santé Ibn Rochd'));
?>
<body>
<div class="pv-wrap">

  <div class="doc-header">
    <?php if ($_pvLogo): ?>
      <img src="<?= h($_pvLogo) ?>" alt="Logo">
    <?php endif; ?>
    <div class="doc-header-info">
      <h1><?= h($_pvNom) ?></h1>
      <h2>PROCÈS VERBAL – NIVEAU SUPÉRIEUR</h2>
      <div class="doc-header-meta">
        Semestre <?= $semestre_num ?> &nbsp;(Année <?= ceil($semestre_num/2) ?>) &nbsp;|&nbsp; <?= h($annee_label ?? '') ?>
      </div>
      <div class="badge-sess s<?= $session_num ?>">
        <?= $session_num == 1 ? 'PREMIÈRE SESSION' : 'DEUXIÈME SESSION (RATTRAPAGE)' ?>
      </div>
    </div>
  </div>

  <?php if ($session_num === 2 && ($validatedS1 ?? false)): ?>
  <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:12px 18px;margin-bottom:14px;color:#155724;font-size:13px">
    <strong>&#10003; Cet étudiant a validé toutes ses UE en Session 1.</strong>
    Son résultat est déjà enregistré – aucune reprise en session 2 n'est requise.
  </div>
  <?php endif; ?>

  <div class="info-grid">
    <div class="info-card">
      <div class="lbl">Étudiant</div>
      <div class="val"><?= strtoupper(h($etudiant['nom']??'')) ?> <?= ucfirst(h($etudiant['prenom']??'')) ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Matricule</div>
      <div class="val" style="font-family:monospace;letter-spacing:.5px"><?= h($etudiant['matricule']??'') ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Filière</div>
      <div class="val"><?= h($etudiant['filiere_nom']??'') ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Année d'étude</div>
      <div class="val"><?= ceil($semestre_num/2) ?>ème année</div>
    </div>
  </div>

  <?php if (empty($ues_data)): ?>
    <div style="text-align:center;color:#999;padding:40px;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06)">
      <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.3"></i>
      Aucune UE trouvée pour ce semestre.
    </div>
  <?php else: ?>
    <?php foreach ($ues_data as $ue): ?>
    <div class="ue-card">
      <div class="ue-head <?= $ue['valide'] ? 'ok' : 'ko' ?>">
        <h4><?= h($ue['code_ue']) ?> – <?= h($ue['nom']) ?></h4>
        <div class="ue-moy"><?= number_format($ue['moyenne'],2) ?>/20</div>
      </div>
      <table class="tbl">
        <thead>
          <tr>
            <th style="text-align:left;padding-left:16px">Matière</th>
            <th>Coeff</th>
            <th>Note /20</th>
            <th>Seuil</th>
            <th>Validation</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($ue['matieres'])): ?>
            <tr><td colspan="5" style="text-align:center;color:#999;padding:18px">Aucune matière</td></tr>
          <?php else: ?>
            <?php foreach ($ue['matieres'] as $m): ?>
            <tr>
              <td style="padding-left:16px"><?= h($m['nom']) ?></td>
              <td><?= $m['coefficient'] ?></td>
              <td>
                <?php if ($m['note'] !== null): ?>
                  <span class="<?= $m['valide'] ? 'cv' : 'cnv' ?>"><?= number_format($m['note'],2) ?>/20</span>
                <?php else: ?>
                  <span style="color:#ccc">–</span>
                <?php endif; ?>
              </td>
              <td><?= $m['seuil'] ?>/20</td>
              <td>
                <?php if ($m['note'] !== null): ?>
                  <span class="<?= $m['valide'] ? 'cv' : 'cnv' ?>"><?= $m['valide'] ? '✓ Validée' : '✗ Non validée' ?></span>
                <?php else: ?>
                  <span style="color:#ccc">Non saisie</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" style="padding-left:16px"><strong>Moyenne UE</strong></td>
            <td colspan="3" class="<?= $ue['valide'] ? 'cv' : 'cnv' ?>">
              <strong><?= number_format($ue['moyenne'],2) ?>/20 — <?= $ue['valide'] ? 'UE VALIDÉE' : 'UE NON VALIDÉE' ?></strong>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="decision-card <?= $decision === 'VALIDÉ' ? 'ok' : 'ko' ?>">
    <div class="decision-stats">
      <div class="d-stat">
        <div class="dval <?= $decision === 'VALIDÉ' ? 'cv' : 'cnv' ?>"><?= number_format($moyenne_generale,2) ?></div>
        <div class="dlbl">Moyenne /20</div>
      </div>
      <div class="d-stat">
        <div class="dval"><?= $ue_validees ?>/<?= ($ue_validees + $ue_non_validees) ?></div>
        <div class="dlbl">UE validées</div>
      </div>
    </div>
    <div class="decision-text <?= $decision_class ?>">Décision : <?= h($decision) ?></div>
  </div>

  <div class="sig-block">
    <div class="sig-item"><div class="sig-line"></div>Signature de l'enseignant</div>
    <div class="sig-item"><div class="sig-line"></div>Responsable pédagogique</div>
    <div class="sig-item"><div class="sig-line"></div>Cachet et signature du Directeur</div>
  </div>

  <div class="doc-footer">
    Procès verbal établi le <?= date('d/m/Y à H:i') ?> &nbsp;|&nbsp; EPSI – École Privée de Santé Ibn Rochd
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
