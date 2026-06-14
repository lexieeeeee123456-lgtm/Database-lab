<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// 强制登录验证
$isLoggedIn = isset($_SESSION['airline_admin_id']);
if (!$isLoggedIn) {
    header("Location: admin_login.php");
    exit();
}

$adminIATA = '';
$navInitials = '';
$flightCount = 0;
$bookingCount = 0;
$aircraftCount = 0;

if ($isLoggedIn) {
    // 获取航空公司IATA代码
    $stmt = $conn->prepare("SELECT IATA_airline_code FROM airline_administrator WHERE airline_admin_id = ?");
    $stmt->bind_param("s", $_SESSION['airline_admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $adminIATA = strtoupper($row['IATA_airline_code']);
        $navInitials = $adminIATA;
        
        // 现在获取动态数据
        
        // 1. 获取航班总数（按航空公司筛选）
        $flightQuery = $conn->prepare("SELECT COUNT(*) as total FROM flight WHERE IATA_airline_code = ?");
        $flightQuery->bind_param("s", $adminIATA);
        $flightQuery->execute();
        $flightResult = $flightQuery->get_result();
        if ($flightRow = $flightResult->fetch_assoc()) {
            $flightCount = $flightRow['total'];
        }
        $flightQuery->close();
        
        // 2. 获取预订总数（统计所有预订）
        $bookingQuery = $conn->query("SELECT COUNT(*) as total FROM booking");
        if ($bookingRow = $bookingQuery->fetch_assoc()) {
            $bookingCount = $bookingRow['total'];
        }
        $bookingQuery->close();
        
        // 3. 获取飞机总数（按航空公司筛选）
        $aircraftQuery = $conn->prepare("SELECT COUNT(*) as total FROM aircraft WHERE IATA_airline_code = ?");
        $aircraftQuery->bind_param("s", $adminIATA);
        $aircraftQuery->execute();
        $aircraftResult = $aircraftQuery->get_result();
        if ($aircraftRow = $aircraftResult->fetch_assoc()) {
            $aircraftCount = $aircraftRow['total'];
        }
        $aircraftQuery->close();
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Airline Management</title>
    <link rel="stylesheet" href="common_style.css">
    <style>
        /* 补充基础间距样式（替代BR标签） */
        .spacing { margin:0.25rem 0; }
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            padding: 0 1rem;
            max-width: 1200px;
            margin: 0.5rem auto;
        }
        .dashboard-card {
            background: #fff;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .dashboard-card .number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }
        /* 不同卡片不同颜色 */
        .dashboard-card:nth-child(1) .number { color: #e44d26; } /* 航班 - 橙色 */
        .dashboard-card:nth-child(2) .number { color: #3b82f6; } /* 预订 - 蓝色 */
        .dashboard-card:nth-child(3) .number { color: #10b981; } /* 飞机 - 绿色 */
        .dashboard-card p {
            color: #666;
            margin: 0;
        }
        .dashboard-card .sub-info {
            font-size: 0.9rem;
            color: #888;
            margin-top: 0.5rem;
            padding: 0.3rem 0.6rem;
            background: #f8f9fa;
            border-radius: 4px;
            display: inline-block;
        }
        
        /* 更新状态提示 */
        .data-updated {
            font-size: 0.8rem;
            color: #4CAF50;
            margin-top: 0.5rem;
        }
        
        /* 实时时间显示 */
        .live-time {
            font-size: 0.9rem;
            color: #3b82f6;
            background: #f0f7ff;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            display: inline-block;
            margin-top: 0.5rem;
            font-family: monospace;
        }
        
        /* 统计说明 */
        .stats-note {
            text-align: center;
            color: #666;
            font-size: 0.9rem;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* 刷新按钮样式 */
        .refresh-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-left: 0.5rem;
            transition: background 0.3s;
        }
        .refresh-btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <!-- 严格匹配sticky-header风格 -->
    <div class="sticky-header">
        <div class="header-content">
            <div class="logo-container">
                <div class="airline-logo">BA</div>
                <h1 class="heading">Bon Avion Airline Management</h1>
            </div>
            <div class="button-container">     
                <a href="admin_flight_management.php" class="btn-link">Flight Management</a>
                <a href="admin_booking_status.php" class="btn-link">Booking Status</a>
                <a href="airline_aircraft_schedule.php" class="btn-link">Aircraft Schedule</a>
            </div>          
            <!-- 管理员IATA编码头像（匹配airline-logo风格） -->
            <div class="airline-logo" style="background: linear-gradient(135deg, #e44d26 0%, #f16529 100%);">
                <?php echo htmlspecialchars($navInitials); ?>
            </div>
            <a href="admin_logout.php" class="btn-back" onclick="return confirm('Are you sure you want to logout?')"> Logout ↩ </a> 
        </div>
    </div>

    <div class="container">
        <!-- Welcome page -->
        <div class="spacing"></div>
        <div class="page-header">
            <h1 id="airlineTitle"><?php echo htmlspecialchars($adminIATA); ?> Airline Dashboard</h1>
            <p>Welcome to <?php echo htmlspecialchars($adminIATA); ?> airline portal</p>
            <p class="data-updated">Data updated: <span id="lastUpdatedTime"><?php echo date('Y-m-d H:i:s'); ?></span></p>
            <div class="live-time">
                Current time: <span id="currentTime"><?php echo date('H:i:s'); ?></span>
                <button class="refresh-btn" onclick="refreshPage()">↻ Refresh</button>
            </div>
        </div>

        <!-- Dashboard -->
        <div class="spacing"></div>
        <div class="dashboard-cards">
            <div class="dashboard-card">
                <h3>Total Flights</h3>
                <div class="number"><?php echo $flightCount; ?></div>
                <p>Active flights in system</p>
                <div class="sub-info">Filtered by: <?php echo htmlspecialchars($adminIATA); ?></div>
            </div>
            <div class="dashboard-card">
                <h3>Total Bookings</h3>
                <div class="number"><?php echo $bookingCount; ?></div>
                <p>All confirmed bookings</p>
                <div class="sub-info">Total system bookings</div>
            </div>
            <div class="dashboard-card">
                <h3>Aircraft Count</h3>
                <div class="number"><?php echo $aircraftCount; ?></div>
                <p>Operational aircraft</p>
                <div class="sub-info">Owned by <?php echo htmlspecialchars($adminIATA); ?></div>
            </div>
        </div>

        <!-- 统计说明 -->
        <div class="stats-note">
            <p><strong>Statistics Note:</strong></p>
            <ul style="text-align: left; display: inline-block; margin: 10px 0;">
                <li><strong>Flights:</strong> Filtered by airline <?php echo htmlspecialchars($adminIATA); ?> only</li>
                <li><strong>Bookings:</strong> All bookings in the system (not filtered by airline)</li>
                <li><strong>Aircraft:</strong> Filtered by airline <?php echo htmlspecialchars($adminIATA); ?> only</li>
            </ul>
            <p style="margin-top: 10px; font-style: italic;">
                <small>Note: Booking filtering by airline will be added when table relationships are established.</small>
            </p>
            <p style="margin-top: 10px; font-size: 0.8rem; color: #666;">
                <i class="fas fa-clock"></i> Page auto-refresh: <span id="refreshCountdown">60</span> seconds
            </p>
        </div>

        <!-- 页脚（匹配footer风格） -->
        <div class="spacing"></div>
        <footer class="footer">
            <p>Bon Avion Airline Administration System &copy; <?php echo date('Y'); ?></p>
            <p style="font-size: 0.8rem; color: #888;">Dashboard for <?php echo htmlspecialchars($adminIATA); ?> | Showing real-time counts</p>
        </footer>
    </div>
    
    <script>
        // 实时更新时间显示
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateString = now.toLocaleDateString('en-US', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }).replace(/\//g, '-');
            
            document.getElementById('currentTime').textContent = timeString;
            document.getElementById('lastUpdatedTime').textContent = dateString + ' ' + timeString;
        }
        
        // 初始化时间
        updateCurrentTime();
        
        // 每秒更新一次时间
        setInterval(updateCurrentTime, 1000);
        
        // 自动刷新数据（每60秒刷新一次页面）
        let refreshTimer = 60;
        const countdownElement = document.getElementById('refreshCountdown');
        
        const countdownInterval = setInterval(function() {
            refreshTimer--;
            countdownElement.textContent = refreshTimer;
            
            if (refreshTimer <= 0) {
                clearInterval(countdownInterval);
                window.location.reload();
            }
        }, 1000);
        
        // 刷新页面函数
        function refreshPage() {
            window.location.reload();
        }
        
        // 重置刷新倒计时
        function resetRefreshTimer() {
            refreshTimer = 60;
            countdownElement.textContent = refreshTimer;
        }
        
        // 当用户与页面交互时重置倒计时
        document.addEventListener('click', resetRefreshTimer);
        document.addEventListener('keypress', resetRefreshTimer);
        document.addEventListener('mousemove', resetRefreshTimer);
        
        // 添加点击卡片跳转功能
        document.querySelectorAll('.dashboard-card').forEach(card => {
            card.style.cursor = 'pointer';
            card.addEventListener('click', function() {
                const title = this.querySelector('h3').textContent;
                if (title.includes('Flights')) {
                    window.location.href = 'admin_flight_management.php';
                } else if (title.includes('Bookings')) {
                    window.location.href = 'admin_booking_status.php';
                } else if (title.includes('Aircraft')) {
                    window.location.href = 'airline_aircraft_schedule.php';
                }
            });
        });
        
        // 添加数字动画效果
        document.addEventListener('DOMContentLoaded', function() {
            const numbers = document.querySelectorAll('.number');
            numbers.forEach(numberElement => {
                const finalNumber = parseInt(numberElement.textContent);
                if (finalNumber > 0) {
                    let current = 0;
                    const increment = finalNumber / 50;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= finalNumber) {
                            current = finalNumber;
                            clearInterval(timer);
                        }
                        numberElement.textContent = Math.floor(current);
                    }, 30);
                }
            });
        });
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</body>
</html>