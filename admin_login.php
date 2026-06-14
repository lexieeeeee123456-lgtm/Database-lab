<?php
session_start();

// Database configuration
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "bonavion";

// If already logged in, go to main page
if (isset($_SESSION['airline_admin_id'])) {
    header("Location: maindesk.php");
    exit();
}

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $dbusername, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = trim($_POST['airline_admin_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($admin_id) || empty($password)) {
        $error = "Please fill in all required fields";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT airline_admin_id, passcode, IATA_airline_code
                FROM airline_administrator  
                WHERE airline_admin_id = :admin_id
                LIMIT 1
            ");
            $stmt->execute([':admin_id' => $admin_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin || $password !== $admin['passcode']) {
                $error = "Invalid admin ID or password";
            } else {
                $_SESSION['airline_admin_id'] = $admin['airline_admin_id'];
                $_SESSION['IATA_airline_code'] = $admin['IATA_airline_code'];
                header("Location: maindesk.php");
                exit();
            }
        } catch (Exception $e) {
            $error = "Server error. Please try again later.";
            error_log("Admin login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion Airline Administration</title>
    <link rel="stylesheet" href="common_style.css">
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
    </style>
</head>
<body>
    <div class="sticky-header">
        <div class="header-content">
            <div class="logo-container">
                <div class="airline-logo">BA</div>
                <h1 class="heading">Bon Avion Airline Management</h1>
            </div>            
            <a href="login.php" class="btn-back">Customer Pages ↩</a>                     
        </div>  
    </div>
    
    <div class="login-section">
        <div class="login-container">
            <div class="login-image">
                <h2>Welcome!</h2>
                <p>Internal Use Only!</p>
                <p>Sign in to manage/monitor<BR>your flights and aircrafts.</p>
            </div>
            <div class="login-form-container">
                <div class="login-header">
                    <h1>Sign In to Your Airline Account</h1>                    
                </div>

                <?php if (!empty($error)): ?>
                    <div class="error-alert"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form class="login-form" method="POST" action="admin_login.php">
                    <div class="form-group">
                        <label for="account">Airline Account</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="text" name="airline_admin_id" 
                                   placeholder="Enter your admin ID" 
                                   value="<?php echo isset($_POST['airline_admin_id']) ? htmlspecialchars($_POST['airline_admin_id']) : ''; ?>"
                                   required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" 
                                   placeholder="Enter your password" 
                                   required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login-form">
                        Sign In <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>