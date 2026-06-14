<?php
$page_start_time = microtime(true);
session_start();
require_once 'membership.php';

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$isLoggedIn = isset($_SESSION['customer_id']);
$firstName = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';

$membership = null;
if ($isLoggedIn) {
    $membership = getMembershipInfo($conn, $_SESSION['customer_id']);
}

function getRemainingSeats($conn, $flight_id, $arn) {
    // Get aircraft total seats
    $sql = "SELECT first_class_seat_num, business_class_seat_num, economy_class_seat_num 
            FROM aircraft WHERE ARN = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $arn);
    $stmt->execute();
    $result = $stmt->get_result();
    $aircraft = $result->fetch_assoc();
    $stmt->close();
    
    if (!$aircraft) {
        return ['first' => 0, 'business' => 0, 'economy' => 0];
    }
    
    // Count booked tickets for each class
    $sql = "SELECT class, COUNT(*) as booked 
            FROM ticket 
            WHERE flight_id = ? 
            GROUP BY class";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $flight_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $booked = ['FIRST' => 0, 'BUSINESS' => 0, 'ECONOMY' => 0];
    while ($row = $result->fetch_assoc()) {
        $booked[$row['class']] = $row['booked'];
    }
    $stmt->close();
    
    return [
        'first' => max(0, $aircraft['first_class_seat_num'] - $booked['FIRST']),
        'business' => max(0, $aircraft['business_class_seat_num'] - $booked['BUSINESS']),
        'economy' => max(0, $aircraft['economy_class_seat_num'] - $booked['ECONOMY'])
    ];
}

function getSelectedClassSeats($remaining, $cabin) {
    switch(strtolower($cabin)) {
        case 'first class':
        case 'first':
            return $remaining['first'];
        case 'business':
            return $remaining['business'];
        default:
            return $remaining['economy'];
    }
}


$tripType = isset($_GET['tripType']) ? $_GET['tripType'] : 'roundtrip';
$fromCity = isset($_GET['from']) ? trim($_GET['from']) : '';
$toCity = isset($_GET['to']) ? trim($_GET['to']) : '';
$departDate = isset($_GET['depart']) ? $_GET['depart'] : '';
$returnDate = isset($_GET['return']) ? $_GET['return'] : '';
$cabinClass = isset($_GET['cabin']) ? $_GET['cabin'] : 'Economy';
$passengers = isset($_GET['passengers']) ? intval($_GET['passengers']) : 1;


$outboundFlights = [];
$returnFlights = [];

if (!empty($fromCity) && !empty($toCity) && !empty($departDate)) {
   
    $sql = "SELECT f.*, 
                   a.airline_name,
                   dep.airport_name as departure_airport_name, dep.city as departure_city,
                   arr.airport_name as arrival_airport_name, arr.city as arrival_city
            FROM flight f
            JOIN airline a ON f.IATA_airline_code = a.IATA_airline_code
            JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
            JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
            WHERE (dep.city LIKE ? OR dep.IATA_airport_code LIKE ?)
            AND (arr.city LIKE ? OR arr.IATA_airport_code LIKE ?)
            AND DATE(f.departure_date_time) = ?
            AND f.flight_status = 'planned'
            ORDER BY f.departure_date_time";
    
    $stmt = $conn->prepare($sql);
    $fromPattern = "%$fromCity%";
    $toPattern = "%$toCity%";
    $stmt->bind_param("sssss", $fromPattern, $fromPattern, $toPattern, $toPattern, $departDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $outboundFlights[] = $row;
    }
    $stmt->close();
    
    
    if ($tripType === 'roundtrip' && !empty($returnDate)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $toPattern, $toPattern, $fromPattern, $fromPattern, $returnDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $returnFlights[] = $row;
        }
        $stmt->close();
    }
}

// Don't close connection here, need it for getRemainingSeats


function getPrice($flight, $cabin) {
    switch(strtolower($cabin)) {
        case 'first class':
        case 'first':
            return $flight['first_class_price'];
        case 'business':
            return $flight['business_class_price'];
        default:
            return $flight['economy_class_price'];
    }
}


function formatTime($datetime) {
    return date('H:i', strtotime($datetime));
}

