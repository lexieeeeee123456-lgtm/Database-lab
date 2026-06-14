<?php
session_start();

$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "bonavion";

$errors = [];

$conn = new mysqli($servername, $dbusername, $dbpassword, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $passcode = $_POST['passcode'] ?? '';
    $confirmPasscode = $_POST['confirm_passcode'] ?? '';

    // Phone validation
    if (empty($phoneNumber)) {
        $errors[] = "Phone number is required";
    } else {
        $stmt = $conn->prepare("SELECT customer_id FROM customer WHERE phone_number = ?");
        $stmt->bind_param("s", $phoneNumber);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Phone number is already registered";
        }
        $stmt->close();
    }

    // Password validation
    if (empty($passcode)) {
        $errors[] = "Password is required";
    } elseif (strlen($passcode) < 6) {
        $errors[] = "Password must be at least 6 characters";
    } elseif (strlen($passcode) > 16) {
        $errors[] = "Password must be at most 16 characters";
    } elseif ($passcode !== $confirmPasscode) {
        $errors[] = "Passwords do not match";
    }

    if (empty($errors)) {
        // Generate unique customer_id
        $result = $conn->query("SELECT customer_id FROM customer ORDER BY CAST(SUBSTRING(customer_id, 2) AS UNSIGNED) DESC LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newNumber = (int)substr($row['customer_id'], 1) + 1;
        } else {
            $newNumber = 1;
        }
        $customerId = "C" . str_pad($newNumber, 5, "0", STR_PAD_LEFT);

        // Default values
        $firstName = "User";
        $lastName = "";

        $stmt = $conn->prepare("INSERT INTO customer (customer_id, passcode, first_name, last_name, phone_number, passport, identification) VALUES (?, ?, ?, ?, ?, NULL, NULL)");
        $stmt->bind_param("sssss", $customerId, $passcode, $firstName, $lastName, $phoneNumber);

        if ($stmt->execute()) {
            $stmt->close();
            
            // Auto login
            $_SESSION['customer_id'] = $customerId;
            $_SESSION['first_name'] = $firstName;
            $_SESSION['customer_name'] = $firstName;
            
            $conn->close();
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "Registration failed: " . $stmt->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="css/login.css">
    <style>
        .error-messages { background: #fee2e2; color: #dc2626; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; list-style-position: inside; font-size: 14px; }
    </style>
</head>
<body>
    <div class="auth-page">
        <div class="auth-card">
            <div class="card-left">
                <div class="overlay">
                    <h1>Bon <span>Avion</span></h1>
                    <p>Start your adventure today</p>
                </div>
            </div>
            <div class="card-right">
                <div class="form-header">
                    <h2>Create Account</h2>
                    <p>Join us and explore the world</p>
                </div>
                <?php if (!empty($errors)): ?>
                    <ul class="error-messages">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="POST" action="register.php">
                    <div class="input-group">
                        <label>Phone Number</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-phone"></i>
                            <input type="text" name="phone_number" placeholder="Enter your phone" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="passcode" placeholder="Create a password" maxlength="16" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Confirm Password</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-shield"></i>
                            <input type="password" name="confirm_passcode" placeholder="Confirm your password" maxlength="16" required>
                        </div>
                    </div>
                    <button type="submit" class="submit-btn">
                        Create Account <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>
                <div class="footer">
                    <p>Already have an account? <a href="login.php">Sign In</a></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
