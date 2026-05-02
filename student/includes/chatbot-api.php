<?php
session_start();
header('Content-Type: application/json');



// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['response' => 'Please login to use the chatbot.', 'options' => []]);
    exit();
}

// Include header which already has database connection
require_once 'header.php';
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'student_portal_db';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['student_id']);
}

// Function to redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit();
    }
}

// Function to get student data
function getStudentData($conn, $student_id) {
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get current session
function getCurrentSession($conn) {
    $sql = "SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Use the existing database connection from header.php
// The connection variable is $conn from header.php

if (!isset($conn) || !$conn) {
    echo json_encode(['response' => 'Database connection error. Please refresh the page and try again.', 'options' => []]);
    exit();
}

$student_id = $_SESSION['student_id'];
$input = json_decode(file_get_contents('php://input'), true);
$user_message = strtolower(trim($input['message'] ?? ''));

if (empty($user_message)) {
    echo json_encode(['response' => 'Please ask a question.', 'options' => []]);
    exit();
}

// Function to get student info
function getStudentInfo($conn, $student_id) {
    $query = "SELECT s.*, d.department_name, p.program_name 
              FROM students s
              LEFT JOIN departments d ON s.department_id = d.department_id
              LEFT JOIN programs p ON s.program_id = p.program_id
              WHERE s.student_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Function to get outstanding fees
function getOutstandingFees($conn, $student_id) {
    $query = "SELECT SUM(amount - amount_paid) as total_balance 
              FROM student_fees 
              WHERE student_id = ? AND status IN ('Pending', 'Partial')";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['total_balance'] ?? 0;
}

// Function to get total fees paid
function getTotalPaid($conn, $student_id) {
    $query = "SELECT SUM(amount_paid) as total_paid 
              FROM student_fees 
              WHERE student_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['total_paid'] ?? 0;
}

// Function to get registered courses
function getRegisteredCourses($conn, $student_id) {
    $query = "SELECT c.course_code, c.course_title, c.credit_units, cr.registration_status
              FROM course_registrations cr
              JOIN courses c ON cr.course_id = c.course_id
              WHERE cr.student_id = ? 
              ORDER BY cr.registration_date DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get recent results
function getRecentResults($conn, $student_id) {
    $query = "SELECT c.course_code, c.course_title, r.grade, r.total_score, r.grade_points
              FROM results r
              JOIN courses c ON r.course_id = c.course_id
              WHERE r.student_id = ? AND r.is_published = 1
              ORDER BY r.created_at DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to calculate CGPA
function calculateCGPA($conn, $student_id) {
    $query = "SELECT SUM(r.grade_points * c.credit_units) as total_points, SUM(c.credit_units) as total_units
              FROM results r
              JOIN courses c ON r.course_id = c.course_id
              WHERE r.student_id = ? AND r.is_published = 1";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && $result['total_units'] > 0 && $result['total_points'] > 0) {
        return $result['total_points'] / $result['total_units'];
    }
    return 0;
}

// Function to get deadlines
function getDeadlines($conn) {
    $query = "SELECT session_name, registration_start, registration_end, lectures_start, lectures_end, exams_start, exams_end
              FROM academic_sessions
              WHERE is_current = 1
              LIMIT 1";
    $result = $conn->query($query);
    return $result->fetch_assoc();
}

// Get student info
$student = getStudentInfo($conn, $student_id);
$response = '';
$options = [];

// If student info is not found
if (!$student) {
    echo json_encode(['response' => 'Unable to find your information. Please make sure you are logged in correctly.', 'options' => ['Refresh page', 'Contact support']]);
    exit();
}

// Process user message
if (strpos($user_message, 'test') !== false) {
    echo json_encode(['response' => '✅ Chatbot is working! How can I help you?', 'options' => ['My fees', 'My courses', 'My results', 'My profile']]);
    exit();
}

// Check for fees
elseif (preg_match('/fee|payment|tuition|school fee|outstanding|balance|owe/i', $user_message)) {
    $outstanding = getOutstandingFees($conn, $student_id);
    $total_paid = getTotalPaid($conn, $student_id);
    
    if ($outstanding > 0) {
        $response = "💰 <strong>Fee Summary:</strong>\n\n";
        $response .= "• Outstanding Balance: ₦" . number_format($outstanding, 2) . "\n";
        $response .= "• Total Paid: ₦" . number_format($total_paid, 2) . "\n\n";
        $response .= "Please visit the Bursary department or make payment online to clear your balance.";
    } else {
        $response = "✅ Great news! You have no outstanding fees.\n";
        $response .= "Total fees paid: ₦" . number_format($total_paid, 2);
    }
    $options = ['Payment history', 'Fee structure'];

}

// Check for courses
elseif (preg_match('/course|subject|class|enrolled|registered|what course/i', $user_message)) {
    $courses = getRegisteredCourses($conn, $student_id);
    if ($courses && $courses->num_rows > 0) {
        $response = "📚 <strong>Your Registered Courses:</strong>\n\n";
        while ($course = $courses->fetch_assoc()) {
            $response .= "📖 <strong>{$course['course_code']}</strong> - {$course['course_title']}\n";
            $response .= "   Credits: {$course['credit_units']} | Status: {$course['registration_status']}\n\n";
        }
    } else {
        $response = "📚 You don't have any registered courses yet.\n\nPlease complete your course registration before the deadline.";
    }
    $options = ['Register for courses', 'Course schedule'];

}

// Check for results/grades
elseif (preg_match('/result|grade|gpa|cgpa|score|performance|how did i do/i', $user_message)) {
    $cgpa = calculateCGPA($conn, $student_id);
    $results = getRecentResults($conn, $student_id);
    
    if ($cgpa > 0) {
        $response = "📊 <strong>Your Academic Performance:</strong>\n\n";
        $response .= "🎯 <strong>Current CGPA:</strong> " . number_format($cgpa, 2) . "\n\n";
        
        if ($results && $results->num_rows > 0) {
            $response .= "<strong>Recent Results:</strong>\n";
            while ($result = $results->fetch_assoc()) {
                $grade_symbol = $result['grade'] ?: 'N/A';
                $response .= "• {$result['course_code']}: <strong>{$grade_symbol}</strong> ({$result['total_score']}%)\n";
            }
        }
    } else {
        $response = "📊 No published results available yet.\n\nResults will appear here once they are released by your department.";
    }
    $options = ['Full transcript', 'Semester details'];

}

// Check for deadlines
elseif (preg_match('/deadline|due date|registration close|exam date|important date|when is/i', $user_message)) {
    $deadlines = getDeadlines($conn);
    if ($deadlines && $deadlines['registration_start']) {
        $response = "📅 <strong>Important Deadlines for {$deadlines['session_name']}</strong>\n\n";
        $response .= "📝 <strong>Registration:</strong> " . date('d M Y', strtotime($deadlines['registration_start'])) . " - " . date('d M Y', strtotime($deadlines['registration_end'])) . "\n";
        $response .= "🏫 <strong>Lectures:</strong> " . date('d M Y', strtotime($deadlines['lectures_start'])) . " - " . date('d M Y', strtotime($deadlines['lectures_end'])) . "\n";
        $response .= "✍️ <strong>Examinations:</strong> " . date('d M Y', strtotime($deadlines['exams_start'])) . " - " . date('d M Y', strtotime($deadlines['exams_end'])) . "\n";
    } else {
        $response = "📅 No active academic session deadlines found.\n\nPlease check with the academic office for important dates.";
    }
    $options = ['Add/Drop period', 'Exam timetable'];

}

// Check for profile
elseif (preg_match('/profile|information|personal|details|department|program|name|matric|who am i/i', $user_message)) {
    $response = "👤 <strong>Your Profile Information</strong>\n\n";
    $response .= "📛 <strong>Name:</strong> {$student['first_name']} " . ($student['middle_name'] ?? '') . " {$student['last_name']}\n";
    $response .= "🎓 <strong>Matric Number:</strong> {$student['matric_number']}\n";
    $response .= "🏛️ <strong>Department:</strong> " . ($student['department_name'] ?? 'N/A') . "\n";
    $response .= "📚 <strong>Program:</strong> " . ($student['program_name'] ?? 'N/A') . "\n";
    $response .= "📊 <strong>Level:</strong> {$student['current_level']}\n";
    $response .= "📧 <strong>Email:</strong> {$student['email']}\n";
    $response .= "📱 <strong>Phone:</strong> " . ($student['phone'] ?? 'Not provided') . "\n";
    $response .= "✅ <strong>Status:</strong> {$student['status']}\n";
    $options = ['Update profile', 'Contact department'];

}

// Help
elseif (preg_match('/help|what can you do|support|assistance|what do you do/i', $user_message)) {
    $response = "🤖 <strong>I can help you with:</strong>\n\n";
    $response .= "💰 Check outstanding fees\n";
    $response .= "📚 View registered courses\n";
    $response .= "📊 Check results and CGPA\n";
    $response .= "📅 View important deadlines\n";
    $response .= "👤 View profile information\n\n";
    $response .= "<strong>Try asking:</strong>\n";
    $response .= "• 'What are my fees?'\n";
    $response .= "• 'Show my courses'\n";
    $response .= "• 'What is my CGPA?'\n";
    $response .= "• 'When is registration deadline?'";

}

// Greeting
elseif (preg_match('/hi|hello|hey|good morning|good afternoon|good evening|how are you/i', $user_message)) {
    $first_name = $student['first_name'] ?? 'there';
    $response = "👋 <strong>Hello {$first_name}!</strong> How can I help you today?\n\n";
    $response .= "I can help you check:\n";
    $response .= "• Your fees and payments\n";
    $response .= "• Your registered courses\n";
    $response .= "• Your results and CGPA\n";
    $response .= "• Important deadlines\n";
    $response .= "• Your profile information\n\n";
    $response .= "💡 <strong>Tip:</strong> Type 'test' to verify I'm working, or 'help' to see all features!";
    $options = ['My fees', 'My courses', 'My results', 'Deadlines', 'My profile'];

}

// Default response
else {
    $response = "I'm not sure I understand. Here's what I can help with:\n\n";
    $response .= "💰 Check your outstanding fees\n";
    $response .= "📚 View your registered courses\n";
    $response .= "📊 Check your results and CGPA\n";
    $response .= "📅 View important deadlines\n";
    $response .= "👤 View your profile information\n\n";
    $response .= "<strong>What would you like to know?</strong>";
    $options = ['Check fees', 'My courses', 'My results', 'Deadlines', 'My profile'];
}

echo json_encode([
    'response' => $response,
    'options' => $options
]);
?>