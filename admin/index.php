<?php
require_once 'includes/database.php';
session_start();

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit();
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    try {
        // Check if account is locked
        $checkLock = $pdo->prepare("
            SELECT * FROM admin_users 
            WHERE (username = ? OR email = ?) 
            AND status = 'Active' 
            AND (locked_until IS NULL OR locked_until < NOW())
        ");
        $checkLock->execute([$username, $username]);
        $admin = $checkLock->fetch();
        
        if ($admin) {
            // Verify password
            if (password_verify($password, $admin['password_hash'])) {
                // Reset failed attempts
                $resetStmt = $pdo->prepare("UPDATE admin_users SET failed_attempts = 0 WHERE admin_id = ?");
                $resetStmt->execute([$admin['admin_id']]);
                
                // Set session
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['department_id'] = $admin['department_id'];
                
                // Update last login
                $updateStmt = $pdo->prepare("
                    UPDATE admin_users 
                    SET last_login = NOW(), last_ip = ?
                    WHERE admin_id = ?
                ");
                $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $admin['admin_id']]);
                
                // Log the login
                $logStmt = $pdo->prepare("
                    INSERT INTO admin_logs 
                    (admin_id, action, description, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $logStmt->execute([
                    $admin['admin_id'],
                    'Login',
                    'Successful login',
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Redirect to dashboard
                header('Location: dashboard.php');
                exit();
                
            } else {
                // Increment failed attempts
                $incrementStmt = $pdo->prepare("
                    UPDATE admin_users 
                    SET failed_attempts = failed_attempts + 1 
                    WHERE admin_id = ?
                ");
                $incrementStmt->execute([$admin['admin_id']]);
                
                // Lock account after 5 failed attempts
                if ($admin['failed_attempts'] + 1 >= 5) {
                    $lockStmt = $pdo->prepare("
                        UPDATE admin_users 
                        SET locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                        WHERE admin_id = ?
                    ");
                    $lockStmt->execute([$admin['admin_id']]);
                    $error = 'Account locked due to too many failed attempts. Try again in 30 minutes.';
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        } else {
            $error = 'Invalid username or password, or account is locked.';
        }
        
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        $error = 'System error. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Student Portal System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <?php include 'includes/preloader.php'; ?>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <div class="logo-container">
                     <img src="../assets/images/logo.jpeg" width="100px" style="border-radius: 50%; border: 2px solid var(--primary);" alt=""> 
                </div> 
                <p class="mb-0">Administrator Login</p>
            </div>
            
            <!-- Body -->
            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert alert-danger mb-4 shake" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <div class="mb-4">
                        <label for="username" class="form-label fw-bold">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   value="<?php echo htmlspecialchars($username); ?>"
                                   placeholder="Enter username or email"
                                   required
                                   autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label for="password" class="form-label fw-bold">Password</label>
                            <a href="#" class="forgot-password small" onclick="togglePassword()">
                                <i class="fas fa-eye me-1"></i>Show Password
                            </a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter password"
                                   required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">
                                Remember me for 30 days
                            </label>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-login">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                    </div>
                </form>
                
                <div class="system-info">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-shield-alt me-2 text-success"></i>
                        <div>
                            <strong>Secure Login</strong>
                            <p class="mb-0 small">Your session will expire after 30 minutes of inactivity.</p>
                        </div>
                    </div>
                </div>
                
                <div class="footer-links">
                    <a href="#"><i class="fas fa-question-circle me-1"></i>Help</a>
                    <a href="#"><i class="fas fa-lock me-1"></i>Privacy Policy</a>
                    <a href="#"><i class="fas fa-file-contract me-1"></i>Terms</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="js/login.js"></script>
</body>
</html>