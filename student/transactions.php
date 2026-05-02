<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Get filter parameters
$session_filter = isset($_GET['session']) ? $_GET['session'] : '2025/2026';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to = isset($_GET['to']) ? $_GET['to'] : '';

// Build query
$query = "SELECT p.*, sf.description as fee_description 
          FROM payments p 
          LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id 
          WHERE p.student_id = ?";
$params = [$student_id];
$types = "i";

if($session_filter != 'all') {
    $query .= " AND DATE_FORMAT(p.payment_date, '%Y') = ?";
    $params[] = substr($session_filter, 0, 4);
    $types .= "s";
}

if($status_filter != 'all') {
    $query .= " AND p.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if($date_from) {
    $query .= " AND DATE(p.payment_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if($date_to) {
    $query .= " AND DATE(p.payment_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$query .= " ORDER BY p.payment_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'Verified' THEN amount ELSE 0 END) as total_verified,
                    SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END) as total_pending,
                    COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_count
                  FROM payments 
                  WHERE student_id = ?";
$stmt = $conn->prepare($summary_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Get available sessions for filter
$sessions_query = "SELECT DISTINCT YEAR(payment_date) as year FROM payments WHERE student_id = ? ORDER BY year DESC";
$stmt = $conn->prepare($sessions_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$sessions = $stmt->get_result();
?>

<div class="fade-in">
    <div class="page-header">
        <div class="header-title">
            <h1>Transaction History</h1>
            <p>View and manage all your payment transactions</p>
        </div>
        <button class="btn-outline" onclick="exportTransactions()">
            <svg viewBox="0 0 24 24">
                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
            </svg>
            Export Report
        </button>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card total">
            <div class="summary-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div class="summary-details">
                <span class="summary-label">Total Transactions</span>
                <span class="summary-value"><?php echo $summary['total_count']; ?></span>
            </div>
        </div>

        <div class="summary-card verified">
            <div class="summary-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
            </div>
            <div class="summary-details">
                <span class="summary-label">Verified Payments</span>
                <span class="summary-value">₦<?php echo number_format($summary['total_verified'] ?: 0); ?></span>
            </div>
        </div>

        <div class="summary-card pending">
            <div class="summary-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="summary-details">
                <span class="summary-label">Pending Amount</span>
                <span class="summary-value pending">₦<?php echo number_format($summary['total_pending'] ?: 0); ?></span>
                <?php if($summary['pending_count'] > 0): ?>
                <span class="summary-badge"><?php echo $summary['pending_count']; ?> pending</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-group">
            <label>Session</label>
            <select class="filter-select" id="sessionFilter" onchange="applyFilters()">
                <option value="all">All Sessions</option>
                <?php while($session = $sessions->fetch_assoc()): ?>
                <option value="<?php echo $session['year']; ?>" <?php echo $session_filter == $session['year'] ? 'selected' : ''; ?>>
                    <?php echo $session['year']; ?>/<?php echo $session['year']+1; ?> Session
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Status</label>
            <select class="filter-select" id="statusFilter" onchange="applyFilters()">
                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="Verified" <?php echo $status_filter == 'Verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Failed" <?php echo $status_filter == 'Failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>

        <div class="filter-group">
            <label>From</label>
            <input type="date" class="filter-input" id="dateFrom" value="<?php echo $date_from; ?>" onchange="applyFilters()">
        </div>

        <div class="filter-group">
            <label>To</label>
            <input type="date" class="filter-input" id="dateTo" value="<?php echo $date_to; ?>" onchange="applyFilters()">
        </div>

        <button class="btn-clear" onclick="clearFilters()">
            <svg viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
            Clear
        </button>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <div class="table-container">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transaction Ref</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($transactions->num_rows > 0): ?>
                        <?php while($transaction = $transactions->fetch_assoc()): ?>
                        <tr class="status-<?php echo strtolower($transaction['status']); ?>">
                            <td>
                                <div class="date-cell">
                                    <span class="date-day"><?php echo date('d', strtotime($transaction['payment_date'])); ?></span>
                                    <span class="date-month"><?php echo date('M', strtotime($transaction['payment_date'])); ?></span>
                                    <span class="date-year"><?php echo date('Y', strtotime($transaction['payment_date'])); ?></span>
                                </div>
                            </td>
                            <td class="transaction-id">
                                <span class="ref-number"><?php echo htmlspecialchars($transaction['transaction_id']); ?></span>
                                <?php if($transaction['invoice_number']): ?>
                                <span class="invoice-number">Invoice: <?php echo $transaction['invoice_number']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($transaction['fee_description'] ?: 'Tuition Fees'); ?></td>
                            <td class="amount">₦<?php echo number_format($transaction['amount']); ?></td>
                            <td><?php echo htmlspecialchars($transaction['payment_method'] ?: 'Online'); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($transaction['status']); ?>">
                                    <?php echo $transaction['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if($transaction['status'] == 'Verified' && $transaction['receipt_number']): ?>
                                <button class="action-btn" onclick="viewReceipt('<?php echo $transaction['receipt_number']; ?>')" title="View Receipt">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H7v-4h4v4zm0-6H7V7h4v4zm6 6h-4v-4h4v4zm0-6h-4V7h4v4z"/>
                                    </svg>
                                </button>
                                <button class="action-btn" onclick="downloadReceipt('<?php echo $transaction['receipt_number']; ?>')" title="Download Receipt">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                                    </svg>
                                </button>
                                <?php elseif($transaction['status'] == 'Pending'): ?>
                                <button class="action-btn" onclick="retryPayment(<?php echo $transaction['payment_id']; ?>)" title="Retry Verification">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
                                    </svg>
                                </button>
                                <?php else: ?>
                                <span class="na">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-data">
                                <div class="no-data-content">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                                    </svg>
                                    <p>No transactions found</p>
                                    <p class="small">Try adjusting your filters or make a payment</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="table-footer">
            <div class="pagination-info">
                Showing <?php echo $transactions->num_rows; ?> transactions
            </div>
            <?php if($summary['pending_count'] > 0): ?>
            <div class="pending-note">
                ⚠️ You have <?php echo $summary['pending_count']; ?> pending transaction(s)
            </div>
            <?php endif; ?>
        </div>
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

    .header-title h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .header-title p {
        color: var(--text-light);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
    }

    .btn-outline:hover {
        background: var(--primary-color);
        color: var(--white);
    }

    .btn-outline svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .summary-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .summary-card.total {
        border-left: 4px solid var(--primary-color);
    }

    .summary-card.verified {
        border-left: 4px solid var(--success-color);
    }

    .summary-card.pending {
        border-left: 4px solid var(--warning-color);
    }

    .summary-icon {
        width: 45px;
        height: 45px;
        background: var(--primary-soft);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .summary-icon svg {
        width: 22px;
        height: 22px;
        fill: var(--primary-color);
    }

    .summary-details {
        flex: 1;
    }

    .summary-label {
        color: var(--text-light);
        font-size: 12px;
        display: block;
        margin-bottom: 4px;
    }

    .summary-value {
        font-size: 22px;
        font-weight: 700;
        color: var(--text-dark);
        display: block;
    }

    .summary-value.pending {
        color: var(--warning-color);
    }

    .summary-badge {
        background: var(--warning-color);
        color: var(--white);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        margin-top: 5px;
    }

    .filter-section {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 30px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: var(--shadow);
    }

    .filter-group {
        flex: 1;
        min-width: 150px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 8px;
        color: var(--text-dark);
        font-size: 13px;
        font-weight: 500;
    }

    .filter-select,
    .filter-input {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--gray-300);
        border-radius: 10px;
        font-size: 14px;
        color: var(--text-dark);
        background: var(--white);
        transition: var(--transition);
    }

    .filter-select:hover,
    .filter-input:hover {
        border-color: var(--primary-color);
    }

    .filter-select:focus,
    .filter-input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    }

    .btn-clear {
        background: transparent;
        border: 1px solid var(--gray-300);
        color: var(--text-light);
        padding: 10px 15px;
        border-radius: 10px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: var(--transition);
        height: 42px;
    }

    .btn-clear:hover {
        background: var(--gray-100);
        border-color: var(--danger-color);
        color: var(--danger-color);
    }

    .btn-clear svg {
        width: 16px;
        height: 16px;
        fill: currentColor;
    }

    .card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .table-container {
        overflow-x: auto;
    }

    .transactions-table {
        width: 100%;
        border-collapse: collapse;
    }

    .transactions-table th {
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 600;
        font-size: 13px;
        padding: 15px 20px;
        text-align: left;
    }

    .transactions-table td {
        padding: 20px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--text-dark);
        font-size: 14px;
    }

    .transactions-table tr.status-verified {
        background: rgba(76, 175, 80, 0.02);
    }

    .transactions-table tr.status-pending {
        background: rgba(255, 152, 0, 0.02);
    }

    .transactions-table tr.status-failed {
        background: rgba(244, 67, 54, 0.02);
    }

    .date-cell {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 50px;
        height: 50px;
        background: var(--gray-100);
        border-radius: 10px;
    }

    .date-day {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary-color);
        line-height: 1.2;
    }

    .date-month {
        font-size: 11px;
        color: var(--text-light);
        text-transform: uppercase;
    }

    .date-year {
        font-size: 9px;
        color: var(--text-light);
    }

    .transaction-id {
        font-family: monospace;
    }

    .ref-number {
        display: block;
        font-size: 13px;
        color: var(--text-dark);
        margin-bottom: 4px;
    }

    .invoice-number {
        font-size: 11px;
        color: var(--text-light);
    }

    .amount {
        font-weight: 600;
        color: var(--primary-color);
    }

    .status-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-badge.verified {
        background: var(--success-color);
        color: var(--white);
    }

    .status-badge.pending {
        background: var(--warning-color);
        color: var(--white);
    }

    .status-badge.failed {
        background: var(--danger-color);
        color: var(--white);
    }

    .action-btn {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 6px;
        border-radius: 6px;
        transition: var(--transition);
        margin: 0 2px;
    }

    .action-btn:hover {
        background: var(--gray-200);
    }

    .action-btn svg {
        width: 18px;
        height: 18px;
        fill: var(--primary-color);
    }

    .na {
        color: var(--text-light);
        font-size: 12px;
    }

    .no-data {
        padding: 60px 20px !important;
    }

    .no-data-content {
        text-align: center;
    }

    .no-data-content svg {
        width: 60px;
        height: 60px;
        fill: var(--gray-400);
        margin-bottom: 15px;
    }

    .no-data-content p {
        color: var(--text-dark);
        font-size: 16px;
        margin-bottom: 5px;
    }

    .no-data-content .small {
        color: var(--text-light);
        font-size: 13px;
    }

    .table-footer {
        padding: 15px 20px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .pagination-info {
        color: var(--text-light);
        font-size: 13px;
    }

    .pending-note {
        background: #fff3e0;
        color: var(--warning-color);
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
    }

    @media (max-width: 768px) {
        .header-title h1 {
            font-size: 24px;
        }

        .summary-cards {
            grid-template-columns: 1fr;
        }

        .filter-section {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            min-width: 100%;
        }

        .btn-clear {
            width: 100%;
            justify-content: center;
        }

        .table-footer {
            flex-direction: column;
            text-align: center;
        }

        .date-cell {
            margin: 0 auto;
        }
    }
</style>

<script>
function applyFilters() {
    const session = document.getElementById('sessionFilter').value;
    const status = document.getElementById('statusFilter').value;
    const from = document.getElementById('dateFrom').value;
    const to = document.getElementById('dateTo').value;
    
    let url = 'transactions.php?';
    if(session !== 'all') url += `session=${session}&`;
    if(status !== 'all') url += `status=${status}&`;
    if(from) url += `from=${from}&`;
    if(to) url += `to=${to}`;
    
    window.location.href = url;
}

function clearFilters() {
    window.location.href = 'transactions.php';
}

function exportTransactions() {
    alert('Exporting transaction report...');
    // Implement export functionality
}

function viewReceipt(receiptNumber) {
    window.open('view-receipt.php?receipt=' + receiptNumber, '_blank');
}

function downloadReceipt(receiptNumber) {
    window.location.href = 'download-receipt.php?receipt=' + receiptNumber;
}

function retryPayment(paymentId) {
    if(confirm('Retry payment verification?')) {
        fetch('ajax/retry-payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({payment_id: paymentId})
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert('Verification retry initiated. Please check back later.');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>