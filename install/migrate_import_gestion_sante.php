<?php
/**
 * Migration : transfert des données depuis gestion_sante_tmp vers epsi_db
 * Exécuter via : http://localhost/EDUPRO/install/migrate_import_gestion_sante.php
 *
 * Prérequis : avoir importé gestion_ecole_sante.sql dans la base gestion_sante_tmp
 *   mysql -u root gestion_sante_tmp < "C:/Users/HP/Downloads/gestion_ecole_sante/gestion_ecole_sante.sql"
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = getDB(); // epsi_db

// Rendre semestre_id nullable dans notes (idempotent)
try { $db->exec("ALTER TABLE notes MODIFY COLUMN semestre_id INT NULL"); } catch (PDOException $e) {}

try {
    $src = new PDO(
        'mysql:host=localhost;dbname=gestion_sante_tmp;charset=utf8mb4',
        'root', '',
        [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Base gestion_sante_tmp introuvable. '
      . 'Importer d\'abord le fichier SQL source.</p>');
}

// ─── MAPPINGS ──────────────────────────────────────────────────────────────────

// filiere source_id => epsi_db id
$filiere_map = [1 => 4, 2 => 1, 3 => 3, 4 => 2];
// VP=4, ASB=1, SF=3, INF=2

// [epsi_filiere_id][annee] => epsi niveau_id
$niveau_map = [
    1 => [1 => 1,  2 => 2,  3 => 3],   // ASB
    2 => [1 => 4,  2 => 5,  3 => 6],   // INF
    3 => [1 => 7,  2 => 8,  3 => 9],   // SF
    4 => [1 => 10],                      // VP
];

// source session_id => ['session' => 1|2, 'semestre_id' => 1|2]
$session_map = [
    1 => ['session' => 1, 'semestre_id' => 1], // 1ere_session annuel
    2 => ['session' => 2, 'semestre_id' => 1], // 2eme_session annuel
    3 => ['session' => 1, 'semestre_id' => 1], // S1_1ere_session
    4 => ['session' => 2, 'semestre_id' => 1], // S1_2eme_session
    5 => ['session' => 1, 'semestre_id' => 2], // S2_1ere_session
    6 => ['session' => 2, 'semestre_id' => 2], // S2_2eme_session
];

$annee_id = 1; // 2024-2025

// ─── LOG ──────────────────────────────────────────────────────────────────────
$stats  = ['ue' => 0, 'ue_skip' => 0, 'matieres' => 0, 'matieres_skip' => 0,
           'etudiants' => 0, 'etudiants_skip' => 0, 'notes' => 0, 'notes_skip' => 0];
$detail = [];

function logLine(string $type, string $msg, array &$detail): void {
    $detail[] = ['type' => $type, 'msg' => $msg];
}

// ─── ÉTAPE 1 : UNITÉS D'ENSEIGNEMENT ──────────────────────────────────────────
$ue_id_map = []; // source_ue_id => epsi_ue_id
$seen_codes_ue = [];

foreach ($src->query("SELECT * FROM ue ORDER BY id") as $ue) {
    $epsi_fil = isset($ue['filiere_id']) ? ($filiere_map[$ue['filiere_id']] ?? null) : null;
    $code     = trim($ue['code_ue']);

    // Déduplication par code_ue dans la source elle-même
    if (isset($seen_codes_ue[$code])) {
        $ue_id_map[$ue['id']] = $seen_codes_ue[$code];
        logLine('skip', "UE source id={$ue['id']} code=$code — doublon source, redirigé vers {$seen_codes_ue[$code]}", $detail);
        $stats['ue_skip']++;
        continue;
    }

    // Vérifier si déjà en base
    $chk = $db->prepare("SELECT id FROM ue WHERE code_ue = ?");
    $chk->execute([$code]);
    $existing = $chk->fetchColumn();

    if ($existing) {
        $ue_id_map[$ue['id']] = (int)$existing;
        $seen_codes_ue[$code] = (int)$existing;
        logLine('skip', "UE $code déjà présente (id=$existing)", $detail);
        $stats['ue_skip']++;
        continue;
    }

    $stmt = $db->prepare(
        "INSERT INTO ue (nom, code_ue, semestre_num, coefficient, credit, filiere_id) VALUES (?,?,?,?,?,?)"
    );
    $stmt->execute([
        $ue['nom'],
        $code,
        (int)($ue['semestre'] ?? 1),
        (float)($ue['coefficient'] ?? 1),
        (int)($ue['credit'] ?? 3),
        $epsi_fil,
    ]);
    $new_id = (int)$db->lastInsertId();
    $ue_id_map[$ue['id']] = $new_id;
    $seen_codes_ue[$code] = $new_id;
    logLine('ok', "UE insérée : $code — {$ue['nom']} (→ id=$new_id)", $detail);
    $stats['ue']++;
}

// ─── ÉTAPE 2 : MATIÈRES ───────────────────────────────────────────────────────
$matiere_id_map = []; // source_matiere_id => epsi_matiere_id
$seen_codes_mat = [];

// Charger les codes déjà présents en epsi_db
foreach ($db->query("SELECT code FROM matieres") as $row) {
    $seen_codes_mat[strtoupper($row['code'])] = true;
}

foreach ($src->query("SELECT * FROM matieres ORDER BY id") as $m) {
    $src_code = trim($m['code_matiere']);
    $src_fil  = isset($m['filiere_id']) ? (int)$m['filiere_id'] : null;
    $src_niv  = isset($m['niveau_id'])  ? (int)$m['niveau_id']  : null;
    $annee    = isset($m['annee'])       ? (int)$m['annee']      : null;

    $epsi_fil = $src_fil ? ($filiere_map[$src_fil] ?? null) : null;

    // Pour les LSIO sans filiere_id dans la source, on garde NULL
    // niveau_id : on le déduit depuis epsi_filiere + annee
    $epsi_niv = null;
    if ($epsi_fil && $annee) {
        $epsi_niv = $niveau_map[$epsi_fil][$annee] ?? null;
    }

    $epsi_ue = (isset($m['ue_id']) && $m['ue_id']) ? ($ue_id_map[(int)$m['ue_id']] ?? null) : null;

    // Générer un code unique :
    // - LSIO : code SIO... suffisamment précis, garder tel quel
    // - ASB/VP : codes numériques courts → préfixer avec filiere+annee
    if ($epsi_fil && in_array($epsi_fil, [1, 4])) {
        // ASB ou VP : codes numériques
        $fcode = ($epsi_fil === 1) ? 'ASB' : 'VP';
        $prefix = $fcode . ($annee ? "-A{$annee}-" : '-');
        $base_code = $prefix . $src_code;
    } else {
        $base_code = $src_code;
    }

    // S'assurer de l'unicité
    $final_code = strtoupper($base_code);
    if (isset($seen_codes_mat[$final_code])) {
        // Ajouter suffixe source id
        $final_code = strtoupper($base_code) . '_' . $m['id'];
        if (isset($seen_codes_mat[$final_code])) {
            logLine('error', "Matière source id={$m['id']} code=$final_code — doublon impossible à résoudre, ignorée", $detail);
            $stats['matieres_skip']++;
            continue;
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO matieres (code, nom, filiere_id, niveau_id, semestre_id, ue_id, seuil_reussite, coefficient, volume_horaire)
         VALUES (?,?,?,?,NULL,?,?,?,?)"
    );
    $stmt->execute([
        $final_code,
        $m['nom'],
        $epsi_fil,
        $epsi_niv,
        $epsi_ue,
        (int)($m['seuil_reussite'] ?? 12),
        (float)($m['coefficient'] ?? 1),
        $m['volume_horaire'] ?: 0,
    ]);
    $new_id = (int)$db->lastInsertId();
    $matiere_id_map[(int)$m['id']] = $new_id;
    $seen_codes_mat[$final_code] = true;
    logLine('ok', "Matière insérée : $final_code — {$m['nom']} (→ id=$new_id)", $detail);
    $stats['matieres']++;
}

// ─── ÉTAPE 3 : ÉTUDIANTS ──────────────────────────────────────────────────────
$etudiant_id_map = []; // source_etudiant_id => epsi_etudiant_id

// Matricules déjà présents
$existing_mat = [];
foreach ($db->query("SELECT id, matricule FROM etudiants") as $row) {
    $existing_mat[$row['matricule']] = (int)$row['id'];
}

foreach ($src->query("SELECT * FROM etudiants ORDER BY id") as $e) {
    $mat = trim($e['matricule']);

    if (isset($existing_mat[$mat])) {
        $etudiant_id_map[(int)$e['id']] = $existing_mat[$mat];
        logLine('skip', "Étudiant $mat déjà présent (id={$existing_mat[$mat]})", $detail);
        $stats['etudiants_skip']++;
        continue;
    }

    $src_fil  = isset($e['filiere_id']) ? (int)$e['filiere_id'] : null;
    $epsi_fil = $src_fil ? ($filiere_map[$src_fil] ?? null) : null;
    $annee    = isset($e['annee_etude']) ? (int)$e['annee_etude'] : 1;
    $epsi_niv = ($epsi_fil && $annee) ? ($niveau_map[$epsi_fil][$annee] ?? null) : null;

    $stmt = $db->prepare(
        "INSERT INTO etudiants (matricule, nom, prenom, filiere_id, niveau_id, sexe, date_naissance, lieu_naissance, statut)
         VALUES (?,?,?,?,?,'M',?,?,'actif')"
    );
    $stmt->execute([
        $mat,
        strtoupper(trim($e['nom'])),
        ucfirst(strtolower(trim($e['prenom']))),
        $epsi_fil,
        $epsi_niv,
        $e['date_naissance'] ?: null,
        $e['lieu_naissance'] ?: null,
    ]);
    $new_id = (int)$db->lastInsertId();
    $etudiant_id_map[(int)$e['id']] = $new_id;
    $existing_mat[$mat] = $new_id;
    logLine('ok', "Étudiant inséré : $mat {$e['prenom']} {$e['nom']} (→ id=$new_id)", $detail);
    $stats['etudiants']++;
}

// ─── ÉTAPE 4 : NOTES ──────────────────────────────────────────────────────────
foreach ($src->query("SELECT * FROM notes ORDER BY id") as $n) {
    $src_etu = (int)$n['etudiant_id'];
    $src_mat = (int)$n['matiere_id'];
    $src_ses = (int)($n['session_id'] ?? 1);

    $epsi_etu = $etudiant_id_map[$src_etu] ?? null;
    $epsi_mat = $matiere_id_map[$src_mat] ?? null;
    $map_ses  = $session_map[$src_ses] ?? ['session' => 1, 'semestre_id' => 1];
    $epsi_ses = $map_ses['session'];
    $epsi_sem = $map_ses['semestre_id'];

    if (!$epsi_etu || !$epsi_mat) {
        logLine('skip', "Note source id={$n['id']} — étudiant ou matière non trouvé(e) après mapping", $detail);
        $stats['notes_skip']++;
        continue;
    }

    // Vérifier doublon note (même étudiant + matière + année + session)
    $chk = $db->prepare(
        "SELECT id FROM notes WHERE etudiant_id=? AND matiere_id=? AND annee_id=? AND session=?"
    );
    $chk->execute([$epsi_etu, $epsi_mat, $annee_id, $epsi_ses]);
    if ($chk->fetchColumn()) {
        logLine('skip', "Note source id={$n['id']} — doublon (etu=$epsi_etu, mat=$epsi_mat)", $detail);
        $stats['notes_skip']++;
        continue;
    }

    $stmt = $db->prepare(
        "INSERT INTO notes (etudiant_id, matiere_id, annee_id, semestre_id, session, note_finale, created_at)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $epsi_etu,
        $epsi_mat,
        $annee_id,
        $epsi_sem,
        $epsi_ses,
        (float)$n['valeur'],
        $n['date_saisie'],
    ]);
    logLine('ok', "Note insérée : etu=$epsi_etu mat=$epsi_mat session=$epsi_ses valeur={$n['valeur']}", $detail);
    $stats['notes']++;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Migration – Résultat</title>
  <style>
    body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;padding:2rem;color:#222}
    h1{color:#0f2d5c;margin-bottom:.5rem}
    .stats{display:flex;flex-wrap:wrap;gap:1rem;margin:1.5rem 0}
    .stat{background:#fff;border-radius:10px;padding:1rem 1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.08);min-width:140px}
    .stat .num{font-size:2rem;font-weight:700;color:#0f2d5c}
    .stat .lbl{font-size:.8rem;color:#888}
    .stat.warn .num{color:#fd7e14}
    .log{background:#fff;border-radius:10px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.08);max-height:500px;overflow-y:auto}
    .log p{margin:.2rem 0;font-size:.82rem;padding:2px 6px;border-radius:4px}
    .log p.ok{color:#155724;background:#d4edda}
    .log p.skip{color:#856404;background:#fff3cd}
    .log p.error{color:#721c24;background:#f8d7da}
    .btn{display:inline-block;margin-top:1.5rem;padding:.6rem 1.4rem;background:#0f2d5c;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
  </style>
</head>
<body>
  <h1>Migration gestion_ecole_sante → epsi_db</h1>
  <p>Date : <?= date('d/m/Y à H:i') ?></p>

  <div class="stats">
    <div class="stat"><div class="num"><?= $stats['ue'] ?></div><div class="lbl">UE insérées</div></div>
    <div class="stat warn"><div class="num"><?= $stats['ue_skip'] ?></div><div class="lbl">UE ignorées</div></div>
    <div class="stat"><div class="num"><?= $stats['matieres'] ?></div><div class="lbl">Matières insérées</div></div>
    <div class="stat warn"><div class="num"><?= $stats['matieres_skip'] ?></div><div class="lbl">Matières ignorées</div></div>
    <div class="stat"><div class="num"><?= $stats['etudiants'] ?></div><div class="lbl">Étudiants insérés</div></div>
    <div class="stat warn"><div class="num"><?= $stats['etudiants_skip'] ?></div><div class="lbl">Étudiants ignorés</div></div>
    <div class="stat"><div class="num"><?= $stats['notes'] ?></div><div class="lbl">Notes insérées</div></div>
    <div class="stat warn"><div class="num"><?= $stats['notes_skip'] ?></div><div class="lbl">Notes ignorées</div></div>
  </div>

  <h2>Détail des opérations</h2>
  <div class="log">
    <?php foreach ($detail as $d): ?>
      <p class="<?= $d['type'] ?>"><?= htmlspecialchars($d['msg']) ?></p>
    <?php endforeach; ?>
  </div>

  <a href="/EDUPRO/dashboard.php" class="btn">← Retour au tableau de bord</a>
</body>
</html>
