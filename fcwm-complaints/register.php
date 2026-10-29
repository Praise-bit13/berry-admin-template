<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';
require_installed();
require_guest();

$departments = db()->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $matric = strtoupper(trim((string) ($_POST['matric_number'] ?? '')));
    $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($name === '' || strlen($name) > 150 || preg_match('/[\p{C}]/u', $name)) {
        $error = 'Enter a valid full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif ($matric === '' || strlen($matric) > 50 || !preg_match('/\A[A-Z0-9\/-]+\z/', $matric)) {
        $error = 'Enter a valid matric number using letters, numbers, slashes, or hyphens.';
    } elseif (!$departmentId || !in_array((int) $departmentId, array_map(
        static fn(array $department): int => (int) $department['id'],
        $departments
    ), true)) {
        $error = 'Choose a department from the list.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Use a password between 12 and 72 characters.';
    } elseif (!hash_equals($password, $confirmation)) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $insert = db()->prepare(
                "INSERT INTO users (full_name, email, matric_number, password_hash, role, department_id)
                 VALUES (:full_name, :email, :matric_number, :password_hash, 'student', :department_id)"
            );
            $insert->execute([
                'full_name' => $name,
                'email' => $email,
                'matric_number' => $matric,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'department_id' => (int) $departmentId,
            ]);
            flash('success', 'Your student account has been created. You can now sign in.');
            redirect('login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That email address or matric number is already registered.';
            } else {
                throw $exception;
            }
        }
    }
}

render_header('Create student account');
?>
<section class="card auth-card">
  <div class="card-body">
    <div class="eyebrow">STUDENT REGISTRATION</div>
    <h2 class="h4 mt-2">Create your account</h2>
    <p class="text-muted">Your account lets you submit tickets and view the full support conversation.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="full_name">Full name</label>
        <input class="form-control" id="full_name" name="full_name" maxlength="150" autocomplete="name" value="<?= e($_POST['full_name'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" type="email" id="email" name="email" maxlength="190" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="matric_number">Matric number</label>
        <input class="form-control" id="matric_number" name="matric_number" maxlength="50" value="<?= e($_POST['matric_number'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="department_id">Department</label>
        <select class="form-select" id="department_id" name="department_id" required>
          <option value="">Select your department</option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>" <?= (string) ($_POST['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
        <div class="form-hint small mt-1">Use at least 12 characters.</div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password_confirmation">Confirm password</label>
        <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Create account</button>
    </form>
  </div>
</section>
<?php render_footer(); ?>
