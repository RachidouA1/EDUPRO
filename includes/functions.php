<?php
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMontant($montant): string {
    return number_format((float)$montant, 0, ',', ' ') . ' FCFA';
}

function formatDate(?string $date): string {
    if (!$date || $date === '0000-00-00') return '-';
    return date('d/m/Y', strtotime($date));
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash(): void {
    $flash = getFlash();
    if (!$flash) return;
    $icons = ['success' => 'check-circle', 'error' => 'exclamation-circle', 'info' => 'info-circle', 'warning' => 'exclamation-triangle'];
    $classes = ['success' => 'alert-success', 'error' => 'alert-danger', 'info' => 'alert-info', 'warning' => 'alert-warning'];
    $type = $flash['type'];
    $icon = $icons[$type] ?? 'info-circle';
    $class = $classes[$type] ?? 'alert-info';
    echo '<div class="alert ' . $class . ' alert-dismissible fade show d-flex align-items-center gap-2" role="alert">';
    echo '<i class="fas fa-' . $icon . '"></i>';
    echo '<span>' . h($flash['message']) . '</span>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

function generateMatricule(string $prefix): string {
    $db = getDB();
    $year = date('Y');
    $short = substr($year, 2);
    $table = ($prefix === 'ETU') ? 'etudiants' : 'enseignants';
    $stmt = $db->query("SELECT COUNT(*) FROM {$table}");
    $count = (int)$stmt->fetchColumn();
    return $prefix . $short . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

function getActiveAnnee(): ?array {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM annees_academiques WHERE actif = 1 LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function getActiveSemestre(): ?array {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM semestres WHERE actif = 1 LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function getFilieres(): array {
    $db = getDB();
    return $db->query("SELECT * FROM filieres WHERE actif = 1 ORDER BY nom")->fetchAll();
}

function getNiveaux(?int $filiere_id = null): array {
    $db = getDB();
    if ($filiere_id) {
        $stmt = $db->prepare("SELECT n.*, f.nom as filiere_nom FROM niveaux n JOIN filieres f ON f.id = n.filiere_id WHERE n.filiere_id = ? ORDER BY n.ordre");
        $stmt->execute([$filiere_id]);
    } else {
        $stmt = $db->query("SELECT n.*, f.nom as filiere_nom FROM niveaux n JOIN filieres f ON f.id = n.filiere_id ORDER BY f.nom, n.ordre");
    }
    return $stmt->fetchAll();
}

function getAnneesAcademiques(): array {
    $db = getDB();
    return $db->query("SELECT * FROM annees_academiques ORDER BY libelle DESC")->fetchAll();
}

function getSemestres(?int $annee_id = null): array {
    $db = getDB();
    if ($annee_id) {
        $stmt = $db->prepare("SELECT * FROM semestres WHERE annee_id = ? ORDER BY id");
        $stmt->execute([$annee_id]);
    } else {
        $stmt = $db->query("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id = s.annee_id ORDER BY a.libelle DESC, s.id");
    }
    return $stmt->fetchAll();
}

function calculateMoyenne(array $notes): ?float {
    if (empty($notes)) return null;
    $totalCoef = 0.0;
    $totalPoints = 0.0;
    foreach ($notes as $note) {
        if ($note['note_finale'] !== null) {
            $coef = (float)$note['coefficient'];
            $totalCoef   += $coef;
            $totalPoints += (float)$note['note_finale'] * $coef;
        }
    }
    if ($totalCoef == 0) return null;
    return round($totalPoints / $totalCoef, 2);
}

/**
 * Returns all (filiere, niveau) sections managed by the current coordinator.
 * Each row: filiere_id, niveau_id (nullable), filiere_nom, filiere_code, niveau_nom
 */
function getCoordinateurSections(): array {
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'coordinateur') return [];
    try {
        $db   = getDB();
        // Ensure table exists
        $db->exec("CREATE TABLE IF NOT EXISTS coordinateur_sections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            filiere_id INT NOT NULL,
            niveau_id INT NULL,
            UNIQUE KEY uk_coord_sec (user_id, filiere_id, niveau_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $stmt = $db->prepare("
            SELECT cs.id as section_id, cs.filiere_id, cs.niveau_id,
                   f.nom as filiere_nom, f.code as filiere_code,
                   n.nom as niveau_nom, COALESCE(n.ordre, 0) as niveau_ordre
            FROM coordinateur_sections cs
            JOIN filieres f ON f.id = cs.filiere_id
            LEFT JOIN niveaux n ON n.id = cs.niveau_id
            WHERE cs.user_id = ?
            ORDER BY f.nom, n.ordre
        ");
        $stmt->execute([$user['id']]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/** Returns unique filière IDs managed by the current coordinator. */
function getCoordinateurFiliereIds(): array {
    $ids = array_column(getCoordinateurSections(), 'filiere_id');
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * Checks if the current coordinator has access to a given filière (+ optional niveau).
 * Access is granted if any section matches the filière, with:
 *  - niveau_id=NULL in the section → access to all levels of that filière
 *  - specific niveau_id → only that level
 */
function coordinateurCanAccess(int $filiereId, ?int $niveauId = null): bool {
    foreach (getCoordinateurSections() as $s) {
        if ((int)$s['filiere_id'] !== $filiereId) continue;
        if ($s['niveau_id'] === null) return true;          // all levels
        if ($niveauId !== null && (int)$s['niveau_id'] === $niveauId) return true;
    }
    return false;
}

/**
 * Builds a parameterized WHERE snippet for filtering by coordinator's sections.
 * $alias: table alias with filiere_id + niveau_id columns (e.g. 'e' for etudiants)
 * $params: array to append bind values to (passed by reference)
 */
function coordSectionWhere(string $alias, array &$params): string {
    $sections = getCoordinateurSections();
    if (empty($sections)) return '1=0';
    $parts = [];
    foreach ($sections as $s) {
        if ($s['niveau_id']) {
            $parts[]  = "({$alias}.filiere_id=? AND {$alias}.niveau_id=?)";
            $params[] = (int)$s['filiere_id'];
            $params[] = (int)$s['niveau_id'];
        } else {
            $parts[]  = "{$alias}.filiere_id=?";
            $params[] = (int)$s['filiere_id'];
        }
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/** Backward-compatible: returns first filière ID (or 0). */
function getCoordinateurFiliereId(): int {
    $ids = getCoordinateurFiliereIds();
    return $ids ? $ids[0] : 0;
}

function getMention(?float $moyenne): string {
    if ($moyenne === null) return '-';
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Insuffisant';
}

function getMentionBadge(?float $moyenne): string {
    if ($moyenne === null) return '<span class="badge bg-secondary">-</span>';
    if ($moyenne >= 16) return '<span class="badge bg-primary">Très Bien</span>';
    if ($moyenne >= 14) return '<span class="badge bg-success">Bien</span>';
    if ($moyenne >= 12) return '<span class="badge bg-info">Assez Bien</span>';
    if ($moyenne >= 10) return '<span class="badge bg-warning text-dark">Passable</span>';
    return '<span class="badge bg-danger">Insuffisant</span>';
}

function noteBadge(?float $note): string {
    if ($note === null) return '<span class="text-muted">-</span>';
    $class = 'text-danger';
    if ($note >= 14) $class = 'text-primary fw-bold';
    elseif ($note >= 10) $class = 'text-success';
    return '<span class="' . $class . '">' . number_format($note, 2) . '</span>';
}

function sanitize(string $str): string {
    return trim(strip_tags($str));
}

function redirect(string $url): void {
    header('Location: ' . APP_URL . $url);
    exit;
}

// ===== Paramètres de l'application =====

function getParam(string $cle, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows  = getDB()->query("SELECT cle, valeur FROM parametres")->fetchAll();
            $cache = [];
            foreach ($rows as $r) { $cache[$r['cle']] = $r['valeur'] ?? ''; }
        } catch (PDOException $e) {
            $cache = [];
        }
    }
    return $cache[$cle] ?? $default;
}

function setParam(string $cle, string $valeur): void {
    getDB()->prepare("INSERT INTO parametres (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur), updated_at=NOW()")
           ->execute([$cle, $valeur]);
}

function getLogoUrl(): string {
    $path = getParam('logo_path');
    if ($path && file_exists(APP_ROOT . '/assets/' . $path)) {
        return APP_URL . '/assets/' . $path;
    }
    return '';
}
