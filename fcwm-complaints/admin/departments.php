<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_installed();
require_role('admin');

$departments = db()->query(
    'SELECT d.id, d.name,
            COUNT(u.id) AS account_count
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.id AND u.role = "department" AND u.is_active = 1
     WHERE d.is_active = 1
     GROUP BY d.id, d.name
     ORDER BY d.name'
)->fetchAll();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
    $password = (string) ($_POST['password'] ?? '');
    $departmentIds = array_map(static fn(array $department): int => (int) $department['id'], $departments);

    if ($name === '' || strlen($name) > 150 || preg_match('/[\p{C}]/u', $name)) {
        $error = 'Enter a valid staff member name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid staff email address.';
    } elseif (!$departmentId || !in_array((int) $departmentId, $departmentIds, true)) {
        $error = 'Choose a department from the list.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Use a password between 12 and 72 characters.';
    } else {
        try {
            $insert = db()->prepare(
                "INSERT INTO users (full_name, email, password_hash, role, department_id)
                 VALUES (:full_name, :email, :password_hash, 'department', :department_id)"
            );
            $insert->execute([
                'full_name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'department_id' => (int) $departmentId,
            ]);
            flash('success', 'Department staff account created. Share the login details with the staff member securely.');
            redirect('admin/departments.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That email address is already associated with an account.';
            } else {
                throw $exception;
            }
        }
    }
}

render_header('Department accounts', 'departments');
?>
<div class="row g-4">
  <div class="col-lg-7">
    <section class="card">
      <div class="card-header"><strong>Departments</strong></div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Department</th><th>Active staff accounts</th></tr></thead>
          <tbody>
          <?php foreach ($departments as $department): ?>
            <tr><td><?= e($department['name']) ?></td><td><?= (int) $department['account_count'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <div class="small text-muted mt-3">Department accounts only see tickets routed to their assigned department. Create one account per staff member.</div>
  </div>
  <div class="col-lg-5">
    <section class="card">
      <div class="card-header"><strong>Create department staff account</strong></div>
      <div class="card-body">
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="full_name">Staff member name</label>
            <input class="form-control" id="full_name" name="full_name" maxlength="150" value="<?= e($_POST['full_name'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id" required>
              <option value="">Select a department</option>
              <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>" <?= (string) ($_POST['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Temporary password</label>
            <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <div class="form-hint small mt-1">Use at least 12 characters; send it to the staff member securely.</div>
          </div>
          <button class="btn btn-primary w-100" type="submit">Create account</button>
        </form>
      </div>
    </section>
  </div>
</div>
<?php render_footer(); ?>
