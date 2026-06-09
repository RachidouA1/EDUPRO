<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$isCoord = hasRole('coordinateur');

$dateDebut  = sanitize($_GET['date_debut'] ?? date('Y-m-01'));
$dateFin    = sanitize($_GET['date_fin']   ?? date('Y-m-d'));
$filiereId  = (int)($_GET['filiere_id']   ?? 0);
$niveauId   = (int)($_GET['niveau_id']    ?? 0);
$anneeIdF   = (int)($_GET['annee_id']     ?? 0);
$filtreJust = sanitize($_GET['justifie']  ?? '');
$searchEtu  = sanitize($_GET['search']    ?? '');
$mode       = sanitize($_GET['mode']      ?? 'detail');  // 'synthese' ou 'detail'

// Construire la clause WHERE commune
$where  = ["a.date_absence BETWEEN ? AND ?"];
$params = [$dateDebut, $dateFin];

if ($ecoleId > 0) { $where[] = 'a.ecole_id = ?'; $params[] = $ecoleId; }
if ($isCoord) { $where[] = coordSectionWhere('e', $params); }
if ($filiereId) { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)  { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($anneeIdF)  { $where[] = 'a.annee_id = ?';   $params[] = $anneeIdF; }
if ($filtreJust !== '') { $where[] = 'a.justifie = ?'; $params[] = (int)$filtreJust; }
if ($searchEtu) {
    $where[]  = '(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)';
    $params   = array_merge($params, ["%$searchEtu%", "%$searchEtu%", "%$searchEtu%"]);
}
$whereStr = 'WHERE ' . implode(' AND ', $where);

$ecole        = getCurrentEcole();
$nomEcole     = $ecole['nom']       ?? getParam('etablissement_nom', 'E-EDU PRO');
$villeEcole   = $ecole['ville']     ?? getParam('etablissement_ville', '');
$adresseEcole = $ecole['adresse']   ?? getParam('etablissement_adresse', '');
$telEcole     = $ecole['telephone'] ?? getParam('etablissement_telephone', '');

$filename = 'rapport_absences_' . str_replace('-', '', $dateDebut) . '_' . str_replace('-', '', $dateFin) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

if ($mode === 'synthese') {
    $sql = "
        SELECT
            e.id, e.nom, e.prenom, e.matricule, e.sexe,
            f.code AS filiere_code, f.nom AS filiere_nom,
            n.nom  AS niveau_nom,
            COUNT(a.id)                                     AS total_abs,
            SUM(CASE WHEN a.justifie=1 THEN 1 ELSE 0 END)  AS nb_just,
            SUM(CASE WHEN a.justifie=0 THEN 1 ELSE 0 END)  AS nb_non_just,
            COALESCE(SUM(a.duree_heures), 0)                AS total_h,
            MAX(a.date_absence)                             AS derniere_abs
        FROM absences a
        JOIN etudiants e ON e.id = a.etudiant_id
        LEFT JOIN filieres f ON f.id = e.filiere_id
        LEFT JOIN niveaux  n ON n.id = e.niveau_id
        $whereStr
        GROUP BY e.id, e.nom, e.prenom, e.matricule, e.sexe, f.code, f.nom, n.nom
        ORDER BY total_abs DESC, total_h DESC, e.nom
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $sql = "
        SELECT a.*,
               e.nom AS e_nom, e.prenom AS e_prenom, e.matricule, e.sexe,
               f.code AS filiere_code, f.nom AS filiere_nom,
               n.nom  AS niveau_nom
        FROM absences a
        JOIN etudiants e ON e.id = a.etudiant_id
        LEFT JOIN filieres f ON f.id = e.filiere_id
        LEFT JOIN niveaux  n ON n.id = e.niveau_id
        $whereStr
        ORDER BY a.date_absence DESC, e.nom
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 10pt; }
  .entete  { font-size:14pt; font-weight:bold; text-align:center; }
  .sous    { font-size:10pt; text-align:center; color:#555; }
  .titre   { font-size:12pt; font-weight:bold; text-align:center; border-bottom:2px solid #1565c0; }
  .periode { font-size:10pt; font-weight:600; text-align:center; color:#1565c0; }
  th { background:#1565c0; color:#fff; font-weight:bold; padding:5px 10px; border:1px solid #0d47a1; text-align:center; }
  td { padding:4px 8px; border:1px solid #ddd; vertical-align:middle; }
  tr:nth-child(even) td { background:#f5f8ff; }
  .b-success { color:#1b5e20; background:#e8f5e9; padding:1px 6px; border-radius:3px; }
  .b-danger  { color:#b71c1c; background:#ffebee; padding:1px 6px; border-radius:3px; }
  .total-row td { font-weight:bold; background:#e3f2fd; }
</style>
</head>
<body>
<table>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="entete"><?= htmlspecialchars($nomEcole, ENT_QUOTES) ?></td></tr>
  <?php if ($adresseEcole || $villeEcole): ?>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="sous"><?= htmlspecialchars(trim($adresseEcole.($villeEcole?' – '.$villeEcole:'')), ENT_QUOTES) ?></td></tr>
  <?php endif; ?>
  <?php if ($telEcole): ?>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="sous">Tél : <?= htmlspecialchars($telEcole, ENT_QUOTES) ?></td></tr>
  <?php endif; ?>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>"></td></tr>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="titre">
    <?= $mode==='synthese' ? 'RAPPORT DES ABSENCES — PAR APPRENANT' : 'RAPPORT DES ABSENCES — DÉTAIL' ?>
  </td></tr>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="periode">
    Période : <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?>
  </td></tr>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>" class="sous">Édité le <?= date('d/m/Y') ?> — <?= count($rows) ?> enregistrement(s)</td></tr>
  <tr><td colspan="<?= $mode==='synthese' ? 8 : 7 ?>"></td></tr>

  <?php if ($mode === 'synthese'): ?>
  <!-- EN-TÊTES SYNTHÈSE -->
  <tr>
    <th>#</th>
    <th>Matricule</th>
    <th>Nom</th>
    <th>Prénom</th>
    <th>Filière</th>
    <th>Total absences</th>
    <th>Justifiées</th>
    <th>Non justifiées</th>
    <th>Total heures</th>
    <th>Dernière absence</th>
  </tr>
  <?php
    $sumAbs=0; $sumJust=0; $sumNonJ=0; $sumH=0;
    foreach ($rows as $i => $r):
      $sumAbs  += $r['total_abs'];
      $sumJust += $r['nb_just'];
      $sumNonJ += $r['nb_non_just'];
      $sumH    += $r['total_h'];
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['matricule'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($r['nom'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($r['prenom'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($r['filiere_code'] ? $r['filiere_code'].' – '.($r['niveau_nom']??'') : '-', ENT_QUOTES) ?></td>
    <td style="text-align:center;font-weight:bold"><?= $r['total_abs'] ?></td>
    <td style="text-align:center"><span class="b-success"><?= $r['nb_just'] ?></span></td>
    <td style="text-align:center"><span class="b-danger"><?= $r['nb_non_just'] ?></span></td>
    <td style="text-align:center"><?= $r['total_h'] > 0 ? number_format($r['total_h'],1).'h' : '—' ?></td>
    <td><?= $r['derniere_abs'] ? date('d/m/Y', strtotime($r['derniere_abs'])) : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  <!-- Ligne totaux -->
  <tr class="total-row">
    <td colspan="5" style="text-align:right">TOTAUX</td>
    <td style="text-align:center"><?= $sumAbs ?></td>
    <td style="text-align:center"><?= $sumJust ?></td>
    <td style="text-align:center"><?= $sumNonJ ?></td>
    <td style="text-align:center"><?= number_format($sumH,1) ?>h</td>
    <td></td>
  </tr>

  <?php else: // mode detail ?>
  <!-- EN-TÊTES DÉTAIL -->
  <tr>
    <th>#</th>
    <th>Matricule</th>
    <th>Nom &amp; Prénom</th>
    <th>Filière / Niveau</th>
    <th>Date</th>
    <th>Heure début</th>
    <th>Heure fin</th>
    <th>Durée (h)</th>
    <th>Séance</th>
    <th>Motif</th>
    <th>Statut</th>
  </tr>
  <?php foreach ($rows as $i => $d): ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($d['matricule'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($d['e_nom'].' '.$d['e_prenom'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($d['filiere_code'] ? $d['filiere_code'].' – '.($d['niveau_nom']??'') : '-', ENT_QUOTES) ?></td>
    <td><?= date('d/m/Y', strtotime($d['date_absence'])) ?></td>
    <td style="text-align:center"><?= $d['heure_debut'] ? substr($d['heure_debut'],0,5) : '—' ?></td>
    <td style="text-align:center"><?= $d['heure_fin']   ? substr($d['heure_fin'],0,5)   : '—' ?></td>
    <td style="text-align:center"><?= $d['duree_heures'] ? number_format($d['duree_heures'],1) : '—' ?></td>
    <td><?= htmlspecialchars($d['seance'] ?? '', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($d['motif']  ?? '', ENT_QUOTES) ?></td>
    <td><span class="<?= $d['justifie'] ? 'b-success' : 'b-danger' ?>"><?= $d['justifie'] ? 'Justifiée' : 'Non justifiée' ?></span></td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>

</table>
</body>
</html>
