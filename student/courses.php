<?php
require_once 'includes/header.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get current session info
$session_query = "SELECT session_id, session_year, semester, session_name, 
                  registration_start, registration_end, exams_start, exams_end,
                  lectures_start, lectures_end
                  FROM academic_sessions 
                  WHERE is_current = 1 AND status = 'Active' 
                  LIMIT 1";
$session_result = $conn->query($session_query);
$current_session = $session_result->fetch_assoc();

if (!$current_session) {
    // Fallback to default if no active session
    $current_session = [
        'session_year' => '2025/2026',
        'semester' => 1,
        'session_name' => '2025/2026 First Semester'
    ];
}

$session_year = $current_session['session_year'];
$semester = $current_session['semester'];

// Get student details for PDF
$student_info_query = "SELECT s.*, d.department_name, p.program_name 
                       FROM students s 
                       LEFT JOIN departments d ON s.department_id = d.department_id 
                       LEFT JOIN programs p ON s.program_id = p.program_id 
                       WHERE s.student_id = ?";
$stmt = $conn->prepare($student_info_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();

// Get registered courses with additional details
$courses_query = "SELECT c.*, cr.registration_status, cr.registration_date, cr.approval_date,
                  cr.attendance_percentage, cr.grade, cr.score
                  FROM courses c 
                  JOIN course_registrations cr ON c.course_id = cr.course_id 
                  WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?
                  ORDER BY c.course_code";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("isi", $student_id, $session_year, $semester);
$stmt->execute();
$courses_result = $stmt->get_result();

// Get total units and stats
$units_query = "SELECT SUM(c.credit_units) as total_units, 
                       COUNT(c.course_id) as total_courses,
                       SUM(CASE WHEN cr.registration_status = 'Approved' THEN 1 ELSE 0 END) as approved_count
                FROM courses c 
                JOIN course_registrations cr ON c.course_id = cr.course_id 
                WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?";
$stmt = $conn->prepare($units_query);
$stmt->bind_param("isi", $student_id, $session_year, $semester);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

$total_units = $stats['total_units'] ?: 0;
$total_courses = $stats['total_courses'] ?: 0;
$approved_courses = $stats['approved_count'] ?: 0;
$pending_courses = $total_courses - $approved_courses;

// Get available courses for registration - FIXED: Removed 'is_active' column
$available_courses_query = "SELECT * FROM courses 
                           WHERE level = ? AND semester = ?";
$stmt = $conn->prepare($available_courses_query);
$current_level = $student_info['current_level'] ?: 100;
$stmt->bind_param("ii", $current_level, $semester);
$stmt->execute();
$available_courses = $stmt->get_result();

// Check if registration is still open
$registration_open = false;
if ($current_session['registration_start'] && $current_session['registration_end']) {
    $today = date('Y-m-d');
    $registration_open = ($today >= $current_session['registration_start'] && $today <= $current_session['registration_end']);
}

// Check if exam card is available (when courses are approved and exams are near)
$exam_card_available = ($approved_courses > 0 && $current_session['exams_start'] && date('Y-m-d') >= $current_session['exams_start']);
?>

<div class="fade-in">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1>My Courses</h1>
            <p class="page-subtitle"><?php echo htmlspecialchars($current_session['session_name']); ?></p>
        </div>
        <div class="header-actions">
            <?php if ($registration_open && $total_courses < 10): ?>
            <a href="course-registration.php" class="btn-primary">
                <svg viewBox="0 0 24 24" width="18" height="18">
                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                </svg>
                Register Courses
            </a>
            <?php endif; ?>
            <?php if ($total_courses > 0): ?>
            <div class="btn-group">
                <button onclick="downloadPDF('course_form')" class="btn-secondary">
                    <svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                    </svg>
                    Course Form
                </button>
                <button onclick="downloadPDF('exam_card')" class="btn-secondary" <?php echo !$exam_card_available ? ' ' : ''; ?>>
                    <svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-8 2h6v2h-6V6zm0 4h6v2h-6v-2zm-6 0h4v2H6v-2zm0 4h4v2H6v-2zm10 0h-4v2h4v-2zm4 4H6v-2h14v2z"/>
                    </svg>
                    Exam Card
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary-bg">
                <svg viewBox="0 0 24 24">
                    <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6z"/>
                    <path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Total Courses</span>
                <span class="stat-value"><?php echo $total_courses; ?></span>
                <span class="stat-unit">courses</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success-bg">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Credit Units</span>
                <span class="stat-value"><?php echo $total_units; ?></span>
                <span class="stat-unit">units</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning-bg">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Approved Courses</span>
                <span class="stat-value"><?php echo $approved_courses; ?>/<?php echo $total_courses; ?></span>
                <span class="stat-unit">approved</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info-bg">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Status</span>
                <span class="stat-value"><?php echo $approved_courses == $total_courses && $total_courses > 0 ? 'Complete' : 'Pending'; ?></span>
                <span class="stat-unit">registration</span>
            </div>
        </div>
    </div>

    <!-- Registration Status Alert -->
    <?php if ($pending_courses > 0): ?>
    <div class="alert alert-warning">
        <svg viewBox="0 0 24 24" width="20" height="20">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <div>
            <strong>Registration Incomplete!</strong> You have <?php echo $pending_courses; ?> course(s) pending approval. 
            Please complete your registration before the deadline.
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Courses Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Registered Courses</h3>
                <p class="card-subtitle"><?php echo $session_year; ?> - Semester <?php echo $semester; ?></p>
            </div>
            <?php if ($total_courses > 0): ?>
            <div class="card-actions">
                <button onclick="window.print()" class="btn-icon" title="Print">
                    <svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M19 8H5v8h2v-4h10v4h2V8z"/>
                        <path d="M17 12H7v-2h10v2zM7 16h10v2H7z"/>
                    </svg>
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($total_courses > 0): ?>
        <div class="table-responsive">
            <table class="courses-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Credit Units</th>
                        <th>Status</th>
                        <th>Registration Date</th>
                        <th>Grade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sn = 1;
                    $courses_result->data_seek(0);
                    while($course = $courses_result->fetch_assoc()): 
                        $status_class = strtolower($course['registration_status']);
                        $grade_class = '';
                        if ($course['grade'] == 'A') $grade_class = 'grade-a';
                        elseif ($course['grade'] == 'F') $grade_class = 'grade-f';
                        elseif ($course['grade']) $grade_class = 'grade-pass';
                    ?>
                    <tr>
                        <td><?php echo $sn++; ?></td>
                        <td class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></td>
                        <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                        <td class="text-center"><?php echo $course['credit_units']; ?></td>
                        <td>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $course['registration_status']; ?>
                            </span>
                        </td>
                        <td><?php echo date('d M Y', strtotime($course['registration_date'])); ?></td>
                        <td>
                            <?php if ($course['grade']): ?>
                            <span class="grade-badge <?php echo $grade_class; ?>">
                                <?php echo $course['grade']; ?>
                            </span>
                            <?php else: ?>
                            <span class="grade-pending">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="summary-row">
                        <td colspan="3"><strong>Total</strong></td>
                        <td class="text-center"><strong><?php echo $total_units; ?></strong></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" width="64" height="64">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h10v2H7z"/>
                <path d="M7 14h6v2H7z"/>
            </svg>
            <h3>No Courses Registered</h3>
            <p>You haven't registered for any courses this semester.</p>
            <?php if ($registration_open): ?>
            <a href="course-registration.php" class="btn-primary">Register Now</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Course Schedule Section -->
    <?php if ($total_courses > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3>Timetable / Course Schedule</h3>
        </div>
        <div class="timetable-container">
            <div class="timetable-grid">
                <div class="timetable-day">
                    <div class="day-header">Monday</div>
                    <div class="day-courses">
                        <?php 
                        $courses_result->data_seek(0);
                        $has_monday = false;
                        while($course = $courses_result->fetch_assoc()):
                            // Simulated schedule - in production, this would come from a schedule table
                            if ($course['course_id'] % 3 == 1):
                                $has_monday = true;
                        ?>
                        <div class="schedule-item">
                            <div class="schedule-course"><?php echo $course['course_code']; ?></div>
                            <div class="schedule-time">10:00 - 12:00</div>
                            <div class="schedule-venue">LT 201</div>
                        </div>
                        <?php 
                            endif;
                        endwhile;
                        if (!$has_monday): ?>
                        <div class="no-schedule">No courses scheduled</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="timetable-day">
                    <div class="day-header">Tuesday</div>
                    <div class="day-courses">
                        <?php 
                        $courses_result->data_seek(0);
                        $has_tuesday = false;
                        while($course = $courses_result->fetch_assoc()):
                            if ($course['course_id'] % 3 == 2):
                                $has_tuesday = true;
                        ?>
                        <div class="schedule-item">
                            <div class="schedule-course"><?php echo $course['course_code']; ?></div>
                            <div class="schedule-time">09:00 - 11:00</div>
                            <div class="schedule-venue">Lab 3</div>
                        </div>
                        <?php 
                            endif;
                        endwhile;
                        if (!$has_tuesday): ?>
                        <div class="no-schedule">No courses scheduled</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="timetable-day">
                    <div class="day-header">Wednesday</div>
                    <div class="day-courses">
                        <?php 
                        $courses_result->data_seek(0);
                        $has_wednesday = false;
                        while($course = $courses_result->fetch_assoc()):
                            if ($course['course_id'] % 3 == 0):
                                $has_wednesday = true;
                        ?>
                        <div class="schedule-item">
                            <div class="schedule-course"><?php echo $course['course_code']; ?></div>
                            <div class="schedule-time">14:00 - 16:00</div>
                            <div class="schedule-venue">Hall B</div>
                        </div>
                        <?php 
                            endif;
                        endwhile;
                        if (!$has_wednesday): ?>
                        <div class="no-schedule">No courses scheduled</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Available Courses for Registration -->
    <?php if ($registration_open && $total_courses < 10 && $available_courses->num_rows > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3>Available Courses for Registration</h3>
            <p class="card-subtitle">Registration closes on <?php echo date('d M Y', strtotime($current_session['registration_end'])); ?></p>
        </div>
        <div class="available-courses-grid">
            <?php while($course = $available_courses->fetch_assoc()): ?>
            <div class="available-course-card">
                <div class="available-course-code"><?php echo $course['course_code']; ?></div>
                <div class="available-course-title"><?php echo $course['course_title']; ?></div>
                <div class="available-course-meta">
                    <span class="credits"><?php echo $course['credit_units']; ?> Credits</span>
                    <span class="course-type <?php echo $course['is_core'] ? 'core' : 'elective'; ?>">
                        <?php echo $course['is_core'] ? 'Core' : 'Elective'; ?>
                    </span>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- PDF Generation Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
function downloadPDF(type) {
    if (type === 'course_form') {
        generateCourseFormPDF();
    } else if (type === 'exam_card') {
        generateExamCardPDF();
    }
}

function generateCourseFormPDF() {
    // Create hidden div for PDF content
    const pdfContent = document.createElement('div');
    pdfContent.className = 'pdf-container';
    pdfContent.style.padding = '40px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.backgroundColor = 'white';
    
    // Get student and course data
    const student = <?php echo json_encode($student_info); ?>;
    const courses = <?php 
        $courses_result->data_seek(0);
        $courses_array = [];
        while($course = $courses_result->fetch_assoc()) {
            $courses_array[] = $course;
        }
        echo json_encode($courses_array); 
    ?>;
    const session_year = '<?php echo $session_year; ?>';
    const semester = '<?php echo $semester; ?>';
    const total_units = '<?php echo $total_units; ?>';
    
    // Build PDF HTML
    pdfContent.innerHTML = `
        <style>
            .pdf-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #2e7d32; padding-bottom: 15px; }
            .pdf-header h1 { color: #2e7d32; margin: 0; font-size: 24px; }
            .pdf-header h2 { margin: 5px 0; font-size: 18px; color: #333; }
            .pdf-header p { margin: 5px 0; color: #666; font-size: 12px; }
            .student-info { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; }
            .info-row { display: flex; margin-bottom: 8px; }
            .info-label { width: 150px; font-weight: bold; color: #555; }
            .info-value { flex: 1; color: #333; }
            .courses-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .courses-table th, .courses-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
            .courses-table th { background-color: #2e7d32; color: white; font-weight: bold; }
            .summary { margin-top: 20px; text-align: right; font-weight: bold; font-size: 14px; }
            .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 15px; }
            .signature-section { margin-top: 40px; display: flex; justify-content: space-between; }
            .signature { text-align: center; }
            .signature-line { width: 200px; border-top: 1px solid #333; margin-top: 30px; padding-top: 5px; }
        </style>
        
        <div class="pdf-header">
            <h1>${student.department_name || 'University'} University</h1>
            <h2>COURSE REGISTRATION FORM</h2>
            <p>Academic Session: ${session_year} | Semester: ${semester}</p>
        </div>
        
        <div class="student-info">
            <div class="info-row">
                <div class="info-label">Student Name:</div>
                <div class="info-value">${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Matric Number:</div>
                <div class="info-value">${student.matric_number || ''}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Department:</div>
                <div class="info-value">${student.department_name || ''}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Program:</div>
                <div class="info-value">${student.program_name || ''}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Current Level:</div>
                <div class="info-value">${student.current_level || ''}</div>
            </div>
        </div>
        
        <table class="courses-table">
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credit Units</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                ${courses.map((course, index) => `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${course.course_code}</td>
                        <td>${course.course_title}</td>
                        <td style="text-align: center">${course.credit_units}</td>
                        <td>${course.registration_status}</td>
                    </tr>
                `).join('')}
            </tbody>
            <tfoot>
                <tr style="background-color: #f5f5f5">
                    <td colspan="3"><strong>Total Credit Units</strong></td>
                    <td colspan="2"><strong>${total_units}</strong></td>
                </tr>
            </tfoot>
        </table>
        
        <div class="summary">
            <p>Total Courses: ${courses.length} | Total Credit Units: ${total_units}</p>
        </div>
        
        <div class="signature-section">
            <div class="signature">
                <div class="signature-line"></div>
                <p>Student's Signature</p>
            </div>
            <div class="signature">
                <div class="signature-line"></div>
                <p>Academic Advisor's Signature</p>
            </div>
            <div class="signature">
                <div class="signature-line"></div>
                <p>HOD's Signature/Stamp</p>
            </div>
        </div>
        
        <div class="footer">
            <p>Generated on: ${new Date().toLocaleDateString()} | This is a computer-generated document.</p>
        </div>
    `;
    
    document.body.appendChild(pdfContent);
    
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `Course_Registration_Form_${student.matric_number}_${session_year}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, letterRendering: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    });
}

function generateExamCardPDF() {
    // Create hidden div for PDF content
    const pdfContent = document.createElement('div');
    pdfContent.className = 'pdf-container';
    pdfContent.style.padding = '40px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.backgroundColor = 'white';
    
    const student = <?php echo json_encode($student_info); ?>;
    const courses = <?php 
        $courses_result->data_seek(0);
        $courses_array = [];
        while($course = $courses_result->fetch_assoc()) {
            if ($course['registration_status'] === 'Approved') {
                $courses_array[] = $course;
            }
        }
        echo json_encode($courses_array); 
    ?>;
    const session_year = '<?php echo $session_year; ?>';
    const semester = '<?php echo $semester; ?>';
    const exams_start = '<?php echo $current_session['exams_start'] ? date('d M Y', strtotime($current_session['exams_start'])) : 'TBA'; ?>';
    const exams_end = '<?php echo $current_session['exams_end'] ? date('d M Y', strtotime($current_session['exams_end'])) : 'TBA'; ?>';
    const total_units = '<?php echo $total_units; ?>';
    
    pdfContent.innerHTML = `
        <style>
            .exam-card { font-family: 'Arial', sans-serif; }
            .exam-header { text-align: center; margin-bottom: 25px; border-bottom: 3px solid #d32f2f; padding-bottom: 15px; }
            .exam-header h1 { color: #d32f2f; margin: 0; font-size: 28px; letter-spacing: 2px; }
            .exam-header h2 { margin: 5px 0; font-size: 20px; color: #333; }
            .exam-header p { margin: 5px 0; color: #666; font-size: 12px; }
            .student-photo { display: flex; justify-content: space-between; margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #f9f9f9; }
            .photo-placeholder { width: 100px; height: 120px; border: 1px solid #ccc; text-align: center; line-height: 120px; color: #999; font-size: 12px; background: white; }
            .student-details { flex: 1; margin-left: 20px; }
            .exam-info { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #fff3e0; }
            .info-row { display: flex; margin-bottom: 8px; }
            .info-label { width: 140px; font-weight: bold; color: #555; }
            .info-value { flex: 1; color: #333; }
            .courses-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .courses-table th, .courses-table td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 11px; }
            .courses-table th { background-color: #d32f2f; color: white; font-weight: bold; text-align: center; }
            .courses-table td { text-align: center; }
            .signature-section { margin-top: 30px; display: flex; justify-content: space-between; }
            .signature { text-align: center; }
            .signature-line { width: 180px; border-top: 1px solid #333; margin-top: 30px; padding-top: 5px; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 15px; }
            .exam-rules { margin-top: 20px; font-size: 10px; color: #666; }
            .exam-rules h4 { color: #d32f2f; margin-bottom: 5px; }
        </style>
        
        <div class="exam-card">
            <div class="exam-header">
                <h1>EXAMINATION CARD</h1>
                <h2>${student.department_name || 'University'} University</h2>
                <p>Academic Session: ${session_year} | Semester: ${semester}</p>
                <p>Examination Period: ${exams_start} - ${exams_end}</p>
            </div>
            
            <div class="student-photo">
                <div class="photo-placeholder">
                    <div style="margin-top: 30px;">PASSPORT</div>
                    <div>PHOTO</div>
                </div>
                <div class="student-details">
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value">${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Matric Number:</div>
                        <div class="info-value">${student.matric_number || ''}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Department:</div>
                        <div class="info-value">${student.department_name || ''}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Program:</div>
                        <div class="info-value">${student.program_name || ''}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Level:</div>
                        <div class="info-value">${student.current_level || ''}</div>
                    </div>
                </div>
            </div>
            
            <div class="exam-info">
                <div class="info-row">
                    <div class="info-label">Registration Status:</div>
                    <div class="info-value" style="color: green; font-weight: bold;">FULLY REGISTERED</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Total Credit Units:</div>
                    <div class="info-value">${total_units}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Eligible for Exams:</div>
                    <div class="info-value" style="color: green;">YES</div>
                </div>
            </div>
            
            <table class="courses-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Credit Units</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Venue</th>
                    </tr>
                </thead>
                <tbody>
                    ${courses.map((course, index) => `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${course.course_code}</td>
                            <td style="text-align: left">${course.course_title.substring(0, 40)}${course.course_title.length > 40 ? '...' : ''}</td>
                            <td>${course.credit_units}</td>
                            <td>${getExamDate(index)}</td>
                            <td>${getExamTime(index)}</td>
                            <td>${getExamVenue(index)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            
            <div class="signature-section">
                <div class="signature">
                    <div class="signature-line"></div>
                    <p>Student's Signature</p>
                </div>
                <div class="signature">
                    <div class="signature-line"></div>
                    <p>Exam Officer's Signature</p>
                </div>
                <div class="signature">
                    <div class="signature-line"></div>
                    <p>Registrar's Signature</p>
                </div>
            </div>
            
            <div class="exam-rules">
                <h4>EXAMINATION RULES AND REGULATIONS</h4>
                <ul>
                    <li>Candidates must present this examination card at each examination hall.</li>
                    <li>Mobile phones and electronic devices are strictly prohibited in the examination hall.</li>
                    <li>Candidates are required to be seated at least 30 minutes before the commencement of each paper.</li>
                    <li>Impersonation is a serious offense and will lead to rustication.</li>
                    <li>No candidate shall be allowed into the examination hall 30 minutes after the commencement of the paper.</li>
                </ul>
            </div>
            
            <div class="footer">
                <p>This examination card is valid only for the ${session_year} ${semester === 1 ? 'First' : 'Second'} Semester Examination.</p>
                <p>Generated on: ${new Date().toLocaleDateString()} | Document ID: ${student.matric_number}_${session_year}_${semester}</p>
            </div>
        </div>
    `;
    
    document.body.appendChild(pdfContent);
    
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `Exam_Card_${student.matric_number}_${session_year}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, letterRendering: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    });
}

// Helper functions for exam schedule simulation
function getExamDate(index) {
    const dates = ['15 Dec 2026', '17 Dec 2026', '19 Dec 2026', '20 Dec 2026', '22 Dec 2026'];
    return dates[index % dates.length];
}

function getExamTime(index) {
    const times = ['09:00 - 12:00', '09:00 - 12:00', '14:00 - 17:00', '09:00 - 12:00', '14:00 - 17:00'];
    return times[index % times.length];
}

function getExamVenue(index) {
    const venues = ['Hall A', 'Hall B', 'Hall C', 'Hall A', 'Hall B'];
    return venues[index % venues.length];
}
</script>

<style>
/* Main Styles */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 30px;
}

.page-header h1 {
    font-size: 28px;
    color: var(--text-dark);
    margin-bottom: 5px;
}

.page-subtitle {
    color: var(--text-light);
    font-size: 14px;
}

.header-actions {
    display: flex;
    gap: 15px;
}

.btn-group {
    display: flex;
    gap: 10px;
}

.btn-primary {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
    color: white;
    padding: 12px 24px;
    border-radius: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(46, 125, 50, 0.3);
}

.btn-secondary {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
    padding: 12px 20px;
    border-radius: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-secondary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(33, 150, 243, 0.3);
}

.btn-secondary:disabled {
    background: #ccc;
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-icon {
    background: none;
    border: 1px solid #ddd;
    padding: 8px 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-icon:hover {
    background: #f5f5f5;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 20px;
    padding: 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon svg {
    width: 28px;
    height: 28px;
    fill: white;
}

.primary-bg { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
.success-bg { background: linear-gradient(135deg, #43a047, #2e7d32); }
.warning-bg { background: linear-gradient(135deg, #fb8c00, #ef6c00); }
.info-bg { background: linear-gradient(135deg, #1e88e5, #1565c0); }

.stat-content {
    flex: 1;
}

.stat-label {
    color: #666;
    font-size: 13px;
    display: block;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    line-height: 1;
}

.stat-unit {
    font-size: 12px;
    color: #999;
    margin-left: 5px;
}

/* Alert */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.alert-warning {
    background: #fff3e0;
    border-left: 4px solid #ff9800;
    color: #e65100;
}

.alert svg {
    fill: #ff9800;
    flex-shrink: 0;
}

/* Card */
.card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
    overflow: hidden;
}

.card-header {
    padding: 20px 25px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    background: linear-gradient(to right, #f9f9f9, transparent);
}

.card-header h3 {
    color: #333;
    font-size: 18px;
    margin-bottom: 5px;
}

.card-subtitle {
    color: #999;
    font-size: 13px;
}

/* Table */
.table-responsive {
    overflow-x: auto;
}

.courses-table {
    width: 100%;
    border-collapse: collapse;
}

.courses-table th {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
    color: white;
    font-weight: 600;
    font-size: 13px;
    padding: 15px 16px;
    text-align: left;
}

.courses-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #eee;
    color: #555;
    font-size: 14px;
}

.courses-table tfoot td {
    background: #f5f5f5;
    font-weight: 600;
}

.text-center {
    text-align: center;
}

.course-code {
    font-weight: 600;
    color: #2e7d32;
}

/* Status Badges */
.status-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.status-badge.approved {
    background: #e8f5e9;
    color: #2e7d32;
}

.status-badge.pending {
    background: #fff3e0;
    color: #ef6c00;
}

.status-badge.rejected {
    background: #ffebee;
    color: #c62828;
}

/* Grade Badges */
.grade-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    display: inline-block;
}

.grade-a {
    background: #e8f5e9;
    color: #2e7d32;
}

.grade-pass {
    background: #e3f2fd;
    color: #1565c0;
}

.grade-f {
    background: #ffebee;
    color: #c62828;
}

.grade-pending {
    color: #999;
}

/* Timetable */
.timetable-container {
    padding: 25px;
}

.timetable-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.timetable-day {
    background: #f8f9fa;
    border-radius: 12px;
    overflow: hidden;
}

.day-header {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
    color: white;
    padding: 12px;
    text-align: center;
    font-weight: 600;
}

.day-courses {
    padding: 15px;
}

.schedule-item {
    background: white;
    padding: 10px;
    margin-bottom: 10px;
    border-radius: 8px;
    border-left: 3px solid #2e7d32;
}

.schedule-course {
    font-weight: 600;
    color: #2e7d32;
    font-size: 13px;
}

.schedule-time, .schedule-venue {
    font-size: 11px;
    color: #666;
    margin-top: 3px;
}

.no-schedule {
    text-align: center;
    color: #999;
    font-size: 12px;
    padding: 20px;
}

/* Available Courses */
.available-courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    padding: 25px;
}

.available-course-card {
    border: 1px solid #eee;
    border-radius: 12px;
    padding: 16px;
    transition: all 0.3s ease;
}

.available-course-card:hover {
    border-color: #2e7d32;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.available-course-code {
    font-weight: 700;
    color: #2e7d32;
    margin-bottom: 8px;
}

.available-course-title {
    font-size: 14px;
    color: #333;
    margin-bottom: 12px;
}

.available-course-meta {
    display: flex;
    gap: 12px;
    font-size: 12px;
}

.credits {
    color: #666;
}

.course-type.core {
    color: #2e7d32;
    background: #e8f5e9;
    padding: 2px 8px;
    border-radius: 12px;
}

.course-type.elective {
    color: #ef6c00;
    background: #fff3e0;
    padding: 2px 8px;
    border-radius: 12px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state svg {
    fill: #ccc;
    margin-bottom: 20px;
}

.empty-state h3 {
    margin-bottom: 10px;
    color: #666;
}

.empty-state p {
    color: #999;
    margin-bottom: 20px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .btn-group {
        flex: 1;
    }
    
    .btn-primary, .btn-secondary {
        flex: 1;
        justify-content: center;
        padding: 10px 16px;
        font-size: 13px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .timetable-grid {
        grid-template-columns: 1fr;
    }
    
    .courses-table th, 
    .courses-table td {
        padding: 10px 8px;
        font-size: 12px;
    }
}

@media print {
    .header-actions, .btn-icon, .alert, .stats-grid .stat-card:first-child,
    .available-courses-grid {
        display: none;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>