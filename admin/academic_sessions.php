<?php
// academic_sessions.php
ob_start();

// Include database connection and session check
require_once 'includes/header.php';

// Set page title
$page_title = "Academic Sessions";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new session
    if (isset($_POST['add_session'])) {
        try {
            $session_year = $_POST['session_year'];
            $semester = (int)$_POST['semester'];
            $session_name = $_POST['session_name'];
            $start_date = $_POST['start_date'] ?: null;
            $end_date = $_POST['end_date'] ?: null;
            
            // Check if session already exists (matches SQL unique constraint)
            $check = $pdo->prepare("SELECT session_id FROM academic_sessions WHERE session_year = ? AND semester = ?");
            $check->execute([$session_year, $semester]);
            
            if ($check->rowCount() > 0) {
                throw new Exception("Session for this year and semester already exists!");
            }
            
            $sql = "INSERT INTO academic_sessions (
                session_year, semester, session_name, start_date, end_date,
                registration_start, registration_end, add_drop_start, add_drop_end,
                lectures_start, lectures_end, exams_start, exams_end,
                break_start, break_end, results_deadline, is_current, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Planning')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $session_year, $semester, $session_name, $start_date, $end_date,
                $_POST['registration_start'] ?: null, $_POST['registration_end'] ?: null,
                $_POST['add_drop_start'] ?: null, $_POST['add_drop_end'] ?: null,
                $_POST['lectures_start'] ?: null, $_POST['lectures_end'] ?: null,
                $_POST['exams_start'] ?: null, $_POST['exams_end'] ?: null,
                $_POST['break_start'] ?: null, $_POST['break_end'] ?: null,
                $_POST['results_deadline'] ?: null
            ]);
            
            $_SESSION['success_message'] = "Academic session added successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: academic_sessions.php");
        exit();
    }
    
    // Update session
    if (isset($_POST['update_session'])) {
        try {
            $session_id = (int)$_POST['session_id'];
            
            $sql = "UPDATE academic_sessions SET
                session_year = ?, semester = ?, session_name = ?,
                start_date = ?, end_date = ?,
                registration_start = ?, registration_end = ?,
                add_drop_start = ?, add_drop_end = ?,
                lectures_start = ?, lectures_end = ?,
                exams_start = ?, exams_end = ?,
                break_start = ?, break_end = ?,
                results_deadline = ?, status = ?
                WHERE session_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['session_year'], $_POST['semester'], $_POST['session_name'],
                $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
                $_POST['registration_start'] ?: null, $_POST['registration_end'] ?: null,
                $_POST['add_drop_start'] ?: null, $_POST['add_drop_end'] ?: null,
                $_POST['lectures_start'] ?: null, $_POST['lectures_end'] ?: null,
                $_POST['exams_start'] ?: null, $_POST['exams_end'] ?: null,
                $_POST['break_start'] ?: null, $_POST['break_end'] ?: null,
                $_POST['results_deadline'] ?: null, $_POST['status'],
                $session_id
            ]);
            
            $_SESSION['success_message'] = "Academic session updated successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: academic_sessions.php");
        exit();
    }
    
    // Set as current session
    if (isset($_POST['set_current'])) {
        try {
            $session_id = (int)$_POST['session_id'];
            
            // First, set all sessions to not current
            $pdo->query("UPDATE academic_sessions SET is_current = 0");
            
            // Then set selected session as current and status to Active
            $stmt = $pdo->prepare("UPDATE academic_sessions SET is_current = 1, status = 'Active' WHERE session_id = ?");
            $stmt->execute([$session_id]);
            
            $_SESSION['success_message'] = "Current session updated successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: academic_sessions.php");
        exit();
    }
    
    // Delete session
    if (isset($_POST['delete_session'])) {
        try {
            $session_id = (int)$_POST['session_id'];
            
            // Get session details to check related records
            $session_info = $pdo->prepare("SELECT session_year FROM academic_sessions WHERE session_id = ?");
            $session_info->execute([$session_id]);
            $session_year = $session_info->fetchColumn();
            
            // Check if session has related registrations (matches foreign key constraint)
            $check_registrations = $pdo->prepare("SELECT COUNT(*) FROM course_registrations WHERE session_year = ?");
            $check_registrations->execute([$session_year]);
            
            if ($check_registrations->fetchColumn() > 0) {
                throw new Exception("Cannot delete session with existing course registrations!");
            }
            
            // Check if session has related results
            $check_results = $pdo->prepare("SELECT COUNT(*) FROM results WHERE session_year = ?");
            $check_results->execute([$session_year]);
            
            if ($check_results->fetchColumn() > 0) {
                throw new Exception("Cannot delete session with existing results!");
            }
            
            // Check if session has related student fees
            $check_fees = $pdo->prepare("SELECT COUNT(*) FROM student_fees WHERE session_year = ?");
            $check_fees->execute([$session_year]);
            
            if ($check_fees->fetchColumn() > 0) {
                throw new Exception("Cannot delete session with existing fee records!");
            }
            
            $stmt = $pdo->prepare("DELETE FROM academic_sessions WHERE session_id = ?");
            $stmt->execute([$session_id]);
            
            $_SESSION['success_message'] = "Academic session deleted successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: academic_sessions.php");
        exit();
    }
}

