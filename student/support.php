<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'student_portal_db';

// Initialize variables
$student_data = [];
$academic_advisor = [];
$support_requests = [];
$faq_categories = [];
$support_contacts = [];

// Support request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $category = $_POST['category'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $message = $_POST['message'] ?? '';
    $priority = $_POST['priority'] ?? 'Medium';
    
    // In a real application, you would save this to the database
    // For demo purposes, we'll just show a success message
    $request_success = true;
}

try {
    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Get student ID from session
    $student_id = $_SESSION['student_id'];
    
    // Fetch student information
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $student_data = $result->fetch_assoc();
        $stmt->close();
        
        // Get academic advisor information
        $advisor_sql = "SELECT aa.* 
                       FROM student_advisors sa
                       JOIN academic_advisors aa ON sa.advisor_id = aa.advisor_id
                       WHERE sa.student_id = ? AND sa.status = 'Active'
                       LIMIT 1";
        $advisor_stmt = $conn->prepare($advisor_sql);
        $advisor_stmt->bind_param("i", $student_id);
        $advisor_stmt->execute();
        $advisor_result = $advisor_stmt->get_result();
        
        if ($advisor_result->num_rows > 0) {
            $academic_advisor = $advisor_result->fetch_assoc();
        }
        $advisor_stmt->close();
        
    } else {
        throw new Exception("Student record not found.");
    }
    
    $conn->close();
    
} catch (Exception $e) {
    // Fallback data for demo
    $student_data = [
        'first_name' => $_SESSION['student_name'] ?? 'Student',
        'last_name' => 'User',
        'matric_number' => $_SESSION['matric_number'] ?? 'CSC/2023/001',
        'current_level' => 300,
        'department_name' => 'Computer Science',
        'program_name' => 'B.Sc. Computer Science'
    ];
    
    $academic_advisor = [
        'first_name' => 'Prof. Ahmed',
        'last_name' => 'Musa',
        'email' => 'ahmed.musa@university.edu',
        'phone' => '08100000001',
        'staff_id' => 'STAFF001'
    ];
}

// FAQ Categories and Questions
$faq_categories = [
    'Academic' => [
        [
            'question' => 'How do I register for courses?',
            'answer' => 'Course registration is done through the student portal during the registration period. Navigate to "Course Registration" section and follow the prompts. Make sure to complete registration before the deadline to avoid late penalties.'
        ],
        [
            'question' => 'What should I do if I miss an examination?',
            'answer' => 'If you miss an examination due to valid reasons (illness, emergency), you must apply for a deferment within 48 hours. Contact your course lecturer and submit a formal application through your department.'
        ],
        [
            'question' => 'How can I request a transcript?',
            'answer' => 'Transcript requests can be made through the "Transcript Request" section in your portal. Processing takes 5-7 working days. You can choose pickup, mail, or electronic delivery.'
        ]
    ],
    'Financial' => [
        [
            'question' => 'What payment methods are accepted?',
            'answer' => 'We accept bank transfers, online payments, cash payments at the bursary, and card payments. Online payment is recommended for faster processing.'
        ],
        [
            'question' => 'Is there a payment plan option?',
            'answer' => 'Yes, payment plans are available for students facing financial difficulties. Contact the Bursary Department to discuss available options and eligibility criteria.'
        ],
        [
            'question' => 'How do I get a receipt for my payment?',
            'answer' => 'Payment receipts are automatically generated and available in the "Financial Records" section of your portal. You can download and print them at any time.'
        ]
    ],
    'Technical' => [
        [
            'question' => 'I forgot my portal password, what should I do?',
            'answer' => 'Use the "Forgot Password" link on the login page. You will receive reset instructions via your registered email. If you don\'t receive it, contact ICT Support at ictsupport@university.edu.'
        ],
        [
            'question' => 'The portal is not loading properly, what can I do?',
            'answer' => 'First, try clearing your browser cache and cookies. If the problem persists, try using a different browser or device. You can also contact ICT Support for assistance.'
        ],
        [
            'question' => 'How do I update my contact information?',
            'answer' => 'You can update your contact information in the "Profile Settings" section. Some changes may require verification from the Registry Department.'
        ]
    ],
    'Hostel & Accommodation' => [
        [
            'question' => 'How do I apply for hostel accommodation?',
            'answer' => 'Hostel applications are done through the "Hostel Management" section during the application period. Allocation is based on availability and merit.'
        ],
        [
            'question' => 'What should I do for hostel maintenance issues?',
            'answer' => 'Report maintenance issues through the "Maintenance Request" section in the Hostel Management module. For emergencies, contact the hostel warden directly.'
        ],
        [
            'question' => 'Can I change my hostel room?',
            'answer' => 'Room changes are possible based on availability. Submit a formal request through the Hostel Management section. Approval is subject to availability and administrative approval.'
        ]
    ]
];

