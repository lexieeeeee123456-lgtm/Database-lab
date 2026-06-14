<?php
session_start();
require_once 'membership.php';
require_once 'avatar_helper.php';

// Check login
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

// Database configuration
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
    SELECT customer_id, first_name, last_name, phone_number, passport, identification, avatar_blob
    FROM customer
    WHERE customer_id = :customer_id
");
$stmt->execute([':customer_id' => $_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$fullName = $customer['first_name'] . ' ' . $customer['last_name'];
$firstName = $customer['first_name'];
$membership = getMembershipInfo($pdo, $_SESSION['customer_id']);

// Use optimized avatar helper with caching and compression
$avatarData = getNavAvatar($pdo, $_SESSION['customer_id']);
$avatarDisplay = $avatarData['display'];
$initials = $avatarData['initials'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bon Avion - Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        :root {
            --deep: #0a2463;
            --navy: #1e3c72;
            --blue: #2a5298;
            --gold: #c9a962;
        }
        
        .profile-wrapper {
            min-height: calc(100vh - 120px);
            background: #f0f4f8;
        }
        .profile-banner {
            height: 180px;
            background: linear-gradient(135deg, var(--deep), var(--navy), var(--blue));
        }
        .profile-card {
            max-width: 500px;
            margin: -90px auto 40px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            overflow: hidden;
        }
        .card-top {
            text-align: center;
            padding: 25px 30px 20px;
            border-bottom: 1px solid #eee;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--gold), #b87333);
            color: #fff;
            font-size: 2.5rem;
            font-weight: 600;
            margin: 0 auto;
            overflow: hidden;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .user-name {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 15px 0 8px;
        }
        .welcome-text {
            color: var(--gold);
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .member-badge {
            display: inline-block;
            padding: 5px 16px;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
        }
        .member-badge.platinum {
            background: linear-gradient(135deg, #545478, #7f7fa5);
            color: #fff;
        }
        .member-badge.gold {
            background: linear-gradient(135deg, #c9a962, #b87333);
        }
        .member-badge.silver {
            background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
            color: #333;
        }
        .member-badge.member {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        .edit-btn {
            display: inline-block;
            margin-top: 12px;
            padding: 8px 20px;
            border: 1px solid #ddd;
            border-radius: 20px;
            background: #fff;
            color: #666;
            font-size: 14px;
            text-decoration: none;
            transition: border-color 0.3s ease, color 0.3s ease;
        }
        .edit-btn:hover {
            border-color: var(--navy);
            color: var(--navy);
        }
        .stats {
            display: flex;
            padding: 20px 15px;
            background: #fafbfc;
        }
        .stat-item {
            flex: 1;
            text-align: center;
        }
        .stat-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--navy);
        }
        .stat-label {
            font-size: 12px;
            color: #888;
            margin-top: 3px;
        }
        .action-links {
            padding: 10px 0;
        }
        .action-links a {
            display: block;
            padding: 15px 30px;
            color: #333;
            text-decoration: none;
            font-size: 15px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        .action-links a:hover {
            background: #f8f9fa;
            color: var(--navy);
        }
        .action-links a:last-child {
            border-bottom: none;
        }
        .logout-link {
            color: #e74c3c !important;
        }

        .user-info-section {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #888;
            font-size: 14px;
        }
        .info-value {
            font-weight: 500;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="top-area">
        <div class="logo"><img src="image/Logo.png" alt="Bon Avion Logo"></div>
        
        <div class="auth-area">
            <div class="order-dropdown">
                <button type="button" class="order-btn">My Orders ▼</button>
                <div class="dropdown-menu">
                    <a href="myOrders.php?tab=upcoming" class="order-link">Upcoming</a>
                    <a href="myOrders.php?tab=completed" class="order-link">Completed</a>
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

    <div class="profile-wrapper">
        <div class="profile-banner"></div>
        <div class="profile-card">
            <div class="card-top">
                <div class="profile-avatar">
                    <?php if ($avatarDisplay): ?>
                        <img src="<?php echo $avatarDisplay; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $initials; ?>
                    <?php endif; ?>
                </div>
                <div class="welcome-text">Welcome, <?php echo htmlspecialchars($firstName); ?>!</div>
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <span class="member-badge <?php echo strtolower($membership['membership_level']); ?>"><?php echo $membership['membership_name']; ?><?php echo ($membership['membership_level'] !== 'Member') ? ' Member' : ''; ?></span>
                <br>
                <a href="update_profile.php" class="edit-btn">Edit Profile</a>
            </div>

            <div class="user-info-section">
                <div class="info-row">
                    <span class="info-label">Customer ID</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['customer_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone Number</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['phone_number']); ?></span>
                </div>
                <?php if (!empty($customer['passport'])): ?>
                <div class="info-row">
                    <span class="info-label">Passport</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['passport']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($customer['identification'])): ?>
                <div class="info-row">
                    <span class="info-label">ID Number</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['identification']); ?></span>
                </div>
                <?php endif; ?>
            </div>
<div class="stats">
    <div class="stat-item">
        <div class="stat-value">
            <?php
            // Count paid flights
            $total_flights = $pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(b.PNR, '-', f.flight_id)) 
                FROM booking b
                INNER JOIN payment p ON b.PNR = p.PNR
                INNER JOIN ticket t ON b.PNR = t.PNR
                INNER JOIN flight f ON t.flight_id = f.flight_id
                WHERE b.customer_id = :cid
            ");
            $total_flights->execute([':cid' => $_SESSION['customer_id']]);
            echo $total_flights->fetchColumn();
            ?>
        </div>
        <div class="stat-label">Flights</div>
    </div>
    <div class="stat-item">
        <div class="stat-value">
            <?php
            // Count upcoming paid flights
            $upcoming = $pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(b.PNR, '-', f.flight_id)) 
                FROM booking b
                INNER JOIN payment p ON b.PNR = p.PNR
                INNER JOIN ticket t ON b.PNR = t.PNR
                INNER JOIN flight f ON t.flight_id = f.flight_id
                WHERE b.customer_id = :cid AND f.departure_date_time > NOW()
            ");
            $upcoming->execute([':cid' => $_SESSION['customer_id']]);
            echo $upcoming->fetchColumn();
            ?>
        </div>
        <div class="stat-label">Upcoming</div>
    </div>
    <div class="stat-item">
        <div class="stat-value">
            <?php
            // Calculate points - count booking amount for each flight (matching myOrders display)
            $points = $pdo->prepare("
                SELECT COALESCE(SUM(flight_amount), 0)
                FROM (
                    SELECT b.total_amount AS flight_amount
                    FROM booking b
                    INNER JOIN ticket t ON b.PNR = t.PNR
                    INNER JOIN flight f ON t.flight_id = f.flight_id
                    WHERE b.customer_id = :cid
                    GROUP BY b.PNR, f.flight_id, b.total_amount
                ) AS flights
            ");
            $points->execute([':cid' => $_SESSION['customer_id']]);
            echo number_format($points->fetchColumn());
            ?>
        </div>
        <div class="stat-label">Points</div>
    </div>
</div>

<div class="action-links">
    <a href="myOrders.php">
        <i class="fas fa-receipt" style="margin-right: 12px; color: #2a5298;"></i>
        My Orders
    </a>
    <a href="about_membership.php">
        <i class="fas fa-crown" style="margin-right: 12px; color: #c9a962;"></i>
        About Membership
    </a>

                <a href="logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt" style="margin-right: 12px;"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>
</body>
</html>
