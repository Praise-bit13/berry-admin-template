<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Initial setup is available only from this computer.');
}

$setupError = '';
$adminExists = false;
try {
    $server = db_server_connection();
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $schema = [
        "CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            matric_number VARCHAR(50) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('student', 'admin', 'department') NOT NULL,
            department_id INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_users_department FOREIGN KEY (department_id)
                REFERENCES departments(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            INDEX idx_users_role_department (role, department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference VARCHAR(40) NOT NULL UNIQUE,
            created_by INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            assigned_department_id INT UNSIGNED NULL,
            subject VARCHAR(180) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('normal', 'urgent') NOT NULL DEFAULT 'normal',
            status ENUM('new', 'assigned', 'in_progress', 'awaiting_student', 'resolved', 'closed')
                NOT NULL DEFAULT 'new',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_tickets_student FOREIGN KEY (created_by)
                REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_tickets_category FOREIGN KEY (category_id)
                REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_tickets_department FOREIGN KEY (assigned_department_id)
                REFERENCES departments(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            INDEX idx_tickets_creator (created_by, created_at),
            INDEX idx_tickets_department_status (assigned_department_id, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ticket_updates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_updates_ticket FOREIGN KEY (ticket_id)
                REFERENCES tickets(id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_updates_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
            INDEX idx_updates_ticket_created (ticket_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($schema as $statement) {
        db()->exec($statement);
    }

    $departments = [
        'Wildlife Management',
        'Forestry',
        'Fisheries',
        'Environmental Science',
    ];
    $insertDepartment = db()->prepare('INSERT IGNORE INTO departments (name) VALUES (:name)');
    foreach ($departments as $department) {
        $insertDepartment->execute(['name' => $department]);
    }

    $categories = ['Academic', 'Registration', 'Finance', 'Accommodation', 'Facilities', 'IT', 'Other'];
    $insertCategory = db()->prepare('INSERT IGNORE INTO categories (name) VALUES (:name)');
    foreach ($categories as $category) {
        $insertCategory->execute(['name' => $category]);
    }

    $adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
} catch (Throwable $exception) {
    error_log('FCWM setup failed: ' . $exception->getMessage());
    $setupError = 'Could not connect to MySQL or create the database. Start MySQL in XAMPP and check the database settings in config/config.php.';
}

if ($setupError === '' && $adminExists) {
    flash('success', 'The portal is already installed. Sign in to continue.');
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupError === '' && !$adminExists) {
    verify_csrf();
    $adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    if ($adminExists) {
        flash('success', 'An administrator was already created. Sign in to continue.');
        redirect('login.php');
    }

    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($name === '' || strlen($name) > 150 || preg_match('/[\p{C}]/u', $name)) {
        $setupError = 'Enter a valid administrator name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $setupError = 'Enter a valid administrator email address.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $setupError = 'Use a password between 12 and 72 characters.';
    } elseif (!hash_equals($password, $confirmation)) {
        $setupError = 'The passwords do not match.';
    } else {
        $insertAdmin = db()->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:full_name, :email, :password_hash, 'admin')"
        );
        try {
            $insertAdmin->execute([
                'full_name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            flash('success', 'Administrator created. Sign in with the account you just set up.');
            redirect('login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $setupError = 'That email address already belongs to an account. Use a different email address.';
            } else {
                throw $exception;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install · FCWM Student Support</title>
  <link rel="stylesheet" href="<?= e(url('../bootstrap/dist/assets/css/plugins/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="portal">
  <header class="topbar">
    <a class="brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark">F</span><span><strong>FCWM</strong><small>Student Support</small></span></a>
  </header>
  <main class="main-content">
    <section class="card auth-card">
      <div class="card-body">
        <div class="eyebrow">ONE-TIME LOCAL SETUP</div>
        <h1 class="h3 mt-2">Set up the support portal</h1>
        <p class="text-muted">This creates the <code><?= e(DB_NAME) ?></code> database and the initial departments and ticket topics using the XAMPP MySQL settings.</p>
        <?php if ($setupError !== ''): ?>
          <div class="alert alert-danger" role="alert"><?= e($setupError) ?></div>
        <?php endif; ?>
        <?php if ($setupError === ''): ?>
          <div class="alert alert-success" role="status">Database and starter data are ready. Create the first administrator below.</div>
          <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="full_name">Administrator full name</label>
              <input class="form-control" id="full_name" name="full_name" maxlength="150" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="email">Email address</label>
              <input class="form-control" type="email" id="email" name="email" maxlength="190" autocomplete="email" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="password">Password</label>
              <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
              <div class="form-hint small mt-1">Use at least 12 characters. This setup page works only from this computer and closes after an administrator is created.</div>
            </div>
            <div class="mb-4">
              <label class="form-label" for="password_confirmation">Confirm password</label>
              <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary" type="submit">Create administrator</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
