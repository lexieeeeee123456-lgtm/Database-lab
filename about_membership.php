<?php
session_start();
require_once 'membership.php';

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

// Get user info
$stmt = $pdo->prepare("
    SELECT customer_id, first_name, last_name, avatar_blob
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

// Get membership info
$membership = getMembershipInfo($pdo, $_SESSION['customer_id']);

// Avatar display
$avatarDisplay = '';
$initials = strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1));
if (!empty($customer['avatar_blob'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($customer['avatar_blob']);
    $avatarDisplay = 'data:' . $mime . ';base64,' . base64_encode($customer['avatar_blob']);
}

// Membership benefits configuration
$membershipBenefits = [
    'Platinum' => [
        'name' => 'Platinum Member',
        'threshold' => THRESHOLD_PLATINUM,
        'discount' => '15% Off',
        'benefits' => [
            'VIP lounge access worldwide',
            'Priority boarding and check-in',
            'Personal concierge service',
            '15% discount on all bookings',
            '4 free upgrade vouchers per year',
            'Free companion ticket annually'
        ],
        'color' => 'linear-gradient(135deg, #545478, #7f7fa5)',
        'icon' => 'fa-gem'
    ],
    'Gold' => [
        'name' => 'Gold Member',
        'threshold' => THRESHOLD_GOLD,
        'discount' => '10% Off',
        'benefits' => [
            'Lounge access at all airports',
            'Priority boarding and check-in',
            'Dedicated customer support line',
            '10% discount on all bookings',
            '2 free upgrade vouchers per year'
        ],
        'color' => 'linear-gradient(135deg, #c9a962, #b87333)',
        'icon' => 'fa-crown'
    ],
    'Silver' => [
        'name' => 'Silver Member',
        'threshold' => THRESHOLD_SILVER,
        'discount' => '5% Off',
        'benefits' => [
            '1 upgrade voucher per year',
            'Extra baggage allowance',
            '5% discount on all bookings',
            'Priority check-in counter'
        ],
        'color' => 'linear-gradient(135deg, #c0c0c0, #a8a8a8)',
        'icon' => 'fa-medal'
    ],
    'Member' => [
        'name' => 'Member',
        'threshold' => 0,
        'discount' => 'Standard Rate',
        'benefits' => [
            'Seat selection privilege',
            'Priority check-in',
            'Earn 1 point per $1 spent',
            'Online check-in service',
            'Standard baggage allowance'
        ],
        'color' => 'linear-gradient(135deg, #3498db, #2980b9)',
        'icon' => 'fa-user'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Membership</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        :root {
            --deep: #0a2463;
            --navy: #1e3c72;
            --blue: #2a5298;
            --gold: #c9a962;
            --light: #f0f4f8;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: var(--light);
        }
        
        .membership-wrapper {
            min-height: calc(100vh - 120px);
            background: var(--light);
        }
        
        .membership-hero {
            height: 280px;
            background: linear-gradient(135deg, var(--deep), var(--navy), var(--blue));
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: #fff;
            position: relative;
        }
        
        .membership-hero h1 {
            font-size: 2.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        .membership-hero h1 span {
            color: var(--gold);
        }
        
        .membership-hero p {
            font-size: 1.1rem;
            margin-top: 10px;
            opacity: 0.9;
        }
        
        .membership-container {
            max-width: 900px;
            margin: -60px auto 40px;
            padding: 0 20px;
            position: relative;
            z-index: 2;
        }
        
        /* Current Status Card */
        .status-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .status-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .status-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--gold), #b87333);
            color: #fff;
            font-size: 2rem;
            font-weight: 600;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .status-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .status-info h2 {
            margin: 0 0 5px;
            font-size: 1.5rem;
            color: #333;
        }
        
        .status-info .member-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }
        
        .progress-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .progress-bar-bg {
            height: 12px;
            background: #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            color: var(--navy);
        }
        
        .progress-text strong {
            color: var(--gold);
        }
        
        /* Tier Cards */
        .tiers-section {
            margin-top: 30px;
        }
        
        .section-title {
            font-size: 1.4rem;
            color: var(--navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--gold);
        }
        
        .tier-cards {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .tier-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .tier-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .tier-card.current {
            border: 3px solid var(--gold);
        }
        
        .tier-header {
            padding: 20px 25px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .tier-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .tier-header i {
            font-size: 1.5rem;
        }
        
        .tier-name {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .tier-discount {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 13px;
        }
        
        .tier-body {
            padding: 20px 25px;
        }
        
        .tier-threshold {
            font-size: 13px;
            color: #888;
            margin-bottom: 15px;
        }
        
        .benefit-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .benefit-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            font-size: 14px;
            color: #555;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .benefit-list li:last-child {
            border-bottom: none;
        }
        
        .benefit-list li i {
            color: #27ae60;
            font-size: 12px;
        }
        
        /* Action Links */
        .action-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-top: 30px;
            overflow: hidden;
        }
        
        .action-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px 25px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
            font-size: 15px;
        }
        
        .action-link:last-child {
            border-bottom: none;
        }
        
        .action-link:hover {
            background: #f8f9fa;
        }
        
        .action-link i {
            width: 20px;
            text-align: center;
        }
        
        .action-link.logout {
            color: #e74c3c;
        }
        
        @media (max-width: 768px) {
            .membership-hero h1 {
                font-size: 2rem;
            }
            .status-header {
                flex-direction: column;
                text-align: center;
            }
            .membership-container {
                padding: 0 15px;
            }
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
            <li><a href="profile.php" class="nav-link">Profile</a></li>
        </ul>
    </nav>

    <div class="membership-hero">
        <h1><span>Membership</span> Program</h1>
        <p>Climb from Member to Silver to Gold to Platinum and unlock exclusive privileges</p>
    </div>

    <div class="membership-wrapper">
        <div class="membership-container">
            
            <!-- Current Status -->
            <div class="status-card">
                <div class="status-header">
                    <div class="status-avatar">
                        <?php if ($avatarDisplay): ?>
                            <img src="<?php echo $avatarDisplay; ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="status-info">
                        <h2><?php echo htmlspecialchars($fullName); ?></h2>
                        <span class="member-badge" style="background: <?php echo $membershipBenefits[$membership['membership_level']]['color']; ?>">
                            <i class="fas <?php echo $membershipBenefits[$membership['membership_level']]['icon']; ?>" style="margin-right: 6px;"></i>
                            <?php echo $membership['membership_name']; ?><?php echo ($membership['membership_level'] !== 'Member') ? ' Member' : ''; ?>
                        </span>
                    </div>
                </div>
                
                <div class="progress-section">
                    <?php if ($membership['next_level']): ?>
                        <div class="progress-label">
                            <span><?php echo $membership['membership_name']; ?></span>
                            <span><?php echo $membership['next_level']; ?></span>
                        </div>
                        <div class="progress-bar-bg">
                            <?php
                            $progress = 0;
                            if ($membership['membership_level'] === 'Member') {
                                $progress = ($membership['total_spending'] / THRESHOLD_SILVER) * 100;
                            } elseif ($membership['membership_level'] === 'Silver') {
                                $progress = (($membership['total_spending'] - THRESHOLD_SILVER) / (THRESHOLD_GOLD - THRESHOLD_SILVER)) * 100;
                            } elseif ($membership['membership_level'] === 'Gold') {
                                $progress = (($membership['total_spending'] - THRESHOLD_GOLD) / (THRESHOLD_PLATINUM - THRESHOLD_GOLD)) * 100;
                            }
                            $progress = min(100, max(0, $progress));
                            ?>
                            <div class="progress-bar-fill" style="width: <?php echo $progress; ?>%; background: <?php echo $membershipBenefits[$membership['membership_level']]['color']; ?>"></div>
                        </div>
                        <div class="progress-text">
                            Total Spent: <strong>$<?php echo number_format($membership['total_spending'], 2); ?></strong> 
                            &nbsp;|&nbsp; 
                            <strong>$<?php echo number_format($membership['amount_to_next_level'], 2); ?></strong> more to reach <?php echo $membership['next_level']; ?>
                        </div>
                    <?php else: ?>
                        <div class="progress-text" style="text-align: center; padding: 10px 0;">
                            <i class="fas fa-trophy" style="color: var(--gold); margin-right: 8px;"></i>
                            Congratulations! You've reached the highest membership level!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Membership Tiers -->
            <div class="tiers-section">
                <h3 class="section-title">
                    <i class="fas fa-gem"></i> Membership Tiers & Benefits
                </h3>
                
                <div class="tier-cards">
                    <?php foreach (['Platinum', 'Gold', 'Silver', 'Member'] as $level): 
                        $tier = $membershipBenefits[$level];
                        $isCurrent = ($membership['membership_level'] === $level);
                    ?>
                    <div class="tier-card <?php echo $isCurrent ? 'current' : ''; ?>">
                        <div class="tier-header" style="background: <?php echo $tier['color']; ?>">
                            <div class="tier-header-left">
                                <i class="fas <?php echo $tier['icon']; ?>"></i>
                                <span class="tier-name"><?php echo $tier['name']; ?></span>
                                <?php if ($isCurrent): ?>
                                    <span style="background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 10px; font-size: 11px;">CURRENT</span>
                                <?php endif; ?>
                            </div>
                            <span class="tier-discount"><?php echo $tier['discount']; ?></span>
                        </div>
                        <div class="tier-body">
                            <div class="tier-threshold">
                                <?php if ($level === 'Member'): ?>
                                    Entry level membership
                                <?php else: ?>
                                    Requires $<?php echo number_format($tier['threshold']); ?>+ total spending
                                <?php endif; ?>
                            </div>
                            <ul class="benefit-list">
                                <?php foreach ($tier['benefits'] as $benefit): ?>
                                <li>
                                    <i class="fas fa-check"></i>
                                    <span><?php echo $benefit; ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Action Links -->
            <div class="action-section">
                <a href="profile.php" class="action-link">
                    <i class="fas fa-user" style="color: var(--blue);"></i>
                    Back to Profile
                </a>
                <a href="myOrders.php" class="action-link">
                    <i class="fas fa-receipt" style="color: var(--blue);"></i>
                    My Orders
                </a>
                <a href="index.php" class="action-link">
                    <i class="fas fa-plane" style="color: var(--gold);"></i>
                    Book a Flight
                </a>
                <a href="logout.php" class="action-link logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
            
        </div>
    </div>
</body>
</html>
