<?php
session_start();

// Database connection configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}


$isLoggedIn = isset($_SESSION['customer_id']);
$firstName = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';

$navAvatarDisplay = '';
$navInitials = '';
if ($isLoggedIn) {
    $stmt = $conn->prepare("SELECT first_name, last_name, avatar_blob FROM customer WHERE customer_id = ?");
    $stmt->bind_param("s", $_SESSION['customer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $navInitials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
        if (!empty($row['avatar_blob'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($row['avatar_blob']);
            $navAvatarDisplay = 'data:' . $mime . ';base64,' . base64_encode($row['avatar_blob']);
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - About Us</title>
    <link rel="stylesheet" href="css/main.css">
    <!-- Font Awesome refer-->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <style>
        :root {
            --deep: #0a2463;
            --navy: #1e3c72;
            --blue: #2a5298;
            --gold: #c9a962;
            --light: #f0f4f8;
            --text: #2d3436;
        }

        .about-wrapper {
            min-height: calc(100vh - 120px);
            background: var(--light);
        }

        /* hero area */
        .about-hero {
            height: 300px;
            background: linear-gradient(135deg, var(--deep), var(--navy), var(--blue));
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .about-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('image/about-bg.jpg') center/cover no-repeat;
            opacity: 0.15;
        }
        .about-hero h1 {
            font-size: 3.2rem;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 1;
            font-family: 'Playfair Display', serif;
        }
        .about-hero h1 span {
            color: var(--gold);
            font-style: italic;
        }
        .about-hero p {
            font-size: 1.2rem;
            margin-top: 12px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        /* content area */
        .about-container {
            max-width: 1200px;
            margin: -60px auto 60px;
            padding: 0 20px;
            position: relative;
            z-index: 2;
        }

        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .info-card {
            background: #fff;
            border-radius: 12px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            will-change: transform;
        }
        .info-card:hover {
            transform: translateY(-12px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .card-header {
            padding: 30px 30px 20px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            text-align: center;
        }
        .card-header i {
            font-size: 2.4rem;
            margin-bottom: 12px;
            opacity: 0.9;
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
        }

        .card-body {
            padding: 30px;
            color: #444;
            line-height: 1.7;
        }
        .card-body ul {
            padding-left: 20px;
            margin: 18px 0;
        }
        .card-body li {
            margin-bottom: 10px;
            position: relative;
        }
        .card-body li::marker {
            color: var(--gold);
        }
        .highlight {
            color: var(--gold);
            font-style: italic;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .about-hero h1 { font-size: 2.5rem; }
            .about-hero { height: 260px; }
            .about-container { margin-top: -40px; }
            .card-header { padding: 25px 20px; }
            .card-header i { font-size: 2rem; }
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
            <?php if ($isLoggedIn): ?>
                <div class="user-welcome">
                    <a href="update_profile.php" class="nav-avatar">
                        <?php if ($navAvatarDisplay): ?>
                            <img src="<?php echo $navAvatarDisplay; ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $navInitials; ?>
                        <?php endif; ?>
                    </a>
                    <span>Welcome, <?php echo htmlspecialchars($firstName); ?>!</span>
                    <a href="logout.php" class="login-btn" style="margin-left: 10px;">Logout</a>
                </div>
            <?php else: ?>
                <div class="auth-buttons" style="display: flex;">
                    <a href="login.php" class="login-btn">Login</a>
                    <a href="register.php" class="register-btn">Register</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <nav class="main-nav">
        <ul class="nav-list">
            <li><a href="index.php" class="nav-link">Index</a></li>
            <li><a href="recommendation.php" class="nav-link">Recommendation</a></li>
            <li><a href="AboutUs.php" class="nav-link active">About Us</a></li>
            <li><a href="profile.php" class="nav-link">Profile</a></li>
        </ul>
    </nav>

    <div class="about-hero">
        <h1>About <span>Bon Avion</span></h1>
        <p>We are more than an airline — we are your journey companion</p>
    </div>

    <div class="about-wrapper">
        <div class="about-container">
            <div class="section-grid">

                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-suitcase-rolling"></i>
                        <h3>Baggage Policy</h3>
                    </div>
                    <div class="card-body">
                        <p>At <span class="highlight">Bon Avion</span>, we offer generous baggage allowances and clear guidelines to make packing easy.</p>
                        <ul>
                            <li><strong>Checked baggage</strong>: 1×23kg free in Economy, 2×32kg in Business</li>
                            <li><strong>Carry-on</strong>: 10kg + 1 personal item always free</li>
                            <li><strong>Overweight/Extra bags</strong>: Flat fees, book in advance for best rates</li>
                        </ul>
                        <p>Sports equipment & musical instruments welcome with prior notice.</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-gem"></i>
                        <h3>Rewards Program</h3>
                    </div>
                    <div class="card-body">
                        <p>Earn points on every time you fly and redeem them for flights, upgrades, and more.</p>
                        <ul>
                            <li>Earn 1 point for every $1 spent</li>
                            <li>Frequent bonus & double-point promotions</li>
                            <li>Redeem for award tickets, upgrades, extra baggage</li>
                        </ul>
                        <p>Start earning from your very first flight!</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-crown"></i>
                        <h3>Membership Tiers</h3>
                    </div>
                    <div class="card-body">
                        <p>Climb from Blue → Silver → Gold → Platinum and unlock exclusive privileges.</p>
                        <ul>
                            <li><strong>Blue</strong>: Seat selection + priority check-in</li>
                            <li><strong>Silver</strong>: 5% off, 1 upgrade voucher, extra bag</li>
                            <li><strong>Gold</strong>: 10% off, lounge access, priority everything</li>
                            <li><strong>Platinum</strong>: 15% off, VIP lounge, personal concierge, free companion ticket</li>
                        </ul>
                    </div>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-heartbeat"></i>
                        <h3>Special Services</h3>
                    </div>
                    <div class="card-body">
                        <p>We care for every passenger with tailored assistance.</p>
                        <ul>
                            <li>Unaccompanied minors with full escort service</li>
                            <li>Wheelchair & mobility support</li>
                            <li>Allergy-friendly meals & cleaning</li>
                            <li>Medical oxygen, sign language, stretcher service</li>
                        </ul>
                        <p>Just let us know 48h in advance — we've got you.</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-ticket-alt"></i>
                        <h3>Ticketing Rules</h3>
                    </div>
                    <div class="card-body">
                        <p>Flexible and transparent policies designed with you in mind.</p>
                        <ul>
                            <li>24h full refund guarantee</li>
                            <li>Change flights anytime (small fee if >7 days)</li>
                            <li>Non-refundable → travel credit</li>
                            <li>Extra flexibility during special circumstances</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    </div>
<?php
    
    $conn->close();
?>
</body>
</html>