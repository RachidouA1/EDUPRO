<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'scolarite', 'directeur', 'enseignant', 'comptable', 'coordinateur']);

$db      = getDB();
$ecoleId = getEcoleId();
$isCoord = hasRole('coordinateur');

$search    = sanitize($_GET['search']   ?? '');
$filiereId = (int)($_GET['filiere_id']  ?? 0);
$niveauId  = (int)($_GET['niveau_id']   ?? 0);
$anneeId   = (int)($_GET['annee_id']    ?? 0);
$statut    = sanitize($_GET['statut']   ?? '');
$sexe      = sanitize($_GET['sexe']     ?? '');

$where  = $ecoleId > 0 ? ['e.ecole_id = ?'] : ['1=1'];
$params = $ecoleId > 0 ? [$ecoleId] : [];

if ($isCoord) {
    $where[] = coordSectionWhere('e', $params);
}
if ($search) {
    $where[]  = "(e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ? OR e.telephone LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($filiereId) { $where[] = 'e.filiere_id = ?'; $params[] = $filiereId; }
if ($niveauId)  { $where[] = 'e.niveau_id = ?';  $params[] = $niveauId; }
if ($anneeId)   { $where[] = 'e.annee_id = ?';   $params[] = $anneeId; }
if ($statut)    { $where[] = 'e.statut = ?';      $params[] = $statut; }
if ($sexe)      { $where[] = 'e.sexe = ?';        $params[] = $sexe; }

$whereStr = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT e.*, f.nom as filiere_nom, f.code as filiere_code, n.nom as niveau_nom, a.libelle as annee_libelle
    FROM etudiants e
    LEFT JOIN filieres f ON f.id = e.filiere_id
    LEFT JOIN niveaux n ON n.id = e.niveau_id
    LEFT JOIN annees_academiques a ON a.id = e.annee_id
    WHERE $whereStr
    ORDER BY e.nom, e.prenom
");
$stmt->execute($params);
$etudiants = $stmt->fetchAll();

$ecole    = getCurrentEcole();
$nomEcole = $ecole['nom'] ?? getParam('etablissement_nom', 'E-EDU PRO');
$villeEcole = $ecole['ville'] ?? getParam('etablissement_ville', '');
$adresseEcole = $ecole['adresse'] ?? getParam('etablissement_adresse', '');
$telEcole = $ecole['telephone'] ?? getParam('etablissement_telephone', '');

$filename = 'etudiants_' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 11pt; }
  .entete { font-size: 14pt; font-weight: bold; text-align: center; }
  .sous-entete { font-size: 10pt; text-align: center; color: #555; }
  .titre-liste { font-size: 12pt; font-weight: bold; text-align: center; border-bottom: 2px solid #1565c0; }
  th { background-color: #1565c0; color: #fff; font-weight: bold; padding: 6px 10px; border: 1px solid #0d47a1; }
  td { padding: 5px 10px; border: 1px solid #ccc; vertical-align: middle; }
  tr:nth-child(even) td { background-color: #f5f8ff; }
  .badge-actif    { color: #1b5e20; background: #e8f5e9; padding: 2px 8px; border-radius: 4px; }
  .badge-exclu    { color: #b71c1c; background: #ffebee; padding: 2px 8px; border-radius: 4px; }
  .badge-transfere{ color: #e65100; background: #fff3e0; padding: 2px 8px; border-radius: 4px; }
  .badge-diplome  { color: #006064; background: #e0f7fa; padding: 2px 8px; border-radius: 4px; }
</style>
</head>
<body>
<table>
  <tr><td colspan="10" class="entete"><?= htmlspecialchars($nomEcole, ENT_QUOTES) ?></td></tr>
  <?php if ($adresseEcole || $villeEcole): ?>
  <tr><td colspan="10" class="sous-entete"><?= htmlspecialchars(trim($adresseEcole . ($villeEcole ? ' – ' . $villeEcole : '')), ENT_QUOTES) ?></td></tr>
  <?php endif; ?>
  <?php if ($telEcole): ?>
  <tr><td colspan="10" class="sous-entete">Tél : <?= htmlspecialchars($telEcole, ENT_QUOTES) ?></td></tr>
  <?php endif; ?>
  <tr><td colspan="10"></td></tr>
  <tr><td colspan="10" class="titre-liste">LISTE DES ÉTUDIANTS — Édité le <?= date('d/m/Y') ?></td></tr>
  <tr><td colspan="10"></td></tr>
  <tr>
    <th>#</th>
    <th>Matricule</th>
    <th>Nom</th>
    <th>Prénom</th>
    <th>Sexe</th>
    <th>Filière</th>
    <th>Niveau</th>
    <th>Téléphone</th>
    <th>Email</th>
    <th>Statut</th>
  </tr>
  <?php foreach ($etudiants as $i => $e): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($e['matricule'] ?? '', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($e['nom'] ?? '', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($e['prenom'] ?? '', ENT_QUOTES) ?></td>
    <td><?= $e['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></td>
    <td><?= htmlspecialchars($e['filiere_nom'] ?? '-', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($e['niveau_nom'] ?? '-', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($e['telephone'] ?? '', ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($e['email'] ?? '', ENT_QUOTES) ?></td>
    <td><span class="badge-<?= $e['statut'] ?>"><?= ucfirst(htmlspecialchars($e['statut'] ?? '', ENT_QUOTES)) ?></span></td>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="10"></td></tr>
  <tr><td colspan="10" style="color:#888;font-size:9pt">Total : <?= count($etudiants) ?> étudiant(s)</td></tr>
</table>
</body>
</html>
