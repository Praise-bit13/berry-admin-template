<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_installed();
require_role('admin');

$counts = db()->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status')->fetchAll();
$statusCounts = array_column($counts, 'total', 'status');
$tickets = db()->query(
    'SELECT t.id, t.reference, t.subject, t.status, t.priority, t.updated_at,
            c.name AS category, d.name AS department, u.full_name AS student_name
     FROM tickets t
     JOIN categories c ON c.id = t.category_id
     JOIN users u ON u.id = t.created_by
     LEFT JOIN departments d ON d.id = t.assigned_department_id
     ORDER BY CASE WHEN t.status IN ("resolved", "closed") THEN 1 ELSE 0 END,
              t.updated_at DESC, t.id DESC'
)->fetchAll();
$departmentCount = (int) db()->query('SELECT COUNT(*) FROM departments WHERE is_active = 1')->fetchColumn();

render_header('Ticket administration', 'tickets');
?>
<div class="row g-3 mb-4">
  <?php foreach (['new' => 'New', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'resolved' => 'Resolved'] as $key => $label): ?>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label"><?= e($label) ?> tickets</div><div class="stat-value"><?= (int) ($statusCounts[$key] ?? 0) ?></div></div></div>
  <?php endforeach; ?>
</div>
<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>All student tickets</strong>
    <span class="text-muted small"><?= count($tickets) ?> tickets · <?= $departmentCount ?> departments</span>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Reference</th><th>Student</th><th>Subject</th><th>Topic</th><th>Department</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="7"><div class="empty-state">No student tickets have been submitted.</div></td></tr>
      <?php else: foreach ($tickets as $ticket): ?>
        <tr>
          <td><a class="ticket-reference" href="<?= e(url('ticket.php?id=' . (int) $ticket['id'])) ?>"><?= e($ticket['reference']) ?></a></td>
          <td><?= e($ticket['student_name']) ?></td>
          <td><?= e($ticket['subject']) ?></td>
          <td><?= e($ticket['category']) ?></td>
          <td><?= e($ticket['department'] ?? 'Unassigned') ?></td>
          <td><?= status_badge($ticket['status']) ?></td>
          <td><?= e(date('M j, Y', strtotime($ticket['updated_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php render_footer(); ?>
