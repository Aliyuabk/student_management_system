<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Handle mark as read
if(isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = $_GET['mark_read'];
    $update = "UPDATE notifications SET is_read = 1, read_date = NOW() WHERE notification_id = ? AND student_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ii", $notification_id, $student_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Handle mark all as read
if(isset($_GET['mark_all_read'])) {
    $update = "UPDATE notifications SET is_read = 1, read_date = NOW() WHERE student_id = ? AND is_read = 0";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Handle delete notification
if(isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $notification_id = $_GET['delete'];
    $delete = "DELETE FROM notifications WHERE notification_id = ? AND student_id = ?";
    $stmt = $conn->prepare($delete);
    $stmt->bind_param("ii", $notification_id, $student_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Get filter parameters
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query
$query = "SELECT * FROM notifications WHERE student_id = ?";
$params = [$student_id];
$types = "i";

if($filter_type != 'all') {
    $query .= " AND notification_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if($filter_status == 'unread') {
    $query .= " AND is_read = 0";
} elseif($filter_status == 'read') {
    $query .= " AND is_read = 1";
}

$query .= " ORDER BY sent_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$notifications = $stmt->get_result();

// Get counts
$count_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN notification_type = 'Academic' THEN 1 ELSE 0 END) as academic,
                SUM(CASE WHEN notification_type = 'Financial' THEN 1 ELSE 0 END) as financial,
                SUM(CASE WHEN notification_type = 'Hostel' THEN 1 ELSE 0 END) as hostel,
                SUM(CASE WHEN notification_type = 'Urgent' THEN 1 ELSE 0 END) as urgent
                FROM notifications WHERE student_id = ?";
$stmt = $conn->prepare($count_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
?>

<div class="fade-in">
    <div class="page-header">
        <div class="header-title">
            <h1>Notifications</h1>
            <p>Stay updated with important announcements and alerts</p>
        </div>
        <div class="header-actions">
            <?php if($counts['unread'] > 0): ?>
            <a href="?mark_all_read=1" class="btn-outline" onclick="return confirm('Mark all notifications as read?')">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                Mark All as Read
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notification Stats -->
    <div class="stats-grid">
        <div class="stat-card total" onclick="filterNotifications('all', 'all')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Total</span>
                <span class="stat-value"><?php echo $counts['total'] ?: 0; ?></span>
            </div>
        </div>

        <div class="stat-card unread" onclick="filterNotifications('all', 'unread')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Unread</span>
                <span class="stat-value"><?php echo $counts['unread'] ?: 0; ?></span>
            </div>
        </div>

        <div class="stat-card urgent" onclick="filterNotifications('Urgent', 'all')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Urgent</span>
                <span class="stat-value"><?php echo $counts['urgent'] ?: 0; ?></span>
            </div>
        </div>

        <div class="stat-card academic" onclick="filterNotifications('Academic', 'all')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Academic</span>
                <span class="stat-value"><?php echo $counts['academic'] ?: 0; ?></span>
            </div>
        </div>

        <div class="stat-card financial" onclick="filterNotifications('Financial', 'all')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Financial</span>
                <span class="stat-value"><?php echo $counts['financial'] ?: 0; ?></span>
            </div>
        </div>

        <div class="stat-card hostel" onclick="filterNotifications('Hostel', 'all')">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 7V3H2v14h5v4h10v-4h5V7h-8z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-label">Hostel</span>
                <span class="stat-value"><?php echo $counts['hostel'] ?: 0; ?></span>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <div class="filter-group">
            <label>Type</label>
            <select class="filter-select" id="typeFilter" onchange="applyFilters()">
                <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="Academic" <?php echo $filter_type == 'Academic' ? 'selected' : ''; ?>>Academic</option>
                <option value="Financial" <?php echo $filter_type == 'Financial' ? 'selected' : ''; ?>>Financial</option>
                <option value="Hostel" <?php echo $filter_type == 'Hostel' ? 'selected' : ''; ?>>Hostel</option>
                <option value="General" <?php echo $filter_type == 'General' ? 'selected' : ''; ?>>General</option>
                <option value="Urgent" <?php echo $filter_type == 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Status</label>
            <select class="filter-select" id="statusFilter" onchange="applyFilters()">
                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All</option>
                <option value="unread" <?php echo $filter_status == 'unread' ? 'selected' : ''; ?>>Unread</option>
                <option value="read" <?php echo $filter_status == 'read' ? 'selected' : ''; ?>>Read</option>
            </select>
        </div>

        <button class="btn-clear" onclick="clearFilters()">
            <svg viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
            Clear Filters
        </button>
    </div>

    <!-- Notifications List -->
    <div class="notifications-container">
        <?php if($notifications->num_rows > 0): ?>
            <?php while($notification = $notifications->fetch_assoc()): 
                $is_unread = $notification['is_read'] == 0;
                $priority_class = strtolower($notification['priority'] ?: 'normal');
                $type_class = strtolower($notification['notification_type'] ?: 'general');
            ?>
            <div class="notification-card <?php echo $is_unread ? 'unread' : ''; ?> priority-<?php echo $priority_class; ?> type-<?php echo $type_class; ?>">
                <div class="notification-indicator"></div>
                
                <div class="notification-icon">
                    <?php 
                    $icon = 'general';
                    switch($notification['notification_type']) {
                        case 'Academic':
                            $icon = '<path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>';
                            break;
                        case 'Financial':
                            $icon = '<path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>';
                            break;
                        case 'Hostel':
                            $icon = '<path d="M12 7V3H2v14h5v4h10v-4h5V7h-8z"/>';
                            break;
                        case 'Urgent':
                            $icon = '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>';
                            break;
                        default:
                            $icon = '<path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>';
                    }
                    ?>
                    <svg viewBox="0 0 24 24">
                        <?php echo $icon; ?>
                    </svg>
                </div>

                <div class="notification-content">
                    <div class="notification-header">
                        <h3 class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></h3>
                        <div class="notification-meta">
                            <?php if($notification['priority'] == 'Urgent'): ?>
                                <span class="priority-badge urgent">Urgent</span>
                            <?php endif; ?>
                            <span class="notification-type <?php echo $type_class; ?>">
                                <?php echo $notification['notification_type'] ?: 'General'; ?>
                            </span>
                            <span class="notification-time">
                                <?php echo time_elapsed_string($notification['sent_date']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <p class="notification-message"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                    
                    <?php if($notification['action_url']): ?>
                    <div class="notification-action">
                        <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="btn-link">
                            View Details →
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($notification['expires_date'] && strtotime($notification['expires_date']) > time()): ?>
                    <div class="notification-expiry">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/>
                        </svg>
                        Expires: <?php echo date('d M Y', strtotime($notification['expires_date'])); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="notification-actions">
                    <?php if($is_unread): ?>
                    <a href="?mark_read=<?php echo $notification['notification_id']; ?>" class="action-btn" title="Mark as read">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                    <a href="?delete=<?php echo $notification['notification_id']; ?>" class="action-btn delete" title="Delete" onclick="return confirm('Delete this notification?')">
                        <svg viewBox="0 0 24 24">
                            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                        </svg>
                    </a>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-notifications">
                <svg viewBox="0 0 24 24">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
                <h3>No Notifications</h3>
                <p>You don't have any notifications at the moment.</p>
                <?php if($filter_type != 'all' || $filter_status != 'all'): ?>
                <button class="btn-primary" onclick="clearFilters()">Clear Filters</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 30px;
    }

    .header-title h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .header-title p {
        color: var(--text-light);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: var(--transition);
    }

    .btn-outline:hover {
        background: var(--primary-color);
        color: var(--white);
    }

    .btn-outline svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: var(--shadow);
        cursor: pointer;
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .stat-card.total {
        border-left: 4px solid var(--primary-color);
    }

    .stat-card.unread {
        border-left: 4px solid var(--warning-color);
    }

    .stat-card.urgent {
        border-left: 4px solid var(--danger-color);
    }

    .stat-card.academic {
        border-left: 4px solid #2196f3;
    }

    .stat-card.financial {
        border-left: 4px solid #4caf50;
    }

    .stat-card.hostel {
        border-left: 4px solid #ff9800;
    }

    .stat-icon {
        width: 45px;
        height: 45px;
        background: var(--primary-soft);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .stat-icon svg {
        width: 22px;
        height: 22px;
        fill: var(--primary-color);
    }

    .stat-content {
        flex: 1;
    }

    .stat-label {
        color: var(--text-light);
        font-size: 12px;
        display: block;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 22px;
        font-weight: 700;
        color: var(--text-dark);
        display: block;
    }

    /* Filters Bar */
    .filters-bar {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 30px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: var(--shadow);
    }

    .filter-group {
        flex: 1;
        min-width: 150px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 8px;
        color: var(--text-dark);
        font-size: 13px;
        font-weight: 500;
    }

    .filter-select {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--gray-300);
        border-radius: 10px;
        font-size: 14px;
        color: var(--text-dark);
        background: var(--white);
        transition: var(--transition);
    }

    .filter-select:hover {
        border-color: var(--primary-color);
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    }

    .btn-clear {
        background: transparent;
        border: 1px solid var(--gray-300);
        color: var(--text-light);
        padding: 10px 15px;
        border-radius: 10px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: var(--transition);
        height: 42px;
    }

    .btn-clear:hover {
        background: var(--gray-100);
        border-color: var(--danger-color);
        color: var(--danger-color);
    }

    .btn-clear svg {
        width: 16px;
        height: 16px;
        fill: currentColor;
    }

    /* Notifications Container */
    .notifications-container {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .notification-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        gap: 20px;
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .notification-card:hover {
        transform: translateX(5px);
        box-shadow: var(--shadow-lg);
    }

    .notification-card.unread {
        background: #f0f7f0;
        border-left: 4px solid var(--primary-color);
    }

    .notification-card.priority-urgent {
        border-left: 4px solid var(--danger-color);
    }

    .notification-card.priority-high {
        border-left: 4px solid var(--warning-color);
    }

    .notification-card.priority-normal {
        border-left: 4px solid var(--primary-color);
    }

    .notification-card.type-academic {
        background: linear-gradient(to right, rgba(33, 150, 243, 0.05), transparent);
    }

    .notification-card.type-financial {
        background: linear-gradient(to right, rgba(76, 175, 80, 0.05), transparent);
    }

    .notification-card.type-hostel {
        background: linear-gradient(to right, rgba(255, 152, 0, 0.05), transparent);
    }

    .notification-card.type-urgent {
        background: linear-gradient(to right, rgba(244, 67, 54, 0.05), transparent);
    }

    .notification-indicator {
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .notification-icon {
        flex-shrink: 0;
        width: 50px;
        height: 50px;
        background: var(--primary-soft);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .notification-icon svg {
        width: 24px;
        height: 24px;
        fill: var(--primary-color);
    }

    .notification-content {
        flex: 1;
    }

    .notification-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 10px;
    }

    .notification-title {
        color: var(--text-dark);
        font-size: 16px;
        font-weight: 600;
        margin: 0;
    }

    .notification-meta {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .priority-badge {
        background: var(--danger-color);
        color: var(--white);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .notification-type {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .notification-type.academic {
        background: #2196f3;
        color: white;
    }

    .notification-type.financial {
        background: #4caf50;
        color: white;
    }

    .notification-type.hostel {
        background: #ff9800;
        color: white;
    }

    .notification-type.urgent {
        background: #f44336;
        color: white;
    }

    .notification-type.general {
        background: var(--primary-color);
        color: white;
    }

    .notification-time {
        color: var(--text-light);
        font-size: 12px;
    }

    .notification-message {
        color: var(--text-dark);
        font-size: 14px;
        line-height: 1.6;
        margin-bottom: 10px;
    }

    .notification-action {
        margin-top: 10px;
    }

    .btn-link {
        color: var(--primary-color);
        text-decoration: none;
        font-weight: 500;
        font-size: 13px;
        transition: var(--transition);
    }

    .btn-link:hover {
        text-decoration: underline;
    }

    .notification-expiry {
        display: flex;
        align-items: center;
        gap: 5px;
        color: var(--warning-color);
        font-size: 12px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed var(--gray-200);
    }

    .notification-expiry svg {
        width: 16px;
        height: 16px;
        fill: currentColor;
    }

    .notification-actions {
        display: flex;
        gap: 5px;
        align-items: flex-start;
        opacity: 0;
        transition: var(--transition);
    }

    .notification-card:hover .notification-actions {
        opacity: 1;
    }

    .action-btn {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        transition: var(--transition);
        color: var(--text-light);
    }

    .action-btn:hover {
        background: var(--gray-100);
        color: var(--primary-color);
    }

    .action-btn.delete:hover {
        color: var(--danger-color);
    }

    .action-btn svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    /* No Notifications */
    .no-notifications {
        text-align: center;
        padding: 60px 20px;
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow);
    }

    .no-notifications svg {
        width: 80px;
        height: 80px;
        fill: var(--gray-400);
        margin-bottom: 20px;
    }

    .no-notifications h3 {
        color: var(--text-dark);
        font-size: 20px;
        margin-bottom: 10px;
    }

    .no-notifications p {
        color: var(--text-light);
        margin-bottom: 20px;
    }

    .btn-primary {
        background: var(--primary-color);
        color: var(--white);
        border: none;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(46, 125, 50, 0.2);
    }

    @media (max-width: 768px) {
        .header-title h1 {
            font-size: 24px;
        }

        .stats-grid {
            grid-template-columns: 1fr 1fr;
        }

        .filters-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            min-width: 100%;
        }

        .btn-clear {
            width: 100%;
            justify-content: center;
        }

        .notification-card {
            flex-direction: column;
        }

        .notification-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .notification-actions {
            opacity: 1;
            justify-content: flex-end;
            margin-top: 10px;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
function applyFilters() {
    const type = document.getElementById('typeFilter').value;
    const status = document.getElementById('statusFilter').value;
    window.location.href = 'notifications.php?type=' + type + '&status=' + status;
}

function clearFilters() {
    window.location.href = 'notifications.php';
}

function filterNotifications(type, status) {
    window.location.href = 'notifications.php?type=' + type + '&status=' + status;
}

// Helper function for time ago
<?php
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
</script>

<?php require_once 'includes/footer.php'; ?>