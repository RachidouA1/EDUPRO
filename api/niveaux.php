<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Pour les appels AJAX, retourner du JSON au lieu de rediriger
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'non_connecte']);
    exit;
}

$filiereId = (int)($_GET['filiere_id'] ?? 0);

if (!$filiereId) {
    echo json_encode([]);
    exit;
}

try {
    $db      = getDB();
    $ecoleId = getEcoleId();

    // S'assurer que les colonnes existent (ajoutées progressivement, absentes sur Docker au premier démarrage)
    try { $db->exec("ALTER TABLE filieres ADD COLUMN tronc_commun TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    try { $db->exec("ALTER TABLE filieres ADD COLUMN tronc_commun_id INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
    try { $db->exec("ALTER TABLE filieres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}

    // INF et SF ont un tronc_commun_id : les étudiants entrent directement en Année 2
    // Also verify filière belongs to current school (prevents cross-school data leakage)
    $fSql    = "SELECT tronc_commun_id FROM filieres WHERE id = ?";
    $fParams = [$filiereId];
    if ($ecoleId > 0) { $fSql .= " AND ecole_id = ?"; $fParams[] = $ecoleId; }
    $fStmt = $db->prepare($fSql);
    $fStmt->execute($fParams);
    $fil = $fStmt->fetch();

    if (!$fil) {
        echo json_encode([]);
        exit;
    }

    $minOrdre = !empty($fil['tronc_commun_id']) ? 2 : 1;

    $stmt = $db->prepare("SELECT id, nom, ordre FROM niveaux WHERE filiere_id = ? AND ordre >= ? ORDER BY ordre");
    $stmt->execute([$filiereId, $minOrdre]);
    echo json_encode($stmt->fetchAll());

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'message' => $e->getMessage()]);
}
