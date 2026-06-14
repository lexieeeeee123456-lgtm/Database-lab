<?php
// admin_booking_status.php - 修改为直接使用flight_number和departure_date_time搜索
session_start();

// 1. 基础配置和验证
if (!isset($_SESSION['airline_admin_id']) || !isset($_SESSION['IATA_airline_code'])) {
    header("Location: admin_login.php");
    exit();
}

$adminIATA = strtoupper($_SESSION['IATA_airline_code']);

// 2. 数据库连接
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// 3. 处理搜索
$searchResults = [];
$searchPerformed = false;
$selectedFlightNumber = '';
$selectedDate = '';
$flightInfo = null;
$errorMessage = '';
$queryTime = 0;

// 座位可用性统计
$seatAvailability = [
    'First Class' => ['total' => 0, 'booked' => 0, 'available' => 0],
    'Business' => ['total' => 0, 'booked' => 0, 'available' => 0],
    'Economy' => ['total' => 0, 'booked' => 0, 'available' => 0]
];

// 收入统计
$revenueStats = [
    'total_revenue' => 0,
    'average_revenue' => 0,
    'revenue_by_class' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchPerformed = true;
    $startTime = microtime(true);
    
    // 获取搜索参数
    $selectedFlightNumber = isset($_POST['flight_number']) ? trim($_POST['flight_number']) : '';
    $selectedDate = isset($_POST['search_date']) ? trim($_POST['search_date']) : '';
    
    // 基础验证
    if (!empty($selectedFlightNumber) && !empty($selectedDate)) {
        // 查询航班信息（包括飞机座位信息）
        // 使用 flight_number 和 departure_date_time 的日期部分进行搜索
        $stmt = $conn->prepare("
            SELECT 
                f.flight_id, 
                f.flight_number, 
                f.departure_airport_code, 
                f.arrival_airport_code,
                f.departure_date_time, 
                f.arrival_date_time, 
                f.IATA_airline_code,
                f.ARN,
                f.first_class_price,
                f.business_class_price,
                f.economy_class_price,
                a.first_class_seat_num,
                a.business_class_seat_num,
                a.economy_class_seat_num,
                dep.airport_name as departure_name,
                arr.airport_name as arrival_name
            FROM flight f
            LEFT JOIN aircraft a ON f.ARN = a.ARN
            LEFT JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
            LEFT JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
            WHERE f.flight_number = ? 
            AND DATE(f.departure_date_time) = ?
            AND f.IATA_airline_code = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("sss", $selectedFlightNumber, $selectedDate, $adminIATA);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $flightInfo = $result->fetch_assoc();
                
                // 获取飞机座位总数
                $seatAvailability = [
                    'First Class' => [
                        'total' => $flightInfo['first_class_seat_num'] ?? 0,
                        'booked' => 0,
                        'available' => $flightInfo['first_class_seat_num'] ?? 0
                    ],
                    'Business' => [
                        'total' => $flightInfo['business_class_seat_num'] ?? 0,
                        'booked' => 0,
                        'available' => $flightInfo['business_class_seat_num'] ?? 0
                    ],
                    'Economy' => [
                        'total' => $flightInfo['economy_class_seat_num'] ?? 0,
                        'booked' => 0,
                        'available' => $flightInfo['economy_class_seat_num'] ?? 0
                    ]
                ];
                
                // 查询该航班的完整预订信息，使用实际的flight_id
                $actualFlightId = $flightInfo['flight_id'];
                $stmt2 = $conn->prepare("
                    SELECT 
                        b.PNR,
                        b.customer_id,
                        b.total_amount,
                        b.booking_date,
                        b.booking_status,
                        t.class as seat_class,
                        t.ETKT_code,
                        c.first_name,
                        c.last_name,
                        c.passport,
                        c.phone_number,
                        COUNT(l.luggage_id) as luggage_count
                    FROM booking b
                    INNER JOIN ticket t ON b.PNR = t.PNR AND b.customer_id = t.customer_id
                    INNER JOIN customer c ON b.customer_id = c.customer_id
                    LEFT JOIN luggage l ON t.ETKT_code = l.ETKT_code
                    WHERE t.flight_id = ?
                    GROUP BY b.PNR, b.customer_id, t.ETKT_code, t.class, b.total_amount, 
                             b.booking_date, b.booking_status, c.first_name, c.last_name, 
                             c.passport, c.phone_number
                    ORDER BY b.booking_date DESC
                ");
                
                if ($stmt2) {
                    $stmt2->bind_param("s", $actualFlightId);
                    $stmt2->execute();
                    $bookingResult = $stmt2->get_result();
                    
                    if ($bookingResult) {
                        $classRevenue = ['First Class' => 0, 'Business' => 0, 'Economy' => 0];
                        $classCount = ['First Class' => 0, 'Business' => 0, 'Economy' => 0];
                        
                        while ($row = $bookingResult->fetch_assoc()) {
                            $searchResults[] = $row;
                            
                            // 统计各舱位预订数量
                            $seatClass = $row['seat_class'];
                            if (isset($seatAvailability[$seatClass])) {
                                $seatAvailability[$seatClass]['booked']++;
                                $seatAvailability[$seatClass]['available'] = 
                                    $seatAvailability[$seatClass]['total'] - 
                                    $seatAvailability[$seatClass]['booked'];
                            }
                            
                            // 统计收入
                            $revenueStats['total_revenue'] += $row['total_amount'];
                            if (isset($classRevenue[$seatClass])) {
                                $classRevenue[$seatClass] += $row['total_amount'];
                                $classCount[$seatClass]++;
                            }
                        }
                        
                        // 计算平均收入和各舱位平均收入
                        $totalBookings = count($searchResults);
                        $revenueStats['average_revenue'] = $totalBookings > 0 ? 
                            $revenueStats['total_revenue'] / $totalBookings : 0;
                        
                        foreach ($classRevenue as $class => $revenue) {
                            $count = $classCount[$class];
                            $revenueStats['revenue_by_class'][$class] = [
                                'total' => $revenue,
                                'average' => $count > 0 ? $revenue / $count : 0,
                                'count' => $count
                            ];
                        }
                    }
                    $stmt2->close();
                }
            } else {
                $errorMessage = "Flight '{$selectedFlightNumber}' on {$selectedDate} not found for airline {$adminIATA}";
                $errorMessage .= "<br><small>Search criteria: Flight Number = {$selectedFlightNumber}, Date = {$selectedDate}</small>";
            }
            $stmt->close();
        }
    } else {
        $errorMessage = "Please enter both flight number and date";
    }
    
    $endTime = microtime(true);
    $queryTime = round($endTime - $startTime, 3);
}

