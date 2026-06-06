<?php
/**
 * Migration : Architecture multi-écoles — Version robuste et idempotente
 * Chaque étape est indépendante et peut être relancée sans risque.
 * En Docker : définir ALLOW_INSTALL=1 dans l'environnement du container
 */

if (!getenv('ALLOW_INSTALL')) {
    http_response_code(403);
    die('<h1 style="font-family:sans-serif">403 Forbidden</h1><p>Définir la variable d\'environnement <code>ALLOW_INSTALL=1</code> pour accéder à cette page.</p>');
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'edupro');

$steps  = [];  // ['label'=>'...', 'status'=>'ok|warn|error', 'msg'=>'...']
$fatal  = null;

// ── Connexion ──────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $fatal = "Connexion impossible : " . $e->getMessage();
}

// ── Helpers ────────────────────────────────────────────────────────────────
function columnExists(PDO $pdo, string $table, string $col): bool {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetchAll();
    return !empty($rows);
}
function tableExists(PDO $pdo, string $table): bool {
    $rows = $pdo->query("SHOW TABLES LIKE '$table'")->fetchAll();
    return !empty($rows);
}
function indexExists(PDO $pdo, string $table, string $keyName): bool {
    $rows = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$keyName'")->fetchAll();
    return !empty($rows);
}
function step(array &$steps, string $label, callable $fn): void {
    try {
        $msg = $fn();
        $steps[] = ['label' => $label, 'status' => 'ok', 'msg' => $msg ?? ''];
    } catch (PDOException $e) {
        $steps[] = ['label' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

$ran = false;
if (!$fatal && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    $ran = true;

    // ── ÉTAPE 1 : Table ecoles ─────────────────────────────────────────────
    step($steps, 'Créer la table ecoles', function() use ($pdo) {
        if (tableExists($pdo, 'ecoles')) return 'Déjà existante — ignorée.';
        $pdo->exec("CREATE TABLE ecoles (
            id                     INT PRIMARY KEY AUTO_INCREMENT,
            code                   VARCHAR(20) NOT NULL UNIQUE,
            nom                    VARCHAR(255) NOT NULL,
            slogan                 VARCHAR(255) DEFAULT NULL,
            adresse                TEXT DEFAULT NULL,
            ville                  VARCHAR(100) DEFAULT NULL,
            pays                   VARCHAR(100) DEFAULT 'Niger',
            telephone              VARCHAR(50) DEFAULT NULL,
            email                  VARCHAR(150) DEFAULT NULL,
            logo_path              VARCHAR(255) DEFAULT NULL,
            cachet_dg_path         VARCHAR(255) DEFAULT NULL,
            theme_couleur_primaire VARCHAR(7) DEFAULT '#1a73e8',
            theme_couleur_sidebar  VARCHAR(7) DEFAULT '#0f2d5c',
            actif                  TINYINT(1) DEFAULT 1,
            created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return 'Créée.';
    });

    // ── ÉTAPE 2 : École par défaut ─────────────────────────────────────────
    step($steps, 'Créer l\'école par défaut', function() use ($pdo) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM ecoles")->fetchColumn();
        if ($count > 0) return 'École(s) déjà présente(s) — ignoré.';
        $params = [];
        try {
            $rows = $pdo->query("SELECT cle, valeur FROM parametres")->fetchAll();
            foreach ($rows as $r) $params[$r['cle']] = $r['valeur'];
        } catch (PDOException $e) {}
        $nom     = $params['etablissement_nom']    ?? 'École par défaut';
        $slogan  = $params['etablissement_slogan'] ?? '';
        $adresse = $params['etablissement_adresse'] ?? '';
        $ville   = $params['etablissement_ville']  ?? 'Tahoua';
        $pays    = $params['etablissement_pays']   ?? 'Niger';
        $tel     = $params['etablissement_telephone'] ?? '';
        $email   = $params['etablissement_email']  ?? '';
        $logo    = $params['logo_path']            ?? '';
        $cachet  = $params['cachet_dg_path']       ?? '';
        $prim    = preg_match('/^#[0-9A-Fa-f]{6}$/', $params['theme_couleur_primaire'] ?? '') ? $params['theme_couleur_primaire'] : '#1a73e8';
        $sid     = preg_match('/^#[0-9A-Fa-f]{6}$/', $params['theme_couleur_sidebar']  ?? '') ? $params['theme_couleur_sidebar']  : '#0f2d5c';
        $pdo->prepare("INSERT INTO ecoles (code,nom,slogan,adresse,ville,pays,telephone,email,logo_path,cachet_dg_path,theme_couleur_primaire,theme_couleur_sidebar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(['ECO001',$nom,$slogan,$adresse,$ville,$pays,$tel,$email,$logo,$cachet,$prim,$sid]);
        return "Créée : <strong>" . htmlspecialchars($nom) . "</strong> (ECO001).";
    });

    // Récupérer l'ID de l'école de référence
    $ecoleId = (int)$pdo->query("SELECT id FROM ecoles ORDER BY id LIMIT 1")->fetchColumn();

    // ── ÉTAPE 3 : Ajouter ecole_id aux tables ─────────────────────────────
    // matieres/ue/niveaux héritent de l'école via filieres.ecole_id (FK),
    // mais on stocke ecole_id directement pour des requêtes plus simples.
    $tableDefs = [
        'users'              => 'INT DEFAULT NULL',
        'etudiants'          => "INT NOT NULL DEFAULT $ecoleId",
        'enseignants'        => "INT NOT NULL DEFAULT $ecoleId",
        'filieres'           => "INT NOT NULL DEFAULT $ecoleId",
        'niveaux'            => "INT NOT NULL DEFAULT $ecoleId",
        'annees_academiques' => "INT NOT NULL DEFAULT $ecoleId",
        'matieres'           => "INT NOT NULL DEFAULT $ecoleId",
        'recettes'           => "INT NOT NULL DEFAULT $ecoleId",
        'depenses'           => "INT NOT NULL DEFAULT $ecoleId",
        'parametres'         => "INT NOT NULL DEFAULT $ecoleId",
        'courriers_depart'   => "INT NOT NULL DEFAULT $ecoleId",
        'courriers_arrivee'  => "INT NOT NULL DEFAULT $ecoleId",
    ];
    foreach ($tableDefs as $table => $def) {
        step($steps, "Ajouter ecole_id à $table", function() use ($pdo, $table, $def) {
            if (!tableExists($pdo, $table)) return "Table $table inexistante — ignorée.";
            if (columnExists($pdo, $table, 'ecole_id')) return 'Colonne déjà présente.';
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN ecole_id $def");
            return 'Colonne ajoutée.';
        });
    }

    // ── ÉTAPE 4 : Rattacher les données existantes ─────────────────────────
    step($steps, 'Rattacher les données à l\'école par défaut', function() use ($pdo, $ecoleId) {
        $total = 0;
        $tables = ['etudiants','enseignants','filieres','niveaux','annees_academiques','matieres','recettes','depenses','parametres','courriers_depart','courriers_arrivee'];
        foreach ($tables as $t) {
            if (!tableExists($pdo,$t) || !columnExists($pdo,$t,'ecole_id')) continue;
            $n = $pdo->exec("UPDATE `$t` SET ecole_id=$ecoleId WHERE ecole_id IS NULL OR ecole_id=0");
            $total += $n;
        }
        if (columnExists($pdo,'users','ecole_id')) {
            $total += $pdo->exec("UPDATE users SET ecole_id=$ecoleId WHERE (ecole_id IS NULL OR ecole_id=0) AND role != 'superadmin'");
        }
        return "$total ligne(s) rattachées à l'école $ecoleId.";
    });

    // ── ÉTAPE 5 : Index ecole_id ────────────────────────────────────────────
    step($steps, 'Ajouter les index ecole_id', function() use ($pdo, $tableDefs) {
        $added = 0;
        foreach (array_keys($tableDefs) as $t) {
            if (!tableExists($pdo,$t) || !columnExists($pdo,$t,'ecole_id')) continue;
            if (indexExists($pdo,$t,'idx_ecole_id')) continue;
            $pdo->exec("ALTER TABLE `$t` ADD INDEX idx_ecole_id (ecole_id)");
            $added++;
        }
        return "$added index ajouté(s).";
    });

    // ── ÉTAPE 6 : Corriger l'index unique de parametres ───────────────────
    step($steps, 'Corriger l\'index unique de parametres (cle → cle+ecole_id)', function() use ($pdo) {
        if (!tableExists($pdo,'parametres')) return 'Table parametres absente.';
        if (!columnExists($pdo,'parametres','ecole_id')) return 'ecole_id absent — à relancer après étape 3.';
        // Drop old unique key on cle alone
        if (indexExists($pdo,'parametres','cle')) {
            $pdo->exec("ALTER TABLE parametres DROP INDEX `cle`");
        }
        // Add composite unique key
        if (!indexExists($pdo,'parametres','uq_cle_ecole')) {
            $pdo->exec("ALTER TABLE parametres ADD UNIQUE KEY uq_cle_ecole (cle, ecole_id)");
        }
        return 'Index unique (cle, ecole_id) appliqué.';
    });

    // ── ÉTAPE 7 : ENUM role → superadmin ──────────────────────────────────
    step($steps, 'Ajouter le rôle superadmin à l\'ENUM users', function() use ($pdo) {
        $row = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if (str_contains($row['Type'], 'superadmin')) return 'Rôle superadmin déjà présent.';
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role
            ENUM('superadmin','admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur','assistante')
            NOT NULL DEFAULT 'enseignant'");
        return 'ENUM mis à jour.';
    });

    // ── ÉTAPE 8 : Compte superadmin ────────────────────────────────────────
    $saEmail = trim($_POST['sa_email'] ?? 'superadmin@edupro.sys');
    $saPass  = $_POST['sa_password'] ?? '';
    step($steps, 'Créer le compte SuperAdmin', function() use ($pdo, $saEmail, $saPass) {
        if (empty($saPass)) return '⚠ Mot de passe non fourni — compte non créé.';
        $exists = $pdo->prepare("SELECT id FROM users WHERE role='superadmin' LIMIT 1");
        $exists->execute();
        if ($exists->fetch()) return 'Un compte superadmin existe déjà.';
        $hash = password_hash($saPass, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (nom,prenom,email,password,role,ecole_id,actif) VALUES (?,?,?,?,?,NULL,1)")
            ->execute(['Système','SuperAdmin',$saEmail,$hash,'superadmin']);
        return "Compte créé : <strong>" . htmlspecialchars($saEmail) . "</strong>.";
    });
}

// Calculer si tout est OK pour afficher le bouton final
$hasError    = $ran && !empty(array_filter($steps, fn($s) => $s['status'] === 'error'));
$allOk       = $ran && !$hasError && count($steps) >= 8;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Migration – Multi-Écoles</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
  .step-ok    { color: #198754; }
  .step-warn  { color: #fd7e14; }
  .step-error { color: #dc3545; }
</style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px">
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0">Migration – Architecture Multi-Écoles</h5>
    </div>
    <div class="card-body">

      <?php if ($fatal): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($fatal) ?></div>
      <?php endif; ?>

      <?php if ($ran): ?>
        <h6 class="mb-3">Résultat de la migration :</h6>
        <table class="table table-sm table-bordered" style="font-size:.875rem">
          <thead class="table-light"><tr><th>#</th><th>Étape</th><th>Résultat</th></tr></thead>
          <tbody>
            <?php foreach ($steps as $i => $s): ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td><?= htmlspecialchars($s['label']) ?></td>
              <td class="step-<?= $s['status'] ?>">
                <?= $s['status']==='ok' ? '✔' : ($s['status']==='warn' ? '⚠' : '✘') ?>
                <?= $s['msg'] ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($allOk): ?>
          <div class="alert alert-success">Migration terminée avec succès !</div>
          <div class="d-flex gap-2 mt-2">
            <a href="../index.php" class="btn btn-success flex-fill">Se connecter</a>
            <a href="../modules/superadmin/index.php" class="btn btn-primary flex-fill">Gérer les écoles</a>
          </div>
        <?php else: ?>
          <div class="alert alert-warning">Des erreurs ont été détectées. Corrigez-les puis relancez la migration.</div>
          <a href="migration_multiecole.php" class="btn btn-warning w-100">Relancer la migration</a>
        <?php endif; ?>

      <?php else: ?>
        <!-- Formulaire initial -->
        <p>Cette migration va :</p>
        <ol style="font-size:.9rem">
          <li>Créer la table <code>ecoles</code></li>
          <li>Créer une école par défaut à partir des paramètres existants</li>
          <li>Ajouter <code>ecole_id</code> à toutes les tables principales</li>
          <li>Rattacher les données existantes à l'école par défaut</li>
          <li>Corriger l'index unique de <code>parametres</code></li>
          <li>Ajouter le rôle <code>superadmin</code></li>
          <li>Créer le compte SuperAdmin système</li>
        </ol>
        <div class="alert alert-info py-2" style="font-size:.85rem">
          La migration est <strong>idempotente</strong> : elle peut être relancée sans risque si une étape a échoué.
        </div>
        <?php if (!$fatal): ?>
        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-bold">Email du SuperAdmin</label>
            <input type="email" name="sa_email" class="form-control" value="superadmin@edupro.sys" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Mot de passe SuperAdmin <span class="text-danger">*</span></label>
            <input type="password" name="sa_password" class="form-control" required placeholder="Minimum 8 caractères">
          </div>
          <div class="alert alert-warning py-2" style="font-size:.85rem">
            <strong>Sauvegardez votre base de données</strong> avant de continuer.
          </div>
          <button name="migrate" value="1" class="btn btn-primary w-100">Lancer la migration</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
