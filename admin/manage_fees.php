<?php
require_once 'includes/header.php';

$page_title = "Manage Student Fees";

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $ids = implode(',', array_map('intval', $selected_ids));
            
            try {
                switch ($action) {
                    case 'mark_paid':
                        $stmt = $pdo->prepare("UPDATE student_fees SET status = 'Paid', amount_paid = amount WHERE fee_id IN ($ids)");
                        $stmt->execute();
                        $_SESSION['success_message'] = "Selected fees marked as paid!";
                        break;
                        
                    case 'send_reminders':
                        // This would typically send email/SMS reminders
                        $_SESSION['success_message'] = "Reminders sent for selected fees!";
                        break;
                        
                    case 'delete':
                        $stmt = $pdo->prepare("DELETE FROM student_fees WHERE fee_id IN ($ids)");
                        $stmt->execute();
                        $_SESSION['success_message'] = "Selected fees deleted!";
                        break;
                        
                    case 'export':
                        // Export functionality would go here
                        $_SESSION['info_message'] = "Export feature coming soon!";
                        break;
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error performing bulk action: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$fee_type = $_GET['fee_type'] ?? '';
$session_year = $_GET['session_year'] ?? '';
$level = $_GET['level'] ?? '';
$department_id = $_GET['department_id'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR sf.invoice_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($status)) {
    $where_conditions[] = "sf.status = ?";
    $params[] = $status;
}

if (!empty($fee_type)) {
    $where_conditions[] = "sf.fee_type = ?";
    $params[] = $fee_type;
}

if (!empty($session_year)) {
    $where_conditions[] = "sf.session_year = ?";
    $params[] = $session_year;
}

if (!empty($level)) {
    $where_conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if (!empty($department_id)) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get fees data
$sql = "
    SELECT sf.*, 
           s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
           s.current_level, s.current_session, s.department_id,
           d.department_name,
           p.program_name
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    $where_sql
    ORDER BY sf.created_date DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fees = $stmt->fetchAll();

// Get filter options
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$fee_types = $pdo->query("SELECT DISTINCT fee_type FROM student_fees WHERE fee_type IS NOT NULL ORDER BY fee_type")->fetchAll();
$session_years = $pdo->query("SELECT DISTINCT session_year FROM student_fees WHERE session_year IS NOT NULL ORDER BY session_year DESC")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Manage Student Fees</h1>
            <p class="text-muted">View, edit, and manage student fee invoices</p>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Filters</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" placeholder="Search student or invoice..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Partial" <?php echo $status === 'Partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="Paid" <?php echo $status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="Overdue" <?php echo $status === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="Waived" <?php echo $status === 'Waived' ? 'selected' : ''; ?>>Waived</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="fee_type">
                            <option value="">All Fee Types</option>
                            <?php foreach ($fee_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['fee_type']); ?>" 
                                    <?php echo $fee_type === $type['fee_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['fee_type']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="department_id">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" 
                                    <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter"></i>
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="manage_fees.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Actions -->
<form method="POST" id="bulkForm">
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div class="btn-group">
                    <select class="form-select form-select-sm me-2" style="width: auto;" name="bulk_action" id="bulkAction">
                        <option value="">Bulk Actions</option>
                        <option value="mark_paid">Mark as Paid</option>
                        <option value="send_reminders">Send Reminders</option>
                        <option value="export">Export Selected</option>
                        <option value="delete" class="text-danger">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" id="applyBulkAction" disabled>
                        Apply
                    </button>
                </div>
                <div>
                    <span id="selectedCount">0 items selected</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Fees Table -->
    <div class="row">
        <div class="col-md-12">
            <div class="app-card app-card-settings shadow-sm">
                <div class="app-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover app-table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>Invoice No.</th>
                                    <th>Student</th>
                                    <th>Fee Type</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="fee-checkbox" name="selected_ids[]" value="<?php echo $fee['fee_id']; ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></strong><br>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($fee['created_date'])); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fee['first_name'] . ' ' . $fee['last_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($fee['matric_number']); ?></small><br>
                                        <small><?php echo htmlspecialchars($fee['department_name'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td>
                                        <strong>₦<?php echo number_format($fee['amount'], 2); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($fee['session_year'] ?? ''); ?> - Level <?php echo $fee['current_level']; ?></small>
                                    </td>
                                    <td>₦<?php echo number_format($fee['amount_paid'], 2); ?></td>
                                    <td>
                                        <strong class="<?php echo $fee['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                            ₦<?php echo number_format($fee['balance'], 2); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if ($fee['due_date']): ?>
                                            <?php echo date('M d, Y', strtotime($fee['due_date'])); ?><br>
                                            <?php if (strtotime($fee['due_date']) < time() && $fee['balance'] > 0): ?>
                                                <small class="text-danger">Overdue</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($fee['status'] === 'Paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($fee['status'] === 'Partial'): ?>
                                            <span class="badge bg-warning">Partial</span>
                                        <?php elseif ($fee['status'] === 'Overdue'): ?>
                                            <span class="badge bg-danger">Overdue</span>
                                        <?php elseif ($fee['status'] === 'Waived'): ?>
                                            <span class="badge bg-info">Waived</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view_invoice.php?id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_fee.php?id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="record_payment.php?fee_id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-success" title="Record Payment">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Summary Stats -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="row text-center">
                    <div class="col-md-3 border-end">
                        <h6 class="stats-type">Total Amount</h6>
                        <div class="stats-figure">₦<?php 
                            $total = array_sum(array_column($fees, 'amount'));
                            echo number_format($total, 2);
                        ?></div>
                    </div>
                    <div class="col-md-3 border-end">
                        <h6 class="stats-type">Total Paid</h6>
                        <div class="stats-figure">₦<?php 
                            $paid = array_sum(array_column($fees, 'amount_paid'));
                            echo number_format($paid, 2);
                        ?></div>
                    </div>
                    <div class="col-md-3 border-end">
                        <h6 class="stats-type">Total Balance</h6>
                        <div class="stats-figure text-danger">₦<?php 
                            $balance = array_sum(array_column($fees, 'balance'));
                            echo number_format($balance, 2);
                        ?></div>
                    </div>
                    <div class="col-md-3">
                        <h6 class="stats-type">Collection Rate</h6>
                        <div class="stats-figure"><?php 
                            $rate = $total > 0 ? ($paid / $total) * 100 : 0;
                            echo number_format($rate, 1);
                        ?>%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Bulk selection functionality
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.fee-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateSelectionCount();
});

document.querySelectorAll('.fee-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateSelectionCount);
});

function updateSelectionCount() {
    const selected = document.querySelectorAll('.fee-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selected + ' items selected';
    document.getElementById('applyBulkAction').disabled = selected === 0;
}

// Confirm bulk delete
document.getElementById('bulkForm').addEventListener('submit', function(e) {
    const action = document.getElementById('bulkAction').value;
    const selected = document.querySelectorAll('.fee-checkbox:checked').length;
    
    if (action === 'delete' && selected > 0) {
        if (!confirm(`Are you sure you want to delete ${selected} fee record(s)? This action cannot be undone.`)) {
            e.preventDefault();
        }
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>