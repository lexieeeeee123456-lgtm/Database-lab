<?php
session_start();
require_once 'avatar_helper.php';

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

$navAvatarDisplay = '';
$navInitials = '';
if ($isLoggedIn) {
    // Use optimized avatar helper with caching and compression
    $avatarData = getNavAvatar($conn, $_SESSION['customer_id']);
    $navAvatarDisplay = $avatarData['display'];
    $navInitials = $avatarData['initials'];
}


$cityAirports = [
    'Tokyo' => ['NRT', 'HND'],
    'Seoul' => ['ICN', 'GMP'],
    'Paris' => ['CDG', 'ORY'],
    'New York' => ['JFK', 'LGA', 'EWR'],
    'Bangkok' => ['BKK', 'DMK'],
    'Singapore' => ['SIN']
];


$selectedCity = isset($_GET['city']) ? $_GET['city'] : '';
$flights = [];

if (!empty($selectedCity) && isset($cityAirports[$selectedCity])) {
    $airports = $cityAirports[$selectedCity];
    $placeholders = implode(',', array_fill(0, count($airports), '?'));
    $today = date('Y-m-d');
    
    $sql = "SELECT f.*, 
                   a.airline_name,
                   dep.airport_name as departure_airport_name, dep.city as departure_city,
                   arr.airport_name as arrival_airport_name, arr.city as arrival_city
            FROM flight f
            JOIN airline a ON f.IATA_airline_code = a.IATA_airline_code
            JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
            JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
            WHERE f.arrival_airport_code IN ($placeholders)
            AND DATE(f.departure_date_time) = ?
            AND f.departure_date_time > NOW()
            AND f.flight_status = 'planned'
            ORDER BY f.departure_date_time ASC
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    
    
    $types = str_repeat('s', count($airports)) . 's';
    $params = array_merge($airports, [$today]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $flights[] = $row;
    }
    $stmt->close();
}

$conn->close();