function formatDate($datetime) {
    return date('M d, Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Flight Results</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="css/main.css">
    <style>
        body { background: var(--light); font-family: 'DM Sans', sans-serif; }

        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 1.1rem;
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(0,0,0,0.25);
            text-decoration: none;
            backdrop-filter: blur(4px);
            transition: 0.2s ease;
            z-index: 100;
        }
        .back-btn:hover { background: rgba(0,0,0,0.4); }

        .hero-banner {
            background: linear-gradient(135deg, #0a1628, var(--navy), var(--blue));
            padding: 60px 5%;
            text-align: center;
            position: relative;
        }
        .hero-banner h1 {
            color: #fff;
            font-family: 'Playfair Display', serif;
            font-size: 2.4rem;
            margin-bottom: 10px;
        }
        .hero-banner p { color: var(--gold); }
        .search-summary {
            color: rgba(255,255,255,0.8);
            margin-top: 15px;
            font-size: 1.1rem;
        }

        .results {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .section-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title i { color: var(--gold); }

        .flight-card {
            background: #fff;
            padding: 24px;
            border-radius: 18px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            border-left: 6px solid var(--blue);
            transition: transform 0.2s;
        }
        .flight-card:hover {
            transform: translateY(-3px);
        }
        .flight-card.return-flight {
            border-left-color: var(--gold);
        }

        .flight-info {
            display: flex;
            align-items: center;
            gap: 30px;
        }
        .airline-logo {
            width: 50px;
            height: 50px;
            background: var(--light);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--navy);
        }
        .flight-details { flex: 1; }
        .time {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text);
        }
        .route {
            font-size: 0.9rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .airline-name {
            font-weight: 600;
            color: var(--navy);
            margin-top: 8px;
        }
        .flight-number {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .price-section {
            text-align: right;
        }
        .price {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--navy);
        }
        .price-original {
            font-size: 0.95rem;
            color: #999;
            text-decoration: line-through;
        }
        .price-discount {
            font-size: 0.75rem;
            color: #e67e22;
            font-weight: 600;
        }
        .price-note {
            font-size: 0.8rem;
            color: var(--muted);
        }
        .book-btn {
            margin-top: 12px;
            padding: 12px 28px;
            border: none;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .book-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(30,60,114,0.3);
        }
        .book-btn.sold-out {
            background: #ccc;
            cursor: not-allowed;
        }
        .book-btn.sold-out:hover {
            transform: none;
            box-shadow: none;
        }

        .seats-left {
            font-size: 0.85rem;
            color: #27ae60;
            margin-top: 6px;
        }
        .seats-left i {
            margin-right: 4px;
        }
        .seats-left.low {
            color: #e74c3c;
            font-weight: 600;
        }

        .no-flights {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 18px;
            color: var(--muted);
        }
        .no-flights i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        .no-flights h3 {
            color: var(--text);
            margin-bottom: 10px;
        }

        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            margin: 40px 0;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Search</a>

    <div class="hero-banner">
        <h1><?php echo $tripType === 'roundtrip' ? 'Round-Trip Flights' : 'One-Way Flights'; ?></h1>
        <p>Find the best flights for your journey ✈️</p>
        <?php if (!empty($fromCity) && !empty($toCity)): ?>
        <div class="search-summary">
            <?php echo htmlspecialchars($fromCity); ?> → <?php echo htmlspecialchars($toCity); ?>
            <?php if ($tripType === 'roundtrip'): ?> → <?php echo htmlspecialchars($fromCity); ?><?php endif; ?>
            &nbsp;|&nbsp; <?php echo $passengers; ?> passenger(s) &nbsp;|&nbsp; <?php echo htmlspecialchars($cabinClass); ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="results">
        <!-- 出发航班 -->
        <div class="section-title">
            <i class="fa-solid fa-plane-departure"></i>
            Outbound Flights
            <?php if (!empty($departDate)): ?>
                <span style="font-weight: normal; color: var(--muted);">- <?php echo formatDate($departDate); ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($outboundFlights)): ?>
            <div class="no-flights">
                <i class="fa-solid fa-plane-slash"></i>
                <h3>No flights found</h3>
                <p>Try adjusting your search criteria or selecting different dates.</p>
            </div>
        <?php else: ?>
            <?php foreach ($outboundFlights as $flight): 
                $remaining = getRemainingSeats($conn, $flight['flight_id'], $flight['ARN']);
                $seatsLeft = getSelectedClassSeats($remaining, $cabinClass);
            ?>
                <div class="flight-card">
                    <div class="flight-info">
                        <div class="airline-logo">
                            <?php echo htmlspecialchars($flight['IATA_airline_code']); ?>
                        </div>
                        <div class="flight-details">
                            <div class="time">
                                <?php echo formatTime($flight['departure_date_time']); ?> → <?php echo formatTime($flight['arrival_date_time']); ?>
                            </div>
                            <div class="route">
                                <?php echo htmlspecialchars($flight['departure_city']); ?> (<?php echo $flight['departure_airport_code']; ?>) → 
                                <?php echo htmlspecialchars($flight['arrival_city']); ?> (<?php echo $flight['arrival_airport_code']; ?>)
                            </div>
                            <div class="airline-name"><?php echo htmlspecialchars($flight['airline_name']); ?></div>
                            <div class="flight-number"><?php echo htmlspecialchars($flight['flight_number']); ?></div>
                            <div class="seats-left <?php echo $seatsLeft <= 5 ? 'low' : ''; ?>">
                                <i class="fa-solid fa-chair"></i> <?php echo $seatsLeft; ?> seats left
                            </div>
                        </div>
                    </div>
                    <div class="price-section">
                        <?php 
                        $originalPrice = getPrice($flight, $cabinClass);
                        if ($membership && $membership['discount_percentage'] > 0):
                            $finalPrice = $originalPrice * $membership['discount_rate'];
                        ?>
                            <div class="price-original">$<?php echo number_format($originalPrice, 2); ?></div>
                            <div class="price">$<?php echo number_format($finalPrice, 2); ?></div>
                            <div class="price-discount"><?php echo $membership['membership_name']; ?> <?php echo $membership['discount_percentage']; ?>% OFF</div>
                        <?php else: ?>
                            <div class="price">$<?php echo number_format($originalPrice, 2); ?></div>
                        <?php endif; ?>
                        <div class="price-note">per person · <?php echo htmlspecialchars($cabinClass); ?></div>
                        <?php if ($seatsLeft >= $passengers): ?>
                            <button class="book-btn" onclick="bookFlight('<?php echo $flight['flight_id']; ?>')">
                                Select <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        <?php else: ?>
                            <button class="book-btn sold-out" disabled>Sold Out</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($tripType === 'roundtrip'): ?>
            <div class="divider"></div>
            
            <!-- 返程航班 -->
            <div class="section-title">
                <i class="fa-solid fa-plane-arrival"></i>
                Return Flights
                <?php if (!empty($returnDate)): ?>
                    <span style="font-weight: normal; color: var(--muted);">- <?php echo formatDate($returnDate); ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($returnFlights)): ?>
                <div class="no-flights">
                    <i class="fa-solid fa-plane-slash"></i>
                    <h3>No return flights found</h3>
                    <p>Try selecting a different return date.</p>
                </div>
            <?php else: ?>
                <?php foreach ($returnFlights as $flight): 
                    $remaining = getRemainingSeats($conn, $flight['flight_id'], $flight['ARN']);
                    $seatsLeft = getSelectedClassSeats($remaining, $cabinClass);
                ?>
                    <div class="flight-card return-flight">
                        <div class="flight-info">
                            <div class="airline-logo">
                                <?php echo htmlspecialchars($flight['IATA_airline_code']); ?>
                            </div>
                            <div class="flight-details">
                                <div class="time">
                                    <?php echo formatTime($flight['departure_date_time']); ?> → <?php echo formatTime($flight['arrival_date_time']); ?>
                                </div>
                                <div class="route">
                                    <?php echo htmlspecialchars($flight['departure_city']); ?> (<?php echo $flight['departure_airport_code']; ?>) → 
                                    <?php echo htmlspecialchars($flight['arrival_city']); ?> (<?php echo $flight['arrival_airport_code']; ?>)
                                </div>
                                <div class="airline-name"><?php echo htmlspecialchars($flight['airline_name']); ?></div>
                                <div class="flight-number"><?php echo htmlspecialchars($flight['flight_number']); ?></div>
                                <div class="seats-left <?php echo $seatsLeft <= 5 ? 'low' : ''; ?>">
                                    <i class="fa-solid fa-chair"></i> <?php echo $seatsLeft; ?> seats left
                                </div>
                            </div>
                        </div>
                        <div class="price-section">
                            <?php 
                            $originalPrice = getPrice($flight, $cabinClass);
                            if ($membership && $membership['discount_percentage'] > 0):
                                $finalPrice = $originalPrice * $membership['discount_rate'];
                            ?>
                                <div class="price-original">$<?php echo number_format($originalPrice, 2); ?></div>
                                <div class="price">$<?php echo number_format($finalPrice, 2); ?></div>
                                <div class="price-discount"><?php echo $membership['membership_name']; ?> <?php echo $membership['discount_percentage']; ?>% OFF</div>
                            <?php else: ?>
                                <div class="price">$<?php echo number_format($originalPrice, 2); ?></div>
                            <?php endif; ?>
                            <div class="price-note">per person · <?php echo htmlspecialchars($cabinClass); ?></div>
                            <?php if ($seatsLeft >= $passengers): ?>
                                <button class="book-btn" onclick="bookFlight('<?php echo $flight['flight_id']; ?>')">
                                    Select <i class="fa-solid fa-arrow-right"></i>
                                </button>
                            <?php else: ?>
                                <button class="book-btn sold-out" disabled>Sold Out</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php $conn->close(); ?>

    <script>
        // 从 localStorage 获取搜索数据并更新 URL
        document.addEventListener('DOMContentLoaded', function() {
            const searchData = localStorage.getItem('flightSearchData');
            if (searchData && !window.location.search) {
                const data = JSON.parse(searchData);
                
                // 解析日期
                let departDate = '';
                let returnDate = '';
                
                if (data.dates) {
                    if (data.dates.includes(' - ')) {
                        // 往返
                        const parts = data.dates.split(' - ');
                        departDate = parseDate(parts[0]);
                        returnDate = parseDate(parts[1]);
                    } else {
                        // 单程
                        departDate = parseDate(data.dates);
                    }
                }
                
                // 解析乘客数
                let passengers = 1;
                if (data.travelers) {
                    const match = data.travelers.match(/(\d+)/);
                    if (match) passengers = match[1];
                }
                
                // 重定向到带参数的 URL
                const params = new URLSearchParams({
                    tripType: data.tripType || 'roundtrip',
                    from: data.from || '',
                    to: data.to || '',
                    depart: departDate,
                    return: returnDate,
                    cabin: data.cabin || 'Economy',
                    passengers: passengers
                });
                
                window.location.href = 'flightResults.php?' + params.toString();
            }
        });
        
        function parseDate(dateStr) {
            // 解析 "Dec 15, 2025" 格式
            const months = {
                'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
                'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
                'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
            };
            
            const match = dateStr.match(/(\w+)\s+(\d+),\s+(\d+)/);
            if (match) {
                const month = months[match[1]] || '01';
                const day = match[2].padStart(2, '0');
                const year = match[3];
                return `${year}-${month}-${day}`;
            }
            return '';
        }
        
        function bookFlight(flightId) {
            <?php if (!$isLoggedIn): ?>
                alert('Please login to book a flight');
                window.location.href = 'login.php';
            <?php else: ?>
                window.location.href = 'booking.php?flight_id=' + flightId + '&cabin=<?php echo urlencode($cabinClass); ?>&passengers=<?php echo $passengers; ?>';
            <?php endif; ?>
        }
    </script>
    <div style="text-align: center; padding: 10px; font-size: 12px; color: #888; background: #f5f5f5; border-top: 1px solid #ddd;">
        <?php 
        $page_end_time = microtime(true);
        $execution_time = ($page_end_time - $page_start_time) * 1000;
        echo "Page generated in " . number_format($execution_time, 2) . " ms";
        ?>
    </div>
</body>
</html>