// 4. 获取航班号列表（当前航空公司的）
$flightNumbers = [];
$stmt3 = $conn->prepare("
    SELECT DISTINCT flight_number 
    FROM flight 
    WHERE IATA_airline_code = ?
    ORDER BY flight_number
");

if ($stmt3) {
    $stmt3->bind_param("s", $adminIATA);
    $stmt3->execute();
    $result3 = $stmt3->get_result();
    
    if ($result3) {
        while ($row = $result3->fetch_assoc()) {
            $flightNumbers[] = $row['flight_number'];
        }
    }
    $stmt3->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Status - Bon Avion</title>
    <link rel="stylesheet" href="common_style.css">
    <style>
        .search-container {
            max-width: 1200px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        
        .search-title {
            color: #1e3c72;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .search-form {
            display: grid;
            gap: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
        }
        
        .btn-search {
            background: #1e3c72;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
            width: 100%;
        }
        
        .btn-search:hover {
            background: #2a5298;
        }
        
        .flight-info-box {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #1e3c72;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .flight-info-box h3 {
            color: #1e3c72;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .dashboard-card h4 {
            color: #1e3c72;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .seat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .seat-class {
            font-weight: 600;
            color: #333;
        }
        
        .seat-count {
            color: #666;
            text-align: right;
        }
        
        .seat-available {
            color: #28a745;
            font-weight: 600;
        }
        
        .seat-booked {
            color: #dc3545;
        }
        
        .revenue-stats {
            display: grid;
            gap: 15px;
        }
        
        .revenue-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .revenue-item:last-child {
            border-bottom: none;
        }
        
        .results-section {
            margin-top: 40px;
        }
        
        .results-title {
            color: #1e3c72;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .results-table th {
            background: #1e3c72;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .results-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .results-table tr:hover {
            background: #f8f9fa;
        }
        
        .no-results {
            text-align: center;
            padding: 60px;
            color: #666;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 30px 0;
        }
        
        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-confirmed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .info-message {
            background: #e7f3ff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #1e3c72;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #dc3545;
        }
        
        .search-preview {
            background: #f0f9ff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px dashed #1e3c72;
            font-family: monospace;
            font-size: 16px;
        }
        
        .price-tag {
            background: #e9ecef;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 14px;
            color: #495057;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-view {
            background: #1e3c72;
            color: white;
        }
        
        .btn-view:hover {
            background: #2a5298;
        }
        
        .btn-confirm {
            background: #28a745;
            color: white;
        }
        
        .btn-confirm:hover {
            background: #218838;
        }
        
        .btn-cancel {
            background: #dc3545;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #c82333;
        }
        
        .Runing_time {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            color: #6f42c1;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px dashed #6f42c1;
        }
    </style>
</head>
<body>
    <div class="sticky-header">
        <div class="header-content">
            <div class="logo-container">
                <div class="airline-logo">BA</div>
                <h1 class="heading">Bon Avion Booking Status</h1>
            </div>
            <a href="maindesk.php" class="btn-back">← Dashboard</a>
            <div class="airline-logo" style="background: linear-gradient(135deg, #e44d26 0%, #f16529 100%);">
                <?php echo htmlspecialchars($adminIATA); ?>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="search-container">
            <h2 class="search-title">Flight Booking Status - <?php echo htmlspecialchars($adminIATA); ?></h2>
            
            <div class="info-message">
                <i class="fas fa-info-circle"></i> 
                Enter flight number and departure date. The system will search for flights matching both criteria.
            </div>
            
            <?php if (!empty($errorMessage)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> 
                    <?php echo $errorMessage; ?>
                </div>
            <?php endif; ?>
            
            <!-- 搜索预览 -->
            <?php if (!empty($selectedFlightNumber) && !empty($selectedDate)): ?>
                <div class="search-preview">
                    <strong>Searching for:</strong><br>
                    Flight Number: <?php echo htmlspecialchars($selectedFlightNumber); ?><br>
                    Departure Date: <?php echo htmlspecialchars($selectedDate); ?>
                </div>
            <?php endif; ?>
            
            <!-- 搜索表单 -->
            <form method="POST" class="search-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="flight_number">Flight Number</label>
                        <input type="text" name="flight_number" id="flight_number" 
                               class="form-control" required 
                               value="<?php echo htmlspecialchars($selectedFlightNumber); ?>"
                               placeholder="e.g., CA1315">
                        <small><?php echo count($flightNumbers); ?> unique flight numbers available</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="search_date">Departure Date</label>
                        <input type="date" name="search_date" id="search_date" 
                               class="form-control" required 
                               value="<?php echo $selectedDate ?: date('Y-m-d'); ?>"
                               max="<?php echo date('Y-m-d', strtotime('+2 years')); ?>">
                        <small>Select departure date (searches by DATE(departure_date_time))</small>
                    </div>
                </div>
                
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Search Bookings
                </button>
            </form>
            
            <!-- 查询时间 -->
            <?php if ($queryTime > 0): ?>
                <div class="Runing_time">
                    <p>The running time of this searching query is <?php echo number_format($queryTime, 3); ?> seconds</p>
                </div>
            <?php endif; ?>
            
            <!-- 航班信息 -->
            <?php if ($searchPerformed && isset($flightInfo)): ?>
                <div class="flight-info-box">
                    <h3>Flight Information</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div>
                            <strong>Flight ID:</strong> <?php echo htmlspecialchars($flightInfo['flight_id']); ?><br>
                            <strong>Flight Number:</strong> <?php echo htmlspecialchars($flightInfo['flight_number']); ?><br>
                            <strong>Airline:</strong> <?php echo htmlspecialchars($flightInfo['IATA_airline_code']); ?>
                        </div>
                        <div>
                            <strong>Route:</strong><br>
                            <span style="font-size: 18px; font-weight: bold;">
                                <?php echo htmlspecialchars($flightInfo['departure_airport_code']); ?> 
                                <i class="fas fa-long-arrow-alt-right"></i> 
                                <?php echo htmlspecialchars($flightInfo['arrival_airport_code']); ?>
                            </span><br>
                            <small>
                                <?php echo htmlspecialchars($flightInfo['departure_name']); ?> → 
                                <?php echo htmlspecialchars($flightInfo['arrival_name']); ?>
                            </small>
                        </div>
                        <div>
                            <strong>Schedule:</strong><br>
                            Departure: <?php echo date('Y-m-d H:i', strtotime($flightInfo['departure_date_time'])); ?><br>
                            Arrival: <?php echo date('Y-m-d H:i', strtotime($flightInfo['arrival_date_time'])); ?>
                        </div>
                        <div>
                            <strong>Aircraft:</strong> <?php echo htmlspecialchars($flightInfo['ARN']); ?><br>
                            <strong>Prices:</strong><br>
                            <span class="price-tag">First: $<?php echo number_format($flightInfo['first_class_price'], 2); ?></span>
                            <span class="price-tag">Business: $<?php echo number_format($flightInfo['business_class_price'], 2); ?></span>
                            <span class="price-tag">Economy: $<?php echo number_format($flightInfo['economy_class_price'], 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- 仪表板卡片 -->
                <div class="dashboard-cards">
                    <!-- 座位可用性卡片 -->
                    <div class="dashboard-card">
                        <h4><i class="fas fa-chair"></i> Seat Availability</h4>
                        <?php foreach ($seatAvailability as $class => $data): ?>
                            <div class="seat-row">
                                <span class="seat-class"><?php echo htmlspecialchars($class); ?></span>
                                <span class="seat-count">
                                    <span class="seat-available"><?php echo $data['available']; ?></span> / 
                                    <?php echo $data['total']; ?> seats
                                    <br>
                                    <small>(<?php echo $data['booked']; ?> booked)</small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; text-align: center;">
                            <strong>Total Capacity:</strong> 
                            <?php echo array_sum(array_column($seatAvailability, 'total')); ?> seats
                        </div>
                    </div>
                    
                    <!-- 预订统计卡片 -->
                    <div class="dashboard-card">
                        <h4><i class="fas fa-chart-bar"></i> Booking Statistics</h4>
                        <div style="text-align: center; margin: 20px 0;">
                            <div style="font-size: 36px; font-weight: bold; color: #1e3c72;">
                                <?php echo count($searchResults); ?>
                            </div>
                            <div style="color: #666;">Total Bookings</div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #28a745;">
                                    $<?php echo number_format($revenueStats['total_revenue'], 2); ?>
                                </div>
                                <small>Total Revenue</small>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #17a2b8;">
                                    $<?php echo number_format($revenueStats['average_revenue'], 2); ?>
                                </div>
                                <small>Avg per Booking</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 收入分布卡片 -->
                    <div class="dashboard-card">
                        <h4><i class="fas fa-money-bill-wave"></i> Revenue by Class</h4>
                        <div class="revenue-stats">
                            <?php foreach ($revenueStats['revenue_by_class'] as $class => $data): ?>
                                <div class="revenue-item">
                                    <span><?php echo htmlspecialchars($class); ?></span>
                                    <span>
                                        <strong>$<?php echo number_format($data['total'], 2); ?></strong>
                                        <br>
                                        <small><?php echo $data['count']; ?> bookings × $<?php echo number_format($data['average'], 2); ?></small>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 搜索结果 -->
                <div class="results-section">
                    <h3 class="results-title">
                        Booking Details 
                        <span style="color: #666; font-size: 16px; font-weight: normal;">
                            (<?php echo count($searchResults); ?> booking<?php echo count($searchResults) !== 1 ? 's' : ''; ?>)
                        </span>
                    </h3>
                    
                    <?php if (count($searchResults) > 0): ?>
                        <div class="table-container">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>PNR</th>
                                        <th>Passenger</th>
                                        <th>Class</th>
                                        <th>Luggage</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Booking Date</th>
                                        <th>ETKT</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($searchResults as $booking): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($booking['PNR']); ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></strong><br>
                                                <small style="color: #666;">
                                                    ID: <?php echo htmlspecialchars($booking['customer_id']); ?><br>
                                                    Passport: <?php echo htmlspecialchars($booking['passport']); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['seat_class']); ?></td>
                                            <td style="text-align: center;">
                                                <span style="font-size: 18px; font-weight: bold;"><?php echo $booking['luggage_count']; ?></span><br>
                                                <small>bag<?php echo $booking['luggage_count'] != 1 ? 's' : ''; ?></small>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format($booking['total_amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <?php 
                                                $statusClass = 'status-pending';
                                                switch ($booking['booking_status']) {
                                                    case 'Confirmed':
                                                        $statusClass = 'status-confirmed';
                                                        break;
                                                    case 'Cancelled':
                                                        $statusClass = 'status-cancelled';
                                                        break;
                                                }
                                                ?>
                                                <span class="status-badge <?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($booking['booking_status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d', strtotime($booking['booking_date'])); ?></td>
                                            <td><code><?php echo htmlspecialchars($booking['ETKT_code']); ?></code></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-view" 
                                                            onclick="viewBookingDetails('<?php echo $booking['PNR']; ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($booking['booking_status'] === 'Pending'): ?>
                                                        <button class="btn-action btn-confirm" 
                                                                onclick="confirmBooking('<?php echo $booking['PNR']; ?>')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($booking['booking_status'] !== 'Cancelled'): ?>
                                                        <button class="btn-action btn-cancel" 
                                                                onclick="cancelBooking('<?php echo $booking['PNR']; ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- 导出选项 -->
                        <div style="text-align: right; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <button class="btn-action btn-view" onclick="exportToCSV()">
                                <i class="fas fa-download"></i> Export to CSV
                            </button>
                            <button class="btn-action btn-view" onclick="window.print()" style="margin-left: 10px;">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="no-results">
                            <i class="fas fa-search" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                            <h3>No Bookings Found</h3>
                            <p>
                                No bookings found for flight <strong><?php echo htmlspecialchars($selectedFlightNumber); ?></strong> 
                                on <strong><?php echo htmlspecialchars($selectedDate); ?></strong>.
                            </p>
                            <p><small>Flight ID: <?php echo htmlspecialchars($flightInfo['flight_id']); ?></small></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($searchPerformed): ?>
                <div class="no-results">
                    <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #ffc107; margin-bottom: 20px;"></i>
                    <h3>Flight Not Found</h3>
                    <p>Please check the flight number and date, then try again.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <footer>
            <div class="footer">
                <p>Bon Avion Airline Administration System &copy; <?php echo date('Y'); ?></p>
                <p style="font-size: 0.8rem; color: #888;">
                    Showing data for <?php echo htmlspecialchars($adminIATA); ?> airline only
                </p>
            </div>
        </footer>
    </div>
    
    <script>
        // 设置默认日期为今天
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('search_date');
            if (!dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
            
            // 实时显示搜索预览
            function updateSearchPreview() {
                const flightNumber = document.getElementById('flight_number').value.trim();
                const dateValue = document.getElementById('search_date').value;
                
                if (flightNumber && dateValue) {
                    let previewDiv = document.querySelector('.search-preview');
                    if (!previewDiv) {
                        previewDiv = document.createElement('div');
                        previewDiv.className = 'search-preview';
                        const form = document.querySelector('.search-form');
                        form.parentNode.insertBefore(previewDiv, form.nextSibling);
                    }
                    previewDiv.innerHTML = `
                        <strong>Searching for:</strong><br>
                        Flight Number: ${flightNumber}<br>
                        Departure Date: ${dateValue}
                    `;
                }
            }
            
            document.getElementById('flight_number').addEventListener('input', updateSearchPreview);
            document.getElementById('search_date').addEventListener('change', updateSearchPreview);
        });
        
        function viewBookingDetails(pnr) {
            alert('Viewing details for PNR: ' + pnr);
            // 实际项目中这里可以打开模态框或跳转到详情页面
            // window.open('booking_details.php?pnr=' + pnr, '_blank');
        }
        
        function confirmBooking(pnr) {
            if (confirm('Confirm booking ' + pnr + '?')) {
                // AJAX请求确认预订
                fetch('update_booking_status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=confirm&pnr=' + encodeURIComponent(pnr)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Booking confirmed successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        function cancelBooking(pnr) {
            if (confirm('Cancel booking ' + pnr + '?')) {
                // AJAX请求取消预订
                fetch('update_booking_status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=cancel&pnr=' + encodeURIComponent(pnr)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Booking cancelled successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        function exportToCSV() {
            alert('Export feature would generate a CSV file with all booking data.');
            // 实际项目中这里可以生成CSV文件并下载
        }
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</body>
</html>