// Get all sessions
$sessions = $pdo->query("
    SELECT * FROM academic_sessions 
    ORDER BY 
        CASE 
            WHEN is_current = 1 THEN 0 
            ELSE 1 
        END,
        session_year DESC, 
        semester DESC
")->fetchAll();

// Get current session
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();

// Get session statistics
$stats = [
    'total' => count($sessions),
    'active' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Active'")->fetchColumn(),
    'planning' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Planning'")->fetchColumn(),
    'completed' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Completed'")->fetchColumn(),
    'archived' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Archived'")->fetchColumn(),
];

// Get registration activity
$registration_stats = [
    'open' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_start <= CURDATE() AND registration_end >= CURDATE()")->fetchColumn(),
    'upcoming' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_start > CURDATE()")->fetchColumn(),
    'closed' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_end < CURDATE()")->fetchColumn(),
];
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
        <i class="fas fa-calendar-alt me-2"></i>Academic Sessions
    </h1>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSessionModal">
            <i class="fas fa-plus me-2"></i>Add New Session
        </button>
        <a href="session_management.php" class="btn btn-info ms-2">
            <i class="fas fa-cog me-2"></i>Session Management
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title">Total Sessions</h6>
                <h3><?php echo $stats['total']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Active</h6>
                <h3><?php echo $stats['active']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">Planning</h6>
                <h3><?php echo $stats['planning']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">Completed</h6>
                <h3><?php echo $stats['completed']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <h6 class="card-title">Archived</h6>
                <h3><?php echo $stats['archived']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-dark text-white">
            <div class="card-body">
                <h6 class="card-title">Registration</h6>
                <h3><?php echo $registration_stats['open']; ?> Open</h3>
            </div>
        </div>
    </div>
</div>

<!-- Current Session Banner -->
<?php if ($current_session): ?>
<div class="alert alert-info mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Current Active Session:</strong> 
            <?php echo htmlspecialchars($current_session['session_name']); ?> 
            (<?php echo htmlspecialchars($current_session['session_year']); ?> - Semester <?php echo $current_session['semester']; ?>)
            <span class="badge bg-<?php 
                echo $current_session['status'] == 'Active' ? 'success' : 
                    ($current_session['status'] == 'Planning' ? 'warning' : 'secondary'); 
            ?> ms-2">
                <?php echo $current_session['status']; ?>
            </span>
        </div>
        <div class="col-md-4 text-end">
            <span class="me-3">
                <i class="fas fa-calendar-check"></i> 
                Registration: 
                <?php 
                if ($current_session['registration_start'] && $current_session['registration_end']) {
                    $today = date('Y-m-d');
                    if ($today < $current_session['registration_start']) {
                        echo '<span class="badge bg-warning">Starts ' . date('M d', strtotime($current_session['registration_start'])) . '</span>';
                    } elseif ($today > $current_session['registration_end']) {
                        echo '<span class="badge bg-danger">Closed</span>';
                    } else {
                        echo '<span class="badge bg-success">Open until ' . date('M d', strtotime($current_session['registration_end'])) . '</span>';
                    }
                } else {
                    echo '<span class="badge bg-secondary">Not Set</span>';
                }
                ?>
            </span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Sessions Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Academic Sessions</h5>
        <div>
            <span class="text-muted">Total: <?php echo count($sessions); ?> sessions</span>
        </div>
    </div>
    
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Session</th>
                        <th>Semester</th>
                        <th>Name</th>
                        <th>Duration</th>
                        <th>Registration</th>
                        <th>Lectures</th>
                        <th>Exams</th>
                        <th>Status</th>
                        <th>Current</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                    <tr class="<?php echo $session['is_current'] ? 'table-primary' : ''; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($session['session_year']); ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-info">Semester <?php echo $session['semester']; ?></span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($session['session_name']) ?: '—'; ?>
                        </td>
                        <td>
                            <small>
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?php echo $session['start_date'] ? date('M d', strtotime($session['start_date'])) : '—'; ?> - 
                                <?php echo $session['end_date'] ? date('M d, Y', strtotime($session['end_date'])) : '—'; ?>
                            </small>
                        </td>
                        <td>
                            <?php if ($session['registration_start'] && $session['registration_end']): ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-<?php 
                                        $today = date('Y-m-d');
                                        if ($today < $session['registration_start']) echo 'warning';
                                        elseif ($today > $session['registration_end']) echo 'danger';
                                        else echo 'success';
                                    ?> dropdown-toggle" data-bs-toggle="dropdown">
                                        <?php 
                                        if ($today < $session['registration_start']) echo 'Upcoming';
                                        elseif ($today > $session['registration_end']) echo 'Closed';
                                        else echo 'Open';
                                        ?>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><span class="dropdown-item-text small">
                                            <strong>Start:</strong> <?php echo date('M d, Y', strtotime($session['registration_start'])); ?>
                                        </span></li>
                                        <li><span class="dropdown-item-text small">
                                            <strong>End:</strong> <?php echo date('M d, Y', strtotime($session['registration_end'])); ?>
                                        </span></li>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-secondary">Not Set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($session['lectures_start']): ?>
                                <small><?php echo date('M d', strtotime($session['lectures_start'])); ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($session['exams_start']): ?>
                                <small><?php echo date('M d', strtotime($session['exams_start'])); ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $status_class = [
                                'Active' => 'success',
                                'Planning' => 'warning',
                                'Completed' => 'info',
                                'Archived' => 'secondary'
                            ][$session['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo $session['status']; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($session['is_current']): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check"></i> Current
                                </span>
                            <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                    <button type="submit" name="set_current" class="btn btn-sm btn-outline-primary" 
                                            onclick="return confirm('Set this as the current session? This will deactivate any other current session.')">
                                        <i class="fas fa-star"></i> Set
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick="editSession(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteSession(<?php echo $session['session_id']; ?>)">
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

<!-- Add Session Modal -->
<div class="modal fade" id="addSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Academic Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addSessionForm">
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
                            <label class="form-label fw-bold">Semester *</label>
                            <select class="form-control" name="semester" required>
                                <option value="">Select</option>
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Session Name</label>
                            <input type="text" class="form-control" name="session_name" 
                                   placeholder="e.g., 2024/2025 First Semester">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Start Date</label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session End Date</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    
                    <h6 class="mt-3 mb-2">Registration Period</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Start</label>
                            <input type="date" class="form-control" name="registration_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration End</label>
                            <input type="date" class="form-control" name="registration_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Add/Drop Start</label>
                            <input type="date" class="form-control" name="add_drop_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Add/Drop End</label>
                            <input type="date" class="form-control" name="add_drop_end">
                        </div>
                    </div>
                    
                    <h6 class="mt-3 mb-2">Academic Activities</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures Start</label>
                            <input type="date" class="form-control" name="lectures_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures End</label>
                            <input type="date" class="form-control" name="lectures_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams Start</label>
                            <input type="date" class="form-control" name="exams_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams End</label>
                            <input type="date" class="form-control" name="exams_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Break Start</label>
                            <input type="date" class="form-control" name="break_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Break End</label>
                            <input type="date" class="form-control" name="break_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Results Deadline</label>
                            <input type="date" class="form-control" name="results_deadline">
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_session" class="btn btn-primary ms-2">
                            <i class="fas fa-save me-2"></i>Save Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Session Modal -->
<div class="modal fade" id="editSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Academic Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editSessionForm">
                    <input type="hidden" name="session_id" id="edit_session_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Year *</label>
                            <select class="form-control" name="session_year" id="edit_session_year" required>
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
                            <label class="form-label fw-bold">Semester *</label>
                            <select class="form-control" name="semester" id="edit_semester" required>
                                <option value="">Select</option>
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-control" name="status" id="edit_status">
                                <option value="Planning">Planning</option>
                                <option value="Active">Active</option>
                                <option value="Completed">Completed</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Session Name</label>
                            <input type="text" class="form-control" name="session_name" id="edit_session_name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="edit_start_date">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session End Date</label>
                            <input type="date" class="form-control" name="end_date" id="edit_end_date">
                        </div>
                    </div>
                    
                    <h6 class="mt-3 mb-2">Registration Period</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Start</label>
                            <input type="date" class="form-control" name="registration_start" id="edit_reg_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration End</label>
                            <input type="date" class="form-control" name="registration_end" id="edit_reg_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Add/Drop Start</label>
                            <input type="date" class="form-control" name="add_drop_start" id="edit_add_drop_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Add/Drop End</label>
                            <input type="date" class="form-control" name="add_drop_end" id="edit_add_drop_end">
                        </div>
                    </div>
                    
                    <h6 class="mt-3 mb-2">Academic Activities</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures Start</label>
                            <input type="date" class="form-control" name="lectures_start" id="edit_lectures_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures End</label>
                            <input type="date" class="form-control" name="lectures_end" id="edit_lectures_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams Start</label>
                            <input type="date" class="form-control" name="exams_start" id="edit_exams_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams End</label>
                            <input type="date" class="form-control" name="exams_end" id="edit_exams_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Break Start</label>
                            <input type="date" class="form-control" name="break_start" id="edit_break_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Break End</label>
                            <input type="date" class="form-control" name="break_end" id="edit_break_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Results Deadline</label>
                            <input type="date" class="form-control" name="results_deadline" id="edit_results_deadline">
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_session" class="btn btn-info ms-2">
                            <i class="fas fa-save me-2"></i>Update Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Edit session function
function editSession(session) {
    document.getElementById('edit_session_id').value = session.session_id;
    document.getElementById('edit_session_year').value = session.session_year;
    document.getElementById('edit_semester').value = session.semester;
    document.getElementById('edit_session_name').value = session.session_name || '';
    document.getElementById('edit_status').value = session.status || 'Planning';
    document.getElementById('edit_start_date').value = session.start_date || '';
    document.getElementById('edit_end_date').value = session.end_date || '';
    document.getElementById('edit_reg_start').value = session.registration_start || '';
    document.getElementById('edit_reg_end').value = session.registration_end || '';
    document.getElementById('edit_add_drop_start').value = session.add_drop_start || '';
    document.getElementById('edit_add_drop_end').value = session.add_drop_end || '';
    document.getElementById('edit_lectures_start').value = session.lectures_start || '';
    document.getElementById('edit_lectures_end').value = session.lectures_end || '';
    document.getElementById('edit_exams_start').value = session.exams_start || '';
    document.getElementById('edit_exams_end').value = session.exams_end || '';
    document.getElementById('edit_break_start').value = session.break_start || '';
    document.getElementById('edit_break_end').value = session.break_end || '';
    document.getElementById('edit_results_deadline').value = session.results_deadline || '';
    
    new bootstrap.Modal(document.getElementById('editSessionModal')).show();
}

// Delete session
function deleteSession(sessionId) {
    if (confirm('Are you sure you want to delete this session? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="session_id" value="${sessionId}"><input type="hidden" name="delete_session" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Form validation
document.getElementById('addSessionForm')?.addEventListener('submit', function(e) {
    const sessionYear = this.querySelector('select[name="session_year"]').value;
    const semester = this.querySelector('select[name="semester"]').value;
    
    if (!sessionYear || !semester) {
        e.preventDefault();
        alert('Please select Session Year and Semester.');
        return false;
    }
    
    return confirm('Add new academic session?');
});

// Auto-generate session name
document.querySelector('select[name="session_year"]')?.addEventListener('change', function() {
    const semester = document.querySelector('select[name="semester"]').value;
    const sessionName = document.querySelector('input[name="session_name"]');
    
    if (this.value && semester) {
        const semText = semester == 1 ? 'First' : 'Second';
        sessionName.value = `${this.value} ${semText} Semester`;
    }
});

document.querySelector('select[name="semester"]')?.addEventListener('change', function() {
    const sessionYear = document.querySelector('select[name="session_year"]').value;
    const sessionName = document.querySelector('input[name="session_name"]');
    
    if (sessionYear && this.value) {
        const semText = this.value == 1 ? 'First' : 'Second';
        sessionName.value = `${sessionYear} ${semText} Semester`;
    }
});

// Date validation
document.querySelectorAll('input[type="date"]').forEach(input => {
    input.addEventListener('change', function() {
        const form = this.closest('form');
        const startDate = form?.querySelector('input[name="start_date"]')?.value;
        const endDate = form?.querySelector('input[name="end_date"]')?.value;
        
        if (startDate && endDate && startDate > endDate) {
            alert('Session end date must be after start date.');
            this.value = '';
        }
    });
});
</script>

<style>
.modal-header.bg-primary .btn-close-white,
.modal-header.bg-info .btn-close-white {
    filter: brightness(0) invert(1);
}
.table-primary {
    background-color: #cfe2ff !important;
}
</style>

<?php
// Include footer
require_once 'includes/footer.php';
?>