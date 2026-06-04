<?php
define('DB_HOST',    getenv('DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME') ?: 'edupro');
define('DB_USER',    getenv('DB_USER') ?: 'root');
define('DB_PASS',    getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="padding:2rem;font-family:sans-serif;background:#fff3f3;color:#c0392b;border-left:4px solid #c0392b;margin:2rem auto;max-width:600px;border-radius:8px">
                <h3 style="margin:0 0 1rem">Erreur de connexion à la base de données</h3>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <p><a href="/EDUPRO/install/setup.php" style="color:#c0392b">Cliquez ici pour installer la base de données</a></p>
            </div>');
        }
    }
    return $pdo;
}
