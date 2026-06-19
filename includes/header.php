<?php
// $pageTitle must be set before including this file
$pageTitle = $pageTitle ?? 'EDUPRO';
$user = getCurrentUser();
$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> – EDUPRO</title>
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 6 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <!-- Custom CSS -->
  <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
  <!-- Dynamic theme (from parametres) -->
  <?php
  $themePrimary = getParam('theme_couleur_primaire', '#1a73e8');
  $themeSidebar = getParam('theme_couleur_sidebar',  '#0f2d5c');
  // Valider les codes hex pour éviter toute injection CSS
  if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $themePrimary)) $themePrimary = '#1a73e8';
  if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $themeSidebar))  $themeSidebar = '#0f2d5c';
  ?>
  <style>
    :root {
      --primary:      <?= $themePrimary ?>;
      --sidebar-bg:   <?= $themeSidebar ?>;
      --sidebar-active: <?= $themePrimary ?>;
    }
    .btn-primary { background-color: <?= $themePrimary ?> !important; border-color: <?= $themePrimary ?> !important; }
    .btn-primary:hover { filter: brightness(.9); }
    .btn-outline-primary { color: <?= $themePrimary ?> !important; border-color: <?= $themePrimary ?> !important; }
    .btn-outline-primary:hover { background-color: <?= $themePrimary ?> !important; color: #fff !important; }
    .text-primary { color: <?= $themePrimary ?> !important; }
    .bg-primary   { background-color: <?= $themePrimary ?> !important; }
    .badge.bg-primary { background-color: <?= $themePrimary ?> !important; }
  </style>
  <?php if (isset($extraHead)) echo $extraHead; ?>
  <script>window.APP_URL = '<?= APP_URL ?>';</script>
</head>
<body>
<div class="wrapper">
  <!-- Sidebar -->
  <?php include APP_ROOT . '/includes/sidebar.php'; ?>

  <!-- Main Content -->
  <div id="main-content">
    <!-- Topbar -->
    <div class="topbar">
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-sm btn-light d-md-none" id="sidebarToggle">
          <i class="fas fa-bars"></i>
        </button>
        <div>
          <h1 class="page-title"><?= h($pageTitle) ?></h1>
          <?php if (isset($breadcrumb)): ?>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php"><i class="fas fa-home"></i></a></li>
              <?php foreach ($breadcrumb as $label => $url): ?>
                <?php if ($url): ?>
                  <li class="breadcrumb-item"><a href="<?= h($url) ?>"><?= h($label) ?></a></li>
                <?php else: ?>
                  <li class="breadcrumb-item active"><?= h($label) ?></li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </nav>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-right">
        <?php $annee = getActiveAnnee(); if ($annee): ?>
          <span class="badge bg-primary d-none d-md-inline-flex align-items-center gap-1">
            <i class="fas fa-calendar-alt"></i> <?= h($annee['libelle']) ?>
          </span>
        <?php endif; ?>
        <div class="dropdown">
          <div class="user-badge dropdown-toggle" data-bs-toggle="dropdown">
            <div class="user-avatar"><?= strtoupper(substr($user['prenom'] ?? 'A', 0, 1) . substr($user['nom'] ?? '', 0, 1)) ?></div>
            <span class="d-none d-md-inline"><?= h(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?></span>
          </div>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><h6 class="dropdown-header"><?= h($user['email'] ?? '') ?></h6></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <span class="dropdown-item-text">
                <span class="badge role-badge-<?= h($user['role']) ?>"><?= ucfirst(h($user['role'])) ?></span>
              </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <?php if (isSuperAdmin()): ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/superadmin/profil.php"><i class="fas fa-user-circle me-2"></i>Mon profil</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Content Area -->
    <div class="content-area">
<?php showFlash(); ?>
