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
    if (is_array($role)) return in_array($user['role'], $role);
    return $user['role'] === $role;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function requireRole($role): void {
    requireLogin();
    if (!hasRole($role)) {
        setFlash('error', 'Accès refusé. Vous n\'avez pas les droits nécessaires.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function login(string $identifier, string $password): bool {
    $db = getDB();

    if (str_contains($identifier, '@')) {
        // Connexion par email (staff : admin, directeur, scolarite, enseignant, comptable)
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND actif = 1");
        $stmt->execute([$identifier]);
    } else {
        // Connexion par matricule (étudiants uniquement)
        $stmt = $db->prepare("
            SELECT u.* FROM users u
            INNER JOIN etudiants e ON e.id = u.reference_id
            WHERE e.matricule = ? AND u.role = 'etudiant' AND u.actif = 1
        ");
        $stmt->execute([strtoupper(trim($identifier))]);
    }

    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'id'           => $user['id'],
            'nom'          => $user['nom'],
            'prenom'       => $user['prenom'],
            'email'        => $user['email'],
            'role'         => $user['role'],
            'reference_id' => $user['reference_id'],
        ];
        return true;
    }
    return false;
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
