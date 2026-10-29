<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';
require_installed();
require_guest();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $query = db()->prepare(
        'SELECT id, password_hash, role FROM users
         WHERE email = :email AND is_active = 1 LIMIT 1'
    );
    $query->execute(['email' => $email]);
    $account = $query->fetch();
    if ($account && strlen($password) <= 72 && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        redirect($account['role'] . '/dashboard.php');
    }
    $error = 'The email or password was not recognized.';
}

render_header('Sign in');
?>
<section class="card auth-card">
  <div class="card-body">
    <div class="eyebrow">STUDENT · DEPARTMENT · ADMIN</div>
    <h2 class="h4 mt-2">Welcome back</h2>
    <p class="text-muted">Sign in to manage your support requests.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" type="email" id="email" name="email" maxlength="190" autocomplete="username" required>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Sign in</button>
    </form>
    <p class="small mt-3 mb-0">New student? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    <p class="small mt-2 mb-0">Have a reference number? <a href="<?= e(url('track.php')) ?>">Track your ticket</a></p>
  </div>
</section>
<?php render_footer(); ?>
