<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'comptable', 'directeur']);

$db      = getDB();
$ecoleId = getEcoleId();

$anneeId   = (int)($_GET['annee_id']   ?? 0);
$filiereId = (int)($_GET['filiere_id'] ?? 0);
$niveauId  = (int)($_GET['niveau_id']  ?? 0);
$search    = sanitize($_GET['search']  ?? '');

$where  = ["e.statut = 'actif'"];
$params = [];

if ($ecoleId > 0) { $where[] = 'e.ecole_id = ?'; $params[] = $ecoleId; }
if ($filiereId)   { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)    { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($search)      {
    $where[]  = "(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

if ($anneeId) {
    $pJoinCond      = "p.etudiant_id = e.id AND p.annee_id = ?";
    $pParamsOrdered = array_merge([$anneeId], $params);
} else {
    $pJoinCond      = "p.etudiant_id = e.id";
    $pParamsOrdered = $params;
}

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT
        e.id, e.matricule, e.nom, e.prenom, e.sexe, e.telephone,
        f.nom  AS filiere_nom, f.code AS filiere_code,
        n.nom  AS niveau_nom,
        a.libelle AS annee_libelle,
        COALESCE(SUM(p.montant),      0) AS total_du,
        COALESCE(SUM(p.montant_paye), 0) AS total_paye,
        COALESCE(SUM(p.montant),0) - COALESCE(SUM(p.montant_paye),0) AS reste
    FROM etudiants e
    LEFT JOIN filieres f           ON f.id = e.filiere_id
    LEFT JOIN niveaux  n           ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    LEFT JOIN paiements_etudiants p ON $pJoinCond
    WHERE $whereStr
    GROUP BY e.id, e.matricule, e.nom, e.prenom, e.sexe, e.telephone,
             f.nom, f.code, n.nom, a.libelle
    HAVING total_du > 0 AND reste > 0
    ORDER BY e.nom, e.prenom
");
$stmt->execute($pParamsOrdered);
$impayes = $stmt->fetchAll();

$ecole      = getCurrentEcole();
$nomEcole   = $ecole['nom'] ?? getParam('etablissement_nom', 'E-EDU PRO');
$villeEcole = $ecole['ville'] ?? '';
$telEcole   = $ecole['telephone'] ?? '';

$totalDu    = array_sum(array_column($impayes, 'total_du'));
$totalPaye  = array_sum(array_column($impayes, 'total_paye'));
$totalReste = array_sum(array_column($impayes, 'reste'));

// Libellé année
$anneeLabel = '';
if ($anneeId) {
    $aStmt = $db->prepare("SELECT libelle FROM annees_academiques WHERE id = ?");
    $aStmt->execute([$anneeId]);
    $anneeLabel = $aStmt->fetchColumn() ?: '';
}

$filename = 'impayes_' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
  body  { font-family: Arial, sans-serif; font-size: 11pt; }
  .entete      { font-size: 14pt; font-weight: bold; text-align: center; }
  .sous-entete { font-size: 10pt; text-align: center; color: #555; }
  .titre       { font-size: 12pt; font-weight: bold; text-align: center; background: #b71c1c; color: #fff; }
  th  { background-color: #c62828; color: #fff; font-weight: bold; padding: 6px 10px; border: 1px solid #b71c1c; text-align: center; }
  td  { padding: 5px 10px; border: 1px solid #ddd; vertical-align: middle; }
  tr:nth-child(even) td { background-color: #fff8f8; }
  .num   { text-align: right; }
  .total { background: #ffebee; font-weight: bold; }
  .rouge { color: #c62828; font-weight: bold; }
  .vert  { color: #2e7d32; }
</style>
</head>
<body>
<table>
  <tr><td colspan="9" class="entete"><?= htmlspecialchars($nomEcole) ?></td></tr>
  <?php if ($villeEcole): ?>
  <tr><td colspan="9" class="sous-entete"><?= htmlspecialchars($villeEcole) ?></td></tr>
  <?php endif; ?>
  <?php if ($telEcole): ?>
  <tr><td colspan="9" class="sous-entete">Tél : <?= htmlspecialchars($telEcole) ?></td></tr>
  <?php endif; ?>
  <tr><td colspan="9"></td></tr>
  <tr>
    <td colspan="9" class="titre">
      LISTE DES APPRENANTS NON SOLDÉS<?= $anneeLabel ? ' — ' . htmlspecialchars($anneeLabel) : '' ?>
      — Édité le <?= date('d/m/Y') ?>
    </td>
  </tr>
  <tr><td colspan="9"></td></tr>
  <tr>
    <th>#</th>
    <th>Matricule</th>
    <th>Nom</th>
    <th>Prénom</th>
    <th>Filière</th>
    <th>Niveau</th>
    <th>Téléphone</th>
    <th>Total dû (FCFA)</th>
    <th>Payé (FCFA)</th>
    <th>Reste à payer (FCFA)</th>
  </tr>
  <?php foreach ($impayes as $i => $e): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($e['matricule'] ?? '') ?></td>
    <td><?= htmlspecialchars($e['nom'] ?? '') ?></td>
    <td><?= htmlspecialchars($e['prenom'] ?? '') ?></td>
    <td><?= htmlspecialchars($e['filiere_nom'] ?? '-') ?></td>
    <td><?= htmlspecialchars($e['niveau_nom'] ?? '-') ?></td>
    <td><?= htmlspecialchars($e['telephone'] ?? '') ?></td>
    <td class="num"><?= number_format($e['total_du'],   0, ',', ' ') ?></td>
    <td class="num vert"><?= number_format($e['total_paye'], 0, ',', ' ') ?></td>
    <td class="num rouge"><?= number_format($e['reste'],     0, ',', ' ') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="10"></td></tr>
  <tr class="total">
    <td colspan="7" style="text-align:right">TOTAUX (<?= count($impayes) ?> apprenant(s))</td>
    <td class="num"><?= number_format($totalDu,    0, ',', ' ') ?></td>
    <td class="num vert"><?= number_format($totalPaye,  0, ',', ' ') ?></td>
    <td class="num rouge"><?= number_format($totalReste, 0, ',', ' ') ?></td>
  </tr>
</table>
</body>
</html>
