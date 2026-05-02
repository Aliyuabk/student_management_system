<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Get student fee information
$fee_query = "SELECT sf.*, fs.description as fee_description, fs.fee_type 
              FROM student_fees sf
              LEFT JOIN fee_structure fs ON sf.fee_structure_id = fs.fee_structure_id
              WHERE sf.student_id = ? AND sf.session_year = '2025/2026' AND sf.status != 'Paid'";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$outstanding_fees = $stmt->get_result();

// Calculate total outstanding
$total_outstanding = 0;
$fee_items = [];
while($fee = $outstanding_fees->fetch_assoc()) {
    $total_outstanding += $fee['balance'];
    $fee_items[] = $fee;
}

// If no outstanding fees, check if there's a pending payment
if($total_outstanding == 0) {
    $pending_query = "SELECT * FROM payments WHERE student_id = ? AND status = 'Pending' ORDER BY payment_date DESC LIMIT 1";
    $stmt = $conn->prepare($pending_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $pending_payment = $stmt->get_result()->fetch_assoc();
}

// Get payment types from URL
$payment_type = isset($_GET['type']) ? $_GET['type'] : 'full';
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : $total_outstanding;

// If amount is 0, set to default fee
if($amount <= 0) {
    $amount = 84900; // Default returning students fee
}

// Handle payment submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_payment'])) {
    $payment_method = $_POST['payment_method'];
    $payment_amount = floatval($_POST['amount']);
    $fee_id = isset($_POST['fee_id']) ? $_POST['fee_id'] : null;
    
    // Map the selected gateway to database enum values
    $db_payment_method = mapPaymentMethod($payment_method);
    
    // Generate transaction reference
    $transaction_ref = 'TXN' . time() . rand(1000, 9999);
    
    // Insert payment record with correct enum value
    $insert = "INSERT INTO payments (student_id, fee_id, amount, payment_method, transaction_id, status, payment_date) 
               VALUES (?, ?, ?, ?, ?, 'Pending', NOW())";
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("iidss", $student_id, $fee_id, $payment_amount, $db_payment_method, $transaction_ref);
    
    if($stmt->execute()) {
        $payment_id = $conn->insert_id;
        
        // Redirect based on payment method (use original for gateway redirect)
        switch($payment_method) {
            case 'Paystack':
                header("Location: process-paystack.php?payment_id=$payment_id");
                break;
            case 'Remita':
                header("Location: process-remita.php?payment_id=$payment_id");
                break;
            case 'Interswitch':
                header("Location: process-interswitch.php?payment_id=$payment_id");
                break;
            case 'Bank Transfer':
                header("Location: bank-transfer.php?payment_id=$payment_id");
                break;
            case 'Card':
                header("Location: process-card.php?payment_id=$payment_id");
                break;
            case 'QR':
                header("Location: process-qr.php?payment_id=$payment_id");
                break;
            default:
                header("Location: payment-confirmation.php?payment_id=$payment_id");
        }
        exit();
    } else {
        $error = "Error processing payment: " . $conn->error;
    }
}

// Function to map payment gateway to database enum
function mapPaymentMethod($method) {
    switch($method) {
        case 'Paystack':
        case 'Remita':
        case 'Interswitch':
        case 'QR':
            return 'Online';
        case 'Bank Transfer':
            return 'Bank Transfer';
        case 'Card':
            return 'Card';
        default:
            return 'Online';
    }
}
?>

