<?php
session_start();

// Database configuration
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "bonavion";

// If already logged in, go to homepage
if (isset($_SESSION['customer_id'])) {
    header("Location: index.php");
    exit();
}

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $dbusername, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$error = "";
$success = "";


if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success = "Registration successful! Please login with your credentials.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($phone) || empty($password)) {
        $error = "Please fill in all required fields";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT customer_id, first_name, last_name, phone_number, passcode
                FROM customer
                WHERE phone_number = :phone
                LIMIT 1
            ");
            $stmt->execute([':phone' => $phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            
            if (!$customer || $password !== $customer['passcode']) {
                $error = "Invalid phone number or password";
            } else {
                // Login success → Set session
                $_SESSION['customer_id'] = $customer['customer_id'];
                $_SESSION['customer_phone'] = $customer['phone_number'];
                $_SESSION['customer_name'] = $customer['first_name'] . " " . $customer['last_name'];
                $_SESSION['first_name'] = $customer['first_name'];
                $_SESSION['last_name'] = $customer['last_name'];

                header("Location: index.php");
                exit();
            }

        } catch (Exception $e) {
            $error = "Server error. Please try again later.";
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Customer Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="css/login.css">
    <style>
        .error-alert {
            background-color: #fee2e2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
        }
        .success-alert {
            background-color: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="auth-page">
        <div class="auth-card">
            <div class="card-left">
                <div class="overlay">
                    <h1>Bon <span>Avion</span></h1>
                    <p>Your journey begins here</p>
                </div>
            </div>
            <div class="card-right">
                <div class="form-header">
                    <h2>Welcome Back, Traveler</h2>
                    <p>Sign in to access your customer account</p>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="success-alert"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="error-alert"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="input-group">
                        <label>Phone Number</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-phone"></i>
                            <input type="text" name="phone" 
                                   placeholder="Enter your registered phone number" 
                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                   required>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" 
                                   placeholder="Enter your account password" 
                                   maxlength="16"
                                   required>
                        </div>
                    </div>
                    <div class="options">
                        <label class="remember">
                            <input type="checkbox" name="remember"> 
                            Remember My Details
                        </label>
                        
                    </div>
                    <button type="submit" class="submit-btn">
                        Sign In <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <div class="footer">
                    <p>Don't have a customer account? <a href="register.php">Create Account</a></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
