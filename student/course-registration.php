<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];
$current_session = "2025/2026";
$error = '';
$success = '';

// Get current academic session info
$session_query = "SELECT * FROM academic_sessions WHERE session_year LIKE ? AND semester = 1";
$stmt = $conn->prepare($session_query);
$session_like = $current_session . "%";
$stmt->bind_param("s", $session_like);
$stmt->execute();
$current_academic = $stmt->get_result()->fetch_assoc();

// Get student's complete information including department and program
$student_query = "SELECT s.*, d.department_id as dept_id, d.department_name, p.program_name 
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student_data = $student_result->fetch_assoc();
$current_level = $student_data['current_level'] ?: 100;
$student_dept_id = $student_data['department_id'];

// Check if registration is open
$registration_open = false;
$registration_start = '';
$registration_end = '';

if($current_academic) {
    // Only check dates if they are not null
    if($current_academic['registration_start'] && $current_academic['registration_end']) {
        $today = date('Y-m-d');
        $registration_open = ($today >= $current_academic['registration_start'] && 
                             $today <= $current_academic['registration_end']);
        $registration_start = date('d M Y', strtotime($current_academic['registration_start']));
        $registration_end = date('d M Y', strtotime($current_academic['registration_end']));
    }
}

// Check if fees are paid (just for display, not required for registration)
$fee_query = "SELECT * FROM student_fees WHERE student_id = ? AND session_year = ? AND status = 'Paid'";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$fees_paid = $stmt->get_result()->num_rows > 0;

// Get available courses for current student based on department AND level
$available_query = "SELECT * FROM courses 
                    WHERE department_id = ? 
                    AND level = ? 
                    AND semester = 1 
                    ORDER BY is_core DESC, course_code";
$stmt = $conn->prepare($available_query);
$stmt->bind_param("ii", $student_dept_id, $current_level);
$stmt->execute();
$available_courses = $stmt->get_result();

