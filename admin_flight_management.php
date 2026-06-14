<?php
session_start();

// 强制登录验证
if (!isset($_SESSION['airline_admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// 数据库连接
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// 获取管理员所属航司代码
$adminIATA = '';
$stmt = $conn->prepare("SELECT IATA_airline_code FROM airline_administrator WHERE airline_admin_id = ?");
$stmt->bind_param("s", $_SESSION['airline_admin_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $adminIATA = strtoupper($row['IATA_airline_code']);
}
$stmt->close();

// 如果没有获取到航司代码，可能是无效管理员
if (empty($adminIATA)) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// 生成CSRF令牌
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 处理航班操作
$message = '';
$messageType = ''; // success, error, warning

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Security token mismatch. Please try again.";
        $messageType = "error";
    } else {
        $action = $_POST['action'] ?? '';
        $flight_id = $_POST['flight_id'] ?? '';
        
        switch ($action) {
            case 'add_flight':
                // 添加新航班
                $flight_number = strtoupper(trim($_POST['flight_number'] ?? ''));
                $departure_airport = trim($_POST['departure_airport'] ?? '');
                $arrival_airport = trim($_POST['arrival_airport'] ?? '');
                $departure_date = $_POST['departure_date'] ?? '';
                $departure_time = $_POST['departure_time'] ?? '';
                $arrival_date = $_POST['arrival_date'] ?? '';
                $arrival_time = $_POST['arrival_time'] ?? '';
                $ARN = trim($_POST['ARN'] ?? '');
                $first_class_price = floatval($_POST['first_class_price'] ?? 0);
                $business_class_price = floatval($_POST['business_class_price'] ?? 0);
                $economy_class_price = floatval($_POST['economy_class_price'] ?? 0);
                
                // 合并日期时间
                $departure_date_time = $departure_date . ' ' . $departure_time . ':00';
                $arrival_date_time = $arrival_date . ' ' . $arrival_time . ':00';
                
                // 基本验证
                if (empty($flight_number) || empty($departure_airport) || empty($arrival_airport) || 
                    empty($departure_date_time) || empty($arrival_date_time) || empty($ARN)) {
                    $message = "Please fill in all required fields.";
                    $messageType = "error";
                } else {
                    // 验证飞机是否属于该航司（如果ARN不为空）
                    if (!empty($ARN)) {
                        $checkAircraft = $conn->prepare("SELECT ARN FROM aircraft WHERE ARN = ? AND IATA_airline_code = ?");
                        $checkAircraft->bind_param("ss", $ARN, $adminIATA);
                        $checkAircraft->execute();
                        
                        if ($checkAircraft->get_result()->num_rows === 0) {
                            $message = "Selected aircraft does not belong to your airline.";
                            $messageType = "error";
                            $checkAircraft->close();
                            break;
                        }
                        $checkAircraft->close();
                    }
                    
                    // 检查航班号是否已存在（同一航司内）
                    $checkFlight = $conn->prepare("SELECT flight_id FROM flight WHERE flight_number = ? AND IATA_airline_code = ?");
                    $checkFlight->bind_param("ss", $flight_number, $adminIATA);
                    $checkFlight->execute();
                    
                    if ($checkFlight->get_result()->num_rows > 0) {
                        $message = "Flight number '{$flight_number}' already exists for your airline.";
                        $messageType = "error";
                        $checkFlight->close();
                        break;
                    }
                    $checkFlight->close();
                    
                    // 生成新的flight_id（格式：flight_number_后跟8位数字）
                    $pattern = $flight_number . '_%';
                    $maxIdResult = $conn->prepare("SELECT MAX(flight_id) as max_id FROM flight WHERE flight_id LIKE ?");
                    $maxIdResult->bind_param("s", $pattern);
                    $maxIdResult->execute();
                    $maxIdRow = $maxIdResult->get_result()->fetch_assoc();
                    $maxIdResult->close();
                    
                    if ($maxIdRow['max_id']) {
                        // 从已有的flight_id中提取数字部分
                        $max_id = $maxIdRow['max_id'];
                        $parts = explode('_', $max_id);
                        $last_number = end($parts);
                        $new_number = str_pad((int)$last_number + 1, 8, '0', STR_PAD_LEFT);
                        $new_flight_id = $flight_number . '_' . $new_number;
                    } else {
                        // 这是该航班号的第一个航班，从00000001开始
                        $new_flight_id = $flight_number . '_00000001';
                    }
                    
                    // 插入新航班 - 默认状态为 'planned'
                    $stmt = $conn->prepare("
                        INSERT INTO flight (
                            flight_id, flight_number, IATA_airline_code, departure_airport_code, 
                            arrival_airport_code, departure_date_time, arrival_date_time, 
                            ARN, first_class_price, business_class_price, economy_class_price,
                            flight_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned')
                    ");
                    
                    $stmt->bind_param(
                        "ssssssssddd", 
                        $new_flight_id, $flight_number, $adminIATA, $departure_airport,
                        $arrival_airport, $departure_date_time, $arrival_date_time,
                        $ARN, $first_class_price, $business_class_price, $economy_class_price
                    );
                    
                    if ($stmt->execute()) {
                        $message = "Flight {$flight_number} added successfully! (ID: {$new_flight_id})";
                        $messageType = "success";
                    } else {
                        $message = "Failed to add flight: " . $conn->error;
                        $messageType = "error";
                    }
                    $stmt->close();
                }
                break;
                
            case 'update_flight':
                // 更新航班
                $flight_number = strtoupper(trim($_POST['flight_number'] ?? ''));
                $departure_airport = trim($_POST['departure_airport'] ?? '');
                $arrival_airport = trim($_POST['arrival_airport'] ?? '');
                $departure_date = $_POST['departure_date'] ?? '';
                $departure_time = $_POST['departure_time'] ?? '';
                $arrival_date = $_POST['arrival_date'] ?? '';
                $arrival_time = $_POST['arrival_time'] ?? '';
                $ARN = trim($_POST['ARN'] ?? '');
                $first_class_price = floatval($_POST['first_class_price'] ?? 0);
                $business_class_price = floatval($_POST['business_class_price'] ?? 0);
                $economy_class_price = floatval($_POST['economy_class_price'] ?? 0);
                
                // 合并日期时间
                $departure_date_time = $departure_date . ' ' . $departure_time . ':00';
                $arrival_date_time = $arrival_date . ' ' . $arrival_time . ':00';
                
                // 验证权限：确保航班属于当前航司
                $old_flight_id = $_POST['flight_id'] ?? '';
                $checkPermission = $conn->prepare("SELECT flight_id, flight_number, flight_status FROM flight WHERE flight_id = ? AND IATA_airline_code = ?");
                $checkPermission->bind_param("ss", $old_flight_id, $adminIATA);
                $checkPermission->execute();
                $permissionResult = $checkPermission->get_result();
                
                if ($permissionResult->num_rows === 0) {
                    $message = "You don't have permission to modify this flight.";
                    $messageType = "error";
                    $checkPermission->close();
                    break;
                }
                
                $oldFlight = $permissionResult->fetch_assoc();
                $old_status = $oldFlight['flight_status'];
                $checkPermission->close();
                
                // 如果航班号被修改，需要生成新的flight_id
                if ($flight_number !== $oldFlight['flight_number']) {
                    // 检查新的航班号是否已存在
                    $checkFlight = $conn->prepare("SELECT flight_id FROM flight WHERE flight_number = ? AND IATA_airline_code = ? AND flight_id != ?");
                    $checkFlight->bind_param("sss", $flight_number, $adminIATA, $old_flight_id);
                    $checkFlight->execute();
                    
                    if ($checkFlight->get_result()->num_rows > 0) {
                        $message = "Flight number '{$flight_number}' already exists for another flight.";
                        $messageType = "error";
                        $checkFlight->close();
                        break;
                    }
                    $checkFlight->close();
                    
                    // 为新航班号生成新的flight_id
                    $pattern = $flight_number . '_%';
                    $maxIdResult = $conn->prepare("SELECT MAX(flight_id) as max_id FROM flight WHERE flight_id LIKE ?");
                    $maxIdResult->bind_param("s", $pattern);
                    $maxIdResult->execute();
                    $maxIdRow = $maxIdResult->get_result()->fetch_assoc();
                    $maxIdResult->close();
                    
                    if ($maxIdRow['max_id']) {
                        // 从已有的flight_id中提取数字部分
                        $max_id = $maxIdRow['max_id'];
                        $parts = explode('_', $max_id);
                        $last_number = end($parts);
                        $new_number = str_pad((int)$last_number + 1, 8, '0', STR_PAD_LEFT);
                        $new_flight_id = $flight_number . '_' . $new_number;
                    } else {
                        // 这是该航班号的第一个航班，从00000001开始
                        $new_flight_id = $flight_number . '_00000001';
                    }
                } else {
                    // 航班号未修改，保持原flight_id
                    $new_flight_id = $old_flight_id;
                }
                
                // 更新航班
                $stmt = $conn->prepare("
                    UPDATE flight SET
                        flight_id = ?,
                        flight_number = ?,
                        departure_airport_code = ?,
                        arrival_airport_code = ?,
                        departure_date_time = ?,
                        arrival_date_time = ?,
                        ARN = ?,
                        first_class_price = ?,
                        business_class_price = ?,
                        economy_class_price = ?,
                        flight_status = ?
                    WHERE flight_id = ?
                ");
                
                // 保持原来的状态
                $stmt->bind_param(
                    "ssssssssddss", 
                    $new_flight_id, $flight_number, $departure_airport, $arrival_airport,
                    $departure_date_time, $arrival_date_time,
                    $ARN, $first_class_price, $business_class_price, $economy_class_price,
                    $old_status, $old_flight_id
                );
                
                if ($stmt->execute()) {
                    $message = "Flight {$flight_number} updated successfully!";
                    $messageType = "success";
                } else {
                    $message = "Failed to update flight: " . $conn->error;
                    $messageType = "error";
                }
                $stmt->close();
                break;
                
            case 'cancel_flight':
                // 取消航班 - 更新状态为 cancelled
                if (!empty($flight_id)) {
                    // 验证权限
                    $checkPermission = $conn->prepare("SELECT flight_id FROM flight WHERE flight_id = ? AND IATA_airline_code = ?");
                    $checkPermission->bind_param("ss", $flight_id, $adminIATA);
                    $checkPermission->execute();
                    
                    if ($checkPermission->get_result()->num_rows === 0) {
                        $message = "You don't have permission to cancel this flight.";
                        $messageType = "error";
                        $checkPermission->close();
                        break;
                    }
                    $checkPermission->close();
                    
                    // 更新航班状态为 cancelled
                    $stmt = $conn->prepare("UPDATE flight SET flight_status = 'cancelled' WHERE flight_id = ?");
                    $stmt->bind_param("s", $flight_id);
                    
                    if ($stmt->execute()) {
                        $message = "Flight cancelled successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Failed to cancel flight: " . $conn->error;
                        $messageType = "error";
                    }
                    $stmt->close();
                }
                break;
                
            case 'activate_flight':
                // 重新激活已取消的航班 - 更新状态为 planned
                if (!empty($flight_id)) {
                    // 验证权限
                    $checkPermission = $conn->prepare("SELECT flight_id FROM flight WHERE flight_id = ? AND IATA_airline_code = ?");
                    $checkPermission->bind_param("ss", $flight_id, $adminIATA);
                    $checkPermission->execute();
                    
                    if ($checkPermission->get_result()->num_rows === 0) {
                        $message = "You don't have permission to modify this flight.";
                        $messageType = "error";
                        $checkPermission->close();
                        break;
                    }
                    $checkPermission->close();
                    
                    // 更新航班状态为 planned
                    $stmt = $conn->prepare("UPDATE flight SET flight_status = 'planned' WHERE flight_id = ?");
                    $stmt->bind_param("s", $flight_id);
                    
                    if ($stmt->execute()) {
                        $message = "Flight reactivated successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Failed to reactivate flight: " . $conn->error;
                        $messageType = "error";
                    }
                    $stmt->close();
                }
                break;
        }
    }
}

// 获取搜索参数
$search_type = $_GET['search_type'] ?? 'destination';
$departure_code = $_GET['departure'] ?? '';
$arrival_code = $_GET['arrival'] ?? '';
$flight_number_search = $_GET['flight_number'] ?? '';
$search_date = $_GET['date'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// 查询航班数据 - 直接从数据库读取状态
$flights = [];
$whereClauses = ["f.IATA_airline_code = ?"];
$params = [$adminIATA];
$paramTypes = "s";

// 构建查询条件
if ($search_type === 'destination' && !empty($departure_code) && !empty($arrival_code)) {
    $whereClauses[] = "f.departure_airport_code = ?";
    $whereClauses[] = "f.arrival_airport_code = ?";
    $params[] = $departure_code;
    $params[] = $arrival_code;
    $paramTypes .= "ss";
    
    if (!empty($search_date)) {
        $whereClauses[] = "DATE(f.departure_date_time) = ?";
        $params[] = $search_date;
        $paramTypes .= "s";
    }
} elseif ($search_type === 'flightNumber' && !empty($flight_number_search)) {
    $whereClauses[] = "f.flight_number LIKE ?";
    $params[] = "%$flight_number_search%";
    $paramTypes .= "s";
}

// 根据状态筛选
if ($status_filter !== 'all') {
    $whereClauses[] = "f.flight_status = ?";
    $params[] = $status_filter;
    $paramTypes .= "s";
}

// 执行查询
$query = "
    SELECT f.*, 
           d.airport_name as departure_airport_name, d.city as departure_city,
           a.airport_name as arrival_airport_name, a.city as arrival_city,
           ac.aircraft_model as aircraft_model
    FROM flight f
    LEFT JOIN airport d ON f.departure_airport_code = d.IATA_airport_code
    LEFT JOIN airport a ON f.arrival_airport_code = a.IATA_airport_code
    LEFT JOIN aircraft ac ON f.ARN = ac.ARN
    WHERE " . implode(" AND ", $whereClauses) . "
    ORDER BY f.departure_date_time DESC
";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $flights[] = $row;
    }
    $stmt->close();
}

// 获取可用的飞机列表（属于当前航司）
$aircrafts = [];
$aircraftQuery = $conn->prepare("SELECT ARN, aircraft_model FROM aircraft WHERE IATA_airline_code = ? ORDER BY aircraft_model");
$aircraftQuery->bind_param("s", $adminIATA);
$aircraftQuery->execute();
$aircraftResult = $aircraftQuery->get_result();
while ($row = $aircraftResult->fetch_assoc()) {
    $aircrafts[] = $row;
}
$aircraftQuery->close();

// 获取机场列表
$airports = [];
$airportQuery = $conn->query("SELECT IATA_airport_code, airport_name, city FROM airport ORDER BY city, airport_name");
if ($airportQuery) {
    while ($row = $airportQuery->fetch_assoc()) {
        $airports[] = $row;
    }
    $airportQuery->free();
}

// 计算状态统计
$status_stats = [
    'planned' => 0,
    'cancelled' => 0
];

foreach ($flights as $flight) {
    if ($flight['flight_status'] === 'cancelled') {
        $status_stats['cancelled']++;
    } else {
        $status_stats['planned']++;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion Airline Administration</title>
    <link rel="stylesheet" href="common_style.css">
    <style>
        .message-alert {
            max-width: 1200px;
            margin: 20px auto;
            padding: 15px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-align: center;
        }
        .message-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .stats-summary {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .stat-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
            min-width: 120px;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1e3c72;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-planned { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        /* 搜索栏统一样式 */
        .airport-select, .date-input, .flight-number-input, .status-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
            background-color: white;
            color: #333;
        }

        .airport-select:focus, .date-input:focus, .flight-number-input:focus, .status-select:focus {
            outline: none;
            border-color: #1e3c72;
            box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.1);
        }

        /* 搜索按钮样式统一 */
        .search-btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 46px;
        }

        .search-btn:hover {
            background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.2);
        }

        /* 输入组样式调整 */
        .input-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .input-group label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            margin-bottom: 2px;
        }

        /* 状态筛选样式 */
        .status-filter-container {
            max-width: 1200px;
            margin: 20px auto 0;
            padding: 0 20px;
            margin-top:100px;
        }
    </style>
</head>

<body>
    <!-- Sticky Header -->
    <div class="sticky-header">
        <div class="header-content">
            <div class="logo-container">
                <div class="airline-logo">BA</div>
                <h1 class="heading">Bon Avion Flight Management</h1>
            </div>
             <a href="maindesk.php" class="btn-back"> Main Dashboard ↩ </a>
            <div class="airline-logo" style="background: linear-gradient(135deg, #e44d26 0%, #f16529 100%);">
                <?php echo htmlspecialchars($adminIATA); ?>
            </div>
        </div>
        
        <!-- Search Bar -->
        <form id="flightSearchForm" class="search-bar" method="GET" action="">
            <!-- Search Type Toggle -->
            <div class="input-group">
                <label>Search Type</label>
                <div class="search-type-toggle">
                    <button type="button" class="search-type-btn <?php echo $search_type === 'destination' ? 'active' : ''; ?>" data-type="destination">By Route</button>
                    <button type="button" class="search-type-btn <?php echo $search_type === 'flightNumber' ? 'active' : ''; ?>" data-type="flightNumber">By Flight Number</button>
                </div>
                <input type="hidden" name="search_type" id="searchTypeInput" value="<?php echo htmlspecialchars($search_type); ?>">
            </div>
            
            <!-- Destination Search -->
            <div id="destinationSearch" class="input-group" style="<?php echo $search_type === 'flightNumber' ? 'display:none;' : ''; ?>">
                <label for="departureSelect">Departure</label>
                <select class="airport-select" id="departureSelect" name="departure">
                    <option value="">Select departure</option>
                    <?php foreach ($airports as $airport): ?>
                        <option value="<?php echo htmlspecialchars($airport['IATA_airport_code']); ?>" 
                            <?php echo ($departure_code === $airport['IATA_airport_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($airport['city'] . ' (' . $airport['IATA_airport_code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="destinationSearch2" class="input-group" style="<?php echo $search_type === 'flightNumber' ? 'display:none;' : ''; ?>">
                <label for="arrivalSelect">Arrival</label>
                <select class="airport-select" id="arrivalSelect" name="arrival">
                    <option value="">Select arrival</option>
                    <?php foreach ($airports as $airport): ?>
                        <option value="<?php echo htmlspecialchars($airport['IATA_airport_code']); ?>" 
                            <?php echo ($arrival_code === $airport['IATA_airport_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($airport['city'] . ' (' . $airport['IATA_airport_code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Departure Date -->
            <div class="input-group" style="<?php echo $search_type === 'flightNumber' ? 'display:none;' : ''; ?>">
                <label for="departureDate">Departure Date</label>
                <input type="date" class="date-input" id="departureDate" name="date" 
                       value="<?php echo htmlspecialchars($search_date); ?>">
            </div>

            <!-- Flight Number Search -->
            <div id="flightNumberSearch" class="input-group" style="<?php echo $search_type === 'destination' ? 'display:none;' : ''; ?>">
                <label for="flightNumberInput">Flight Number</label>
                <input type="text" class="flight-number-input" id="flightNumberInput" name="flight_number" 
                       placeholder="Enter flight number" 
                       value="<?php echo htmlspecialchars($flight_number_search); ?>">
            </div>

            <!-- Search Button -->
            <button type="submit" class="search-btn">Search Flights</button>
            
            <!-- Clear Button -->
            <a href="admin_flight_management.php" class="btn-secondary" style="padding: 12px 20px; text-decoration: none; display: inline-flex; align-items: center; height: 46px;">Clear</a>
        </form>
    </div>

    <!-- Status Filter -->
    <div class="status-filter-container">
        <form method="GET" class="search-bar" style="padding: 10px; margin-top: 10px;">
            <div class="input-group">
                <label for="statusFilter">Filter by Status</label>
                <select class="status-select" id="statusFilter" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="planned" <?php echo $status_filter === 'planned' ? 'selected' : ''; ?>>Planned</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <!-- 保持其他搜索参数 -->
                <input type="hidden" name="search_type" value="<?php echo htmlspecialchars($search_type); ?>">
                <input type="hidden" name="departure" value="<?php echo htmlspecialchars($departure_code); ?>">
                <input type="hidden" name="arrival" value="<?php echo htmlspecialchars($arrival_code); ?>">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($search_date); ?>">
                <input type="hidden" name="flight_number" value="<?php echo htmlspecialchars($flight_number_search); ?>">
            </div>
        </form>
    </div>

    <!-- Message Alert -->
    <?php if (!empty($message)): ?>
        <div class="message-alert message-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Summary -->
    <div class="container">
        <div class="stats-summary">
            <div class="stat-item">
                <div class="stat-number"><?php echo count($flights); ?></div>
                <div class="stat-label">Flights Found</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $status_stats['planned']; ?></div>
                <div class="stat-label">Planned</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $status_stats['cancelled']; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Results Section -->
    <div class="results-section" id="resultsSection">
        <div class="section-header">
            <h2 style="color: #1e3c72;">Flight Management - <?php echo htmlspecialchars($adminIATA); ?></h2>
            <button class="add-flight-btn" id="openAddModal">+ Add Flight</button>
        </div>
        
        <?php if (empty($flights)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <h3>No flights found</h3>
                <p><?php echo empty($departure_code) && empty($arrival_code) && empty($flight_number_search) ? 
                    'Add your first flight using the "Add Flight" button.' : 
                    'No flights match your search criteria.'; ?></p>
            </div>
        <?php else: ?>
            <div class="flight-cards" id="flightCards">
                <?php foreach ($flights as $flight): 
                    $departure_dt = new DateTime($flight['departure_date_time']);
                    $arrival_dt = new DateTime($flight['arrival_date_time']);
                    $duration_interval = $departure_dt->diff($arrival_dt);
                    $duration = $duration_interval->h . 'h ' . $duration_interval->i . 'm';
                ?>
                    <div class="flight-card">
                        <div class="flight-card-header">
                            <div class="flight-date">
                                <div class="day-month"><?php echo $departure_dt->format('d M'); ?></div>
                                <div class="year"><?php echo $departure_dt->format('Y'); ?></div>
                            </div>
                            
                            <div class="flight-number"><?php echo htmlspecialchars($flight['flight_number']); ?></div>
                            
                            <div class="flight-status">
                                <span class="status-badge status-<?php echo htmlspecialchars($flight['flight_status']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($flight['flight_status'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="flight-route">
                            <div class="airport-info">
                                <div class="airport-code"><?php echo htmlspecialchars($flight['departure_airport_code']); ?></div>
                                <div class="airport-name"><?php echo htmlspecialchars($flight['departure_city'] ?? $flight['departure_airport_code']); ?></div>
                                <div class="flight-time"><?php echo $departure_dt->format('H:i'); ?></div>
                            </div>
                            
                            <div class="plane-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="#1e3c72">
                                    <path d="M22 16v-2l-8.5-5V3.5c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5V9L2 14v2l8.5-2.5V19L8 20.5V22l4-1 4 1v-1.5L13.5 19v-5.5L22 16z"/>
                                </svg>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;"><?php echo $duration; ?></div>
                            </div>
                            
                            <div class="airport-info">
                                <div class="airport-code"><?php echo htmlspecialchars($flight['arrival_airport_code']); ?></div>
                                <div class="airport-name"><?php echo htmlspecialchars($flight['arrival_city'] ?? $flight['arrival_airport_code']); ?></div>
                                <div class="flight-time"><?php echo $arrival_dt->format('H:i'); ?></div>
                            </div>
                        </div>
                        
                        <div class="flight-details">
                            <div class="aircraft-info">
                                <?php if (!empty($flight['ARN'])): ?>
                                    <strong>Aircraft:</strong> 
                                    <?php echo htmlspecialchars($flight['ARN']); ?>
                                    <?php if (!empty($flight['aircraft_model'])): ?>
                                        • <?php echo htmlspecialchars($flight['aircraft_model']); ?>
                                    <?php endif; ?>
                                    <br>
                                <?php endif; ?>
                                <strong>Prices:</strong> 
                                £<?php echo number_format($flight['first_class_price'], 2); ?> (F) • 
                                £<?php echo number_format($flight['business_class_price'], 2); ?> (B) • 
                                £<?php echo number_format($flight['economy_class_price'], 2); ?> (E)
                            </div>
                            
                            <div>
                                <button class="modify-btn" onclick="openModifyModal(
                                    '<?php echo htmlspecialchars(addslashes($flight['flight_id'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($flight['flight_number'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($flight['departure_airport_code'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($flight['arrival_airport_code'])); ?>',
                                    '<?php echo $departure_dt->format('Y-m-d'); ?>',
                                    '<?php echo $departure_dt->format('H:i'); ?>',
                                    '<?php echo $arrival_dt->format('Y-m-d'); ?>',
                                    '<?php echo $arrival_dt->format('H:i'); ?>',
                                    '<?php echo htmlspecialchars(addslashes($flight['ARN'] ?? '')); ?>',
                                    '<?php echo $flight['first_class_price']; ?>',
                                    '<?php echo $flight['business_class_price']; ?>',
                                    '<?php echo $flight['economy_class_price']; ?>',
                                    '<?php echo htmlspecialchars(addslashes($flight['flight_status'])); ?>'
                                )">Modify</button>
                                
                                <?php if ($flight['flight_status'] === 'cancelled'): ?>
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to reactivate this flight?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="activate_flight">
                                        <input type="hidden" name="flight_id" value="<?php echo htmlspecialchars($flight['flight_id']); ?>">
                                        <button type="submit" class="btn-success" style="padding: 10px 15px; margin-left: 10px;">Reactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to cancel this flight? This will change the status to cancelled.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="cancel_flight">
                                        <input type="hidden" name="flight_id" value="<?php echo htmlspecialchars($flight['flight_id']); ?>">
                                        <button type="submit" class="btn-secondary" style="padding: 10px 15px; margin-left: 10px;">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Flight Modification/Addition Modal -->
    <div class="modal-overlay" id="flightModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Flight</h2>
                <button class="close-btn" id="closeModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="flightForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" id="formAction" value="add_flight">
                    <input type="hidden" name="flight_id" id="formFlightId" value="">
                    <input type="hidden" name="flight_status" id="formFlightStatus" value="planned">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="modalFlightNumber">Flight Number *</label>
                            <input type="text" class="form-control" id="modalFlightNumber" name="flight_number" 
                                   placeholder="e.g., AC123" required maxlength="10" pattern="[A-Z0-9]+"
                                   title="Flight number should contain only letters and numbers">
                            <small style="color: #666; font-size: 12px;">Only letters and numbers allowed</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalAirlineCode">Airline</label>
                            <input type="text" class="form-control" id="modalAirlineCode" 
                                   value="<?php echo htmlspecialchars($adminIATA); ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalDeparture">Departure Airport *</label>
                            <select class="form-control" id="modalDeparture" name="departure_airport" required>
                                <option value="">Select departure airport</option>
                                <?php foreach ($airports as $airport): ?>
                                    <option value="<?php echo htmlspecialchars($airport['IATA_airport_code']); ?>">
                                        <?php echo htmlspecialchars($airport['city'] . ' (' . $airport['IATA_airport_code'] . ') - ' . $airport['airport_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalArrival">Arrival Airport *</label>
                            <select class="form-control" id="modalArrival" name="arrival_airport" required>
                                <option value="">Select arrival airport</option>
                                <?php foreach ($airports as $airport): ?>
                                    <option value="<?php echo htmlspecialchars($airport['IATA_airport_code']); ?>">
                                        <?php echo htmlspecialchars($airport['city'] . ' (' . $airport['IATA_airport_code'] . ') - ' . $airport['airport_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                                              
                        <div class="form-group">
                            <label for="modalDepartureDate">Departure Date *</label>
                            <input type="date" class="form-control" id="modalDepartureDate" name="departure_date" required>
                        </div>

                        <div class="form-group">
                            <label for="modalDepartureTime">Departure Time *</label>
                            <input type="time" class="form-control" id="modalDepartureTime" name="departure_time" required>
                        </div>

                        <div class="form-group">
                            <label for="modalArrivalDate">Arrival Date *</label>
                            <input type="date" class="form-control" id="modalArrivalDate" name="arrival_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalArrivalTime">Arrival Time *</label>
                            <input type="time" class="form-control" id="modalArrivalTime" name="arrival_time" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalAircraft">Aircraft *</label>
                            <select class="form-control" id="modalAircraft" name="ARN" required>
                                <option value="">Select aircraft</option>
                                <?php foreach ($aircrafts as $aircraft): ?>
                                    <option value="<?php echo htmlspecialchars($aircraft['ARN']); ?>">
                                        <?php echo htmlspecialchars($aircraft['ARN'] . ' - ' . $aircraft['aircraft_model']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="modalFirstClass">First Class Price (£)</label>
                            <input type="number" class="form-control" id="modalFirstClass" name="first_class_price" 
                                   placeholder="e.g., 1200" min="0" step="0.01" value="0" >
                        </div>
                        
                        <div class="form-group">
                            <label for="modalBusinessClass">Business Class Price (£)</label>
                            <input type="number" class="form-control" id="modalBusinessClass" name="business_class_price" 
                                   placeholder="e.g., 800" min="0" step="0.01" value="0" >
                        </div>
                        
                        <div class="form-group">
                            <label for="modalEconomyClass">Economy Class Price (£)</label>
                            <input type="number" class="form-control" id="modalEconomyClass" name="economy_class_price" 
                                   placeholder="e.g., 400" min="0" step="0.01" value="0" >
                        </div>
                        
                        <!-- 状态选择（仅修改模式显示） -->
                        <div class="form-group full-width" id="statusSelection" style="display: none;">
                            <label>Flight Status</label>
                            <div class="status-toggle">
                                <button type="button" class="status-btn status-planned-btn active" onclick="setFlightStatus('planned')">Planned</button>
                                <button type="button" class="status-btn status-cancelled-btn" onclick="setFlightStatus('cancelled')">Cancelled</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="saveFlight">Save Flight</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Bon Avion Airline Administration System &copy; <?php echo date('Y'); ?></p>
        <p style="font-size: 0.8rem; color: #888;">
            Showing flights for <?php echo htmlspecialchars($adminIATA); ?> airline • 
            Total flights: <?php echo count($flights); ?> • 
            Last updated: <?php echo date('Y-m-d H:i:s'); ?>
        </p>
    </div>

    <script>
        // 设置默认日期为明天
        function setDefaultDate() {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            const dateStr = tomorrow.toISOString().split('T')[0];
            document.getElementById('modalDepartureDate').value = dateStr;
            document.getElementById('modalArrivalDate').value = dateStr;
            
            // 设置默认时间
            document.getElementById('modalDepartureTime').value = '08:00';
            document.getElementById('modalArrivalTime').value = '10:00';
        }

        // 切换搜索类型
        function toggleSearchType(type) {
            document.getElementById('searchTypeInput').value = type;
            
            // 更新按钮状态
            document.querySelectorAll('.search-type-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.type === type);
            });
            
            // 显示/隐藏相应的输入
            if (type === 'flightNumber') {
                document.getElementById('destinationSearch').style.display = 'none';
                document.getElementById('destinationSearch2').style.display = 'none';
                document.querySelector('.input-group:nth-child(4)').style.display = 'none';
                document.getElementById('flightNumberSearch').style.display = 'flex';
            } else {
                document.getElementById('destinationSearch').style.display = 'flex';
                document.getElementById('destinationSearch2').style.display = 'flex';
                document.querySelector('.input-group:nth-child(4)').style.display = 'flex';
                document.getElementById('flightNumberSearch').style.display = 'none';
            }
        }

        // 设置航班状态
        function setFlightStatus(status) {
            document.getElementById('formFlightStatus').value = status;
            
            // 更新按钮状态
            document.querySelectorAll('.status-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            if (status === 'planned') {
                document.querySelector('.status-planned-btn').classList.add('active');
            } else {
                document.querySelector('.status-cancelled-btn').classList.add('active');
            }
        }

        // 打开添加航班模态框
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Flight';
            document.getElementById('formAction').value = 'add_flight';
            document.getElementById('formFlightId').value = '';
            document.getElementById('flightForm').reset();
            
            // 隐藏状态选择
            document.getElementById('statusSelection').style.display = 'none';
            
            // 设置默认值
            setDefaultDate();
            setFlightStatus('planned');
            
            // 设置价格默认值
            document.getElementById('modalFirstClass').value = '1200';
            document.getElementById('modalBusinessClass').value = '800';
            document.getElementById('modalEconomyClass').value = '400';
            
            // 打开模态框
            document.getElementById('flightModal').classList.add('active');
        }

        // 打开修改航班模态框
        function openModifyModal(flightId, flightNumber, departure, arrival, 
                                 depDate, depTime, arrDate, arrTime, 
                                 ARN, firstPrice, businessPrice, economyPrice, flightStatus) {
            document.getElementById('modalTitle').textContent = 'Modify Flight';
            document.getElementById('formAction').value = 'update_flight';
            document.getElementById('formFlightId').value = flightId;
            
            // 显示状态选择
            document.getElementById('statusSelection').style.display = 'block';
            setFlightStatus(flightStatus);
            
            // 填充表单数据
            document.getElementById('modalFlightNumber').value = flightNumber;
            document.getElementById('modalDeparture').value = departure;
            document.getElementById('modalArrival').value = arrival;
            document.getElementById('modalDepartureDate').value = depDate;
            document.getElementById('modalDepartureTime').value = depTime;
            document.getElementById('modalArrivalDate').value = arrDate;
            document.getElementById('modalArrivalTime').value = arrTime;
            document.getElementById('modalAircraft').value = ARN;
            document.getElementById('modalFirstClass').value = firstPrice;
            document.getElementById('modalBusinessClass').value = businessPrice;
            document.getElementById('modalEconomyClass').value = economyPrice;
            
            // 打开模态框
            document.getElementById('flightModal').classList.add('active');
        }

        // 关闭模态框
        function closeModal() {
            document.getElementById('flightModal').classList.remove('active');
        }

        // 初始化事件监听器
        document.addEventListener('DOMContentLoaded', function() {
            // 搜索类型切换
            document.querySelectorAll('.search-type-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    toggleSearchType(this.dataset.type);
                });
            });

            // 添加航班按钮
            document.getElementById('openAddModal').addEventListener('click', openAddModal);

            // 模态框关闭按钮
            document.getElementById('closeModal').addEventListener('click', closeModal);
            document.getElementById('cancelModal').addEventListener('click', closeModal);

            // 点击模态框外部关闭
            document.getElementById('flightModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });

            // 设置默认日期
            setDefaultDate();
            
            // 设置今天为最小日期
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('modalDepartureDate').min = today;
            document.getElementById('modalArrivalDate').min = today;
            
            // 航班号自动大写
            document.getElementById('modalFlightNumber').addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            });
        });

        // 表单验证
        document.getElementById('flightForm').addEventListener('submit', function(e) {
            const depDate = document.getElementById('modalDepartureDate').value;
            const depTime = document.getElementById('modalDepartureTime').value;
            const arrDate = document.getElementById('modalArrivalDate').value;
            const arrTime = document.getElementById('modalArrivalTime').value;
            
            const depDateTime = new Date(depDate + 'T' + depTime);
            const arrDateTime = new Date(arrDate + 'T' + arrTime);
            
            if (arrDateTime <= depDateTime) {
                e.preventDefault();
                alert('Arrival date/time must be after departure date/time.');
                return false;
            }
            
            // 验证价格
            const firstPrice = parseFloat(document.getElementById('modalFirstClass').value);
            const businessPrice = parseFloat(document.getElementById('modalBusinessClass').value);
            const economyPrice = parseFloat(document.getElementById('modalEconomyClass').value);
            
            if (firstPrice < 0 || businessPrice < 0 || economyPrice < 0) {
                e.preventDefault();
                alert('Prices cannot be negative.');
                return false;
            }
            
            if (!(firstPrice >= businessPrice && businessPrice >= economyPrice)) {
                e.preventDefault();
                alert('Price hierarchy must be: First Class ≥ Business Class ≥ Economy Class');
                return false;
            }
            
            // 验证航班号格式
            const flightNumber = document.getElementById('modalFlightNumber').value;
            if (!/^[A-Z0-9]+$/.test(flightNumber)) {
                e.preventDefault();
                alert('Flight number should contain only letters and numbers (no spaces or special characters).');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>