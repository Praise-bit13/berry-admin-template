<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_installed();
$user = require_role('student');

$countQuery = db()->prepare(
    'SELECT status, COUNT(*) AS total FROM tickets WHERE created_by = :user_id GROUP BY status'
);
$countQuery->execute(['user_id' => $user['id']]);
$counts = array_column($countQuery->fetchAll(), 'total', 'status');
$ticketsQuery = db()->prepare(
    'SELECT t.id, t.reference, t.subject, t.status, t.priority, t.created_at, t.updated_at,
            c.name AS category, d.name AS department
     FROM tickets t
     JOIN categories c ON c.id = t.category_id
     LEFT JOIN departments d ON d.id = t.assigned_department_id
     WHERE t.created_by = :user_id
     ORDER BY t.updated_at DESC, t.id DESC'
);
$ticketsQuery->execute(['user_id' => $user['id']]);
$tickets = $ticketsQuery->fetchAll();

render_header('My support tickets', 'tickets');
?>
<div class="row g-3 mb-4">
  <?php foreach (['open' => ['new', 'assigned', 'in_progress', 'awaiting_student'], 'resolved' => ['resolved'], 'closed' => ['closed']] as $label => $statuses): ?>
    <?php $total = array_sum(array_map(static fn(string $status): int => (int) ($counts[$status] ?? 0), $statuses)); ?>
    <div class="col-12 col-md-4"><div class="stat-card"><div class="stat-label"><?= e(ucfirst($label)) ?> tickets</div><div class="stat-value"><?= $total ?></div></div></div>
  <?php endforeach; ?>
</div>
<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Your recent requests</strong>
    <a class="btn btn-sm btn-primary" href="<?= e(url('student/new-ticket.php')) ?>">Create ticket</a>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Reference</th><th>Subject</th><th>Topic</th><th>Department</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="6"><div class="empty-state">You have not submitted a support ticket yet.</div></td></tr>
      <?php else: foreach ($tickets as $ticket): ?>
        <tr>
          <td><a class="ticket-reference" href="<?= e(url('ticket.php?id=' . (int) $ticket['id'])) ?>"><?= e($ticket['reference']) ?></a></td>
          <td><?= e($ticket['subject']) ?></td>
          <td><?= e($ticket['category']) ?></td>
          <td><?= e($ticket['department'] ?? 'Not assigned') ?></td>
          <td><?= status_badge($ticket['status']) ?></td>
          <td><?= e(date('M j, Y', strtotime($ticket['updated_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php render_footer(); ?>
