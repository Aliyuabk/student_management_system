<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// ==================== FIX 1: Dynamic Session from Database ====================
$session_query = "SELECT session_year, semester, session_name 
                  FROM academic_sessions 
                  WHERE is_current = 1 
                  AND status = 'Active' 
                  LIMIT 1";
$session_result = $conn->query($session_query);
$session_data = $session_result->fetch_assoc();

if ($session_data) {
    $current_session = $session_data['session_year'];
    $current_semester = $session_data['semester'];
    $session_name = $session_data['session_name'];
} else {
    $current_session = "2025/2026";
    $current_semester = 1;
    $session_name = "First Semester 2025/2026";
}

// ==================== FIX 2: Ensure $student is loaded ====================
if (!isset($student) || empty($student)) {
    $student_query = "SELECT s.*, d.department_name, p.program_name, p.program_code
                      FROM students s
                      LEFT JOIN departments d ON s.department_id = d.department_id
                      LEFT JOIN programs p ON s.program_id = p.program_id
                      WHERE s.student_id = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ==================== CGPA CALCULATION ====================
// Initialize CGPA variables
$cgpa_data = [
    'current_cgpa' => 0,
    'semester_gpa' => 0,
    'total_units' => 0,
    'total_points' => 0,
    'courses_passed' => 0,
    'courses_failed' => 0,
    'previous_cgpa' => 0,
    'grade_distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]
];

// Get all published results for CGPA calculation
$all_results_query = "SELECT r.*, c.course_code, c.course_title, c.credit_units
                      FROM results r
                      JOIN courses c ON r.course_id = c.course_id
                      WHERE r.student_id = ? AND r.is_published = 1
                      ORDER BY r.session_year DESC, r.semester DESC";
$stmt = $conn->prepare($all_results_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$all_results = $stmt->get_result();

$total_all_units = 0;
$total_all_points = 0;
$semester_results = [];

if ($all_results->num_rows > 0) {
    while ($row = $all_results->fetch_assoc()) {
        $units = $row['credit_units'];
        $grade_point = $row['grade_points'] ?? 0;
        
        $total_all_units += $units;
        $total_all_points += $grade_point * $units;
        
        // Track semester results for GPA calculation
        $key = $row['session_year'] . '_' . $row['semester'];
        if (!isset($semester_results[$key])) {
            $semester_results[$key] = [
                'session_year' => $row['session_year'],
                'semester' => $row['semester'],
                'units' => 0,
                'points' => 0,
                'gpa' => 0
            ];
        }
        $semester_results[$key]['units'] += $units;
        $semester_results[$key]['points'] += $grade_point * $units;
    }
    
    // Calculate GPA for each semester
    foreach ($semester_results as &$sem) {
        $sem['gpa'] = $sem['units'] > 0 ? $sem['points'] / $sem['units'] : 0;
    }
}

$cgpa_data['current_cgpa'] = $total_all_units > 0 ? $total_all_points / $total_all_units : 0;

// Get current semester results for GPA display
$current_results_query = "SELECT r.*, c.course_code, c.course_title, c.credit_units
                          FROM results r
                          JOIN courses c ON r.course_id = c.course_id
                          WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1";
$stmt = $conn->prepare($current_results_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$current_results = $stmt->get_result();

if ($current_results->num_rows > 0) {
    $sem_units = 0;
    $sem_points = 0;
    while ($row = $current_results->fetch_assoc()) {
        $units = $row['credit_units'];
        $grade_point = $row['grade_points'] ?? 0;
        $sem_units += $units;
        $sem_points += $grade_point * $units;
        
        if ($row['grade'] == 'F') {
            $cgpa_data['courses_failed']++;
        } else {
            $cgpa_data['courses_passed']++;
        }
        
        if (isset($cgpa_data['grade_distribution'][$row['grade']])) {
            $cgpa_data['grade_distribution'][$row['grade']]++;
        }
    }
    $cgpa_data['semester_gpa'] = $sem_units > 0 ? $sem_points / $sem_units : 0;
    $cgpa_data['total_units'] = $sem_units;
    $cgpa_data['total_points'] = $sem_points;
}

// Get previous CGPA
$prev_cgpa_query = "SELECT gpa FROM academic_records 
                    WHERE student_id = ? 
                    AND (session_year < ? OR (session_year = ? AND semester < ?))
                    ORDER BY session_year DESC, semester DESC 
                    LIMIT 1";
$stmt = $conn->prepare($prev_cgpa_query);
$stmt->bind_param("issi", $student_id, $current_session, $current_session, $current_semester);
$stmt->execute();
$prev_result = $stmt->get_result();
$cgpa_data['previous_cgpa'] = $prev_result->num_rows > 0 ? floatval($prev_result->fetch_assoc()['gpa']) : 0;

// ==================== Get course count ====================
$course_count_query = "SELECT COUNT(*) as total 
                       FROM course_registrations 
                       WHERE student_id = ? 
                       AND session_year = ? 
                       AND semester = ?";
$stmt = $conn->prepare($course_count_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$course_count = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== Get fee status ====================
$fee_query = "SELECT * FROM student_fees 
              WHERE student_id = ? 
              AND session_year = ? 
              AND semester = ?
              ORDER BY fee_id DESC 
              LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$fee_status = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== Get registered courses ====================
$courses_query = "SELECT c.course_code, c.course_title, c.credit_units, cr.registration_status 
                  FROM courses c 
                  JOIN course_registrations cr ON c.course_id = cr.course_id 
                  WHERE cr.student_id = ? 
                  AND cr.session_year = ? 
                  AND cr.semester = ?
                  ORDER BY c.course_code";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$courses = $stmt->get_result();
$stmt->close();

// ==================== Get total credit units ====================
$units_query = "SELECT SUM(c.credit_units) as total 
                FROM courses c 
                JOIN course_registrations cr ON c.course_id = cr.course_id 
                WHERE cr.student_id = ? 
                AND cr.session_year = ? 
                AND cr.semester = ?
                AND cr.registration_status = 'Approved'";
$stmt = $conn->prepare($units_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$total_units = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== Get recent transactions ====================
$recent_query = "SELECT p.*, sf.fee_type 
                 FROM payments p
                 LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                 WHERE p.student_id = ? 
                 ORDER BY p.payment_date DESC 
                 LIMIT 5";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$recent_payments = $stmt->get_result();
$stmt->close();

// Calculate completion steps
$steps_completed = 0;
$total_steps = 2;
if($fee_status && in_array($fee_status['status'], ['Paid', 'Partial'])) $steps_completed++;
if($course_count['total'] > 0) $steps_completed++;
$completion_percent = ($steps_completed / $total_steps) * 100;

function getGpaClass($gpa) {
    if($gpa >= 4.5) return 'excellent';
    if($gpa >= 3.5) return 'good';
    if($gpa >= 2.5) return 'average';
    if($gpa >= 1.5) return 'fair';
    return 'poor';
}
?>

<div class="fade-in">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-header">
            <div>
                <h1>Welcome back, <?php echo htmlspecialchars($student['first_name'] ?? 'Student'); ?>!</h1>
                <p><?php echo htmlspecialchars($student['program_name'] ?? ''); ?> | Level <?php echo $student['current_level'] ?? 100; ?></p>
                <div class="session-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?php echo htmlspecialchars($session_name); ?> (<?php echo htmlspecialchars($current_session); ?>)</span>
                </div>
            </div>
            <div class="progress-ring">
                <svg viewBox="0 0 100 100">
                    <circle class="progress-bg" cx="50" cy="50" r="45"/>
                    <circle class="progress-fill" cx="50" cy="50" r="45" 
                            style="stroke-dashoffset: <?php echo 283 - (283 * $completion_percent / 100); ?>"/>
                </svg>
                <div class="progress-text"><?php echo round($completion_percent); ?>%</div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-icon courses-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
            </div>
            <div class="stats-content">
                <span class="stats-label">Current Courses</span>
                <span class="stats-value"><?php echo $course_count['total'] ?? 0; ?></span>
                <span class="stats-sub"><?php echo $total_units['total'] ?: 0; ?> Credit Units</span>
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-icon gpa-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="stats-content">
                <span class="stats-label">Current CGPA</span>
                <span class="stats-value <?php echo getGpaClass($cgpa_data['current_cgpa']); ?>">
                    <?php echo number_format($cgpa_data['current_cgpa'], 2); ?>
                </span>
                <span class="stats-sub">Overall Academic Standing</span>
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-icon semester-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </div>
            <div class="stats-content">
                <span class="stats-label">Semester GPA</span>
                <span class="stats-value <?php echo getGpaClass($cgpa_data['semester_gpa']); ?>">
                    <?php echo number_format($cgpa_data['semester_gpa'], 2); ?>
                </span>
                <span class="stats-sub">Current Semester</span>
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-icon fee-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                </svg>
            </div>
            <div class="stats-content">
                <span class="stats-label">Fee Status</span>
                <span class="stats-value status-<?php echo $fee_status ? strtolower($fee_status['status']) : 'pending'; ?>">
                    <?php echo $fee_status ? $fee_status['status'] : 'Pending'; ?>
                </span>
                <?php if($fee_status): ?>
                <span class="stats-sub">
                    <?php if($fee_status['status'] == 'Paid'): ?>
                        ₦<?php echo number_format($fee_status['amount'] ?? 0); ?> paid
                    <?php elseif($fee_status['status'] == 'Partial'): ?>
                        ₦<?php echo number_format($fee_status['amount_paid'] ?? 0); ?> / ₦<?php echo number_format($fee_status['amount'] ?? 0); ?>
                    <?php else: ?>
                        ₦<?php echo number_format($fee_status['amount'] ?? 0); ?> due
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="dashboard-grid">
        <!-- Current Courses -->
        <div class="grid-item span-2">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-book-open"></i> Current Semester Courses</h3>
                    <a href="courses.php" class="view-all">View All →</a>
                </div>
                <div class="table-container">
                    <table class="courses-table">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th class="text-center">Units</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($courses->num_rows > 0): ?>
                                <?php while($course = $courses->fetch_assoc()): ?>
                                <tr>
                                    <td class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                                    <td class="text-center"><?php echo $course['credit_units']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($course['registration_status']); ?>">
                                            <?php echo $course['registration_status']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center no-data">
                                        <i class="fas fa-inbox"></i>
                                        <p>No courses registered for this semester</p>
                                        <a href="course-registration.php" class="btn-sm">Register Now</a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CGPA Details Card -->
        <div class="grid-item">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Academic Performance</h3>
                    <a href="result.php" class="view-all">View Results →</a>
                </div>
                <div class="cgpa-details">
                    <div class="cgpa-comparison">
                        <div class="cgpa-box">
                            <span class="cgpa-label">Previous CGPA</span>
                            <span class="cgpa-value"><?php echo number_format($cgpa_data['previous_cgpa'], 2); ?></span>
                        </div>
                        <div class="cgpa-arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="cgpa-box highlight">
                            <span class="cgpa-label">Current CGPA</span>
                            <span class="cgpa-value <?php echo getGpaClass($cgpa_data['current_cgpa']); ?>">
                                <?php echo number_format($cgpa_data['current_cgpa'], 2); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="semester-stats">
                        <div class="stat-row">
                            <span class="stat-row-label">Semester GPA:</span>
                            <span class="stat-row-value <?php echo getGpaClass($cgpa_data['semester_gpa']); ?>">
                                <?php echo number_format($cgpa_data['semester_gpa'], 2); ?>
                            </span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-row-label">Credit Units:</span>
                            <span class="stat-row-value"><?php echo $cgpa_data['total_units']; ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-row-label">Courses Passed:</span>
                            <span class="stat-row-value success"><?php echo $cgpa_data['courses_passed']; ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-row-label">Courses Failed:</span>
                            <span class="stat-row-value <?php echo $cgpa_data['courses_failed'] > 0 ? 'danger' : ''; ?>">
                                <?php echo $cgpa_data['courses_failed']; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Grade Distribution -->
                    <?php if(array_sum($cgpa_data['grade_distribution']) > 0): ?>
                    <div class="grade-distribution">
                        <div class="grade-dist-title">Grade Distribution</div>
                        <div class="grade-bars">
                            <?php foreach(['A', 'B', 'C', 'D', 'E', 'F'] as $grade): 
                                $count = $cgpa_data['grade_distribution'][$grade] ?? 0;
                                $total_courses = array_sum($cgpa_data['grade_distribution']);
                                $percentage = $total_courses > 0 ? ($count / $total_courses) * 100 : 0;
                                if($count > 0):
                            ?>
                            <div class="grade-bar-item">
                                <span class="grade-letter <?php echo strtolower($grade); ?>"><?php echo $grade; ?></span>
                                <div class="grade-bar-container">
                                    <div class="grade-bar-fill <?php echo strtolower($grade); ?>" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <span class="grade-count"><?php echo $count; ?></span>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="grid-item">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-receipt"></i> Recent Transactions</h3>
                    <a href="transactions.php" class="view-all">View All →</a>
                </div>
                <div class="recent-list">
                    <?php if($recent_payments->num_rows > 0): ?>
                        <?php while($payment = $recent_payments->fetch_assoc()): ?>
                        <div class="recent-item">
                            <div class="recent-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                </svg>
                            </div>
                            <div class="recent-details">
                                <div class="recent-title">
                                    <?php echo htmlspecialchars($payment['fee_type'] ?? 'Payment'); ?>
                                    <span class="amount">₦<?php echo number_format($payment['amount']); ?></span>
                                </div>
                                <div class="recent-meta">
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('d M Y, h:i A', strtotime($payment['payment_date'])); ?>
                                </div>
                            </div>
                            <div class="recent-status">
                                <span class="status-badge <?php echo strtolower($payment['status']); ?>">
                                    <?php echo $payment['status']; ?>
                                </span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-receipt"></i>
                            <p>No recent transactions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid-item">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="quick-actions-grid">
                    <a href="fees.php" class="quick-action-item">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                            </svg>
                        </div>
                        <span>Pay Fees</span>
                    </a>
                    <a href="course-registration.php" class="quick-action-item">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                            </svg>
                        </div>
                        <span>Register Courses</span>
                    </a>
                    <a href="result.php" class="quick-action-item">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                            </svg>
                        </div>
                        <span>View Results</span>
                    </a>
                    <a href="profile.php" class="quick-action-item">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                        </div>
                        <span>View Profile</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Semester GPA History -->
        <?php if(count($semester_results) > 1): ?>
        <div class="grid-item span-2">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> GPA History</h3>
                </div>
                <div class="gpa-history">
                    <?php foreach($semester_results as $sem): ?>
                    <div class="gpa-history-item">
                        <div class="gpa-history-label">
                            <?php echo $sem['session_year']; ?> - <?php echo $sem['semester'] == 1 ? 'First' : 'Second'; ?> Semester
                        </div>
                        <div class="gpa-history-bar">
                            <div class="gpa-history-fill" style="width: <?php echo ($sem['gpa'] / 5) * 100; ?>%; background: <?php 
                                if($sem['gpa'] >= 4.5) echo '#4caf50';
                                elseif($sem['gpa'] >= 3.5) echo '#2196f3';
                                elseif($sem['gpa'] >= 2.5) echo '#ff9800';
                                elseif($sem['gpa'] >= 1.5) echo '#ff5722';
                                else echo '#f44336';
                            ?>;"></div>
                        </div>
                        <div class="gpa-history-value <?php echo getGpaClass($sem['gpa']); ?>">
                            <?php echo number_format($sem['gpa'], 2); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    :root {
        --primary-color: #1e5631;
        --primary-dark: #164026;
        --primary-light: #2d7a4a;
        --primary-soft: rgba(30, 86, 49, 0.1);
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --text-dark: #1a1a1a;
        --text-light: #6c757d;
        --white: #ffffff;
        --gray-100: #f8f9fa;
        --gray-200: #e9ecef;
        --shadow: 0 4px 20px rgba(0,0,0,0.08);
        --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
        --transition: all 0.3s ease;
    }

    .welcome-section {
        margin-bottom: 30px;
    }

    .welcome-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }

    .welcome-section h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .welcome-section p {
        color: var(--text-light);
        margin-bottom: 10px;
    }

    .session-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 8px 16px;
        border-radius: 50px;
        font-size: 13px;
        font-weight: 600;
    }

    /* Progress Ring */
    .progress-ring {
        position: relative;
        width: 100px;
        height: 100px;
    }

    .progress-ring svg {
        transform: rotate(-90deg);
        width: 100px;
        height: 100px;
    }

    .progress-ring circle {
        fill: none;
        stroke-width: 8;
    }

    .progress-bg {
        stroke: var(--gray-200);
    }

    .progress-fill {
        stroke: var(--primary-color);
        stroke-linecap: round;
        stroke-dasharray: 283;
        transition: stroke-dashoffset 0.5s ease;
    }

    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 18px;
        font-weight: 700;
        color: var(--primary-color);
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .stats-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .stats-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }

    .stats-icon {
        width: 55px;
        height: 55px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .stats-icon svg {
        width: 28px;
        height: 28px;
        fill: var(--white);
    }

    .courses-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .gpa-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .semester-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .fee-icon { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }

    .stats-content {
        flex: 1;
    }

    .stats-label {
        color: var(--text-light);
        font-size: 13px;
        display: block;
        margin-bottom: 5px;
    }

    .stats-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--text-dark);
        display: block;
        line-height: 1.2;
    }

    .stats-value.excellent { color: #4caf50; }
    .stats-value.good { color: #2196f3; }
    .stats-value.average { color: #ff9800; }
    .stats-value.fair { color: #ff5722; }
    .stats-value.poor { color: #f44336; }
    .stats-value.status-paid { color: var(--success-color); }
    .stats-value.status-partial { color: var(--warning-color); }
    .stats-value.status-pending { color: var(--danger-color); }

    .stats-sub {
        color: var(--text-light);
        font-size: 12px;
        margin-top: 5px;
        display: block;
    }

    /* Dashboard Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 25px;
    }

    .grid-item.span-2 {
        grid-column: span 2;
    }

    .card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
        height: 100%;
    }

    .card-header {
        padding: 18px 20px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(to right, var(--primary-soft), transparent);
    }

    .card-header h3 {
        color: var(--text-dark);
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h3 i {
        color: var(--primary-color);
    }

    .view-all {
        color: var(--primary-color);
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
    }

    .view-all:hover {
        text-decoration: underline;
    }

    /* CGPA Details */
    .cgpa-details {
        padding: 20px;
    }

    .cgpa-comparison {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--gray-200);
    }

    .cgpa-box {
        text-align: center;
        flex: 1;
    }

    .cgpa-box.highlight {
        background: var(--primary-soft);
        padding: 10px;
        border-radius: 12px;
    }

    .cgpa-label {
        display: block;
        font-size: 12px;
        color: var(--text-light);
        margin-bottom: 5px;
    }

    .cgpa-value {
        display: block;
        font-size: 24px;
        font-weight: 700;
        color: var(--text-dark);
    }

    .cgpa-value.excellent { color: #4caf50; }
    .cgpa-value.good { color: #2196f3; }
    .cgpa-value.average { color: #ff9800; }
    .cgpa-value.fair { color: #ff5722; }
    .cgpa-value.poor { color: #f44336; }

    .cgpa-arrow {
        padding: 0 10px;
        color: var(--text-light);
    }

    .semester-stats {
        margin-bottom: 20px;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px dashed var(--gray-200);
    }

    .stat-row-label {
        color: var(--text-light);
        font-size: 13px;
    }

    .stat-row-value {
        font-weight: 600;
        color: var(--text-dark);
    }

    .stat-row-value.success { color: var(--success-color); }
    .stat-row-value.danger { color: var(--danger-color); }

    /* Grade Distribution */
    .grade-distribution {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--gray-200);
    }

    .grade-dist-title {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 12px;
    }

    .grade-bars {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .grade-bar-item {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .grade-letter {
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        font-weight: 700;
        font-size: 12px;
        color: white;
    }

    .grade-letter.a { background: #4caf50; }
    .grade-letter.b { background: #2196f3; }
    .grade-letter.c { background: #ff9800; }
    .grade-letter.d { background: #9c27b0; }
    .grade-letter.e { background: #ff5722; }
    .grade-letter.f { background: #f44336; }

    .grade-bar-container {
        flex: 1;
        height: 8px;
        background: var(--gray-200);
        border-radius: 4px;
        overflow: hidden;
    }

    .grade-bar-fill {
        height: 100%;
        transition: width 0.5s ease;
    }

    .grade-bar-fill.a { background: #4caf50; }
    .grade-bar-fill.b { background: #2196f3; }
    .grade-bar-fill.c { background: #ff9800; }
    .grade-bar-fill.d { background: #9c27b0; }
    .grade-bar-fill.e { background: #ff5722; }
    .grade-bar-fill.f { background: #f44336; }

    .grade-count {
        min-width: 30px;
        text-align: right;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-dark);
    }

    /* GPA History */
    .gpa-history {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .gpa-history-item {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .gpa-history-label {
        width: 180px;
        font-size: 13px;
        color: var(--text-dark);
    }

    .gpa-history-bar {
        flex: 1;
        height: 10px;
        background: var(--gray-200);
        border-radius: 5px;
        overflow: hidden;
    }

    .gpa-history-fill {
        height: 100%;
        border-radius: 5px;
        transition: width 0.5s ease;
    }

    .gpa-history-value {
        width: 50px;
        text-align: right;
        font-weight: 700;
        font-size: 14px;
    }

    .gpa-history-value.excellent { color: #4caf50; }
    .gpa-history-value.good { color: #2196f3; }
    .gpa-history-value.average { color: #ff9800; }
    .gpa-history-value.fair { color: #ff5722; }
    .gpa-history-value.poor { color: #f44336; }

    /* Table Styles */
    .table-container {
        overflow-x: auto;
    }

    .courses-table {
        width: 100%;
        border-collapse: collapse;
    }

    .courses-table th {
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 600;
        font-size: 12px;
        padding: 12px 15px;
        text-align: left;
    }

    .courses-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--text-dark);
        font-size: 13px;
    }

    .courses-table tbody tr:hover {
        background: var(--gray-100);
    }

    .course-code {
        font-weight: 600;
        color: var(--primary-color);
    }

    .text-center {
        text-align: center;
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .status-badge.approved,
    .status-badge.verified,
    .status-badge.paid {
        background: rgba(40, 167, 69, 0.15);
        color: var(--success-color);
    }

    .status-badge.pending {
        background: rgba(255, 193, 7, 0.15);
        color: #d97706;
    }

    .status-badge.rejected,
    .status-badge.failed {
        background: rgba(220, 53, 69, 0.15);
        color: var(--danger-color);
    }

    .no-data {
        text-align: center;
        padding: 40px 20px;
        color: var(--text-light);
    }

    .no-data i {
        font-size: 40px;
        margin-bottom: 15px;
        display: block;
        color: var(--gray-200);
    }

    .btn-sm {
        display: inline-block;
        padding: 8px 16px;
        background: var(--primary-color);
        color: var(--white);
        text-decoration: none;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-sm:hover {
        background: var(--primary-dark);
    }

    /* Recent Items */
    .recent-list {
        padding: 10px;
    }

    .recent-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border-bottom: 1px solid var(--gray-200);
    }

    .recent-item:last-child {
        border-bottom: none;
    }

    .recent-icon {
        width: 36px;
        height: 36px;
        background: var(--primary-soft);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .recent-icon svg {
        width: 18px;
        height: 18px;
        fill: var(--primary-color);
    }

    .recent-details {
        flex: 1;
    }

    .recent-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 4px;
    }

    .recent-title .amount {
        font-weight: 700;
        color: var(--primary-color);
    }

    .recent-meta {
        font-size: 11px;
        color: var(--text-light);
    }

    /* Quick Actions */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        padding: 20px;
    }

    .quick-action-item {
        background: var(--gray-100);
        border-radius: 12px;
        padding: 15px 10px;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
        color: var(--text-dark);
    }

    .quick-action-item:hover {
        background: var(--primary-color);
        color: var(--white);
        transform: translateY(-2px);
    }

    .action-icon {
        width: 40px;
        height: 40px;
        background: var(--white);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .action-icon svg {
        width: 20px;
        height: 20px;
        fill: var(--primary-color);
    }

    .quick-action-item:hover .action-icon svg {
        fill: var(--white);
    }

    .quick-action-item span {
        font-size: 12px;
        font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .dashboard-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .grid-item.span-2 {
            grid-column: span 2;
        }
    }

    @media (max-width: 768px) {
        .welcome-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .welcome-section h1 {
            font-size: 24px;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        .grid-item.span-2 {
            grid-column: span 1;
        }
        .gpa-history-label {
            width: 140px;
            font-size: 11px;
        }
    }

    @media (max-width: 480px) {
        .quick-actions-grid {
            grid-template-columns: 1fr;
        }
        .stats-card {
            flex-direction: column;
            text-align: center;
        }
        .cgpa-comparison {
            flex-direction: column;
            gap: 15px;
        }
        .cgpa-arrow {
            transform: rotate(90deg);
        }
        .gpa-history-item {
            flex-direction: column;
            align-items: flex-start;
        }
        .gpa-history-bar {
            width: 100%;
        }
    }
</style>

<?php require_once 'includes/footer.php'; ?>