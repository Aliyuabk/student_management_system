<?php
require_once 'includes/header.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Initialize variables
$gpa = 0;
$total_units = 0;
$total_points = 0;
$courses_passed = 0;
$courses_failed = 0;
$previous_cgpa = 0;
$new_cgpa = 0;
$grade_distribution = [
    'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0
];

// Get student information with full details
$student_query = "SELECT s.*, 
                         d.department_name, 
                         d.department_code,
                         p.program_name,
                         p.program_code,
                         f.faculty_name
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();

// Get available academic sessions for this student
$sessions_query = "SELECT DISTINCT r.session_year, r.semester 
                   FROM results r
                   WHERE r.student_id = ? AND r.is_published = 1 
                   ORDER BY r.session_year DESC, r.semester DESC";
$stmt = $conn->prepare($sessions_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$available_sessions = $stmt->get_result();

// Get selected session and semester from URL
$selected_session = isset($_GET['session']) ? $_GET['session'] : '';
$selected_semester = isset($_GET['semester']) ? $_GET['semester'] : '';

// If no session selected, get the latest one
if(empty($selected_session) && $available_sessions->num_rows > 0) {
    $available_sessions->data_seek(0);
    $latest = $available_sessions->fetch_assoc();
    $selected_session = $latest['session_year'];
    $selected_semester = $latest['semester'];
    $available_sessions->data_seek(0);
}

// Group sessions by academic year
$academic_years = [];
if($available_sessions && $available_sessions->num_rows > 0) {
    $available_sessions->data_seek(0);
    while($session = $available_sessions->fetch_assoc()) {
        $year = $session['session_year'];
        if(!isset($academic_years[$year])) {
            $academic_years[$year] = ['first' => null, 'second' => null];
        }
        if($session['semester'] == 1) {
            $academic_years[$year]['first'] = $session;
        } else {
            $academic_years[$year]['second'] = $session;
        }
    }
    $available_sessions->data_seek(0);
}

// Fetch results for selected session
$results = null;
if(!empty($selected_session) && !empty($selected_semester)) {
    // Get results for selected session and semester
    $results_query = "SELECT 
                        r.result_id,
                        r.student_id,
                        r.course_id,
                        r.session_year,
                        r.semester,
                        r.level,
                        r.ca_score,
                        r.exam_score,
                        r.total_score,
                        r.grade,
                        r.grade_points,
                        r.grade_remark,
                        r.created_at,
                        r.published_date,
                        r.published_by,
                        r.calculated_by,
                        r.is_published,
                        r.rejection_reason,
                        r.remarks,
                        c.course_code,
                        c.course_title,
                        c.credit_units,
                        c.level as course_level,
                        c.department_id as course_dept_id,
                        c.is_core,
                        c.is_elective,
                        d.department_name as course_department,
                        gs.grade_points as scale_grade_points,
                        gs.remark as scale_remark,
                        gs.min_score,
                        gs.max_score,
                        gs.is_pass
                      FROM results r
                      INNER JOIN courses c ON r.course_id = c.course_id
                      LEFT JOIN departments d ON c.department_id = d.department_id
                      LEFT JOIN grade_scale gs ON r.grade = gs.grade
                      WHERE r.student_id = ? 
                        AND r.session_year = ? 
                        AND r.semester = ? 
                        AND r.is_published = 1
                      ORDER BY c.course_code";
    $stmt = $conn->prepare($results_query);
    $stmt->bind_param("iss", $student_id, $selected_session, $selected_semester);
    $stmt->execute();
    $results = $stmt->get_result();

    // Calculate semester statistics
    if($results->num_rows > 0) {
        $results->data_seek(0);
        while($result = $results->fetch_assoc()) {
            $units = $result['credit_units'];
            $grade = $result['grade'];
            $grade_point = $result['grade_points'] !== null ? $result['grade_points'] : ($result['scale_grade_points'] ?? 0);
            
            $total_units += $units;
            $total_points += $grade_point * $units;
            
            if($grade == 'F' || ($grade_point == 0 && $grade == 'F')) {
                $courses_failed++;
            } else {
                $courses_passed++;
            }
            
            if(isset($grade_distribution[$grade])) {
                $grade_distribution[$grade]++;
            }
        }
        $gpa = $total_units > 0 ? $total_points / $total_units : 0;
        $results->data_seek(0);
    }

    // Get previous CGPA
    $prev_cgpa_query = "SELECT gpa FROM academic_records 
                        WHERE student_id = ? 
                        AND (session_year < ? OR (session_year = ? AND semester < ?))
                        ORDER BY session_year DESC, semester DESC 
                        LIMIT 1";
    $stmt = $conn->prepare($prev_cgpa_query);
    $stmt->bind_param("issi", $student_id, $selected_session, $selected_session, $selected_semester);
    $stmt->execute();
    $prev_result = $stmt->get_result();
    $previous_cgpa = $prev_result->num_rows > 0 ? floatval($prev_result->fetch_assoc()['gpa']) : 0;

    // Calculate current CGPA from all published results
    $all_results_query = "SELECT r.total_score, r.grade, r.grade_points, c.credit_units
                          FROM results r
                          INNER JOIN courses c ON r.course_id = c.course_id
                          WHERE r.student_id = ? AND r.is_published = 1";
    $stmt = $conn->prepare($all_results_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $all_results = $stmt->get_result();
    
    $total_all_units = 0;
    $total_all_points = 0;
    while($row = $all_results->fetch_assoc()) {
        $total_all_units += $row['credit_units'];
        $grade_point = $row['grade_points'] !== null ? $row['grade_points'] : 0;
        $total_all_points += $grade_point * $row['credit_units'];
    }
    $new_cgpa = $total_all_units > 0 ? $total_all_points / $total_all_units : 0;
}

function getGpaClass($gpa) {
    if($gpa >= 4.5) return 'excellent';
    if($gpa >= 3.5) return 'good';
    if($gpa >= 2.5) return 'average';
    if($gpa >= 1.5) return 'fair';
    return 'poor';
}
?>

<div class="fade-in">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-title">
            <h1>Academic Results</h1>
            <p><?php echo htmlspecialchars($student_info['faculty_name'] ?? 'Faculty'); ?> | 
               <?php echo htmlspecialchars($student_info['department_name'] ?? 'Department'); ?></p>
            <p class="student-info">
                Matric: <?php echo htmlspecialchars($student_info['matric_number'] ?? 'N/A'); ?> | 
                Name: <?php echo htmlspecialchars(($student_info['first_name'] ?? '') . ' ' . ($student_info['last_name'] ?? '')); ?> | 
                Level: <?php echo $student_info['current_level'] ?? 'N/A'; ?>
            </p>
        </div>
    </div>

    <!-- Academic Year Cards -->
    <?php if(!empty($academic_years)): ?>
    <div class="academic-years-section">
        <h2>Select Academic Session</h2>
        <div class="year-cards">
            <?php foreach($academic_years as $year => $semesters): ?>
            <div class="year-card <?php echo ($selected_session == $year) ? 'active' : ''; ?>">
                <div class="year-header">
                    <h3><?php echo $year; ?></h3>
                </div>
                <div class="semester-buttons">
                    <?php if($semesters['first']): ?>
                    <a href="?session=<?php echo $year; ?>&semester=1" 
                       class="semester-btn first <?php echo ($selected_session == $year && $selected_semester == 1) ? 'active' : ''; ?>">
                        <span class="semester-icon">📚</span>
                        First Semester
                        <?php if($selected_session == $year && $selected_semester == 1): ?>
                        <span class="check-mark">✓</span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php if($semesters['second']): ?>
                    <a href="?session=<?php echo $year; ?>&semester=2" 
                       class="semester-btn second <?php echo ($selected_session == $year && $selected_semester == 2) ? 'active' : ''; ?>">
                        <span class="semester-icon">📖</span>
                        Second Semester
                        <?php if($selected_session == $year && $selected_semester == 2): ?>
                        <span class="check-mark">✓</span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Results Display Section -->
    <?php if($results && $results->num_rows > 0): ?>
    
    <!-- Quick Stats Bar -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-icon">📊</div>
            <div class="stat-info">
                <span class="stat-label">Semester GPA</span>
                <span class="stat-value <?php echo getGpaClass($gpa); ?>"><?php echo number_format($gpa, 2); ?></span>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">🎯</div>
            <div class="stat-info">
                <span class="stat-label">CGPA</span>
                <span class="stat-value"><?php echo number_format($new_cgpa, 2); ?></span>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <span class="stat-label">Passed</span>
                <span class="stat-value success"><?php echo $courses_passed; ?></span>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">❌</div>
            <div class="stat-info">
                <span class="stat-label">Failed</span>
                <span class="stat-value <?php echo $courses_failed > 0 ? 'danger' : ''; ?>"><?php echo $courses_failed; ?></span>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">📚</div>
            <div class="stat-info">
                <span class="stat-label">Credit Units</span>
                <span class="stat-value"><?php echo $total_units; ?></span>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button class="action-btn print-btn" onclick="window.print()">
            <svg viewBox="0 0 24 24" width="18" height="18">
                <path d="M19 8H5v11h14V8zm0-2v-2H5v2h14zM6 13h5v2H6v-2z"/>
            </svg>
            Print Results
        </button>
        <button class="action-btn download-btn" onclick="downloadResultsPDF()">
            <svg viewBox="0 0 24 24" width="18" height="18">
                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
            </svg>
            Download PDF
        </button>
    </div>

    <!-- Results Card -->
    <div class="results-card" id="resultsCard">
        <div class="results-header">
            <div class="university-info">
                <h2><?php echo htmlspecialchars($student_info['faculty_name'] ?? 'University'); ?> University</h2>
                <p><?php echo htmlspecialchars($student_info['department_name'] ?? ''); ?> Department</p>
            </div>
            <div class="session-info">
                <h3>Academic Results</h3>
                <p><?php echo $selected_session; ?> - <?php echo $selected_semester == 1 ? 'First' : 'Second'; ?> Semester</p>
            </div>
        </div>

        <div class="student-info-section">
            <div class="info-row">
                <div class="info-group">
                    <label>Student Name:</label>
                    <span><?php echo htmlspecialchars(($student_info['first_name'] ?? '') . ' ' . ($student_info['last_name'] ?? '')); ?></span>
                </div>
                <div class="info-group">
                    <label>Matric Number:</label>
                    <span><?php echo htmlspecialchars($student_info['matric_number'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-group">
                    <label>Program:</label>
                    <span><?php echo htmlspecialchars($student_info['program_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-group">
                    <label>Level:</label>
                    <span><?php echo $student_info['current_level'] ?? 'N/A'; ?></span>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="results-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Credit Units</th>
                        <th>CA (40)</th>
                        <th>Exam (60)</th>
                        <th>Total (100)</th>
                        <th>Grade</th>
                        <th>GP</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $results->data_seek(0);
                    $sn = 1;
                    while($result = $results->fetch_assoc()): 
                        $status = ($result['grade'] == 'F') ? 'Failed' : 'Passed';
                        $status_class = ($result['grade'] == 'F') ? 'failed' : 'passed';
                        $grade_class = strtolower($result['grade']);
                        $grade_point = $result['grade_points'] !== null ? $result['grade_points'] : ($result['scale_grade_points'] ?? 0);
                    ?>
                    <tr class="grade-<?php echo $grade_class; ?>">
                        <td class="text-center"><?php echo $sn++; ?></td>
                        <td class="course-code"><?php echo htmlspecialchars($result['course_code']); ?></td>
                        <td><?php echo htmlspecialchars(substr($result['course_title'], 0, 50)); ?></td>
                        <td class="text-center"><?php echo $result['credit_units']; ?></td>
                        <td class="text-center"><?php echo number_format($result['ca_score'], 1); ?></td>
                        <td class="text-center"><?php echo number_format($result['exam_score'], 1); ?></td>
                        <td class="text-center total"><strong><?php echo number_format($result['total_score'], 1); ?></strong></td>
                        <td class="text-center">
                            <span class="grade-badge <?php echo $grade_class; ?>">
                                <?php echo $result['grade']; ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo number_format($grade_point, 2); ?></td>
                        <td class="text-center">
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $status; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="summary-row">
                        <td colspan="3"><strong>Total Credit Units: <?php echo $total_units; ?></strong></td>
                        <td colspan="2"></td>
                        <td colspan="2"></td>
                        <td colspan="2"><strong>Semester GPA:</strong></td>
                        <td><strong class="<?php echo getGpaClass($gpa); ?>"><?php echo number_format($gpa, 2); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="results-footer">
            <div class="grade-summary">
                <div class="grade-distribution">
                    <?php foreach(['A', 'B', 'C', 'D', 'E', 'F'] as $grade): 
                        $count = $grade_distribution[$grade] ?? 0;
                        if($count > 0):
                    ?>
                    <div class="grade-item">
                        <span class="grade-letter <?php echo strtolower($grade); ?>"><?php echo $grade; ?></span>
                        <span class="grade-count"><?php echo $count; ?></span>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
            <div class="signature-section">
                <div class="signature-line">
                    <span>Registrar's Signature</span>
                    <span class="date">Date: <?php echo date('d/m/Y'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php elseif($selected_session && $selected_semester): ?>
    <!-- No Results for Selected Session -->
    <div class="no-results-card">
        <div class="no-results-icon">📭</div>
        <h2>No Results Available</h2>
        <p>Results for <?php echo $selected_session; ?> - <?php echo $selected_semester == 1 ? 'First' : 'Second'; ?> Semester have not been published yet.</p>
        <p class="small">Please select another session from the cards above.</p>
    </div>

    <?php elseif(empty($academic_years)): ?>
    <!-- No Results at All -->
    <div class="no-results-card">
        <div class="no-results-icon">📋</div>
        <h2>No Results Found</h2>
        <p>You don't have any published results yet.</p>
        <p class="small">Check back later or contact the academic department.</p>
    </div>
    <?php endif; ?>
</div>

<!-- PDF Generation Script -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
function downloadResultsPDF() {
    const element = document.getElementById('resultsCard');
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: 'Result_<?php echo $selected_session . '_' . ($selected_semester == 1 ? 'First' : 'Second') . '_Semester_' . $student_info['matric_number']; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, letterRendering: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>

<style>
/* Academic Years Cards */
.academic-years-section {
    margin-bottom: 30px;
}

.academic-years-section h2 {
    font-size: 18px;
    color: var(--text-dark);
    margin-bottom: 15px;
}

.year-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}

.year-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: var(--transition);
}

.year-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-lg);
}

.year-card.active {
    border: 2px solid var(--primary-color);
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
}

.year-header {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    padding: 15px 20px;
    text-align: center;
}

.year-header h3 {
    color: white;
    font-size: 20px;
    margin: 0;
}

.semester-buttons {
    padding: 15px;
    display: flex;
    gap: 12px;
}

.semester-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    background: var(--gray-100);
    border-radius: 12px;
    text-decoration: none;
    color: var(--text-dark);
    font-weight: 500;
    transition: var(--transition);
    position: relative;
}

.semester-btn.first:hover {
    background: #e3f2fd;
    color: #1565c0;
}

.semester-btn.second:hover {
    background: #fff3e0;
    color: #ef6c00;
}

.semester-btn.active {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.semester-btn.active .semester-icon {
    filter: brightness(0) invert(1);
}

.check-mark {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #4caf50;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.semester-icon {
    font-size: 18px;
}

/* Stats Bar */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.stat-item {
    background: white;
    border-radius: 16px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: var(--shadow);
}

.stat-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--primary-soft), transparent);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-info {
    flex: 1;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: var(--text-light);
    margin-bottom: 4px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-dark);
}

.stat-value.excellent { color: #4caf50; }
.stat-value.good { color: #2196f3; }
.stat-value.average { color: #ff9800; }
.stat-value.fair { color: #ff5722; }
.stat-value.poor { color: #f44336; }
.stat-value.success { color: #4caf50; }
.stat-value.danger { color: #f44336; }

/* Action Buttons */
.action-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-bottom: 30px;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.action-btn svg {
    fill: currentColor;
}

.print-btn {
    background: linear-gradient(135deg, #607d8b, #455a64);
    color: white;
}

.print-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(96, 125, 139, 0.3);
}

.download-btn {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.download-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
}

/* Results Card */
.results-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
}

.results-header {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    padding: 25px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.university-info h2 {
    font-size: 24px;
    margin-bottom: 5px;
}

.university-info p {
    font-size: 14px;
    opacity: 0.9;
}

.session-info {
    text-align: right;
}

.session-info h3 {
    font-size: 20px;
    margin-bottom: 5px;
}

.session-info p {
    font-size: 14px;
    opacity: 0.9;
}

.student-info-section {
    padding: 20px 25px;
    background: var(--gray-100);
    border-bottom: 1px solid var(--gray-200);
}

.info-row {
    display: flex;
    flex-wrap: wrap;
    gap: 30px;
}

.info-group {
    display: flex;
    gap: 8px;
}

.info-group label {
    font-weight: 600;
    color: var(--text-light);
}

.info-group span {
    color: var(--text-dark);
    font-weight: 500;
}

.results-table {
    width: 100%;
    border-collapse: collapse;
}

.results-table th {
    background: var(--primary-soft);
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 13px;
    padding: 14px 10px;
    text-align: center;
}

.results-table td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--gray-200);
    font-size: 13px;
}

.results-table td.text-center {
    text-align: center;
}

.course-code {
    font-weight: 600;
    color: var(--primary-color);
}

.grade-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    min-width: 40px;
}

.grade-badge.a { background: #4caf50; color: white; }
.grade-badge.b { background: #2196f3; color: white; }
.grade-badge.c { background: #ff9800; color: white; }
.grade-badge.d { background: #9c27b0; color: white; }
.grade-badge.e { background: #ff5722; color: white; }
.grade-badge.f { background: #f44336; color: white; }

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.passed {
    background: #4caf50;
    color: white;
}

.status-badge.failed {
    background: #f44336;
    color: white;
}

.grade-a { background: rgba(76, 175, 80, 0.05); }
.grade-b { background: rgba(33, 150, 243, 0.05); }
.grade-c { background: rgba(255, 152, 0, 0.05); }
.grade-d { background: rgba(156, 39, 176, 0.05); }
.grade-e { background: rgba(255, 87, 34, 0.05); }
.grade-f { background: rgba(244, 67, 54, 0.05); }

.summary-row {
    background: var(--gray-100);
    font-weight: 600;
}

.results-footer {
    padding: 20px 25px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.grade-summary {
    display: flex;
    gap: 15px;
}

.grade-distribution {
    display: flex;
    gap: 12px;
}

.grade-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.grade-letter {
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 6px;
}

.grade-letter.a { background: #4caf50; color: white; }
.grade-letter.b { background: #2196f3; color: white; }
.grade-letter.c { background: #ff9800; color: white; }
.grade-letter.d { background: #9c27b0; color: white; }
.grade-letter.e { background: #ff5722; color: white; }
.grade-letter.f { background: #f44336; color: white; }

.signature-section {
    text-align: right;
}

.signature-line {
    border-top: 1px solid #333;
    padding-top: 10px;
    min-width: 200px;
}

.signature-line span {
    font-size: 11px;
    color: var(--text-light);
}

.no-results-card {
    background: white;
    border-radius: 20px;
    padding: 60px 30px;
    text-align: center;
    box-shadow: var(--shadow);
}

.no-results-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.no-results-card h2 {
    color: var(--text-dark);
    margin-bottom: 10px;
}

.no-results-card p {
    color: var(--text-light);
}

.no-results-card .small {
    font-size: 13px;
    margin-top: 10px;
}

@media (max-width: 768px) {
    .year-cards {
        grid-template-columns: 1fr;
    }
    
    .stats-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .results-header {
        flex-direction: column;
        text-align: center;
    }
    
    .session-info {
        text-align: center;
    }
    
    .info-row {
        flex-direction: column;
        gap: 12px;
    }
    
    .results-footer {
        flex-direction: column;
        text-align: center;
    }
    
    .signature-section {
        text-align: center;
    }
}

@media print {
    .academic-years-section,
    .stats-bar,
    .action-buttons,
    .page-header {
        display: none;
    }
    
    .results-card {
        box-shadow: none;
        margin: 0;
        padding: 0;
    }
    
    .results-table th {
        background: #ddd;
        color: #000;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>