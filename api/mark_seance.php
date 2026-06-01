<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide.']);
    exit;
}

if (!hasRole(['coordinateur', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

$db       = getDB();
$user     = getCurrentUser();
$slotId   = (int)($_POST['slot_id'] ?? 0);
$action   = sanitize($_POST['action'] ?? '');

if (!$slotId || !in_array($action, ['mark', 'unmark'])) {
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides.']);
    exit;
}

// Load slot with emploi info
$stmt = $db->prepare("
    SELECT s.*,
           edt.semaine_debut, edt.filiere_id as edt_filiere_id, edt.niveau_id as edt_niveau_id, edt.annee_id as edt_annee_id,
           edt.statut as emploi_statut
    FROM emplois_slots s
    JOIN emplois_du_temps edt ON edt.id = s.emploi_id
    WHERE s.id = ?
");
$stmt->execute([$slotId]);
$slot = $stmt->fetch();

if (!$slot) {
    echo json_encode(['success' => false, 'message' => 'Créneau introuvable.']);
    exit;
}

if ($slot['emploi_statut'] !== 'approuve') {
    echo json_encode(['success' => false, 'message' => "L'emploi du temps doit être approuvé pour marquer des séances."]);
    exit;
}

// Verify coordinator access
if (hasRole('coordinateur') && !coordinateurCanAccess((int)$slot['edt_filiere_id'], (int)$slot['edt_niveau_id'] ?: null)) {
    echo json_encode(['success' => false, 'message' => 'Accès refusé à cette section.']);
    exit;
}

if (!$slot['enseignant_id'] || !$slot['matiere_id']) {
    echo json_encode(['success' => false, 'message' => 'Ce créneau n\'a pas d\'enseignant ou de matière assigné.']);
    exit;
}

// Compute actual date of the session
$jourOffset = ['lundi' => 0, 'mardi' => 1, 'mercredi' => 2, 'jeudi' => 3, 'vendredi' => 4, 'samedi' => 5];
$offset     = $jourOffset[$slot['jour']] ?? 0;
$dateSeance = date('Y-m-d', strtotime($slot['semaine_debut']) + $offset * 86400);

if ($action === 'mark') {
    try {
        $db->prepare("
            INSERT INTO seances_cours
                (emploi_slot_id, enseignant_id, matiere_id, filiere_id, niveau_id, annee_id,
                 date_seance, heure_debut, heure_fin, duree_heures, marked_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 2.00, ?)
            ON DUPLICATE KEY UPDATE
                marked_by = VALUES(marked_by),
                created_at = created_at
        ")->execute([
            $slotId,
            $slot['enseignant_id'],
            $slot['matiere_id'],
            $slot['edt_filiere_id'],
            $slot['edt_niveau_id'] ?: null,
            $slot['edt_annee_id'],
            $dateSeance,
            $slot['heure_debut'],
            $slot['heure_fin'],
            $user['id'],
        ]);
        $done = true;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }
} else {
    $db->prepare("DELETE FROM seances_cours WHERE emploi_slot_id = ?")->execute([$slotId]);
    $done = false;
}

// Recompute progress for this matière
$prog = $db->prepare("
    SELECT
        COUNT(sc.id) * 2 as heures_effectuees
    FROM emplois_slots es
    JOIN emplois_du_temps edt ON edt.id = es.emploi_id
    LEFT JOIN seances_cours sc ON sc.emploi_slot_id = es.id
    WHERE es.matiere_id = ? AND edt.filiere_id = ? AND edt.statut = 'approuve'
    " . ($slot['edt_annee_id'] ? " AND edt.annee_id = " . (int)$slot['edt_annee_id'] : "")
);
$prog->execute([$slot['matiere_id'], $slot['edt_filiere_id']]);
$heuresEffectuees = (int)($prog->fetchColumn() ?: 0);

// Recompute teacher total done hours
$tprog = $db->prepare("
    SELECT COALESCE(SUM(sc.duree_heures), 0) as total
    FROM seances_cours sc
    WHERE sc.enseignant_id = ? AND sc.filiere_id = ?
    " . ($slot['edt_annee_id'] ? " AND sc.annee_id = " . (int)$slot['edt_annee_id'] : "")
);
$tprog->execute([$slot['enseignant_id'], $slot['edt_filiere_id']]);
$teacherHeures = (float)($tprog->fetchColumn() ?: 0);

echo json_encode([
    'success'          => true,
    'done'             => $done,
    'date_seance'      => $dateSeance,
    'heures_matiere'   => $heuresEffectuees,
    'heures_enseignant'=> $teacherHeures,
]);
