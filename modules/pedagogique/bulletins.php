<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$db   = getDB();
$user = getCurrentUser();

$etudiantId  = (int)($_GET['etudiant_id']  ?? 0);
$anneeId     = (int)($_GET['annee_id']     ?? getActiveAnnee()['id'] ?? 0);
$semestreId  = (int)($_GET['semestre_id']  ?? 0);
$semestreNum = (int)($_GET['semestre_num'] ?? 0);  // S1-S6 for niveau supérieur
$_sess       = (int)($_GET['session'] ?? 1);
$sessionNum  = in_array($_sess, [1, 2]) ? $_sess : 1;
$print       = isset($_GET['print']);

$SANS_SEMESTRE_CODES = ['ASB', 'VP'];

// Runtime migrations
try { $db->exec("ALTER TABLE notes ADD COLUMN session TINYINT NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN seuil_reussite INT NOT NULL DEFAULT 12"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE filieres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("UPDATE filieres SET niveau_superieur=1 WHERE tronc_commun=1 OR tronc_commun_id IS NOT NULL"); } catch (PDOException $e) {}

// Students can only see their own bulletins
if ($user['role'] === 'etudiant') {
    $etudiantId = (int)$user['reference_id'];
}

// Load etudiant
$etudiant = null;
if ($etudiantId) {
    $stmt = $db->prepare("
        SELECT e.*, f.nom as filiere_nom, f.code as filiere_code,
               f.niveau_superieur, f.tronc_commun, f.tronc_commun_id,
               n.nom as niveau_nom
        FROM etudiants e
        LEFT JOIN filieres f ON f.id=e.filiere_id
        LEFT JOIN niveaux n ON n.id=e.niveau_id
        WHERE e.id=?
    ");
    $stmt->execute([$etudiantId]);
    $etudiant = $stmt->fetch();
}

$isSansSemestre = $etudiant && in_array($etudiant['filiere_code'] ?? '', $SANS_SEMESTRE_CODES);
$isNivSup       = !empty($etudiant['niveau_superieur']);

// Semestres available for niveau supérieur based on filière type
$nivSupSemestres = [];
if ($isNivSup) {
    if (!empty($etudiant['tronc_commun'])) {
        $nivSupSemestres = [1 => 'Semestre 1', 2 => 'Semestre 2'];
    } else {
        $nivSupSemestres = [3 => 'Semestre 3', 4 => 'Semestre 4', 5 => 'Semestre 5', 6 => 'Semestre 6'];
    }
}

$notes    = [];
$ueGroups = [];  // [ue_id => ['code','nom','moyenne','validee','has_elim','notes'=>[...]]]
$noUeNotes = []; // notes for niveau supérieur with no UE assigned
$classement = null;

$hasData = $etudiant && $anneeId && (
    $isSansSemestre ||
    ($isNivSup && $semestreNum) ||
    (!$isSansSemestre && !$isNivSup && $semestreId)
);

if ($hasData) {
    if ($isSansSemestre) {
        $notesStmt = $db->prepare("
            SELECT n.*, m.nom as matiere_nom, m.code as matiere_code, m.coefficient,
                   COALESCE(m.seuil_reussite, 10) as seuil_reussite,
                   m.ue_id, NULL as ue_nom, NULL as ue_code
            FROM notes n
            JOIN matieres m ON m.id = n.matiere_id
            WHERE n.etudiant_id=? AND n.annee_id=? AND n.session=?
            ORDER BY m.nom
        ");
        $notesStmt->execute([$etudiantId, $anneeId, $sessionNum]);
    } elseif ($isNivSup) {
        $notesStmt = $db->prepare("
            SELECT n.*, m.nom as matiere_nom, m.code as matiere_code, m.coefficient,
                   COALESCE(m.seuil_reussite, 10) as seuil_reussite,
                   m.ue_id, u.nom as ue_nom, u.code_ue as ue_code
            FROM notes n
            JOIN matieres m ON m.id = n.matiere_id
            LEFT JOIN ue u ON u.id = m.ue_id
            WHERE n.etudiant_id=? AND n.annee_id=? AND n.session=?
              AND m.semestre_num = ?
            ORDER BY u.code_ue, m.nom
        ");
        $notesStmt->execute([$etudiantId, $anneeId, $sessionNum, $semestreNum]);
    } else {
        $notesStmt = $db->prepare("
            SELECT n.*, m.nom as matiere_nom, m.code as matiere_code, m.coefficient,
                   COALESCE(m.seuil_reussite, 10) as seuil_reussite,
                   m.ue_id, NULL as ue_nom, NULL as ue_code
            FROM notes n
            JOIN matieres m ON m.id = n.matiere_id
            WHERE n.etudiant_id=? AND n.annee_id=?
              AND n.semestre_id=?
              AND n.session=?
            ORDER BY m.nom
        ");
        $notesStmt->execute([$etudiantId, $anneeId, $semestreId, $sessionNum]);
    }
    $notes = $notesStmt->fetchAll();

    // ── UE compensation (niveau supérieur only) ───────────────────────────────
    if ($isNivSup && !empty($notes)) {
        $tmpUeGroups = [];
        foreach ($notes as &$n) {
            $ueId = (int)($n['ue_id'] ?? 0);
            if ($ueId) {
                if (!isset($tmpUeGroups[$ueId])) {
                    $tmpUeGroups[$ueId] = ['code' => $n['ue_code'] ?? '', 'nom' => $n['ue_nom'] ?? '', 'notes' => []];
                }
                $tmpUeGroups[$ueId]['notes'][] = &$n;
            } else {
                $noUeNotes[] = &$n;
            }
        }
        unset($n);

        foreach ($tmpUeGroups as $ueId => &$ug) {
            $pts = 0; $coef = 0; $hasElim = false;
            foreach ($ug['notes'] as $n) {
                $nf = $n['note_finale'] !== null ? (float)$n['note_finale'] : null;
                if ($nf !== null) {
                    $pts  += $nf * (float)$n['coefficient'];
                    $coef += (float)$n['coefficient'];
                    if ($nf <= 5) $hasElim = true;
                }
            }
            $ueMoy = $coef > 0 ? round($pts / $coef, 2) : null;
            $ueVal = !$hasElim && $ueMoy !== null && $ueMoy >= 10;
            $ug['moyenne']  = $ueMoy;
            $ug['validee']  = $ueVal;
            $ug['has_elim'] = $hasElim;

            foreach ($ug['notes'] as &$n) {
                $nf = $n['note_finale'] !== null ? (float)$n['note_finale'] : null;
                if ($nf === null)     $n['niv_sup_statut'] = 'absent';
                elseif ($nf >= 10)   $n['niv_sup_statut'] = 'valide';
                elseif ($nf <= 5)    $n['niv_sup_statut'] = 'eliminatoire';
                elseif ($ueVal)      $n['niv_sup_statut'] = 'compense';
                else                 $n['niv_sup_statut'] = 'a_repasser';
            }
            unset($n);
        }
        unset($ug);
        $ueGroups = $tmpUeGroups;

        foreach ($noUeNotes as &$n) {
            $nf = $n['note_finale'] !== null ? (float)$n['note_finale'] : null;
            if ($nf === null)   $n['niv_sup_statut'] = 'absent';
            elseif ($nf >= 10) $n['niv_sup_statut'] = 'valide';
            elseif ($nf <= 5)  $n['niv_sup_statut'] = 'eliminatoire';
            else               $n['niv_sup_statut'] = 'a_repasser';
        }
        unset($n);
    }

    // Calculate ranking
    if (!empty($notes)) {
        $myMoyenne = calculateMoyenne($notes);

        // Get all students in same filiere/niveau for ranking
        if ($isSansSemestre) {
            $allStmt = $db->prepare("
                SELECT e2.id,
                       GROUP_CONCAT(n2.note_finale * m2.coefficient ORDER BY m2.id SEPARATOR ',') as points,
                       GROUP_CONCAT(m2.coefficient ORDER BY m2.id SEPARATOR ',') as coefs
                FROM etudiants e2
                JOIN notes n2 ON n2.etudiant_id = e2.id
                JOIN matieres m2 ON m2.id = n2.matiere_id
                WHERE e2.filiere_id=? AND e2.niveau_id=? AND n2.annee_id=? AND n2.session=?
                GROUP BY e2.id
            ");
            $allStmt->execute([$etudiant['filiere_id'], $etudiant['niveau_id'], $anneeId, $sessionNum]);
        } elseif ($isNivSup) {
            $allStmt = $db->prepare("
                SELECT e2.id,
                       GROUP_CONCAT(n2.note_finale * m2.coefficient ORDER BY m2.id SEPARATOR ',') as points,
                       GROUP_CONCAT(m2.coefficient ORDER BY m2.id SEPARATOR ',') as coefs
                FROM etudiants e2
                JOIN notes n2 ON n2.etudiant_id = e2.id
                JOIN matieres m2 ON m2.id = n2.matiere_id
                WHERE e2.filiere_id=? AND e2.niveau_id=? AND n2.annee_id=? AND n2.session=?
                  AND m2.semestre_num=?
                GROUP BY e2.id
            ");
            $allStmt->execute([$etudiant['filiere_id'], $etudiant['niveau_id'], $anneeId, $sessionNum, $semestreNum]);
        } else {
            $allStmt = $db->prepare("
                SELECT e2.id,
                       GROUP_CONCAT(n2.note_finale * m2.coefficient ORDER BY m2.id SEPARATOR ',') as points,
                       GROUP_CONCAT(m2.coefficient ORDER BY m2.id SEPARATOR ',') as coefs
                FROM etudiants e2
                JOIN notes n2 ON n2.etudiant_id = e2.id
                JOIN matieres m2 ON m2.id = n2.matiere_id
                WHERE e2.filiere_id=? AND e2.niveau_id=? AND n2.annee_id=?
                  AND (n2.semestre_id=? OR n2.semestre_id IS NULL) AND n2.session=?
                GROUP BY e2.id
            ");
            $allStmt->execute([$etudiant['filiere_id'], $etudiant['niveau_id'], $anneeId, $semestreId, $sessionNum]);
        }
        $allStudents = $allStmt->fetchAll();

        $moyennes = [];
        foreach ($allStudents as $s) {
            $pts  = array_map('floatval', explode(',', $s['points']));
            $cfs  = array_map('floatval', explode(',', $s['coefs']));
            $tc   = array_sum($cfs);
            $moy  = $tc > 0 ? array_sum(array_map(fn($p,$c) => $p*($c/$tc), $pts, $cfs)) : 0;
            $moyennes[$s['id']] = round($moy, 2);
        }
        arsort($moyennes);
        $rank = array_search($etudiantId, array_keys($moyennes));
        $classement = ['rang' => ($rank !== false ? $rank + 1 : '-'), 'total' => count($moyennes), 'moyenne' => $myMoyenne];
    }
}

// Helper: statut badge for niveau supérieur
function nivSupStatutBadge(string $statut): string {
    return match($statut) {
        'valide'       => '<span style="background:#d4edda;color:#155724;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600">Validé</span>',
        'eliminatoire' => '<span style="background:#f8d7da;color:#721c24;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600">Éliminatoire</span>',
        'compense'     => '<span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600">Compensé</span>',
        'a_repasser'   => '<span style="background:#e2e3e5;color:#383d41;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600">À repasser</span>',
        default        => '<span style="color:#999">–</span>',
    };
}

// Excel export
if (isset($_GET['export_excel']) && $etudiant && !empty($notes)) {
    $xlAnneeLabel    = '';
    $xlSemestreLabel = '';
    foreach (getAnneesAcademiques() as $a) { if ($a['id'] == $anneeId) { $xlAnneeLabel = $a['libelle']; break; } }
    if ($isNivSup && $semestreNum) {
        $xlSemestreLabel = 'Semestre ' . $semestreNum;
    } else {
        foreach (getSemestres($anneeId ?: null) as $s) { if ($s['id'] == $semestreId) { $xlSemestreLabel = $s['nom']; break; } }
    }
    $xlMoyenne = calculateMoyenne($notes);

    header('Content-Type: application/vnd.ms-excel');
    $fname = 'Bulletin_' . preg_replace('/[^a-zA-Z0-9]/', '_', $etudiant['nom']) . '_' . date('Y-m-d') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%">';

    echo '<tr><td colspan="7" style="text-align:center;font-size:16px;font-weight:bold;background:#0f2d5c;color:white">';
    echo 'ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD<br>BULLETIN DE NOTES<br>';
    echo htmlspecialchars($xlAnneeLabel);
    if ($xlSemestreLabel) echo ' – ' . htmlspecialchars($xlSemestreLabel);
    echo '<br>' . ($sessionNum == 1 ? '1ÈRE SESSION' : '2ÈME SESSION (RATTRAPAGE)');
    echo '</td></tr>';

    echo '<tr style="background:#1a5276;color:white"><td colspan="7" style="font-weight:bold">INFORMATIONS ÉTUDIANT</td></tr>';
    echo '<tr><td><strong>Étudiant :</strong></td><td colspan="2">' . strtoupper($etudiant['nom']??'') . ' ' . ucfirst($etudiant['prenom']??'') . '</td>';
    echo '<td><strong>Matricule :</strong></td><td colspan="3">' . ($etudiant['matricule']??'') . '</td></tr>';
    echo '<tr><td><strong>Filière :</strong></td><td colspan="2">' . htmlspecialchars($etudiant['filiere_nom']??'') . '</td>';
    echo '<td><strong>Niveau :</strong></td><td colspan="3">' . htmlspecialchars($etudiant['niveau_nom']??'') . '</td></tr>';

    if ($isNivSup) {
        echo '<tr style="background:#1a5276;color:white"><th>Code</th><th>Matière / UE</th><th>Coeff</th><th>CC /20</th><th>Examen /20</th><th>Note Finale /20</th><th>Statut</th></tr>';
        foreach ($ueGroups as $ug) {
            $ueValBg = $ug['validee'] ? '#d4edda' : '#f8d7da';
            echo '<tr style="background:#e8eaf6;font-weight:bold"><td colspan="7">UE ' . htmlspecialchars($ug['code']) . ' – ' . htmlspecialchars($ug['nom']) . '</td></tr>';
            foreach ($ug['notes'] as $n) {
                $bg = ($n['note_finale'] !== null && $n['note_finale'] < 10) ? '#fff8e1' : ($n['note_finale'] !== null ? '#f1f8e9' : '#fff');
                echo '<tr style="background:' . $bg . '">';
                echo '<td>' . htmlspecialchars($n['matiere_code']??'') . '</td>';
                echo '<td>' . htmlspecialchars($n['matiere_nom']??'') . '</td>';
                echo '<td style="text-align:center">' . ($n['coefficient']??'') . '</td>';
                echo '<td style="text-align:center">' . ($n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–') . '</td>';
                echo '<td style="text-align:center">' . ($n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–') . '</td>';
                echo '<td style="text-align:center;font-weight:bold">' . ($n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–') . '</td>';
                $statutLabel = match($n['niv_sup_statut'] ?? '') {
                    'valide' => 'Validé', 'eliminatoire' => 'Éliminatoire',
                    'compense' => 'Compensé', 'a_repasser' => 'À repasser', default => '–',
                };
                echo '<td style="text-align:center">' . $statutLabel . '</td>';
                echo '</tr>';
            }
            $ueMoyStr = $ug['moyenne'] !== null ? number_format($ug['moyenne'],2).'/20' : '–';
            $ueRes    = $ug['validee'] ? 'UE VALIDÉE' : 'UE NON VALIDÉE';
            echo '<tr style="background:' . $ueValBg . ';font-weight:bold"><td colspan="5" style="text-align:right">Moy. UE ' . htmlspecialchars($ug['code']) . ' :</td><td style="text-align:center">' . $ueMoyStr . '</td><td style="text-align:center">' . $ueRes . '</td></tr>';
        }
        if (!empty($noUeNotes)) {
            echo '<tr style="background:#f5f5f5;font-weight:bold"><td colspan="7">Matières hors UE</td></tr>';
            foreach ($noUeNotes as $n) {
                $bg = ($n['note_finale'] !== null && $n['note_finale'] < 10) ? '#fff8e1' : '#fff';
                echo '<tr style="background:' . $bg . '">';
                echo '<td>' . htmlspecialchars($n['matiere_code']??'') . '</td><td>' . htmlspecialchars($n['matiere_nom']??'') . '</td>';
                echo '<td style="text-align:center">' . ($n['coefficient']??'') . '</td>';
                echo '<td style="text-align:center">' . ($n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–') . '</td>';
                echo '<td style="text-align:center">' . ($n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–') . '</td>';
                echo '<td style="text-align:center;font-weight:bold">' . ($n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–') . '</td>';
                echo '<td style="text-align:center">–</td></tr>';
            }
        }
        // Résultat niveau supérieur: toutes UEs validées?
        $allUesOk  = !empty($ueGroups) && array_reduce($ueGroups, fn($c,$ug) => $c && $ug['validee'], true);
        $xlNivSupResult = $allUesOk ? 'ADMIS(E)' : 'À REPASSER';
        $res_bg = $allUesOk ? '#d4edda' : '#f8d7da';
        echo '<tr style="font-weight:bold;background:#e3f2fd"><td colspan="5" style="text-align:right">Moyenne générale :</td>';
        echo '<td style="text-align:center">' . ($xlMoyenne !== null ? number_format($xlMoyenne,2).'/20' : '–') . '</td>';
        echo '<td style="text-align:center">' . getMention($xlMoyenne) . '</td></tr>';
        if ($classement) {
            echo '<tr><td colspan="5" style="text-align:right"><strong>Classement :</strong></td><td colspan="2"><strong>' . $classement['rang'] . ' / ' . $classement['total'] . '</strong></td></tr>';
            echo '<tr style="background:' . $res_bg . '"><td colspan="5" style="text-align:right"><strong>Résultat :</strong></td><td colspan="2" style="font-weight:bold">' . $xlNivSupResult . '</td></tr>';
        }
    } else {
        echo '<tr style="background:#1a5276;color:white"><th>Code</th><th>Matière</th><th>Coeff</th><th>CC /20</th><th>Examen /20</th><th>Note Finale /20</th><th>Mention</th></tr>';
        foreach ($notes as $n) {
            $bg = ($n['note_finale'] !== null && $n['note_finale'] < 10) ? '#f8d7da' : ($n['note_finale'] !== null ? '#d4edda' : '#fff');
            echo '<tr style="background:' . $bg . '">';
            echo '<td>' . htmlspecialchars($n['matiere_code']??'') . '</td>';
            echo '<td>' . htmlspecialchars($n['matiere_nom']??'') . '</td>';
            echo '<td style="text-align:center">' . ($n['coefficient']??'') . '</td>';
            echo '<td style="text-align:center">' . ($n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–') . '</td>';
            echo '<td style="text-align:center">' . ($n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–') . '</td>';
            echo '<td style="text-align:center;font-weight:bold">' . ($n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–') . '</td>';
            echo '<td style="text-align:center">' . getMention($n['note_finale'] !== null ? (float)$n['note_finale'] : null) . '</td>';
            echo '</tr>';
        }
        echo '<tr style="font-weight:bold;background:#e3f2fd"><td colspan="5" style="text-align:right">Moyenne générale :</td>';
        echo '<td style="text-align:center">' . ($xlMoyenne !== null ? number_format($xlMoyenne,2).'/20' : '–') . '</td>';
        echo '<td style="text-align:center">' . getMention($xlMoyenne) . '</td></tr>';
        if ($classement) {
            echo '<tr><td colspan="5" style="text-align:right"><strong>Classement :</strong></td><td colspan="2"><strong>' . $classement['rang'] . ' / ' . $classement['total'] . '</strong></td></tr>';
            $res_bg = $xlMoyenne >= 10 ? '#d4edda' : '#f8d7da';
            echo '<tr style="background:' . $res_bg . '"><td colspan="5" style="text-align:right"><strong>Résultat :</strong></td><td colspan="2" style="font-weight:bold">' . ($xlMoyenne >= 10 ? 'ADMIS(E)' : 'AJOURNÉ(E)') . '</td></tr>';
        }
    }
    echo '<tr style="background:#0f2d5c;color:white"><td colspan="7" style="text-align:center">Bulletin édité le ' . date('d/m/Y à H:i') . ' – Cachet et signature du Directeur</td></tr>';
    echo '</table></body></html>';
    exit();
}

$annees    = getAnneesAcademiques();
$semestres = getSemestres($anneeId ?: null);

// Semestre label for display
$semestreLabel = '';
if ($isNivSup && $semestreNum) {
    $semestreLabel = 'Semestre ' . $semestreNum;
} else {
    foreach ($semestres as $s) { if ($s['id'] == $semestreId) { $semestreLabel = $s['nom']; break; } }
}

// All students for the dropdown (non-etudiant roles only)
$allEtudiants = [];
if (!in_array($user['role'], ['etudiant'])) {
    $allEtudiants = $db->query("
        SELECT e.id, e.nom, e.prenom, e.matricule,
               f.code as filiere_code, f.niveau_superieur, f.tronc_commun, f.tronc_commun_id,
               n.nom as niveau_nom
        FROM etudiants e
        LEFT JOIN filieres f ON f.id=e.filiere_id
        LEFT JOIN niveaux n ON n.id=e.niveau_id
        WHERE e.statut='actif'
        ORDER BY e.nom, e.prenom
    ")->fetchAll();
}

// Niveaux groupés par filière_id (pour le formulaire Bulletin Global)
$niveaux_by_filiere = [];
$nv_rows = $db->query("
    SELECT n.id, n.nom, n.filiere_id
    FROM niveaux n
    JOIN filieres f ON f.id = n.filiere_id
    WHERE f.actif = 1
    ORDER BY n.filiere_id, n.ordre
")->fetchAll();
foreach ($nv_rows as $nv) {
    $niveaux_by_filiere[(int)$nv['filiere_id']][] = ['id' => (int)$nv['id'], 'nom' => $nv['nom']];
}

$pageTitle  = 'Bulletins de notes';
$breadcrumb = ['Pédagogie' => null, 'Bulletins' => null];

if (!$print) {
    include APP_ROOT . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulletin – <?= $etudiant ? h($etudiant['prenom'].' '.$etudiant['nom']) : 'Notes' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#eef1f7;padding:20px;font-size:13px;color:#212529}
    .bul-wrap{max-width:900px;margin:0 auto}
    .doc-header{background:linear-gradient(135deg,#0f2d5c 0%,#1a5276 100%);border-radius:12px;padding:22px 28px;color:#fff;display:flex;align-items:center;gap:20px;margin-bottom:18px}
    .doc-header img{width:80px;height:80px;object-fit:cover;border-radius:50%;flex-shrink:0}
    .doc-header-info h1{font-size:17px;font-weight:700;margin:0 0 3px}
    .doc-header-info h2{font-size:12px;font-weight:400;opacity:.85;margin:0 0 5px}
    .doc-header-meta{font-size:12px;opacity:.9;margin-bottom:6px}
    .badge-sess{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600}
    .s1{background:#28a745;color:#fff}.s2{background:#fd7e14;color:#fff}
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
    .info-card{background:#fff;border-radius:8px;padding:11px 15px;box-shadow:0 1px 6px rgba(0,0,0,.07)}
    .info-card .lbl{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px}
    .info-card .val{font-size:13px;font-weight:600;color:#1a1a2e}
    .notes-card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden;margin-bottom:16px}
    .tbl{width:100%;border-collapse:collapse;font-size:12.5px}
    .tbl thead th{background:#0f2d5c;color:#fff;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;padding:9px 10px;text-align:center}
    .tbl thead th:first-child,.tbl thead th:nth-child(2){text-align:left;padding-left:14px}
    .tbl td{padding:9px 10px;border-bottom:1px solid #f0f0f0;text-align:center;vertical-align:middle}
    .tbl td:first-child,.tbl td:nth-child(2){text-align:left;padding-left:14px}
    .tbl tbody tr:last-child td{border-bottom:none}
    .tbl tfoot td{background:#f8f9fa;font-weight:600;border-top:2px solid #e9ecef}
    .cv{color:#1e7e34;font-weight:700}.cnv{color:#c82333;font-weight:700}
    .row-fail{background:#fff8f8}
    .stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
    .stat-card{background:#fff;border-radius:8px;padding:14px 18px;box-shadow:0 1px 6px rgba(0,0,0,.07)}
    .stat-card .s-inner{display:flex;justify-content:space-around;text-align:center}
    .stat-card .sval{font-size:26px;font-weight:700;line-height:1.1}
    .stat-card .slbl{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
    .result-card{background:#fff;border-radius:8px;padding:14px 18px;box-shadow:0 1px 6px rgba(0,0,0,.07);display:flex;align-items:center;justify-content:space-between}
    .result-badge{display:inline-block;padding:7px 20px;border-radius:20px;font-size:15px;font-weight:700}
    .badge-ok{background:#d4edda;color:#155724}.badge-ko{background:#f8d7da;color:#721c24}
    .sig-block{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:20px;padding-top:14px;border-top:1px solid #dee2e6}
    .sig-item{text-align:center;font-size:11px;color:#888}
    .sig-line{border-bottom:1px solid #999;margin:38px 10px 7px}
    .doc-footer{text-align:center;font-size:11px;color:#aaa;margin-top:14px;padding-top:10px;border-top:1px solid #e0e0e0}
    .btn-actions{position:fixed;bottom:20px;right:16px;display:flex;gap:10px;z-index:999}
    .btn-actions button,.btn-actions a{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:7px;box-shadow:0 3px 12px rgba(0,0,0,.2);transition:opacity .15s}
    .btn-actions button:hover,.btn-actions a:hover{opacity:.87}
    .btn-print{background:#1a73e8;color:#fff}
    .btn-excel{background:linear-gradient(135deg,#1e7e34,#28a745);color:#fff}
    .btn-back{background:#6c757d;color:#fff}
    @media print{
      body{background:#fff;padding:0}.btn-actions{display:none!important}
      .doc-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .tbl thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .row-fail{-webkit-print-color-adjust:exact;print-color-adjust:exact}
      img{max-width:100%!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
  </style>
</head>
<body>
<?php
}
?>

<?php if (!$print): ?>
<div class="page-header">
  <h2><i class="fas fa-file-alt me-2 text-primary"></i>Bulletins de Notes</h2>
</div>

<!-- Search Form -->
<div class="card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <?php if (!in_array($user['role'], ['etudiant'])): ?>
      <div class="col-md-4">
        <label class="form-label fw-600">Étudiant</label>
        <div class="custom-student-select position-relative" id="studentSelectWrap">
          <!-- Display input -->
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-graduate text-primary" style="font-size:.85rem"></i></span>
            <input type="text" id="studentSearchInput" class="form-control border-start-0 border-end-0"
                   placeholder="Cliquer pour choisir un étudiant..." autocomplete="off" readonly
                   style="cursor:pointer;background:#fff"
                   value="<?= $etudiant ? h($etudiant['prenom'].' '.$etudiant['nom'].' ('.$etudiant['matricule'].')') : '' ?>">
            <button type="button" id="clearStudentBtn" class="btn btn-outline-secondary border-start-0" title="Effacer" <?= !$etudiantId ? 'style="display:none"' : '' ?>>
              <i class="fas fa-times"></i>
            </button>
          </div>
          <input type="hidden" name="etudiant_id" id="etudiantHidden" value="<?= $etudiantId ?>">

          <!-- Dropdown panel -->
          <div id="studentDropdownPanel" class="card shadow position-absolute w-100" style="display:none;z-index:1050;top:calc(100% + 4px);min-width:320px">
            <div class="p-2 border-bottom">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="studentFilterInput" class="form-control" placeholder="Filtrer par nom, prénom ou matricule...">
              </div>
            </div>
            <div id="studentOptionsList" style="max-height:240px;overflow-y:auto">
              <?php if (empty($allEtudiants)): ?>
                <div class="text-center text-muted py-4"><i class="fas fa-inbox d-block mb-1"></i>Aucun étudiant actif</div>
              <?php else: ?>
              <?php foreach ($allEtudiants as $e): ?>
              <div class="student-opt d-flex align-items-center gap-2 px-3 py-2"
                   data-id="<?= $e['id'] ?>"
                   data-label="<?= h($e['prenom'].' '.$e['nom'].' ('.$e['matricule'].')') ?>"
                   data-search="<?= strtolower(h($e['nom'].' '.$e['prenom'].' '.$e['matricule'])) ?>"
                   data-sans-semestre="<?= in_array($e['filiere_code'] ?? '', $SANS_SEMESTRE_CODES) ? '1' : '0' ?>"
                   data-niv-sup="<?= !empty($e['niveau_superieur']) ? '1' : '0' ?>"
                   data-tronc="<?= !empty($e['tronc_commun']) ? '1' : '0' ?>"
                   data-tc-id="<?= (int)($e['tronc_commun_id'] ?? 0) ?>"
                   style="cursor:pointer;transition:background .15s">
                <div class="avatar-circle" style="background:#1a73e8;width:32px;height:32px;font-size:.7rem;flex-shrink:0">
                  <?= strtoupper(substr($e['prenom'],0,1).substr($e['nom'],0,1)) ?>
                </div>
                <div style="min-width:0">
                  <div class="fw-600 fs-sm text-truncate"><?= h($e['prenom'].' '.$e['nom']) ?></div>
                  <div class="text-muted" style="font-size:.73rem">
                    <code><?= h($e['matricule']) ?></code>
                    <?php if ($e['filiere_code']): ?>&nbsp;·&nbsp;<?= h($e['filiere_code']) ?><?php endif; ?>
                    <?php if ($e['niveau_nom']): ?>&nbsp;<?= h($e['niveau_nom']) ?><?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              <div id="noStudentResult" class="text-center text-muted py-3 d-none">
                <i class="fas fa-search-minus d-block mb-1"></i>Aucun résultat
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php else: ?>
      <input type="hidden" name="etudiant_id" value="<?= $etudiantId ?>">
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Année académique</label>
        <select name="annee_id" id="annee_id" class="form-select" onchange="this.form.submit()">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Semestre standard (non-niveau supérieur, non sans-semestre) -->
      <div class="col-md-2" id="semestre_div"<?= ($isSansSemestre || $isNivSup) ? ' style="display:none"' : '' ?>>
        <label class="form-label">Semestre</label>
        <select name="semestre_id" id="semestre_id" class="form-select"<?= ($isSansSemestre || $isNivSup) ? ' disabled' : '' ?>>
          <option value="">-- Sélectionner --</option>
          <?php foreach ($semestres as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $semestreId == $s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Semestre niveau supérieur (S1-S6) -->
      <div class="col-md-2" id="semestre_num_div"<?= !$isNivSup ? ' style="display:none"' : '' ?>>
        <label class="form-label">Semestre</label>
        <select name="semestre_num" id="semestre_num" class="form-select"<?= !$isNivSup ? ' disabled' : '' ?>>
          <option value="">-- Semestre --</option>
          <option value="1" data-for="tc" <?= ($semestreNum == 1) ? 'selected' : '' ?>>Semestre 1</option>
          <option value="2" data-for="tc" <?= ($semestreNum == 2) ? 'selected' : '' ?>>Semestre 2</option>
          <option value="3" data-for="inf_sf" <?= ($semestreNum == 3) ? 'selected' : '' ?>>Semestre 3</option>
          <option value="4" data-for="inf_sf" <?= ($semestreNum == 4) ? 'selected' : '' ?>>Semestre 4</option>
          <option value="5" data-for="inf_sf" <?= ($semestreNum == 5) ? 'selected' : '' ?>>Semestre 5</option>
          <option value="6" data-for="inf_sf" <?= ($semestreNum == 6) ? 'selected' : '' ?>>Semestre 6</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Session</label>
        <select name="session" class="form-select">
          <option value="1" <?= $sessionNum == 1 ? 'selected' : '' ?>>1ère session</option>
          <option value="2" <?= $sessionNum == 2 ? 'selected' : '' ?>>2ème session</option>
        </select>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($etudiant && !empty($notes)): ?>
  <?php
    $anneeLabel = '';
    foreach ($annees as $a) if ($a['id'] == $anneeId) $anneeLabel = $a['libelle'];
    // $semestreLabel already set above (handles both standard and niveau supérieur)
    $moyenne  = calculateMoyenne($notes);
    $allUesOk = $isNivSup
        ? (!empty($ueGroups) && array_reduce($ueGroups, fn($c,$ug) => $c && $ug['validee'], true))
        : ($moyenne !== null && $moyenne >= 10);
  ?>

<?php if (!$print): ?>
<div class="d-flex justify-content-end gap-2 mb-3 no-print">
  <a href="?etudiant_id=<?= $etudiantId ?>&annee_id=<?= $anneeId ?>&semestre_id=<?= $semestreId ?>&semestre_num=<?= $semestreNum ?>&session=<?= $sessionNum ?>&print=1"
     target="_blank" class="btn btn-outline-primary">
    <i class="fas fa-print me-2"></i>Imprimer / PDF
  </a>
  <a href="?etudiant_id=<?= $etudiantId ?>&annee_id=<?= $anneeId ?>&semestre_id=<?= $semestreId ?>&semestre_num=<?= $semestreNum ?>&session=<?= $sessionNum ?>&export_excel=1"
     target="_blank" class="btn btn-outline-success">
    <i class="fas fa-file-excel me-2"></i>Exporter Excel
  </a>
</div>
<?php endif; ?>

<?php if ($print): ?>
<!-- ═══ PRINT : modern standalone view ═══ -->
<div class="bul-wrap">

  <?php
  $bulSidebarColor = getParam('theme_couleur_sidebar',  '#0f2d5c');
  $bulPrimaryColor = getParam('theme_couleur_primaire', '#1a73e8');
  $bulLogo         = getLogoUrl();
  $bulNom          = getParam('etablissement_nom', 'École Privée de Santé Ibn Rochd');
  $bulSlogan       = getParam('etablissement_slogan');
  ?>
  <div class="doc-header" style="background:linear-gradient(135deg,<?= h($bulSidebarColor) ?> 0%,<?= h($bulPrimaryColor) ?> 100%)">
    <?php if ($bulLogo): ?>
      <img src="<?= h($bulLogo) ?>" alt="Logo"
           style="width:80px;height:80px;object-fit:contain;border-radius:50%;background:#fff;padding:6px;flex-shrink:0">
    <?php endif; ?>
    <div class="doc-header-info">
      <h1><?= h(strtoupper($bulNom)) ?></h1>
      <?php if ($bulSlogan): ?><h2><?= h($bulSlogan) ?></h2><?php endif; ?>
      <h2>BULLETIN DE NOTES</h2>
      <div class="doc-header-meta">
        <?= h($anneeLabel) ?><?= !$isSansSemestre && $semestreLabel ? ' &nbsp;|&nbsp; ' . h($semestreLabel) : '' ?>
      </div>
      <div class="badge-sess s<?= $sessionNum ?>">
        <?= $sessionNum == 1 ? 'PREMIÈRE SESSION' : 'DEUXIÈME SESSION (RATTRAPAGE)' ?>
      </div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <div class="lbl">Étudiant</div>
      <div class="val"><?= strtoupper(h($etudiant['nom'])) ?> <?= ucfirst(h($etudiant['prenom'])) ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Matricule</div>
      <div class="val" style="font-family:monospace;letter-spacing:.5px"><?= h($etudiant['matricule']) ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Filière</div>
      <div class="val"><?= h($etudiant['filiere_nom'] ?? '-') ?></div>
    </div>
    <div class="info-card">
      <div class="lbl">Niveau</div>
      <div class="val"><?= h($etudiant['niveau_nom'] ?? '-') ?></div>
    </div>
  </div>

  <?php /* $allUesOk already set in shared block above */ ?>

  <div class="notes-card">
    <table class="tbl">
      <thead>
        <tr>
          <th>Code</th>
          <th>Matière / Module</th>
          <th>Coef.</th>
          <th>CC /20</th>
          <th>Examen /20</th>
          <th>Note /20</th>
          <th><?= $isNivSup ? 'Statut' : 'Mention' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($isNivSup): ?>
          <?php foreach ($ueGroups as $ug): ?>
            <tr style="background:#e8eaf6">
              <td colspan="7" style="font-weight:700;font-size:11px;padding:5px 10px;color:#1a237e">
                UE <?= h($ug['code']) ?> — <?= h($ug['nom']) ?>
              </td>
            </tr>
            <?php foreach ($ug['notes'] as $n): ?>
            <?php $statut = $n['niv_sup_statut'] ?? ''; ?>
            <tr class="<?= ($statut === 'eliminatoire' || $statut === 'a_repasser') ? 'row-fail' : '' ?>">
              <td style="font-family:monospace;font-size:11px"><?= h($n['matiere_code']) ?></td>
              <td><?= h($n['matiere_nom']) ?></td>
              <td><?= $n['coefficient'] ?></td>
              <td><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
              <td><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
              <td class="<?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'cv' : 'cnv' ?>">
                <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
              </td>
              <td style="font-size:10px"><?= nivSupStatutBadge($statut) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:<?= $ug['validee'] ? '#e8f5e9' : '#fce4ec' ?>;font-weight:600;font-size:11px">
              <td colspan="5" style="text-align:right;padding-right:10px">Moy. UE <?= h($ug['code']) ?> :</td>
              <td class="<?= ($ug['moyenne'] ?? 0) >= 10 ? 'cv' : 'cnv' ?>">
                <?= $ug['moyenne'] !== null ? number_format($ug['moyenne'],2).'/20' : '–' ?>
              </td>
              <td style="font-size:10px">
                <?php if ($ug['has_elim']): ?>
                  <span style="background:#f8d7da;color:#721c24;padding:2px 6px;border-radius:8px">Non validée (élim.)</span>
                <?php elseif ($ug['validee']): ?>
                  <span style="background:#d4edda;color:#155724;padding:2px 6px;border-radius:8px">UE Validée</span>
                <?php else: ?>
                  <span style="background:#f8d7da;color:#721c24;padding:2px 6px;border-radius:8px">UE Non validée</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty($noUeNotes)): ?>
            <tr style="background:#f5f5f5"><td colspan="7" style="font-weight:700;font-size:11px;padding:5px 10px">Matières hors UE</td></tr>
            <?php foreach ($noUeNotes as $n): ?>
            <tr <?= ($n['note_finale'] !== null && $n['note_finale'] < 10) ? 'class="row-fail"' : '' ?>>
              <td style="font-family:monospace;font-size:11px"><?= h($n['matiere_code']) ?></td>
              <td><?= h($n['matiere_nom']) ?></td>
              <td><?= $n['coefficient'] ?></td>
              <td><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
              <td><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
              <td class="<?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'cv' : 'cnv' ?>">
                <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
              </td>
              <td style="font-size:10px"><?= nivSupStatutBadge($n['niv_sup_statut'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($notes as $n): ?>
          <tr <?= ($n['note_finale'] !== null && $n['note_finale'] < 10) ? 'class="row-fail"' : '' ?>>
            <td style="font-family:monospace;font-size:11px"><?= h($n['matiere_code']) ?></td>
            <td><?= h($n['matiere_nom']) ?></td>
            <td><?= $n['coefficient'] ?></td>
            <td><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
            <td><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
            <td class="<?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'cv' : 'cnv' ?>">
              <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
            </td>
            <td><?= getMention($n['note_finale'] !== null ? (float)$n['note_finale'] : null) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:14px"><strong>Moyenne générale :</strong></td>
          <td class="<?= $moyenne >= 10 ? 'cv' : 'cnv' ?>" style="font-size:15px">
            <strong><?= $moyenne !== null ? number_format($moyenne,2).'/20' : '–' ?></strong>
          </td>
          <td><?= $isNivSup ? '' : getMention($moyenne) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if ($classement): ?>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="s-inner">
        <div>
          <div class="sval" style="color:#0f2d5c"><?= $classement['rang'] ?></div>
          <div class="slbl">Classement</div>
        </div>
        <div>
          <div class="sval" style="color:#0f2d5c"><?= $classement['total'] ?></div>
          <div class="slbl">Candidats</div>
        </div>
        <div>
          <div class="sval <?= $classement['moyenne'] >= 10 ? 'cv' : 'cnv' ?>"><?= $classement['moyenne'] !== null ? number_format($classement['moyenne'],2) : '–' ?></div>
          <div class="slbl">Moyenne /20</div>
        </div>
      </div>
    </div>
    <div class="result-card">
      <div style="font-size:13px;color:#555">Décision :</div>
      <div class="result-badge <?= $allUesOk ? 'badge-ok' : 'badge-ko' ?>">
        <?= $allUesOk ? 'ADMIS(E)' : ($isNivSup ? 'À REPASSER' : 'AJOURNÉ(E)') ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="sig-block">
    <div class="sig-item"><div class="sig-line"></div>Signature de l'enseignant</div>
    <div class="sig-item"><div class="sig-line"></div>Responsable pédagogique</div>
    <div class="sig-item"><div class="sig-line"></div>Cachet et signature du Directeur</div>
  </div>

  <div class="doc-footer">
    EPSI – École Privée de Santé Ibn Rochd &nbsp;|&nbsp; Bulletin édité le <?= date('d/m/Y à H:i') ?>
  </div>
</div>

<div class="btn-actions">
  <button class="btn-print" onclick="window.print()">
    <i class="fas fa-print"></i> Imprimer
  </button>
  <a href="?etudiant_id=<?= $etudiantId ?>&annee_id=<?= $anneeId ?>&semestre_id=<?= $semestreId ?>&semestre_num=<?= $semestreNum ?>&session=<?= $sessionNum ?>&export_excel=1"
     class="btn-excel">
    <i class="fas fa-file-excel"></i> Excel
  </a>
  <a href="bulletins.php" class="btn-back">
    <i class="fas fa-arrow-left"></i> Retour
  </a>
</div>
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
<?php return; /* skip the rest of the file in print mode */ ?>

<?php else: /* non-print embedded Bootstrap view */ ?>
<!-- ═══ EMBEDDED Bootstrap card ═══ -->
<div class="card" id="bulletin">
  <div class="card-body p-4">
    <!-- Header -->
    <div class="mb-4" style="background:linear-gradient(135deg,#0f2d5c,#1a5276);border-radius:8px;padding:18px 22px;color:#fff;display:flex;align-items:center;gap:16px">
      <div class="flex-grow-1 text-center">
        <div style="font-size:.8rem;font-weight:600;opacity:.85;letter-spacing:.5px">ÉCOLE PRIVÉE DE SANTÉ IBN ROCHD</div>
        <h4 style="font-weight:700;margin:3px 0;font-size:1.1rem">BULLETIN DE NOTES</h4>
        <div style="font-size:.8rem;opacity:.85"><?= h($anneeLabel) ?><?= !$isSansSemestre ? ' – ' . h($semestreLabel) : '' ?></div>
      </div>
      <div class="text-end" style="flex-shrink:0">
        <span class="badge px-2 py-1 <?= $sessionNum == 1 ? 'bg-success' : 'bg-warning text-dark' ?>">
          <?= $sessionNum == 1 ? 'Session 1' : 'Session 2' ?>
        </span>
        <div style="font-size:.73rem;opacity:.8;margin-top:4px"><?= date('d/m/Y') ?></div>
      </div>
    </div>

    <!-- Student Info -->
    <div class="row mb-4">
      <div class="col-md-6">
        <table class="table table-borderless table-sm mb-0" style="font-size:.88rem">
          <tr><td style="width:140px;color:#666">Nom &amp; Prénom :</td><td><strong><?= h($etudiant['prenom'].' '.$etudiant['nom']) ?></strong></td></tr>
          <tr><td style="color:#666">Matricule :</td><td><code><?= h($etudiant['matricule']) ?></code></td></tr>
          <tr><td style="color:#666">Sexe :</td><td><?= $etudiant['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></td></tr>
          <tr><td style="color:#666">Date naissance :</td><td><?= formatDate($etudiant['date_naissance']) ?></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-borderless table-sm mb-0" style="font-size:.88rem">
          <tr><td style="width:140px;color:#666">Filière :</td><td><strong><?= h($etudiant['filiere_nom'] ?? '-') ?></strong></td></tr>
          <tr><td style="color:#666">Niveau :</td><td><?= h($etudiant['niveau_nom'] ?? '-') ?></td></tr>
          <tr><td style="color:#666">Année académique :</td><td><?= h($anneeLabel) ?></td></tr>
          <?php if (!$isSansSemestre): ?><tr><td style="color:#666">Semestre :</td><td><?= h($semestreLabel) ?></td></tr><?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Notes Table -->
    <?php if ($isNivSup): ?>
    <!-- Légende compensation -->
    <div class="d-flex gap-2 flex-wrap mb-2" style="font-size:.78rem">
      <span class="badge bg-success">≥10 Validé</span>
      <span class="badge bg-warning text-dark">6-9 + moy. UE ≥10 → Compensé</span>
      <span class="badge bg-danger">≤5 Éliminatoire</span>
      <span class="badge bg-secondary">À repasser</span>
    </div>
    <?php endif; ?>
    <table class="table table-bordered" style="font-size:.88rem">
      <thead style="background:#0f2d5c;color:#fff">
        <tr>
          <th>Code</th><th>Matière / Module</th><th class="text-center">Coef.</th>
          <th class="text-center">CC /20</th><th class="text-center">Examen /20</th>
          <th class="text-center">Note /20</th>
          <th class="text-center"><?= $isNivSup ? 'Statut' : 'Mention' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($isNivSup): ?>
          <?php foreach ($ueGroups as $ug): ?>
            <tr style="background:#e8eaf6">
              <td colspan="7" class="fw-bold" style="font-size:.8rem;color:#1a237e;padding:6px 10px">
                <i class="fas fa-layer-group me-1"></i>UE <?= h($ug['code']) ?> — <?= h($ug['nom']) ?>
              </td>
            </tr>
            <?php foreach ($ug['notes'] as $n): ?>
            <?php $statut = $n['niv_sup_statut'] ?? ''; ?>
            <?php $rowBg = match($statut) { 'eliminatoire'=>'#fff3f3','a_repasser'=>'#fff8e1', default=>'' }; ?>
            <tr <?= $rowBg ? 'style="background:' . $rowBg . '"' : '' ?>>
              <td><code style="font-size:.8rem"><?= h($n['matiere_code']) ?></code></td>
              <td><?= h($n['matiere_nom']) ?></td>
              <td class="text-center"><?= $n['coefficient'] ?></td>
              <td class="text-center"><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
              <td class="text-center"><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
              <td class="text-center fw-bold <?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'text-success' : 'text-danger' ?>">
                <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
              </td>
              <td class="text-center"><?php
                echo match($statut) {
                    'valide'       => '<span class="badge bg-success">Validé</span>',
                    'eliminatoire' => '<span class="badge bg-danger">Éliminatoire</span>',
                    'compense'     => '<span class="badge bg-warning text-dark">Compensé</span>',
                    'a_repasser'   => '<span class="badge bg-secondary">À repasser</span>',
                    default        => '<span class="text-muted">–</span>',
                };
              ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:<?= $ug['validee'] ? '#e8f5e9' : '#fce4ec' ?>;font-weight:600">
              <td colspan="5" class="text-end pe-3" style="font-size:.82rem">Moy. UE <?= h($ug['code']) ?> :</td>
              <td class="text-center <?= ($ug['moyenne'] ?? 0) >= 10 ? 'text-success' : 'text-danger' ?>">
                <?= $ug['moyenne'] !== null ? number_format($ug['moyenne'],2).'/20' : '–' ?>
              </td>
              <td class="text-center" style="font-size:.8rem">
                <?php if ($ug['has_elim']): ?>
                  <span class="badge bg-danger">Non validée (élim.)</span>
                <?php elseif ($ug['validee']): ?>
                  <span class="badge bg-success">UE Validée</span>
                <?php else: ?>
                  <span class="badge bg-danger">UE Non validée</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty($noUeNotes)): ?>
            <tr style="background:#f5f5f5">
              <td colspan="7" class="fw-bold" style="font-size:.8rem;padding:6px 10px">Matières hors UE</td>
            </tr>
            <?php foreach ($noUeNotes as $n): ?>
            <tr <?= ($n['note_finale'] !== null && $n['note_finale'] < 10) ? 'style="background:#fff8e1"' : '' ?>>
              <td><code style="font-size:.8rem"><?= h($n['matiere_code']) ?></code></td>
              <td><?= h($n['matiere_nom']) ?></td>
              <td class="text-center"><?= $n['coefficient'] ?></td>
              <td class="text-center"><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
              <td class="text-center"><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
              <td class="text-center fw-bold <?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'text-success' : 'text-danger' ?>">
                <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
              </td>
              <td class="text-center"><?php
                $st = $n['niv_sup_statut'] ?? '';
                echo match($st) { 'valide'=>'<span class="badge bg-success">Validé</span>', 'eliminatoire'=>'<span class="badge bg-danger">Éliminatoire</span>', 'compense'=>'<span class="badge bg-warning text-dark">Compensé</span>', 'a_repasser'=>'<span class="badge bg-secondary">À repasser</span>', default=>'–' };
              ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($notes as $n): ?>
          <tr <?= ($n['note_finale'] !== null && $n['note_finale'] < 10) ? 'style="background:#fff3f3"' : '' ?>>
            <td><code style="font-size:.8rem"><?= h($n['matiere_code']) ?></code></td>
            <td><?= h($n['matiere_nom']) ?></td>
            <td class="text-center"><?= $n['coefficient'] ?></td>
            <td class="text-center"><?= $n['note_cc']   !== null ? number_format($n['note_cc'],2)   : '–' ?></td>
            <td class="text-center"><?= $n['note_exam'] !== null ? number_format($n['note_exam'],2) : '–' ?></td>
            <td class="text-center fw-bold <?= ($n['note_finale'] !== null && $n['note_finale'] >= 10) ? 'text-success' : 'text-danger' ?>">
              <?= $n['note_finale'] !== null ? number_format($n['note_finale'],2) : '–' ?>
            </td>
            <td class="text-center"><?= getMention($n['note_finale'] !== null ? (float)$n['note_finale'] : null) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fa;font-weight:700">
          <td colspan="2" class="text-end">Moyenne générale :</td>
          <td colspan="3"></td>
          <td class="text-center fs-5 <?= $moyenne >= 10 ? 'text-success' : 'text-danger' ?>">
            <?= $moyenne !== null ? number_format($moyenne,2) . '/20' : '–' ?>
          </td>
          <td class="text-center"><?= $isNivSup ? '' : getMention($moyenne) ?></td>
        </tr>
      </tfoot>
    </table>

    <!-- Classement et résultat -->
    <?php if ($classement): ?>
    <div class="row mt-3">
      <div class="col-md-6">
        <div class="p-3 rounded" style="background:#f0f4f8;border-left:4px solid #0f2d5c">
          <div class="row text-center">
            <div class="col-4">
              <div class="fw-bold" style="font-size:1.5rem;color:#0f2d5c"><?= $classement['rang'] ?></div>
              <div class="text-muted" style="font-size:.75rem">Classement</div>
            </div>
            <div class="col-4">
              <div class="fw-bold" style="font-size:1.5rem;color:#0f2d5c"><?= $classement['total'] ?></div>
              <div class="text-muted" style="font-size:.75rem">Candidats</div>
            </div>
            <div class="col-4">
              <div class="fw-bold <?= $classement['moyenne'] >= 10 ? 'text-success' : 'text-danger' ?>" style="font-size:1.5rem">
                <?= $classement['moyenne'] !== null ? number_format($classement['moyenne'],2) : '–' ?>
              </div>
              <div class="text-muted" style="font-size:.75rem">Moyenne</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="p-3 rounded d-flex align-items-center justify-content-between h-100" style="background:#f0f4f8">
          <div style="font-size:.85rem;color:#666">Résultat :</div>
          <span class="badge px-3 py-2" style="font-size:1rem;background:<?= $allUesOk ? '#34a853' : '#ea4335' ?>">
            <?= $allUesOk ? 'ADMIS(E)' : ($isNivSup ? 'À REPASSER' : 'AJOURNÉ(E)') ?>
          </span>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="row mt-5 pt-3" style="border-top:1px solid #ddd">
      <div class="col-4 text-center">
        <div class="text-muted mb-4" style="font-size:.8rem">Signature de l'enseignant</div>
        <div style="border-bottom:1px solid #999;margin:0 2rem"></div>
      </div>
      <div class="col-4 text-center">
        <div class="text-muted mb-4" style="font-size:.8rem">Responsable pédagogique</div>
        <div style="border-bottom:1px solid #999;margin:0 2rem"></div>
      </div>
      <div class="col-4 text-center">
        <div class="text-muted mb-4" style="font-size:.8rem">Cachet et signature du Directeur</div>
        <div style="border-bottom:1px solid #999;margin:0 2rem"></div>
      </div>
    </div>

    <div class="text-center mt-4" style="font-size:.75rem;color:#aaa">
      EPSI – École Privée de Santé Ibn Rochd &nbsp;|&nbsp; Document généré le <?= date('d/m/Y à H:i') ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php elseif ($etudiant && empty($notes)): ?>
<div class="card"><div class="card-body empty-state"><i class="fas fa-inbox"></i><h5>Aucune note trouvée</h5><p class="text-muted">Aucune note enregistrée pour ce semestre.</p></div></div>
<?php elseif ($etudiantId && !$etudiant): ?>
<div class="alert alert-warning">Étudiant introuvable.</div>
<?php else: ?>
<div class="card"><div class="card-body empty-state"><i class="fas fa-file-alt"></i><h5>Sélectionner un étudiant</h5><p class="text-muted">Choisissez l'étudiant, l'année et le semestre pour générer le bulletin.</p></div></div>
<?php endif; ?>

<?php if (!$print): ?>

<!-- ── Bulletin Global ───────────────────────────────────────────────────── -->
<div class="card mt-4 no-print">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="fas fa-users text-success"></i>
    <strong>Bulletin Global – Toute une filière</strong>
  </div>
  <div class="card-body">
    <form method="GET" action="bulletin_global_view.php" target="_blank" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-600">Filière</label>
        <select name="filiere_id" id="bg_filiere_id" class="form-select" required onchange="toggleBGFiliere(this)">
          <option value="">-- Sélectionner --</option>
          <?php foreach (getFilieres() as $f): ?>
            <?php if (!empty($f['niveau_superieur'])) continue; // Exclure LSIO/TC, INF, SF ?>
            <option value="<?= $f['id'] ?>"
                    data-sans-semestre="<?= in_array($f['code'] ?? '', $SANS_SEMESTRE_CODES) ? '1' : '0' ?>">
              <?= h($f['code']) ?> – <?= h($f['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Niveau : affiché uniquement pour les filières sans semestre (ASB, VP) -->
      <div class="col-md-2" id="bg_niveau_div" style="display:none">
        <label class="form-label fw-600">Niveau <span class="text-danger">*</span></label>
        <select name="niveau_id" id="bg_niveau_id" class="form-select">
          <option value="">-- Sélectionner --</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-600">Année académique</label>
        <select name="annee_id" id="bg_annee_id" class="form-select">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($annees as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $anneeId == $a['id'] ? 'selected' : '' ?>><?= h($a['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Semestre : masqué pour les filières sans semestre -->
      <div class="col-md-2" id="bg_semestre_div">
        <label class="form-label fw-600">Semestre</label>
        <select name="semestre_id" id="bg_semestre_id" class="form-select">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($semestres as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label fw-600">Session</label>
        <select name="session" class="form-select">
          <option value="1">S1</option>
          <option value="2">S2</option>
        </select>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-success w-100">
          <i class="fas fa-file-alt"></i>
        </button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php if (!$print):
$extraScripts = <<<JS
<script>
(function() {
  const wrap        = document.getElementById('studentSelectWrap');
  if (!wrap) return;

  const displayInput = document.getElementById('studentSearchInput');
  const hiddenInput  = document.getElementById('etudiantHidden');
  const panel        = document.getElementById('studentDropdownPanel');
  const filterInput  = document.getElementById('studentFilterInput');
  const optionsList  = document.getElementById('studentOptionsList');
  const clearBtn     = document.getElementById('clearStudentBtn');
  const noResult     = document.getElementById('noStudentResult');

  function openPanel() {
    panel.style.display = '';
    filterInput.value   = '';
    filterOptions('');
    setTimeout(() => filterInput.focus(), 30);
  }

  function closePanel() {
    panel.style.display = 'none';
  }

  function filterOptions(q) {
    const opts    = optionsList.querySelectorAll('.student-opt');
    let   visible = 0;
    opts.forEach(o => {
      const match = !q || o.dataset.search.includes(q.toLowerCase());
      o.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    if (noResult) noResult.classList.toggle('d-none', visible > 0);
  }

  function filterSemNumOptions(isTc, hasTcId) {
    const sel = document.getElementById('semestre_num');
    if (!sel) return;
    const type = isTc ? 'tc' : (hasTcId ? 'inf_sf' : 'all');
    sel.querySelectorAll('option[data-for]').forEach(o => {
      const show = type === 'all' || o.getAttribute('data-for') === type;
      o.style.display = show ? '' : 'none';
    });
    const cur = sel.options[sel.selectedIndex];
    if (cur && cur.style && cur.style.display === 'none') sel.value = '';
  }

  function selectStudent(id, label, sansSemestre, nivSup, isTc, hasTcId) {
    hiddenInput.value  = id;
    displayInput.value = label;
    if (clearBtn) clearBtn.style.display = '';
    closePanel();
    filterInput.value = '';
    const sDiv    = document.getElementById('semestre_div');
    const sSel    = document.getElementById('semestre_id');
    const snDiv   = document.getElementById('semestre_num_div');
    const snSel   = document.getElementById('semestre_num');
    const showStd = !sansSemestre && !nivSup;
    const showNiv = !sansSemestre && !!nivSup;
    if (sDiv)  { sDiv.style.display  = showStd ? '' : 'none'; }
    if (sSel)  { sSel.disabled  = !showStd; }
    if (snDiv) { snDiv.style.display = showNiv ? '' : 'none'; }
    if (snSel) { snSel.disabled = !showNiv; }
    if (showNiv) filterSemNumOptions(isTc, hasTcId);
  }

  // Open on click/focus
  displayInput.addEventListener('click', openPanel);

  // Filter as user types
  if (filterInput) {
    filterInput.addEventListener('input', () => filterOptions(filterInput.value.trim()));
  }

  // Click on option
  optionsList.addEventListener('click', function(e) {
    const opt = e.target.closest('.student-opt');
    if (!opt) return;
    selectStudent(
      opt.dataset.id, opt.dataset.label,
      opt.dataset.sansSemestre === '1',
      opt.dataset.nivSup       === '1',
      opt.dataset.tronc        === '1',
      parseInt(opt.dataset.tcId || '0') > 0
    );
  });

  // Hover styles
  optionsList.addEventListener('mouseover', e => {
    const opt = e.target.closest('.student-opt');
    if (opt) opt.style.background = '#f0f4f8';
  });
  optionsList.addEventListener('mouseout', e => {
    const opt = e.target.closest('.student-opt');
    if (opt) opt.style.background = '';
  });

  // Clear button
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      hiddenInput.value  = '';
      displayInput.value = '';
      clearBtn.style.display = 'none';
    });
  }

  // Close when clicking outside
  document.addEventListener('click', e => {
    if (!wrap.contains(e.target)) closePanel();
  });
})();

</script>
JS;

// Hors heredoc : évite que PHP n'interpole $niveaux_by_filiere et ${n.id}
$_nivJson      = json_encode($niveaux_by_filiere);
$extraScripts .= "<script>const bgNiveaux={$_nivJson};</script>";
$extraScripts .= <<<'EOFJS'
<script>
function toggleBGFiliere(sel) {
  var opt      = sel.options[sel.selectedIndex];
  var sans     = opt && opt.dataset.sansSemestre === '1';
  var filiereId = opt ? parseInt(opt.value) : 0;
  var semDiv   = document.getElementById('bg_semestre_div');
  var semSel   = document.getElementById('bg_semestre_id');
  if (semDiv) semDiv.style.display = sans ? 'none' : '';
  if (semSel) semSel.disabled = sans;
  var nivDiv = document.getElementById('bg_niveau_div');
  var nivSel = document.getElementById('bg_niveau_id');
  if (!nivDiv || !nivSel) return;
  if (sans && filiereId && bgNiveaux[filiereId]) {
    nivSel.innerHTML = '<option value="">-- Sélectionner --</option>';
    bgNiveaux[filiereId].forEach(function(n) {
      nivSel.innerHTML += '<option value="' + n.id + '">' + n.nom + '</option>';
    });
    nivDiv.style.display = '';
  } else {
    nivSel.innerHTML = '<option value="">-- Sélectionner --</option>';
    nivDiv.style.display = 'none';
  }
}
function toggleBGSemestre(sel) { toggleBGFiliere(sel); }
</script>
EOFJS;

include APP_ROOT . '/includes/footer.php';
endif;
?>
