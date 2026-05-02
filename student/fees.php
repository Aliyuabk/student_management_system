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
    $student_query = "SELECT s.*, d.department_name, p.program_name, p.duration_years
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

// Determine student level and type for fee structure
$student_level = $student['current_level'] ?? 100;
$student_type = 'Returning Students';

// Check if student is new (admission year matches current session start)
if ($student['admission_year']) {
    $current_year = date('Y');
    $admission_year = intval($student['admission_year']);

    // If admitted this year or last year and in first year, they're new
    if ($student_level == 100 || ($current_year - $admission_year) <= 1) {
        $student_type = 'New Students';
    }
    // Final year students
    if (isset($student['duration_years']) && $student_level >= ($student['duration_years'] * 100)) {
        $student_type = 'Final Year';
    }
}

// ==================== FIX 3: Get fee information (with semester filter) ====================
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
$fee = $fee_result->fetch_assoc();
$stmt->close();

// ==================== FIX 4: Get all payments (with fee type join) ====================
$payments_query = "SELECT p.*, sf.fee_type, sf.description as fee_description
                   FROM payments p
                   LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                   WHERE p.student_id = ? 
                   ORDER BY p.payment_date DESC";
$stmt = $conn->prepare($payments_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$payments_result = $stmt->get_result();
$stmt->close();

// ==================== FIX 5: Get fee structure (dynamic level & type) ====================
$fee_structure_query = "SELECT * FROM fee_structure 
                        WHERE level = ? 
                        AND session_year = ?
                        AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
                        AND is_mandatory = 1
                        ORDER BY fee_type";
$stmt = $conn->prepare($fee_structure_query);
$stmt->bind_param("iss", $student_level, $current_session, $student_type);
$stmt->execute();
$fee_structure = $stmt->get_result();
$stmt->close();

// If no fee structure found, try without session filter
if (!$fee_structure || $fee_structure->num_rows == 0) {
    $fee_structure_query = "SELECT * FROM fee_structure 
                            WHERE level = ? 
                            AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
                            AND is_mandatory = 1
                            ORDER BY fee_type";
    $stmt = $conn->prepare($fee_structure_query);
    $stmt->bind_param("is", $student_level, $student_type);
    $stmt->execute();
    $fee_structure = $stmt->get_result();
    $stmt->close();
}

// ==================== FIX 6: Calculate totals efficiently ====================
$total_paid = 0;
$total_pending = 0;
$total_failed = 0;
$payments_data = [];

// Store payments in array for display and calculate totals in one loop
while($payment = $payments_result->fetch_assoc()) {
    $payments_data[] = $payment;
    if($payment['status'] == 'Verified') {
        $total_paid += $payment['amount'];
    } elseif($payment['status'] == 'Pending') {
        $total_pending += $payment['amount'];
    } elseif($payment['status'] == 'Failed') {
        $total_failed += $payment['amount'];
    }
}

// Calculate outstanding
$total_fees = $fee ? ($fee['amount'] ?? 0) : 0;
$outstanding = $total_fees - $total_paid;

// If no fee record, calculate from fee structure
if (!$fee && $fee_structure && $fee_structure->num_rows > 0) {
    $total_fees = 0;
    $fee_structure->data_seek(0);
    while($item = $fee_structure->fetch_assoc()) {
        $total_fees += $item['amount'];
    }
    $outstanding = $total_fees - $total_paid;
}

// Ensure non-negative outstanding
if ($outstanding < 0) $outstanding = 0;
?>

<div class="fade-in">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <h1><i class="fas fa-wallet"></i> Fees Management</h1>
            <p>Manage your fee payments and download receipts</p>
            <div class="session-info">
                <span class="badge-session"><?php echo htmlspecialchars($session_name); ?></span>
                <span class="badge-level">Level <?php echo $student_level; ?></span>
                <span class="badge-type"><?php echo $student_type; ?></span>
            </div>
        </div>
    </div>

    <!-- Fee Summary Cards -->
    <div class="fee-summary-cards">
        <div class="summary-card total-fees">
            <div class="summary-icon">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="summary-content">
                <span class="summary-label">Total Fees</span>
                <span class="summary-value">₦<?php echo number_format($total_fees); ?></span>
                <?php if($fee): ?>
                <span class="summary-sub">Invoice: <?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-card paid-fees">
            <div class="summary-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="summary-content">
                <span class="summary-label">Total Paid</span>
                <span class="summary-value">₦<?php echo number_format($total_paid); ?></span>
                <?php if($total_pending > 0): ?>
                <span class="summary-sub pending">₦<?php echo number_format($total_pending); ?> pending</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-card outstanding-fees">
            <div class="summary-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="summary-content">
                <span class="summary-label">Outstanding</span>
                <span class="summary-value <?php echo $outstanding <= 0 ? 'zero' : 'due'; ?>">
                    <?php echo $outstanding <= 0 ? 'CLEARED' : '₦'.number_format($outstanding); ?>
                </span>
                <?php if($outstanding > 0 && $fee && $fee['due_date']): ?>
                <span class="summary-sub">Due: <?php echo date('d M Y', strtotime($fee['due_date'])); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fee Breakdown -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-alt"></i> Fee Breakdown</h3>
            <span class="session-badge"><?php echo htmlspecialchars($current_session); ?></span>
        </div>
        <div class="card-body">
            <div class="fee-items">
                <?php if($fee_structure && $fee_structure->num_rows > 0): ?>
                    <?php $fee_structure->data_seek(0); ?>
                    <?php while($item = $fee_structure->fetch_assoc()): ?>
                    <div class="fee-item">
                        <div class="fee-info">
                            <h4><?php echo htmlspecialchars($item['description'] ?: $item['fee_type']); ?></h4>
                            <span class="fee-type"><?php echo htmlspecialchars($item['fee_type']); ?></span>
                            <?php if($item['is_mandatory']): ?>
                            <span class="mandatory-badge">Required</span>
                            <?php endif; ?>
                        </div>
                        <div class="fee-amount">₦<?php echo number_format($item['amount']); ?></div>
                    </div>
                    <?php endwhile; ?>
                <?php elseif($fee): ?>
                    <div class="fee-item">
                        <div class="fee-info">
                            <h4><?php echo htmlspecialchars($fee['fee_type'] ?? 'Tuition'); ?></h4>
                            <span class="fee-type"><?php echo htmlspecialchars($fee['description'] ?? 'Session Fees'); ?></span>
                        </div>
                        <div class="fee-amount">₦<?php echo number_format($fee['amount']); ?></div>
                    </div>
                <?php else: ?>
                    <div class="no-fee-structure">
                        <i class="fas fa-info-circle"></i>
                        <p>No fee structure found for your level (<?php echo $student_level; ?>) and type (<?php echo $student_type; ?>).</p>
                        <p>Please contact the bursary department.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Total Row -->
            <?php if($total_fees > 0): ?>
            <div class="fee-total">
                <span class="total-label">Total Amount</span>
                <span class="total-amount">₦<?php echo number_format($total_fees); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment History -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Payment History</h3>
            <div class="header-actions">
                <select class="filter-select" onchange="filterPayments(this.value)">
                    <option value="all">All Transactions (<?php echo count($payments_data); ?>)</option>
                    <option value="verified">Verified Only</option>
                    <option value="pending">Pending Only</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
        </div>
        <div class="table-container">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transaction ID</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($payments_data) > 0): ?>
                        <?php foreach($payments_data as $payment): ?>
                        <tr class="payment-row <?php echo strtolower($payment['status']); ?>">
                            <td>
                                <div class="date-cell">
                                    <span class="date-day"><?php echo date('d', strtotime($payment['payment_date'])); ?></span>
                                    <span class="date-month"><?php echo date('M Y', strtotime($payment['payment_date'])); ?></span>
                                </div>
                            </td>
                            <td class="transaction-id"><?php echo htmlspecialchars($payment['transaction_id'] ?: 'N/A'); ?></td>
                            <td>
                                <div class="desc-cell">
                                    <span class="desc-main"><?php echo htmlspecialchars($payment['fee_type'] ?? 'Tuition Fees'); ?></span>
                                    <?php if($payment['fee_description']): ?>
                                    <span class="desc-sub"><?php echo htmlspecialchars($payment['fee_description']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="amount">₦<?php echo number_format($payment['amount']); ?></td>
                            <td>
                                <span class="method-badge">
                                    <i class="fas fa-<?php 
                                        echo match(strtolower($payment['payment_method'] ?? '')) {
                                            'bank transfer' => 'university',
                                            'online' => 'globe',
                                            'card' => 'credit-card',
                                            'cash' => 'money-bill',
                                            default => 'payment'
                                        };
                                    ?>"></i>
                                    <?php echo htmlspecialchars($payment['payment_method'] ?: 'Online'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo strtolower($payment['status']); ?>">
                                    <i class="fas fa-<?php 
                                        echo match(strtolower($payment['status'])) {
                                            'verified' => 'check-circle',
                                            'pending' => 'clock',
                                            'failed' => 'times-circle',
                                            'refunded' => 'undo',
                                            default => 'question-circle'
                                        };
                                    ?>"></i>
                                    <?php echo $payment['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if($payment['status'] == 'Verified'): ?>
                                <button class="icon-btn" onclick="downloadReceipt('<?php echo $payment['receipt_number'] ?: $payment['transaction_id']; ?>')" title="Download Receipt">
                                    <i class="fas fa-download"></i>
                                </button>
                                <?php elseif($payment['status'] == 'Pending'): ?>
                                <button class="icon-btn retry" onclick="retryPayment('<?php echo $payment['transaction_id']; ?>')" title="Retry Verification">
                                    <i class="fas fa-redo"></i>
                                </button>
                                <?php else: ?>
                                <span class="na">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center no-data">
                                <i class="fas fa-receipt"></i>
                                <p>No payment records found</p>
                                <a href="#" class="btn-sm" onclick="makePayment()">Make First Payment</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <?php if($outstanding > 0): ?>
        <button class="btn-primary" onclick="makePayment()">
            <i class="fas fa-credit-card"></i>
            Make Payment (₦<?php echo number_format($outstanding); ?>)
        </button>
        <?php endif; ?>

        <?php if($total_paid > 0): ?>
        <button class="btn-outline" onclick="printStatement()">
            <i class="fas fa-print"></i>
            Print Statement
        </button>
        <?php endif; ?>

        <button class="btn-feedback" onclick="giveFeedback()">
            <i class="fas fa-headset"></i>
            Need Help?
        </button>
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
        --info-color: #17a2b8;
        --text-dark: #1a1a1a;
        --text-light: #6c757d;
        --white: #ffffff;
        --gray-100: #f8f9fa;
        --gray-200: #e9ecef;
        --gray-300: #dee2e6;
        --shadow: 0 4px 20px rgba(0,0,0,0.08);
        --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
        --transition: all 0.3s ease;
    }

    .page-header {
        margin-bottom: 30px;
    }

    .header-content h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-content h1 i {
        color: var(--primary-color);
    }

    .header-content p {
        color: var(--text-light);
        margin-bottom: 15px;
    }

    .session-info {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .session-info span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 600;
    }

    .badge-session {
        background: var(--primary-soft);
        color: var(--primary-color);
    }

    .badge-level {
        background: rgba(23, 162, 184, 0.1);
        color: var(--info-color);
    }

    .badge-type {
        background: rgba(255, 193, 7, 0.1);
        color: #d97706;
    }

    .fee-summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .summary-card {
        background: var(--white);
        border-radius: 16px;
        padding: 25px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .summary-card.total-fees {
        border-left: 4px solid var(--primary-color);
    }

    .summary-card.paid-fees {
        border-left: 4px solid var(--success-color);
    }

    .summary-card.outstanding-fees {
        border-left: 4px solid var(--warning-color);
    }

    .summary-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }

    .total-fees .summary-icon {
        background: var(--primary-soft);
        color: var(--primary-color);
    }

    .paid-fees .summary-icon {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
    }

    .outstanding-fees .summary-icon {
        background: rgba(255, 193, 7, 0.1);
        color: #d97706;
    }

    .summary-content {
        flex: 1;
    }

    .summary-label {
        color: var(--text-light);
        font-size: 14px;
        display: block;
        margin-bottom: 5px;
    }

    .summary-value {
        font-size: 24px;
        font-weight: 700;
        color: var(--text-dark);
        display: block;
    }

    .summary-value.zero {
        color: var(--success-color);
    }

    .summary-value.due {
        color: #d97706;
    }

    .summary-sub {
        font-size: 12px;
        color: var(--text-light);
        margin-top: 5px;
        display: block;
    }

    .summary-sub.pending {
        color: var(--warning-color);
    }

    .card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow);
        margin-bottom: 30px;
        overflow: hidden;
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
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h3 i {
        color: var(--primary-color);
    }

    .session-badge {
        background: var(--primary-color);
        color: var(--white);
        padding: 5px 15px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 500;
    }

    .filter-select {
        padding: 8px 15px;
        border: 2px solid var(--gray-300);
        border-radius: 10px;
        font-size: 14px;
        color: var(--text-dark);
        background: var(--white);
        cursor: pointer;
        transition: var(--transition);
    }

    .filter-select:hover, .filter-select:focus {
        border-color: var(--primary-color);
        outline: none;
    }

    .card-body {
        padding: 20px;
    }

    .fee-items {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .fee-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid var(--gray-200);
        transition: var(--transition);
    }

    .fee-item:hover {
        background: var(--gray-100);
        border-radius: 8px;
    }

    .fee-item:last-child {
        border-bottom: none;
    }

    .fee-info h4 {
        color: var(--text-dark);
        font-size: 16px;
        margin-bottom: 5px;
    }

    .fee-type {
        color: var(--text-light);
        font-size: 13px;
    }

    .mandatory-badge {
        display: inline-block;
        margin-left: 8px;
        padding: 2px 8px;
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
    }

    .fee-amount {
        font-size: 18px;
        font-weight: 600;
        color: var(--primary-color);
    }

    .fee-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 15px;
        margin-top: 15px;
        border-top: 2px solid var(--primary-color);
        background: var(--primary-soft);
        border-radius: 0 0 12px 12px;
    }

    .total-label {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-dark);
    }

    .total-amount {
        font-size: 24px;
        font-weight: 800;
        color: var(--primary-color);
    }

    .no-fee-structure {
        text-align: center;
        padding: 40px 20px;
        color: var(--text-light);
    }

    .no-fee-structure i {
        font-size: 40px;
        margin-bottom: 15px;
        display: block;
        color: var(--warning-color);
    }

    .no-fee-structure p {
        margin-bottom: 10px;
    }

    .table-container {
        overflow-x: auto;
    }

    .payments-table {
        width: 100%;
        border-collapse: collapse;
    }

    .payments-table th {
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 600;
        font-size: 13px;
        padding: 15px 20px;
        text-align: left;
    }

    .payments-table td {
        padding: 15px 20px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--text-dark);
        font-size: 14px;
        vertical-align: middle;
    }

    .payment-row.verified {
        background: rgba(76, 175, 80, 0.05);
    }

    .payment-row.pending {
        background: rgba(255, 152, 0, 0.05);
    }

    .payment-row.failed {
        background: rgba(244, 67, 54, 0.05);
    }

    .date-cell {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 2px;
    }

    .date-day {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .date-month {
        font-size: 11px;
        color: var(--text-light);
        text-transform: uppercase;
    }

    .transaction-id {
        font-family: 'Courier New', monospace;
        font-size: 12px;
        color: var(--text-light);
    }

    .desc-cell {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .desc-main {
        font-weight: 600;
        color: var(--text-dark);
    }

    .desc-sub {
        font-size: 12px;
        color: var(--text-light);
    }

    .amount {
        font-weight: 700;
        color: var(--primary-color);
    }

    .method-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        background: var(--gray-100);
        border-radius: 6px;
        font-size: 12px;
        color: var(--text-dark);
    }

    .method-badge i {
        color: var(--primary-color);
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-badge.verified {
        background: rgba(40, 167, 69, 0.15);
        color: var(--success-color);
    }

    .status-badge.pending {
        background: rgba(255, 193, 7, 0.15);
        color: #d97706;
    }

    .status-badge.failed {
        background: rgba(220, 53, 69, 0.15);
        color: var(--danger-color);
    }

    .status-badge.refunded {
        background: rgba(108, 117, 125, 0.15);
        color: var(--text-light);
    }

    .icon-btn {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--primary-color);
        font-size: 16px;
    }

    .icon-btn:hover {
        background: var(--primary-soft);
        transform: scale(1.1);
    }

    .icon-btn.retry {
        color: var(--warning-color);
    }

    .icon-btn.retry:hover {
        background: rgba(255, 193, 7, 0.1);
    }

    .na {
        color: var(--text-light);
        font-size: 12px;
    }

    .text-center {
        text-align: center;
    }

    .no-data {
        color: var(--text-light);
        padding: 40px !important;
    }

    .no-data i {
        font-size: 40px;
        margin-bottom: 15px;
        display: block;
        color: var(--gray-300);
    }

    .no-data p {
        margin-bottom: 15px;
    }

    .btn-sm {
        display: inline-block;
        padding: 8px 16px;
        background: var(--primary-color);
        color: var(--white);
        text-decoration: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-sm:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .action-buttons {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        border: none;
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
        box-shadow: 0 4px 16px rgba(30, 86, 49, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(30, 86, 49, 0.4);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        padding: 14px 28px;
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
    }

    .btn-feedback {
        background: var(--white);
        border: 1px solid var(--gray-300);
        color: var(--text-dark);
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
        margin-left: auto;
    }

    .btn-feedback:hover {
        background: var(--gray-100);
        border-color: var(--primary-color);
        color: var(--primary-color);
        transform: translateY(-2px);
    }

    @media (max-width: 768px) {
        .page-header h1 {
            font-size: 24px;
        }

        .fee-summary-cards {
            grid-template-columns: 1fr;
        }

        .card-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .filter-select {
            width: 100%;
        }

        .action-buttons {
            flex-direction: column;
        }

        .btn-primary, .btn-outline, .btn-feedback {
            width: 100%;
            justify-content: center;
        }

        .btn-feedback {
            margin-left: 0;
        }

        .payments-table {
            font-size: 12px;
        }

        .payments-table th,
        .payments-table td {
            padding: 10px 12px;
        }
    }

    @media (max-width: 480px) {
        .summary-card {
            flex-direction: column;
            text-align: center;
        }

        .fee-item {
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }
    }
</style>

<script>
function filterPayments(filter) {
    const rows = document.querySelectorAll('.payment-row');
    let visibleCount = 0;

    rows.forEach(row => {
        if(filter === 'all') {
            row.style.display = 'table-row';
            visibleCount++;
        } else {
            if(row.classList.contains(filter)) {
                row.style.display = 'table-row';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });

    // Update filter dropdown text with count
    const select = document.querySelector('.filter-select');
    const option = select.querySelector('option[value="' + filter + '"]');
    if(!option.textContent.includes('(')) {
        option.textContent += ' (' + visibleCount + ')';
    }
}

function makePayment() {
    // Show payment modal or redirect
    window.location.href = 'payment.php?amount=<?php echo $outstanding; ?>&session=<?php echo urlencode($current_session); ?>';
}

function downloadReceipt(receiptNumber) {
    if(!receiptNumber || receiptNumber === 'N/A') {
        alert('Receipt not available yet. Please wait for verification.');
        return;
    }
    window.open('download-receipt.php?receipt=' + encodeURIComponent(receiptNumber), '_blank');
}

function retryPayment(transactionId) {
    if(!confirm('Retry verification for transaction ' + transactionId + '?')) return;

    fetch('ajax/retry-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({transaction_id: transactionId})
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            alert('Verification retry initiated. Please refresh in a few minutes.');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not retry'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

function printStatement() {
    window.print();
}

function giveFeedback() {
    // Open help modal or redirect to support
    window.location.href = 'support.php?type=fees';
}
</script>

<?php require_once 'includes/footer.php'; ?>