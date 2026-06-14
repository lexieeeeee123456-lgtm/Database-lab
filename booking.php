<?php
session_start();
require_once 'membership.php';

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$customer_id = $_SESSION['customer_id'];
$firstName = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';

$flight_id = isset($_GET['flight_id']) ? $_GET['flight_id'] : '';
$cabin = isset($_GET['cabin']) ? $_GET['cabin'] : 'Economy';
$passengers = isset($_GET['passengers']) ? intval($_GET['passengers']) : 1;

$flight = null;
$error = '';

// Get flight info
if (!empty($flight_id)) {
    $stmt = $conn->prepare("
        SELECT f.*, 
               a.airline_name,
               dep.airport_name as departure_airport_name, dep.city as departure_city,
               arr.airport_name as arrival_airport_name, arr.city as arrival_city,
               ac.aircraft_model
        FROM flight f
        JOIN airline a ON f.IATA_airline_code = a.IATA_airline_code
        JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
        JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
        JOIN aircraft ac ON f.ARN = ac.ARN
        WHERE f.flight_id = ?
    ");
    $stmt->bind_param("s", $flight_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $flight = $result->fetch_assoc();
    $stmt->close();
}

// Get membership info
$membership = getMembershipInfo($conn, $customer_id);

// Get customer info
$stmt = $conn->prepare("SELECT * FROM customer WHERE customer_id = ?");
$stmt->bind_param("s", $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate price
function getFlightPrice($flight, $cabin) {
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

$originalPrice = $flight ? getFlightPrice($flight, $cabin) * $passengers : 0;
$discountInfo = applyMembershipDiscount($conn, $customer_id, $originalPrice);
$finalPrice = $discountInfo['final_price'];

// Process booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    if (!$flight) {
        $error = 'Flight not found';
    } else {
        // Generate PNR (6 characters)
        $pnr = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        
        // Create booking
        $stmt = $conn->prepare("INSERT INTO booking (PNR, customer_id, total_amount, booking_date, booking_status) VALUES (?, ?, ?, NOW(), 'PENDING')");
        $stmt->bind_param("ssd", $pnr, $customer_id, $originalPrice);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Create ticket(s)
            $cabinCode = strtoupper($cabin);
            if ($cabinCode == 'FIRST CLASS') $cabinCode = 'FIRST';
            
            for ($i = 0; $i < $passengers; $i++) {
                // Generate ETKT code (13 digits)
                $etkt = $flight['IATA_airline_code'] . date('ymd') . rand(1000000, 9999999);
                
                $ticketStmt = $conn->prepare("INSERT INTO ticket (ETKT_code, PNR, customer_id, flight_id, class) VALUES (?, ?, ?, ?, ?)");
                $ticketStmt->bind_param("sssss", $etkt, $pnr, $customer_id, $flight_id, $cabinCode);
                $ticketStmt->execute();
                $ticketStmt->close();
            }
            
            // Redirect to payment
            header("Location: payment.php?pnr=" . $pnr);
            exit();
        } else {
            $error = 'Failed to create booking. Please try again.';
            $stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Booking - Bon Avion</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        :root {
            --navy: #1e3c72;
            --blue: #2a5298;
            --gold: #c9a962;
            --light: #f5f7fa;
        }
        body { background: var(--light); font-family: 'Segoe UI', Arial, sans-serif; margin: 0; }
        
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 1rem;
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(0,0,0,0.25);
            text-decoration: none;
            z-index: 100;
        }
        .back-btn:hover { background: rgba(0,0,0,0.4); }
        
        .hero-banner {
            background: linear-gradient(135deg, #0a1628, var(--navy), var(--blue));
            padding: 50px 5%;
            text-align: center;
            position: relative;
        }
        .hero-banner h1 { color: #fff; font-size: 2rem; margin-bottom: 8px; }
        .hero-banner p { color: var(--gold); }
        
        .container { max-width: 700px; margin: 30px auto; padding: 0 20px; }
        
        .card {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 13px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .flight-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .airline-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .flight-title { font-size: 1.3rem; font-weight: 600; color: var(--navy); }
        .flight-subtitle { color: #666; font-size: 14px; margin-top: 4px; }
        
        .flight-route {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        .route-point { text-align: center; }
        .route-time { font-size: 1.5rem; font-weight: 600; color: var(--navy); }
        .route-city { font-size: 14px; color: #666; margin-top: 4px; }
        .route-airport { font-size: 12px; color: #999; }
        .route-arrow { font-size: 24px; color: var(--gold); }
        
        .flight-meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        .meta-item { text-align: center; }
        .meta-label { font-size: 12px; color: #888; }
        .meta-value { font-weight: 600; color: #333; margin-top: 4px; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #666; }
        .info-value { font-weight: 500; }
        
        .price-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
        }
        .price-row.total {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--navy);
            border-top: 2px solid var(--navy);
            margin-top: 10px;
            padding-top: 15px;
        }
        .price-original { text-decoration: line-through; color: #999; }
        .discount-tag {
            background: #fff3e0;
            color: #e67e22;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .membership-banner {
            background: linear-gradient(135deg, var(--gold), #b8860b);
            color: #fff;
            padding: 12px 16px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .membership-banner.platinum { background: linear-gradient(135deg, #545478, #7f7fa5); color: #fff; }
        .membership-banner.gold { background: linear-gradient(135deg, #c9a962, #b87333); }
        .membership-banner.silver { background: linear-gradient(135deg, #c0c0c0, #808080); color: #333; }
        .membership-banner.member { background: linear-gradient(135deg, #3498db, #2980b9); }
        
        .btn-confirm {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            will-change: transform;
        }
        .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(30,60,114,0.3); }
        
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .no-flight {
            text-align: center;
            padding: 60px 20px;
        }
        .no-flight i { font-size: 4rem; color: #ddd; margin-bottom: 20px; }
        .no-flight h3 { color: #333; margin-bottom: 10px; }
        .no-flight a { color: var(--navy); }
    </style>
</head>
<body>
    <a href="javascript:history.back()" class="back-btn">← Back</a>
    
    <div class="hero-banner">
        <h1>Confirm Your Booking</h1>
        <p>Review your flight details</p>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (!$flight): ?>
            <div class="card no-flight">
                <i class="fas fa-plane-slash"></i>
                <h3>Flight not found</h3>
                <p><a href="index.php">Search for flights</a></p>
            </div>
        <?php else: ?>
            <!-- Membership Banner -->
            <?php if ($membership['discount_percentage'] > 0): ?>
            <div class="membership-banner <?php echo strtolower($membership['membership_level']); ?>">
                <span><i class="fas fa-crown"></i> <?php echo $membership['membership_name']; ?><?php echo ($membership['membership_level'] !== 'Member') ? ' Member' : ''; ?></span>
                <span><?php echo $membership['discount_percentage']; ?>% OFF Applied</span>
            </div>
            <?php endif; ?>
            
            <!-- Flight Details -->
            <div class="card">
                <div class="card-title">Flight Details</div>
                <div class="flight-header">
                    <div class="airline-logo"><?php echo $flight['IATA_airline_code']; ?></div>
                    <div>
                        <div class="flight-title"><?php echo htmlspecialchars($flight['airline_name']); ?></div>
                        <div class="flight-subtitle"><?php echo $flight['flight_number']; ?> · <?php echo $flight['aircraft_model']; ?></div>
                    </div>
                </div>
                
                <div class="flight-route">
                    <div class="route-point">
                        <div class="route-time"><?php echo date('H:i', strtotime($flight['departure_date_time'])); ?></div>
                        <div class="route-city"><?php echo $flight['departure_city']; ?></div>
                        <div class="route-airport"><?php echo $flight['departure_airport_code']; ?></div>
                    </div>
                    <div class="route-arrow"><i class="fas fa-plane"></i></div>
                    <div class="route-point">
                        <div class="route-time"><?php echo date('H:i', strtotime($flight['arrival_date_time'])); ?></div>
                        <div class="route-city"><?php echo $flight['arrival_city']; ?></div>
                        <div class="route-airport"><?php echo $flight['arrival_airport_code']; ?></div>
                    </div>
                </div>
                
                <div class="flight-meta">
                    <div class="meta-item">
                        <div class="meta-label">Date</div>
                        <div class="meta-value"><?php echo date('M d, Y', strtotime($flight['departure_date_time'])); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Class</div>
                        <div class="meta-value"><?php echo htmlspecialchars($cabin); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Passengers</div>
                        <div class="meta-value"><?php echo $passengers; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Passenger Info -->
            <div class="card">
                <div class="card-title">Passenger Information</div>
                <div class="info-row">
                    <span class="info-label">Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['phone_number']); ?></span>
                </div>
                <?php if (!empty($customer['passport'])): ?>
                <div class="info-row">
                    <span class="info-label">Passport</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer['passport']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Price Summary -->
            <div class="card">
                <div class="card-title">Price Summary</div>
                <div class="price-row">
                    <span>Ticket Price × <?php echo $passengers; ?></span>
                    <span>$<?php echo number_format(getFlightPrice($flight, $cabin), 2); ?> × <?php echo $passengers; ?></span>
                </div>
                <div class="price-row">
                    <span>Subtotal</span>
                    <span class="<?php echo $membership['discount_percentage'] > 0 ? 'price-original' : ''; ?>">
                        $<?php echo number_format($originalPrice, 2); ?>
                    </span>
                </div>
                <?php if ($membership['discount_percentage'] > 0): ?>
                <div class="price-row">
                    <span>Member Discount</span>
                    <span class="discount-tag">-<?php echo $membership['discount_percentage']; ?>% (Save $<?php echo number_format($discountInfo['savings'], 2); ?>)</span>
                </div>
                <?php endif; ?>
                <div class="price-row total">
                    <span>Total</span>
                    <span>$<?php echo number_format($finalPrice, 2); ?></span>
                </div>
            </div>
            
            <!-- Confirm Button -->
            <form method="POST">
                <input type="hidden" name="flight_id" value="<?php echo htmlspecialchars($flight_id); ?>">
                <input type="hidden" name="cabin" value="<?php echo htmlspecialchars($cabin); ?>">
                <input type="hidden" name="passengers" value="<?php echo $passengers; ?>">
                <button type="submit" name="confirm_booking" class="btn-confirm">
                    <i class="fas fa-lock"></i> Proceed to Payment
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>