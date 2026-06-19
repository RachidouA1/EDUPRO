<?php
/**
 * EPSI – Migration : nouveaux rôles utilisateurs
 * Accéder via : http://localhost/EDUPRO/install/migrate_roles.php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // 1. Modifier l'ENUM role pour ajouter les nouveaux rôles
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','directeur','scolarite','enseignant','comptable','etudiant') NOT NULL DEFAULT 'etudiant'");
        $messages[] = "✅ Colonne <code>role</code> mise à jour avec les nouveaux rôles.";

        // 2. Créer les comptes de démonstration pour chaque nouveau rôle
        $comptes = [
            [
                'nom'    => 'Direction',
                'prenom' => 'Directeur',
                'email'  => 'directeur@epsi.sn',
                'role'   => 'directeur',
                'pwd'    => 'Directeur@2025',
            ],
            [
                'nom'    => 'Scolarité',
                'prenom' => 'Secrétaire',
                'email'  => 'scolarite@epsi.sn',
                'role'   => 'scolarite',
                'pwd'    => 'Scolarite@2025',
            ],
            [
                'nom'    => 'Demo',
                'prenom' => 'Enseignant',
                'email'  => 'enseignant@epsi.sn',
                'role'   => 'enseignant',
                'pwd'    => 'Enseignant@2025',
            ],
            [
                'nom'    => 'Finance',
                'prenom' => 'Comptable',
                'email'  => 'comptable@epsi.sn',
                'role'   => 'comptable',
                'pwd'    => 'Comptable@2025',
            ],
            [
                'nom'    => 'Demo',
                'prenom' => 'Etudiant',
                'email'  => 'etudiant@epsi.sn',
                'role'   => 'etudiant',
                'pwd'    => 'Etudiant@2025',
            ],
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO users (nom, prenom, email, password, role, actif) VALUES (?,?,?,?,?,1)");
        foreach ($comptes as $c) {
            $stmt->execute([$c['nom'], $c['prenom'], $c['email'], password_hash($c['pwd'], PASSWORD_DEFAULT), $c['role']]);
            $messages[] = "👤 Compte créé : <strong>{$c['email']}</strong> (rôle : {$c['role']})";
        }

        $messages[] = "✅ Migration terminée avec succès !";

    } catch (PDOException $e) {
        $errors[] = "Erreur : " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migration des rôles – EPSI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
body { background: linear-gradient(135deg, #0f2d5c, #1a4a8a); min-height:100vh; display:flex; align-items:center; justify-content:center; }
.card { max-width:680px; width:100%; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
.logo { width:60px; height:60px; background:#1a73e8; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; color:#fff; margin:0 auto 1rem; }
.role-row { display:flex; align-items:center; justify-content:space-between; padding:.6rem 1rem; border-radius:8px; background:#f8f9fa; margin-bottom:.5rem; }
.role-badge { padding:.25rem .75rem; border-radius:50px; font-size:.78rem; font-weight:600; }
</style>
</head>
<body>
<div class="card">
  <div class="card-body p-4">
    <div class="logo"><i class="fas fa-users-cog"></i></div>
    <h3 class="text-center fw-bold mb-1" style="color:#0f2d5c">Migration des Rôles</h3>
    <p class="text-center text-muted mb-4">EPSI – Ajout des nouveaux rôles utilisateurs</p>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= $e ?></div>
    <?php endforeach; ?>

    <?php if (!empty($messages)): ?>
      <div class="alert alert-success">
        <?php foreach ($messages as $m): ?>
          <div class="mb-1"><?= $m ?></div>
        <?php endforeach; ?>
      </div>

      <?php if (in_array("✅ Migration terminée avec succès !", $messages)): ?>
        <h5 class="mt-4 mb-3">Comptes créés :</h5>
        <?php
        $comptesList = [
            ['email'=>'admin@epsi.sn',       'pwd'=>'Admin@2025',       'role'=>'admin',      'color'=>'#0f2d5c', 'label'=>'Administrateur'],
            ['email'=>'directeur@epsi.sn',    'pwd'=>'Directeur@2025',   'role'=>'directeur',  'color'=>'#5c35a0', 'label'=>'Directeur'],
            ['email'=>'scolarite@epsi.sn',    'pwd'=>'Scolarite@2025',   'role'=>'scolarite',  'color'=>'#1a73e8', 'label'=>'Scolarité'],
            ['email'=>'enseignant@epsi.sn',   'pwd'=>'Enseignant@2025',  'role'=>'enseignant', 'color'=>'#34a853', 'label'=>'Enseignant'],
            ['email'=>'comptable@epsi.sn',    'pwd'=>'Comptable@2025',   'role'=>'comptable',  'color'=>'#f57c00', 'label'=>'Comptable'],
            ['email'=>'etudiant@epsi.sn',     'pwd'=>'Etudiant@2025',    'role'=>'etudiant',   'color'=>'#ea4335', 'label'=>'Étudiant'],
        ];
        foreach ($comptesList as $c):
        ?>
        <div class="role-row">
          <div>
            <strong><?= $c['email'] ?></strong><br>
            <small class="text-muted">Mot de passe : <code><?= $c['pwd'] ?></code></small>
          </div>
          <span class="role-badge" style="background:<?= $c['color'] ?>;color:#fff"><?= $c['label'] ?></span>
        </div>
        <?php endforeach; ?>
        <a href="/EDUPRO/index.php" class="btn btn-primary w-100 mt-3">
          <i class="fas fa-sign-in-alt me-2"></i>Se connecter
        </a>
      <?php endif; ?>

    <?php else: ?>
      <h5 class="mb-3">Nouveaux rôles à ajouter :</h5>
      <div class="role-row"><div><strong>directeur</strong><div class="text-muted" style="font-size:.82rem">Consultation globale, rapports, lecture seule</div></div><span class="role-badge" style="background:#5c35a0;color:#fff">Directeur</span></div>
      <div class="role-row"><div><strong>scolarite</strong><div class="text-muted" style="font-size:.82rem">Gestion étudiants, inscriptions, paiements scolarité, notes</div></div><span class="role-badge" style="background:#1a73e8;color:#fff">Scolarité</span></div>
      <div class="role-row"><div><strong>comptable</strong><div class="text-muted" style="font-size:.82rem">Recettes, dépenses, rapports financiers, paiements enseignants</div></div><span class="role-badge" style="background:#f57c00;color:#fff">Comptable</span></div>

      <div class="alert alert-warning mt-3 py-2" style="font-size:.85rem">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Cette migration modifie la structure de la table <code>users</code>. Faites une sauvegarde avant de continuer.
      </div>

      <form method="POST" class="mt-3">
        <button type="submit" name="migrate" value="1" class="btn btn-primary w-100">
          <i class="fas fa-database me-2"></i>Lancer la migration
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
