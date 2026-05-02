<?php
session_start();

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

// Process AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if (!isLoggedIn()) {
        echo json_encode(['error' => 'Please login to use the assistant']);
        exit();
    }
    
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $student_id = $_SESSION['student_id'];
    
    if (empty($message)) {
        echo json_encode(['response' => 'Please enter a question.']);
        exit();
    }
    
    $studentData = getStudentData($conn, $student_id);
    $response = processUserMessage($conn, $message, $student_id, $studentData);
    
    echo json_encode(['response' => $response]);
    exit();
}

function processUserMessage($conn, $message, $student_id, $studentData) {
    $message = strtolower($message);
    
    // Get current session
    $currentSession = getCurrentSession($conn);
    $sessionYear = $currentSession ? $currentSession['session_year'] : '2025/2026';
    $semester = $currentSession ? $currentSession['semester'] : 1;
    
    // Course related queries
    if (strpos($message, 'course') !== false || strpos($message, 'registered') !== false || strpos($message, 'registration') !== false) {
        return getCourseInfo($conn, $student_id, $message, $sessionYear, $semester);
    }
    
    // Result/Grade related queries
    if (strpos($message, 'result') !== false || strpos($message, 'grade') !== false || strpos($message, 'gpa') !== false || strpos($message, 'cgpa') !== false) {
        return getResultInfo($conn, $student_id, $message, $sessionYear);
    }
    
    // Fee/Payment related queries
    if (strpos($message, 'fee') !== false || strpos($message, 'payment') !== false || strpos($message, 'pay') !== false || strpos($message, 'balance') !== false || strpos($message, 'invoice') !== false) {
        return getFeeInfo($conn, $student_id, $message);
    }
    
    // Hostel related queries
    if (strpos($message, 'hostel') !== false || strpos($message, 'room') !== false || strpos($message, 'accommodation') !== false) {
        return getHostelInfo($conn, $student_id, $message);
    }
    
    // Personal/Profile related queries
    if (strpos($message, 'profile') !== false || strpos($message, 'my name') !== false || strpos($message, 'department') !== false || strpos($message, 'program') !== false) {
        return getPersonalInfo($studentData);
    }
    
    // Session/Academic calendar related queries
    if (strpos($message, 'session') !== false || strpos($message, 'calendar') !== false || strpos($message, 'deadline') !== false) {
        return getSessionInfo($conn, $message);
    }
    
    // Transcript related queries
    if (strpos($message, 'transcript') !== false) {
        return getTranscriptInfo($conn, $student_id);
    }
    
    // Help/Greeting
    if (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false || strpos($message, 'help') !== false) {
        return getGreetingResponse($studentData);
    }
    
    // Default response
    return "I'm here to help you with:\n\n📚 Course Registration\n📊 Results & GPA\n💰 Fees & Payments\n🏠 Hostel Accommodation\n👤 Profile Information\n📅 Academic Calendar\n📜 Transcripts\n\nWhat would you like to know?";
}

function getCurrentSession($conn) {
    $sql = "SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

function getCourseInfo($conn, $student_id, $message, $sessionYear, $semester) {
    // Check registered courses
    $sql = "SELECT c.course_code, c.course_title, c.credit_units, cr.registration_status, cr.level
            FROM course_registrations cr
            JOIN courses c ON cr.course_id = c.course_id
            WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $student_id, $sessionYear, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $courses = [];
        $totalCredits = 0;
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row['course_code'] . " - " . $row['course_title'] . " (" . $row['credit_units'] . " units) - Status: " . $row['registration_status'];
            $totalCredits += $row['credit_units'];
        }
        return "You are registered for " . $result->num_rows . " courses in $sessionYear Semester $semester:\n\n" . implode("\n", $courses) . "\n\n📊 Total Credit Units: $totalCredits";
    } else {
        return "You haven't registered for any courses in $sessionYear Semester $semester yet. Please visit the course registration page to register.";
    }
}

