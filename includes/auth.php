<?php
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function hasRole($role): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    // superadmin has all permissions
    if ($user['role'] === 'superadmin') return true;
    if (is_array($role)) return in_array($user['role'], $role);
    return $user['role'] === $role;
}

function isSuperAdmin(): bool {
    $user = getCurrentUser();
    return ($user['role'] ?? '') === 'superadmin';
}

/** Returns the current school ID from session (0 if not set / superadmin with no context). */
function getEcoleId(): int {
    return (int)($_SESSION['ecole_id'] ?? 0);
}

/** Returns the current school record (from ecoles table). */
function getCurrentEcole(): ?array {
    return $_SESSION['ecole'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
    // Block school users if their licence is suspended or revoked
    if (!isSuperAdmin()) {
        $ecId = getEcoleId();
        if ($ecId > 0) {
            $lic = getLicenceEcole($ecId);
            if ($lic && in_array($lic['statut'], ['suspendue', 'revoquee'])) {
                doLogout();
                header('Location: ' . APP_URL . '/index.php?err=licence');
                exit;
            }
        }
    }
}

function requireRole($role): void {
    requireLogin();
    // Superadmin manages schools — block access to all school-operation modules
    // unless the page explicitly lists 'superadmin' in its allowed roles.
    if (isSuperAdmin()) {
        $roles = is_array($role) ? $role : [$role];
        if (!in_array('superadmin', $roles)) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
        return;
    }
    if (!hasRole($role)) {
        setFlash('error', 'Accès refusé. Vous n\'avez pas les droits nécessaires.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

/** Superadmin-only pages. */
function requireSuperAdmin(): void {
    requireLogin();
    if (!isSuperAdmin()) {
        setFlash('error', 'Accès réservé au SuperAdmin.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function login(string $identifier, string $password): bool {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND actif = 1");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'nom'          => $user['nom'],
        'prenom'       => $user['prenom'],
        'email'        => $user['email'],
        'role'         => $user['role'],
        'reference_id' => $user['reference_id'],
        'ecole_id'     => $user['ecole_id'] ?? null,
    ];

    // Set school context in session
    $ecoleId = (int)($user['ecole_id'] ?? 0);
    $_SESSION['ecole_id'] = $ecoleId;

    if ($ecoleId > 0) {
        try {
            $es = $db->prepare("SELECT * FROM ecoles WHERE id = ?");
            $es->execute([$ecoleId]);
            $_SESSION['ecole'] = $es->fetch() ?: null;
        } catch (PDOException $e) {
            $_SESSION['ecole'] = null;
        }
    } else {
        $_SESSION['ecole'] = null;
    }

    return true;
}

/**
 * Superadmin: switch current school context.
 * Returns false if the school does not exist.
 */
function switchEcole(int $ecoleId): bool {
    if (!isSuperAdmin()) return false;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM ecoles WHERE id = ? AND actif = 1");
        $stmt->execute([$ecoleId]);
        $ecole = $stmt->fetch();
        if (!$ecole) return false;
        $_SESSION['ecole_id']             = $ecoleId;
        $_SESSION['ecole']                = $ecole;
        $_SESSION['user']['ecole_id']     = $ecoleId;
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function doLogout(): void {
    $_SESSION = [];
    session_destroy();
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
