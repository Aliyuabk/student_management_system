<?php
ob_start();
require_once 'includes/header.php';
$page_title = "Staff Management";

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$employment_type = isset($_GET['employment_type']) ? $_GET['employment_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.staff_number LIKE ? OR s.designation LIKE ? OR d.department_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($employment_type !== '') {
    $conditions[] = "s.employment_type = ?";
    $params[] = $employment_type;
}

if ($status !== '') {
    $conditions[] = "s.status = ?";
    $params[] = $status;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Check if staff table exists
$table_exists = $pdo->query("SHOW TABLES LIKE 'staff'")->rowCount() > 0;

if (!$table_exists) {
    // Create staff table if it doesn't exist
    $create_table_sql = "
        CREATE TABLE IF NOT EXISTS `staff` (
            `staff_id` int(11) NOT NULL AUTO_INCREMENT,
            `staff_number` varchar(20) NOT NULL,
            `first_name` varchar(50) NOT NULL,
            `middle_name` varchar(50) DEFAULT NULL,
            `last_name` varchar(50) NOT NULL,
            `email` varchar(100) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `gender` enum('Male','Female') DEFAULT NULL,
            `date_of_birth` date DEFAULT NULL,
            `department_id` int(11) DEFAULT NULL,
            `designation` varchar(100) DEFAULT NULL,
            `employment_type` enum('Full-time','Part-time','Contract','Visiting') DEFAULT 'Full-time',
            `employment_date` date DEFAULT NULL,
            `qualification` varchar(200) DEFAULT NULL,
            `specialization` varchar(200) DEFAULT NULL,
            `office_location` varchar(100) DEFAULT NULL,
            `office_hours` varchar(100) DEFAULT NULL,
            `profile_image` varchar(255) DEFAULT NULL,
            `status` enum('Active','Inactive','On Leave','Retired','Terminated') DEFAULT 'Active',
            `notes` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`staff_id`),
            UNIQUE KEY `staff_number` (`staff_number`),
            UNIQUE KEY `email` (`email`),
            KEY `department_id` (`department_id`),
            KEY `status` (`status`),
            CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    $pdo->exec($create_table_sql);
}

// Get total records
$count_sql = "
    SELECT COUNT(*) as total 
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get staff data
$sql = "
    SELECT 
        s.*,
        d.department_name,
        d.department_code,
        TIMESTAMPDIFF(YEAR, s.employment_date, CURDATE()) as years_of_service
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    {$where_clause}
    ORDER BY s.last_name, s.first_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

// Get data for filters
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$employment_types = ['Full-time', 'Part-time', 'Contract', 'Visiting'];
$status_options = ['Active', 'Inactive', 'On Leave', 'Retired', 'Terminated'];

// Form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_staff'])) {
        $selected_ids = $_POST['selected_staff'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'Active' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) activated!";
                    break;
                    
                case 'deactivate':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'Inactive' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) deactivated!";
                    break;
                    
                case 'leave':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'On Leave' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) marked as on leave!";
                    break;
                    
                case 'delete':
                    // Check if staff are academic advisors
                    $check_sql = "SELECT staff_number, 
                                 (SELECT COUNT(*) FROM academic_advisors WHERE staff_id = s.staff_number) as advisor_count
                          FROM staff s 
                          WHERE staff_id IN ($placeholders)";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute($selected_ids);
                    $staff_check = $check_stmt->fetchAll();
                    
                    $deletable = [];
                    $non_deletable = [];
                    
                    foreach ($staff_check as $staff_member) {
                        if ($staff_member['advisor_count'] > 0) {
                            $non_deletable[] = $staff_member['staff_number'];
                        } else {
                            $deletable[] = $staff_member['staff_id'];
                        }
                    }
                    
                    if (!empty($deletable)) {
                        $deletable_placeholders = implode(',', array_fill(0, count($deletable), '?'));
                        $delete_stmt = $pdo->prepare("DELETE FROM staff WHERE staff_id IN ($deletable_placeholders)");
                        $delete_stmt->execute($deletable);
                        $deleted_count = count($deletable);
                    } else {
                        $deleted_count = 0;
                    }
                    
                    $message = "Deleted {$deleted_count} staff member(s).";
                    if (!empty($non_deletable)) {
                        $message .= " Could not delete: " . implode(', ', $non_deletable) . " (is an academic advisor)";
                    }
                    
                    $_SESSION['success_message'] = $message;
                    break;
            }
        }
        
        header("Location: manage_staffs.php");
        exit();
    }
    
    // Add new staff
    if (isset($_POST['add_staff'])) {
        $staff_number = trim($_POST['staff_number']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $gender = $_POST['gender']; 
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
        $designation = trim($_POST['designation']);
        $employment_type = $_POST['employment_type'];
        $employment_date = $_POST['employment_date'];
        $qualification = trim($_POST['qualification']);
        $specialization = trim($_POST['specialization']);
        $office_location = trim($_POST['office_location']);
        $office_hours = trim($_POST['office_hours']);
        $status = $_POST['status'];
        $notes = trim($_POST['notes']);
        
        try {
            // Check for existing staff number or email
            $check_sql = "SELECT staff_id FROM staff WHERE staff_number = ? OR email = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$staff_number, $email]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Staff number or Email already exists!";
            } else {
                $insert_sql = "INSERT INTO staff (
                    staff_number, first_name, last_name, email, phone, gender,
                    department_id, designation, employment_type,
                    employment_date, qualification, specialization, office_location,
                    office_hours, status, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $staff_number, $first_name, $last_name, $email, $phone, $gender,
                    $department_id, $designation, $employment_type,
                    $employment_date, $qualification, $specialization, $office_location,
                    $office_hours, $status, $notes, $admin_id
                ]);
                
                $_SESSION['success_message'] = "Staff member added successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding staff: " . $e->getMessage();
        }
        
        header("Location: manage_staffs.php");
        exit();
    }
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-chalkboard-teacher me-2"></i>Staff Management
    </h1>
    <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
        <i class="fas fa-plus-circle me-2"></i>Add Staff
    </button>
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
                           placeholder="Name, email, designation..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        <?php echo ($department_id == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Employment Type</label>
                <select class="form-select" name="employment_type">
                    <option value="">All Types</option>
                    <?php foreach ($employment_types as $type): ?>
                    <option value="<?php echo $type; ?>"
                        <?php echo ($employment_type == $type) ? 'selected' : ''; ?>>
                        <?php echo $type; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $opt): ?>
                    <option value="<?php echo $opt; ?>"
                        <?php echo ($status == $opt) ? 'selected' : ''; ?>>
                        <?php echo $opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-12">
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="manage_staffs.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
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
                <div class="stats-type">Total Staff</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-users text-primary"></i> All Staff
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Staff</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT COUNT(*) FROM staff WHERE status = 'Active'");
                    $active_count = $stmt->fetchColumn();
                    echo number_format($active_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently Active
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Full-time</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT COUNT(*) FROM staff WHERE employment_type = 'Full-time' AND status = 'Active'");
                    $fulltime_count = $stmt->fetchColumn();
                    echo number_format($fulltime_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-user-tie"></i> Full-time Staff
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Avg Experience</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(YEAR, employment_date, CURDATE())) FROM staff WHERE status = 'Active'");
                    $avg_experience = $stmt->fetchColumn();
                    echo $avg_experience ? number_format($avg_experience, 1) : '0.0';
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-calendar-alt"></i> Years Average
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Staff Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Staff Directory</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> staff members
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_staff.php?format=excel<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_staff.php?format=pdf<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_staff.php?format=csv<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <!-- Bulk Actions Form -->
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
                            <th class="cell">Staff Member</th>
                            <th class="cell">Department</th>
                            <th class="cell">Employment Details</th>
                            <th class="cell">Contact</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($staff)): ?>
                            <?php foreach ($staff as $member): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_staff[]" 
                                               value="<?php echo $member['staff_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($member['staff_number']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($member['designation']); ?>
                                    </div>
                                    <?php if ($member['years_of_service']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-calendar-alt me-1"></i><?php echo $member['years_of_service']; ?> years
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php if ($member['department_name']): ?>
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($member['department_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($member['department_code']); ?></div>
                                    <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <span class="badge bg-info">
                                            <?php echo $member['employment_type']; ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted">
                                        <?php if ($member['employment_date']): ?>
                                        <i class="fas fa-calendar-day me-1"></i>
                                        <?php echo date('M d, Y', strtotime($member['employment_date'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($member['office_location']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($member['office_location']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($member['email']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?php if ($member['phone']): ?>
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($member['phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($member['office_hours']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($member['office_hours']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Active' => 'success',
                                        'Inactive' => 'secondary',
                                        'On Leave' => 'warning',
                                        'Retired' => 'info',
                                        'Terminated' => 'danger'
                                    ][$member['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $member['status']; ?>
                                    </span>
                                    <?php if ($member['gender']): ?>
                                    <div class="small mt-1">
                                        <?php 
                                        $gender_icon = $member['gender'] == 'Male' ? 'mars' : 'venus';
                                        $gender_color = $member['gender'] == 'Male' ? 'primary' : 'danger';
                                        ?>
                                        <i class="fas fa-<?php echo $gender_icon; ?> text-<?php echo $gender_color; ?>"></i>
                                        <?php echo $member['gender']; ?>
                                    </div>
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
                                                <a class="dropdown-item" href="view_staff.php?id=<?php echo $member['staff_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_staff.php?id=<?php echo $member['staff_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit Staff
                                                </a>
                                            </li>
                                            <li>
                                                <?php 
                                                // Check if staff is already an advisor
                                                $check_stmt = $pdo->prepare("SELECT advisor_id FROM academic_advisors WHERE staff_id = ?");
                                                $check_stmt->execute([$member['staff_number']]);
                                                $is_advisor = $check_stmt->fetchColumn();
                                                ?>
                                                <a class="dropdown-item <?php echo $is_advisor ? 'disabled' : ''; ?>" 
                                                   href="make_advisor.php?id=<?php echo $member['staff_id']; ?>">
                                                    <i class="fas fa-user-graduate me-2"></i>
                                                    <?php echo $is_advisor ? 'Already Advisor' : 'Make Academic Advisor'; ?>
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $member['staff_id']; ?>)">
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
                                <td colspan="7" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3"></i>
                                        <h5>No staff members found</h5>
                                        <p class="text-muted">No staff members match your search criteria.</p>
                                        <?php if ($search || $department_id || $employment_type || $status): ?>
                                        <a href="manage_staffs.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                                            <i class="fas fa-plus-circle me-1"></i>Add New Staff
                                        </button>
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
                        <li><a class="dropdown-item text-success" href="#" onclick="submitBulkAction('activate')">
                            <i class="fas fa-check me-2"></i>Activate Selected
                        </a></li>
                        <li><a class="dropdown-item text-secondary" href="#" onclick="submitBulkAction('deactivate')">
                            <i class="fas fa-times me-2"></i>Deactivate Selected
                        </a></li>
                        <li><a class="dropdown-item text-warning" href="#" onclick="submitBulkAction('leave')">
                            <i class="fas fa-umbrella-beach me-2"></i>Mark as On Leave
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
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

<!-- Add Staff Modal -->
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addStaffModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Add New Staff Member
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addStaffForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff Number *</label>
                            <input type="text" class="form-control" name="staff_number" required
                                   placeholder="e.g., STAFF001" maxlength="20">
                            <div class="form-text">Unique staff identification number</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required
                                   placeholder="e.g., staff@university.edu">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required
                                   placeholder="e.g., John">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name"
                                   placeholder="e.g., Michael">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required
                                   placeholder="e.g., Doe">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone"
                                   placeholder="e.g., 08012345678">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Designation *</label>
                            <input type="text" class="form-control" name="designation" required
                                   placeholder="e.g., Senior Lecturer">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" required>
                                <option value="">Select Type</option>
                                <option value="Full-time" selected>Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Visiting">Visiting</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Date</label>
                            <input type="date" class="form-control" name="employment_date"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                                <option value="Retired">Retired</option>
                                <option value="Terminated">Terminated</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Highest Qualification</label>
                            <input type="text" class="form-control" name="qualification"
                                   placeholder="e.g., Ph.D. in Computer Science">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Specialization</label>
                            <input type="text" class="form-control" name="specialization"
                                   placeholder="e.g., Artificial Intelligence">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Location</label>
                            <input type="text" class="form-control" name="office_location"
                                   placeholder="e.g., Faculty Building, Room 101">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Hours</label>
                            <input type="text" class="form-control" name="office_hours"
                                   placeholder="e.g., Mon-Fri, 9am-5pm">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"
                                      placeholder="Additional notes about this staff member"></textarea>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Important Notes:</h6>
                        <ul class="mb-0 small">
                            <li>Staff number and email must be unique</li>
                            <li>Staff can later be assigned as academic advisors</li>
                            <li>Profile image can be added after creation</li>
                            <li>All required fields are marked with *</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addStaffForm" name="add_staff" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Add Staff
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all-bottom').checked = this.checked;
});

document.getElementById('select-all-bottom').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one staff member.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected staff member(s)?`;
            break;
        case 'deactivate':
            confirmMessage = `Deactivate ${selectedIds.length} selected staff member(s)?`;
            break;
        case 'leave':
            confirmMessage = `Mark ${selectedIds.length} selected staff member(s) as on leave?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected staff member(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected staff IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Single staff delete
function confirmDelete(staffId) {
    if (confirm('Are you sure you want to delete this staff member? This action cannot be undone.')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_staff[]';
        input.value = staffId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Validate add staff form
document.getElementById('addStaffForm').addEventListener('submit', function(e) {
    const staffNumber = this.querySelector('input[name="staff_number"]').value.trim();
    const email = this.querySelector('input[name="email"]').value.trim();
    const firstName = this.querySelector('input[name="first_name"]').value.trim();
    const lastName = this.querySelector('input[name="last_name"]').value.trim();
    const designation = this.querySelector('input[name="designation"]').value.trim();
    const employmentType = this.querySelector('select[name="employment_type"]').value;
    const status = this.querySelector('select[name="status"]').value;
    
    if (!staffNumber || !email || !firstName || !lastName || !designation || !employmentType || !status) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return false;
    }
    
    // Staff number format validation
    if (!/^[A-Za-z0-9\-]+$/.test(staffNumber)) {
        e.preventDefault();
        alert('Staff number can only contain letters, numbers, and hyphens');
        return false;
    }
    
    // Date validation
    const dob = this.querySelector('input[name="date_of_birth"]').value;
    const empDate = this.querySelector('input[name="employment_date"]').value;
    
    if (dob && new Date(dob) > new Date()) {
        e.preventDefault();
        alert('Date of birth cannot be in the future.');
        return false;
    }
    
    if (empDate && new Date(empDate) > new Date()) {
        e.preventDefault();
        alert('Employment date cannot be in the future.');
        return false;
    }
    
    if (!confirm('Are you sure you want to add this staff member?')) {
        e.preventDefault();
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>