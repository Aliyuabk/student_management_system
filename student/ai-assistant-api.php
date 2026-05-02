<?php
session_start();
header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['response' => 'Please login to use the AI Assistant.', 'options' => []]);
    exit();
}

require_once '../config/database.php';

$student_id = $_SESSION['student_id'];
$input = json_decode(file_get_contents('php://input'), true);
$user_message = strtolower(trim($input['message'] ?? ''));

if (empty($user_message)) {
    echo json_encode(['response' => 'Please ask me a question.', 'options' => []]);
    exit();
}

// Get connection
$conn = getConnection();

// Helper functions (using different names to avoid conflicts)
function getStudentInfo($conn, $sid) {
    $query = "SELECT s.*, d.department_name, p.program_name 
              FROM students s
              LEFT JOIN departments d ON s.department_id = d.department_id
              LEFT JOIN programs p ON s.program_id = p.program_id
              WHERE s.student_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function calculateStudentCGPA($conn, $sid) {
    $query = "SELECT SUM(COALESCE(r.grade_points, 0) * c.credit_units) as total_points, SUM(c.credit_units) as total_units
              FROM results r
              JOIN courses c ON r.course_id = c.course_id
              WHERE r.student_id = ? AND r.is_published = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && $result['total_units'] > 0) {
        return $result['total_points'] / $result['total_units'];
    }
    return 0;
}

