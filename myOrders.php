<?php
session_start();
require_once 'membership.php';

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

// Fetch customer info
$customerStmt = $pdo->prepare("SELECT first_name FROM customer WHERE customer_id = :cid");
$customerStmt->execute([':cid' => $_SESSION['customer_id']]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
$firstName = $customer ? $customer['first_name'] : 'User';

// Fetch upcoming orders (grouped by booking)
$upcomingStmt = $pdo->prepare("
    SELECT 
        b.PNR,
        b.booking_date,
        b.total_amount,
        f.flight_number,
        f.departure_date_time,
        f.flight_status,
        t.class,
        dep.airport_name AS departure_airport,
        arr.airport_name AS arrival_airport,
        COALESCE(SUM(l.additional_fee), 0) AS luggage_fee,
        COUNT(t.ETKT_code) AS passenger_count
    FROM booking b
    INNER JOIN ticket t ON b.PNR = t.PNR
    INNER JOIN flight f ON t.flight_id = f.flight_id
    INNER JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
    INNER JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
    LEFT JOIN luggage l ON t.ETKT_code = l.ETKT_code
    WHERE b.customer_id = :cid AND f.departure_date_time > NOW()
    GROUP BY b.PNR, b.booking_date, b.total_amount, f.flight_number, 
             f.departure_date_time, f.flight_status, t.class,
             dep.airport_name, arr.airport_name
    ORDER BY f.departure_date_time ASC
");
$upcomingStmt->execute([':cid' => $_SESSION['customer_id']]);
$upcomingOrders = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch completed orders (grouped by booking)
$completedStmt = $pdo->prepare("
    SELECT 
        b.PNR,
        b.booking_date,
        b.total_amount,
        f.flight_number,
        f.departure_date_time,
        f.flight_status,
        t.class,
        dep.airport_name AS departure_airport,
        arr.airport_name AS arrival_airport,
        COALESCE(SUM(l.additional_fee), 0) AS luggage_fee,
        COUNT(t.ETKT_code) AS passenger_count
    FROM booking b
    INNER JOIN ticket t ON b.PNR = t.PNR
    INNER JOIN flight f ON t.flight_id = f.flight_id
    INNER JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
    INNER JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
    LEFT JOIN luggage l ON t.ETKT_code = l.ETKT_code
    WHERE b.customer_id = :cid AND f.departure_date_time <= NOW()
    GROUP BY b.PNR, b.booking_date, b.total_amount, f.flight_number, 
             f.departure_date_time, f.flight_status, t.class,
             dep.airport_name, arr.airport_name
    ORDER BY f.departure_date_time DESC
");
$completedStmt->execute([':cid' => $_SESSION['customer_id']]);
$completedOrders = $completedStmt->fetchAll(PDO::FETCH_ASSOC);

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'upcoming';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <style>
        :root {
            --navy: #1e3c72;
            --navy-dark: #c9a962;
            --navy-light: #eef2ff;
            --text-gray: #555;
            --completed-green: #1e8a4d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }

        /* Header - Full Width with rounded bottom */
        .header {
            background: var(--navy);
            color: #fff;
            padding: 16px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            border-radius: 0 0 12px 12px;
        }

        .header-nav-item {
            color: #fff;
            text-decoration: none;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.2s;
        }
        .header-nav-item:hover {
            opacity: 0.8;
        }

        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: bold;
        }

        /* Container for content */
        .container {
            padding: 20px;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        /* Tabs */
        .tabs {
            display: flex;
            justify-content: center;
            margin: 30px 0 20px;
            gap: 10px;
        }

        .tab-btn {
            padding: 10px 20px;
            border: 1px solid var(--navy);
            background: white;
            color: var(--navy);
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.25s;
        }

        .tab-btn:hover {
            background: var(--navy-light);
        }

        .tab-btn.active {
            background: var(--navy);
            color: white;
        }

        /* Order cards */
        .order-list {
            width: 100%;
        }

        .order-card {
            background: #fff;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            margin-bottom: 16px;
            border-left: 4px solid var(--navy);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            will-change: transform;
            cursor: pointer;
        }

        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }

        .order-card:hover .order-title,
        .order-card:hover .order-description,
        .order-card:hover .order-price,
        .order-card:hover .order-time {
            color: var(--navy-dark);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .order-status {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #fff;
        }

        .order-status.upcoming {
            background: #e74c3c;
        }

        .order-status.completed {
            background: var(--completed-green);
        }

        .order-status.cancelled {
            background: #6c757d;
        }

        .order-time {
            font-size: 14px;
            color: var(--text-gray);
        }

        .order-title {
            margin: 0 0 4px;
            font-size: 17px;
            font-weight: bold;
            color: var(--navy);
        }

        .order-description {
            margin: 0 0 8px;
            color: #666;
            font-size: 14px;
        }

        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .order-price {
            color: var(--navy-dark);
            font-size: 18px;
            font-weight: bold;
        }

        /* Tab content */
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #888;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #ddd;
        }

        .luggage-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fff3cd;
            color: #856404;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }
        .luggage-badge i {
            font-size: 11px;
        }
    </style>
</head>
<body>
    <!-- Header - Full width with rounded bottom corners -->
    <header class="header">
        <a href="index.php" class="header-nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>

        <h1>My Orders</h1>

        <a href="profile.php" class="header-nav-item">
            <i class="fas fa-user"></i>
            <span><?php echo htmlspecialchars($firstName); ?></span>
        </a>
    </header>

    <!-- Content container -->
    <div class="container">
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?php echo $activeTab === 'upcoming' ? 'active' : ''; ?>" data-tab="pending">Upcoming</button>
            <button class="tab-btn <?php echo $activeTab === 'completed' ? 'active' : ''; ?>" data-tab="completed">Completed</button>
        </div>

        <!-- Upcoming -->
        <div class="tab-content <?php echo $activeTab === 'upcoming' ? 'active' : ''; ?>" id="pending">
            <div class="order-list">
                <?php if (empty($upcomingOrders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-plane"></i>
                        <p>No upcoming flights</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingOrders as $order): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <?php if ($order['flight_status'] === 'cancelled'): ?>
                                    <span class="order-status cancelled">Cancelled</span>
                                <?php else: ?>
                                    <span class="order-status upcoming">Upcoming</span>
                                <?php endif; ?>
                                <span class="order-time"><?php echo date('Y-m-d H:i', strtotime($order['booking_date'])); ?></span>
                            </div>
                            
                            <h3 class="order-title"><?php echo htmlspecialchars($order['departure_airport']); ?> - <?php echo htmlspecialchars($order['arrival_airport']); ?> Flight <?php echo htmlspecialchars($order['flight_number']); ?></h3>
                            <p class="order-description">
                                <?php echo date('Y-m-d H:i', strtotime($order['departure_date_time'])); ?> Takeoff | <?php echo ucfirst(strtolower($order['class'])); ?> Class<?php if ($order['passenger_count'] > 1): ?> | <?php echo $order['passenger_count']; ?> Passengers<?php endif; ?>
                                <?php if ($order['luggage_fee'] > 0): ?>
                                    <span class="luggage-badge"><i class="fas fa-suitcase"></i> +$<?php echo number_format($order['luggage_fee'], 2); ?></span>
                                <?php endif; ?>
                            </p>
                            
                            <div class="order-footer">
                                <div></div>
                                <div class="order-price">$<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Completed -->
        <div class="tab-content <?php echo $activeTab === 'completed' ? 'active' : ''; ?>" id="completed">
            <div class="order-list">
                <?php if (empty($completedOrders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No completed flights</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($completedOrders as $order): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <?php if ($order['flight_status'] === 'cancelled'): ?>
                                    <span class="order-status cancelled">Cancelled</span>
                                <?php else: ?>
                                    <span class="order-status completed">Completed</span>
                                <?php endif; ?>
                                <span class="order-time"><?php echo date('Y-m-d H:i', strtotime($order['booking_date'])); ?></span>
                            </div>
                            
                            <h3 class="order-title"><?php echo htmlspecialchars($order['departure_airport']); ?> - <?php echo htmlspecialchars($order['arrival_airport']); ?> Flight <?php echo htmlspecialchars($order['flight_number']); ?></h3>
                            <p class="order-description">
                                <?php echo date('Y-m-d H:i', strtotime($order['departure_date_time'])); ?> Takeoff | <?php echo ucfirst(strtolower($order['class'])); ?> Class<?php if ($order['passenger_count'] > 1): ?> | <?php echo $order['passenger_count']; ?> Passengers<?php endif; ?>
                                <?php if ($order['luggage_fee'] > 0): ?>
                                    <span class="luggage-badge"><i class="fas fa-suitcase"></i> +$<?php echo number_format($order['luggage_fee'], 2); ?></span>
                                <?php endif; ?>
                            </p>
                            
                            <div class="order-footer">
                                <div></div>
                                <div class="order-price">$<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));

                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        const tabFromProfile = urlParams.get("tab");

        if (tabFromProfile === "completed") {
            document.querySelector('.tab-btn[data-tab="completed"]').click();
        } else if (tabFromProfile === "upcoming") {
            document.querySelector('.tab-btn[data-tab="pending"]').click();
        }
    </script>
</body>
</html>