<div class="fade-in">
    <div class="page-header">
        <h1>Make Payment</h1>
        <p>Complete your fee payment securely</p>
    </div>

    <?php if(isset($error)): ?>
    <div class="alert error">
        <svg viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <?php if($total_outstanding == 0 && !isset($pending_payment)): ?>
    <div class="alert success">
        <svg viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
        </svg>
        <div>
            <h3>No Outstanding Fees</h3>
            <p>You have no pending fee payments at this time.</p>
            <a href="fees.php" class="btn-link">View Payment History →</a>
        </div>
    </div>
    <?php elseif(isset($pending_payment)): ?>
    <div class="alert warning">
        <svg viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <div>
            <h3>Payment Pending Verification</h3>
            <p>You have a pending payment of ₦<?php echo number_format($pending_payment['amount']); ?>.</p>
            <p class="small">Transaction Ref: <?php echo $pending_payment['transaction_id']; ?></p>
            <button class="btn-link" onclick="checkPaymentStatus(<?php echo $pending_payment['payment_id']; ?>)">
                Check Status →
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if($total_outstanding > 0 || $payment_type != 'full'): ?>
    <div class="payment-container">
        <!-- Payment Summary -->
        <div class="payment-summary-card">
            <h3>Payment Summary</h3>
            <div class="summary-details">
                <?php if(!empty($fee_items)): ?>
                    <?php foreach($fee_items as $fee): ?>
                    <div class="summary-row">
                        <span><?php echo htmlspecialchars($fee['fee_description'] ?: $fee['fee_type'] ?: 'Tuition Fee'); ?></span>
                        <span class="amount">₦<?php echo number_format($fee['balance']); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="summary-row">
                        <span>Returning Students Fee</span>
                        <span class="amount">₦<?php echo number_format($amount); ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="summary-total">
                    <span>Total Amount</span>
                    <span class="total-amount">₦<?php echo number_format($amount); ?></span>
                </div>
            </div>

            <?php if($payment_type == 'part'): ?>
            <div class="part-payment-note">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                <span>You are making a part payment. The remaining balance must be paid before registration.</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment Form -->
        <div class="payment-form-card">
            <h3>Select Payment Method</h3>
            
            <form method="POST" action="" id="paymentForm" onsubmit="return validatePayment()">
                <input type="hidden" name="amount" value="<?php echo $amount; ?>">
                <?php if(!empty($fee_items) && count($fee_items) == 1): ?>
                <input type="hidden" name="fee_id" value="<?php echo $fee_items[0]['fee_id']; ?>">
                <?php endif; ?>

                <!-- Payment Methods Grid -->
                <div class="payment-methods">
                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="Paystack" required>
                        <div class="method-content">
                            <div class="method-icon paystack">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>Paystack</h4>
                                <p>Pay with card, bank transfer or USSD</p>
                            </div>
                            <div class="method-badge popular">Popular</div>
                        </div>
                    </label>

                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="Remita">
                        <div class="method-content">
                            <div class="method-icon remita">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>Remita</h4>
                                <p>Pay via internet banking</p>
                            </div>
                        </div>
                    </label>

                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="Interswitch">
                        <div class="method-content">
                            <div class="method-icon interswitch">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>Interswitch</h4>
                                <p>Pay with Verve, Mastercard, Visa</p>
                            </div>
                        </div>
                    </label>

                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="Bank Transfer">
                        <div class="method-content">
                            <div class="method-icon bank">
                                <svg viewBox="0 0 24 24">
                                    <path d="M4 10h3v7H4zm6.5 0h3v7h-3zM2 19h20v3H2zm15-9h3v7h-3zm-5-9L2 6v2h20V6z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>Bank Transfer</h4>
                                <p>Generate account number for transfer</p>
                            </div>
                        </div>
                    </label>

                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="Card">
                        <div class="method-content">
                            <div class="method-icon card">
                                <svg viewBox="0 0 24 24">
                                    <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>Credit/Debit Card</h4>
                                <p>Direct card payment</p>
                            </div>
                        </div>
                    </label>

                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="QR">
                        <div class="method-content">
                            <div class="method-icon qr">
                                <svg viewBox="0 0 24 24">
                                    <path d="M3 3h6v2H5v4H3V3zm2 14H3v6h6v-2H5v-4zm12 2h-2v4h6v-6h-4v2zm2-12V5h-4V3h6v6h-2z"/>
                                </svg>
                            </div>
                            <div class="method-info">
                                <h4>QR Code</h4>
                                <p>Scan with mobile app</p>
                            </div>
                            <div class="method-badge new">New</div>
                        </div>
                    </label>
                </div>

                <!-- Payment Details (shown when method selected) -->
                <div class="payment-details" id="paymentDetails" style="display: none;">
                    <div class="details-header">
                        <h4>Payment Details</h4>
                        <span class="method-name" id="selectedMethod"></span>
                    </div>

                    <!-- Paystack Details -->
                    <div class="method-details" id="paystackDetails">
                        <p>You will be redirected to Paystack's secure payment page to complete your transaction.</p>
                        <div class="info-box">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            <span>Supported: Cards, Bank Transfer, USSD, QR Code</span>
                        </div>
                    </div>

                    <!-- Remita Details -->
                    <div class="method-details" id="remitaDetails">
                        <p>You will be redirected to Remita's secure payment gateway.</p>
                        <div class="info-box">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            <span>Supported: All Nigerian banks</span>
                        </div>
                    </div>

                    <!-- Interswitch Details -->
                    <div class="method-details" id="interswitchDetails">
                        <p>Secure payment via Interswitch gateway.</p>
                        <div class="info-box">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            <span>Verve, Mastercard, Visa accepted</span>
                        </div>
                    </div>

                    <!-- Bank Transfer Details -->
                    <div class="method-details" id="bankDetails">
                        <p>Make a transfer to the account below:</p>
                        <div class="bank-details">
                            <div class="detail-row">
                                <span class="label">Bank Name:</span>
                                <span class="value">Guaranty Trust Bank</span>
                            </div>
                            <div class="detail-row">
                                <span class="label">Account Number:</span>
                                <span class="value">0123456789</span>
                            </div>
                            <div class="detail-row">
                                <span class="label">Account Name:</span>
                                <span class="value">University Student Portal</span>
                            </div>
                            <div class="detail-row">
                                <span class="label">Amount:</span>
                                <span class="value amount">₦<?php echo number_format($amount); ?></span>
                            </div>
                        </div>
                        <div class="warning-note">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            <span>Use your Student ID as transfer reference</span>
                        </div>
                    </div>

                    <!-- Card Details -->
                    <div class="method-details" id="cardDetails">
                        <div class="card-form">
                            <div class="form-group">
                                <label>Card Number</label>
                                <input type="text" class="card-input" placeholder="1234 5678 9012 3456" maxlength="19">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Expiry Date</label>
                                    <input type="text" class="card-input" placeholder="MM/YY" maxlength="5">
                                </div>
                                <div class="form-group">
                                    <label>CVV</label>
                                    <input type="text" class="card-input" placeholder="123" maxlength="3">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Cardholder Name</label>
                                <input type="text" class="card-input" placeholder="Name on card">
                            </div>
                        </div>
                        <div class="info-box">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                            </svg>
                            <span>Your card information is secure and encrypted</span>
                        </div>
                    </div>

                    <!-- QR Code Details -->
                    <div class="method-details" id="qrDetails">
                        <div class="qr-container">
                            <div class="qr-code">
                                <!-- Generate QR code dynamically -->
                                <svg viewBox="0 0 100 100" width="150" height="150">
                                    <rect width="100" height="100" fill="white"/>
                                    <!-- Simple QR pattern - in production, generate actual QR -->
                                    <rect x="10" y="10" width="20" height="20" fill="black"/>
                                    <rect x="40" y="10" width="20" height="20" fill="black"/>
                                    <rect x="70" y="10" width="20" height="20" fill="black"/>
                                    <rect x="10" y="40" width="20" height="20" fill="black"/>
                                    <rect x="40" y="40" width="20" height="20" fill="black"/>
                                    <rect x="70" y="40" width="20" height="20" fill="black"/>
                                    <rect x="10" y="70" width="20" height="20" fill="black"/>
                                    <rect x="40" y="70" width="20" height="20" fill="black"/>
                                    <rect x="70" y="70" width="20" height="20" fill="black"/>
                                </svg>
                            </div>
                            <p>Scan with your banking app to pay</p>
                            <p class="amount">₦<?php echo number_format($amount); ?></p>
                        </div>
                    </div>
                </div>

                <button type="submit" name="process_payment" class="btn-pay" id="payButton" disabled>
                    <span>Proceed to Pay ₦<?php echo number_format($amount); ?></span>
                    <svg viewBox="0 0 24 24">
                        <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                    </svg>
                </button>
            </form>
        </div>

        <!-- Security Info -->
        <div class="security-info">
            <div class="security-item">
                <svg viewBox="0 0 24 24">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                </svg>
                <span>256-bit SSL Secure</span>
            </div>
            <div class="security-item">
                <svg viewBox="0 0 24 24">
                    <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                </svg>
                <span>PCI-DSS Compliant</span>
            </div>
            <div class="security-item">
                <svg viewBox="0 0 24 24">
                    <path d="M21 6h-2v2h-2V6h-2V4h2V2h2v2h2v2zm-10 3c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm0 4c-2.33 0-7 1.17-7 3.5V17h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
                <span>Verified by Visa & Mastercard</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
    /* All the CSS styles remain the same as before */
    .page-header {
        margin-bottom: 30px;
    }

    .page-header h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .page-header p {
        color: var(--text-light);
    }

    .alert {
        padding: 20px;
        border-radius: 16px;
        margin-bottom: 30px;
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

    .alert.warning {
        background: #fff3e0;
        color: #f57c00;
        border-left: 4px solid #f57c00;
    }

    .alert svg {
        width: 32px;
        height: 32px;
        fill: currentColor;
        flex-shrink: 0;
    }

    .alert h3 {
        margin-bottom: 5px;
    }

    .alert .small {
        font-size: 13px;
        margin-top: 5px;
        opacity: 0.8;
    }

    .btn-link {
        color: var(--primary-color);
        text-decoration: none;
        font-weight: 600;
        background: none;
        border: none;
        cursor: pointer;
        font-size: 14px;
    }

    .btn-link:hover {
        text-decoration: underline;
    }

    .payment-container {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 25px;
        align-items: start;
    }

    .payment-summary-card {
        background: var(--white);
        border-radius: 20px;
        padding: 25px;
        box-shadow: var(--shadow-lg);
        position: sticky;
        top: 100px;
    }

    .payment-summary-card h3 {
        color: var(--text-dark);
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--primary-soft);
    }

    .summary-details {
        margin-bottom: 20px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px dashed var(--gray-200);
        color: var(--text-dark);
    }

    .summary-row .amount {
        font-weight: 600;
        color: var(--primary-color);
    }

    .summary-total {
        display: flex;
        justify-content: space-between;
        padding: 15px 0;
        margin-top: 10px;
        font-size: 18px;
        font-weight: 700;
        color: var(--text-dark);
        border-top: 2px solid var(--primary-color);
    }

    .total-amount {
        color: var(--primary-color);
        font-size: 22px;
    }

    .part-payment-note {
        background: #fff3e0;
        padding: 15px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--warning-color);
        font-size: 13px;
        margin-top: 20px;
    }

    .part-payment-note svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
        flex-shrink: 0;
    }

    .payment-form-card {
        background: var(--white);
        border-radius: 20px;
        padding: 25px;
        box-shadow: var(--shadow-lg);
    }

    .payment-form-card h3 {
        color: var(--text-dark);
        margin-bottom: 25px;
    }

    .payment-methods {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }

    .payment-method-card {
        position: relative;
        cursor: pointer;
        border: 2px solid var(--gray-200);
        border-radius: 16px;
        transition: var(--transition);
        overflow: hidden;
    }

    .payment-method-card:hover {
        border-color: var(--primary-color);
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .payment-method-card input[type="radio"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .payment-method-card input[type="radio"]:checked + .method-content {
        background: var(--primary-soft);
        border-color: var(--primary-color);
    }

    .payment-method-card input[type="radio"]:checked + .method-content .method-icon {
        background: var(--primary-color);
    }

    .payment-method-card input[type="radio"]:checked + .method-content .method-icon svg {
        fill: var(--white);
    }

    .method-content {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
        background: var(--white);
        transition: var(--transition);
    }

    .method-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
    }

    .method-icon.paystack {
        background: #e5424d;
    }

    .method-icon.remita {
        background: #1a4d8c;
    }

    .method-icon.interswitch {
        background: #f7941e;
    }

    .method-icon.bank {
        background: #4caf50;
    }

    .method-icon.card {
        background: #9c27b0;
    }

    .method-icon.qr {
        background: #ff9800;
    }

    .method-icon svg {
        width: 24px;
        height: 24px;
        fill: var(--white);
    }

    .method-info {
        flex: 1;
    }

    .method-info h4 {
        color: var(--text-dark);
        font-size: 15px;
        margin-bottom: 4px;
    }

    .method-info p {
        color: var(--text-light);
        font-size: 11px;
    }

    .method-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .method-badge.popular {
        background: var(--success-color);
        color: var(--white);
    }

    .method-badge.new {
        background: var(--warning-color);
        color: var(--white);
    }

    .payment-details {
        background: var(--gray-100);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 25px;
        animation: fadeIn 0.3s ease;
    }

    .details-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--gray-300);
    }

    .details-header h4 {
        color: var(--text-dark);
        font-size: 16px;
    }

    .method-name {
        color: var(--primary-color);
        font-weight: 600;
        font-size: 14px;
    }

    .method-details {
        display: none;
    }

    .method-details.active {
        display: block;
    }

    .info-box {
        background: var(--white);
        border-radius: 10px;
        padding: 12px;
        margin-top: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: var(--text-light);
        border-left: 3px solid var(--primary-color);
    }

    .info-box svg {
        width: 18px;
        height: 18px;
        fill: var(--primary-color);
        flex-shrink: 0;
    }

    .bank-details {
        background: var(--white);
        border-radius: 12px;
        padding: 15px;
    }

    .detail-row {
        display: flex;
        padding: 10px 0;
        border-bottom: 1px dashed var(--gray-200);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-row .label {
        width: 120px;
        color: var(--text-light);
        font-size: 13px;
    }

    .detail-row .value {
        flex: 1;
        color: var(--text-dark);
        font-weight: 500;
    }

    .detail-row .value.amount {
        color: var(--primary-color);
        font-weight: 700;
    }

    .warning-note {
        background: #fff3e0;
        border-radius: 10px;
        padding: 12px;
        margin-top: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--warning-color);
        font-size: 13px;
    }

    .warning-note svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
        flex-shrink: 0;
    }

    .card-form {
        background: var(--white);
        border-radius: 12px;
        padding: 15px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        color: var(--text-dark);
        font-size: 13px;
        font-weight: 500;
    }

    .card-input {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid var(--gray-300);
        border-radius: 10px;
        font-size: 14px;
        transition: var(--transition);
    }

    .card-input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .qr-container {
        text-align: center;
        padding: 20px;
    }

    .qr-code {
        background: var(--white);
        padding: 20px;
        border-radius: 16px;
        display: inline-block;
        margin-bottom: 15px;
        box-shadow: var(--shadow);
    }

    .qr-code svg {
        display: block;
    }

    .qr-container p {
        color: var(--text-light);
        margin-bottom: 5px;
    }

    .qr-container .amount {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .btn-pay {
        width: 100%;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
        border: none;
        padding: 16px;
        border-radius: 16px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: var(--transition);
    }

    .btn-pay:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(46, 125, 50, 0.3);
    }

    .btn-pay:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-pay svg {
        width: 20px;
        height: 20px;
        fill: currentColor;
    }

    .security-info {
        grid-column: 1 / -1;
        display: flex;
        justify-content: center;
        gap: 30px;
        margin-top: 20px;
        flex-wrap: wrap;
    }

    .security-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-light);
        font-size: 13px;
    }

    .security-item svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
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

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    @media (max-width: 1024px) {
        .payment-container {
            grid-template-columns: 1fr;
        }

        .payment-summary-card {
            position: static;
        }
    }

    @media (max-width: 768px) {
        .page-header h1 {
            font-size: 24px;
        }

        .payment-methods {
            grid-template-columns: 1fr;
        }

        .security-info {
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .detail-row {
            flex-direction: column;
            gap: 5px;
        }

        .detail-row .label {
            width: auto;
        }
    }
</style>

<script>
// Show payment details based on selected method
document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const paymentDetails = document.getElementById('paymentDetails');
        const selectedMethod = document.getElementById('selectedMethod');
        const payButton = document.getElementById('payButton');
        
        // Hide all method details
        document.querySelectorAll('.method-details').forEach(detail => {
            detail.classList.remove('active');
        });
        
        // Show selected method details
        const method = this.value.toLowerCase().replace(' ', '');
        let detailId = '';
        
        switch(this.value) {
            case 'Paystack':
                detailId = 'paystackDetails';
                break;
            case 'Remita':
                detailId = 'remitaDetails';
                break;
            case 'Interswitch':
                detailId = 'interswitchDetails';
                break;
            case 'Bank Transfer':
                detailId = 'bankDetails';
                break;
            case 'Card':
                detailId = 'cardDetails';
                break;
            case 'QR':
                detailId = 'qrDetails';
                break;
        }
        
        if(detailId) {
            document.getElementById(detailId).classList.add('active');
        }
        
        selectedMethod.textContent = this.value;
        paymentDetails.style.display = 'block';
        payButton.disabled = false;
    });
});