function formatTime($datetime) {
    return date('H:i', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Recommendations</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .page-wrapper {
            min-height: calc(100vh - 120px);
            background: #f0f4f8;
        }
        .page-banner {
            height: 160px;
            background: linear-gradient(135deg, var(--deep), var(--navy), var(--blue));
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #fff;
        }
        .page-banner h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            margin: 0;
        }
        .page-banner p {
            margin-top: 8px;
            color: rgba(255,255,255,0.75);
        }
        .dest-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .dest-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }
        .dest-card {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: transform 0.25s, box-shadow 0.25s;
            cursor: pointer;
        }
        .dest-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .dest-card.active {
            border: 3px solid var(--gold);
        }
        .dest-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }
        .dest-info {
            padding: 18px 20px;
        }
        .dest-info h3 {
            margin: 0 0 8px;
            font-size: 1.2rem;
            color: var(--text);
        }
        .dest-info p {
            margin: 0 0 15px;
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }
        .view-btn {
            display: inline-block;
            padding: 9px 18px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            text-decoration: none;
            border-radius: 20px;
            font-size: 14px;
            transition: opacity 0.3s;
        }
        .view-btn:hover {
            opacity: 0.9;
        }

        .flights-section {
            margin-top: 40px;
            padding-top: 40px;
            border-top: 2px solid #e0e0e0;
        }
        .flights-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            color: var(--navy);
            margin-bottom: 10px;
        }
        .flights-subtitle {
            color: var(--muted);
            margin-bottom: 25px;
        }
        .flight-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            border-left: 5px solid var(--blue);
            transition: transform 0.2s;
        }
        .flight-card:hover {
            transform: translateX(5px);
        }
        .flight-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .airline-code {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .flight-details .time {
            font-size: 1.3rem;
            font-weight: 600;
        }
        .flight-details .route {
            font-size: 0.85rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .flight-details .airline-name {
            font-weight: 500;
            color: var(--navy);
            margin-top: 6px;
        }
        .price-area {
            text-align: right;
        }
        .price-area .price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--navy);
        }
        .price-area .price-note {
            font-size: 0.8rem;
            color: var(--muted);
        }
        .book-btn {
            margin-top: 10px;
            padding: 10px 24px;
            border: none;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .book-btn:hover {
            transform: translateY(-2px);
        }

        .no-flights {
            text-align: center;
            padding: 40px;
            background: #fff;
            border-radius: 12px;
            color: var(--muted);
        }
        .no-flights i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
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
            <li><a href="recommendation.php" class="nav-link active">Recommendation</a></li>
            <li><a href="AboutUs.php" class="nav-link">About Us</a></li>
            <li><a href="profile.php" class="nav-link">Profile</a></li>
        </ul>
    </nav>

    <div class="page-wrapper">
        <div class="page-banner">
            <h1>Recommendations</h1>
            <p>Our top picks for your next adventure</p>
        </div>

        <div class="dest-container">
            <div class="dest-grid">
                <div class="dest-card <?php echo $selectedCity === 'Tokyo' ? 'active' : ''; ?>" onclick="selectCity('Tokyo')">
                    <img src="image/Tokyo.webp" class="dest-img" alt="Tokyo">
                    <div class="dest-info">
                        <h3>Tokyo, Japan</h3>
                        <p>Modern skyscrapers, anime culture, shrines, and endless food options.</p>
                        <a href="recommendation.php?city=Tokyo" class="view-btn">View Flights</a>
                    </div>
                </div>
                <div class="dest-card <?php echo $selectedCity === 'Seoul' ? 'active' : ''; ?>" onclick="selectCity('Seoul')">
                    <img src="image/seoul.avif" class="dest-img" alt="Seoul">
                    <div class="dest-info">
                        <h3>Seoul, South Korea</h3>
                        <p>A perfect blend of tradition and pop culture, shopping and street food.</p>
                        <a href="recommendation.php?city=Seoul" class="view-btn">View Flights</a>
                    </div>
                </div>
                <div class="dest-card <?php echo $selectedCity === 'Paris' ? 'active' : ''; ?>" onclick="selectCity('Paris')">
                    <img src="image/Paris.webp" class="dest-img" alt="Paris">
                    <div class="dest-info">
                        <h3>Paris, France</h3>
                        <p>City of romance, iconic monuments, art museums, and world-class cuisine.</p>
                        <a href="recommendation.php?city=Paris" class="view-btn">View Flights</a>
                    </div>
                </div>
                <div class="dest-card <?php echo $selectedCity === 'New York' ? 'active' : ''; ?>" onclick="selectCity('New York')">
                    <img src="image/Newyork.avif" class="dest-img" alt="New York">
                    <div class="dest-info">
                        <h3>New York, USA</h3>
                        <p>The city that never sleeps — culture, fashion, and unforgettable landmarks.</p>
                        <a href="recommendation.php?city=New York" class="view-btn">View Flights</a>
                    </div>
                </div>
                <div class="dest-card <?php echo $selectedCity === 'Bangkok' ? 'active' : ''; ?>" onclick="selectCity('Bangkok')">
                    <img src="image/Bangkok.avif" class="dest-img" alt="Bangkok">
                    <div class="dest-info">
                        <h3>Bangkok, Thailand</h3>
                        <p>Street food paradise, temples, markets, and vibrant nightlife.</p>
                        <a href="recommendation.php?city=Bangkok" class="view-btn">View Flights</a>
                    </div>
                </div>
                <div class="dest-card <?php echo $selectedCity === 'Singapore' ? 'active' : ''; ?>" onclick="selectCity('Singapore')">
                    <img src="image/Singapore.avif" class="dest-img" alt="Singapore">
                    <div class="dest-info">
                        <h3>Singapore</h3>
                        <p>Clean, modern, and exciting—famous for landmarks and diverse food.</p>
                        <a href="recommendation.php?city=Singapore" class="view-btn">View Flights</a>
                    </div>
                </div>
            </div>

            <?php if (!empty($selectedCity)): ?>
            <div class="flights-section" id="flightsSection">
                <h2 class="flights-title">
                    <i class="fa-solid fa-plane"></i> Flights to <?php echo htmlspecialchars($selectedCity); ?>
                </h2>
                <p class="flights-subtitle">Available flights for today (<?php echo date('M d, Y'); ?>)</p>

                <?php if (empty($flights)): ?>
                    <div class="no-flights">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <h3>No flights available today</h3>
                        <p>Try searching for different dates on our <a href="index.php">home page</a>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($flights as $flight): ?>
                        <div class="flight-card">
                            <div class="flight-info">
                                <div class="airline-code">
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
                                    <div class="airline-name">
                                        <?php echo htmlspecialchars($flight['airline_name']); ?> · <?php echo htmlspecialchars($flight['flight_number']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="price-area">
                                <div class="price">$<?php echo number_format($flight['economy_class_price'], 2); ?></div>
                                <div class="price-note">Economy · per person</div>
                                <button class="book-btn" onclick="bookFlight('<?php echo $flight['flight_id']; ?>')">
                                    Book Now
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function selectCity(city) {
            window.location.href = 'recommendation.php?city=' + encodeURIComponent(city);
        }

        function bookFlight(flightId) {
            <?php if (!$isLoggedIn): ?>
                alert('Please login to book a flight');
                window.location.href = 'login.php';
            <?php else: ?>
                window.location.href = 'booking.php?flight_id=' + flightId;
            <?php endif; ?>
        }

        <?php if (!empty($selectedCity)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                document.getElementById('flightsSection').scrollIntoView({ behavior: 'smooth' });
            }, 300);
        });
        <?php endif; ?>
    </script>
</body>
</html>