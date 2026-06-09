<?php
$user = getCurrentUser();
$role = $user['role'] ?? 'etudiant';

if (!function_exists('isActive')) {
    function isActive(string ...$paths): string {
        $uri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
        foreach ($paths as $path) {
            if (str_contains($uri, strtolower($path))) return ' active';
        }
        return '';
    }
}
if (!function_exists('isExact')) {
    function isExact(string $path): string {
        $uri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
        return str_ends_with($uri, strtolower($path)) ? ' active' : '';
    }
}
if (!function_exists('sectionOpen')) {
    function sectionOpen(string ...$paths): bool {
        $uri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
        foreach ($paths as $path) {
            if (str_contains($uri, strtolower($path))) return true;
        }
        return false;
    }
}

// Couleurs et libellés par rôle
$roleConfig = [
    'superadmin'   => ['color' => '#6200ea', 'label' => 'Super Administrateur', 'icon' => 'fa-crown'],
    'admin'        => ['color' => '#1a73e8', 'label' => 'Administrateur',       'icon' => 'fa-user-shield'],
    'directeur'    => ['color' => '#5c35a0', 'label' => 'Directeur',            'icon' => 'fa-user-tie'],
    'scolarite'    => ['color' => '#0097a7', 'label' => 'Scolarité',             'icon' => 'fa-user-cog'],
    'enseignant'   => ['color' => '#34a853', 'label' => 'Enseignant',            'icon' => 'fa-chalkboard-teacher'],
    'comptable'    => ['color' => '#f57c00', 'label' => 'Comptable',             'icon' => 'fa-calculator'],
    'etudiant'     => ['color' => '#ea4335', 'label' => 'Étudiant',              'icon' => 'fa-user-graduate'],
    'coordinateur' => ['color' => '#2e7d32', 'label' => 'Coordinateur',          'icon' => 'fa-sitemap'],
    'assistante'   => ['color' => '#c0392b', 'label' => 'Assistante Direction',  'icon' => 'fa-envelope-open-text'],
];
$rc = $roleConfig[$role] ?? $roleConfig['etudiant'];

