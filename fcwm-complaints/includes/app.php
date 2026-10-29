<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$appPath = realpath(dirname(__DIR__));
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$basePath = '';
if ($appPath !== false && $documentRoot !== false && str_starts_with(
    strtolower($appPath),
    strtolower($documentRoot . DIRECTORY_SEPARATOR)
)) {
    $basePath = str_replace('\\', '/', substr($appPath, strlen($documentRoot)));
}
define('APP_BASE_URL', rtrim($basePath, '/'));

function url(string $path = ''): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your form session expired. Go back, refresh the page, and try again.');
    }
}

function db_server_connection(): PDO
{
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', DB_NAME)) {
        throw new RuntimeException('The configured database name is invalid.');
    }

    return new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function db(): PDO
{
    static $connection;
    if (!$connection instanceof PDO) {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/', DB_NAME)) {
            throw new RuntimeException('The configured database name is invalid.');
        }

        $connection = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $connection;
}

function database_is_installed(): bool
{
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function require_installed(): void
{
    if (!database_is_installed()) {
        redirect('setup.php');
    }
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $query = db()->prepare(
        'SELECT id, full_name, email, matric_number, role, department_id
         FROM users WHERE id = :id AND is_active = 1'
    );
    $query->execute(['id' => (int) $userId]);
    $user = $query->fetch() ?: null;
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

function require_guest(): void
{
    $user = current_user();
    if ($user !== null) {
        redirect($user['role'] . '/dashboard.php');
    }
}

function ticket_statuses(): array
{
    return [
        'new' => 'New',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'awaiting_student' => 'Awaiting student',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

function status_badge(string $status): string
{
    $labels = ticket_statuses();
    $label = $labels[$status] ?? 'Unknown';
    return '<span class="status-badge status-' . e($status) . '">' . e($label) . '</span>';
}

function email_student(int $studentId, string $subject, string $body): bool
{
    if (!MAIL_ENABLED) {
        error_log('FCWM email notification skipped: FCWM_MAIL_ENABLED is disabled.');
        return false;
    }

    $query = db()->prepare('SELECT full_name, email FROM users WHERE id = :id AND role = "student"');
    $query->execute(['id' => $studentId]);
    $student = $query->fetch();
    if (!$student) {
        error_log('FCWM email notification failed: student account was not found.');
        return false;
    }

    $safeSubject = str_replace(["\r", "\n"], '', $subject);
    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $sent = @mail($student['email'], $safeSubject, $body, implode("\r\n", $headers));
    if (!$sent) {
        error_log('FCWM email notification failed for ticket update to ' . $student['email'] . '. Check XAMPP SMTP/sendmail configuration.');
    }
    return $sent;
}

function email_ticket_update(array $ticket, string $subject, string $message): bool
{
    $body = "Hello,\n\n" . $message
        . "\n\nReference: " . $ticket['reference']
        . "\nTrack your ticket: " . url('track.php')
        . "\n\nFederal College of Wildlife Management Student Support";
    return email_student((int) $ticket['created_by'], $subject, $body);
}

function ticket_accessible_to(array $ticket, array $user): bool
{
    return match ($user['role']) {
        'admin' => true,
        'department' => $ticket['assigned_department_id'] !== null
            && (int) $ticket['assigned_department_id'] === (int) $user['department_id'],
        'student' => (int) $ticket['created_by'] === (int) $user['id'],
        default => false,
    };
}

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $role = $user['role'] ?? 'guest';
    $menus = match ($role) {
        'admin' => [
            ['Tickets', 'admin/dashboard.php', 'tickets'],
            ['Department accounts', 'admin/departments.php', 'departments'],
        ],
        'department' => [
            ['Department cases', 'department/dashboard.php', 'tickets'],
        ],
        'student' => [
            ['My tickets', 'student/dashboard.php', 'tickets'],
            ['Create a ticket', 'student/new-ticket.php', 'new-ticket'],
        ],
        default => [
            ['Track a ticket', 'track.php', 'track'],
        ],
    };
    $roleLabel = match ($role) {
        'admin' => 'Administrator',
        'department' => 'Department staff',
        'student' => 'Student',
        default => 'Student support',
    };
    $titleEscaped = e($title);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#183c32">
      <title><?= $titleEscaped ?> · FCWM Student Support</title>
      <link rel="stylesheet" href="<?= e(url('../bootstrap/dist/assets/css/plugins/bootstrap.min.css')) ?>">
      <link rel="stylesheet" href="<?= e(url('../bootstrap/dist/assets/css/style.css')) ?>">
      <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    </head>
    <body class="portal portal-<?= e($role) ?>">
      <header class="topbar">
        <a class="brand" href="<?= e(url($user ? $role . '/dashboard.php' : 'index.php')) ?>">
          <span class="brand-mark" aria-hidden="true">F</span>
          <span><strong>FCWM</strong><small>Student Support</small></span>
        </a>
        <div class="topbar-right">
          <span class="role-pill"><?= e($roleLabel) ?></span>
          <?php if ($user): ?>
            <span class="user-name"><?= e($user['full_name']) ?></span>
            <form method="post" action="<?= e(url('logout.php')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn-sm btn-outline-secondary" type="submit">Sign out</button>
            </form>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('login.php')) ?>">Sign in</a>
          <?php endif; ?>
        </div>
      </header>
      <div class="app-shell">
        <aside class="sidebar" aria-label="<?= e($roleLabel) ?> navigation">
          <div class="sidebar-caption"><?= e($roleLabel) ?> workspace</div>
          <nav>
            <?php foreach ($menus as [$label, $href, $key]): ?>
              <a class="sidebar-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
                <span class="nav-dot" aria-hidden="true"></span><?= e($label) ?>
              </a>
            <?php endforeach; ?>
            <?php if ($role === 'guest'): ?>
              <a class="sidebar-link" href="<?= e(url('register.php')) ?>"><span class="nav-dot" aria-hidden="true"></span>Student registration</a>
            <?php endif; ?>
          </nav>
          <div class="sidebar-help">
            <span class="help-icon" aria-hidden="true">?</span>
            <strong>Need help?</strong>
            <p>Create a support ticket and keep your reference number safe.</p>
            <?php if ($role === 'student'): ?>
              <a href="<?= e(url('student/new-ticket.php')) ?>">Open a ticket</a>
            <?php else: ?>
              <a href="<?= e(url('track.php')) ?>">Track a ticket</a>
            <?php endif; ?>
          </div>
        </aside>
        <main class="main-content">
          <div class="page-heading">
            <div>
              <div class="eyebrow">FEDERAL COLLEGE OF WILDLIFE MANAGEMENT</div>
              <h1><?= $titleEscaped ?></h1>
            </div>
            <?php if ($role === 'student'): ?>
              <a class="btn btn-primary" href="<?= e(url('student/new-ticket.php')) ?>">+ New ticket</a>
            <?php endif; ?>
          </div>
          <?php foreach (take_flashes() as $notice): ?>
            <div class="alert alert-<?= e($notice['type']) ?> alert-dismissible fade show" role="alert">
              <?= e($notice['message']) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    $user = current_user();
    $footerNote = match ($user['role'] ?? 'guest') {
          'admin' => 'Administrator console · Route tickets to the right team.',
          'department' => 'Department workspace · Only your routed cases are shown.',
          'student' => 'Student workspace · Keep your ticket reference private.',
          default => 'Student support · Track requests with your reference number.',
    };
    ?>
        </main>
        </div>
        <footer class="footer">
          <span>© <?= date('Y') ?> Federal College of Wildlife Management</span>
          <span><?= e($footerNote) ?></span>
      </footer>
      <script src="<?= e(url('../bootstrap/dist/assets/js/plugins/bootstrap.min.js')) ?>"></script>
      <script src="<?= e(url('assets/js/app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Service error</title>'
        . '<body style="font:16px sans-serif;max-width:42rem;margin:5rem auto;padding:1rem">'
        . '<h1>We could not complete that request</h1>'
        . '<p>The error has been logged. Check that MySQL is running in XAMPP and that the database is installed.</p>'
        . '<p><a href="' . e(url('setup.php')) . '">Open database setup</a></p></body></html>';
});