// Get already registered courses
$registered_query = "SELECT course_id FROM course_registrations WHERE student_id = ? AND session_year = ? AND semester = 1";
$stmt = $conn->prepare($registered_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$registered_result = $stmt->get_result();
$registered_courses = [];
while($reg = $registered_result->fetch_assoc()) {
    $registered_courses[] = $reg['course_id'];
}

// Calculate current units
$units_query = "SELECT SUM(c.credit_units) as total FROM courses c 
                JOIN course_registrations cr ON c.course_id = cr.course_id 
                WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = 1";
$stmt = $conn->prepare($units_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$current_units = $stmt->get_result()->fetch_assoc()['total'] ?: 0;

// Handle registration submission - REMOVED fees_paid condition
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_courses']) && $registration_open) {
    $selected_courses = $_POST['courses'] ?? [];
    $total_units = 0;
    
    // Calculate total units
    foreach($selected_courses as $course_id) {
        $unit_query = "SELECT credit_units, is_core FROM courses WHERE course_id = ?";
        $stmt = $conn->prepare($unit_query);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $total_units += $course['credit_units'];
    }
    
    // Validate units
    if($total_units < 15) {
        $error = "Minimum credit units required is 15. You selected $total_units units.";
    } elseif($total_units > 22) {
        $error = "Maximum credit units allowed is 22. You selected $total_units units.";
    } else {
        // Begin transaction
        $conn->begin_transaction();
        try {
            // Delete existing registrations
            $delete = "DELETE FROM course_registrations WHERE student_id = ? AND session_year = ? AND semester = 1";
            $stmt = $conn->prepare($delete);
            $stmt->bind_param("is", $student_id, $current_session);
            $stmt->execute();
            
            // Insert new registrations
            $insert = "INSERT INTO course_registrations 
                      (student_id, course_id, session_year, semester, level, registration_date, registration_status) 
                      VALUES (?, ?, ?, 1, ?, NOW(), 'Pending')";
            $stmt = $conn->prepare($insert);
            
            foreach($selected_courses as $course_id) {
                $stmt->bind_param("iisi", $student_id, $course_id, $current_session, $current_level);
                $stmt->execute();
            }
            
            $conn->commit();
            $success = "Course registration submitted successfully!";
            
            // Refresh registered courses
            $registered_result = $conn->query("SELECT course_id FROM course_registrations WHERE student_id = $student_id AND session_year = '$current_session' AND semester = 1");
            $registered_courses = [];
            while($reg = $registered_result->fetch_assoc()) {
                $registered_courses[] = $reg['course_id'];
            }
            $current_units = $total_units;
            
        } catch(Exception $e) {
            $conn->rollback();
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

// Format session display
$session_display = $current_session;
$semester_display = "First Semester";
if($current_academic && isset($current_academic['session_name'])) {
    $session_display = $current_academic['session_name'];
}

// Check if registration is allowed (always true if registration is open now)
$allow_registration = $registration_open;
?>

<div class="fade-in">
    <div class="page-header">
        <h1>Course Registration</h1>
        <div class="session-info">
            <span class="session-badge"><?php echo htmlspecialchars($session_display); ?></span>
            <span class="level-badge">Level <?php echo $current_level; ?></span>
            <span class="semester-badge"><?php echo $semester_display; ?></span>
        </div>
    </div>

    <!-- Student Information Summary -->
    <div class="student-info-card">
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Department:</span>
                <span class="info-value"><?php echo htmlspecialchars($student_data['department_name'] ?? 'Not Assigned'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Program:</span>
                <span class="info-value"><?php echo htmlspecialchars($student_data['program_name'] ?? 'Not Assigned'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Current Level:</span>
                <span class="info-value"><?php echo $current_level; ?> Level</span>
            </div>
            <div class="info-item">
                <span class="info-label">Matric Number:</span>
                <span class="info-value"><?php echo htmlspecialchars($student_data['matric_number'] ?? 'N/A'); ?></span>
            </div>
        </div>
    </div>

    <!-- Registration Status -->
    <div class="status-bar">
        <div class="status-item <?php echo $registration_open ? 'active' : 'inactive'; ?>">
            <div class="status-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
            </div>
            <div class="status-content">
                <span class="status-label">Registration Status</span>
                <span class="status-value <?php echo $registration_open ? 'open' : 'closed'; ?>">
                    <?php echo $registration_open ? 'OPEN' : 'CLOSED'; ?>
                </span>
            </div>
        </div>

        <div class="status-item <?php echo $fees_paid ? 'active' : 'inactive'; ?>">
            <div class="status-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                </svg>
            </div>
            <div class="status-content">
                <span class="status-label">Fee Payment</span>
                <span class="status-value <?php echo $fees_paid ? 'paid' : 'unpaid'; ?>">
                    <?php echo $fees_paid ? 'PAID' : 'NOT PAID'; ?>
                </span>
            </div>
        </div>

        <div class="status-item units <?php echo $current_units >= 15 && $current_units <= 22 ? 'valid' : 'invalid'; ?>">
            <div class="status-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div class="status-content">
                <span class="status-label">Credit Units</span>
                <span class="status-value">
                    <?php echo $current_units; ?>/22
                </span>
            </div>
        </div>
    </div>

    <?php if($error): ?>
        <div class="alert error">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if($success): ?>
        <div class="alert success">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
            </svg>
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if(!$registration_open): ?>
        <div class="warning-message">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <div>
                <h3>Registration is currently closed</h3>
                <p>Course registration for <strong><?php echo htmlspecialchars($session_display); ?> - Level <?php echo $current_level; ?></strong> is only available during the registration period.</p>
                <?php if($current_academic && $current_academic['registration_start'] && $current_academic['registration_end']): ?>
                <p class="small">Registration Period: <?php echo $registration_start; ?> - <?php echo $registration_end; ?></p>
                <?php else: ?>
                <p class="small">Registration period has not been set for this session. Please contact your academic advisor.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif($allow_registration): ?>
        <?php if(!$fees_paid): ?>
        <div class="info-message">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <div>
                <h3>Registration is allowed</h3>
                <p>You can register for courses now. However, please note that your registration will be subject to approval only after fee payment.</p>
                <a href="fees.php" class="btn-link">Pay Fees Now →</a>
            </div>
        </div>
        <?php endif; ?>
        
    <form method="POST" action="" id="registrationForm">
        <div class="card">
            <div class="card-header">
                <h3>Available Courses - <?php echo htmlspecialchars($student_data['department_name'] ?? 'Your Department'); ?> (Level <?php echo $current_level; ?>)</h3>
                <div class="selected-info">
                    <span>Selected: <span id="selectedCount"><?php echo count($registered_courses); ?></span> courses</span>
                    <span>Total Units: <span id="totalUnits"><?php echo $current_units; ?></span>/22</span>
                </div>
            </div>

            <div class="courses-list">
                <?php 
                if($available_courses && $available_courses->num_rows > 0) {
                    $available_courses->data_seek(0);
                    while($course = $available_courses->fetch_assoc()): 
                        $is_registered = in_array($course['course_id'], $registered_courses);
                        $is_disabled = $course['is_core'] && $is_registered; // Core courses can't be unregistered
                ?>
                <div class="course-item <?php echo $is_registered ? 'selected' : ''; ?>" 
                     onclick="toggleCourse(this, <?php echo $course['course_id']; ?>, <?php echo $course['credit_units']; ?>, <?php echo $course['is_core'] ? 'true' : 'false'; ?>)">
                    <div class="course-checkbox">
                        <input type="checkbox" 
                               name="courses[]" 
                               value="<?php echo $course['course_id']; ?>"
                               data-units="<?php echo $course['credit_units']; ?>"
                               data-core="<?php echo $course['is_core']; ?>"
                               <?php echo $is_registered ? 'checked' : ''; ?>
                               <?php echo $is_disabled ? 'disabled' : ''; ?>
                               onchange="updateTotals()">
                    </div>
                    <div class="course-details">
                        <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                        <div class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></div>
                        <?php if($course['is_core']): ?>
                            <span class="course-tag core">Core Course</span>
                        <?php endif; ?>
                    </div>
                    <div class="course-meta">
                        <span class="credit-badge"><?php echo $course['credit_units']; ?> Credits</span>
                        <span class="level-badge-small">Level <?php echo $course['level']; ?></span>
                    </div>
                </div>
                <?php 
                    endwhile;
                } else { 
                ?>
                <div class="no-courses">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <h3>No Courses Available</h3>
                    <p>There are no courses available for registration at Level <?php echo $current_level; ?> in your department (<?php echo htmlspecialchars($student_data['department_name'] ?? 'N/A'); ?>).</p>
                    <p class="small">Please contact your academic advisor if you believe this is an error.</p>
                </div>
                <?php } ?>
            </div>

            <?php if($available_courses && $available_courses->num_rows > 0): ?>
            <div class="card-footer">
                <div class="unit-warning" id="unitWarning" style="display: none;">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <span id="warningMessage"></span>
                </div>
                
                <button type="submit" name="register_courses" class="btn-primary" onclick="return validateRegistration()">
                    Submit Registration for Level <?php echo $current_level; ?>
                    <svg viewBox="0 0 24 24">
                        <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                    </svg>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <!-- Registration Guidelines -->
    <div class="guidelines-card">
        <h4>Registration Guidelines for Level <?php echo $current_level; ?> - <?php echo htmlspecialchars($session_display); ?></h4>
        <ul>
            <li>Department: <strong><?php echo htmlspecialchars($student_data['department_name'] ?? 'N/A'); ?></strong></li>
            <li>Current Level: <strong><?php echo $current_level; ?></strong></li>
            <li>Minimum credit units required: <strong>15</strong></li>
            <li>Maximum credit units allowed: <strong>22</strong></li>
            <li>Core courses are mandatory and cannot be dropped</li>
            <li>Registration is subject to approval by your academic advisor</li>
            <li>Changes can be made during the add/drop period</li>
            <li>Session: <strong><?php echo htmlspecialchars($session_display); ?></strong> - <strong><?php echo $semester_display; ?></strong></li>
            <?php if(!$fees_paid): ?>
            <li class="warning-note"><strong>Note:</strong> Registration will be pending approval until fees are paid</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 30px;
    }

    .page-header h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin: 0;
    }

    .session-info {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .session-badge, .level-badge, .semester-badge {
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 500;
        white-space: nowrap;
    }

    .session-badge {
        background: var(--primary-color);
        color: var(--white);
    }

    .level-badge {
        background: #2196f3;
        color: var(--white);
    }

    .semester-badge {
        background: #ff9800;
        color: var(--white);
    }

    .student-info-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 30px;
        color: white;
        box-shadow: var(--shadow);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .info-label {
        font-size: 12px;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 16px;
        font-weight: 600;
    }

    .status-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .status-item {
        background: var(--white);
        border-radius: 12px;
        padding: 15px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .status-item.active {
        border-left: 4px solid var(--success-color);
    }

    .status-item.inactive {
        border-left: 4px solid var(--danger-color);
        opacity: 0.7;
    }

    .status-item.units {
        border-left: 4px solid var(--primary-color);
    }
    
    .status-item.units.valid {
        border-left: 4px solid var(--success-color);
    }
    
    .status-item.units.invalid {
        border-left: 4px solid var(--warning-color);
    }

    .status-icon {
        width: 40px;
        height: 40px;
        background: var(--primary-soft);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .status-icon svg {
        width: 20px;
        height: 20px;
        fill: var(--primary-color);
    }

    .status-content {
        flex: 1;
    }

    .status-label {
        color: var(--text-light);
        font-size: 12px;
        display: block;
        margin-bottom: 4px;
    }

    .status-value {
        font-size: 16px;
        font-weight: 600;
    }

    .status-value.open, .status-value.paid {
        color: var(--success-color);
    }

    .status-value.closed, .status-value.unpaid {
        color: var(--danger-color);
    }

    .alert {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        animation: slideIn 0.5s ease;
    }

    .alert.error {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid #c62828;
    }

    .alert.success {
        background: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid #2e7d32;
    }

    .alert svg {
        width: 24px;
        height: 24px;
        fill: currentColor;
    }

    .info-message {
        background: #e3f2fd;
        border-left: 4px solid #2196f3;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .info-message svg {
        width: 40px;
        height: 40px;
        fill: #2196f3;
        flex-shrink: 0;
    }

    .info-message h3 {
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .info-message p {
        color: var(--text-light);
    }

    .warning-message {
        background: #fff3e0;
        border-left: 4px solid var(--warning-color);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .warning-message svg {
        width: 40px;
        height: 40px;
        fill: var(--warning-color);
        flex-shrink: 0;
    }

    .warning-message h3 {
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .warning-message p {
        color: var(--text-light);
    }

    .warning-message .small {
        font-size: 13px;
        margin-top: 5px;
    }

    .btn-link {
        color: var(--primary-color);
        text-decoration: none;
        font-weight: 600;
        display: inline-block;
        margin-top: 8px;
    }

    .btn-link:hover {
        text-decoration: underline;
    }

    .card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .card-header {
        padding: 20px 25px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        background: linear-gradient(to right, var(--primary-soft), transparent);
    }

    .card-header h3 {
        color: var(--text-dark);
        font-size: 18px;
        margin: 0;
    }

    .selected-info {
        display: flex;
        gap: 20px;
        background: var(--white);
        padding: 8px 20px;
        border-radius: 30px;
        box-shadow: var(--shadow-sm);
    }

    .selected-info span {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-dark);
    }

    .courses-list {
        max-height: 550px;
        overflow-y: auto;
        padding: 20px;
    }

    .course-item {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 15px;
        border: 1px solid var(--gray-200);
        border-radius: 12px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: var(--transition);
    }

    .course-item:hover {
        border-color: var(--primary-color);
        background: var(--primary-soft);
        transform: translateX(5px);
    }

    .course-item.selected {
        background: var(--primary-soft);
        border-color: var(--primary-color);
    }

    .course-checkbox {
        flex-shrink: 0;
    }

    .course-checkbox input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--primary-color);
    }

    .course-checkbox input[type="checkbox"]:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .course-details {
        flex: 1;
    }

    .course-code {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 4px;
        font-size: 16px;
    }

    .course-title {
        color: var(--text-dark);
        font-size: 14px;
        margin-bottom: 4px;
    }

    .course-tag {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        background: var(--primary-color);
        color: var(--white);
    }

    .course-meta {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .credit-badge {
        background: var(--gray-100);
        color: var(--text-light);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .level-badge-small {
        background: #e3f2fd;
        color: #1976d2;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .no-courses {
        text-align: center;
        padding: 60px 20px;
    }

    .no-courses svg {
        width: 80px;
        height: 80px;
        fill: var(--gray-400);
        margin-bottom: 20px;
    }

    .no-courses h3 {
        color: var(--text-dark);
        margin-bottom: 10px;
        font-size: 20px;
    }

    .no-courses p {
        color: var(--text-light);
        margin-bottom: 5px;
    }

    .no-courses .small {
        font-size: 13px;
        color: var(--text-light);
    }

    .card-footer {
        padding: 20px 25px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .unit-warning {
        background: #fff3e0;
        padding: 10px 15px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--warning-color);
        font-size: 14px;
        flex: 1;
    }

    .unit-warning svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
        flex-shrink: 0;
    }

    .btn-primary {
        background: var(--primary-color);
        color: var(--white);
        border: none;
        padding: 14px 32px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
    }

    .btn-primary:hover:not(:disabled) {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(46, 125, 50, 0.2);
    }

    .btn-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-primary svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
    }

    .guidelines-card {
        background: var(--white);
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        margin-top: 30px;
    }

    .guidelines-card h4 {
        color: var(--text-dark);
        margin-bottom: 15px;
        font-size: 16px;
    }

    .guidelines-card ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 10px;
    }

    .guidelines-card li {
        color: var(--text-light);
        padding: 8px 0;
        padding-left: 24px;
        position: relative;
        font-size: 14px;
    }

    .guidelines-card li::before {
        content: '•';
        color: var(--primary-color);
        font-weight: bold;
        position: absolute;
        left: 8px;
    }
    
    .guidelines-card li.warning-note {
        color: #f57c00;
        font-weight: 500;
    }
    
    .guidelines-card li.warning-note::before {
        color: #f57c00;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .page-header h1 {
            font-size: 24px;
        }

        .session-info {
            width: 100%;
        }

        .session-badge, .level-badge, .semester-badge {
            flex: 1;
            text-align: center;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .status-bar {
            grid-template-columns: 1fr;
        }

        .course-item {
            flex-wrap: wrap;
        }

        .course-meta {
            width: 100%;
            justify-content: flex-end;
        }

        .card-footer {
            flex-direction: column;
        }

        .btn-primary {
            width: 100%;
            justify-content: center;
        }

        .guidelines-card ul {
            grid-template-columns: 1fr;
        }
        
        .info-message, .warning-message {
            flex-direction: column;
            text-align: center;
        }
    }

    @media (max-width: 480px) {
        .session-info {
            flex-direction: column;
        }

        .selected-info {
            flex-direction: column;
            gap: 10px;
            width: 100%;
            text-align: center;
        }
    }
</style>

<script>
let selectedCourses = [];
let totalUnits = <?php echo $current_units; ?>;

function toggleCourse(element, courseId, units, isCore) {
    const checkbox = element.querySelector('input[type="checkbox"]');
    
    // Don't allow unchecking core courses
    if(isCore && checkbox.checked) {
        alert('Core courses cannot be unregistered.');
        return;
    }
    
    checkbox.checked = !checkbox.checked;
    
    if(checkbox.checked) {
        element.classList.add('selected');
        selectedCourses.push(courseId);
        totalUnits += units;
    } else {
        element.classList.remove('selected');
        selectedCourses = selectedCourses.filter(id => id !== courseId);
        totalUnits -= units;
    }
    
    updateDisplay();
}

function updateTotals() {
    const checkboxes = document.querySelectorAll('input[name="courses[]"]:checked');
    let count = 0;
    let units = 0;
    
    checkboxes.forEach(cb => {
        if(!cb.disabled) {
            count++;
            units += parseInt(cb.dataset.units);
        }
    });
    
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('totalUnits').textContent = units;
    totalUnits = units;
    
    // Update status display
    const statusValue = document.querySelector('.status-item.units .status-value');
    const statusItem = document.querySelector('.status-item.units');
    
    if(statusValue) {
        statusValue.textContent = units + '/22';
    }
    
    if(statusItem) {
        if(units >= 15 && units <= 22) {
            statusItem.classList.add('valid');
            statusItem.classList.remove('invalid');
        } else {
            statusItem.classList.add('invalid');
            statusItem.classList.remove('valid');
        }
    }
    
    // Show/hide warning
    const warning = document.getElementById('unitWarning');
    const warningMsg = document.getElementById('warningMessage');
    
    if(units < 15) {
        warning.style.display = 'flex';
        warningMsg.textContent = `Minimum 15 credits required. You need ${15 - units} more credits.`;
    } else if(units > 22) {
        warning.style.display = 'flex';
        warningMsg.textContent = `Maximum 22 credits allowed. You have ${units - 22} excess credits.`;
    } else {
        warning.style.display = 'none';
    }
}

function validateRegistration() {
    if(totalUnits < 15) {
        alert('Minimum credit units required is 15. You have selected ' + totalUnits + ' units.');
        return false;
    }
    
    if(totalUnits > 22) {
        alert('Maximum credit units allowed is 22. You have selected ' + totalUnits + ' units.');
        return false;
    }
    
    <?php if(!$fees_paid): ?>
    return confirm('Note: You haven\'t paid your fees yet. Your registration will be recorded but pending approval until fees are paid.\n\nSubmit registration for Level <?php echo $current_level; ?> with ' + totalUnits + ' units?');
    <?php else: ?>
    return confirm('Submit registration for Level <?php echo $current_level; ?> with ' + totalUnits + ' units?');
    <?php endif; ?>
}

function updateDisplay() {
    updateTotals();
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    updateTotals();
});
</script>

<?php require_once 'includes/footer.php'; ?>