function getResultInfo($conn, $student_id, $message, $sessionYear) {
    // Check if asking for specific semester
    preg_match('/semester\s*(\d+)/i', $message, $matches);
    $semester = isset($matches[1]) ? intval($matches[1]) : null;
    
    if ($semester) {
        $sql = "SELECT c.course_code, c.course_title, c.credit_units, r.total_score, r.grade, r.grade_points
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $student_id, $sessionYear, $semester);
    } else {
        $sql = "SELECT c.course_code, c.course_title, c.credit_units, r.total_score, r.grade, r.grade_points, r.semester
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? AND r.session_year = ? AND r.is_published = 1
                ORDER BY r.semester, c.course_code";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $student_id, $sessionYear);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $currentSemester = null;
        $semesterResults = [];
        $totalPoints = 0;
        $totalUnits = 0;
        
        while ($row = $result->fetch_assoc()) {
            $sem = isset($row['semester']) ? $row['semester'] : $semester;
            if (!isset($semesterResults[$sem])) {
                $semesterResults[$sem] = ['courses' => [], 'points' => 0, 'units' => 0];
            }
            $gradePoint = floatval($row['grade_points']);
            $creditUnits = intval($row['credit_units']);
            $semesterResults[$sem]['courses'][] = $row['course_code'] . ": " . $row['total_score'] . "% - Grade: " . $row['grade'] . " (GP: " . $gradePoint . ")";
            $semesterResults[$sem]['points'] += $gradePoint * $creditUnits;
            $semesterResults[$sem]['units'] += $creditUnits;
            if ($sem == $semester || $semester === null) {
                $totalPoints += $gradePoint * $creditUnits;
                $totalUnits += $creditUnits;
            }
        }
        
        $response = "";
        foreach ($semesterResults as $sem => $data) {
            $gpa = $data['units'] > 0 ? round($data['points'] / $data['units'], 2) : 0;
            $response .= "\n📚 Semester $sem:\n" . implode("\n", $data['courses']) . "\n📊 GPA: $gpa\n---\n";
        }
        
        if ($totalUnits > 0 && ($semester !== null || count($semesterResults) > 1)) {
            $cgpa = round($totalPoints / $totalUnits, 2);
            $response .= "\n🎯 Overall CGPA for $sessionYear: $cgpa";
        }
        
        return $response;
    } else {
        if ($semester) {
            return "No results found for Semester $semester, $sessionYear. Results may not be published yet.";
        }
        return "No results found for $sessionYear. Results may not be published yet.";
    }
}

function getFeeInfo($conn, $student_id, $message) {
    $sql = "SELECT sf.*, fs.fee_type as structure_type, fs.description as fee_description
            FROM student_fees sf
            LEFT JOIN fee_structure fs ON sf.fee_structure_id = fs.fee_structure_id
            WHERE sf.student_id = ?
            ORDER BY sf.due_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $fees = [];
        $totalAmount = 0;
        $totalPaid = 0;
        $totalBalance = 0;
        
        while ($row = $result->fetch_assoc()) {
            $feeType = $row['fee_type'] ?: $row['structure_type'];
            $amount = floatval($row['amount']);
            $paid = floatval($row['amount_paid']);
            $balance = $amount - $paid;
            
            $fees[] = "📄 " . ($feeType ?: 'Fee') . ": ₦" . number_format($amount, 2) . 
                      " | Paid: ₦" . number_format($paid, 2) . 
                      " | Balance: ₦" . number_format($balance, 2) .
                      " | Status: " . $row['status'] .
                      ($row['due_date'] ? " | Due: " . date('d M Y', strtotime($row['due_date'])) : "");
            
            $totalAmount += $amount;
            $totalPaid += $paid;
            $totalBalance += $balance;
        }
        
        $response = "💰 Your Fee Summary:\n\n" . implode("\n", $fees);
        $response .= "\n\n📊 Totals:\nTotal Fees: ₦" . number_format($totalAmount, 2) .
                    "\nTotal Paid: ₦" . number_format($totalPaid, 2) .
                    "\nTotal Balance: ₦" . number_format($totalBalance, 2);
        
        if ($totalBalance > 0) {
            $response .= "\n\n⚠️ You have an outstanding balance of ₦" . number_format($totalBalance, 2) . ". Please clear your fees to avoid penalties.";
        } else {
            $response .= "\n\n✅ Your fees are fully paid!";
        }
        
        return $response;
    } else {
        return "No fee records found. Please contact the Bursary department for your fee information.";
    }
}