function getStudentFeeStatus($conn, $sid) {
    $query = "SELECT status, amount, amount_paid 
              FROM student_fees 
              WHERE student_id = ? 
              ORDER BY fee_id DESC 
              LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getStudentCourseList($conn, $sid) {
    $query = "SELECT c.course_code, c.course_title, c.credit_units, cr.registration_status
              FROM course_registrations cr
              JOIN courses c ON cr.course_id = c.course_id
              WHERE cr.student_id = ? 
              ORDER BY cr.registration_date DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    return $stmt->get_result();
}

function getStudentLatestResults($conn, $sid) {
    $query = "SELECT c.course_code, c.course_title, r.grade, r.total_score
              FROM results r
              JOIN courses c ON r.course_id = c.course_id
              WHERE r.student_id = ? AND r.is_published = 1
              ORDER BY r.created_at DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    return $stmt->get_result();
}

function getAcademicDeadlines($conn) {
    $query = "SELECT session_name, registration_end, lectures_start, exams_start
              FROM academic_sessions
              WHERE is_current = 1
              LIMIT 1";
    $result = $conn->query($query);
    return $result->fetch_assoc();
}

$student = getStudentInfo($conn, $student_id);
$response = '';
$options = [];

// Intelligent response based on keywords
if (preg_match('/cgpa|gpa|grade point|academic standing|performance/', $user_message)) {
    $cgpa = calculateStudentCGPA($conn, $student_id);
    $response = "📊 **Your Academic Standing**\n\n";
    $response .= "🎯 **Current CGPA:** " . number_format($cgpa, 2) . "\n\n";
    
    if ($cgpa >= 4.5) {
        $response .= "🌟 Excellent! You're doing outstanding! Keep up the great work!";
    } elseif ($cgpa >= 3.5) {
        $response .= "👍 Good job! You're performing well. Aim for a first class!";
    } elseif ($cgpa >= 2.5) {
        $response .= "📈 You're on track! Keep working hard to improve your grades.";
    } elseif ($cgpa >= 1.5) {
        $response .= "⚠️ You need to improve. Consider seeking academic support.";
    } else {
        $response .= "⚠️ Your CGPA is below average. Please meet with your academic advisor.";
    }
    $options = ['View detailed results', 'Grade distribution', 'Improvement tips'];

} elseif (preg_match('/fee|payment|tuition|school fee|outstanding|balance|owe/', $user_message)) {
    $fee = getStudentFeeStatus($conn, $student_id);
    if ($fee) {
        $response = "💰 **Fee Status**\n\n";
        $response .= "📊 **Status:** " . $fee['status'] . "\n";
        $response .= "💰 **Total Amount:** ₦" . number_format($fee['amount'], 2) . "\n";
        $response .= "💳 **Amount Paid:** ₦" . number_format($fee['amount_paid'], 2) . "\n";
        $balance = $fee['amount'] - $fee['amount_paid'];
        $response .= "📉 **Balance:** ₦" . number_format($balance, 2) . "\n\n";
        
        if ($balance > 0) {
            $response .= "⚠️ Please clear your outstanding balance to avoid penalties.";
        } else {
            $response .= "✅ Your fees are fully paid! Thank you.";
        }
    } else {
        $response = "💰 No fee record found. Please contact the Bursary department.";
    }
    $options = ['Payment options', 'Fee structure', 'Make payment'];

} elseif (preg_match('/course|subject|class|registered|enrolled/', $user_message)) {
    $courses = getStudentCourseList($conn, $student_id);
    if ($courses->num_rows > 0) {
        $response = "📚 **Your Registered Courses**\n\n";
        while ($course = $courses->fetch_assoc()) {
            $response .= "📖 **{$course['course_code']}** - {$course['course_title']}\n";
            $response .= "   Credits: {$course['credit_units']} | Status: {$course['registration_status']}\n\n";
        }
    } else {
        $response = "📚 You haven't registered for any courses yet. Please complete your course registration.";
    }
    $options = ['Register for courses', 'Course schedule', 'Available courses'];

} elseif (preg_match('/result|grade|score|exam result/', $user_message)) {
    $results = getStudentLatestResults($conn, $student_id);
    if ($results->num_rows > 0) {
        $response = "📊 **Your Latest Results**\n\n";
        while ($result = $results->fetch_assoc()) {
            $grade_emoji = $result['grade'] == 'A' ? '🌟' : ($result['grade'] == 'F' ? '❌' : '📘');
            $response .= "{$grade_emoji} **{$result['course_code']}**: {$result['grade']} ({$result['total_score']}%)\n";
        }
        $response .= "\nView full results for more details.";
    } else {
        $response = "📊 No published results available yet. Check back after the examination period.";
    }
    $options = ['View full transcript', 'Semester GPA', 'Result analysis'];

} elseif (preg_match('/deadline|due date|registration close|exam date|important date/', $user_message)) {
    $deadlines = getAcademicDeadlines($conn);
    if ($deadlines) {
        $response = "📅 **Important Deadlines**\n\n";
        $response .= "📝 **Registration Deadline:** " . date('d M Y', strtotime($deadlines['registration_end'])) . "\n";
        $response .= "🏫 **Lectures Start:** " . date('d M Y', strtotime($deadlines['lectures_start'])) . "\n";
        $response .= "✍️ **Exams Start:** " . date('d M Y', strtotime($deadlines['exams_start'])) . "\n\n";
        $days_left = ceil((strtotime($deadlines['registration_end']) - time()) / 86400);
        if ($days_left > 0) {
            $response .= "⏰ You have {$days_left} days left for registration!";
        } else {
            $response .= "⚠️ Registration has closed. Late registration may apply.";
        }
    } else {
        $response = "📅 No active deadlines found. Please check the academic calendar.";
    }
    $options = ['Academic calendar', 'Add/Drop period', 'Exam timetable'];

} elseif (preg_match('/profile|information|department|program|name|matric/', $user_message)) {
    if ($student) {
        $response = "👤 **Your Profile Information**\n\n";
        $response .= "📛 **Name:** {$student['first_name']} {$student['last_name']}\n";
        $response .= "🎓 **Matric Number:** {$student['matric_number']}\n";
        $response .= "🏛️ **Department:** {$student['department_name']}\n";
        $response .= "📚 **Program:** {$student['program_name']}\n";
        $response .= "📊 **Level:** {$student['current_level']}\n";
        $response .= "📧 **Email:** {$student['email']}\n";
        $response .= "📱 **Phone:** " . ($student['phone'] ?? 'Not provided') . "\n";
    }
    $options = ['Edit profile', 'Update contact', 'View full profile'];

} elseif (preg_match('/help|what can you do|support|assistance|features/', $user_message)) {
    $response = "🤖 **I can help you with:**\n\n";
    $response .= "💰 **Fees** - Check outstanding fees and payment status\n";
    $response .= "📚 **Courses** - View registered courses\n";
    $response .= "📊 **Results** - Check your CGPA and exam results\n";
    $response .= "📅 **Deadlines** - View important academic deadlines\n";
    $response .= "👤 **Profile** - View your personal information\n\n";
    $response .= "💡 **Try asking:**\n";
    $response .= "• 'What is my CGPA?'\n";
    $response .= "• 'Show my registered courses'\n";
    $response .= "• 'What are my outstanding fees?'\n";
    $response .= "• 'When is registration deadline?'";
    $options = ['Check CGPA', 'Check fees', 'View courses', 'View deadlines'];

} elseif (preg_match('/hi|hello|hey|good morning|good afternoon|good evening/', $user_message)) {
    $first_name = $student['first_name'] ?? 'there';
    $response = "👋 Hello {$first_name}! Welcome to the AI Academic Assistant.\n\n";
    $response .= "I'm here to help you with:\n";
    $response .= "💰 Fees, 📚 Courses, 📊 Results, 📅 Deadlines, and more!\n\n";
    $response .= "What would you like to know today?";
    $options = ['My CGPA', 'My fees', 'My courses', 'Deadlines'];

} else {
    $response = "🤔 I'm not sure I understand. Here's what I can help with:\n\n";
    $response .= "💰 Check your fee status\n";
    $response .= "📚 View your registered courses\n";
    $response .= "📊 Check your CGPA and results\n";
    $response .= "📅 View important deadlines\n";
    $response .= "👤 View your profile information\n\n";
    $response .= "💡 **Tip:** Try using the suggestion chips above or type 'help' for more options.";
    $options = ['My CGPA', 'My fees', 'My courses', 'Deadlines', 'My profile'];
}

$conn->close();

echo json_encode([
    'response' => $response,
    'options' => $options
]);
?>