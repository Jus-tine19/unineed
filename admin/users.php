<?php

require_once '../config/database.php';
requireAdmin();

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $email = clean($_POST['email']);
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    $student_id = clean($_POST['student_id']);
    $course = isset($_POST['course']) ? clean($_POST['course']) : '';
    $year_level = isset($_POST['year_level']) ? clean($_POST['year_level']) : '';
    $section = isset($_POST['section']) ? clean($_POST['section']) : ''; 
// Default password    
    $password = password_hash('@Student01', PASSWORD_DEFAULT);
    
    $check_query = "SELECT * FROM users WHERE email = '$email'";
    $check_result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error = "Email already exists!";
    } else {
        $query = "INSERT INTO users (email, password, user_type, full_name, phone, student_id, course, year_level, section) 
                 VALUES ('$email', '$password', 'student', '$full_name', '$phone', '$student_id', '$course', '$year_level', '$section')";
        if (mysqli_query($conn, $query)) {
            $success = "Student added successfully! Default password: @Student01";
        } else {
            $error = "Failed to add student.";
        }
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $user_id = clean($_POST['user_id']);
    $email = clean($_POST['email']);
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    $student_id = clean($_POST['student_id']);
    $course = isset($_POST['course']) ? clean($_POST['course']) : '';
    $year_level = isset($_POST['year_level']) ? clean($_POST['year_level']) : '';
    $section = isset($_POST['section']) ? clean($_POST['section']) : ''; 

    $query = "UPDATE users SET 
             email = '$email',
             full_name = '$full_name',
             phone = '$phone',
             student_id = '$student_id',
             course = '$course',
             year_level = '$year_level',
             section = '$section'
             WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student updated successfully!";
    } else {
        $error = "Failed to update student.";
    }
}

// Handle Bulk Upload CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    $row = 0;
    $added = 0;
    $skipped = 0;
    
    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
        $row++;
        if ($row == 1) continue; // Skip header
        
        // New CSV format: student_id, full_name, email, phone, course, year_level, section
        $student_id = isset($data[0]) ? clean($data[0]) : '';
        $full_name = isset($data[1]) ? clean($data[1]) : '';
        $email = isset($data[2]) ? clean($data[2]) : '';
        $phone = isset($data[3]) ? clean($data[3]) : '';
        $course = isset($data[4]) ? clean($data[4]) : '';
        $year_level = isset($data[5]) ? clean($data[5]) : '';
        $section = isset($data[6]) ? clean($data[6]) : '';

        $password = password_hash('@Student01', PASSWORD_DEFAULT);

        // Skip rows without email or student_id
        if (empty($email) && empty($student_id)) {
            $skipped++;
            continue;
        }

        $check_query = "SELECT * FROM users WHERE email = '$email' OR student_id = '$student_id'";
        $check_result = mysqli_query($conn, $check_query);

        if (mysqli_num_rows($check_result) == 0) {
            $query = "INSERT INTO users (email, password, user_type, full_name, phone, student_id, course, year_level, section) 
                     VALUES ('$email', '$password', 'student', '$full_name', '$phone', '$student_id', '$course', '$year_level', '$section')";
            if (mysqli_query($conn, $query)) {
                $added++;
            }
        } else {
            $skipped++;
        }
    }
    fclose($handle);
    $success = "Bulk upload completed! Added: $added, Skipped (duplicates): $skipped";
}

// Handle Archive/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_user'])) {
    $user_id = clean($_POST['user_id']);
    $query = "UPDATE users SET status = 'archived' WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student archived successfully!";
    }
}

// Handle Activate (un-archive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_user'])) {
    $user_id = clean($_POST['user_id']);
    $query = "UPDATE users SET status = 'active' WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student activated successfully!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = clean($_POST['user_id']);
    $query = "DELETE FROM users WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student deleted successfully!";
    }
}

// Get users
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : 'active';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$course_filter = isset($_GET['course_filter']) ? clean($_GET['course_filter']) : ''; 
$year_level_filter = isset($_GET['year_level_filter']) ? clean($_GET['year_level_filter']) : '';
$section_filter = isset($_GET['section_filter']) ? clean($_GET['section_filter']) : '';

// Define available courses (used in Modals and Filters)
$all_courses = [
    'Bachelor of Science in Information Systems (BSIS)',
    'Bachelor of Science in Office Management (BSOM)',
    'Bachelor of Science in Accounting Information System (BSAIS)',
    'Bachelor of Technical Vocational Teacher Education (BTVTED)',
    'Bachelor of Science in Customs Administration (BSCA)',
    'Associate in Computer Technology',
    'Diploma in Hotel and Restaurant Management Technology (DHRMT)',
    'Hotel and Restaurant Services (Bundled) HB',
    'Shielded Metal Arc Welding (SMAW)',
    'Bookkeeping',
    'Electrical Installations and Maintenance (EIM)'
];

