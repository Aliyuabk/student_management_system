<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// ==================== FIX 1: Dynamic Session from Database ====================
// Get current session from academic_sessions table instead of hardcoding
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
    // Fallback to hardcoded only if no active session in DB
    $current_session = "2025/2026";
    $current_semester = 1;
    $session_name = "First Semester 2025/2026";
}

// ==================== FIX 2: Ensure $student is loaded ====================
// If header.php didn't load student data, fetch it here
if (!isset($student) || empty($student)) {
    $student_query = "SELECT s.*, d.department_name, p.program_name 
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

// ==================== FIX 3: Check Payment Status ====================
$fee_query = "SELECT * FROM student_fees 
              WHERE student_id = ? 
              AND session_year = ? 
              AND semester = ?
              ORDER BY fee_id DESC 
              LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$fee_result = $stmt->get_result();
$fee_status = $fee_result->fetch_assoc();
$stmt->close();

// ==================== FIX 4: Check Course Registration (Approved only) ====================
// Only count APPROVED registrations as completed
$course_reg_query = "SELECT COUNT(*) as total, 
                            GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as course_codes
                     FROM course_registrations cr
                     LEFT JOIN courses c ON cr.course_id = c.course_id
                     WHERE cr.student_id = ? 
                     AND cr.session_year = ? 
                     AND cr.semester = ?
                     AND cr.registration_status = 'Approved'";
$stmt = $conn->prepare($course_reg_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$course_reg = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Also get pending/rejected registrations count for display
$pending_reg_query = "SELECT COUNT(*) as pending_count 
                      FROM course_registrations 
                      WHERE student_id = ? 
                      AND session_year = ? 
                      AND semester = ?
                      AND registration_status = 'Pending'";
$stmt = $conn->prepare($pending_reg_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$pending_reg = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== FIX 5: Check Pending Payments ====================
$pending_query = "SELECT COUNT(*) as total, 
                         SUM(amount) as total_amount
                  FROM payments 
                  WHERE student_id = ? 
                  AND status = 'Pending'";
$stmt = $conn->prepare($pending_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate completion steps
$steps_completed = 0;
$total_steps = 2;
if($fee_status && $fee_status['status'] == 'Paid') $steps_completed++;
if($course_reg['total'] > 0) $steps_completed++;

// Calculate completion percentage
$completion_percent = ($steps_completed / $total_steps) * 100;
?>

<div class="fade-in">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="welcome-content">
            <h1>Welcome back, <?php echo htmlspecialchars($student['first_name'] ?? 'Student'); ?> <span class="wave">👋</span></h1>
            <p><?php echo $steps_completed; ?>/<?php echo $total_steps; ?> registration steps completed</p>
            <div class="session-info">
                <i class="fas fa-calendar-alt"></i>
                <span><?php echo htmlspecialchars($session_name); ?> (<?php echo htmlspecialchars($current_session); ?>)</span>
            </div>
            <small class="note">*Please note hostel accommodation is not compulsory and it depends on eligibility and availability.</small>
        </div>
        <div class="welcome-actions">
            <a href="dashboard.php" class="btn-primary">
                <svg viewBox="0 0 24 24">
                    <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                </svg>
                Proceed to Dashboard
            </a>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-section">
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: <?php echo $completion_percent; ?>%;"></div>
        </div>
        <span class="progress-text"><?php echo round($completion_percent); ?>% Complete</span>
    </div>

    <!-- Steps -->
    <div class="steps-container">
        <div class="step-card <?php echo ($fee_status && $fee_status['status'] == 'Paid') ? 'completed' : ''; ?>">
            <div class="step-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                </svg>
            </div>
            <div class="step-content">
                <h3>Fees</h3>
                <p>Complete your fee payment</p>
                <?php if($fee_status && $fee_status['status'] == 'Paid'): ?>
                    <span class="step-badge completed">Completed ✓</span>
                    <div class="step-detail">
                        Amount Paid: ₦<?php echo number_format($fee_status['amount_paid'] ?? 0); ?>
                    </div>
                <?php else: ?>
                    <span class="step-badge pending">Pending</span>
                    <?php if($fee_status): ?>
                    <div class="step-detail">
                        Amount Due: ₦<?php echo number_format($fee_status['amount']); ?>
                        <?php if($fee_status['status'] == 'Partial'): ?>
                        <br><small>Partially Paid: ₦<?php echo number_format($fee_status['amount_paid'] ?? 0); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="step-detail">No fee record found</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="step-card <?php echo ($course_reg['total'] > 0) ? 'completed' : ''; ?>">
            <div class="step-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v4h-2v-4H9V9h4V5h2v4h4v2z"/>
                </svg>
            </div>
            <div class="step-content">
                <h3>Courses</h3>
                <p>Register for your courses</p>
                <?php if($course_reg['total'] > 0): ?>
                    <span class="step-badge completed">Completed ✓</span>
                    <div class="step-detail">
                        <?php echo $course_reg['total']; ?> course(s) registered
                        <?php if($course_reg['course_codes']): ?>
                        <br><small><?php echo htmlspecialchars($course_reg['course_codes']); ?></small>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="step-badge pending">Pending</span>
                    <?php if($pending_reg['pending_count'] > 0): ?>
                    <div class="step-detail">
                        <span class="text-warning"><?php echo $pending_reg['pending_count']; ?> registration(s) awaiting approval</span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Trouble Section -->
    <?php if($pending['total'] > 0): ?>
    <div class="payment-trouble">
        <div class="trouble-content">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <div>
                <h3>Having Trouble with Payment Verification?</h3>
                <p>You have <?php echo $pending['total']; ?> pending payment(s) totaling ₦<?php echo number_format($pending['total_amount'] ?? 0); ?>. Most payments are verified automatically. If yours has not gone through yet, you can manually recheck it below.</p>
            </div>
        </div>
        <button class="btn-outline" onclick="retryPaymentVerification(<?php echo $student_id; ?>)">
            <svg viewBox="0 0 24 24">
                <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
            </svg>
            Retry Payment Verification
        </button>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <h3>Quick Actions</h3>
        <div class="actions-grid">
            <a href="fees.php" class="action-card">
                <svg viewBox="0 0 24 24">
                    <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                </svg>
                <span>Pay Fees</span>
            </a>
            <a href="course-registration.php" class="action-card">
                <svg viewBox="0 0 24 24">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <span>Register Courses</span>
            </a>
            <a href="result.php" class="action-card">
                <svg viewBox="0 0 24 24">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <span>Check Results</span>
            </a>
            <a href="profile.php" class="action-card">
                <svg viewBox="0 0 24 24">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <span>My Profile</span>
            </a>
        </div>
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
        --shadow: 0 4px 20px rgba(0,0,0,0.08);
        --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
        --transition: all 0.3s ease;
    }

    .welcome-banner {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 20px;
        padding: 40px;
        margin-bottom: 20px;
        color: var(--white);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        box-shadow: var(--shadow-lg);
        position: relative;
        overflow: hidden;
    }

    .welcome-banner::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 20s linear infinite;
    }

    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .welcome-content {
        position: relative;
        z-index: 1;
    }

    .welcome-content h1 {
        font-size: 32px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .session-info {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
        font-size: 14px;
        opacity: 0.9;
    }

    .wave {
        animation: wave 2s infinite;
        display: inline-block;
    }

    @keyframes wave {
        0%, 100% { transform: rotate(0deg); }
        25% { transform: rotate(20deg); }
        75% { transform: rotate(-20deg); }
    }

    .note {
        font-size: 13px;
        opacity: 0.9;
        margin-top: 10px;
        display: block;
    }

    .btn-primary {
        background: var(--white);
        color: var(--primary-color);
        padding: 12px 24px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
        position: relative;
        z-index: 1;
        border: none;
        cursor: pointer;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }

    .btn-primary svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
    }

    /* Progress Bar */
    .progress-section {
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .progress-bar-container {
        flex: 1;
        height: 8px;
        background: #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, var(--primary-color), var(--success-color));
        border-radius: 10px;
        transition: width 0.5s ease;
    }

    .progress-text {
        font-size: 14px;
        font-weight: 600;
        color: var(--primary-color);
        min-width: 60px;
    }

    .steps-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .step-card {
        background: var(--white);
        border-radius: 16px;
        padding: 25px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .step-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-color);
        transition: var(--transition);
    }

    .step-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .step-card.completed::before {
        background: var(--success-color);
    }

    .step-icon {
        width: 60px;
        height: 60px;
        background: var(--primary-soft);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
        flex-shrink: 0;
    }

    .step-card:hover .step-icon {
        transform: scale(1.1);
        background: var(--primary-light);
    }

    .step-icon svg {
        width: 30px;
        height: 30px;
        fill: var(--primary-color);
        transition: var(--transition);
    }

    .step-card:hover .step-icon svg {
        fill: var(--white);
    }

    .step-content {
        flex: 1;
    }

    .step-content h3 {
        color: var(--text-dark);
        margin-bottom: 5px;
        font-size: 18px;
    }

    .step-content p {
        color: var(--text-light);
        font-size: 14px;
        margin-bottom: 8px;
    }

    .step-badge {
        font-size: 12px;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 20px;
        display: inline-block;
    }

    .step-badge.completed {
        background: var(--success-color);
        color: var(--white);
    }

    .step-badge.pending {
        background: var(--warning-color);
        color: var(--text-dark);
    }

    .step-detail {
        font-size: 13px;
        color: var(--text-light);
        margin-top: 8px;
        font-weight: 500;
    }

    .text-warning {
        color: #d97706;
    }

    .payment-trouble {
        background: var(--white);
        border-radius: 16px;
        padding: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        box-shadow: var(--shadow);
        border-left: 4px solid var(--warning-color);
        transition: var(--transition);
        margin-bottom: 30px;
    }

    .payment-trouble:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .trouble-content {
        display: flex;
        align-items: center;
        gap: 20px;
        flex: 1;
    }

    .trouble-content svg {
        width: 40px;
        height: 40px;
        fill: var(--warning-color);
        animation: pulse 2s infinite;
        flex-shrink: 0;
    }

    .trouble-content h3 {
        color: var(--text-dark);
        margin-bottom: 5px;
        font-size: 18px;
    }

    .trouble-content p {
        color: var(--text-light);
        font-size: 14px;
        line-height: 1.6;
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
    }

    .btn-outline:hover {
        background: var(--primary-color);
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(46, 125, 50, 0.2);
    }

    .btn-outline svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
    }

    .quick-actions {
        margin-top: 40px;
    }

    .quick-actions h3 {
        color: var(--text-dark);
        margin-bottom: 20px;
        font-size: 18px;
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .action-card {
        background: var(--white);
        border-radius: 12px;
        padding: 20px;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        box-shadow: var(--shadow);
        transition: var(--transition);
        color: var(--text-dark);
    }

    .action-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
        background: var(--primary-color);
        color: var(--white);
    }

    .action-card svg {
        width: 30px;
        height: 30px;
        fill: var(--primary-color);
        transition: var(--transition);
    }

    .action-card:hover svg {
        fill: var(--white);
    }

    .action-card span {
        font-weight: 500;
        font-size: 14px;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    @media (max-width: 768px) {
        .welcome-banner {
            padding: 30px 20px;
        }

        .welcome-content h1 {
            font-size: 24px;
        }

        .step-card {
            padding: 20px;
        }

        .trouble-content {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 576px) {
        .payment-trouble {
            flex-direction: column;
            align-items: flex-start;
        }

        .btn-outline {
            width: 100%;
            justify-content: center;
        }

        .actions-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
</style>

<script>
function retryPaymentVerification(studentId) {
    const btn = document.querySelector('.btn-outline');
    const originalContent = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Retrying...';
    btn.disabled = true;

    fetch('ajax/retry-payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({student_id: studentId})
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            alert('Payment verification retry initiated. Please check back in a few minutes.');
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.innerHTML = originalContent;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('An error occurred. Please try again.');
        btn.innerHTML = originalContent;
        btn.disabled = false;
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>