<?php
// courses.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Course Management";

// Helper function for export URLs
function getExportQueryString() {
    $params = [];
    
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['faculty_id'])) {
        $params['faculty_id'] = $_GET['faculty_id'];
    }
    if (!empty($_GET['department_id'])) {
        $params['department_id'] = $_GET['department_id'];
    }
    if (!empty($_GET['program_id'])) {
        $params['program_id'] = $_GET['program_id'];
    }
    if (!empty($_GET['level'])) {
        $params['level'] = $_GET['level'];
    }
    if (!empty($_GET['semester'])) {
        $params['semester'] = $_GET['semester'];
    }
    if (!empty($_GET['course_type'])) {
        $params['course_type'] = $_GET['course_type'];
    }
    
    return !empty($params) ? '&' . http_build_query($params) : '';
}

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$course_type = isset($_GET['course_type']) ? $_GET['course_type'] : '';

// Build query conditions
$conditions = [];
$params_count = [];
$query_params = [];

if (!empty($search)) {
    $conditions[] = "(c.course_code LIKE ? OR c.course_title LIKE ? OR c.course_description LIKE ?)";
    $search_term = "%{$search}%";
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

if ($faculty_id > 0) {
    $conditions[] = "d.faculty_id = ?";
    $query_params[] = $faculty_id;
}

if ($department_id > 0) {
    $conditions[] = "c.department_id = ?";
    $query_params[] = $department_id;
}

if ($program_id > 0) {
    $conditions[] = "p.program_id = ?";
    $query_params[] = $program_id;
}

if ($level > 0) {
    $conditions[] = "c.level = ?";
    $query_params[] = $level;
}

if ($semester !== '') {
    $conditions[] = "c.semester = ?";
    $query_params[] = $semester;
}

// Handle course_type
if ($course_type !== '') {
    if ($course_type === 'core') {
        $conditions[] = "c.is_core = 1";
    } elseif ($course_type === 'elective') {
        $conditions[] = "c.is_elective = 1";
    }
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN programs p ON p.department_id = d.department_id
    LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get courses data
$sql = "
    SELECT 
        c.*,
        d.department_name,
        d.department_code,
        d.faculty_id,
        f.faculty_name,
        f.faculty_code as faculty_code,
        pre.course_code as prerequisite_code,
        pre.course_title as prerequisite_title,
        (SELECT COUNT(*) FROM course_registrations cr WHERE cr.course_id = c.course_id) as registration_count,
        (SELECT COUNT(*) FROM results r WHERE r.course_id = c.course_id AND r.is_published = 1) as result_count,
        (SELECT GROUP_CONCAT(DISTINCT p.program_name SEPARATOR ', ') 
         FROM course_programs cp 
         JOIN programs p ON cp.program_id = p.program_id 
         WHERE cp.course_id = c.course_id) as program_names
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
    {$where_clause}
    ORDER BY f.faculty_name, d.department_name, c.level, c.semester, c.course_code
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($query_params);
$courses = $stmt->fetchAll();

// Get data for filters
$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
$departments = $pdo->query("SELECT d.*, f.faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.faculty_id ORDER BY d.department_name")->fetchAll();
$programs = $pdo->query("SELECT p.*, d.department_name, f.faculty_name, f.faculty_id 
                         FROM programs p 
                         LEFT JOIN departments d ON p.department_id = d.department_id 
                         LEFT JOIN faculties f ON d.faculty_id = f.faculty_id 
                         WHERE p.is_active = 1 
                         ORDER BY f.faculty_name, d.department_name, p.program_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$semester_options = ['1' => 'First Semester', '2' => 'Second Semester'];
$course_type_options = ['all' => 'All Courses', 'core' => 'Core Courses', 'elective' => 'Elective Courses'];

// Valid elective types
$valid_elective_types = ['University', 'Faculty', 'Department'];

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_courses'])) {
        $selected_ids = $_POST['selected_courses'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'delete':
                    // Check if courses have registrations or results
                    $check_sql = "SELECT course_id, course_code, 
                                 (SELECT COUNT(*) FROM course_registrations WHERE course_id = ?) as reg_count,
                                 (SELECT COUNT(*) FROM results WHERE course_id = ?) as res_count
                          FROM courses 
                          WHERE course_id = ?";
                    
                    $deletable = [];
                    $non_deletable = [];
                    
                    foreach ($selected_ids as $course_id) {
                        $check_stmt = $pdo->prepare($check_sql);
                        $check_stmt->execute([$course_id, $course_id, $course_id]);
                        $course_check = $check_stmt->fetch();
                        
                        if ($course_check && ($course_check['reg_count'] > 0 || $course_check['res_count'] > 0)) {
                            $non_deletable[] = $course_check['course_code'];
                        } else {
                            $deletable[] = $course_id;
                        }
                    }
                    
                    if (!empty($deletable)) {
                        $deletable_placeholders = implode(',', array_fill(0, count($deletable), '?'));
                        $delete_stmt = $pdo->prepare("DELETE FROM courses WHERE course_id IN ($deletable_placeholders)");
                        $delete_stmt->execute($deletable);
                        $deleted_count = count($deletable);
                    } else {
                        $deleted_count = 0;
                    }
                    
                    $message = "Deleted {$deleted_count} course(s).";
                    if (!empty($non_deletable)) {
                        $message .= " Could not delete: " . implode(', ', $non_deletable) . " (has registrations/results)";
                    }
                    
                    $_SESSION['success_message'] = $message;
                    break;
            }
        }
        
        header("Location: courses.php");
        exit();
    }
    
    // Import courses from CSV
    if (isset($_POST['import_courses']) && isset($_FILES['csv_file'])) {
        // Get the selected faculty, department, program, and level
        $import_faculty_id = isset($_POST['import_faculty_id']) ? (int)$_POST['import_faculty_id'] : 0;
        $import_department_id = isset($_POST['import_department_id']) ? (int)$_POST['import_department_id'] : 0;
        $import_program_id = isset($_POST['import_program_id']) ? (int)$_POST['import_program_id'] : 0;
        $import_level = isset($_POST['import_level']) ? (int)$_POST['import_level'] : 0;
        
        // Validate selections
        if ($import_faculty_id == 0 || $import_department_id == 0 || $import_program_id == 0 || $import_level == 0) {
            $_SESSION['error_message'] = "Please select Faculty, Department, Program, and Level before importing.";
            header("Location: courses.php");
            exit();
        }
        
        if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            $file_name = $_FILES['csv_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if ($file_ext === 'csv') {
                $imported_count = 0;
                $updated_count = 0;
                $failed_count = 0;
                $warnings = [];
                $errors = [];
                
                if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
                    // Read headers
                    $headers = fgetcsv($handle);
                    
                    if ($headers === FALSE) {
                        $_SESSION['error_message'] = "CSV file is empty or invalid.";
                        header("Location: courses.php");
                        exit();
                    }
                    
                    // Clean headers
                    $header_map = [];
                    foreach ($headers as $index => $header) {
                        $clean_header = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header);
                        $clean_header = trim($clean_header);
                        $clean_header = strtolower($clean_header);
                        
                        if (!empty($clean_header)) {
                            $header_map[$clean_header] = $index;
                        }
                    }
                    
                    // Required columns
                    $required_columns = ['course_code', 'course_title', 'credit_units'];
                    $missing_columns = [];
                    
                    foreach ($required_columns as $col) {
                        if (!isset($header_map[$col])) {
                            $missing_columns[] = $col;
                        }
                    }
                    
                    if (!empty($missing_columns)) {
                        $_SESSION['error_message'] = "Missing required column(s): " . implode(', ', $missing_columns);
                        fclose($handle);
                        header("Location: courses.php");
                        exit();
                    }
                    
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // Process each row
                    $row_num = 1;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_num++;
                        
                        try {
                            // Map data using headers
                            $course_code = isset($header_map['course_code']) && isset($data[$header_map['course_code']]) 
                                         ? trim($data[$header_map['course_code']]) : '';
                            
                            $course_title = isset($header_map['course_title']) && isset($data[$header_map['course_title']]) 
                                          ? trim($data[$header_map['course_title']]) : '';
                            
                            $credit_units = isset($header_map['credit_units']) && isset($data[$header_map['credit_units']]) 
                                          ? (int)trim($data[$header_map['credit_units']]) : 3;
                            
                            // Validate required fields
                            if (empty($course_code)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Course code is empty";
                                continue;
                            }
                            
                            if (empty($course_title)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Course title is empty";
                                continue;
                            }
                            
                            if ($credit_units < 1 || $credit_units > 6) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Credit units must be between 1 and 6 (got: $credit_units)";
                                continue;
                            }
                            
                            // Optional fields
                            $semester_val = isset($header_map['semester']) && isset($data[$header_map['semester']]) && $data[$header_map['semester']] !== ''
                                          ? (int)trim($data[$header_map['semester']]) : null;
                            
                            $prerequisite_code = isset($header_map['prerequisite_code']) && isset($data[$header_map['prerequisite_code']]) 
                                               ? trim($data[$header_map['prerequisite_code']]) : '';
                            
                            $is_core = isset($header_map['is_core']) && isset($data[$header_map['is_core']]) && $data[$header_map['is_core']] !== ''
                                     ? (int)trim($data[$header_map['is_core']]) : 1;
                            
                            $is_elective = isset($header_map['is_elective']) && isset($data[$header_map['is_elective']]) && $data[$header_map['is_elective']] !== ''
                                         ? (int)trim($data[$header_map['is_elective']]) : 0;
                            
                            // Handle elective_type
                            $elective_type = null;
                            if (isset($header_map['elective_type']) && isset($data[$header_map['elective_type']]) && $data[$header_map['elective_type']] !== '') {
                                $raw_elective_type = trim($data[$header_map['elective_type']]);
                                if (!empty($raw_elective_type)) {
                                    if (in_array($raw_elective_type, $valid_elective_types)) {
                                        $elective_type = $raw_elective_type;
                                    } else {
                                        $warnings[] = "Row $row_num: Invalid elective_type '$raw_elective_type' - must be University, Faculty, or Department. Using NULL.";
                                    }
                                }
                            }
                            
                            $course_description = isset($header_map['course_description']) && isset($data[$header_map['course_description']]) 
                                                ? trim($data[$header_map['course_description']]) : '';
                            
                            // Get prerequisite course_id
                            $prerequisite_course_id = null;
                            if (!empty($prerequisite_code)) {
                                $pre_stmt = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ?");
                                $pre_stmt->execute([$prerequisite_code]);
                                $prerequisite = $pre_stmt->fetch();
                                if ($prerequisite) {
                                    $prerequisite_course_id = $prerequisite['course_id'];
                                } else {
                                    $warnings[] = "Row $row_num: Prerequisite course '$prerequisite_code' not found - prerequisite will be ignored.";
                                }
                            }
                            
                            // Check for existing course
                            $check_sql = "SELECT course_id FROM courses WHERE course_code = ?";
                            $check_stmt = $pdo->prepare($check_sql);
                            $check_stmt->execute([$course_code]);
                            
                            if ($check_stmt->rowCount() > 0) {
                                // Update existing course
                                $update_sql = "UPDATE courses SET 
                                    course_title = ?, 
                                    credit_units = ?, 
                                    department_id = ?,
                                    level = ?, 
                                    semester = ?, 
                                    prerequisite_course_id = ?,
                                    is_core = ?, 
                                    is_elective = ?, 
                                    elective_type = ?,
                                    course_description = ?
                                    WHERE course_code = ?";
                                
                                $update_stmt = $pdo->prepare($update_sql);
                                $update_result = $update_stmt->execute([
                                    $course_title, 
                                    $credit_units, 
                                    $import_department_id,
                                    $import_level, 
                                    $semester_val, 
                                    $prerequisite_course_id,
                                    $is_core, 
                                    $is_elective, 
                                    $elective_type,
                                    $course_description,
                                    $course_code
                                ]);
                                
                                if ($update_result) {
                                    $updated_count++;
                                } else {
                                    $failed_count++;
                                    $error_info = $update_stmt->errorInfo();
                                    $errors[] = "Row $row_num: Failed to update course - " . ($error_info[2] ?? 'Unknown error');
                                }
                            } else {
                                // Insert new course
                                $insert_sql = "INSERT INTO courses (
                                    course_code, 
                                    course_title, 
                                    credit_units, 
                                    department_id,
                                    level, 
                                    semester, 
                                    prerequisite_course_id, 
                                    is_core, 
                                    is_elective,
                                    elective_type, 
                                    course_description, 
                                    created_by, 
                                    created_date
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
                                
                                $insert_stmt = $pdo->prepare($insert_sql);
                                $insert_result = $insert_stmt->execute([
                                    $course_code, 
                                    $course_title, 
                                    $credit_units, 
                                    $import_department_id,
                                    $import_level, 
                                    $semester_val, 
                                    $prerequisite_course_id,
                                    $is_core, 
                                    $is_elective, 
                                    $elective_type,
                                    $course_description,
                                    $_SESSION['admin_id'] ?? 1
                                ]);
                                
                                if ($insert_result) {
                                    $imported_count++;
                                } else {
                                    $failed_count++;
                                    $error_info = $insert_stmt->errorInfo();
                                    $errors[] = "Row $row_num: Failed to insert course - " . ($error_info[2] ?? 'Unknown error');
                                }
                            }
                            
                            // Link course to program if not already linked
                            $check_link_sql = "SELECT cp_id FROM course_programs WHERE course_code = ? AND program_id = ?";
                            $check_link_stmt = $pdo->prepare($check_link_sql);
                            $check_link_stmt->execute([$course_code, $import_program_id]);
                            
                            if ($check_link_stmt->rowCount() == 0) {
                                $link_sql = "INSERT INTO course_programs (course_code, program_id) VALUES (?, ?)";
                                $link_stmt = $pdo->prepare($link_sql);
                                $link_stmt->execute([$course_code, $import_program_id]);
                            }
                            
                        } catch (Exception $e) {
                            $failed_count++;
                            $errors[] = "Row $row_num: " . $e->getMessage();
                        }
                    }
                    
                    // Commit transaction
                    $pdo->commit();
                    fclose($handle);
                    
                    // Set result messages
                    $total_processed = $imported_count + $updated_count;
                    if ($total_processed > 0) {
                        $message = "Import completed!";
                        if ($imported_count > 0) {
                            $message .= " $imported_count new course(s) added.";
                        }
                        if ($updated_count > 0) {
                            $message .= " $updated_count existing course(s) updated.";
                        }
                        if ($failed_count > 0) {
                            $message .= " $failed_count row(s) failed.";
                        }
                        $_SESSION['success_message'] = $message;
                    } else {
                        $_SESSION['error_message'] = "No courses imported. $failed_count rows failed.";
                    }
                    
                    // Store warnings and errors
                    if (!empty($warnings)) {
                        $_SESSION['import_warnings'] = array_slice($warnings, 0, 20);
                    }
                    if ($failed_count > 0 && !empty($errors)) {
                        $_SESSION['import_errors'] = array_slice($errors, 0, 20);
                    }
                    
                } else {
                    $_SESSION['error_message'] = "Unable to read CSV file.";
                }
            } else {
                $_SESSION['error_message'] = "Please upload a CSV file (.csv extension).";
            }
        } else {
            $_SESSION['error_message'] = "Error uploading file.";
        }
        
        header("Location: courses.php");
        exit();
    }
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['import_warnings'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <h6><i class="fas fa-exclamation-triangle me-2"></i>Import Warnings:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['import_warnings'] as $warning): ?>
                <li><?php echo htmlspecialchars($warning); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['import_warnings']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h6><i class="fas fa-times-circle me-2"></i>Import Errors:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Course Management</h1>
    <div class="app-actions">
        <a href="add_course.php" class="btn app-btn-primary">
            <i class="fas fa-plus-circle me-2"></i>Add New Course
        </a>
        <button class="btn app-btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import me-2"></i>Import Courses
        </button>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters & Search
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Course code, title, description..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty_id" id="faculty_filter">
                    <option value="0">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                    <option value="<?php echo $faculty['faculty_id']; ?>"
                        <?php echo ($faculty_id == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id" id="department_filter">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        data-faculty="<?php echo $dept['faculty_id'] ?? ''; ?>"
                        <?php echo ($department_id == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                    <option value="<?php echo $program['program_id']; ?>"
                        data-department="<?php echo $program['department_id']; ?>"
                        data-faculty="<?php echo $program['faculty_id']; ?>"
                        <?php echo ($program_id == $program['program_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($program['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="0">All</option>
                    <?php foreach ($levels as $lvl): ?>
                    <option value="<?php echo $lvl; ?>" 
                        <?php echo ($level == $lvl) ? 'selected' : ''; ?>>
                        <?php echo $lvl; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester">
                    <option value="">All</option>
                    <?php foreach ($semester_options as $val => $label): ?>
                    <option value="<?php echo $val; ?>"
                        <?php echo ($semester == $val) ? 'selected' : ''; ?>>
                        <?php echo substr($label, 0, 3); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i>
                    </button>
                    <a href="courses.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Courses</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-book text-primary"></i> All Courses
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Core Courses</div>
                <div class="stats-figure">
                    <?php 
                    $core_count = $pdo->query("SELECT COUNT(*) FROM courses WHERE is_core = 1")->fetchColumn();
                    echo number_format($core_count);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-star"></i> Mandatory Courses
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Elective Courses</div>
                <div class="stats-figure">
                    <?php 
                    $elective_count = $pdo->query("SELECT COUNT(*) FROM courses WHERE is_elective = 1")->fetchColumn();
                    echo number_format($elective_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-asterisk"></i> Optional Courses
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Registrations</div>
                <div class="stats-figure">
                    <?php 
                    $current_session = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn();
                    if ($current_session) {
                        $reg_count = $pdo->query("SELECT COUNT(DISTINCT course_id) FROM course_registrations WHERE session_year = '$current_session'")->fetchColumn();
                    } else {
                        $reg_count = 0;
                    }
                    echo number_format($reg_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-users"></i> Current Session
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Courses Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Courses List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> courses
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_courses.php?format=excel<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_courses.php?format=pdf<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_courses.php?format=csv<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="cell" width="30">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="select-all">
                                </div>
                            </th>
                            <th class="cell">Course Code</th>
                            <th class="cell">Course Title</th>
                            <th class="cell">Faculty</th>
                            <th class="cell">Department</th>
                            <th class="cell">Program(s)</th>
                            <th class="cell">Level/Sem</th>
                            <th class="cell">Units</th>
                            <th class="cell">Type</th>
                            <th class="cell">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($courses)): ?>
                            <?php foreach ($courses as $course): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_courses[]" 
                                               value="<?php echo $course['course_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <strong class="text-primary"><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                </td>
                                <td class="cell">
                                    <div class="fw-bold"><?php echo htmlspecialchars($course['course_title']); ?></div>
                                    <?php if (!empty($course['course_description'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($course['course_description'], 0, 50)); ?>...</small>
                                    <?php endif; ?>
                                 </td>
                                <td class="cell">
                                    <span class="badge bg-info"><?php echo htmlspecialchars($course['faculty_code'] ?? 'N/A'); ?></span>
                                    <br><small><?php echo htmlspecialchars(substr($course['faculty_name'] ?? '', 0, 15)); ?></small>
                                 </td>
                                <td class="cell">
                                    <?php echo htmlspecialchars($course['department_code'] ?? 'N/A'); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($course['department_name'] ?? '', 0, 12)); ?></small>
                                 </td>
                                <td class="cell">
                                    <?php if (!empty($course['program_names'])): ?>
                                    <span class="badge bg-secondary" title="<?php echo htmlspecialchars($course['program_names']); ?>">
                                        <?php echo substr($course['program_names'], 0, 30); ?>
                                        <?php echo strlen($course['program_names']) > 30 ? '...' : ''; ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                 </td>
                                <td class="cell">
                                    <div class="d-flex flex-column">
                                        <span class="badge bg-info mb-1"><?php echo $course['level'] ?? 'N/A'; ?> Level</span>
                                        <?php if ($course['semester']): ?>
                                        <span class="badge bg-secondary">Sem <?php echo $course['semester']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                                <td class="cell">
                                    <span class="badge bg-warning"><?php echo $course['credit_units']; ?> Units</span>
                                 </td>
                                <td class="cell">
                                    <?php if ($course['is_core']): ?>
                                    <span class="badge bg-primary">Core</span>
                                    <?php elseif ($course['is_elective']): ?>
                                    <span class="badge bg-success">Elective</span>
                                    <?php if ($course['elective_type']): ?>
                                    <br><small><?php echo $course['elective_type']; ?></small>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">General</span>
                                    <?php endif; ?>
                                 </td>
                                <td class="cell text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="dropdown" 
                                                data-bs-auto-close="outside">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="view_course.php?id=<?php echo $course['course_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_course.php?id=<?php echo $course['course_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $course['course_id']; ?>)">
                                                    <i class="fas fa-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                                        <h5>No courses found</h5>
                                        <p class="text-muted">No courses match your search criteria.</p>
                                        <?php if ($search || $faculty_id || $department_id || $program_id || $level || $semester || $course_type): ?>
                                        <a href="courses.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <a href="add_course.php" class="btn btn-primary">
                                            <i class="fas fa-plus-circle me-1"></i>Add New Course
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <!-- Table Footer -->
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="select-all-bottom">
                    <label class="form-check-label" for="select-all-bottom">
                        Select All
                    </label>
                </div>
                <div class="btn-group ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bulk-actions-btn">
                        Bulk Actions
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" 
                            data-bs-toggle="dropdown">
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('delete')">
                            <i class="fas fa-trash me-2"></i>Delete Selected
                        </a></li>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        if ($start_page > 1): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">
                    <i class="fas fa-file-import me-2"></i>Import Courses from CSV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <!-- Faculty Selection -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Select Faculty <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_faculty_id" id="import_faculty_id" required>
                                <option value="">-- Select Faculty --</option>
                                <?php foreach ($faculties as $faculty): ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">All courses will be assigned to this faculty</div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Select Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_department_id" id="import_department_id" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>" 
                                        data-faculty="<?php echo $dept['faculty_id'] ?? ''; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">All courses will be assigned to this department</div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Select Program <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_program_id" id="import_program_id" required>
                                <option value="">-- Select Program --</option>
                                <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['program_id']; ?>"
                                        data-department="<?php echo $program['department_id']; ?>">
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">All courses will be linked to this program</div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Select Level <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_level" id="import_level" required>
                                <option value="">-- Select Level --</option>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">All courses will be assigned to this level</div>
                        </div>
                    </div>
                    
                    <!-- File Upload -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Upload CSV File *</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                            <div class="form-text mt-2">
                                <a href="download_course_template.php" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="fas fa-download me-1"></i>Download CSV Template
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-2 fw-bold">Required Columns:</p>
                                <ul class="mb-3 small">
                                    <li><strong class="text-primary">course_code</strong> - Unique course code (e.g., CSC101)</li>
                                    <li><strong class="text-primary">course_title</strong> - Full course title</li>
                                    <li><strong class="text-primary">credit_units</strong> - Number of credits (1-6)</li>
                                </ul>
                                <p class="mb-2 fw-bold text-success">Note:</p>
                                <ul class="mb-3 small text-success">
                                    <li>Faculty, Department, Program, and Level are selected above - they don't need to be in the CSV</li>
                                    <li>Program linking is automatic - courses will be linked to the selected program</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-2 fw-bold">Optional Columns:</p>
                                <ul class="mb-3 small">
                                    <li><strong>semester</strong> - 1 or 2</li>
                                    <li><strong>prerequisite_code</strong> - Course code of prerequisite</li>
                                    <li><strong>is_core</strong> - 1 for core, 0 for not core (default: 1)</li>
                                    <li><strong>is_elective</strong> - 1 for elective, 0 for not elective (default: 0)</li>
                                    <li><strong>elective_type</strong> - Must be: University, Faculty, or Department</li>
                                    <li><strong>course_description</strong> - Course description</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Note:</strong> 
                            <ul class="mb-0 mt-1">
                                <li>Existing courses with the same course_code will be UPDATED</li>
                                <li>New courses will be ADDED</li>
                                <li><strong>elective_type</strong> must be exactly: University, Faculty, or Department (leave empty for non-elective courses)</li>
                                <li>Prerequisite courses must exist in the database or be imported first</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Sample CSV Preview -->
                    <div class="mt-3">
                        <p class="fw-bold mb-2">Sample CSV Format:</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>course_code</th>
                                        <th>course_title</th>
                                        <th>credit_units</th>
                                        <th>semester</th>
                                        <th>prerequisite_code</th>
                                        <th>is_core</th>
                                        <th>is_elective</th>
                                        <th>elective_type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>CSC101</td>
                                        <td>Introduction to Programming</td>
                                        <td>3</td>
                                        <td>1</td>
                                        <td></td>
                                        <td>1</td>
                                        <td>0</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td>CSC102</td>
                                        <td>Computer Programming II</td>
                                        <td>3</td>
                                        <td>2</td>
                                        <td>CSC101</td>
                                        <td>1</td>
                                        <td>0</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td>BUS301</td>
                                        <td>Business Ethics</td>
                                        <td>2</td>
                                        <td>1</td>
                                        <td></td>
                                        <td>0</td>
                                        <td>1</td>
                                        <td>Faculty</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="importForm" name="import_courses" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload & Import
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Filter departments based on faculty selection for import modal
document.getElementById('import_faculty_id').addEventListener('change', function() {
    const facultyId = this.value;
    const departmentSelect = document.getElementById('import_department_id');
    const programSelect = document.getElementById('import_program_id');
    const options = departmentSelect.querySelectorAll('option');
    
    // Filter departments
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = '';
        } else {
            const optionFaculty = option.getAttribute('data-faculty');
            if (facultyId === '' || optionFaculty == facultyId) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
                if (option.selected) {
                    option.selected = false;
                }
            }
        }
    });
    
    // Reset department and program selection
    departmentSelect.value = '';
    programSelect.innerHTML = '<option value="">-- Select Program --</option>';
});

// Filter programs based on department selection for import modal
document.getElementById('import_department_id').addEventListener('change', function() {
    const departmentId = this.value;
    const programSelect = document.getElementById('import_program_id');
    
    programSelect.innerHTML = '<option value="">-- Loading programs... --</option>';
    
    if (departmentId) {
        // Filter programs by department
        const allPrograms = <?php echo json_encode($programs); ?>;
        const filteredPrograms = allPrograms.filter(p => p.department_id == departmentId);
        
        programSelect.innerHTML = '<option value="">-- Select Program --</option>';
        if (filteredPrograms.length > 0) {
            filteredPrograms.forEach(program => {
                const option = document.createElement('option');
                option.value = program.program_id;
                option.text = program.program_name;
                programSelect.appendChild(option);
            });
        } else {
            programSelect.innerHTML = '<option value="">-- No programs found for this department --</option>';
        }
    } else {
        programSelect.innerHTML = '<option value="">-- First select department --</option>';
    }
});

// Filter departments based on faculty selection for main filter
const facultyFilter = document.getElementById('faculty_filter');
if (facultyFilter) {
    facultyFilter.addEventListener('change', function() {
        const facultyId = this.value;
        const departmentSelect = document.getElementById('department_filter');
        const programFilter = document.querySelector('select[name="program_id"]');
        const options = departmentSelect.querySelectorAll('option');
        
        // Filter departments
        options.forEach(option => {
            if (option.value === '0') {
                option.style.display = '';
            } else {
                const optionFaculty = option.getAttribute('data-faculty');
                if (facultyId === '0' || optionFaculty == facultyId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
        });
        
        // Reset department and program selection if hidden
        if (departmentSelect.value !== '0') {
            const selectedOption = departmentSelect.querySelector(`option[value="${departmentSelect.value}"]`);
            if (selectedOption && selectedOption.style.display === 'none') {
                departmentSelect.value = '0';
            }
        }
        
        // Filter programs by faculty
        if (programFilter) {
            const programOptions = programFilter.querySelectorAll('option');
            programOptions.forEach(option => {
                if (option.value === '0') {
                    option.style.display = '';
                } else {
                    const optionFaculty = option.getAttribute('data-faculty');
                    if (facultyId === '0' || optionFaculty == facultyId) {
                        option.style.display = '';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });
            
            if (programFilter.value !== '0') {
                const selectedProgramOption = programFilter.querySelector(`option[value="${programFilter.value}"]`);
                if (selectedProgramOption && selectedProgramOption.style.display === 'none') {
                    programFilter.value = '0';
                }
            }
        }
    });
}

// Select all checkboxes
const selectAllTop = document.getElementById('select-all');
const selectAllBottom = document.getElementById('select-all-bottom');

if (selectAllTop) {
    selectAllTop.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.select-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        if (selectAllBottom) selectAllBottom.checked = this.checked;
    });
}

if (selectAllBottom) {
    selectAllBottom.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.select-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        if (selectAllTop) selectAllTop.checked = this.checked;
    });
}

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one course.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected course(s)? This action cannot be undone.`;
            break;
        default:
            confirmMessage = `Are you sure you want to perform this action on ${selectedIds.length} course(s)?`;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function confirmDelete(courseId) {
    if (confirm('Are you sure you want to delete this course? This action cannot be undone.')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_courses[]';
        input.value = courseId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Validate import form
const csvFileInput = document.querySelector('input[name="csv_file"]');
if (csvFileInput) {
    csvFileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        const maxSize = 10 * 1024 * 1024;
        
        if (file) {
            if (file.size > maxSize) {
                alert('File size exceeds 10MB limit.');
                e.target.value = '';
            } else if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('Please select a CSV file.');
                e.target.value = '';
            }
        }
    });
}

// Import form submission
const importForm = document.getElementById('importForm');
if (importForm) {
    importForm.addEventListener('submit', function(e) {
        const facultyId = document.getElementById('import_faculty_id').value;
        const departmentId = document.getElementById('import_department_id').value;
        const programId = document.getElementById('import_program_id').value;
        const level = document.getElementById('import_level').value;
        const fileInput = this.querySelector('input[name="csv_file"]');
        
        if (!facultyId) {
            e.preventDefault();
            alert('Please select a faculty.');
            return false;
        }
        
        if (!departmentId) {
            e.preventDefault();
            alert('Please select a department.');
            return false;
        }
        
        if (!programId) {
            e.preventDefault();
            alert('Please select a program.');
            return false;
        }
        
        if (!level) {
            e.preventDefault();
            alert('Please select a level.');
            return false;
        }
        
        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please select a CSV file to upload.');
            return false;
        }
        
        if (!confirm('Are you sure you want to import courses? All courses will be assigned to the selected Faculty, Department, Program, and Level. This action may take several minutes.')) {
            e.preventDefault();
            return false;
        }
        
        // Show loading
        const submitBtn = this.querySelector('button[name="import_courses"]');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
            submitBtn.disabled = true;
        }
    });
}
</script>

<?php
require_once 'includes/footer.php';
?>