// --- FIXED DROPDOWN VALUES AS REQUESTED (1-4 and A-J) ---
$fixed_year_levels = ['1', '2', '3', '4'];
$fixed_sections = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
// ---

$where_clauses = ["user_type = 'student'"];
if ($status_filter) {
    $where_clauses[] = "status = '$status_filter'";
}
if ($search) {
    $where_clauses[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR student_id LIKE '%$search%')";
}
if ($course_filter) {
    $where_clauses[] = "course = '$course_filter'";
}
if ($year_level_filter) {
    $where_clauses[] = "year_level = '$year_level_filter'";
}
if ($section_filter) {
    $where_clauses[] = "section = '$section_filter'";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$query = "SELECT * FROM users $where_sql ORDER BY created_at DESC";
$users = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Student Users</h2>
            <div class="ms-auto">
                <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Bulk Upload
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-2"></i>Add Student
                </button>
                <a href="users.php?status=archived" class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-archive me-2"></i>View Archived
                </a>
            </div>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="filter-bar">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Search</label>
                        <input type="text" class="form-control" name="search" placeholder="Name/Email/ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Course</label>
                        <select class="form-select" name="course_filter">
                            <option value="">All Courses</option>
                            <?php foreach ($all_courses as $course_option): ?>
                                <option value="<?php echo htmlspecialchars($course_option); ?>" <?php echo $course_filter === $course_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Year</label>
                        <select class="form-select" name="year_level_filter">
                            <option value="">All Years</option>
                            <?php foreach ($fixed_year_levels as $year_option): ?>
                                <option value="<?php echo htmlspecialchars($year_option); ?>" <?php echo $year_level_filter === $year_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Section</label>
                        <select class="form-select" name="section_filter">
                            <option value="">All Sections</option>
                            <?php foreach ($fixed_sections as $section_option): ?>
                                <option value="<?php echo htmlspecialchars($section_option); ?>" <?php echo $section_filter === $section_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                    <div class="col-md-1">
                        <a href="users.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Course</th>
                                    <th>Year Level</th>
                                    <th>Section</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($users) > 0): ?>
                                    <?php while ($user = mysqli_fetch_assoc($users)): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($user['student_id']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                            <td><?php echo htmlspecialchars($user['course']); ?></td>
                                            <td><?php echo htmlspecialchars($user['year_level']); ?></td>
                                            <td><?php echo htmlspecialchars($user['section']); ?></td>
                                            <td>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $user['user_id']; ?>" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($user['status'] === 'active'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <button type="submit" name="archive_user" class="btn btn-sm btn-warning btn-action" title="Archive">
                                                                <i class="bi bi-archive"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <button type="submit" name="activate_user" class="btn btn-sm btn-success btn-action" title="Activate" onclick="return confirm('Activate this student account?')">
                                                                <i class="bi bi-unlock"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($user['full_name']); ?>')">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-sm btn-danger btn-action" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <div class="modal fade" id="editModal<?php echo $user['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Student</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Full Name *</label>
                                                                <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Email *</label>
                                                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Phone</label>
                                                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Student ID</label>
                                                                <input type="text" class="form-control" name="student_id" value="<?php echo htmlspecialchars($user['student_id']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Course</label>
                                                                <select class="form-select course-select" name="course" required>
                                                                    <option value="">Select Course</option>
                                                                    <optgroup label="4-YEAR">
                                                                        <option <?php echo $user['course'] === 'Bachelor of Science in Information Systems (BSIS)' ? 'selected' : ''; ?>>Bachelor of Science in Information Systems (BSIS)</option>
                                                                        <option <?php echo $user['course'] === 'Bachelor of Science in Office Management (BSOM)' ? 'selected' : ''; ?>>Bachelor of Science in Office Management (BSOM)</option>
                                                                        <option <?php echo $user['course'] === 'Bachelor of Science in Accounting Information System (BSAIS)' ? 'selected' : ''; ?>>Bachelor of Science in Accounting Information System (BSAIS)</option>
                                                                        <option <?php echo $user['course'] === 'Bachelor of Technical Vocational Teacher Education (BTVTED)' ? 'selected' : ''; ?>>Bachelor of Technical Vocational Teacher Education (BTVTED)</option>
                                                                        <option <?php echo $user['course'] === 'Bachelor of Science in Customs Administration (BSCA)' ? 'selected' : ''; ?>>Bachelor of Science in Customs Administration (BSCA)</option>
                                                                    </optgroup>
                                                                    <optgroup label="2-YEAR">
                                                                        <option <?php echo $user['course'] === 'Associate in Computer Technology' ? 'selected' : ''; ?>>Associate in Computer Technology</option>
                                                                    </optgroup>
                                                                    <optgroup label="3-YEAR">
                                                                        <option <?php echo $user['course'] === 'Diploma in Hotel and Restaurant Management Technology (DHRMT)' ? 'selected' : ''; ?>>Diploma in Hotel and Restaurant Management Technology (DHRMT)</option>
                                                                    </optgroup>
                                                                    <optgroup label="1-YEAR">
                                                                        <option <?php echo $user['course'] === 'Hotel and Restaurant Services (Bundled) HB' ? 'selected' : ''; ?>>Hotel and Restaurant Services (Bundled) HB</option>
                                                                        <option <?php echo $user['course'] === 'Shielded Metal Arc Welding (SMAW)' ? 'selected' : ''; ?>>Shielded Metal Arc Welding (SMAW)</option>
                                                                        <option <?php echo $user['course'] === 'Bookkeeping' ? 'selected' : ''; ?>>Bookkeeping</option>
                                                                        <option <?php echo $user['course'] === 'Electrical Installations and Maintenance (EIM)' ? 'selected' : ''; ?>>Electrical Installations and Maintenance (EIM)</option>
                                                                    </optgroup>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Year Level</label>
                                                                <select class="form-select year-select" name="year_level" required>
                                                                    <option value="">Select Year Level</option>
                                                                    <?php foreach ($fixed_year_levels as $year_option): ?>
                                                                        <option value="<?php echo htmlspecialchars($year_option); ?>" <?php echo $user['year_level'] === $year_option ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($year_option); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Section</label>
                                                                <select class="form-select" name="section">
                                                                    <option value="">Select Section</option>
                                                                    <?php foreach ($fixed_sections as $section_option): ?>
                                                                        <option value="<?php echo htmlspecialchars($section_option); ?>" <?php echo $user['section'] === $section_option ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($section_option); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="edit_user" class="btn btn-primary">Update Student</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class="bi bi-people"></i>
                                                <h5>No Students Found</h5>
                                                <p>Start by adding students individually or via bulk upload.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Student</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Student ID</label>
                            <input type="text" class="form-control" name="student_id" placeholder="2024-001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course</label>
                            <select class="form-select course-select" name="course" required>
                                <option value="">Select Course</option>
                                <optgroup label="4-YEAR">
                                    <option>Bachelor of Science in Information Systems (BSIS)</option>
                                    <option>Bachelor of Science in Office Management (BSOM)</option>
                                    <option>Bachelor of Science in Accounting Information System (BSAIS)</option>
                                    <option>Bachelor of Technical Vocational Teacher Education (BTVTED)</option>
                                    <option>Bachelor of Science in Customs Administration (BSCA)</option>
                                </optgroup>
                                <optgroup label="2-YEAR">
                                    <option>Associate in Computer Technology</option>
                                </optgroup>
                                <optgroup label="3-YEAR">
                                    <option>Diploma in Hotel and Restaurant Management Technology (DHRMT)</option>
                                </optgroup>
                                <optgroup label="1-YEAR">
                                    <option>Hotel and Restaurant Services (Bundled) HB</option>
                                    <option>Shielded Metal Arc Welding (SMAW)</option>
                                    <option>Bookkeeping</option>
                                    <option>Electrical Installations and Maintenance (EIM)</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Year Level</label>
                            <select class="form-select year-select" name="year_level" required>
                                <option value="">Select Year Level</option>
                                <?php foreach ($fixed_year_levels as $year_option): ?>
                                    <option value="<?php echo htmlspecialchars($year_option); ?>">
                                        <?php echo htmlspecialchars($year_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Section</label>
                            <select class="form-select" name="section">
                                <option value="">Select Section</option>
                                <?php foreach ($fixed_sections as $section_option): ?>
                                    <option value="<?php echo htmlspecialchars($section_option); ?>">
                                        <?php echo htmlspecialchars($section_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Default password will be: <strong>@Student01</strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="bulkUploadModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Upload Students (CSV)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Upload CSV File *</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                            <div class="mt-2">
                                <a href="../assets/templates/students_template.csv" class="btn btn-outline-primary btn-sm" download>
                                    <i class="bi bi-download me-1"></i>Download CSV template
                                </a>
                            </div>
                        </div>
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-exclamation-triangle me-2"></i>CSV Format Instructions:</h6>
                            <p class="mb-2">Your CSV file must have the following columns (in order):</p>
                            <ol class="mb-2">
                                <li>Student ID</li>
                                <li>Full Name</li>
                                <li>Email</li>
                                <li>Phone</li>
                                <li>Course</li>
                                <li>Year Level</li>
                                <li>Section</li>
                            </ol>
                            <p class="mb-0"><strong>Example:</strong></p>
                            <code style="display: block; background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 5px;">
                                student_id,full_name,email,phone,course,year_level,section<br>
                                2024-001,Juan Dela Cruz,juan@email.com,09123456789,"Bachelor of Science in Information Systems (BSIS)",1,A<br>
                                2024-002,Maria Santos,maria@email.com,09234567890,"Bachelor of Science in Office Management (BSOM)",2,B
                            </code>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            All students will be created with default password: <strong>@Student01</strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="bulk_upload" class="btn btn-success">
                            <i class="bi bi-upload me-2"></i>Upload & Create Accounts
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>