function getHostelInfo($conn, $student_id, $message) {
    // Check if student has hostel allocation
    $sql = "SELECT ha.*, h.hostel_name, h.hostel_code, hr.room_number, hr.room_type
            FROM hostel_allocations ha
            JOIN hostels h ON ha.hostel_id = h.hostel_id
            JOIN hostel_rooms hr ON ha.room_id = hr.room_id
            WHERE ha.student_id = ? AND ha.status = 'Active'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return "🏠 Your Hostel Information:\n\n" .
               "Hostel: " . $row['hostel_name'] . " (" . $row['hostel_code'] . ")\n" .
               "Room Number: " . $row['room_number'] . "\n" .
               "Room Type: " . $row['room_type'] . "\n" .
               "Bed Number: " . $row['bed_number'] . "\n" .
               "Allocated From: " . date('d M Y', strtotime($row['start_date'])) . "\n" .
               "Allocated To: " . date('d M Y', strtotime($row['end_date'])) . "\n" .
               "Payment Status: " . $row['payment_status'];
    } else {
        // Check available hostels
        $sql = "SELECT h.hostel_name, h.hostel_code, h.total_rooms, h.available_beds, h.monthly_rent, h.gender
                FROM hostels h
                WHERE h.status = 'Available' AND h.available_beds > 0";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $available = [];
            while ($row = $result->fetch_assoc()) {
                $available[] = $row['hostel_name'] . " (" . $row['hostel_code'] . ") - " . 
                              $row['available_beds'] . " beds available - ₦" . number_format($row['monthly_rent'], 2) . "/month";
            }
            return "You don't have a hostel allocation currently.\n\n🏢 Available Hostels:\n• " . implode("\n• ", $available) . "\n\nPlease visit the Hostel Office to apply for accommodation.";
        } else {
            return "You don't have a hostel allocation currently and no hostels are currently available. Please check back later or contact the Hostel Office.";
        }
    }
}

function getPersonalInfo($studentData) {
    $response = "👤 Your Profile Information:\n\n" .
                "Name: " . $studentData['first_name'] . " " . ($studentData['middle_name'] ? $studentData['middle_name'] . " " : "") . $studentData['last_name'] . "\n" .
                "Matric Number: " . $studentData['matric_number'] . "\n" .
                "Email: " . $studentData['email'] . "\n" .
                "Phone: " . ($studentData['phone'] ?: 'Not provided') . "\n" .
                "Department: " . ($studentData['department_name'] ?: 'Not assigned') . "\n" .
                "Program: " . ($studentData['program_name'] ?: 'Not assigned') . "\n" .
                "Current Level: " . $studentData['current_level'] . "\n" .
                "Status: " . $studentData['status'] . "\n" .
                "Gender: " . ($studentData['gender'] ?: 'Not specified') . "\n" .
                "Date of Birth: " . ($studentData['date_of_birth'] ? date('d M Y', strtotime($studentData['date_of_birth'])) : 'Not provided');
    
    return $response;
}

function getSessionInfo($conn, $message) {
    $sql = "SELECT * FROM academic_sessions ORDER BY session_id DESC LIMIT 5";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $current = $row['is_current'] ? " ✓ CURRENT" : "";
            $sessions[] = $row['session_year'] . " - Semester " . $row['semester'] . $current .
                         ($row['registration_start'] ? "\n   Registration: " . date('d M', strtotime($row['registration_start'])) . " - " . date('d M Y', strtotime($row['registration_end'])) : "");
        }
        return "📅 Academic Sessions:\n\n" . implode("\n\n", $sessions);
    }
    
    return "No academic session information available.";
}

function getTranscriptInfo($conn, $student_id) {
    $sql = "SELECT t.*, t.status as transcript_status, t.request_date, t.purpose
            FROM transcripts t
            WHERE t.student_id = ?
            ORDER BY t.request_date DESC
            LIMIT 3";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $transcripts = [];
        while ($row = $result->fetch_assoc()) {
            $transcripts[] = "📜 Request Date: " . date('d M Y', strtotime($row['request_date'])) .
                            " | Status: " . $row['transcript_status'] .
                            " | Purpose: " . ($row['purpose'] ?: 'Not specified') .
                            ($row['collection_date'] ? " | Collected: " . date('d M Y', strtotime($row['collection_date'])) : "");
        }
        return "Your Transcript Requests:\n\n" . implode("\n", $transcripts) . 
               "\n\nTo request a new transcript, please visit the Academic Records Office.";
    } else {
        return "You have no transcript requests. To request a transcript, please visit the Academic Records Office or fill out the transcript request form online.";
    }
}

function getGreetingResponse($studentData) {
    $name = $studentData['first_name'];
    return "Hello $name! 👋\n\nI'm your AI Academic Assistant. I can help you with:\n\n📚 Course Registration - Check your registered courses\n📊 Results & GPA - View your grades and calculate GPA\n💰 Fees & Payments - Check fee balances and payment status\n🏠 Hostel Accommodation - View hostel allocation and availability\n👤 Profile Information - View your personal and academic details\n📅 Academic Calendar - Check session dates and deadlines\n📜 Transcripts - Track transcript requests\n\nJust ask me a question, and I'll help you out! 😊";
}
?>