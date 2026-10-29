<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/ticket_helpers.php';
require_installed();
$user = require_login();

$ticketId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$ticketId || $ticketId < 1) {
    http_response_code(404);
    exit('Ticket not found.');
}

$ticketQuery = db()->prepare(
    'SELECT t.*, c.name AS category, d.name AS department,
            u.full_name AS student_name, u.email AS student_email, u.matric_number
     FROM tickets t
     JOIN categories c ON c.id = t.category_id
     JOIN users u ON u.id = t.created_by
     LEFT JOIN departments d ON d.id = t.assigned_department_id
     WHERE t.id = :id'
);
$ticketQuery->execute(['id' => $ticketId]);
$ticket = $ticketQuery->fetch();
if (!$ticket || !ticket_accessible_to($ticket, $user)) {
    http_response_code(404);
    exit('Ticket not found.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reply') {
        $message = trim((string) ($_POST['message'] ?? ''));
        if (strlen($message) < 2 || strlen($message) > 10000) {
            $error = 'Enter a reply between 2 and 10,000 characters.';
        } else {
            db()->beginTransaction();
            $insert = db()->prepare(
                'INSERT INTO ticket_updates (ticket_id, user_id, message)
                 VALUES (:ticket_id, :user_id, :message)'
            );
            $insert->execute([
                'ticket_id' => $ticketId,
                'user_id' => $user['id'],
                'message' => $message,
            ]);
            db()->prepare('UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => $ticketId]);
            if ($user['role'] === 'student' && in_array($ticket['status'], ['resolved', 'closed'], true)) {
                $reopenedStatus = $ticket['assigned_department_id'] === null ? 'new' : 'assigned';
                db()->prepare('UPDATE tickets SET status = :status WHERE id = :id')
                    ->execute(['status' => $reopenedStatus, 'id' => $ticketId]);
                db()->prepare(
                    'INSERT INTO ticket_updates (ticket_id, user_id, message)
                     VALUES (:ticket_id, :user_id, :message)'
                )->execute([
                    'ticket_id' => $ticketId,
                    'user_id' => $user['id'],
                    'message' => 'Ticket reopened after a student reply.',
                ]);
            }
            db()->commit();
            $emailSent = true;
            if ($user['role'] !== 'student') {
                $emailSent = email_ticket_update($ticket, 'New update on FCWM ticket ' . $ticket['reference'], 'The support team replied to your ticket.');
            }
            flash('success', 'Your reply has been added to the ticket.');
            if (!$emailSent) {
                flash('warning', 'The reply was saved, but the student email notification could not be sent. Check the XAMPP mail configuration.');
            }
            redirect('ticket.php?id=' . $ticketId);
        }
    } elseif ($action === 'admin_update' && $user['role'] === 'admin') {
        $departmentValue = trim((string) ($_POST['department_id'] ?? ''));
        $departmentId = $departmentValue === '' ? null : filter_var($departmentValue, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        $priority = (string) ($_POST['priority'] ?? '');
        $statuses = ticket_statuses();
        $departmentExists = true;
        if ($departmentId !== null && $departmentId !== false) {
            $departmentCheck = db()->prepare('SELECT id FROM departments WHERE id = :id AND is_active = 1');
            $departmentCheck->execute(['id' => (int) $departmentId]);
            $departmentExists = (bool) $departmentCheck->fetchColumn();
        }

        if ($departmentValue !== '' && (!$departmentId || !$departmentExists)) {
            $error = 'Choose an active department or leave the ticket unassigned.';
        } elseif (!isset($statuses[$status]) || !in_array($priority, ['normal', 'urgent'], true)) {
            $error = 'Choose a valid ticket status and priority.';
        } elseif ($departmentId === null && in_array($status, ['assigned', 'in_progress', 'awaiting_student'], true)) {
            $error = 'Route the ticket to a department before selecting this status.';
        } else {
            $oldDepartmentId = $ticket['assigned_department_id'] === null ? null : (int) $ticket['assigned_department_id'];
            $newDepartmentId = $departmentId === null ? null : (int) $departmentId;
            if ($newDepartmentId !== null && $status === 'new') {
                $status = 'assigned';
            }
            $changes = [];
            if ($oldDepartmentId !== $newDepartmentId) {
                $changes[] = $newDepartmentId === null
                    ? 'Ticket returned to the unassigned queue.'
                    : 'Ticket routed to ' . db_department_name($newDepartmentId) . '.';
            }
            if ($ticket['status'] !== $status) {
                $changes[] = 'Status changed to ' . $statuses[$status] . '.';
            }
            if ($ticket['priority'] !== $priority) {
                $changes[] = 'Priority changed to ' . ucfirst($priority) . '.';
            }

            db()->beginTransaction();
            $update = db()->prepare(
                'UPDATE tickets
                 SET assigned_department_id = :department_id, status = :status, priority = :priority
                 WHERE id = :id'
            );
            $update->execute([
                'department_id' => $newDepartmentId,
                'status' => $status,
                'priority' => $priority,
                'id' => $ticketId,
            ]);
            if ($changes) {
                $log = db()->prepare(
                    'INSERT INTO ticket_updates (ticket_id, user_id, message)
                     VALUES (:ticket_id, :user_id, :message)'
                );
                $log->execute([
                    'ticket_id' => $ticketId,
                    'user_id' => $user['id'],
                    'message' => implode(' ', $changes),
                ]);
            }
            db()->commit();
            $emailSent = !$changes || email_ticket_update(
                $ticket,
                'FCWM ticket updated: ' . $ticket['reference'],
                implode(' ', $changes)
            );
            flash('success', $changes ? 'Ticket assignment and status have been updated.' : 'No ticket changes were needed.');
            if (!$emailSent) {
                flash('warning', 'The ticket was updated, but the student email notification could not be sent. Check the XAMPP mail configuration.');
            }
            redirect('ticket.php?id=' . $ticketId);
        }
    } elseif ($action === 'department_status' && $user['role'] === 'department') {
        $status = (string) ($_POST['status'] ?? '');
        $departmentStatuses = [
            'in_progress' => 'In progress',
            'awaiting_student' => 'Awaiting student',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ];
        if (!isset($departmentStatuses[$status])) {
            $error = 'Choose a valid status for this case.';
        } elseif ($ticket['status'] === $status) {
            flash('success', 'The case already has that status.');
            redirect('ticket.php?id=' . $ticketId);
        } else {
            $message = 'Status changed to ' . $departmentStatuses[$status] . '.';
            db()->beginTransaction();
            db()->prepare('UPDATE tickets SET status = :status WHERE id = :id AND assigned_department_id = :department_id')
                ->execute(['status' => $status, 'id' => $ticketId, 'department_id' => $user['department_id']]);
            db()->prepare(
                'INSERT INTO ticket_updates (ticket_id, user_id, message)
                 VALUES (:ticket_id, :user_id, :message)'
            )->execute(['ticket_id' => $ticketId, 'user_id' => $user['id'], 'message' => $message]);
            db()->commit();
            $emailSent = email_ticket_update($ticket, 'FCWM ticket status: ' . $ticket['reference'], $message);
            flash('success', 'Case status updated.');
            if (!$emailSent) {
                flash('warning', 'The case status was saved, but the student email notification could not be sent. Check the XAMPP mail configuration.');
            }
            redirect('ticket.php?id=' . $ticketId);
        }
    } else {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
}

$departmentOptions = [];
if ($user['role'] === 'admin') {
    $departmentOptions = db()->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
}
$updatesQuery = db()->prepare(
    'SELECT tu.message, tu.created_at, COALESCE(u.full_name, "Support team") AS author
     FROM ticket_updates tu
     LEFT JOIN users u ON u.id = tu.user_id
     WHERE tu.ticket_id = :ticket_id
     ORDER BY tu.created_at, tu.id'
);
$updatesQuery->execute(['ticket_id' => $ticketId]);
$updates = $updatesQuery->fetchAll();
$dashboardPath = match ($user['role']) {
    'admin' => 'admin/dashboard.php',
    'department' => 'department/dashboard.php',
    default => 'student/dashboard.php',
};

render_header('Ticket ' . $ticket['reference'], 'tickets');
?>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4">
  <div class="<?= $user['role'] === 'admin' ? 'col-lg-8' : 'col-12' ?>">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <div>
          <div class="ticket-reference"><?= e($ticket['reference']) ?></div>
          <strong><?= e($ticket['subject']) ?></strong>
        </div>
        <?= status_badge($ticket['status']) ?>
      </div>
      <div class="card-body">
        <div class="row g-3 mb-4 small">
          <div class="col-sm-4"><span class="text-muted">Topic</span><br><strong><?= e($ticket['category']) ?></strong></div>
          <div class="col-sm-4"><span class="text-muted">Department</span><br><strong><?= e($ticket['department'] ?? 'Not assigned') ?></strong></div>
          <div class="col-sm-4"><span class="text-muted">Priority</span><br><strong><?= e(ucfirst($ticket['priority'])) ?></strong></div>
          <div class="col-sm-4"><span class="text-muted">Submitted</span><br><strong><?= e(date('M j, Y g:i a', strtotime($ticket['created_at']))) ?></strong></div>
          <?php if ($user['role'] !== 'student'): ?>
            <div class="col-sm-4"><span class="text-muted">Student</span><br><strong><?= e($ticket['student_name']) ?></strong></div>
            <div class="col-sm-4"><span class="text-muted">Matric number</span><br><strong><?= e($ticket['matric_number']) ?></strong></div>
          <?php endif; ?>
        </div>
        <div class="ticket-message">
          <div class="ticket-message-meta"><strong>Original request · <?= e($ticket['student_name']) ?></strong><span><?= e(date('M j, Y g:i a', strtotime($ticket['created_at']))) ?></span></div>
          <p><?= e($ticket['description']) ?></p>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><strong>Conversation</strong></div>
      <div class="card-body">
        <?php if (!$updates): ?>
          <p class="text-muted small">No replies yet. Add a message below to continue the conversation.</p>
        <?php else: foreach ($updates as $update): ?>
          <div class="ticket-message">
            <div class="ticket-message-meta"><strong><?= e($update['author']) ?></strong><span><?= e(date('M j, Y g:i a', strtotime($update['created_at']))) ?></span></div>
            <p><?= e($update['message']) ?></p>
          </div>
        <?php endforeach; endif; ?>
        <form method="post" class="mt-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reply">
          <label class="form-label" for="message"><?= $user['role'] === 'student' ? 'Add a reply' : 'Reply to the student' ?></label>
          <textarea class="form-control" id="message" name="message" rows="4" maxlength="10000" required></textarea>
          <button class="btn btn-primary mt-3" type="submit">Send reply</button>
        </form>
      </div>
    </section>
  </div>

  <?php if ($user['role'] === 'admin'): ?>
    <div class="col-lg-4">
      <section class="card">
        <div class="card-header"><strong>Route and update</strong></div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="admin_update">
            <div class="mb-3">
              <label class="form-label" for="department_id">Assign department</label>
              <select class="form-select" id="department_id" name="department_id">
                <option value="">Unassigned</option>
                <?php foreach ($departmentOptions as $department): ?>
                  <option value="<?= (int) $department['id'] ?>" <?= (string) $ticket['assigned_department_id'] === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="status">Status</label>
              <select class="form-select" id="status" name="status">
                <?php foreach (ticket_statuses() as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="priority">Priority</label>
              <select class="form-select" id="priority" name="priority">
                <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="urgent" <?= $ticket['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
              </select>
            </div>
            <button class="btn btn-primary w-100" type="submit">Save ticket updates</button>
          </form>
        </div>
      </section>
    </div>
  <?php elseif ($user['role'] === 'department'): ?>
    <div class="col-12">
      <section class="card">
        <div class="card-header"><strong>Update case status</strong></div>
        <div class="card-body">
          <form method="post" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="department_status">
            <div class="col-md-5">
              <label class="form-label" for="status">Status</label>
              <select class="form-select" id="status" name="status" required>
                <?php foreach (['in_progress' => 'In progress', 'awaiting_student' => 'Awaiting student', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-auto"><button class="btn btn-primary" type="submit">Update status</button></div>
          </form>
        </div>
      </section>
    </div>
  <?php endif; ?>
</div>
<a class="btn btn-outline-secondary mt-4" href="<?= e(url($dashboardPath)) ?>">Back to tickets</a>
<?php render_footer(); ?>
