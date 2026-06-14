<?php
session_start();
require_once 'membership.php';
require_once 'avatar_helper.php';
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "bonavion";
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $dbusername, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
$stmt = $pdo->prepare("
    SELECT customer_id, first_name, last_name, phone_number, passport, identification, passcode, avatar_blob 
    FROM customer 
    WHERE customer_id = :customer_id
");
$stmt->execute([':customer_id' => $_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
$firstName = $customer['first_name'];
$lastName = $customer['last_name'];
$membership = getMembershipInfo($pdo, $_SESSION['customer_id']);
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $newFirstName = trim($_POST['first_name']);
    $newLastName = trim($_POST['last_name']);
    $currentPwd = $_POST['current_password'] ?? '';
    $newPwd = $_POST['new_password'] ?? '';
    $confirmPwd = $_POST['confirm_password'] ?? '';
    if (empty($newFirstName)) $errors[] = "First name is required";
    if (empty($newLastName)) $errors[] = "Last name is required";
    if (!empty($newPwd)) {
        if (empty($currentPwd)) $errors[] = "Current password is required";
        if ($currentPwd !== $customer['passcode']) $errors[] = "Current password is incorrect";
        if (strlen($newPwd) < 6) $errors[] = "New password must be at least 6 characters";
        if ($newPwd !== $confirmPwd) $errors[] = "Passwords do not match";
    }
    $avatarBlob = $customer['avatar_blob'];
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($_FILES['avatar']['type'], $allowed)) {
            $errors[] = "Only JPG/PNG/GIF allowed";
        } elseif ($_FILES['avatar']['size'] > 16*1024*1024) {
            $errors[] = "Avatar max size 16MB";
        } else {
            $rawData = file_get_contents($_FILES['avatar']['tmp_name']);
            // Compress avatar before storing (max 500x500, quality 80)
            $avatarBlob = compressAvatarForStorage($rawData, 500, 500, 80);
        }
    }
    if (empty($errors)) {
        try {
            if (!empty($newPwd)) {
                $sql = "UPDATE customer SET first_name=:first_name, last_name=:last_name, avatar_blob=:avatar_blob, passcode=:newPwd WHERE customer_id=:customer_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'first_name' => $newFirstName,
                    'last_name' => $newLastName,
                    'avatar_blob' => $avatarBlob,
                    'newPwd' => $newPwd,
                    'customer_id' => $_SESSION['customer_id']
                ]);
            } else {
                $sql = "UPDATE customer SET first_name=:first_name, last_name=:last_name, avatar_blob=:avatar_blob WHERE customer_id=:customer_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'first_name' => $newFirstName,
                    'last_name' => $newLastName,
                    'avatar_blob' => $avatarBlob,
                    'customer_id' => $_SESSION['customer_id']
                ]);
            }
            
            $_SESSION['customer_name'] = $newFirstName . ' ' . $newLastName;
            $_SESSION['first_name'] = $newFirstName;
            // Clear avatar cache to force refresh
            clearAvatarCache();
            $success = "Profile updated successfully!";
            
            $stmt = $pdo->prepare("SELECT * FROM customer WHERE customer_id = :customer_id");
            $stmt->execute([':customer_id' => $_SESSION['customer_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $firstName = $customer['first_name'];
            $lastName = $customer['last_name'];
        } catch(Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    } else {
        $error = implode(' | ', $errors);
    }
}
$avatarDisplay = '';
$initials = strtoupper(substr($customer['first_name'],0,1) . substr($customer['last_name'],0,1));
if (!empty($customer['avatar_blob'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($customer['avatar_blob']);
    $avatarDisplay = 'data:' . $mime . ';base64,' . base64_encode($customer['avatar_blob']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Edit Profile</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="css/main.css">
    <style>
        :root {
            --deep: #0a2463;
            --navy: #1e3c72;
            --blue: #2a5298;
            --gold: #c9a962;
            --copper: #a67c52;
            --light: #f5f7fa;
            --muted: #8a94a7;
            --text: #2d3748;
        }
        .content-container {
            max-width: 1200px;
            margin: 50px auto;
            padding: 0 20px;
            width: 100%;
        }
        
        .profile-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.05);
            padding: 40px;
            width: 100%;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--light);
        }
        
        .edit-avatar-container {
            position: relative;
            width: 80px;          
            height: 80px;         
            max-width: 80px;      
            max-height: 80px;     
            margin: 0 auto 12px;
            border-radius: 50%;
            overflow: hidden;     
            border: 3px solid #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .edit-avatar {
            width: 100%;          
            height: 100%;         
            border-radius: 50%;
            object-fit: cover;    
            background: linear-gradient(135deg, var(--gold), #b87333);
            color: #fff;
            font-size: 2rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .edit-avatar img {
            width: 100% !important;    
            height: 100% !important;   
            max-width: 80px !important;
            max-height: 80px !important;
            border-radius: 50%;
            object-fit: cover;         
            object-position: center;   
        }
        
        .edit-avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            border-radius: 50%;
            max-width: 80px;
            max-height: 80px;
        }
        
        .edit-avatar-overlay i {
            font-size: 18px;
        }
        
        .edit-avatar-container:hover .edit-avatar-overlay {
            opacity: 1;
        }
        
        .profile-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            color: var(--navy);
            margin-bottom: 8px;
        }
        
        .member-tier {
            padding: 6px 16px;
            border-radius: 18px;
            font-size: 13px;
            display: inline-block;
            margin-bottom: 10px;
            color: #fff;
        }
        .member-tier.platinum {
            background: linear-gradient(135deg, #545478, #7f7fa5);
            color: #fff;
        }
        .member-tier.gold {
            background: linear-gradient(135deg, var(--gold), #b87333);
        }
        .member-tier.silver {
            background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
            color: #333;
        }
        .member-tier.member {
             background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .total-spent {
            font-size: 13px;
            color: var(--muted);
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 18px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--light);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-input {
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 15px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #fff;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.1);
        }
        
        .form-input:disabled {
            background: var(--light);
            color: var(--muted);
            cursor: not-allowed;
        }
        
        .full-width {
            grid-column: span 2;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 18px;
            border-top: 1px solid var(--light);
        }
        
        .save-btn {
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 14px 30px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            will-change: transform;
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30,60,114,0.2);
        }
        
        .back-btn {
            background: transparent;
            color: var(--navy);
            border: 1px solid var(--navy);
            border-radius: 10px;
            padding: 14px 30px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.2s ease, transform 0.2s ease;
            will-change: transform;
        }
        
        .back-btn:hover {
            background: var(--light);
            transform: translateY(-2px);
        }
        input[type="file"] {
            display: none;
        }
        @media screen and (min-width: 1400px) {
            .content-container { max-width: 1400px; }
            .profile-card { padding: 50px 60px; }
            .form-grid { gap: 30px; }
        }
        
        @media screen and (max-width: 992px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
    <div class="top-area">
        <div class="logo"><img src="image/Logo.png" alt="Bon Avion"></div>
        <div class="auth-area">
            <div class="order-dropdown">
                <button type="button" class="order-btn">My Orders ▼</button>
                <div class="dropdown-menu">
                    <a href="myOrders.php?tab=upcoming">Upcoming</a>
                    <a href="myOrders.php?tab=completed">Completed</a>
                </div>
            </div>
            
            <div class="user-welcome">
                <a href="update_profile.php" class="nav-avatar">
                    <?php if ($avatarDisplay): ?>
                        <img src="<?php echo $avatarDisplay; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $initials; ?>
                    <?php endif; ?>
                </a>
                <span>Welcome, <?php echo htmlspecialchars($firstName); ?>!</span>
                <a href="logout.php" class="login-btn" style="margin-left: 10px;">Logout</a>
            </div>
        </div>
    </div>
    <nav class="main-nav">
        <ul class="nav-list">
            <li><a href="index.php" class="nav-link">Index</a></li>
            <li><a href="recommendation.php" class="nav-link">Recommendation</a></li>
            <li><a href="AboutUs.php" class="nav-link">About Us</a></li>
            <li><a href="profile.php" class="nav-link active">Profile</a></li>
        </ul>
    </nav>
    <div class="content-container">
        <div class="profile-card">
            <?php if ($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="profile-header">
                    <label for="avatar-upload" class="edit-avatar-container">
                        <div class="edit-avatar">
                            <?php if ($avatarDisplay): ?>
                                <img src="<?php echo $avatarDisplay; ?>" alt="Avatar">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                        <div class="edit-avatar-overlay">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </label>
                    <input type="file" id="avatar-upload" name="avatar" accept="image/jpeg,image/png,image/gif">
                    
                    <h2 class="profile-name"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></h2>
                    <div class="member-tier <?php echo strtolower($membership['membership_level']); ?>"><?php echo $membership['membership_name']; ?><?php echo ($membership['membership_level'] !== 'Member') ? ' Member' : ''; ?></div>
                    <div class="total-spent">Total Spent: $<?php echo number_format($membership['total_spending'], 2); ?></div>
                </div>
                <div class="form-section">
                    <h3 class="section-title">Personal Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['last_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Customer ID</label>
                            <input type="text" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['customer_id']); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['phone_number']); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Passport</label>
                            <input type="text" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['passport'] ?? ''); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ID Number</label>
                            <input type="text" class="form-input" 
                                   value="<?php echo htmlspecialchars($customer['identification'] ?? ''); ?>" disabled>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h3 class="section-title">Password Reset</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-input" placeholder="Required to change password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" placeholder="Leave blank to keep current">
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" placeholder="Leave blank to keep current">
                        </div>
                    </div>
                </div>
                <div class="btn-group">
                    <a href="profile.php" class="back-btn">Back</a>
                    <button type="submit" class="save-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('avatar-upload').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const avatarDiv = document.querySelector('.edit-avatar');
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.maxWidth = '80px';
                    img.style.maxHeight = '80px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '50%';
                    avatarDiv.innerHTML = '';
                    avatarDiv.appendChild(img);
                }
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    </script>
</body>
</html>