// Support Contacts
$support_contacts = [
    [
        'department' => 'Registry Department',
        'contact_person' => 'Mr. James Okoro',
        'email' => 'registry@university.edu',
        'phone' => '08120000001',
        'office' => 'Administration Block, Room 101',
        'hours' => 'Mon-Fri: 8:00 AM - 4:00 PM',
        'services' => 'Student Records, Transcripts, Certificates'
    ],
    [
        'department' => 'Bursary Department',
        'contact_person' => 'Mrs. Grace Williams',
        'email' => 'bursary@university.edu',
        'phone' => '08120000002',
        'office' => 'Administration Block, Room 201',
        'hours' => 'Mon-Fri: 9:00 AM - 3:00 PM',
        'services' => 'Fee Payments, Financial Clearance, Receipts'
    ],
    [
        'department' => 'ICT Support',
        'contact_person' => 'Engr. David Okafor',
        'email' => 'ictsupport@university.edu',
        'phone' => '08120000003',
        'office' => 'ICT Building, Room 305',
        'hours' => '24/7 Helpdesk',
        'services' => 'Portal Issues, Technical Support, Password Reset'
    ],
    [
        'department' => 'Student Affairs',
        'contact_person' => 'Dr. Sarah Johnson',
        'email' => 'studentaffairs@university.edu',
        'phone' => '08120000004',
        'office' => 'Student Center, Room 102',
        'hours' => 'Mon-Fri: 8:30 AM - 5:00 PM',
        'services' => 'Student Welfare, Complaints, Counseling'
    ],
    [
        'department' => 'Health Services',
        'contact_person' => 'Dr. Michael Adebayo',
        'email' => 'health@university.edu',
        'phone' => '08120000005',
        'office' => 'University Health Center',
        'hours' => '24/7 Emergency Services',
        'services' => 'Medical Care, Health Consultations, Emergency'
    ],
    [
        'department' => 'Library Services',
        'contact_person' => 'Mrs. Elizabeth Nwosu',
        'email' => 'library@university.edu',
        'phone' => '08120000006',
        'office' => 'Main Library, Ground Floor',
        'hours' => 'Mon-Sat: 8:00 AM - 10:00 PM',
        'services' => 'Research Support, Book Loans, E-Resources'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Support | Al-Qalam University</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/suppot.css">
    
</head>
<body>
     <?php include 'includes/preloader.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Student Support</h1>
                <p>Get help and support for academic, technical, and other issues</p>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="academic-calendar.php" class="btn btn-secondary">
                    <i class="fas fa-calendar"></i> Calendar
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Contacts
                </button>
            </div>
        </div>

        <!-- Student Info Banner -->
        <div class="student-banner">
            <div class="student-info-grid">
                <div class="info-item">
                    <div class="info-label">Student ID</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['matric_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars(($student_data['first_name'] ?? '') . ' ' . ($student_data['last_name'] ?? '')); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Program</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['program_name'] ?? 'Not Available'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Current Level</div>
                    <div class="info-value">Level <?php echo htmlspecialchars($student_data['current_level'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Quick Support Options -->
        <div class="support-grid">
            <div class="support-card academic">
                <div class="support-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h3 class="support-title">Academic Support</h3>
                <p class="support-description">
                    Course registration, examination issues, transcript requests, 
                    academic advising, and other academic-related concerns.
                </p>
                <div class="support-actions">
                    <button class="btn btn-primary" onclick="scrollToSection('faq-academic')">
                        <i class="fas fa-question-circle"></i> FAQ
                    </button>
                    <a href="#support-form" class="btn btn-secondary">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </div>
            </div>
            
            <div class="support-card financial">
                <div class="support-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <h3 class="support-title">Financial Support</h3>
                <p class="support-description">
                    Fee payments, payment plans, receipts, financial clearance, 
                    scholarships, and other financial matters.
                </p>
                <div class="support-actions">
                    <button class="btn btn-success" onclick="scrollToSection('faq-financial')">
                        <i class="fas fa-question-circle"></i> FAQ
                    </button>
                    <a href="#support-form" class="btn btn-secondary">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </div>
            </div>
            
            <div class="support-card technical">
                <div class="support-icon">
                    <i class="fas fa-laptop-code"></i>
                </div>
                <h3 class="support-title">Technical Support</h3>
                <p class="support-description">
                    Portal access issues, password reset, technical difficulties, 
                    system errors, and other technical problems.
                </p>
                <div class="support-actions">
                    <button class="btn btn-warning" onclick="scrollToSection('faq-technical')">
                        <i class="fas fa-question-circle"></i> FAQ
                    </button>
                    <a href="#support-form" class="btn btn-secondary">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </div>
            </div>
            
            <div class="support-card hostel">
                <div class="support-icon">
                    <i class="fas fa-home"></i>
                </div>
                <h3 class="support-title">Hostel & Accommodation</h3>
                <p class="support-description">
                    Hostel allocation, maintenance issues, room changes, 
                    accommodation policies, and hostel-related concerns.
                </p>
                <div class="support-actions">
                    <button class="btn btn-danger" onclick="scrollToSection('faq-hostel')">
                        <i class="fas fa-question-circle"></i> FAQ
                    </button>
                    <a href="#support-form" class="btn btn-secondary">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </div>
            </div>
            
            <div class="support-card emergency">
                <div class="support-icon">
                    <i class="fas fa-phone-alt"></i>
                </div>
                <h3 class="support-title">Emergency Support</h3>
                <p class="support-description">
                    For urgent matters requiring immediate attention. 
                    Available 24/7 for emergencies and critical situations.
                </p>
                <div class="support-actions">
                    <a href="tel:08120000005" class="btn btn-danger">
                        <i class="fas fa-phone"></i> Call Health Center
                    </a>
                    <a href="tel:08120000004" class="btn btn-warning">
                        <i class="fas fa-phone"></i> Student Affairs
                    </a>
                </div>
            </div>
        </div>

        <!-- Academic Advisor -->
        <?php if (!empty($academic_advisor)): ?>
        <div class="advisor-card">
            <div class="advisor-header">
                <div class="advisor-avatar">
                    <?php echo substr($academic_advisor['first_name'], 0, 1) . substr($academic_advisor['last_name'], 0, 1); ?>
                </div>
                <div class="advisor-info">
                    <h3>Your Academic Advisor</h3>
                    <p>Assigned to guide you through your academic journey</p>
                </div>
            </div>
            
            <div class="advisor-details">
                <div class="advisor-detail-item">
                    <i class="fas fa-user"></i>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($academic_advisor['first_name'] . ' ' . $academic_advisor['last_name']); ?></div>
                        <div style="font-size: 13px; color: var(--gray);">Academic Advisor</div>
                    </div>
                </div>
                
                <div class="advisor-detail-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <div style="font-weight: 600;">Email</div>
                        <div style="font-size: 13px; color: var(--primary);">
                            <a href="mailto:<?php echo htmlspecialchars($academic_advisor['email']); ?>" style="color: inherit; text-decoration: none;">
                                <?php echo htmlspecialchars($academic_advisor['email']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="advisor-detail-item">
                    <i class="fas fa-phone"></i>
                    <div>
                        <div style="font-weight: 600;">Phone</div>
                        <div style="font-size: 13px; color: var(--primary);">
                            <a href="tel:<?php echo htmlspecialchars($academic_advisor['phone']); ?>" style="color: inherit; text-decoration: none;">
                                <?php echo htmlspecialchars($academic_advisor['phone']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="advisor-detail-item">
                    <i class="fas fa-id-badge"></i>
                    <div>
                        <div style="font-weight: 600;">Staff ID</div>
                        <div style="font-size: 13px; color: var(--gray);"><?php echo htmlspecialchars($academic_advisor['staff_id']); ?></div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--light-gray);">
                <p style="font-size: 14px; color: var(--gray);">
                    <i class="fas fa-info-circle"></i> Your academic advisor is your primary point of contact for:
                    course selection, academic planning, performance concerns, and guidance on university policies.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- FAQ Section -->
        <div class="faq-section">
            <div class="section-header">
                <h2>Frequently Asked Questions</h2>
                <p>Find quick answers to common questions</p>
            </div>
            
            <div class="faq-categories">
                <?php foreach ($faq_categories as $category => $questions): ?>
                <button class="faq-category-btn <?php echo $category === 'Academic' ? 'active' : ''; ?>" 
                        onclick="showFAQCategory('<?php echo strtolower($category); ?>')">
                    <?php echo $category; ?>
                </button>
                <?php endforeach; ?>
            </div>
            
            <?php foreach ($faq_categories as $category => $questions): ?>
            <div class="faq-list <?php echo $category === 'Academic' ? 'active' : ''; ?>" 
                 id="faq-<?php echo strtolower($category); ?>">
                <?php foreach ($questions as $index => $faq): ?>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFAQ(this)">
                        <?php echo htmlspecialchars($faq['question']); ?>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <?php echo htmlspecialchars($faq['answer']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Support Request Form -->
        <div class="support-form-section" id="support-form">
            <div class="section-header">
                <h2>Submit Support Request</h2>
                <p>Send us your questions, concerns, or issues</p>
            </div>
            
            <?php if (isset($request_success) && $request_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Request Submitted Successfully!</strong>
                    <p>Your support request has been received. We'll contact you within 24-48 hours.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <div class="select-wrapper">
                        <select name="category" class="form-select" required>
                            <option value="">-- Select Category --</option>
                            <option value="academic">Academic Issues</option>
                            <option value="financial">Financial Matters</option>
                            <option value="technical">Technical Support</option>
                            <option value="hostel">Hostel & Accommodation</option>
                            <option value="other">Other Issues</option>
                        </select>
                        <div class="select-icon">
                            <i class="fas fa-caret-down"></i>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" 
                           placeholder="Brief description of your issue" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-badges">
                        <label class="priority-badge priority-low selected" onclick="selectPriority(this, 'low')">
                            <input type="radio" name="priority" value="Low" checked>
                            <i class="fas fa-info-circle"></i> Low Priority
                        </label>
                        <label class="priority-badge priority-medium" onclick="selectPriority(this, 'medium')">
                            <input type="radio" name="priority" value="Medium">
                            <i class="fas fa-exclamation-circle"></i> Medium Priority
                        </label>
                        <label class="priority-badge priority-high" onclick="selectPriority(this, 'high')">
                            <input type="radio" name="priority" value="High">
                            <i class="fas fa-exclamation-triangle"></i> High Priority
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Detailed Description</label>
                    <textarea name="message" class="form-control" 
                              placeholder="Please provide detailed information about your issue..." 
                              required></textarea>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="submit" name="submit_request" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>

        <!-- University Contacts -->
        <div class="faq-section">
            <div class="section-header">
                <h2>University Departments & Contacts</h2>
                <p>Contact information for various university departments</p>
            </div>
            
            <div class="contact-grid">
                <?php foreach ($support_contacts as $contact): ?>
                <div class="contact-card">
                    <div class="contact-card-header">
                        <div class="contact-icon">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="contact-title">
                            <h4><?php echo htmlspecialchars($contact['department']); ?></h4>
                            <p><?php echo htmlspecialchars($contact['services']); ?></p>
                        </div>
                    </div>
                    
                    <div class="contact-details">
                        <div class="contact-detail">
                            <i class="fas fa-user"></i>
                            <span><?php echo htmlspecialchars($contact['contact_person']); ?></span>
                        </div>
                        <div class="contact-detail">
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:<?php echo htmlspecialchars($contact['email']); ?>" 
                               style="color: inherit; text-decoration: none;">
                                <?php echo htmlspecialchars($contact['email']); ?>
                            </a>
                        </div>
                        <div class="contact-detail">
                            <i class="fas fa-phone"></i>
                            <a href="tel:<?php echo htmlspecialchars($contact['phone']); ?>" 
                               style="color: inherit; text-decoration: none;">
                                <?php echo htmlspecialchars($contact['phone']); ?>
                            </a>
                        </div>
                        <div class="contact-detail">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo htmlspecialchars($contact['office']); ?></span>
                        </div>
                        <div class="contact-hours">
                            <i class="far fa-clock"></i> <?php echo htmlspecialchars($contact['hours']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
            <a href="academic-calendar.php" class="btn btn-secondary">
                <i class="fas fa-calendar"></i> Academic Calendar
            </a>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Contacts
            </button>
            <a href="results.php" class="btn btn-secondary">
                <i class="fas fa-chart-line"></i> View Results
            </a>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize FAQ functionality
        const firstQuestion = document.querySelector('.faq-question');
        if (firstQuestion) {
            const firstAnswer = firstQuestion.nextElementSibling;
            firstAnswer.classList.add('show');
            firstQuestion.querySelector('i').className = 'fas fa-chevron-up';
        }
    });
    
    function showFAQCategory(category) {
        // Hide all FAQ lists
        document.querySelectorAll('.faq-list').forEach(list => {
            list.classList.remove('active');
        });
        
        // Remove active class from all category buttons
        document.querySelectorAll('.faq-category-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected FAQ list
        document.getElementById('faq-' + category).classList.add('active');
        
        // Add active class to clicked button
        event.target.classList.add('active');
    }
    
    function toggleFAQ(questionElement) {
        const answer = questionElement.nextElementSibling;
        const icon = questionElement.querySelector('i');
        
        if (answer.classList.contains('show')) {
            answer.classList.remove('show');
            icon.className = 'fas fa-chevron-down';
        } else {
            answer.classList.add('show');
            icon.className = 'fas fa-chevron-up';
        }
    }
    
    function selectPriority(element, level) {
        // Remove selected class from all badges
        document.querySelectorAll('.priority-badge').forEach(badge => {
            badge.classList.remove('selected');
        });
        
        // Add selected class to clicked badge
        element.classList.add('selected');
        
        // Check the radio button
        const radio = element.querySelector('input[type="radio"]');
        radio.checked = true;
    }
    
    function scrollToSection(sectionId) {
        document.getElementById(sectionId).scrollIntoView({
            behavior: 'smooth'
        });
    }
    
    // Form validation
    const supportForm = document.querySelector('form');
    if (supportForm) {
        supportForm.addEventListener('submit', function(e) {
            const subject = this.querySelector('input[name="subject"]').value;
            const message = this.querySelector('textarea[name="message"]').value;
            
            if (subject.length < 10) {
                e.preventDefault();
                alert('Please provide a more descriptive subject (minimum 10 characters).');
                return;
            }
            
            if (message.length < 20) {
                e.preventDefault();
                alert('Please provide more details in your message (minimum 20 characters).');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            submitBtn.disabled = true;
            
            // In a real application, this would be an AJAX call
            // For demo, we'll simulate a delay
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 2000);
        });
    }
    
    // Print optimization
    window.addEventListener('beforeprint', function() {
        // Hide unnecessary elements when printing
        document.querySelectorAll('.header-right, .support-form-section, .btn, .priority-badges, .support-actions').forEach(el => {
            el.style.display = 'none';
        });
    });
    
    window.addEventListener('afterprint', function() {
        // Restore elements after printing
        document.querySelectorAll('.header-right, .support-form-section, .btn, .priority-badges, .support-actions').forEach(el => {
            el.style.display = '';
        });
    });
    
    // Add click handlers for FAQ category buttons
    document.querySelectorAll('.faq-category-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const category = this.textContent.toLowerCase();
            showFAQCategory(category);
        });
    });
    
    // Add click handlers for FAQ questions
    document.querySelectorAll('.faq-question').forEach(question => {
        question.addEventListener('click', function() {
            toggleFAQ(this);
        });
    });
    </script>
</body>
</html>