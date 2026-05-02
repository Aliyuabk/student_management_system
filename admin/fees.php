<?php
// fees.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Fee Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new fee structure
    if (isset($_POST['add_fee'])) {
        try {
            $session_year = $_POST['session_year'];
            $level = (int)$_POST['level'];
            $program_id = (int)$_POST['program_id'];
            $fee_type = $_POST['fee_type'];
            $description = $_POST['description'];
            $amount = floatval($_POST['amount']);
            $due_date = $_POST['due_date'] ?: null;
            $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $applicable_to = $_POST['applicable_to'];
            
            // Check if fee already exists
            $check = $pdo->prepare("
                SELECT fee_structure_id FROM fee_structure 
                WHERE session_year = ? AND level = ? AND program_id = ? AND fee_type = ? AND applicable_to = ?
            ");
            $check->execute([$session_year, $level, $program_id, $fee_type, $applicable_to]);
            
            if ($check->rowCount() > 0) {
                throw new Exception("This fee structure already exists!");
            }
            
            $sql = "INSERT INTO fee_structure (
                session_year, level, program_id, fee_type, description, 
                amount, due_date, is_mandatory, applicable_to, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $session_year, $level, $program_id, $fee_type, $description,
                $amount, $due_date, $is_mandatory, $applicable_to
            ]);
            
            $_SESSION['success_message'] = "Fee structure added successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
    
    // Update fee structure
    if (isset($_POST['update_fee'])) {
        try {
            $fee_structure_id = (int)$_POST['fee_structure_id'];
            
            $sql = "UPDATE fee_structure SET
                session_year = ?, level = ?, program_id = ?, fee_type = ?,
                description = ?, amount = ?, due_date = ?, is_mandatory = ?,
                applicable_to = ?
                WHERE fee_structure_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['session_year'], (int)$_POST['level'], (int)$_POST['program_id'],
                $_POST['fee_type'], $_POST['description'], floatval($_POST['amount']),
                $_POST['due_date'] ?: null, isset($_POST['is_mandatory']) ? 1 : 0,
                $_POST['applicable_to'], $fee_structure_id
            ]);
            
            $_SESSION['success_message'] = "Fee structure updated successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
    
    // Delete fee structure
    if (isset($_POST['delete_fee'])) {
        try {
            $fee_structure_id = (int)$_POST['fee_structure_id'];
            
            // Check if fee is in use
            $check = $pdo->prepare("SELECT COUNT(*) FROM student_fees WHERE fee_structure_id = ?");
            $check->execute([$fee_structure_id]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("Cannot delete fee structure that has student records!");
            }
            
            $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE fee_structure_id = ?");
            $stmt->execute([$fee_structure_id]);
            
            $_SESSION['success_message'] = "Fee structure deleted successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
    
    // Bulk generate student fees
    if (isset($_POST['generate_fees'])) {
        try {
            $session_year = $_POST['session_year'];
            $program_id = (int)$_POST['program_id'];
            $level = (int)$_POST['level'];
            $student_type = $_POST['student_type']; // 'new' or 'returning'
            
            // Get applicable fee structures
            $fee_sql = "
                SELECT * FROM fee_structure 
                WHERE session_year = ? AND level = ? AND program_id = ?
                AND (applicable_to = 'All' OR applicable_to = ?)
            ";
            $fee_stmt = $pdo->prepare($fee_sql);
            $fee_stmt->execute([$session_year, $level, $program_id, 
                               $student_type == 'new' ? 'New Students' : 'Returning Students']);
            $fee_structures = $fee_stmt->fetchAll();
            
            if (empty($fee_structures)) {
                throw new Exception("No fee structures found for the selected criteria!");
            }
            
            // Get students
            $student_sql = "
                SELECT student_id, matric_number, first_name, last_name 
                FROM students 
                WHERE program_id = ? AND current_level = ? AND status = 'Active'
            ";
            
            if ($student_type == 'new') {
                $student_sql .= " AND mode_of_entry IN ('UTME', 'Direct Entry')";
            }
            
            $student_stmt = $pdo->prepare($student_sql);
            $student_stmt->execute([$program_id, $level]);
            $students = $student_stmt->fetchAll();
            
            if (empty($students)) {
                throw new Exception("No $student_type students found for the selected level and program!");
            }
            
            $pdo->beginTransaction();
            
            $generated_count = 0;
            $skipped_count = 0;
            
            foreach ($students as $student) {
                foreach ($fee_structures as $fee) {
                    // Check if fee already exists for this student
                    $check = $pdo->prepare("
                        SELECT fee_id FROM student_fees 
                        WHERE student_id = ? AND fee_structure_id = ? AND session_year = ?
                    ");
                    $check->execute([$student['student_id'], $fee['fee_structure_id'], $session_year]);
                    
                    if ($check->rowCount() == 0) {
                        // Generate invoice number
                        $invoice = 'INV-' . $session_year . '-' . $student['matric_number'] . '-' . rand(1000, 9999);
                        
                        $insert = $pdo->prepare("
                            INSERT INTO student_fees (
                                student_id, fee_structure_id, session_year, semester,
                                fee_type, description, amount, due_date, status, invoice_number
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
                        ");
                        
                        $insert->execute([
                            $student['student_id'],
                            $fee['fee_structure_id'],
                            $session_year,
                            $fee['semester'] ?? 1,
                            $fee['fee_type'],
                            $fee['description'],
                            $fee['amount'],
                            $fee['due_date'],
                            $invoice
                        ]);
                        
                        $generated_count++;
                    } else {
                        $skipped_count++;
                    }
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Generated $generated_count fee records. Skipped $skipped_count existing records.";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Error generating fees: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
    
    // Apply hostel fee
    if (isset($_POST['apply_hostel_fee'])) {
        try {
            $session_year = $_POST['session_year'];
            $hostel_id = (int)$_POST['hostel_id'];
            $amount = floatval($_POST['amount']);
            $due_date = $_POST['due_date'];
            
            // Get all active hostel allocations
            $allocations = $pdo->prepare("
                SELECT ha.*, s.matric_number 
                FROM hostel_allocations ha
                JOIN students s ON ha.student_id = s.student_id
                WHERE ha.academic_year = ? AND ha.status = 'Active'
            ");
            $allocations->execute([$session_year]);
            $allocations = $allocations->fetchAll();
            
            if (empty($allocations)) {
                throw new Exception("No active hostel allocations found for this session!");
            }
            
            $pdo->beginTransaction();
            
            $generated_count = 0;
            
            foreach ($allocations as $alloc) {
                // Check if hostel fee already exists
                $check = $pdo->prepare("
                    SELECT fee_id FROM student_fees 
                    WHERE student_id = ? AND session_year = ? AND fee_type = 'Hostel'
                ");
                $check->execute([$alloc['student_id'], $session_year]);
                
                if ($check->rowCount() == 0) {
                    $invoice = 'HST-' . $session_year . '-' . $alloc['matric_number'] . '-' . rand(1000, 9999);
                    
                    $insert = $pdo->prepare("
                        INSERT INTO student_fees (
                            student_id, session_year, semester, fee_type, description,
                            amount, due_date, status, invoice_number
                        ) VALUES (?, ?, ?, 'Hostel', ?, ?, ?, 'Pending', ?)
                    ");
                    
                    $desc = "Hostel accommodation fee for " . $session_year;
                    $insert->execute([
                        $alloc['student_id'],
                        $session_year,
                        1, // Assuming first semester
                        $desc,
                        $amount,
                        $due_date,
                        $invoice
                    ]);
                    
                    $generated_count++;
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Hostel fee applied to $generated_count students!";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Error applying hostel fee: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
    
    // Bulk update fee amounts
    if (isset($_POST['bulk_update'])) {
        try {
            $fee_ids = $_POST['fee_ids'] ?? [];
            $new_amounts = $_POST['amounts'] ?? [];
            
            if (empty($fee_ids)) {
                throw new Exception("No fees selected");
            }
            
            $pdo->beginTransaction();
            $updated = 0;
            
            foreach ($fee_ids as $fee_id) {
                if (isset($new_amounts[$fee_id]) && $new_amounts[$fee_id] > 0) {
                    $stmt = $pdo->prepare("UPDATE fee_structure SET amount = ? WHERE fee_structure_id = ?");
                    $stmt->execute([$new_amounts[$fee_id], $fee_id]);
                    $updated++;
                }
            }
            
            $pdo->commit();
            $_SESSION['success_message'] = "Updated $updated fee structures!";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: fees.php");
        exit();
    }
}

// Get filter parameters
$filter_session = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$filter_program = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$filter_level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$filter_type = isset($_GET['fee_type']) ? $_GET['fee_type'] : '';
$filter_applicable = isset($_GET['applicable_to']) ? $_GET['applicable_to'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($filter_session)) {
    $conditions[] = "f.session_year = ?";
    $params[] = $filter_session;
}

if ($filter_program > 0) {
    $conditions[] = "f.program_id = ?";
    $params[] = $filter_program;
}

if ($filter_level > 0) {
    $conditions[] = "f.level = ?";
    $params[] = $filter_level;
}

if (!empty($filter_type)) {
    $conditions[] = "f.fee_type = ?";
    $params[] = $filter_type;
}

if (!empty($filter_applicable)) {
    $conditions[] = "f.applicable_to = ? OR f.applicable_to = 'All'";
    $params[] = $filter_applicable;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get fee structures
$fee_structures = $pdo->prepare("
    SELECT f.*, p.program_name, p.program_code
    FROM fee_structure f
    LEFT JOIN programs p ON f.program_id = p.program_id
    $where_clause
    ORDER BY f.session_year DESC, f.level, f.fee_type
");
$fee_structures->execute($params);
$fee_structures = $fee_structures->fetchAll();

// Get data for filters
$sessions = $pdo->query("SELECT DISTINCT session_year FROM fee_structure ORDER BY session_year DESC")->fetchAll();
$programs = $pdo->query("SELECT program_id, program_name, program_code FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$fee_types = ['Tuition', 'Hostel', 'Acceptance', 'Library', 'Sports', 'Development', 'Medical', 'Other'];
$applicable_options = ['All', 'New Students', 'Returning Students', 'Final Year'];

// Get hostels for hostel fee
$hostels = $pdo->query("SELECT hostel_id, hostel_name FROM hostels ORDER BY hostel_name")->fetchAll();

// Get statistics
$stats = [
    'total_fees' => $pdo->query("SELECT COUNT(*) FROM fee_structure")->fetchColumn(),
    'tuition_fees' => $pdo->query("SELECT COUNT(*) FROM fee_structure WHERE fee_type = 'Tuition'")->fetchColumn(),
    'hostel_fees' => $pdo->query("SELECT COUNT(*) FROM fee_structure WHERE fee_type = 'Hostel'")->fetchColumn(),
    'active_sessions' => $pdo->query("SELECT COUNT(DISTINCT session_year) FROM fee_structure")->fetchColumn(),
];

// Get total collections
$collections = $pdo->query("
    SELECT 
        SUM(amount_paid) as total_collected,
        COUNT(DISTINCT student_id) as paying_students
    FROM student_fees 
    WHERE status = 'Paid'
")->fetch();
?>

<!-- Display Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-money-bill-wave me-2"></i>Fee Management
    </h1>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
            <i class="fas fa-plus me-2"></i>Add Fee Structure
        </button>
        <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#generateFeeModal">
            <i class="fas fa-cogs me-2"></i>Generate Student Fees
        </button>
        <a href="fee_payments.php" class="btn btn-info ms-2">
            <i class="fas fa-credit-card me-2"></i>View Payments
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title">Total Fee Structures</h6>
                <h3><?php echo number_format($stats['total_fees']); ?></h3>
                <small><?php echo $stats['active_sessions']; ?> active sessions</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Tuition Fees</h6>
                <h3><?php echo number_format($stats['tuition_fees']); ?></h3>
                <small>Active structures</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">Hostel Fees</h6>
                <h3><?php echo number_format($stats['hostel_fees']); ?></h3>
                <small>Accommodation fees</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">Total Collected</h6>
                <h3>₦<?php echo number_format($collections['total_collected'] ?? 0, 2); ?></h3>
                <small><?php echo number_format($collections['paying_students'] ?? 0); ?> students</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Fee Structures</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Session</label>
                <select class="form-control" name="session_year">
                    <option value="">All Sessions</option>
                    <?php foreach ($sessions as $session): ?>
                    <option value="<?php echo htmlspecialchars($session['session_year']); ?>"
                        <?php echo $filter_session == $session['session_year'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($session['session_year']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Program</label>
                <select class="form-control" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo $prog['program_id']; ?>"
                        <?php echo $filter_program == $prog['program_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($prog['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-control" name="level">
                    <option value="0">All Levels</option>
                    <?php foreach ($levels as $lvl): ?>
                    <option value="<?php echo $lvl; ?>" <?php echo $filter_level == $lvl ? 'selected' : ''; ?>>
                        Level <?php echo $lvl; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Fee Type</label>
                <select class="form-control" name="fee_type">
                    <option value="">All Types</option>
                    <?php foreach ($fee_types as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo $filter_type == $type ? 'selected' : ''; ?>>
                        <?php echo $type; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Applicable To</label>
                <select class="form-control" name="applicable_to">
                    <option value="">All</option>
                    <?php foreach ($applicable_options as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $filter_applicable == $opt ? 'selected' : ''; ?>>
                        <?php echo $opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Fee Structures Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Fee Structures</h5>
        <div>
            <span class="text-muted me-3">Total: <?php echo count($fee_structures); ?> records</span>
            <button class="btn btn-sm btn-warning" onclick="showBulkUpdate()">
                <i class="fas fa-pencil-alt me-1"></i>Bulk Update
            </button>
        </div>
    </div>
    
    <div class="card-body p-0">
        <form method="POST" id="bulkUpdateForm" style="display: none;" class="p-3 bg-light border-bottom">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <p class="mb-2"><strong>Bulk Update Amounts</strong> - Enter new amounts for selected fees</p>
                    <div id="bulkInputs">
                        <!-- Dynamic inputs will be added here -->
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" name="bulk_update" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Selected
                    </button>
                </div>
            </div>
        </form>
        
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                        </th>
                        <th>Session</th>
                        <th>Program</th>
                        <th>Level</th>
                        <th>Fee Type</th>
                        <th>Description</th>
                        <th>Amount (₦)</th>
                        <th>Due Date</th>
                        <th>Applicable To</th>
                        <th>Mandatory</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fee_structures as $fee): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="fee-checkbox" value="<?php echo $fee['fee_structure_id']; ?>">
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($fee['session_year']); ?></strong>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($fee['program_code'] ?? 'All'); ?>
                            <br>
                            <small><?php echo htmlspecialchars($fee['program_name'] ?? 'All Programs'); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-info">Level <?php echo $fee['level']; ?></span>
                        </td>
                        <td>
                            <?php
                            $type_class = [
                                'Tuition' => 'primary',
                                'Hostel' => 'success',
                                'Acceptance' => 'warning',
                                'Library' => 'info',
                                'Sports' => 'secondary',
                                'Development' => 'dark',
                                'Medical' => 'danger'
                            ][$fee['fee_type']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $type_class; ?>">
                                <?php echo htmlspecialchars($fee['fee_type']); ?>
                            </span>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars($fee['description']); ?></small>
                        </td>
                        <td>
                            <strong class="text-primary">₦<?php echo number_format($fee['amount'], 2); ?></strong>
                        </td>
                        <td>
                            <?php if ($fee['due_date']): ?>
                                <span class="badge bg-<?php echo strtotime($fee['due_date']) < time() ? 'danger' : 'success'; ?>">
                                    <?php echo date('M d, Y', strtotime($fee['due_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($fee['applicable_to'] == 'All'): ?>
                                <span class="badge bg-success">All</span>
                            <?php elseif ($fee['applicable_to'] == 'New Students'): ?>
                                <span class="badge bg-info">New</span>
                            <?php elseif ($fee['applicable_to'] == 'Returning Students'): ?>
                                <span class="badge bg-warning">Returning</span>
                            <?php elseif ($fee['applicable_to'] == 'Final Year'): ?>
                                <span class="badge bg-dark">Final Year</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($fee['is_mandatory']): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check"></i> Yes
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-times"></i> No
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick="editFee(<?php echo htmlspecialchars(json_encode($fee)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteFee(<?php echo $fee['fee_structure_id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Fee Modal -->
<div class="modal fade" id="addFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Fee Structure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addFeeForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Year *</label>
                            <select class="form-control" name="session_year" required>
                                <option value="">Select Session</option>
                                <?php 
                                $year = date('Y');
                                for ($y = $year - 1; $y <= $year + 3; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Level *</label>
                            <select class="form-control" name="level" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>">Level <?php echo $lvl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Program *</label>
                            <select class="form-control" name="program_id" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>">
                                    <?php echo htmlspecialchars($prog['program_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Fee Type *</label>
                            <select class="form-control" name="fee_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($fee_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" min="0" step="100" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="2" 
                                      placeholder="Brief description of the fee"></textarea>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Applicable To *</label>
                            <select class="form-control" name="applicable_to" required>
                                <option value="All">All Students</option>
                                <option value="New Students">New Students Only</option>
                                <option value="Returning Students">Returning Students Only</option>
                                <option value="Final Year">Final Year Only</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_mandatory" id="isMandatory" checked>
                                <label class="form-check-label" for="isMandatory">
                                    Mandatory Fee (Required for all students)
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Tuition and Hostel fees can be set here. Use "Generate Student Fees" to apply to students.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_fee" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Fee Structure
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Fee Modal -->
<div class="modal fade" id="editFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Fee Structure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editFeeForm">
                    <input type="hidden" name="fee_structure_id" id="edit_fee_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Year *</label>
                            <select class="form-control" name="session_year" id="edit_session_year" required>
                                <option value="">Select Session</option>
                                <?php 
                                for ($y = $year - 1; $y <= $year + 3; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Level *</label>
                            <select class="form-control" name="level" id="edit_level" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>">Level <?php echo $lvl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Program *</label>
                            <select class="form-control" name="program_id" id="edit_program_id" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>">
                                    <?php echo htmlspecialchars($prog['program_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Fee Type *</label>
                            <select class="form-control" name="fee_type" id="edit_fee_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($fee_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" id="edit_amount" min="0" step="100" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Due Date</label>
                            <input type="date" class="form-control" name="due_date" id="edit_due_date">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Applicable To *</label>
                            <select class="form-control" name="applicable_to" id="edit_applicable_to" required>
                                <option value="All">All Students</option>
                                <option value="New Students">New Students Only</option>
                                <option value="Returning Students">Returning Students Only</option>
                                <option value="Final Year">Final Year Only</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_mandatory" id="edit_is_mandatory">
                                <label class="form-check-label" for="edit_is_mandatory">
                                    Mandatory Fee (Required for all students)
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_fee" class="btn btn-info">
                            <i class="fas fa-save me-2"></i>Update Fee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Generate Student Fees Modal -->
<div class="modal fade" id="generateFeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-cogs me-2"></i>Generate Student Fees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="feeTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tuition-tab" data-bs-toggle="tab" data-bs-target="#tuition" type="button">
                            Tuition & Fees
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="hostel-tab" data-bs-toggle="tab" data-bs-target="#hostel" type="button">
                            Hostel Fee
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Tuition & Other Fees -->
                    <div class="tab-pane fade show active" id="tuition">
                        <form method="POST" id="generateFeeForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Session Year *</label>
                                <select class="form-control" name="session_year" required>
                                    <option value="">Select Session</option>
                                    <?php 
                                    for ($y = $year; $y <= $year + 3; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Program *</label>
                                <select class="form-control" name="program_id" required>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['program_id']; ?>">
                                        <?php echo htmlspecialchars($prog['program_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Level *</label>
                                <select class="form-control" name="level" required>
                                    <option value="">Select Level</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>">Level <?php echo $lvl; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Student Type *</label>
                                <select class="form-control" name="student_type" required>
                                    <option value="new">New Students (Fresh Entry)</option>
                                    <option value="returning">Returning Students</option>
                                </select>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                This will generate fee records for all active students matching the criteria based on existing fee structures.
                            </div>
                            
                            <div class="text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="generate_fees" class="btn btn-success">
                                    <i class="fas fa-play me-2"></i>Generate Fees
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Hostel Fee -->
                    <div class="tab-pane fade" id="hostel">
                        <form method="POST" id="hostelFeeForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Session Year *</label>
                                <select class="form-control" name="session_year" required>
                                    <option value="">Select Session</option>
                                    <?php 
                                    for ($y = $year; $y <= $year + 3; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Hostel *</label>
                                <select class="form-control" name="hostel_id" required>
                                    <option value="">Select Hostel</option>
                                    <?php foreach ($hostels as $hostel): ?>
                                    <option value="<?php echo $hostel['hostel_id']; ?>">
                                        <?php echo htmlspecialchars($hostel['hostel_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Amount (₦) *</label>
                                <input type="number" class="form-control" name="amount" min="0" step="100" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Due Date *</label>
                                <input type="date" class="form-control" name="due_date" required>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                This will apply hostel fee to all students with active hostel allocations for the selected session.
                            </div>
                            
                            <div class="text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="apply_hostel_fee" class="btn btn-success">
                                    <i class="fas fa-bed me-2"></i>Apply Hostel Fee
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Toggle all checkboxes
function toggleAll(source) {
    document.querySelectorAll('.fee-checkbox').forEach(cb => {
        cb.checked = source.checked;
    });
    updateBulkInputs();
}

// Update bulk inputs
function updateBulkInputs() {
    const checkboxes = document.querySelectorAll('.fee-checkbox:checked');
    const bulkForm = document.getElementById('bulkUpdateForm');
    const bulkInputs = document.getElementById('bulkInputs');
    
    if (checkboxes.length > 0) {
        let html = '<div class="row">';
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            const feeType = row.cells[4].textContent.trim();
            const amount = row.cells[6].textContent.trim().replace('₦', '').replace(',', '');
            const feeId = cb.value;
            
            html += `
                <div class="col-md-6 mb-2">
                    <label class="small">${feeType} (ID: ${feeId})</label>
                    <input type="number" class="form-control form-control-sm" 
                           name="amounts[${feeId}]" value="${amount}" min="0" step="100">
                    <input type="hidden" name="fee_ids[]" value="${feeId}">
                </div>
            `;
        });
        html += '</div>';
        bulkInputs.innerHTML = html;
        bulkForm.style.display = 'block';
    } else {
        bulkForm.style.display = 'none';
    }
}

// Add change event to checkboxes
document.querySelectorAll('.fee-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkInputs);
});

// Show bulk update
function showBulkUpdate() {
    const checkboxes = document.querySelectorAll('.fee-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one fee structure.');
        return;
    }
    document.getElementById('bulkUpdateForm').style.display = 'block';
    document.getElementById('bulkUpdateForm').scrollIntoView({ behavior: 'smooth' });
}

// Edit fee
function editFee(fee) {
    document.getElementById('edit_fee_id').value = fee.fee_structure_id;
    document.getElementById('edit_session_year').value = fee.session_year;
    document.getElementById('edit_level').value = fee.level;
    document.getElementById('edit_program_id').value = fee.program_id;
    document.getElementById('edit_fee_type').value = fee.fee_type;
    document.getElementById('edit_amount').value = fee.amount;
    document.getElementById('edit_due_date').value = fee.due_date || '';
    document.getElementById('edit_description').value = fee.description || '';
    document.getElementById('edit_applicable_to').value = fee.applicable_to;
    document.getElementById('edit_is_mandatory').checked = fee.is_mandatory == 1;
    
    new bootstrap.Modal(document.getElementById('editFeeModal')).show();
}

// Delete fee
function deleteFee(feeId) {
    if (confirm('Are you sure you want to delete this fee structure? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="fee_structure_id" value="${feeId}"><input type="hidden" name="delete_fee" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Form validation
document.getElementById('addFeeForm')?.addEventListener('submit', function(e) {
    const amount = this.querySelector('input[name="amount"]').value;
    if (amount <= 0) {
        e.preventDefault();
        alert('Amount must be greater than 0.');
    }
});

document.getElementById('generateFeeForm')?.addEventListener('submit', function(e) {
    return confirm('Generate fees for selected students? This may take a moment.');
});

document.getElementById('hostelFeeForm')?.addEventListener('submit', function(e) {
    return confirm('Apply hostel fee to all allocated students?');
});

// Initialize
updateBulkInputs();
</script>

<style>
.modal-header.bg-primary .btn-close-white,
.modal-header.bg-info .btn-close-white,
.modal-header.bg-success .btn-close-white {
    filter: brightness(0) invert(1);
}
.table td {
    vertical-align: middle;
}
.badge {
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
}
</style>

<?php
require_once 'includes/footer.php';
?>