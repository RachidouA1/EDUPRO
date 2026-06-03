<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = sanitize($_POST['identifiant'] ?? '');
    $password    = $_POST['password'] ?? '';

    if (empty($identifiant) || empty($password)) {
        $error = 'Veuillez renseigner tous les champs.';
    } elseif (login($identifiant, $password)) {
        redirect('/dashboard.php');
    } else {
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion – E-EDU PRO</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">

<div class="login-card">
  <div class="text-center mb-3">
    <i class="fas fa-graduation-cap" style="font-size:2.5rem;color:#1a73e8"></i>
  </div>
  <h2 class="text-center fw-bold mb-1" style="color:#0f2d5c">E-EDU PRO</h2>
  <p class="text-center text-muted mb-4" style="font-size:.88rem">
    Plateforme de Gestion Scolaire<br>
    <small>Multi-établissements</small>
  </p>

  <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
      <i class="fas fa-exclamation-circle"></i>
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <div class="mb-3">
      <label class="form-label">Adresse email</label>
      <div class="input-group">
        <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
        <input type="email" name="identifiant" class="form-control"
               placeholder="email@epsi.sn"
               value="<?= h($_POST['identifiant'] ?? '') ?>" required autofocus autocomplete="username">
      </div>
    </div>
    <div class="mb-4">
      <label class="form-label">Mot de passe</label>
      <div class="input-group">
        <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
        <input type="password" name="password" id="passwordInput" class="form-control" placeholder="••••••••" required>
        <button type="button" class="input-group-text" onclick="togglePassword()">
          <i class="fas fa-eye text-muted" id="eyeIcon"></i>
        </button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
      <i class="fas fa-sign-in-alt me-2"></i>Se connecter
    </button>
  </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
  const input = document.getElementById('passwordInput');
  const icon  = document.getElementById('eyeIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}
</script>
</body>
</html>
