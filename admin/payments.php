<?php
require_once 'includes/header.php';

$page_title = "Record Payment";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Generate receipt number
        $receipt_prefix = 'RCPT-' . date('Ymd');
        $last_receipt = $pdo->query("SELECT receipt_number FROM payments WHERE receipt_number LIKE '$receipt_prefix%' ORDER BY payment_id DESC LIMIT 1")->fetch();
        
        if ($last_receipt) {
            $last_number = intval(substr($last_receipt['receipt_number'], -4));
            $receipt_number = $receipt_prefix . '-' . str_pad($last_number + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $receipt_number = $receipt_prefix . '-0001';
        }
        
        // Insert payment
        $stmt = $pdo->prepare("INSERT INTO payments 
            (student_id, fee_id, invoice_number, amount, payment_method, transaction_id, 
             bank_name, account_number, payer_name, receipt_number, verified_by, status, remarks, proof_of_payment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['student_id'] ?: null,
            $_POST['fee_id'] ?: null,
            $_POST['invoice_number'] ?? null,
            $_POST['amount'],
            $_POST['payment_method'],
            $_POST['transaction_id'] ?? null,
            $_POST['bank_name'] ?? null,
            $_POST['account_number'] ?? null,
            $_POST['payer_name'] ?? null,
            $receipt_number,
            $admin_id,
            'Verified', // Auto-verify for admin users
            $_POST['remarks'] ?? null,
            null // proof_of_payment would be handled via file upload
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Update student_fees table
        if (!empty($_POST['fee_id'])) {
            $fee_id = $_POST['fee_id'];
            
            // Get current payment status
            $fee_stmt = $pdo->prepare("SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?");
            $fee_stmt->execute([$fee_id]);
            $fee = $fee_stmt->fetch();
            
            if ($fee) {
                $new_amount_paid = $fee['amount_paid'] + $_POST['amount'];
                $balance = $fee['amount'] - $new_amount_paid;
                
                // Determine new status
                if ($balance <= 0) {
                    $status = 'Paid';
                } elseif ($new_amount_paid > 0) {
                    $status = 'Partial';
                } else {
                    $status = 'Pending';
                }
                
                $update_stmt = $pdo->prepare("UPDATE student_fees SET 
                    amount_paid = ?, 
                    balance = ?,
                    status = ?,
                    updated_date = CURRENT_TIMESTAMP
                    WHERE fee_id = ?");
                
                $update_stmt->execute([$new_amount_paid, $balance, $status, $fee_id]);
            }
        }
        
        // Add notification for student
        if (!empty($_POST['student_id'])) {
            $notification_sql = "INSERT INTO notifications 
                (student_id, title, message, notification_type, priority, action_url)
                VALUES (?, ?, ?, 'Financial', 'Normal', ?)";
            
            $notification_stmt = $pdo->prepare($notification_sql);
            $notification_stmt->execute([
                $_POST['student_id'],
                "Payment Received",
                "Your payment of ₦" . number_format($_POST['amount'], 2) . " has been received and verified. Receipt No: " . $receipt_number,
                "student_payments.php"
            ]);
        }
        
        $_SESSION['success_message'] = "Payment recorded successfully! Receipt Number: " . $receipt_number;
        
        // Redirect to receipt page or stay based on user choice
        if (isset($_POST['print_receipt'])) {
            header("Location: print_receipt.php?id=$payment_id");
            exit();
        } else {
            header("Location: payments.php?success=1");
            exit();
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error recording payment: " . $e->getMessage();
    }
}

// Get student and fee data for dropdowns
$students = $pdo->query("
    SELECT s.*, d.department_name, p.program_name 
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE s.status = 'Active'
    ORDER BY s.matric_number
    LIMIT 100
")->fetchAll();

// Get pending fees for selected student
$student_id = $_GET['student_id'] ?? '';
$student_fees = [];
if ($student_id) {
    $fee_stmt = $pdo->prepare("
        SELECT * FROM student_fees 
        WHERE student_id = ? AND status IN ('Pending', 'Partial')
        ORDER BY due_date ASC
    ");
    $fee_stmt->execute([$student_id]);
    $student_fees = $fee_stmt->fetchAll();
}

// Get student details if selected
$selected_student = null;
if ($student_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $selected_student = $stmt->fetch();
}

// Get recent payments
$recent_payments = $pdo->query("
    SELECT p.*, s.matric_number, s.first_name, s.last_name, sf.fee_type
    FROM payments p
    LEFT JOIN students s ON p.student_id = s.student_id
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    WHERE p.status = 'Verified'
    ORDER BY p.payment_date DESC
    LIMIT 10
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Record Payment</h1>
            <p class="text-muted">Record and verify student payments</p>
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

<div class="row">
    <!-- Payment Form -->
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Payment Details</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" id="paymentForm">
                    <!-- Student Selection -->
                    <div class="mb-4">
                        <label class="form-label">Select Student *</label>
                        <select class="form-select" id="studentSelect" name="student_id" required 
                                onchange="window.location.href='payments.php?student_id=' + this.value">
                            <option value="">Search for student...</option>
                            <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>" 
                                    <?php echo $student_id == $student['student_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                (<?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($selected_student): ?>
                    <!-- Student Info -->
                    <div class="alert alert-info mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($selected_student['matric_number']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <small>Email: <?php echo htmlspecialchars($selected_student['email']); ?></small><br>
                                <small>Phone: <?php echo htmlspecialchars($selected_student['phone'] ?? 'N/A'); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fee Selection -->
                    <div class="mb-4">
                        <label class="form-label">Select Fee (Optional)</label>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Invoice No.</th>
                                        <th>Fee Type</th>
                                        <th>Amount</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Due Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_fees as $fee): ?>
                                    <tr>
                                        <td>
                                            <input type="radio" name="fee_id" value="<?php echo $fee['fee_id']; ?>" 
                                                   data-invoice="<?php echo htmlspecialchars($fee['invoice_number']); ?>"
                                                   data-amount="<?php echo $fee['balance']; ?>"
                                                   onchange="updatePaymentAmount(this)">
                                        </td>
                                        <td><?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                        <td>₦<?php echo number_format($fee['amount'], 2); ?></td>
                                        <td>₦<?php echo number_format($fee['amount_paid'], 2); ?></td>
                                        <td class="<?php echo $fee['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                            ₦<?php echo number_format($fee['balance'], 2); ?>
                                        </td>
                                        <td>
                                            <?php echo $fee['due_date'] ? date('M d, Y', strtotime($fee['due_date'])) : 'N/A'; ?>
                                            <?php if ($fee['due_date'] && strtotime($fee['due_date']) < time()): ?>
                                                <br><small class="text-danger">Overdue</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($student_fees)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">
                                            No pending fees found for this student.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">Select a fee to apply payment, or leave unselected for miscellaneous payment</small>
                    </div>
                    
                    <!-- Payment Amount -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Payment Amount (₦) *</label>
                            <input type="number" class="form-control" id="amount" name="amount" 
                                   step="0.01" min="0" required value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method *</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select Method</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Online">Online Payment</option>
                                <option value="Card">Card Payment</option>
                                <option value="Bank Draft">Bank Draft</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Payment Details -->
                    <div class="mb-4" id="paymentDetails" style="display: none;">
                        <h6 class="mb-3">Payment Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction ID/Reference</label>
                                <input type="text" class="form-control" name="transaction_id">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payer Name</label>
                                <input type="text" class="form-control" name="payer_name" 
                                       value="<?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name</label>
                                <input type="text" class="form-control" name="bank_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-control" name="account_number">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Remarks -->
                    <div class="mb-4">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" 
                                  placeholder="Optional notes about this payment"></textarea>
                    </div>
                    
                    <!-- Actions -->
                    <div class="d-flex justify-content-between">
                        <a href="payments.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <div>
                            <button type="submit" name="print_receipt" value="1" class="btn btn-success me-2">
                                <i class="fas fa-print me-2"></i>Save & Print Receipt
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Payment
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Recent Payments -->
    <div class="col-md-4">
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Recent Payments</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr>
                                <td>
                                    <small class="text-muted"><?php echo date('M d', strtotime($payment['payment_date'])); ?></small><br>
                                    <small><strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong></small>
                                </td>
                                <td class="text-end">
                                    <small class="text-success">₦<?php echo number_format($payment['amount'], 2); ?></small><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($payment['payment_method']); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Today's Payments</h6>
                <?php
                $today = date('Y-m-d');
                $today_payments = $pdo->prepare("
                    SELECT COUNT(*) as count, SUM(amount) as total 
                    FROM payments 
                    WHERE DATE(payment_date) = ? AND status = 'Verified'
                ");
                $today_payments->execute([$today]);
                $today_stats = $today_payments->fetch();
                ?>
                <div class="stats-figure">₦<?php echo number_format($today_stats['total'] ?? 0, 2); ?></div>
                <p class="stats-detail mb-0"><?php echo $today_stats['count'] ?? 0; ?> transactions today</p>
            </div>
        </div>
    </div>
</div>

<script>
// Show/hide payment details based on method
document.getElementById('payment_method').addEventListener('change', function() {
    const detailsDiv = document.getElementById('paymentDetails');
    const method = this.value;
    
    if (method === 'Bank Transfer' || method === 'Online' || method === 'Card') {
        detailsDiv.style.display = 'block';
    } else {
        detailsDiv.style.display = 'none';
    }
});

// Update payment amount when fee is selected
function updatePaymentAmount(radio) {
    if (radio.checked) {
        const amount = radio.dataset.amount;
        document.getElementById('amount').value = amount;
    }
}

// Form validation
document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value);
    const method = document.getElementById('payment_method').value;
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount greater than 0.');
        document.getElementById('amount').focus();
        return false;
    }
    
    if (!method) {
        e.preventDefault();
        alert('Please select a payment method.');
        document.getElementById('payment_method').focus();
        return false;
    }
    
    return confirm('Confirm recording this payment?');
});

// Auto-select student if coming from manage_fees page
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.has('student_id')) {
    document.getElementById('studentSelect').value = urlParams.get('student_id');
}
</script>

<?php
require_once 'includes/footer.php';
?>