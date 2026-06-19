<?php
/**
 * EPSI – Migration : UE, PV Supérieur, seuil_reussite
 * Accéder via : http://localhost/EDUPRO/install/migrate_ue_pv.php
 * En Docker  : définir ALLOW_INSTALL=1 dans l'environnement du container
 */

if (!getenv('ALLOW_INSTALL')) {
    http_response_code(403);
    die('<h1 style="font-family:sans-serif">403 Forbidden</h1><p>Définir la variable d\'environnement <code>ALLOW_INSTALL=1</code> pour accéder à cette page.</p>');
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'edupro');

$messages = [];
$errors   = [];

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. Table UE
    $pdo->exec("CREATE TABLE IF NOT EXISTS ue (
        id          INT          PRIMARY KEY AUTO_INCREMENT,
        nom         VARCHAR(100) NOT NULL,
        code_ue     VARCHAR(20)  UNIQUE NOT NULL,
        semestre_num TINYINT     NOT NULL DEFAULT 1,
        coefficient DECIMAL(3,2) NOT NULL DEFAULT 1.00,
        credit      INT          NOT NULL DEFAULT 3,
        filiere_id  INT          NULL,
        actif       TINYINT(1)   DEFAULT 1,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = "✅ Table <code>ue</code> créée (ou déjà existante).";

    // 2. ue_id dans matieres
    try {
        $pdo->exec("ALTER TABLE matieres ADD COLUMN ue_id INT NULL AFTER semestre_id");
        $pdo->exec("ALTER TABLE matieres ADD CONSTRAINT fk_mat_ue FOREIGN KEY (ue_id) REFERENCES ue(id) ON DELETE SET NULL");
        $messages[] = "✅ Colonne <code>ue_id</code> ajoutée à <code>matieres</code>.";
    } catch (PDOException $e) {
        $messages[] = "ℹ️  Colonne <code>ue_id</code> déjà présente dans <code>matieres</code>.";
    }

    // 3. seuil_reussite dans matieres
    try {
        $pdo->exec("ALTER TABLE matieres ADD COLUMN seuil_reussite INT NOT NULL DEFAULT 12 AFTER ue_id");
        $messages[] = "✅ Colonne <code>seuil_reussite</code> (défaut 12/20) ajoutée à <code>matieres</code>.";
    } catch (PDOException $e) {
        $messages[] = "ℹ️  Colonne <code>seuil_reussite</code> déjà présente dans <code>matieres</code>.";
    }

    // 4. session dans notes (déjà ajouté par notes.php, on s'assure juste)
    try {
        $pdo->exec("ALTER TABLE notes ADD COLUMN session TINYINT NOT NULL DEFAULT 1");
        $messages[] = "✅ Colonne <code>session</code> ajoutée à <code>notes</code>.";
    } catch (PDOException $e) {
        $messages[] = "ℹ️  Colonne <code>session</code> déjà présente dans <code>notes</code>.";
    }

    // 5. Table PV
    $pdo->exec("CREATE TABLE IF NOT EXISTS pv (
        id               INT         PRIMARY KEY AUTO_INCREMENT,
        etudiant_id      INT         NOT NULL,
        filiere_id       INT         NOT NULL,
        annee_id         INT         NOT NULL,
        semestre_num     TINYINT     NOT NULL DEFAULT 1,
        session_num      TINYINT     NOT NULL DEFAULT 1,
        decision         VARCHAR(100),
        moyenne_generale DECIMAL(4,2),
        date_edition     DATETIME    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE,
        FOREIGN KEY (annee_id)    REFERENCES annees_academiques(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = "✅ Table <code>pv</code> créée (ou déjà existante).";

    // 6. Table resultats_ue
    $pdo->exec("CREATE TABLE IF NOT EXISTS resultats_ue (
        id          INT         PRIMARY KEY AUTO_INCREMENT,
        pv_id       INT         NOT NULL,
        ue_id       INT         NOT NULL,
        moyenne_ue  DECIMAL(4,2),
        est_valide  TINYINT(1)  DEFAULT 0,
        FOREIGN KEY (pv_id) REFERENCES pv(id) ON DELETE CASCADE,
        FOREIGN KEY (ue_id) REFERENCES ue(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = "✅ Table <code>resultats_ue</code> créée (ou déjà existante).";

} catch (PDOException $e) {
    $errors[] = "Erreur PDO : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Migration UE / PV – EPSI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:700px">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="bi bi-database-fill-gear me-2"></i>Migration – UE & PV Supérieur</h4>
        </div>
        <div class="card-body">
            <?php foreach ($messages as $msg): ?>
                <p class="mb-1"><?= $msg ?></p>
            <?php endforeach; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
            <?php if (empty($errors)): ?>
                <hr>
                <div class="alert alert-success">
                    <strong>Migration terminée !</strong><br>
                    Les modules <strong>UE</strong> et <strong>PV Supérieur</strong> sont maintenant disponibles.
                </div>
                <a href="/EDUPRO/dashboard.php" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt me-2"></i>Retour au tableau de bord
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
