<?php
session_start();

require_once 'database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../student/products.php'); // Updated redirect
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email']);
    $password = $_POST['password'];
    
    $query = "SELECT * FROM users WHERE email = '$email' AND status = 'active' LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_type'] = $user['user_type'];
            
            if ($user['user_type'] === 'admin') {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: student/products.php'); // Updated redirect
            }
            exit();
        } else {
            $error = 'Invalid email or password';
        }
    } else {
        $error = 'Invalid email or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniNeeds - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-image: url('../assets/images/bpcbg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3); /* semi-transparent overlay */
            z-index: 0;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
            display: flex;
        }
        .login-left {
            background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%);
            color: white;
            padding: 60px 40px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-left h1 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .login-left p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        .login-right {
            padding: 60px 40px;
            flex: 1;
        }
        .form-control:focus {
            border-color: #61B087;
            box-shadow: 0 0 0 0.2rem rgba(97, 176, 135, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(97, 176, 135, 0.4);
        }
        .logo-wrapper {
            width: 350px;
            height: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
        }
        .logo-wrapper img {
            width: 100%;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        }
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                margin: 15px;
                max-width: 100%;
            }
            .login-left, .login-right {
                padding: 30px 20px;
            }
            .logo-wrapper {
                width: 200px;
                height: auto;
                margin-bottom: 20px;
            }
            .login-left h1 {
                font-size: 2rem;
            }
            .login-left p {
                font-size: 1rem;
            }
            body {
                align-items: flex-start;
                padding: 20px 0;
            }
        }
        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }
            .login-left, .login-right {
                padding: 20px 15px;
            }
            .logo-wrapper {
                width: 150px;
            }
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container" style="position: relative; z-index: 1;">
        <div class="login-left">
            <div class="logo-wrapper">
                <img src="/unineeds/assets/images/logo.png" alt="UniNeeds Logo" class="img-fluid" onerror="this.src='https://via.placeholder.com/350x150?text=UniNeeds'">
            </div>
            
            <p><i><b>Welcome back! </i></b> 📚 It’s time to freshen up your school gear. From quality uniforms to everyday supplies, we’ve got everything to keep you study-ready and looking your best.</p>
            <h6><i>Study ready. Style steady.</i></h6>
            <div class="mt-4">
                <small><i class="bi bi-check-circle me-2"></i>Easy ordering system</small><br>
                <small><i class="bi bi-check-circle me-2"></i>Real-time order tracking</small><br>
                <small><i class="bi bi-check-circle me-2"></i>Cash payment on pickup</small>
            </div>
        </div>
        <div class="login-right">
            <h2 class="mb-4">Welcome Back!</h2>
            <p class="text-muted mb-4">Please login to your account</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" required placeholder="Enter your email">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="bi bi-eye-slash" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
            </form>
            
        
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            
            // Toggle password visibility
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        });
    </script>
    <style>
        #togglePassword {
            border-color: #dee2e6;
        }
        #togglePassword:hover {
            background-color: #f8f9fa;
        }
        #togglePassword:focus {
            box-shadow: none;
            border-color: #61B087;
        }
    </style>
</body>
</html>