// Validate payment form
function validatePayment() {
    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
    
    if(!selectedMethod) {
        alert('Please select a payment method');
        return false;
    }
    
    return confirm(`Proceed with payment of ₦<?php echo number_format($amount); ?> via ${selectedMethod.value}?`);
}

// Check payment status
function checkPaymentStatus(paymentId) {
    fetch('ajax/check-payment-status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({payment_id: paymentId})
    })
    .then(response => response.json())
    .then(data => {
        if(data.status === 'Verified') {
            alert('Payment has been verified!');
            location.reload();
        } else {
            alert('Payment is still pending verification.');
        }
    });
}

// Format card number
document.querySelectorAll('.card-input[placeholder*="1234"]').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = this.value.replace(/\s/g, '');
        if(value.length > 0) {
            value = value.match(new RegExp('.{1,4}', 'g')).join(' ');
        }
        this.value = value;
    });
});

// Format expiry date
document.querySelectorAll('.card-input[placeholder="MM/YY"]').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = this.value.replace(/\D/g, '');
        if(value.length >= 2) {
            value = value.slice(0,2) + '/' + value.slice(2,4);
        }
        this.value = value;
    });
});

// Format CVV - numbers only
document.querySelectorAll('.card-input[placeholder="123"]').forEach(input => {
    input.addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').slice(0,3);
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>