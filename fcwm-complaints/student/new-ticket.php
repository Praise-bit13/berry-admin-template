<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_installed();
$user = require_role('student');

$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $priority = (string) ($_POST['priority'] ?? 'normal');
    $categoryIds = array_map(static fn(array $category): int => (int) $category['id'], $categories);

    if (!$categoryId || !in_array((int) $categoryId, $categoryIds, true)) {
        $error = 'Choose a ticket topic.';
    } elseif ($subject === '' || strlen($subject) > 180) {
        $error = 'Enter a subject of no more than 180 characters.';
    } elseif (strlen($description) < 10 || strlen($description) > 20000) {
        $error = 'Describe the issue in at least 10 characters and no more than 20,000 characters.';
    } elseif (!in_array($priority, ['normal', 'urgent'], true)) {
        $error = 'Choose a valid priority.';
    } else {
        $reference = 'FCWM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(12)));
        $insert = db()->prepare(
            "INSERT INTO tickets (reference, created_by, category_id, subject, description, priority)
             VALUES (:reference, :created_by, :category_id, :subject, :description, :priority)"
        );
        $insert->execute([
            'reference' => $reference,
            'created_by' => $user['id'],
            'category_id' => (int) $categoryId,
            'subject' => $subject,
            'description' => $description,
            'priority' => $priority,
        ]);
        $ticket = ['created_by' => (int) $user['id'], 'reference' => $reference];
        flash('success', 'Ticket submitted. Your reference number is ' . $reference . '. Save it to track this request.');
        if (!email_ticket_update($ticket, 'FCWM ticket received: ' . $reference, 'We received your request: ' . $subject . '.')) {
            flash('warning', 'Your ticket was saved, but we could not send the email notification. You can still track updates in the portal.');
        }
        redirect('ticket.php?id=' . (int) db()->lastInsertId());
    }
}

render_header('Create a support ticket', 'new-ticket');
?>
<section class="card">
  <div class="card-body">
    <div class="eyebrow">NEW STUDENT REQUEST</div>
    <p class="text-muted mt-2">Choose the closest topic and explain what you need. Your ticket will be routed by an administrator to the appropriate department.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="category_id">Topic</label>
          <select class="form-select" id="category_id" name="category_id" required>
            <option value="">Select a topic</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>" <?= (string) ($_POST['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="priority">Priority</label>
          <select class="form-select" id="priority" name="priority">
            <option value="normal" <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="urgent" <?= ($_POST['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
          </select>
          <div class="form-hint small mt-1">Select urgent only if the issue needs prompt attention.</div>
        </div>
        <div class="col-12">
          <label class="form-label" for="subject">Subject</label>
          <input class="form-control" id="subject" name="subject" maxlength="180" value="<?= e($_POST['subject'] ?? '') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label" for="description">Describe your issue or request</label>
          <textarea class="form-control" id="description" name="description" rows="7" maxlength="20000" required><?= e($_POST['description'] ?? '') ?></textarea>
          <div class="form-hint small mt-1">Include any details that will help the support team understand your request.</div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" type="submit">Submit ticket</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('student/dashboard.php')) ?>">Cancel</a>
      </div>
    </form>
  </div>
</section>
<?php render_footer(); ?>