// Helper: build a collapsible sidebar group
// $id: unique CSS id, $label: section title, $icon: FA icon class, $paths: array of URI fragments to check for auto-open, $links: HTML string of nav-links
function sidebarGroup(string $id, string $label, string $icon, array $paths, string $links): string {
    $open     = sectionOpen(...$paths);
    $showCls  = $open ? ' show' : '';
    $expanded = $open ? 'true' : 'false';
    return <<<HTML
<div class="sidebar-group">
  <a class="sidebar-section-toggle" data-bs-toggle="collapse" href="#sg-{$id}" aria-expanded="{$expanded}">
    <span><i class="fas {$icon} me-1" style="font-size:.65rem;opacity:.7"></i> {$label}</span>
    <i class="fas fa-chevron-down sidebar-chevron"></i>
  </a>
  <div class="collapse{$showCls}" id="sg-{$id}">
    {$links}
  </div>
</div>
HTML;
}
?>
<nav id="sidebar">
  <!-- Brand -->
  <?php
    $sidebarLogo = getLogoUrl();
    $sidebarNom  = isSuperAdmin() && !getEcoleId()
                   ? 'E-EDU PRO'
                   : getParam('etablissement_nom', 'E-EDU PRO');
    $sidebarSlogan = isSuperAdmin() && !getEcoleId()
                   ? 'Plateforme multi-établissements'
                   : getParam('etablissement_slogan', '');
  ?>
  <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-brand">
    <?php if ($sidebarLogo && getEcoleId()): ?>
      <img src="<?= h($sidebarLogo) ?>" alt="Logo"
           style="width:38px;height:38px;object-fit:contain;border-radius:8px;background:#fff;padding:3px;flex-shrink:0">
    <?php elseif (isSuperAdmin()): ?>
      <div class="brand-icon" style="background:linear-gradient(135deg,#6200ea,#9c27b0)"><i class="fas fa-crown"></i></div>
    <?php else: ?>
      <div class="brand-icon"><i class="fas fa-school"></i></div>
    <?php endif; ?>
    <div class="brand-text">
      <strong style="white-space:normal;word-break:break-word;line-height:1.2"><?= h(mb_strtoupper($sidebarNom)) ?></strong>
      <small><?= h($sidebarSlogan) ?></small>
    </div>
  </a>

  <!-- Contexte école (superadmin) -->
  <?php if (isSuperAdmin()): ?>
  <div style="padding:.25rem .75rem;margin:0 .5rem .25rem;">
    <?php $ecoleCtx = getCurrentEcole(); ?>
    <?php if ($ecoleCtx): ?>
    <div style="background:#6200ea22;border:1px solid #6200ea44;border-radius:8px;padding:.4rem .7rem;font-size:.72rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem">
      <span style="color:#9c27b0;font-weight:600;word-break:break-word;line-height:1.3">
        <i class="fas fa-school me-1"></i><?= h($ecoleCtx['nom']) ?>
      </span>
      <a href="<?= APP_URL ?>/modules/superadmin/switch_ecole.php?id=0&csrf=<?= h(generateCsrfToken()) ?>"
         style="color:#c62828;font-size:.68rem;white-space:nowrap" title="Quitter cette école">
        <i class="fas fa-times"></i>
      </a>
    </div>
    <?php else: ?>
    <div style="background:#6200ea11;border:1px dashed #6200ea44;border-radius:8px;padding:.4rem .7rem;font-size:.72rem;color:#9e9e9e;text-align:center">
      <i class="fas fa-globe me-1"></i>Vue globale
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Role badge -->
  <div style="padding:.5rem 1rem .25rem;margin:0 .5rem;">
    <div style="background:<?= $rc['color'] ?>22;border:1px solid <?= $rc['color'] ?>44;border-radius:8px;padding:.45rem .75rem;display:flex;align-items:center;gap:.6rem;">
      <i class="fas <?= $rc['icon'] ?>" style="color:<?= $rc['color'] ?>;font-size:.85rem"></i>
      <span style="color:<?= $rc['color'] ?>;font-size:.78rem;font-weight:600"><?= $rc['label'] ?></span>
    </div>
  </div>

  <!-- Navigation -->
  <div style="overflow-y:auto;flex:1;padding-bottom:1rem;margin-top:.5rem;">

    <!-- ===== ACCUEIL (tous les rôles) ===== -->
    <a href="<?= APP_URL ?>/dashboard.php" class="nav-link<?= isExact('/dashboard.php') ?>" style="margin-bottom:.25rem">
      <i class="fas fa-tachometer-alt"></i> Tableau de bord
    </a>

    <!-- ===== SUPERADMIN ===== -->
    <?php if ($role === 'superadmin'): ?>

    <?= sidebarGroup('sa-ecoles', 'Établissements', 'fa-school',
        ['/superadmin/index', '/superadmin/ecole_form'],
        '<a href="' . APP_URL . '/modules/superadmin/index.php" class="nav-link' . isActive('/superadmin/index') . '"><i class="fas fa-list"></i> Toutes les écoles</a>'
      . '<a href="' . APP_URL . '/modules/superadmin/ecole_form.php" class="nav-link' . isActive('/superadmin/ecole_form') . '"><i class="fas fa-plus"></i> Nouvelle école</a>'
    ) ?>

    <?= sidebarGroup('sa-admins', 'Administration', 'fa-users-cog',
        ['/superadmin/licences', '/superadmin/superadmins', '/superadmin/profil'],
        '<a href="' . APP_URL . '/modules/superadmin/licences.php" class="nav-link' . isActive('/superadmin/licences') . '"><i class="fas fa-key"></i> Licences</a>'
      . '<a href="' . APP_URL . '/modules/superadmin/superadmins.php" class="nav-link' . isActive('/superadmin/superadmins') . '"><i class="fas fa-crown"></i> Super Administrateurs</a>'
      . '<a href="' . APP_URL . '/modules/superadmin/profil.php" class="nav-link' . isActive('/superadmin/profil') . '"><i class="fas fa-user-circle"></i> Mon profil</a>'
    ) ?>

    <?php if (getEcoleId()): // SuperAdmin with school context — show school menus ?>

    <?= sidebarGroup('sa-admin', 'Administration école', 'fa-cogs',
        ['/administration/'],
        '<a href="' . APP_URL . '/modules/administration/filieres.php" class="nav-link' . isActive('/administration/filieres') . '"><i class="fas fa-layer-group"></i> Filières &amp; Niveaux</a>'
      . '<a href="' . APP_URL . '/modules/administration/annees.php" class="nav-link' . isActive('/administration/annees') . '"><i class="fas fa-calendar-alt"></i> Années académiques</a>'
      . '<a href="' . APP_URL . '/modules/administration/utilisateurs.php" class="nav-link' . isActive('/administration/utilisateurs') . '"><i class="fas fa-users-cog"></i> Utilisateurs</a>'
      . '<a href="' . APP_URL . '/modules/administration/parametres.php" class="nav-link' . isActive('/administration/parametres') . '"><i class="fas fa-cog"></i> Paramètres école</a>'
    ) ?>

    <?= sidebarGroup('sa-etudiants', 'Apprenants', 'fa-user-graduate',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index') . '"><i class="fas fa-user-graduate"></i> Liste des étudiants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/add.php" class="nav-link' . isActive('/etudiants/add') . '"><i class="fas fa-user-plus"></i> Nouvel étudiant</a>'
    ) ?>

    <?php endif; // end school context ?>

    <!-- ===== ADMIN ===== -->
    <?php elseif ($role === 'admin'): ?>

    <?= sidebarGroup('admin-admin', 'Administration', 'fa-cogs',
        ['/administration/'],
        '<a href="' . APP_URL . '/modules/administration/filieres.php" class="nav-link' . isActive('/administration/filieres') . '"><i class="fas fa-layer-group"></i> Filières &amp; Niveaux</a>'
      . '<a href="' . APP_URL . '/modules/administration/annees.php" class="nav-link' . isActive('/administration/annees') . '"><i class="fas fa-calendar-alt"></i> Années académiques</a>'
      . '<a href="' . APP_URL . '/modules/administration/utilisateurs.php" class="nav-link' . isActive('/administration/utilisateurs') . '"><i class="fas fa-users-cog"></i> Utilisateurs</a>'
      . '<a href="' . APP_URL . '/modules/administration/parametres.php" class="nav-link' . isActive('/administration/parametres') . '"><i class="fas fa-cog"></i> Paramètres</a>'
    ) ?>

    <?= sidebarGroup('admin-etudiants', 'Apprenants', 'fa-user-graduate',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index','/etudiants/view','/etudiants/edit','/etudiants/paiements','/etudiants/delete') . '"><i class="fas fa-user-graduate"></i> Liste des étudiants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/add.php" class="nav-link' . isActive('/etudiants/add') . '"><i class="fas fa-user-plus"></i> Nouvel étudiant</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/absences.php" class="nav-link' . isActive('/etudiants/absences','/etudiants/rapport_absences') . '"><i class="fas fa-calendar-times"></i> Absences</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/promotion.php" class="nav-link' . isActive('/etudiants/promotion') . '"><i class="fas fa-level-up-alt"></i> Passage en classe sup.</a>'
    ) ?>

    <?= sidebarGroup('admin-enseignants', 'Enseignants', 'fa-chalkboard-teacher',
        ['/enseignants/', '/salaires_enseignants'],
        '<a href="' . APP_URL . '/modules/enseignants/index.php" class="nav-link' . isActive('/enseignants/index','/enseignants/edit','/enseignants/paiements') . '"><i class="fas fa-chalkboard-teacher"></i> Liste des enseignants</a>'
      . '<a href="' . APP_URL . '/modules/enseignants/add.php" class="nav-link' . isActive('/enseignants/add') . '"><i class="fas fa-user-tie"></i> Nouvel enseignant</a>'
      . '<a href="' . APP_URL . '/modules/administration/salaires_enseignants.php" class="nav-link' . isActive('/salaires_enseignants') . '"><i class="fas fa-money-bill-wave"></i> Calcul des salaires</a>'
    ) ?>

    <?= sidebarGroup('admin-pedagogie', 'Pédagogie', 'fa-book-open',
        ['/pedagogique/', '/emploi_du_temps/'],
        '<a href="' . APP_URL . '/modules/pedagogique/matieres.php" class="nav-link' . isActive('/pedagogique/matieres') . '"><i class="fas fa-book-open"></i> Matières / Modules</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/ue.php" class="nav-link' . isActive('/pedagogique/ue') . '"><i class="fas fa-layer-group"></i> Unités d\'Ens. (UE)</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/notes.php" class="nav-link' . isActive('/pedagogique/notes') . '"><i class="fas fa-eye"></i> Notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/bulletins.php" class="nav-link' . isActive('/pedagogique/bulletins') . '"><i class="fas fa-file-alt"></i> Relevés de notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/pv.php" class="nav-link' . isActive('/pedagogique/pv') . '"><i class="fas fa-file-contract"></i> PV Supérieur</a>'
      . '<a href="' . APP_URL . '/modules/emploi_du_temps/index.php" class="nav-link' . isActive('/emploi_du_temps/') . '"><i class="fas fa-calendar-week"></i> Emplois du temps</a>'
    ) ?>

    <?= sidebarGroup('admin-compta', 'Comptabilité', 'fa-coins',
        ['/comptabilite/'],
        '<a href="' . APP_URL . '/modules/comptabilite/recettes.php" class="nav-link' . isActive('/comptabilite/recettes') . '"><i class="fas fa-arrow-circle-down" style="color:#34a853"></i> Recettes</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/depenses.php" class="nav-link' . isActive('/comptabilite/depenses') . '"><i class="fas fa-arrow-circle-up" style="color:#ea4335"></i> Dépenses</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/rapports.php" class="nav-link' . isActive('/comptabilite/rapports') . '"><i class="fas fa-chart-bar"></i> Rapports financiers</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/recu.php" class="nav-link' . isActive('/comptabilite/recu') . '"><i class="fas fa-receipt"></i> Reçus de paiement</a>'
    ) ?>

    <!-- ===== DIRECTEUR ===== -->
    <?php elseif ($role === 'directeur'): ?>

    <?= sidebarGroup('dir-admin', 'Vue Établissement', 'fa-building',
        ['/administration/'],
        '<a href="' . APP_URL . '/modules/administration/filieres.php" class="nav-link' . isActive('/administration/filieres') . '"><i class="fas fa-layer-group"></i> Filières &amp; Niveaux</a>'
      . '<a href="' . APP_URL . '/modules/administration/annees.php" class="nav-link' . isActive('/administration/annees') . '"><i class="fas fa-calendar-alt"></i> Années académiques</a>'
    ) ?>

    <?= sidebarGroup('dir-etudiants', 'Apprenants', 'fa-user-graduate',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index','/etudiants/view') . '"><i class="fas fa-user-graduate"></i> Liste des étudiants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/promotion.php" class="nav-link' . isActive('/etudiants/promotion') . '"><i class="fas fa-level-up-alt"></i> Passage en classe sup.</a>'
    ) ?>

    <?= sidebarGroup('dir-enseignants', 'Enseignants', 'fa-chalkboard-teacher',
        ['/enseignants/'],
        '<a href="' . APP_URL . '/modules/enseignants/index.php" class="nav-link' . isActive('/enseignants/') . '"><i class="fas fa-chalkboard-teacher"></i> Liste des enseignants</a>'
    ) ?>

    <?php
      $nbEdtPending = 0;
      try { $nbEdtPending = (int)getDB()->query("SELECT COUNT(*) FROM emplois_du_temps WHERE statut='soumis'")->fetchColumn(); } catch (PDOException $e) {}
      $edtBadge = $nbEdtPending > 0 ? ' <span class="badge bg-warning text-dark ms-auto">'.$nbEdtPending.'</span>' : '';
    ?>
    <?= sidebarGroup('dir-pedagogie', 'Pédagogie', 'fa-book-open',
        ['/pedagogique/', '/emploi_du_temps/'],
        '<a href="' . APP_URL . '/modules/pedagogique/matieres.php" class="nav-link' . isActive('/pedagogique/matieres') . '"><i class="fas fa-book-open"></i> Matières / Modules</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/ue.php" class="nav-link' . isActive('/pedagogique/ue') . '"><i class="fas fa-layer-group"></i> Unités d\'Ens. (UE)</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/bulletins.php" class="nav-link' . isActive('/pedagogique/bulletins') . '"><i class="fas fa-file-alt"></i> Relevés de notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/pv.php" class="nav-link' . isActive('/pedagogique/pv') . '"><i class="fas fa-file-contract"></i> PV Supérieur</a>'
      . '<a href="' . APP_URL . '/modules/emploi_du_temps/index.php" class="nav-link d-flex align-items-center justify-content-between' . isActive('/emploi_du_temps/') . '"><span><i class="fas fa-calendar-week"></i> Emplois du temps</span>' . $edtBadge . '</a>'
    ) ?>

    <!-- ===== SCOLARITÉ ===== -->
    <?php elseif ($role === 'scolarite'): ?>

    <?= sidebarGroup('sco-etudiants', 'Apprenants', 'fa-user-graduate',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index','/etudiants/view','/etudiants/edit','/etudiants/delete') . '"><i class="fas fa-user-graduate"></i> Liste des étudiants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/add.php" class="nav-link' . isActive('/etudiants/add') . '"><i class="fas fa-user-plus"></i> Nouvel étudiant</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/absences.php" class="nav-link' . isActive('/etudiants/absences','/etudiants/rapport_absences') . '"><i class="fas fa-calendar-times"></i> Absences</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/promotion.php" class="nav-link' . isActive('/etudiants/promotion') . '"><i class="fas fa-level-up-alt"></i> Passage en classe sup.</a>'
    ) ?>

    <?= sidebarGroup('sco-pedagogie', 'Pédagogie', 'fa-book-open',
        ['/pedagogique/'],
        '<a href="' . APP_URL . '/modules/pedagogique/notes.php" class="nav-link' . isActive('/pedagogique/notes') . '"><i class="fas fa-edit"></i> Saisie des notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/bulletins.php" class="nav-link' . isActive('/pedagogique/bulletins') . '"><i class="fas fa-file-alt"></i> Relevés de notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/pv.php" class="nav-link' . isActive('/pedagogique/pv') . '"><i class="fas fa-file-contract"></i> PV Supérieur</a>'
    ) ?>

    <!-- ===== ENSEIGNANT ===== -->
    <?php elseif ($role === 'enseignant'): ?>

    <?= sidebarGroup('ens-profil', 'Mon Espace', 'fa-id-card',
        ['/enseignants/edit','/enseignants/view'],
        '<a href="' . APP_URL . '/modules/enseignants/edit.php?id=' . (int)($user['reference_id'] ?? 0) . '" class="nav-link' . isActive('/enseignants/edit','/enseignants/view') . '"><i class="fas fa-id-card"></i> Mon Profil</a>'
    ) ?>

    <?= sidebarGroup('ens-classes', 'Mes Classes', 'fa-users',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index','/etudiants/view') . '"><i class="fas fa-users"></i> Mes Étudiants</a>'
    ) ?>

    <?= sidebarGroup('ens-pedagogie', 'Pédagogie', 'fa-book-open',
        ['/pedagogique/'],
        '<a href="' . APP_URL . '/modules/pedagogique/matieres.php" class="nav-link' . isActive('/pedagogique/matieres') . '"><i class="fas fa-book-open"></i> Mes Matières</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/notes.php" class="nav-link' . isActive('/pedagogique/notes') . '"><i class="fas fa-eye"></i> Notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/bulletins.php" class="nav-link' . isActive('/pedagogique/bulletins') . '"><i class="fas fa-file-alt"></i> Relevés de notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/pv.php" class="nav-link' . isActive('/pedagogique/pv') . '"><i class="fas fa-file-contract"></i> PV Supérieur</a>'
    ) ?>

    <!-- ===== COMPTABLE ===== -->
    <?php elseif ($role === 'comptable'): ?>

    <?= sidebarGroup('cpt-compta', 'Comptabilité', 'fa-coins',
        ['/comptabilite/'],
        '<a href="' . APP_URL . '/modules/comptabilite/recettes.php" class="nav-link' . isActive('/comptabilite/recettes') . '"><i class="fas fa-arrow-circle-down" style="color:#34a853"></i> Recettes</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/depenses.php" class="nav-link' . isActive('/comptabilite/depenses') . '"><i class="fas fa-arrow-circle-up" style="color:#ea4335"></i> Dépenses</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/rapports.php" class="nav-link' . isActive('/comptabilite/rapports') . '"><i class="fas fa-chart-bar"></i> Rapports financiers</a>'
      . '<a href="' . APP_URL . '/modules/comptabilite/recu.php" class="nav-link' . isActive('/comptabilite/recu') . '"><i class="fas fa-receipt"></i> Reçus de paiement</a>'
    ) ?>

    <?php
      $nbDemandesCpt = 0;
      try { $stmtNb = getDB()->query("SELECT COUNT(*) FROM demandes_paiement_enseignant WHERE statut='en_attente'"); $nbDemandesCpt = (int)$stmtNb->fetchColumn(); } catch (PDOException $e) {}
      $cptDemBadge = $nbDemandesCpt > 0 ? ' <span class="badge bg-danger ms-auto">'.$nbDemandesCpt.'</span>' : '';
    ?>
    <?= sidebarGroup('cpt-paiements', 'Paiements', 'fa-money-bill',
        ['/enseignants/','/etudiants/','/demandes_paiement'],
        '<a href="' . APP_URL . '/modules/administration/demandes_paiement.php" class="nav-link d-flex align-items-center justify-content-between' . isActive('/demandes_paiement') . '"><span><i class="fas fa-file-invoice-dollar"></i> Vacations enseignants</span>' . $cptDemBadge . '</a>'
      . '<a href="' . APP_URL . '/modules/enseignants/index.php" class="nav-link' . isActive('/enseignants/') . '"><i class="fas fa-chalkboard-teacher"></i> Paiements enseignants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/') . '"><i class="fas fa-user-graduate"></i> Paiements étudiants</a>'
    ) ?>

    <!-- ===== COORDINATEUR ===== -->
    <?php elseif ($role === 'coordinateur'): ?>

    <?php
      $nbDemandesCoord = 0;
      try { $stmtDC = getDB()->prepare("SELECT COUNT(*) FROM demandes_paiement_enseignant WHERE coordinateur_id=? AND statut='en_attente'"); $stmtDC->execute([$user['id']]); $nbDemandesCoord = (int)$stmtDC->fetchColumn(); } catch (PDOException $e) {}
      $coordPayBadge = $nbDemandesCoord > 0 ? ' <span class="badge bg-warning text-dark ms-auto">'.$nbDemandesCoord.'</span>' : '';
    ?>

    <?= sidebarGroup('coord-section', 'Ma Section', 'fa-user-graduate',
        ['/etudiants/'],
        '<a href="' . APP_URL . '/modules/etudiants/index.php" class="nav-link' . isActive('/etudiants/index','/etudiants/view') . '"><i class="fas fa-user-graduate"></i> Mes Étudiants</a>'
      . '<a href="' . APP_URL . '/modules/etudiants/absences.php" class="nav-link' . isActive('/etudiants/absences','/etudiants/rapport_absences') . '"><i class="fas fa-calendar-times"></i> Absences</a>'
    ) ?>

    <?= sidebarGroup('coord-enseignants', 'Enseignants', 'fa-chalkboard-teacher',
        ['/enseignants/'],
        '<a href="' . APP_URL . '/modules/enseignants/index.php" class="nav-link' . isActive('/enseignants/index','/enseignants/edit') . '"><i class="fas fa-chalkboard-teacher"></i> Liste enseignants</a>'
      . '<a href="' . APP_URL . '/modules/enseignants/add.php" class="nav-link' . isActive('/enseignants/add') . '"><i class="fas fa-user-tie"></i> Ajouter enseignant</a>'
    ) ?>

    <?= sidebarGroup('coord-pedagogie', 'Pédagogie', 'fa-book-open',
        ['/pedagogique/', '/emploi_du_temps/'],
        '<a href="' . APP_URL . '/modules/pedagogique/ue.php" class="nav-link' . isActive('/pedagogique/ue') . '"><i class="fas fa-layer-group"></i> Unités d\'Ens. (UE)</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/matieres.php" class="nav-link' . isActive('/pedagogique/matieres') . '"><i class="fas fa-book-open"></i> Matières / Modules</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/notes.php" class="nav-link' . isActive('/pedagogique/notes') . '"><i class="fas fa-eye"></i> Notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/bulletins.php" class="nav-link' . isActive('/pedagogique/bulletins') . '"><i class="fas fa-file-alt"></i> Relevés de notes</a>'
      . '<a href="' . APP_URL . '/modules/pedagogique/pv.php" class="nav-link' . isActive('/pedagogique/pv') . '"><i class="fas fa-file-contract"></i> PV Supérieur</a>'
      . '<a href="' . APP_URL . '/modules/emploi_du_temps/index.php" class="nav-link' . isActive('/emploi_du_temps/') . '"><i class="fas fa-calendar-week"></i> Emplois du temps</a>'
    ) ?>

    <?= sidebarGroup('coord-suivi', 'Suivi &amp; Paiements', 'fa-tasks',
        ['/suivi_cours', '/demandes_paiement', '/salaires_enseignants'],
        '<a href="' . APP_URL . '/modules/administration/suivi_cours.php" class="nav-link' . isActive('/suivi_cours') . '"><i class="fas fa-tasks"></i> Suivi des cours</a>'
      . '<a href="' . APP_URL . '/modules/administration/salaires_enseignants.php" class="nav-link' . isActive('/salaires_enseignants') . '"><i class="fas fa-money-bill-wave"></i> Calcul des salaires</a>'
      . '<a href="' . APP_URL . '/modules/administration/demandes_paiement.php" class="nav-link d-flex align-items-center justify-content-between' . isActive('/demandes_paiement') . '"><span><i class="fas fa-file-invoice-dollar"></i> Demandes paiement</span>' . $coordPayBadge . '</a>'
    ) ?>

    <!-- ===== ASSISTANTE DE DIRECTION ===== -->
    <?php elseif ($role === 'assistante'): ?>

    <?= sidebarGroup('ast-courriers', 'Courriers', 'fa-envelope-open-text',
        ['/courriers/'],
        '<a href="' . APP_URL . '/modules/courriers/depart.php" class="nav-link' . isActive('/courriers/depart') . '"><i class="fas fa-paper-plane"></i> Courriers Départ</a>'
      . '<a href="' . APP_URL . '/modules/courriers/arrivee.php" class="nav-link' . isActive('/courriers/arrivee') . '"><i class="fas fa-inbox"></i> Courriers Arrivée</a>'
    ) ?>

    <?php endif; ?>

  </div>

  <!-- Sidebar Footer -->
  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/logout.php" class="nav-link" style="margin:0;color:#ff7675;">
      <i class="fas fa-sign-out-alt"></i> Déconnexion
    </a>
    <div style="font-size:.65rem;color:rgba(255,255,255,.3);margin-top:.5rem;padding:0 .4rem;">
      EDUPRO v2.0 &copy; <?= date('Y') ?>
    </div>
  </div>
</nav>
