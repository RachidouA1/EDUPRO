<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole(['admin', 'directeur', 'enseignant', 'scolarite', 'coordinateur']);

$db = getDB();

// Migrations runtime (identiques à notes.php)
try { $db->exec("ALTER TABLE notes    ADD COLUMN session        TINYINT     NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE matieres ADD COLUMN formule_calcul VARCHAR(20) NOT NULL DEFAULT 'pondere'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE notes MODIFY COLUMN semestre_id INT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE filieres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE semestres ADD COLUMN niveau_superieur TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE semestres ADD COLUMN semestre_num TINYINT NULL"); } catch (PDOException $e) {}

$anneeId    = (int)($_GET['annee_id']    ?? 0);
$semestreId = (int)($_GET['semestre_id'] ?? 0);
$matiereId  = (int)($_GET['matiere_id']  ?? 0);
$sessionNum = (int)($_GET['session']     ?? 1);
if (!in_array($sessionNum, [1, 2])) $sessionNum = 1;

if (!$matiereId || !$anneeId) {
    http_response_code(400);
    exit('Paramètres manquants.');
}

$NO_SEM_CODES   = ['ASB', 'VP'];
$EXAM_ONLY_CODES = ['ASB', 'VP'];

function calcFin(?float $cc, ?float $exam, string $f): ?float {
    if ($exam === null) return null;
    if ($f === 'pondere' && $cc !== null)    return round($cc * 0.4 + $exam * 0.6, 2);
    if ($f === 'demi_somme' && $cc !== null) return round(($cc + $exam) / 2, 2);
    return $exam;
}

function effectiveFormule(string $fCode, string $matFormule): string {
    global $EXAM_ONLY_CODES;
    if (in_array(strtoupper($fCode), $EXAM_ONLY_CODES)) return 'exam_seul';
    return in_array($matFormule, ['pondere', 'demi_somme', 'exam_seul']) ? $matFormule : 'pondere';
}

