<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['response' => 'Please login to use the chatbot.', 'options' => []]);
    exit();
}

// CORRECTED PATH - adjust based on your setup
// Try different paths based on where database.php is located

// Option 1: If database.php is in sms/config/
if (file_exists('../config/database.php')) {
    require_once '../config/database.php';
}
// Option 2: If database.php is in sms/includes/
elseif (file_exists('../includes/database.php')) {
    require_once '../includes/database.php';
}
// Option 3: If database.php is in sms/student/config/
elseif (file_exists('config/database.php')) {
    require_once 'config/database.php';
}
// Option 4: If database.php is in the same folder as student files
elseif (file_exists('database.php')) {
    require_once 'database.php';
}
// Option 5: If database.php is one level up in sms/
else {
    // Try to find the database connection file
    $possible_paths = [
        '../../config/database.php',
        '../../includes/database.php',
        '../config/db_connect.php',
        '../db.php'
    ];
    
    $found = false;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo json_encode(['response' => 'Database configuration file not found. Please contact administrator.', 'options' => []]);
        exit();
    }
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
    $query = "SELECT SUM(balance) as total_balance 
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

// Function to get registered courses
function getRegisteredCourses($conn, $student_id) {
    $query = "SELECT c.course_code, c.course_title, c.credit_units, cr.registration_status
              FROM course_registrations cr
              JOIN courses c ON cr.course_id = c.course_id
              WHERE cr.student_id = ? 
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
    
    if ($result && $result['total_units'] > 0) {
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

// Check if connection exists (using your existing connection variable)
if (!isset($conn) || !$conn) {
    // Try to get connection from your existing setup
    global $conn;
    if (!isset($conn)) {
        echo json_encode(['response' => 'Database connection error. Please make sure you are logged in and try again.', 'options' => []]);
        exit();
    }
}

// Process user message and generate response
$student = getStudentInfo($conn, $student_id);
$response = '';
$options = [];

// Test response to verify it's working
if (strpos($user_message, 'test') !== false) {
    echo json_encode(['response' => '✅ Chatbot is working! How can I help you?', 'options' => ['My fees', 'My courses', 'My results', 'My profile']]);
    exit();
}

// Keywords matching
if (preg_match('/fee|payment|tuition|school fee|outstanding|balance/i', $user_message)) {
    $outstanding = getOutstandingFees($conn, $student_id);
    if ($outstanding > 0) {
        $response = "💰 Your outstanding fees: ₦" . number_format($outstanding, 2) . "\n\n";
        $response .= "Please visit the Bursary department or make payment online to clear your balance.";
    } else {
        $response = "✅ Great news! You have no outstanding fees. Your account is fully paid up.";
    }
    $options = ['Check payment history', 'Fee structure'];

} elseif (preg_match('/course|subject|class|enrolled|registered/i', $user_message)) {
    $courses = getRegisteredCourses($conn, $student_id);
    if ($courses && $courses->num_rows > 0) {
        $response = "📚 Here are your registered courses:\n\n";
        while ($course = $courses->fetch_assoc()) {
            $response .= "📖 {$course['course_code']} - {$course['course_title']}\n";
            $response .= "   Credits: {$course['credit_units']} | Status: {$course['registration_status']}\n\n";
        }
    } else {
        $response = "📚 You don't have any registered courses yet. Please complete your course registration.";
    }
    $options = ['Register for courses', 'Course schedule'];

} elseif (preg_match('/result|grade|gpa|cgpa|score|performance/i', $user_message)) {
    $cgpa = calculateCGPA($conn, $student_id);
    $results = getRecentResults($conn, $student_id);
    
    $response = "📊 Your Academic Performance:\n\n";
    $response .= "🎯 Current CGPA: " . number_format($cgpa, 2) . "\n\n";
    
    if ($results && $results->num_rows > 0) {
        $response .= "Recent Results:\n";
        while ($result = $results->fetch_assoc()) {
            $grade_symbol = $result['grade'] ?: 'N/A';
            $response .= "• {$result['course_code']}: {$grade_symbol} ({$result['total_score']}%)\n";
        }
    } else {
        $response .= "No published results available yet.\n";
    }
    $options = ['View full transcript', 'Semester GPA'];

} elseif (preg_match('/deadline|due date|registration close|exam date|important date/i', $user_message)) {
    $deadlines = getDeadlines($conn);
    if ($deadlines && $deadlines['registration_start']) {
        $response = "📅 Important Deadlines for {$deadlines['session_name']}:\n\n";
        $response .= "📝 Registration: " . date('d M Y', strtotime($deadlines['registration_start'])) . " - " . date('d M Y', strtotime($deadlines['registration_end'])) . "\n";
        $response .= "🏫 Lectures: " . date('d M Y', strtotime($deadlines['lectures_start'])) . " - " . date('d M Y', strtotime($deadlines['lectures_end'])) . "\n";
        $response .= "✍️ Examinations: " . date('d M Y', strtotime($deadlines['exams_start'])) . " - " . date('d M Y', strtotime($deadlines['exams_end'])) . "\n";
    } else {
        $response = "📅 No active academic session deadlines found. Please check with the academic office.";
    }
    $options = ['Registration info', 'Exam timetable'];

} elseif (preg_match('/profile|information|personal|details|department|program|name|matric/i', $user_message)) {
    if ($student) {
        $response = "👤 Your Profile Information:\n\n";
        $response .= "📛 Name: {$student['first_name']} {$student['middle_name']} {$student['last_name']}\n";
        $response .= "🎓 Matric Number: {$student['matric_number']}\n";
        $response .= "🏛️ Department: " . ($student['department_name'] ?? 'N/A') . "\n";
        $response .= "📚 Program: " . ($student['program_name'] ?? 'N/A') . "\n";
        $response .= "📊 Level: {$student['current_level']}\n";
        $response .= "📧 Email: {$student['email']}\n";
        $response .= "📱 Phone: " . ($student['phone'] ?? 'N/A') . "\n";
        $response .= "✅ Status: {$student['status']}\n";
    } else {
        $response = "Unable to retrieve your profile information. Please contact support.";
    }
    $options = ['My fees', 'My courses', 'My results'];

} elseif (preg_match('/help|what can you do|support|assistance/i', $user_message)) {
    $response = "🤖 I can help you with:\n\n";
    $response .= "💰 Check outstanding fees\n";
    $response .= "📚 View registered courses\n";
    $response .= "📊 Check results and CGPA\n";
    $response .= "📅 View important deadlines\n";
    $response .= "👤 View profile information\n\n";
    $response .= "Just ask me anything! Try typing:\n";
    $response .= "- 'What are my fees?'\n";
    $response .= "- 'Show my courses'\n";
    $response .= "- 'What is my CGPA?'";

} elseif (preg_match('/hi|hello|hey|good morning|good afternoon|good evening/i', $user_message)) {
    $first_name = $student['first_name'] ?? 'there';
    $response = "👋 Hello {$first_name}! How can I help you today?\n\n";
    $response .= "You can ask me about fees, courses, results, deadlines, or your profile information.\n\n";
    $response .= "💡 Tip: Type 'test' to verify the chatbot is working!";
    $options = ['My fees', 'My courses', 'My results', 'Deadlines', 'My profile'];

} else {
    $response = "I'm not sure I understand. Here are some things I can help with:\n\n";
    $response .= "💰 Check your outstanding fees\n";
    $response .= "📚 View your registered courses\n";
    $response .= "📊 Check your results and CGPA\n";
    $response .= "📅 View important deadlines\n";
    $response .= "👤 View your profile information\n\n";
    $response .= "What would you like to know?";
    $options = ['Check fees', 'My courses', 'My results', 'Deadlines', 'My profile'];
}

echo json_encode([
    'response' => $response,
    'options' => $options
]);
?>