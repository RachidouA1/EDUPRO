<?php
/**
 * EPSI – Script d'installation de la base de données
 * Accéder via : http://localhost/EDUPRO/install/setup.php
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

$errors   = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    try {
        // Connexion sans base de données d'abord
        $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $messages[] = "Connexion MySQL établie.";

        // Créer la base de données
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        $messages[] = "Base de données créée.";

        // ===== TABLES =====
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            email VARCHAR(150) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur') NOT NULL DEFAULT 'etudiant',
            reference_id INT NULL,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS filieres (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(20) UNIQUE NOT NULL,
            nom VARCHAR(200) NOT NULL,
            description TEXT,
            duree_annees INT NOT NULL DEFAULT 3,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS niveaux (
            id INT PRIMARY KEY AUTO_INCREMENT,
            filiere_id INT NOT NULL,
            nom VARCHAR(50) NOT NULL,
            ordre INT NOT NULL DEFAULT 1,
            FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS annees_academiques (
            id INT PRIMARY KEY AUTO_INCREMENT,
            libelle VARCHAR(20) NOT NULL,
            date_debut DATE,
            date_fin DATE,
            actif TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS semestres (
            id INT PRIMARY KEY AUTO_INCREMENT,
            annee_id INT NOT NULL,
            nom VARCHAR(50) NOT NULL,
            date_debut DATE,
            date_fin DATE,
            actif TINYINT(1) DEFAULT 0,
            FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS enseignants (
            id INT PRIMARY KEY AUTO_INCREMENT,
            matricule VARCHAR(20) UNIQUE NOT NULL,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            sexe ENUM('M','F') NOT NULL,
            date_naissance DATE,
            telephone VARCHAR(20),
            email VARCHAR(150),
            adresse TEXT,
            specialite VARCHAR(200),
            type_contrat ENUM('permanent','vacataire') DEFAULT 'permanent',
            salaire_base DECIMAL(10,2) DEFAULT 0,
            date_recrutement DATE,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS etudiants (
            id INT PRIMARY KEY AUTO_INCREMENT,
            matricule VARCHAR(20) UNIQUE NOT NULL,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            sexe ENUM('M','F') NOT NULL,
            date_naissance DATE,
            lieu_naissance VARCHAR(100),
            telephone VARCHAR(20),
            email VARCHAR(150),
            adresse TEXT,
            nom_tuteur VARCHAR(200),
            telephone_tuteur VARCHAR(20),
            filiere_id INT,
            niveau_id INT,
            annee_id INT,
            statut ENUM('actif','transfere','exclu','diplome') DEFAULT 'actif',
            photo VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL,
            FOREIGN KEY (niveau_id) REFERENCES niveaux(id) ON DELETE SET NULL,
            FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS matieres (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(20) UNIQUE NOT NULL,
            nom VARCHAR(200) NOT NULL,
            filiere_id INT,
            niveau_id INT,
            semestre_id INT,
            coefficient DECIMAL(4,2) DEFAULT 1,
            volume_horaire INT DEFAULT 0,
            enseignant_id INT,
            formule_calcul VARCHAR(20) NOT NULL DEFAULT 'pondere',
            seuil_reussite DECIMAL(4,2) DEFAULT 10,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL,
            FOREIGN KEY (niveau_id) REFERENCES niveaux(id) ON DELETE SET NULL,
            FOREIGN KEY (semestre_id) REFERENCES semestres(id) ON DELETE SET NULL,
            FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS notes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            etudiant_id INT NOT NULL,
            matiere_id INT NOT NULL,
            annee_id INT NOT NULL,
            semestre_id INT NULL,
            session TINYINT NOT NULL DEFAULT 1,
            note_cc DECIMAL(5,2) DEFAULT NULL,
            note_exam DECIMAL(5,2) DEFAULT NULL,
            note_finale DECIMAL(5,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_note (etudiant_id, matiere_id, annee_id, semestre_id, session),
            FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE,
            FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE,
            FOREIGN KEY (annee_id) REFERENCES annees_academiques(id),
            FOREIGN KEY (semestre_id) REFERENCES semestres(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS types_frais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nom VARCHAR(100) NOT NULL,
            montant_defaut DECIMAL(10,2) DEFAULT 0,
            description TEXT
        );

        CREATE TABLE IF NOT EXISTS paiements_etudiants (
            id INT PRIMARY KEY AUTO_INCREMENT,
            etudiant_id INT NOT NULL,
            annee_id INT,
            type_frais_id INT,
            libelle VARCHAR(200),
            montant DECIMAL(10,2) NOT NULL,
            montant_paye DECIMAL(10,2) DEFAULT 0,
            numero_recu VARCHAR(20) NULL,
            date_paiement DATE,
            mode_paiement ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
            reference VARCHAR(100),
            statut ENUM('en_attente','partiel','complet') DEFAULT 'en_attente',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS versements (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            paiement_id     INT NOT NULL,
            etudiant_id     INT NOT NULL,
            num_versement   TINYINT NOT NULL DEFAULT 1,
            montant         DECIMAL(10,2) NOT NULL,
            date_versement  DATE NOT NULL,
            mode_paiement   VARCHAR(50) DEFAULT 'especes',
            reference       VARCHAR(100) NULL,
            created_by      INT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (paiement_id) REFERENCES paiements_etudiants(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS paiements_enseignants (
            id INT PRIMARY KEY AUTO_INCREMENT,
            enseignant_id INT NOT NULL,
            annee_id INT,
            semestre_id INT,
            libelle VARCHAR(200) NOT NULL,
            type ENUM('salaire','prime','vacation','autre') DEFAULT 'salaire',
            montant DECIMAL(10,2) NOT NULL,
            date_paiement DATE,
            mode_paiement ENUM('especes','cheque','virement') DEFAULT 'virement',
            reference VARCHAR(100),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recettes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            annee_id INT,
            date_recette DATE NOT NULL,
            libelle VARCHAR(200) NOT NULL,
            categorie ENUM('inscription','scolarite','examen','autre') DEFAULT 'autre',
            montant DECIMAL(10,2) NOT NULL,
            mode_paiement ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
            reference VARCHAR(100),
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS depenses (
            id INT PRIMARY KEY AUTO_INCREMENT,
            annee_id INT,
            date_depense DATE NOT NULL,
            libelle VARCHAR(200) NOT NULL,
            categorie ENUM('salaire','fournitures','equipement','loyer','electricite','eau','internet','autre') DEFAULT 'autre',
            montant DECIMAL(10,2) NOT NULL,
            beneficiaire VARCHAR(200),
            mode_paiement ENUM('especes','cheque','virement') DEFAULT 'especes',
            statut ENUM('en_attente','approuvee','rejetee') NOT NULL DEFAULT 'approuvee',
            approuve_par INT NULL,
            approuve_at DATETIME NULL,
            note_rejet TEXT NULL,
            reference VARCHAR(100),
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS heures_cours (
            id INT PRIMARY KEY AUTO_INCREMENT,
            enseignant_id INT NOT NULL,
            matiere_id INT NOT NULL,
            annee_id INT NOT NULL,
            semestre_id INT NOT NULL,
            heures_prevues INT DEFAULT 0,
            heures_effectuees INT DEFAULT 0,
            taux_horaire DECIMAL(10,2) DEFAULT 0,
            FOREIGN KEY (enseignant_id) REFERENCES enseignants(id),
            FOREIGN KEY (matiere_id) REFERENCES matieres(id),
            FOREIGN KEY (annee_id) REFERENCES annees_academiques(id),
            FOREIGN KEY (semestre_id) REFERENCES semestres(id)
        );

        CREATE TABLE IF NOT EXISTS parametres (
            id INT PRIMARY KEY AUTO_INCREMENT,
            cle VARCHAR(100) UNIQUE NOT NULL,
            valeur TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        ";

        // Execute each statement separately
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (!empty($stmt)) $pdo->exec($stmt);
        }
        $messages[] = "Tables créées avec succès.";

        // ===== DONNÉES INITIALES =====

        // Filières
        $filieresData = [
            ['ASB',  'Agent de Santé de Base',                           'Formation d\'agent de santé de base communautaire', 3],
            ['INF',  'Licence Sciences Infirmières – Infirmier',          'Licence en sciences infirmières et obstétricales – option Infirmier', 3],
            ['SF',   'Licence Sciences Infirmières – Sage-Femme',         'Licence en sciences infirmières et obstétricales – option Sage-Femme', 3],
            ['VP',   'Vendeur en Pharmacie',                              'Formation de vendeur/préparateur en officine pharmaceutique', 1],
        ];
        $stmtF = $pdo->prepare("INSERT IGNORE INTO filieres (code, nom, description, duree_annees) VALUES (?,?,?,?)");
        foreach ($filieresData as $f) $stmtF->execute($f);
        $messages[] = "Filières insérées.";

        // Niveaux
        $filieres = $pdo->query("SELECT id, code, duree_annees FROM filieres")->fetchAll(PDO::FETCH_ASSOC);
        $stmtN = $pdo->prepare("INSERT IGNORE INTO niveaux (filiere_id, nom, ordre) VALUES (?,?,?)");
        foreach ($filieres as $fil) {
            for ($i = 1; $i <= $fil['duree_annees']; $i++) {
                $stmtN->execute([$fil['id'], 'Année ' . $i, $i]);
            }
        }
        $messages[] = "Niveaux insérés.";

        // Année académique active
        $pdo->exec("INSERT IGNORE INTO annees_academiques (libelle, date_debut, date_fin, actif) VALUES ('2024-2025','2024-10-01','2025-07-31',1)");
        $pdo->exec("INSERT IGNORE INTO annees_academiques (libelle, date_debut, date_fin, actif) VALUES ('2025-2026','2025-10-01','2026-07-31',0)");
        $anneeId = $pdo->lastInsertId();
        // Get the active annee id
        $anneeId = $pdo->query("SELECT id FROM annees_academiques WHERE libelle='2024-2025'")->fetchColumn();

        // Semestres
        $pdo->prepare("INSERT IGNORE INTO semestres (annee_id, nom, date_debut, date_fin, actif) VALUES (?,?,?,?,?)")
            ->execute([$anneeId, 'Semestre 1', '2024-10-01', '2025-01-31', 1]);
        $pdo->prepare("INSERT IGNORE INTO semestres (annee_id, nom, date_debut, date_fin, actif) VALUES (?,?,?,?,?)")
            ->execute([$anneeId, 'Semestre 2', '2025-02-01', '2025-07-31', 0]);
        $messages[] = "Années académiques et semestres insérés.";

        // Types de frais
        $fraisData = [
            ['Droits d\'inscription', 50000,   'Frais d\'inscription au dossier'],
            ['Frais de scolarité',    300000,  'Frais de scolarité'],
            ['Frais d\'examen',       25000,   'Frais de passage d\'examen'],
        ];
        $stmtTF = $pdo->prepare("INSERT IGNORE INTO types_frais (nom, montant_defaut, description) VALUES (?,?,?)");
        foreach ($fraisData as $tf) $stmtTF->execute($tf);
        $messages[] = "Types de frais insérés.";

        // Paramètres par défaut de l'application
        $params = [
            ['etablissement_nom',       'École Privée de Santé Ibn Rochd'],
            ['etablissement_slogan',    'Excellence – Santé – Service'],
            ['etablissement_adresse',   'Dakar, Sénégal'],
            ['etablissement_telephone', ''],
            ['etablissement_email',     ''],
            ['theme_couleur_primaire',  '#1a73e8'],
            ['theme_couleur_sidebar',   '#0f2d5c'],
            ['logo_path',               ''],
        ];
        $stmtP = $pdo->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?,?)");
        foreach ($params as $p) $stmtP->execute($p);
        $messages[] = "Paramètres initialisés.";

        // Utilisateur admin par défaut
        $adminPass = password_hash('Admin@2025', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT IGNORE INTO users (nom, prenom, email, password, role, actif) VALUES (?,?,?,?,?,?)")
            ->execute(['EPSI', 'Administrateur', 'admin@epsi.sn', $adminPass, 'admin', 1]);
        $messages[] = "Compte administrateur créé.";

        $messages[] = "✅ Installation terminée avec succès !";

    } catch (PDOException $e) {
        $errors[] = "Erreur SQL: " . $e->getMessage();
    } catch (Exception $e) {
        $errors[] = "Erreur: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation EPSI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
body { background: linear-gradient(135deg, #0f2d5c, #1a4a8a); min-height: 100vh; display:flex; align-items:center; justify-content:center; }
.setup-card { background:#fff; border-radius:18px; padding:2.5rem; max-width:600px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.3); }
.logo { width:70px; height:70px; background:#1a73e8; border-radius:18px; display:flex; align-items:center; justify-content:center; font-size:2rem; color:#fff; margin:0 auto 1.5rem; }
</style>
</head>
<body>
<div class="setup-card">
  <div class="logo"><i class="fas fa-hospital"></i></div>
  <h2 class="text-center mb-1 fw-bold" style="color:#0f2d5c">EPSI</h2>
  <p class="text-center text-muted mb-4">École Privée de Santé Ibn Rochd<br><small>Script d'installation de la base de données</small></p>

  <?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($messages)): ?>
    <div class="alert alert-success">
      <?php foreach ($messages as $m): ?>
        <div><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if (in_array("✅ Installation terminée avec succès !", $messages)): ?>
      <div class="alert alert-info">
        <strong>Identifiants par défaut :</strong><br>
        Email : <code>admin@epsi.sn</code><br>
        Mot de passe : <code>Admin@2025</code><br>
        <small class="text-muted">Changez le mot de passe après la première connexion.</small>
      </div>
      <a href="/EDUPRO/index.php" class="btn btn-primary w-100 mt-2">
        <i class="fas fa-sign-in-alt me-2"></i>Se connecter
      </a>
    <?php endif; ?>
  <?php else: ?>
    <form method="POST">
      <div class="mb-3 p-3 bg-light rounded">
        <h6 class="fw-bold">Configuration de la base de données</h6>
        <table class="table table-sm mb-0">
          <tr><td>Hôte</td><td><code><?= DB_HOST ?></code></td></tr>
          <tr><td>Base de données</td><td><code><?= DB_NAME ?></code></td></tr>
          <tr><td>Utilisateur</td><td><code><?= DB_USER ?></code></td></tr>
        </table>
        <small class="text-muted">Modifiez <code>config/database.php</code> si nécessaire.</small>
      </div>
      <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Ce script va créer la base de données et insérer les données initiales. Les données existantes seront préservées (INSERT IGNORE).
      </div>
      <button type="submit" name="install" value="1" class="btn btn-primary w-100">
        <i class="fas fa-database me-2"></i>Lancer l'installation
      </button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
