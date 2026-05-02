<?php
// promotion.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Student Promotion";

// Handle promotion actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Promote selected students
    if (isset($_POST['promote_students'])) {
        try {
            $student_ids = $_POST['student_ids'] ?? [];
            $current_level = (int)$_POST['current_level'];
            $target_level = (int)$_POST['target_level'];
            $new_session = $_POST['new_session'];
            $promotion_type = $_POST['promotion_type']; // 'normal', 'graduating', 'repeating'
            
            if (empty($student_ids)) {
                throw new Exception("No students selected for promotion");
            }
            
            $pdo->beginTransaction();
            
            $success_count = 0;
            $failed_count = 0;
            $errors = [];
            
            foreach ($student_ids as $student_id) {
                try {
                    // Get student details
                    $student = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
                    $student->execute([$student_id]);
                    $student_data = $student->fetch();
                    
                    if (!$student_data) {
                        throw new Exception("Student not found");
                    }
                    
                    // Update student level
                    $update = $pdo->prepare("
                        UPDATE students SET 
                            current_level = ?,
                            current_session = ?,
                            updated_at = NOW()
                        WHERE student_id = ?
                    ");
                    $update->execute([$target_level, $new_session, $student_id]);
                    
                    // Record promotion history (you may want to create a promotion_history table)
                    $log = $pdo->prepare("
                        INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Student Promotion', ?, ?, NOW())
                    ");
                    $log->execute([
                        $_SESSION['admin_id'],
                        "Promoted student {$student_data['matric_number']} from Level $current_level to Level $target_level",
                        $_SERVER['REMOTE_ADDR']
                    ]);
                    
                    $success_count++;
                    
                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = "Student ID $student_id: " . $e->getMessage();
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Successfully promoted $success_count students to Level $target_level.";
            if ($failed_count > 0) {
                $_SESSION['warning_message'] = "$failed_count students failed. Check errors below.";
                $_SESSION['promotion_errors'] = $errors;
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error during promotion: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
    
    // Bulk promote by level
    if (isset($_POST['bulk_promote'])) {
        try {
            $from_level = (int)$_POST['from_level'];
            $to_level = (int)$_POST['to_level'];
            $program_id = (int)$_POST['program_id'];
            $department_id = (int)$_POST['department_id'];
            $session_year = $_POST['session_year'];
            $promote_all = isset($_POST['promote_all']) ? true : false;
            $min_cgpa = !empty($_POST['min_cgpa']) ? floatval($_POST['min_cgpa']) : 0;
            
            // Build query to get eligible students
            $conditions = ["current_level = ?", "status = 'Active'"];
            $params = [$from_level];
            
            if ($program_id > 0) {
                $conditions[] = "program_id = ?";
                $params[] = $program_id;
            }
            
            if ($department_id > 0) {
                $conditions[] = "department_id = ?";
                $params[] = $department_id;
            }
            
            if (!$promote_all && $min_cgpa > 0) {
                $conditions[] = "cgpa >= ?";
                $params[] = $min_cgpa;
            }
            
            $where = implode(" AND ", $conditions);
            
            // Get eligible students
            $students = $pdo->prepare("
                SELECT student_id, matric_number, first_name, last_name, cgpa 
                FROM students 
                WHERE $where
                ORDER BY matric_number
            ");
            $students->execute($params);
            $eligible_students = $students->fetchAll();
            
            if (empty($eligible_students)) {
                throw new Exception("No eligible students found for promotion from Level $from_level to Level $to_level");
            }
            
            // Store in session for confirmation
            $_SESSION['pending_promotion'] = [
                'students' => $eligible_students,
                'from_level' => $from_level,
                'to_level' => $to_level,
                'new_session' => $session_year,
                'program_id' => $program_id,
                'department_id' => $department_id,
                'total' => count($eligible_students)
            ];
            
            $_SESSION['info_message'] = count($eligible_students) . " students ready for promotion. Please confirm.";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
    
    // Confirm bulk promotion
    if (isset($_POST['confirm_promotion'])) {
        try {
            $pending = $_SESSION['pending_promotion'] ?? null;
            
            if (!$pending) {
                throw new Exception("No pending promotion found");
            }
            
            $pdo->beginTransaction();
            
            $success_count = 0;
            $failed_count = 0;
            $errors = [];
            
            foreach ($pending['students'] as $student) {
                try {
                    $update = $pdo->prepare("
                        UPDATE students SET 
                            current_level = ?,
                            current_session = ?,
                            updated_at = NOW()
                        WHERE student_id = ?
                    ");
                    $update->execute([$pending['to_level'], $pending['new_session'], $student['student_id']]);
                    
                    // Log promotion
                    $log = $pdo->prepare("
                        INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Bulk Promotion', ?, ?, NOW())
                    ");
                    $log->execute([
                        $_SESSION['admin_id'],
                        "Promoted {$student['matric_number']} from Level {$pending['from_level']} to Level {$pending['to_level']}",
                        $_SERVER['REMOTE_ADDR']
                    ]);
                    
                    $success_count++;
                    
                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = "Student {$student['matric_number']}: " . $e->getMessage();
                }
            }
            
            $pdo->commit();
            
            // Clear pending promotion
            unset($_SESSION['pending_promotion']);
            
            $_SESSION['success_message'] = "Successfully promoted $success_count students to Level {$pending['to_level']}.";
            if ($failed_count > 0) {
                $_SESSION['warning_message'] = "$failed_count students failed.";
                $_SESSION['promotion_errors'] = $errors;
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error during promotion: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
    
    // Cancel promotion
    if (isset($_POST['cancel_promotion'])) {
        unset($_SESSION['pending_promotion']);
        $_SESSION['info_message'] = "Promotion cancelled.";
        header("Location: promotion.php");
        exit();
    }
    
    // Reset students (demote or repeat)
    if (isset($_POST['reset_students'])) {
        try {
            $student_ids = $_POST['student_ids'] ?? [];
            $reset_level = (int)$_POST['reset_level'];
            $reason = $_POST['reset_reason'];
            
            if (empty($student_ids)) {
                throw new Exception("No students selected");
            }
            
            $pdo->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            
            $update = $pdo->prepare("
                UPDATE students SET 
                    current_level = ?,
                    status = 'Active',
                    updated_at = NOW()
                WHERE student_id IN ($placeholders)
            ");
            
            $params = array_merge([$reset_level], $student_ids);
            $update->execute($params);
            
            // Log the reset
            foreach ($student_ids as $sid) {
                $log = $pdo->prepare("
                    INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                    VALUES (?, 'Student Reset', ?, ?, NOW())
                ");
                $log->execute([
                    $_SESSION['admin_id'],
                    "Reset student ID $sid to Level $reset_level. Reason: $reason",
                    $_SERVER['REMOTE_ADDR']
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = count($student_ids) . " students reset to Level $reset_level.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error resetting students: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
    
    // Graduation processing
    if (isset($_POST['process_graduation'])) {
        try {
            $program_id = (int)$_POST['program_id'];
            $graduating_level = (int)$_POST['graduating_level']; // Usually 400 or 500
            $session_year = $_POST['session_year'];
            
            // Get graduating students
            $students = $pdo->prepare("
                SELECT student_id, matric_number, first_name, last_name, cgpa 
                FROM students 
                WHERE current_level = ? AND program_id = ? AND status = 'Active'
                ORDER BY matric_number
            ");
            $students->execute([$graduating_level, $program_id]);
            $graduating_students = $students->fetchAll();
            
            if (empty($graduating_students)) {
                throw new Exception("No graduating students found");
            }
            
            // Store in session for confirmation
            $_SESSION['pending_graduation'] = [
                'students' => $graduating_students,
                'from_level' => $graduating_level,
                'session' => $session_year,
                'program_id' => $program_id,
                'total' => count($graduating_students)
            ];
            
            $_SESSION['info_message'] = count($graduating_students) . " students ready for graduation. Please confirm.";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
    
    // Confirm graduation
    if (isset($_POST['confirm_graduation'])) {
        try {
            $pending = $_SESSION['pending_graduation'] ?? null;
            
            if (!$pending) {
                throw new Exception("No pending graduation found");
            }
            
            $pdo->beginTransaction();
            
            $success_count = 0;
            
            foreach ($pending['students'] as $student) {
                // Update student status to Graduated
                $update = $pdo->prepare("
                    UPDATE students SET 
                        status = 'Graduated',
                        current_session = ?,
                        updated_at = NOW()
                    WHERE student_id = ?
                ");
                $update->execute([$pending['session'], $student['student_id']]);
                
                // Log graduation
                $log = $pdo->prepare("
                    INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                    VALUES (?, 'Student Graduation', ?, ?, NOW())
                ");
                $log->execute([
                    $_SESSION['admin_id'],
                    "Graduated {$student['matric_number']} with CGPA {$student['cgpa']}",
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $success_count++;
            }
            
            $pdo->commit();
            
            unset($_SESSION['pending_graduation']);
            
            $_SESSION['success_message'] = "Successfully processed graduation for $success_count students.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error processing graduation: " . $e->getMessage();
        }
        
        header("Location: promotion.php");
        exit();
    }
}

// Get filter parameters for student list
$filter_program = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$filter_department = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$filter_level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'Active';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for student list
$conditions = ["s.status = ?"];
$params = [$filter_status];

if ($filter_program > 0) {
    $conditions[] = "s.program_id = ?";
    $params[] = $filter_program;
}

if ($filter_department > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $filter_department;
}

if ($filter_level > 0) {
    $conditions[] = "s.current_level = ?";
    $params[] = $filter_level;
}

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

$where = implode(" AND ", $conditions);

// Get students for manual selection
$students = $pdo->prepare("
    SELECT 
        s.*,
        d.department_name,
        p.program_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE $where
    ORDER BY s.current_level, s.matric_number
    LIMIT 500
");
$students->execute($params);
$students_list = $students->fetchAll();

// Get filter data
$programs = $pdo->query("SELECT program_id, program_name, program_code FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];

// Get promotion statistics
$stats = [];
foreach ($levels as $level) {
    $count = $pdo->prepare("SELECT COUNT(*) FROM students WHERE current_level = ? AND status = 'Active'");
    $count->execute([$level]);
    $stats[$level] = $count->fetchColumn();
}

// Get current session
$current_session = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn();
if (!$current_session) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
}

// Check for pending promotion
$pending_promotion = $_SESSION['pending_promotion'] ?? null;
$pending_graduation = $_SESSION['pending_graduation'] ?? null;
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

<?php if (isset($_SESSION['warning_message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo $_SESSION['warning_message']; unset($_SESSION['warning_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['info_message'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['promotion_errors'])): ?>
    <div class="alert alert-danger">
        <h6><i class="fas fa-exclamation-triangle me-2"></i>Promotion Errors:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['promotion_errors'] as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['promotion_errors']); ?>
<?php endif; ?>

<!-- Pending Promotion Confirmation -->
<?php if ($pending_promotion): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Pending Promotion - Action Required</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p><strong><?php echo $pending_promotion['total']; ?> students</strong> ready to be promoted from 
                <strong>Level <?php echo $pending_promotion['from_level']; ?> to Level <?php echo $pending_promotion['to_level']; ?></strong></p>
                
                <div class="mb-3">
                    <h6>Student List:</h6>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                        <?php foreach ($pending_promotion['students'] as $student): ?>
                        <span class="badge bg-info m-1">
                            <?php echo htmlspecialchars($student['matric_number']); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <form method="POST">
                    <button type="submit" name="confirm_promotion" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-check me-2"></i>Confirm Promotion
                    </button>
                    <button type="submit" name="cancel_promotion" class="btn btn-secondary w-100">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Pending Graduation Confirmation -->
<?php if ($pending_graduation): ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Pending Graduation - Action Required</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p><strong><?php echo $pending_graduation['total']; ?> students</strong> ready for graduation from 
                <strong>Level <?php echo $pending_graduation['from_level']; ?></strong> for session <strong><?php echo $pending_graduation['session']; ?></strong></p>
                
                <div class="mb-3">
                    <h6>Graduating Students:</h6>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                        <?php foreach ($pending_graduation['students'] as $student): ?>
                        <span class="badge bg-success m-1">
                            <?php echo htmlspecialchars($student['matric_number']); ?> (CGPA: <?php echo $student['cgpa']; ?>)
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <form method="POST">
                    <button type="submit" name="confirm_graduation" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-graduation-cap me-2"></i>Confirm Graduation
                    </button>
                    <button type="submit" name="cancel_promotion" class="btn btn-secondary w-100">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-arrow-up me-2"></i>Student Promotion Management
    </h1>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkPromoteModal">
            <i class="fas fa-users me-2"></i>Bulk Promotion
        </button>
        <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#graduationModal">
            <i class="fas fa-graduation-cap me-2"></i>Process Graduation
        </button>
        <a href="manage_students.php" class="btn btn-info ms-2">
            <i class="fas fa-user-graduate me-2"></i>Manage Students
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <?php foreach ($levels as $level): ?>
    <div class="col-md-2">
        <div class="card <?php 
            echo $level == 100 ? 'bg-info' : 
                ($level == 200 ? 'bg-primary' : 
                ($level == 300 ? 'bg-success' : 
                ($level == 400 ? 'bg-warning' : 
                ($level == 500 ? 'bg-secondary' : 'bg-dark')))); 
        ?> text-white">
            <div class="card-body text-center">
                <h6 class="card-title">Level <?php echo $level; ?></h6>
                <h3><?php echo number_format($stats[$level]); ?></h3>
                <small>Active Students</small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Promotion Paths -->
<div class="row g-3 mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-road me-2"></i>Promotion Paths</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $promotion_paths = [
                        ['from' => 100, 'to' => 200, 'icon' => 'fa-arrow-right', 'color' => 'info'],
                        ['from' => 200, 'to' => 300, 'icon' => 'fa-arrow-right', 'color' => 'primary'],
                        ['from' => 300, 'to' => 400, 'icon' => 'fa-arrow-right', 'color' => 'success'],
                        ['from' => 400, 'to' => 500, 'icon' => 'fa-arrow-right', 'color' => 'warning'],
                        ['from' => 500, 'to' => 600, 'icon' => 'fa-arrow-right', 'color' => 'secondary'],
                    ];
                    
                    foreach ($promotion_paths as $path):
                        $eligible = $stats[$path['from']];
                        $can_promote = $eligible > 0;
                    ?>
                    <div class="col-md-2 mb-2">
                        <div class="card border-<?php echo $path['color']; ?> <?php echo !$can_promote ? 'opacity-50' : ''; ?>">
                            <div class="card-body text-center p-3">
                                <h5 class="text-<?php echo $path['color']; ?>">
                                    <?php echo $path['from']; ?> <i class="fas <?php echo $path['icon']; ?> mx-2"></i> <?php echo $path['to']; ?>
                                </h5>
                                <p class="mb-2"><?php echo number_format($eligible); ?> students eligible</p>
                                <button class="btn btn-sm btn-<?php echo $path['color']; ?> w-100" 
                                        onclick="quickPromote(<?php echo $path['from']; ?>, <?php echo $path['to']; ?>)"
                                        <?php echo !$can_promote ? 'disabled' : ''; ?>>
                                    <i class="fas fa-arrow-up me-1"></i>Promote All
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="col-md-2 mb-2">
                        <div class="card border-success">
                            <div class="card-body text-center p-3">
                                <h5 class="text-success">
                                    400 <i class="fas fa-graduation-cap mx-2"></i> Graduate
                                </h5>
                                <p class="mb-2"><?php echo number_format($stats[400]); ?> final year</p>
                                <button class="btn btn-sm btn-success w-100" 
                                        onclick="quickGraduate()">
                                    <i class="fas fa-graduation-cap me-1"></i>Graduate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Promotion Modal -->
<div class="modal fade" id="bulkPromoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Bulk Student Promotion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="bulkPromoteForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">From Level *</label>
                        <select class="form-control" name="from_level" id="fromLevel" required>
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">To Level *</label>
                        <select class="form-control" name="to_level" id="toLevel" required>
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Target level must be higher than current level</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Program (Optional)</label>
                        <select class="form-control" name="program_id">
                            <option value="0">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['program_id']; ?>">
                                <?php echo htmlspecialchars($prog['program_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Department (Optional)</label>
                        <select class="form-control" name="department_id">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>">
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Session Year *</label>
                        <select class="form-control" name="session_year" required>
                            <option value="">Select Session</option>
                            <?php 
                            $year = date('Y');
                            for ($y = $year; $y <= $year + 3; $y++):
                                $session = $y . '/' . ($y + 1);
                            ?>
                            <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="promote_all" id="promoteAll" value="1">
                            <label class="form-check-label" for="promoteAll">
                                Promote all students (ignore CGPA requirements)
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="cgpaField">
                        <label class="form-label fw-bold">Minimum CGPA Required</label>
                        <input type="number" class="form-control" name="min_cgpa" min="0" max="5" step="0.1" value="1.0">
                        <small class="text-muted">Students with CGPA below this will be excluded</small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will show eligible students for review. You will confirm before final promotion.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="bulk_promote" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Preview Eligible Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Graduation Modal -->
<div class="modal fade" id="graduationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i>Process Graduation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="graduationForm">
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
                        <label class="form-label fw-bold">Graduating Level *</label>
                        <select class="form-control" name="graduating_level" required>
                            <option value="400">400 Level (4-year program)</option>
                            <option value="500">500 Level (5-year program)</option>
                            <option value="600">600 Level (6-year program)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Graduation Session *</label>
                        <select class="form-control" name="session_year" required>
                            <option value="">Select Session</option>
                            <?php 
                            for ($y = $year; $y <= $year + 1; $y++):
                                $session = $y . '/' . ($y + 1);
                            ?>
                            <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Students will be marked as "Graduated" and removed from active student list.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="process_graduation" class="btn btn-success">
                            <i class="fas fa-graduation-cap me-2"></i>Preview Graduates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Manual Student Selection Modal -->
<div class="modal fade" id="manualPromoteModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-hand-pointer me-2"></i>Manual Student Promotion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="manualPromoteForm">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Current Level</label>
                            <select class="form-control" name="current_level" id="manualCurrentLevel" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $level): ?>
                                <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Target Level</label>
                            <select class="form-control" name="target_level" id="manualTargetLevel" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $level): ?>
                                <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">New Session</label>
                            <select class="form-control" name="new_session" required>
                                <option value="">Select Session</option>
                                <?php 
                                for ($y = $year; $y <= $year + 3; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Promotion Type</label>
                            <select class="form-control" name="promotion_type" required>
                                <option value="normal">Normal Promotion</option>
                                <option value="graduating">Graduating</option>
                                <option value="repeating">Repeating</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Students</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllStudents()">
                                Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">
                                Deselect All
                            </button>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                            <?php foreach ($students_list as $student): ?>
                            <div class="form-check">
                                <input class="form-check-input student-checkbox" type="checkbox" 
                                       name="student_ids[]" value="<?php echo $student['student_id']; ?>"
                                       id="student_<?php echo $student['student_id']; ?>">
                                <label class="form-check-label" for="student_<?php echo $student['student_id']; ?>">
                                    <strong><?php echo htmlspecialchars($student['matric_number']); ?></strong> - 
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    (Level <?php echo $student['current_level']; ?> - <?php echo htmlspecialchars($student['program_name']); ?>)
                                    <?php if ($student['cgpa'] > 0): ?>
                                    <span class="badge bg-info">CGPA: <?php echo $student['cgpa']; ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="promote_students" class="btn btn-primary" onclick="return confirmPromotion()">
                            <i class="fas fa-arrow-up me-2"></i>Promote Selected
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Reset Students Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Reset Students</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="resetForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reset to Level</label>
                        <select class="form-control" name="reset_level" required>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Reset</label>
                        <select class="form-control" name="reset_reason" required>
                            <option value="Academic probation">Academic Probation</option>
                            <option value="Failed promotion">Failed Promotion</option>
                            <option value="Repeating year">Repeating Year</option>
                            <option value="Administrative">Administrative</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <input type="hidden" name="student_ids" id="resetStudentIds">
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will reset selected students to a lower level. Use with caution.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_students" class="btn btn-danger">
                            <i class="fas fa-undo me-2"></i>Reset Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Student Filter Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Students</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Program</label>
                <select class="form-control" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo $prog['program_id']; ?>" <?php echo $filter_program == $prog['program_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($prog['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select class="form-control" name="department_id">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>" <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
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
                <label class="form-label">Status</label>
                <select class="form-control" name="status">
                    <option value="Active" <?php echo $filter_status == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Graduated" <?php echo $filter_status == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                    <option value="Inactive" <?php echo $filter_status == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Matric or name">
            </div>
            
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
                <a href="promotion.php" class="btn btn-secondary">
                    <i class="fas fa-redo me-1"></i>Reset
                </a>
                <button type="button" class="btn btn-info ms-2" onclick="openManualPromote()">
                    <i class="fas fa-hand-pointer me-1"></i>Manual Promotion
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Students List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Students (<?php echo count($students_list); ?> found)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Matric No</th>
                        <th>Name</th>
                        <th>Program/Department</th>
                        <th>Current Level</th>
                        <th>CGPA</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students_list as $student): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($student['matric_number']); ?></strong>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars($student['program_name']); ?></small>
                            <br>
                            <small class="text-muted"><?php echo htmlspecialchars($student['department_name']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $student['current_level'] == 100 ? 'info' : 
                                    ($student['current_level'] == 200 ? 'primary' : 
                                    ($student['current_level'] == 300 ? 'success' : 
                                    ($student['current_level'] == 400 ? 'warning' : 
                                    ($student['current_level'] == 500 ? 'secondary' : 'dark')))); 
                            ?>">
                                Level <?php echo $student['current_level']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($student['cgpa'] > 0): ?>
                                <span class="badge bg-<?php echo $student['cgpa'] >= 3.5 ? 'success' : ($student['cgpa'] >= 2.0 ? 'info' : 'danger'); ?>">
                                    <?php echo number_format($student['cgpa'], 2); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $student['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                <?php echo $student['status']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick="promoteSingle(<?php echo $student['student_id']; ?>, <?php echo $student['current_level']; ?>)">
                                    <i class="fas fa-arrow-up"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="resetSingle(<?php echo $student['student_id']; ?>)">
                                    <i class="fas fa-undo"></i>
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

<!-- JavaScript -->
<script>
// Validate level selection
document.getElementById('fromLevel')?.addEventListener('change', function() {
    const from = parseInt(this.value);
    const toSelect = document.getElementById('toLevel');
    
    Array.from(toSelect.options).forEach(opt => {
        if (opt.value) {
            const toVal = parseInt(opt.value);
            opt.disabled = toVal <= from;
        }
    });
});

document.getElementById('toLevel')?.addEventListener('change', function() {
    const to = parseInt(this.value);
    const fromSelect = document.getElementById('fromLevel');
    
    Array.from(fromSelect.options).forEach(opt => {
        if (opt.value) {
            const fromVal = parseInt(opt.value);
            opt.disabled = fromVal >= to;
        }
    });
});

// Toggle CGPA field
document.getElementById('promoteAll')?.addEventListener('change', function() {
    document.getElementById('cgpaField').style.display = this.checked ? 'none' : 'block';
});

// Quick promote path
function quickPromote(fromLevel, toLevel) {
    document.getElementById('fromLevel').value = fromLevel;
    document.getElementById('toLevel').value = toLevel;
    
    // Trigger change events to update disabled options
    document.getElementById('fromLevel').dispatchEvent(new Event('change'));
    document.getElementById('toLevel').dispatchEvent(new Event('change'));
    
    new bootstrap.Modal(document.getElementById('bulkPromoteModal')).show();
}

// Quick graduate
function quickGraduate() {
    new bootstrap.Modal(document.getElementById('graduationModal')).show();
}

// Open manual promotion
function openManualPromote() {
    new bootstrap.Modal(document.getElementById('manualPromoteModal')).show();
}

// Select all students
function selectAllStudents() {
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

// Deselect all students
function deselectAllStudents() {
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

// Promote single student
function promoteSingle(studentId, currentLevel) {
    document.getElementById('manualCurrentLevel').value = currentLevel;
    document.getElementById('manualTargetLevel').value = currentLevel + 100;
    
    // Uncheck all and check only this student
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.checked = cb.value == studentId;
    });
    
    new bootstrap.Modal(document.getElementById('manualPromoteModal')).show();
}

// Reset single student
function resetSingle(studentId) {
    document.getElementById('resetStudentIds').value = JSON.stringify([studentId]);
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}

// Confirm promotion
function confirmPromotion() {
    const selected = document.querySelectorAll('.student-checkbox:checked').length;
    if (selected === 0) {
        alert('Please select at least one student.');
        return false;
    }
    
    const fromLevel = document.getElementById('manualCurrentLevel').value;
    const toLevel = document.getElementById('manualTargetLevel').value;
    
    return confirm(`Promote ${selected} student(s) from Level ${fromLevel} to Level ${toLevel}?`);
}

// Validate manual promotion form
document.getElementById('manualPromoteForm')?.addEventListener('submit', function(e) {
    const fromLevel = parseInt(this.querySelector('select[name="current_level"]').value);
    const toLevel = parseInt(this.querySelector('select[name="target_level"]').value);
    
    if (toLevel <= fromLevel) {
        e.preventDefault();
        alert('Target level must be higher than current level.');
    }
});

// Validate bulk promotion form
document.getElementById('bulkPromoteForm')?.addEventListener('submit', function(e) {
    const fromLevel = parseInt(this.querySelector('select[name="from_level"]').value);
    const toLevel = parseInt(this.querySelector('select[name="to_level"]').value);
    
    if (toLevel <= fromLevel) {
        e.preventDefault();
        alert('Target level must be higher than current level.');
    }
});
</script>

<style>
.modal-header.bg-primary .btn-close-white,
.modal-header.bg-success .btn-close-white,
.modal-header.bg-info .btn-close-white,
.modal-header.bg-danger .btn-close-white {
    filter: brightness(0) invert(1);
}
.opacity-50 {
    opacity: 0.5;
}
.student-checkbox {
    margin-right: 10px;
}
</style>

<?php
require_once 'includes/footer.php';
?>