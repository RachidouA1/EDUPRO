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
    $db      = getDB();
    $short   = substr(date('Y'), 2);
    $table   = ($prefix === 'ETU') ? 'etudiants' : 'enseignants';
    $ecoleId = getEcoleId();
    try {
        if ($ecoleId > 0) {
            $s = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE ecole_id = ?");
            $s->execute([$ecoleId]);
        } else {
            $s = $db->query("SELECT COUNT(*) FROM `{$table}`");
        }
        $count = (int)$s->fetchColumn();
    } catch (PDOException $e) { $count = 0; }
    do {
        $count++;
        $candidate = $prefix . $short . str_pad($count, 4, '0', STR_PAD_LEFT);
        $chk = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE matricule = ?");
        $chk->execute([$candidate]);
    } while ((int)$chk->fetchColumn() > 0);
    return $candidate;
}

function getActiveAnnee(): ?array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT * FROM annees_academiques WHERE actif = 1 AND ecole_id = ? LIMIT 1");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT * FROM annees_academiques WHERE actif = 1 LIMIT 1");
        }
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

function getActiveSemestre(): ?array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT s.* FROM semestres s JOIN annees_academiques a ON a.id = s.annee_id WHERE s.actif = 1 AND a.ecole_id = ? LIMIT 1");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT * FROM semestres WHERE actif = 1 LIMIT 1");
        }
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

