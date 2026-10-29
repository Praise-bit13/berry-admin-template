<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';
require_installed();

$ticket = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));
    if (!preg_match('/\AFCWM-\d{8}-[A-F0-9]{24}\z/', $reference)) {
        $error = 'Enter the complete reference number shown when your ticket was submitted.';
    } else {
        $query = db()->prepare(
            'SELECT t.reference, t.status, t.created_at, t.updated_at, d.name AS department
             FROM tickets t
             LEFT JOIN departments d ON d.id = t.assigned_department_id
             WHERE t.reference = :reference'
        );
        $query->execute(['reference' => $reference]);
        $ticket = $query->fetch() ?: null;
        if (!$ticket) {
            $error = 'No ticket was found for that reference number. Check it and try again.';
        }
    }
}

render_header('Track a ticket', 'track');
?>
<section class="card track-panel">
  <div class="card-body">
    <div class="eyebrow">TICKET STATUS LOOKUP</div>
    <h2 class="h4 mt-2">Track your request</h2>
    <p class="text-muted">Enter the reference number you received after submitting your ticket. Keep this number private.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="mb-4">
      <?= csrf_field() ?>
      <label class="form-label" for="reference">Ticket reference number</label>
      <div class="input-group">
        <input class="form-control text-uppercase" id="reference" name="reference" maxlength="40" placeholder="FCWM-YYYYMMDD-..." value="<?= e($_POST['reference'] ?? '') ?>" required>
        <button class="btn btn-primary" type="submit">Track ticket</button>
      </div>
    </form>
    <?php if ($ticket): ?>
      <div class="track-status" role="status">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
          <div><div class="small text-muted">Reference</div><div class="ticket-reference mt-1"><?= e($ticket['reference']) ?></div></div>
          <?= status_badge($ticket['status']) ?>
        </div>
        <hr>
        <div class="row g-3 small">
          <div class="col-sm-6"><span class="text-muted">Assigned department</span><br><strong><?= e($ticket['department'] ?? 'Awaiting assignment') ?></strong></div>
          <div class="col-sm-6"><span class="text-muted">Last updated</span><br><strong><?= e(date('M j, Y g:i a', strtotime($ticket['updated_at']))) ?></strong></div>
        </div>
        <p class="small text-muted mt-3 mb-0">Sign in to view the full conversation or add a reply.</p>
      </div>
    <?php endif; ?>
    <p class="small mb-0">Need to submit a new request? <a href="<?= e(url('register.php')) ?>">Create a student account</a>.</p>
  </div>
</section>
<?php render_footer(); ?>
