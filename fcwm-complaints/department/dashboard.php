<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_installed();
$user = require_role('department');

$countQuery = db()->prepare(
    'SELECT status, COUNT(*) AS total FROM tickets WHERE assigned_department_id = :department_id GROUP BY status'
);
$countQuery->execute(['department_id' => $user['department_id']]);
$counts = array_column($countQuery->fetchAll(), 'total', 'status');
$ticketQuery = db()->prepare(
    'SELECT t.id, t.reference, t.subject, t.status, t.priority, t.updated_at,
            c.name AS category, u.full_name AS student_name
     FROM tickets t
     JOIN categories c ON c.id = t.category_id
     JOIN users u ON u.id = t.created_by
     WHERE t.assigned_department_id = :department_id
     ORDER BY CASE WHEN t.status IN ("resolved", "closed") THEN 1 ELSE 0 END,
              t.updated_at DESC, t.id DESC'
);
$ticketQuery->execute(['department_id' => $user['department_id']]);
$tickets = $ticketQuery->fetchAll();
$departmentQuery = db()->prepare('SELECT name FROM departments WHERE id = :id');
$departmentQuery->execute(['id' => $user['department_id']]);
$departmentName = (string) $departmentQuery->fetchColumn();

render_header($departmentName . ' cases', 'tickets');
?>
<div class="row g-3 mb-4">
  <?php foreach (['assigned' => 'Assigned', 'in_progress' => 'In progress', 'awaiting_student' => 'Awaiting student', 'resolved' => 'Resolved'] as $key => $label): ?>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label"><?= e($label) ?> cases</div><div class="stat-value"><?= (int) ($counts[$key] ?? 0) ?></div></div></div>
  <?php endforeach; ?>
</div>
<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Cases routed to <?= e($departmentName) ?></strong>
    <span class="text-muted small"><?= count($tickets) ?> cases</span>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Reference</th><th>Student</th><th>Subject</th><th>Topic</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="6"><div class="empty-state">No cases have been routed to your department.</div></td></tr>
      <?php else: foreach ($tickets as $ticket): ?>
        <tr>
          <td><a class="ticket-reference" href="<?= e(url('ticket.php?id=' . (int) $ticket['id'])) ?>"><?= e($ticket['reference']) ?></a></td>
          <td><?= e($ticket['student_name']) ?></td>
          <td><?= e($ticket['subject']) ?></td>
          <td><?= e($ticket['category']) ?></td>
          <td><?= status_badge($ticket['status']) ?></td>
          <td><?= e(date('M j, Y', strtotime($ticket['updated_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php render_footer(); ?>