function getFilieres(): array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT * FROM filieres WHERE actif = 1 AND ecole_id = ? ORDER BY nom");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT * FROM filieres WHERE actif = 1 ORDER BY nom");
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getNiveaux(?int $filiere_id = null): array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($filiere_id) {
            $stmt = $db->prepare("SELECT n.*, f.nom as filiere_nom FROM niveaux n JOIN filieres f ON f.id = n.filiere_id WHERE n.filiere_id = ? ORDER BY n.ordre");
            $stmt->execute([$filiere_id]);
        } elseif ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT n.*, f.nom as filiere_nom FROM niveaux n JOIN filieres f ON f.id = n.filiere_id WHERE f.ecole_id = ? ORDER BY f.nom, n.ordre");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT n.*, f.nom as filiere_nom FROM niveaux n JOIN filieres f ON f.id = n.filiere_id ORDER BY f.nom, n.ordre");
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getAnneesAcademiques(): array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT * FROM annees_academiques WHERE ecole_id = ? ORDER BY libelle DESC");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT * FROM annees_academiques ORDER BY libelle DESC");
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getSemestres(?int $annee_id = null): array {
    $db      = getDB();
    $ecoleId = getEcoleId();
    try {
        if ($annee_id) {
            $stmt = $db->prepare("SELECT * FROM semestres WHERE annee_id = ? ORDER BY id");
            $stmt->execute([$annee_id]);
        } elseif ($ecoleId > 0) {
            $stmt = $db->prepare("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id = s.annee_id WHERE a.ecole_id = ? ORDER BY a.libelle DESC, s.id");
            $stmt->execute([$ecoleId]);
        } else {
            $stmt = $db->query("SELECT s.*, a.libelle as annee_libelle FROM semestres s JOIN annees_academiques a ON a.id = s.annee_id ORDER BY a.libelle DESC, s.id");
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
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
 * - No $niveauId → filière-level check: true if any section exists for that filière
 * - With $niveauId → true if section has niveau_id=NULL (all levels) or matches exactly
 */
function coordinateurCanAccess(int $filiereId, ?int $niveauId = null): bool {
    foreach (getCoordinateurSections() as $s) {
        if ((int)$s['filiere_id'] !== $filiereId) continue;
        if ($niveauId === null) return true;                // filière-only check passes
        if ($s['niveau_id'] === null) return true;          // all levels granted
        if ((int)$s['niveau_id'] === $niveauId) return true;
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

// ===== Paramètres de l'application (scopés à l'école courante) =====

function getParam(string $cle, string $default = ''): string {
    static $cache = [];
    $ecoleId = getEcoleId();

    // Try to serve from the ecoles table first for key school fields
    $ecoleFields = ['etablissement_nom','etablissement_slogan','etablissement_adresse','etablissement_ville',
                    'etablissement_pays','etablissement_telephone','etablissement_email',
                    'logo_path','cachet_dg_path','theme_couleur_primaire','theme_couleur_sidebar'];

    if (in_array($cle, $ecoleFields) && $ecoleId > 0) {
        $ecole = getCurrentEcole();
        if ($ecole) {
            $map = [
                'etablissement_nom'       => 'nom',
                'etablissement_slogan'    => 'slogan',
                'etablissement_adresse'   => 'adresse',
                'etablissement_ville'     => 'ville',
                'etablissement_pays'      => 'pays',
                'etablissement_telephone' => 'telephone',
                'etablissement_email'     => 'email',
                'logo_path'               => 'logo_path',
                'cachet_dg_path'          => 'cachet_dg_path',
                'theme_couleur_primaire'  => 'theme_couleur_primaire',
                'theme_couleur_sidebar'   => 'theme_couleur_sidebar',
            ];
            if (isset($map[$cle]) && isset($ecole[$map[$cle]])) {
                return (string)($ecole[$map[$cle]] ?? $default);
            }
        }
    }

    // Fall back to parametres table (school-scoped when possible)
    if (!isset($cache[$ecoleId])) {
        try {
            if ($ecoleId > 0) {
                $stmt = getDB()->prepare("SELECT cle, valeur FROM parametres WHERE ecole_id = ?");
                $stmt->execute([$ecoleId]);
            } else {
                $stmt = getDB()->query("SELECT cle, valeur FROM parametres LIMIT 500");
            }
            $rows = $stmt->fetchAll();
            $cache[$ecoleId] = [];
            foreach ($rows as $r) $cache[$ecoleId][$r['cle']] = $r['valeur'] ?? '';
        } catch (PDOException $e) {
            $cache[$ecoleId] = [];
        }
    }
    return $cache[$ecoleId][$cle] ?? $default;
}

function setParam(string $cle, string $valeur): void {
    $ecoleId = getEcoleId();

    // Sync to ecoles table for core fields
    $map = [
        'etablissement_nom'       => 'nom',
        'etablissement_slogan'    => 'slogan',
        'etablissement_adresse'   => 'adresse',
        'etablissement_ville'     => 'ville',
        'etablissement_pays'      => 'pays',
        'etablissement_telephone' => 'telephone',
        'etablissement_email'     => 'email',
        'logo_path'               => 'logo_path',
        'cachet_dg_path'          => 'cachet_dg_path',
        'theme_couleur_primaire'  => 'theme_couleur_primaire',
        'theme_couleur_sidebar'   => 'theme_couleur_sidebar',
    ];
    if (isset($map[$cle]) && $ecoleId > 0) {
        try {
            $col = $map[$cle];
            getDB()->prepare("UPDATE ecoles SET `$col` = ? WHERE id = ?")
                   ->execute([$valeur, $ecoleId]);
            $es = getDB()->prepare("SELECT * FROM ecoles WHERE id = ?");
            $es->execute([$ecoleId]);
            $_SESSION['ecole'] = $es->fetch() ?: null;
        } catch (PDOException $e) {}
    }

    // Write to parametres table (with ecole_id when available)
    try {
        if ($ecoleId > 0) {
            getDB()->prepare("INSERT INTO parametres (cle, valeur, ecole_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur), updated_at=NOW()")
                   ->execute([$cle, $valeur, $ecoleId]);
        } else {
            getDB()->prepare("INSERT INTO parametres (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur), updated_at=NOW()")
                   ->execute([$cle, $valeur]);
        }
    } catch (PDOException $e) {}
}

function getLogoUrl(): string {
    $path = getParam('logo_path');
    if ($path && file_exists(APP_ROOT . '/assets/' . $path)) {
        return APP_URL . '/assets/' . $path . '?v=' . filemtime(APP_ROOT . '/assets/' . $path);
    }
    return '';
}

// ── Licences ──────────────────────────────────────────────────────────────────

function generateLicenceKey(string $ecoleCode): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $ecoleCode));
    $prefix = str_pad(substr($prefix, 0, 4), 4, 'X');
    return 'EDUPRO-' . $prefix
         . '-' . strtoupper(bin2hex(random_bytes(2)))
         . '-' . strtoupper(bin2hex(random_bytes(2)))
         . '-' . strtoupper(bin2hex(random_bytes(2)));
}

/** Returns the most recent licence for a school (any status). */
function getLicenceEcole(int $ecoleId): ?array {
    if ($ecoleId <= 0) return null;
    try {
        $s = getDB()->prepare("SELECT * FROM licences WHERE ecole_id = ? ORDER BY created_at DESC LIMIT 1");
        $s->execute([$ecoleId]);
        return $s->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

/** Returns the currently active licence for a school (statut = 'active' only). */
function getLicenceActive(int $ecoleId): ?array {
    if ($ecoleId <= 0) return null;
    try {
        $s = getDB()->prepare("SELECT * FROM licences WHERE ecole_id = ? AND statut = 'active' ORDER BY date_expiration DESC LIMIT 1");
        $s->execute([$ecoleId]);
        return $s->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

function getLicenceBadge(?array $lic): string {
    if (!$lic) return '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Aucune licence</span>';
    return match($lic['statut']) {
        'active'    => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
        'expiree'   => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Expirée</span>',
        'suspendue' => '<span class="badge bg-secondary"><i class="fas fa-pause-circle me-1"></i>Suspendue</span>',
        'revoquee'  => '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Révoquée</span>',
        default     => '<span class="badge bg-secondary">' . h($lic['statut']) . '</span>',
    };
}