// Charger la matière
$smStmt = $db->prepare("
    SELECT m.*, f.nom as filiere_nom, f.code as filiere_code,
           f.niveau_superieur as fil_niveau_sup,
           n.nom as niveau_nom,
           u.code_ue as ue_code, u.nom as ue_nom,
           s.nom as semestre_nom
    FROM matieres m
    LEFT JOIN filieres f ON f.id = m.filiere_id
    LEFT JOIN niveaux  n ON n.id = m.niveau_id
    LEFT JOIN ue       u ON u.id = m.ue_id
    LEFT JOIN semestres s ON s.id = ?
    WHERE m.id = ?
");
$smStmt->execute([$semestreId, $matiereId]);
$mat = $smStmt->fetch();
if (!$mat) { http_response_code(404); exit('Matière introuvable.'); }

$isNivSup      = !empty($mat['fil_niveau_sup']);
$filiereCode   = strtoupper($mat['filiere_code'] ?? '');
$needsSemestre = !in_array($filiereCode, $NO_SEM_CODES) && !$isNivSup;
if (!$needsSemestre) $semestreId = 0;

$activeFormule = effectiveFormule($filiereCode, $mat['formule_calcul'] ?? 'pondere');
$formuleDefs = [
    'exam_seul'  => ['label' => 'Examen seul (100 %)',                 'showCC' => false, 'ccPct' => '',     'examPct' => '100%'],
    'pondere'    => ['label' => 'Note de classe 40% + Examen 60%',   'showCC' => true,  'ccPct' => '40%',  'examPct' => '60%'],
    'demi_somme' => ['label' => '(Note de classe + Examen) / 2',     'showCC' => true,  'ccPct' => '50%',  'examPct' => '50%'],
];
$fd     = $formuleDefs[$activeFormule] ?? $formuleDefs['exam_seul'];
$showCC = $fd['showCC'];

// Année académique
$anneeLabel = '';
$annees = getAnneesAcademiques();
foreach ($annees as $a) { if ($a['id'] == $anneeId) { $anneeLabel = $a['libelle']; break; } }

// Semestre label
$semestreLabel = '';
if ($isNivSup && !empty($mat['semestre_num'])) {
    $semestreLabel = 'Semestre ' . $mat['semestre_num'];
} elseif ($needsSemestre && $semestreId && $mat['semestre_nom']) {
    $semestreLabel = $mat['semestre_nom'];
}

// Étudiants
$ecoleId = getEcoleId();
$eSql    = "SELECT e.id, e.nom, e.prenom, e.matricule, e.sexe
            FROM etudiants e
            WHERE e.filiere_id=? AND e.niveau_id=? AND e.annee_id=? AND e.statut='actif'";
$eParams = [$mat['filiere_id'], $mat['niveau_id'], $anneeId];
if ($ecoleId > 0) { $eSql .= " AND e.ecole_id=?"; $eParams[] = $ecoleId; }
$eSql .= " ORDER BY e.nom, e.prenom";
$eStmt = $db->prepare($eSql);
$eStmt->execute($eParams);
$etudiants = $eStmt->fetchAll();

// Notes
if ($semestreId) {
    $nStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id=? AND session=?");
    $nStmt->execute([$matiereId, $anneeId, $semestreId, $sessionNum]);
} else {
    $nStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id IS NULL AND session=?");
    $nStmt->execute([$matiereId, $anneeId, $sessionNum]);
}
$existingNotes = [];
foreach ($nStmt->fetchAll() as $row) { $existingNotes[$row['etudiant_id']] = $row; }

// Notes Session 1 de référence (pour Session 2)
$prevNotes = [];
if ($sessionNum === 2) {
    if ($semestreId) {
        $pStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id=? AND session=1");
        $pStmt->execute([$matiereId, $anneeId, $semestreId]);
    } else {
        $pStmt = $db->prepare("SELECT * FROM notes WHERE matiere_id=? AND annee_id=? AND semestre_id IS NULL AND session=1");
        $pStmt->execute([$matiereId, $anneeId]);
    }
    foreach ($pStmt->fetchAll() as $row) { $prevNotes[$row['etudiant_id']] = $row; }
    $seuilValid = (float)($mat['seuil_reussite'] ?? 10);
    $etudiants  = array_values(array_filter($etudiants, function ($e) use ($prevNotes, $seuilValid) {
        $pn = $prevNotes[$e['id']] ?? null;
        return !($pn && $pn['note_finale'] !== null && (float)$pn['note_finale'] >= $seuilValid);
    }));
}

$seuil   = (float)($mat['seuil_reussite'] ?? 10);
$nomEcole = getParam('etablissement_nom', 'École');
$slogan   = getParam('etablissement_slogan');
$showS1   = ($sessionNum === 2);

// ─── Nom du fichier ───────────────────────────────────────────────────────────
$filename = 'Notes_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $mat['code'] ?? 'MAT')
          . '_S' . $sessionNum . '_' . date('Ymd') . '.xls';

// ─── En-têtes HTTP ────────────────────────────────────────────────────────────
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// ─── Génération SpreadsheetML ─────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:o="urn:schemas-microsoft-com:office:office"
  xmlns:x="urn:schemas-microsoft-com:office:excel"
  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:html="http://www.w3.org/TR/REC-html40">

  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
    <Title>Notes <?= htmlspecialchars($mat['code'] ?? '') ?> – Session <?= $sessionNum ?></Title>
    <Author><?= htmlspecialchars($nomEcole) ?></Author>
    <Created><?= date('Y-m-d\TH:i:s\Z') ?></Created>
  </DocumentProperties>

  <Styles>
    <Style ss:ID="Title">
      <Font ss:Bold="1" ss:Size="14"/>
      <Alignment ss:Horizontal="Center"/>
    </Style>
    <Style ss:ID="SubTitle">
      <Font ss:Bold="1" ss:Size="11"/>
      <Alignment ss:Horizontal="Center"/>
    </Style>
    <Style ss:ID="Info">
      <Font ss:Size="10"/>
    </Style>
    <Style ss:ID="Header">
      <Font ss:Bold="1" ss:Size="10" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#1a73e8" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
      </Borders>
    </Style>
    <Style ss:ID="HeaderS1">
      <Font ss:Bold="1" ss:Size="10" ss:Color="#333333"/>
      <Interior ss:Color="#E8E8E8" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      </Borders>
    </Style>
    <Style ss:ID="CellNum">
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
      <NumberFormat ss:Format="0.00"/>
    </Style>
    <Style ss:ID="CellTxt">
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
    <Style ss:ID="CellTxtC">
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
    <Style ss:ID="CellBold">
      <Font ss:Bold="1"/>
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
      <NumberFormat ss:Format="0.00"/>
    </Style>
    <Style ss:ID="ValOK">
      <Font ss:Bold="1" ss:Color="#155724"/>
      <Interior ss:Color="#D4EDDA" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
    <Style ss:ID="ValKO">
      <Font ss:Bold="1" ss:Color="#721C24"/>
      <Interior ss:Color="#F8D7DA" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
    <Style ss:ID="RowAlt">
      <Interior ss:Color="#F8F9FA" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
    <Style ss:ID="SummaryLabel">
      <Font ss:Bold="1" ss:Size="10"/>
      <Interior ss:Color="#E3F2FD" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
      </Borders>
    </Style>
    <Style ss:ID="SummaryVal">
      <Font ss:Bold="1" ss:Size="10" ss:Color="#1565C0"/>
      <Interior ss:Color="#E3F2FD" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#90CAF9"/>
      </Borders>
    </Style>
    <Style ss:ID="Empty">
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      </Borders>
    </Style>
  </Styles>

  <Worksheet ss:Name="Notes">
    <Table ss:DefaultColumnWidth="80">

<?php
// ── Calcul du nombre de colonnes ────────────────────────────────────────────
$nbCols = 3; // #, Matricule, Nom
if ($showS1)  $nbCols += 2; // Exam S1, Moy S1
if ($showCC)  $nbCols += 1; // CC
$nbCols += 3; // Examen, Moyenne, Validation

// ── Largeurs de colonnes ────────────────────────────────────────────────────
echo "      <Column ss:Width=\"30\"/>\n";   // #
echo "      <Column ss:Width=\"80\"/>\n";   // Matricule
echo "      <Column ss:Width=\"160\"/>\n";  // Nom & Prénom
if ($showS1) {
    echo "      <Column ss:Width=\"70\"/>\n"; // Exam S1
    echo "      <Column ss:Width=\"70\"/>\n"; // Moy S1
}
if ($showCC) echo "      <Column ss:Width=\"70\"/>\n"; // CC
echo "      <Column ss:Width=\"70\"/>\n";   // Examen
echo "      <Column ss:Width=\"70\"/>\n";   // Moyenne
echo "      <Column ss:Width=\"90\"/>\n";   // Validation

// ── Ligne 1 : Nom de l'établissement ────────────────────────────────────────
echo "      <Row ss:Height=\"22\">\n";
echo "        <Cell ss:MergeAcross=\"" . ($nbCols - 1) . "\" ss:StyleID=\"Title\"><Data ss:Type=\"String\">" . htmlspecialchars($nomEcole) . "</Data></Cell>\n";
echo "      </Row>\n";

// ── Ligne 2 : Slogan ────────────────────────────────────────────────────────
if ($slogan) {
    echo "      <Row ss:Height=\"16\">\n";
    echo "        <Cell ss:MergeAcross=\"" . ($nbCols - 1) . "\" ss:StyleID=\"SubTitle\"><Data ss:Type=\"String\">" . htmlspecialchars($slogan) . "</Data></Cell>\n";
    echo "      </Row>\n";
}

// ── Ligne 3 : Titre PV ──────────────────────────────────────────────────────
echo "      <Row ss:Height=\"18\">\n";
echo "        <Cell ss:MergeAcross=\"" . ($nbCols - 1) . "\" ss:StyleID=\"SubTitle\"><Data ss:Type=\"String\">PROCÈS-VERBAL DE NOTES — Session " . $sessionNum . ($sessionNum === 2 ? ' (Rattrapage)' : '') . "</Data></Cell>\n";
echo "      </Row>\n";

// ── Ligne vide ──────────────────────────────────────────────────────────────
echo "      <Row ss:Height=\"8\"><Cell><Data ss:Type=\"String\"></Data></Cell></Row>\n";

// ── Infos de la matière ──────────────────────────────────────────────────────
$infoRows = [
    ['Matière :', $mat['code'] . ' – ' . $mat['nom']],
    ['Filière :', $mat['filiere_nom'] ?? ''],
    ['Niveau :',  $mat['niveau_nom']  ?? ''],
    ['Année académique :', $anneeLabel],
    ['Semestre :', $semestreLabel ?: '–'],
    ['Formule de calcul :', $fd['label']],
    ['Effectif :', count($etudiants) . ' étudiant(s)'],
    ['Date d\'export :', date('d/m/Y à H:i')],
];
$half = ceil(count($infoRows) / 2);
for ($ri = 0; $ri < $half; $ri++) {
    $left  = $infoRows[$ri];
    $right = $infoRows[$ri + $half] ?? null;
    echo "      <Row ss:Height=\"14\">\n";
    echo "        <Cell ss:MergeAcross=\"1\" ss:StyleID=\"Info\"><Data ss:Type=\"String\">" . htmlspecialchars($left[0]) . "</Data></Cell>\n";
    $mergeRight = $right ? 1 : ($nbCols - 3);
    echo "        <Cell ss:MergeAcross=\"" . $mergeRight . "\" ss:StyleID=\"Info\"><Data ss:Type=\"String\">" . htmlspecialchars($left[1]) . "</Data></Cell>\n";
    if ($right) {
        $remain = $nbCols - 4;
        echo "        <Cell ss:MergeAcross=\"1\" ss:StyleID=\"Info\"><Data ss:Type=\"String\">" . htmlspecialchars($right[0]) . "</Data></Cell>\n";
        echo "        <Cell ss:MergeAcross=\"" . ($remain < 0 ? 0 : $remain) . "\" ss:StyleID=\"Info\"><Data ss:Type=\"String\">" . htmlspecialchars($right[1]) . "</Data></Cell>\n";
    }
    echo "      </Row>\n";
}

// ── Ligne vide ──────────────────────────────────────────────────────────────
echo "      <Row ss:Height=\"8\"><Cell><Data ss:Type=\"String\"></Data></Cell></Row>\n";

// ── En-têtes de colonnes ─────────────────────────────────────────────────────
echo "      <Row ss:Height=\"30\">\n";
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">#</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Matricule</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Nom &amp; Prénom</Data></Cell>\n";
if ($showS1) {
    echo "        <Cell ss:StyleID=\"HeaderS1\"><Data ss:Type=\"String\">Exam S1</Data></Cell>\n";
    echo "        <Cell ss:StyleID=\"HeaderS1\"><Data ss:Type=\"String\">Moy S1</Data></Cell>\n";
}
if ($showCC) {
    echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Note de classe /20 (" . $fd['ccPct'] . ")</Data></Cell>\n";
}
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Examen /20 (" . $fd['examPct'] . ")</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Moyenne /20</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Validation</Data></Cell>\n";
echo "      </Row>\n";

// ── Lignes de données ────────────────────────────────────────────────────────
$nbValides  = 0;
$totalMoy   = 0;
$nbAvecNote = 0;

foreach ($etudiants as $i => $e) {
    $n   = $existingNotes[$e['id']] ?? null;
    $pn  = $prevNotes[$e['id']]     ?? null;
    $fin = $n['note_finale'] ?? null;

    $isValide = ($fin !== null && (float)$fin >= $seuil);
    if ($isValide) $nbValides++;
    if ($fin !== null) { $totalMoy += (float)$fin; $nbAvecNote++; }

    $rowStyle = ($i % 2 === 1) ? 'RowAlt' : 'Empty';

    echo "      <Row ss:Height=\"16\">\n";
    echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"Number\">" . ($i + 1) . "</Data></Cell>\n";
    echo "        <Cell ss:StyleID=\"CellTxt\"><Data ss:Type=\"String\">" . htmlspecialchars($e['matricule']) . "</Data></Cell>\n";
    echo "        <Cell ss:StyleID=\"CellTxt\"><Data ss:Type=\"String\">" . htmlspecialchars($e['nom'] . ' ' . $e['prenom']) . "</Data></Cell>\n";

    if ($showS1) {
        $examS1 = ($pn && $pn['note_exam']    !== null) ? $pn['note_exam']    : null;
        $moyS1  = ($pn && $pn['note_finale']  !== null) ? $pn['note_finale']  : null;
        if ($examS1 !== null) echo "        <Cell ss:StyleID=\"CellNum\"><Data ss:Type=\"Number\">" . $examS1 . "</Data></Cell>\n";
        else                  echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"String\">–</Data></Cell>\n";
        if ($moyS1 !== null)  echo "        <Cell ss:StyleID=\"CellNum\"><Data ss:Type=\"Number\">" . $moyS1 . "</Data></Cell>\n";
        else                  echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"String\">–</Data></Cell>\n";
    }

    if ($showCC) {
        $cc = ($n && $n['note_cc'] !== null) ? $n['note_cc'] : null;
        if ($cc !== null) echo "        <Cell ss:StyleID=\"CellNum\"><Data ss:Type=\"Number\">" . $cc . "</Data></Cell>\n";
        else              echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"String\">–</Data></Cell>\n";
    }

    $exam = ($n && $n['note_exam'] !== null) ? $n['note_exam'] : null;
    if ($exam !== null) echo "        <Cell ss:StyleID=\"CellNum\"><Data ss:Type=\"Number\">" . $exam . "</Data></Cell>\n";
    else                echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"String\">–</Data></Cell>\n";

    if ($fin !== null) echo "        <Cell ss:StyleID=\"CellBold\"><Data ss:Type=\"Number\">" . $fin . "</Data></Cell>\n";
    else               echo "        <Cell ss:StyleID=\"CellTxtC\"><Data ss:Type=\"String\">–</Data></Cell>\n";

    $valStyle = $fin !== null ? ($isValide ? 'ValOK' : 'ValKO') : 'CellTxtC';
    $valTxt   = $fin !== null ? ($isValide ? 'Validé' : 'Non validé') : '–';
    echo "        <Cell ss:StyleID=\"$valStyle\"><Data ss:Type=\"String\">" . $valTxt . "</Data></Cell>\n";

    echo "      </Row>\n";
}

// ── Ligne vide ──────────────────────────────────────────────────────────────
echo "      <Row ss:Height=\"8\"><Cell><Data ss:Type=\"String\"></Data></Cell></Row>\n";

// ── Ligne de récapitulatif ───────────────────────────────────────────────────
$moyGlobale   = $nbAvecNote > 0 ? round($totalMoy / $nbAvecNote, 2) : null;
$tauxReussite = count($etudiants) > 0 ? round($nbValides / count($etudiants) * 100, 1) : 0;

echo "      <Row ss:Height=\"16\">\n";
echo "        <Cell ss:MergeAcross=\"2\" ss:StyleID=\"SummaryLabel\"><Data ss:Type=\"String\">Effectif total</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"SummaryVal\"><Data ss:Type=\"Number\">" . count($etudiants) . "</Data></Cell>\n";
echo "      </Row>\n";
echo "      <Row ss:Height=\"16\">\n";
echo "        <Cell ss:MergeAcross=\"2\" ss:StyleID=\"SummaryLabel\"><Data ss:Type=\"String\">Admis (≥ " . $seuil . "/20)</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"SummaryVal\"><Data ss:Type=\"Number\">" . $nbValides . "</Data></Cell>\n";
echo "      </Row>\n";
echo "      <Row ss:Height=\"16\">\n";
echo "        <Cell ss:MergeAcross=\"2\" ss:StyleID=\"SummaryLabel\"><Data ss:Type=\"String\">Taux de réussite</Data></Cell>\n";
echo "        <Cell ss:StyleID=\"SummaryVal\"><Data ss:Type=\"String\">" . $tauxReussite . " %</Data></Cell>\n";
echo "      </Row>\n";
if ($moyGlobale !== null) {
    echo "      <Row ss:Height=\"16\">\n";
    echo "        <Cell ss:MergeAcross=\"2\" ss:StyleID=\"SummaryLabel\"><Data ss:Type=\"String\">Moyenne de la classe</Data></Cell>\n";
    echo "        <Cell ss:StyleID=\"SummaryVal\"><Data ss:Type=\"Number\">" . $moyGlobale . "</Data></Cell>\n";
    echo "      </Row>\n";
}
?>

    </Table>
    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <PageSetup>
        <Layout x:Orientation="Landscape"/>
        <PageMargins x:Bottom="0.75" x:Left="0.7" x:Right="0.7" x:Top="0.75"/>
      </PageSetup>
      <FitToPage/>
      <Print>
        <FitHeight>1</FitHeight>
        <FitWidth>1</FitWidth>
      </Print>
      <Selected/>
      <FreezePanes/>
      <SplitHorizontal>10</SplitHorizontal>
      <TopRowBottomPane>10</TopRowBottomPane>
      <ActivePane>2</ActivePane>
    </WorksheetOptions>
  </Worksheet>
</Workbook>
