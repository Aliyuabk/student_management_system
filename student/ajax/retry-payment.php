<?php
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$student_id = $_SESSION['student_id'];

if (isset($data['payment_id'])) {
    // Retry specific payment
    $payment_id = $data['payment_id'];
    $update = "UPDATE payments SET status = 'Pending', verification_date = NULL 
               WHERE payment_id = ? AND student_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ii", $payment_id, $student_id);
} else {
    // Retry all pending payments
    $update = "UPDATE payments SET status = 'Pending', verification_date = NULL 
               WHERE student_id = ? AND status = 'Pending'";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("i", $student_id);
}

if ($stmt->execute()) {
    // Log the action
    $log = "INSERT INTO admin_logs (admin_id, action, description) VALUES (?, 'Payment Retry', 'Student initiated payment verification retry')";
    $log_stmt = $conn->prepare($log);
    $log_stmt->bind_param("i", $_SESSION['student_id']);
    $log_stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Verification retry initiated']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>