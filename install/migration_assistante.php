<?php
/**
 * Migration : rôle assistante + tables courriers
 * Accéder via : http://localhost/SCO-EPSI/install/migration_assistante.php
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'epsi_db');

$errors   = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // 1. Ajouter le rôle 'assistante' à l'ENUM users
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role
            ENUM('admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur','assistante')
            NOT NULL DEFAULT 'enseignant'");
        $messages[] = "Rôle 'assistante' ajouté à la table users.";

        // 2. Créer la table courriers_depart
        $pdo->exec("CREATE TABLE IF NOT EXISTS courriers_depart (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            nbre_pieces INT NOT NULL DEFAULT 1,
            date_depart DATE NOT NULL,
            destinataire VARCHAR(255) NOT NULL,
            objet       TEXT NOT NULL,
            n_archives  VARCHAR(100) DEFAULT NULL,
            observations TEXT DEFAULT NULL,
            created_by  INT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $messages[] = "Table 'courriers_depart' créée.";

        // 3. Créer la table courriers_arrivee
        $pdo->exec("CREATE TABLE IF NOT EXISTS courriers_arrivee (
            id                  INT PRIMARY KEY AUTO_INCREMENT,
            date_arrivee        DATE NOT NULL,
            date_correspondance DATE DEFAULT NULL,
            n_correspondance    VARCHAR(100) DEFAULT NULL,
            expediteur          VARCHAR(255) NOT NULL,
            objet               TEXT NOT NULL,
            date_reponse        DATE DEFAULT NULL,
            n_reponse           VARCHAR(100) DEFAULT NULL,
            created_by          INT NOT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $messages[] = "Table 'courriers_arrivee' créée.";

        $messages[] = "<strong>Migration terminée avec succès !</strong>";

    } catch (PDOException $e) {
        $errors[] = "Erreur : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Migration – Assistante de Direction</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px">
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0">Migration – Rôle Assistante de Direction</h5>
    </div>
    <div class="card-body">
      <p>Cette migration va :</p>
      <ul>
        <li>Ajouter le rôle <code>assistante</code> dans la table <code>users</code></li>
        <li>Créer la table <code>courriers_depart</code></li>
        <li>Créer la table <code>courriers_arrivee</code></li>
      </ul>
      <?php foreach ($messages as $m): ?>
        <div class="alert alert-success py-2"><?= $m ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
      <?php if (!$messages): ?>
      <form method="POST">
        <button name="migrate" value="1" class="btn btn-primary w-100">
          Lancer la migration
        </button>
      </form>
      <?php else: ?>
        <a href="../index.php" class="btn btn-success w-100">Retour à l'accueil</a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
