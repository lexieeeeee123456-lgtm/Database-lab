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
if (!isset($_SESSION['airline_admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// 获取航空公司IATA代码
$adminIATA = '';
$navInitials = 'AC'; // 默认显示AC

if (isset($_SESSION['airline_admin_id'])) {
    $stmt = $conn->prepare("SELECT IATA_airline_code FROM airline_administrator WHERE airline_admin_id = ?");
    $stmt->bind_param("s", $_SESSION['airline_admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $adminIATA = strtoupper($row['IATA_airline_code']);
        $navInitials = substr($adminIATA, 0, 2); // 取前两个字符作为徽标
    }
    $stmt->close();
}

// 初始化筛选变量
$filter_aircraft = isset($_GET['aircraft']) ? $_GET['aircraft'] : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$where_conditions = [];
$params = [];
$param_types = "";

// 基础查询条件：按航空公司筛选
$where_conditions[] = "f.IATA_airline_code = ?";
$params[] = $adminIATA;
$param_types .= "s";

// 应用飞机型号筛选
if (!empty($filter_aircraft)) {
    $where_conditions[] = "a.aircraft_model = ?";
    $params[] = $filter_aircraft;
    $param_types .= "s";
}

// 应用日期筛选
if (!empty($filter_date)) {
    $where_conditions[] = "DATE(f.departure_date_time) = ?";
    $params[] = $filter_date;
    $param_types .= "s";
}

// 构建WHERE子句
$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(" AND ", $where_conditions);
}

// 开始计时
$start_time = microtime(true);

// 执行查询
$query = "
    SELECT 
        f.flight_number,
        f.departure_date_time,
        f.arrival_date_time,
        a.aircraft_model,
        a.ARN,
        dep.IATA_airport_code AS dep_code,
        dep.airport_name AS dep_name,
        dep.city AS dep_city,
        arr.IATA_airport_code AS arr_code,
        arr.airport_name AS arr_name,
        arr.city AS arr_city
    FROM flight f
    INNER JOIN aircraft a ON f.ARN = a.ARN
    INNER JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
    INNER JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
    $where_sql
    ORDER BY f.departure_date_time ASC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 结束计时
$end_time = microtime(true);
$query_time = round($end_time - $start_time, 3);

// 获取飞机型号列表用于筛选下拉框
$aircraft_models_query = "
    SELECT DISTINCT aircraft_model 
    FROM aircraft 
    WHERE IATA_airline_code = ?
    ORDER BY aircraft_model
";
$aircraft_stmt = $conn->prepare($aircraft_models_query);
$aircraft_stmt->bind_param("s", $adminIATA);
$aircraft_stmt->execute();
$aircraft_models_result = $aircraft_stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Aircraft Management</title>
    <link rel="stylesheet" href="common_style.css">
</head>
<body>
    <div class="sticky-header">
        <div class="header-content">
            <div class="logo-container">
                <div class="airline-logo">BA</div>
                <h1 class="heading">Bon Avion Aircraft Schedule</h1>
            </div>
            <a href="maindesk.php" class="btn-back"> Main Dashboard ↩ </a>  
            <div class="airline-logo" style="background: linear-gradient(135deg, #e44d26 0%, #f16529 100%);">
                <?php echo htmlspecialchars($navInitials); ?>
            </div>
        </div>
    </div>

    <!-- Aircraft Schedule Page -->
    <div class="container">
        <div id="aircraft-schedule">
            <div class="page-header">
                <h1>Aircraft Management - <?php echo htmlspecialchars($adminIATA); ?></h1>
                <p>View aircraft utilization and schedules</p>
            </div>
        
        <div class="form-container">
            <h3>View Aircraft Schedule</h3><br>
            <form id="schedule-filter-form" method="GET" action="airline_aircraft_schedule.php">
                <div class="flight-form">
                    <div class="form-group">
                        <label for="filter-aircraft">Aircraft Model</label>
                        <select id="filter-aircraft" name="aircraft" class="form-control">
                            <option value="">All Aircraft Models</option>
                            <?php while($row = $aircraft_models_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($row['aircraft_model']); ?>" 
                                    <?php echo ($filter_aircraft == $row['aircraft_model']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['aircraft_model']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-date">Date</label>
                        <input type="date" id="filter-date" name="date" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                    <div class="form-group full-width">
                        <button type="submit" class="btn-login-form">Search</button>
                        <?php if (!empty($filter_aircraft) || !empty($filter_date)): ?>
                            <a href="airline_aircraft_schedule.php" class="btn-secondary" style="margin-left: 10px; padding: 12px 25px;">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
                
        <br><br>
        
        <?php if ($query_time > 0): ?>
        <div class="Runing_time">
            <p>The running time of this searching query is <?php echo $query_time; ?> s.</p>
            <p>Found <?php echo $result->num_rows; ?> flight(s)</p>
        </div><br>
        <?php endif; ?>

        <div class="table-container">
            <h3>Aircraft Utilization</h3>
            <p><?php echo !empty($filter_date) ? date('Y, M d', strtotime($filter_date)) : date('Y, M d'); ?></p>
            
            <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Aircraft</th>
                        <th>Flight</th>
                        <th>Route</th>
                        <th>Departure</th>
                        <th>Arrival</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $current_time = time();
                    while($row = $result->fetch_assoc()): 
                        $departure_time = strtotime($row['departure_date_time']);
                        $arrival_time = strtotime($row['arrival_date_time']);
                        
                        // 动态计算状态
                        if ($current_time < $departure_time) {
                            $status = "Scheduled";
                            $status_color = "blue";
                        } elseif ($current_time >= $departure_time && $current_time <= $arrival_time) {
                            $status = "In Flight";
                            $status_color = "green";
                        } else {
                            $status = "Completed";
                            $status_color = "gray";
                        }
                        
                        // 格式化时间显示
                        $departure_formatted = date('M d H:i', $departure_time);
                        $arrival_formatted = date('M d H:i', $arrival_time);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['aircraft_model']); ?> (<?php echo htmlspecialchars($row['ARN']); ?>)</td>
                        <td><?php echo htmlspecialchars($row['flight_number']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($row['dep_code']); ?> (<?php echo htmlspecialchars($row['dep_city']); ?>) → 
                            <?php echo htmlspecialchars($row['arr_code']); ?> (<?php echo htmlspecialchars($row['arr_city']); ?>)
                        </td>
                        <td><?php echo $departure_formatted; ?></td>
                        <td><?php echo $arrival_formatted; ?></td>
                        <td><span style="color: <?php echo $status_color; ?>; font-weight: bold;"><?php echo $status; ?></span></td>                             
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px;">
                <p style="color: #666; font-size: 16px;">No flights found for the selected criteria.</p>
                <?php if (!empty($filter_aircraft) || !empty($filter_date)): ?>
                    <p style="margin-top: 10px;">Try changing your filters or <a href="airline_aircraft_schedule.php">view all flights</a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <div class="footer">
            <p>Bon Avion Airline Administration System &copy; <?php echo date('Y'); ?></p>
            <p style="font-size: 0.8rem; color: #888;">Airline: <?php echo htmlspecialchars($adminIATA); ?> | Showing: <?php echo $result->num_rows; ?> flights</p>
        </div>
    </footer>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 设置默认日期为今天
            const dateInput = document.getElementById('filter-date');
            if (dateInput && !dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
            
            // 添加回车键提交表单
            document.querySelectorAll('.form-control').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('schedule-filter-form').submit();
                    }
                });
            });
        });
    </script>
</body>
</html>