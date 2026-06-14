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

$pnr = isset($_GET['pnr']) ? $_GET['pnr'] : '';
$message = '';
$error = '';

// Luggage pricing
define('EXTRA_BAG_FEE', 75.00);
define('OVERWEIGHT_FEE', 50.00);

// Baggage allowance by class
function getBaggageAllowance($class) {
    switch(strtoupper($class)) {
        case 'FIRST':
        case 'BUSINESS':
            return ['checked_bags' => 2, 'checked_weight' => 32, 'carryon' => '10kg + 1 personal item'];
        default:
            return ['checked_bags' => 1, 'checked_weight' => 23, 'carryon' => '10kg + 1 personal item'];
    }
}

// Get booking info
$booking = null;
$flights = [];
$ticketClass = 'ECONOMY';

if (!empty($pnr)) {
    $stmt = $conn->prepare("SELECT * FROM booking WHERE PNR = ? AND customer_id = ?");
    $stmt->bind_param("ss", $pnr, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if ($booking) {
        $stmt = $conn->prepare("
            SELECT t.*, f.flight_number, f.departure_airport_code, f.arrival_airport_code,
                   f.departure_date_time, f.arrival_date_time, a.airline_name,
                   dep.city as departure_city, arr.city as arrival_city
            FROM ticket t
            JOIN flight f ON t.flight_id = f.flight_id
            JOIN airline a ON f.IATA_airline_code = a.IATA_airline_code
            JOIN airport dep ON f.departure_airport_code = dep.IATA_airport_code
            JOIN airport arr ON f.arrival_airport_code = arr.IATA_airport_code
            WHERE t.PNR = ?
        ");
        $stmt->bind_param("s", $pnr);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $flights[] = $row;
            $ticketClass = $row['class'];
        }
        $stmt->close();
    }
}

$baggageAllowance = getBaggageAllowance($ticketClass);
$membership = getMembershipInfo($conn, $customer_id);

$extraBags = isset($_POST['extra_bags']) ? intval($_POST['extra_bags']) : 0;
$overweightBags = isset($_POST['overweight_bags']) ? intval($_POST['overweight_bags']) : 0;

$originalAmount = $booking ? $booking['total_amount'] : 0;
$discountInfo = applyMembershipDiscount($conn, $customer_id, $originalAmount);
$ticketPrice = $discountInfo['final_price'];
$luggageFee = ($extraBags * EXTRA_BAG_FEE) + ($overweightBags * OVERWEIGHT_FEE);
$finalAmount = $ticketPrice + $luggageFee;

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $paymentMethod = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    
    if (empty($paymentMethod)) {
        $error = 'Please select a payment method';
    } elseif ($booking && $booking['booking_status'] === 'PENDING') {
        $paymentId = 'PAY' . date('YmdHis') . rand(100, 999);
        
        $stmt = $conn->prepare("INSERT INTO payment (payment_id, PNR, amount, payment_time) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssd", $paymentId, $pnr, $finalAmount);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            $updateStmt = $conn->prepare("UPDATE booking SET booking_status = 'CONFIRMED', total_amount = ? WHERE PNR = ?");
            $updateStmt->bind_param("ds", $finalAmount, $pnr);
            $updateStmt->execute();
            $updateStmt->close();
            
            if ($luggageFee > 0 && count($flights) > 0) {
                foreach ($flights as $flight) {
                    $luggageId = 'LUG' . date('YmdHis') . rand(100, 999);
                    $luggageStmt = $conn->prepare("INSERT INTO luggage (luggage_id, ETKT_code, additional_fee) VALUES (?, ?, ?)");
                    $luggageStmt->bind_param("ssd", $luggageId, $flight['ETKT_code'], $luggageFee);
                    $luggageStmt->execute();
                    $luggageStmt->close();
                }
            }
            
            $message = 'Payment successful! Your booking is confirmed.';
            $booking['booking_status'] = 'CONFIRMED';
        } else {
            $error = 'Payment failed. Please try again.';
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
    <title>Payment - Bon Avion</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
    <style>
        :root { --navy: #1e3c72; --blue: #2a5298; --gold: #c9a962; --light: #f5f7fa; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: var(--light); }
        
        .navbar { background: linear-gradient(135deg, var(--navy), var(--blue)); color: #fff; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav-item { color: white; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .nav-item:hover { opacity: 0.8; }
        .nav-title { font-size: 20px; font-weight: bold; }
        
        .container { max-width: 650px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .card-title { font-size: 14px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; }
        
        .flight-info { padding: 12px 0; border-bottom: 1px solid #eee; }
        .flight-info:last-child { border-bottom: none; }
        .flight-route { font-weight: 600; color: var(--navy); }
        .flight-detail { font-size: 14px; color: #666; margin-top: 4px; }
        
        .price-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .price-row:last-child { border-bottom: none; }
        .price-row.total { font-weight: 700; font-size: 1.2rem; color: var(--navy); border-top: 2px solid var(--navy); margin-top: 10px; padding-top: 15px; }
        .price-original { text-decoration: line-through; color: #999; }
        .discount-tag { background: #fff3e0; color: #e67e22; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        
        .membership-banner { background: linear-gradient(135deg, var(--gold), #b8860b); color: #fff; padding: 12px 16px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .membership-banner.platinum { background: linear-gradient(135deg, #545478, #7f7fa5); color: #fff; }
        .membership-banner.gold { background: linear-gradient(135deg, #c9a962, #b87333); }
        .membership-banner.silver { background: linear-gradient(135deg, #c0c0c0, #808080); color: #333; }
        .membership-banner.member { background: linear-gradient(135deg, #3498db, #2980b9); }
        
        .luggage-allowance { background: #e8f5e9; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .luggage-allowance h4 { color: #2e7d32; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .allowance-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 14px; color: #333; }
        .allowance-item i { color: #2e7d32; width: 20px; }
        
        .luggage-option { display: flex; justify-content: space-between; align-items: center; padding: 15px; border: 1px solid #eee; border-radius: 10px; margin-bottom: 12px; }
        .luggage-option:hover { border-color: var(--navy); }
        .luggage-label span { font-weight: 500; }
        .luggage-label small { color: #666; font-size: 12px; display: block; margin-top: 2px; }
        .luggage-price { color: var(--navy); font-weight: 600; }
        
        .qty-control { display: flex; align-items: center; gap: 12px; }
        .qty-btn { width: 32px; height: 32px; border: 1px solid #ddd; border-radius: 8px; background: #fff; cursor: pointer; font-size: 16px; color: var(--navy); }
        .qty-btn:hover { background: var(--light); }
        .qty-value { font-weight: 600; min-width: 20px; text-align: center; }
        
        .pay-option { display: flex; align-items: center; padding: 14px 16px; border: 2px solid #eee; border-radius: 12px; margin-bottom: 12px; cursor: pointer; transition: border-color 0.2s ease, background-color 0.2s ease; }
        .pay-option:hover { border-color: var(--navy); background: #f8f9ff; }
        .pay-option.selected { border-color: var(--navy); background: #f0f4ff; }
        .pay-option input { margin-right: 12px; transform: scale(1.3); }
        .pay-option img { width: 32px; height: 32px; margin-right: 12px; }
        .pay-option span { font-weight: 500; }
        
        .btn-pay { width: 100%; padding: 16px; background: linear-gradient(135deg, var(--navy), var(--blue)); color: #fff; border: none; border-radius: 12px; font-size: 18px; font-weight: 600; cursor: pointer; margin-top: 10px; transition: transform 0.2s ease, box-shadow 0.2s ease; will-change: transform; }
        .btn-pay:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(30,60,114,0.3); }
        
        .message { padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-badge.confirmed { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        
        .class-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .class-badge.first { background: #ffd700; color: #333; }
        .class-badge.business { background: #e1bee7; color: #6a1b9a; }
        .class-badge.economy { background: #bbdefb; color: #1565c0; }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <span class="nav-title">Payment</span>
        <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> <?php echo htmlspecialchars($firstName); ?></a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (!$booking): ?>
            <div class="card"><p>Booking not found. <a href="index.php">Search for flights</a></p></div>
        <?php else: ?>
            <div class="membership-banner <?php echo strtolower($membership['membership_level']); ?>">
                <span><i class="fas fa-crown"></i> <?php echo $membership['membership_name']; ?><?php echo ($membership['membership_level'] !== 'Member') ? ' Member' : ''; ?></span>
                <?php if ($membership['discount_percentage'] > 0): ?>
                    <span><?php echo $membership['discount_percentage']; ?>% OFF</span>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <div class="card-title">Booking Details</div>
                <div class="price-row"><span>PNR</span><span style="font-weight: 600;"><?php echo htmlspecialchars($pnr); ?></span></div>
                <div class="price-row"><span>Status</span><span class="status-badge <?php echo strtolower($booking['booking_status']); ?>"><?php echo $booking['booking_status']; ?></span></div>
                <div class="price-row"><span>Class</span><span class="class-badge <?php echo strtolower($ticketClass); ?>"><?php echo $ticketClass; ?></span></div>
                
                <?php foreach ($flights as $flight): ?>
                <div class="flight-info">
                    <div class="flight-route"><?php echo $flight['departure_city']; ?> (<?php echo $flight['departure_airport_code']; ?>) → <?php echo $flight['arrival_city']; ?> (<?php echo $flight['arrival_airport_code']; ?>)</div>
                    <div class="flight-detail"><?php echo $flight['airline_name']; ?> · <?php echo $flight['flight_number']; ?> · <?php echo date('M d, H:i', strtotime($flight['departure_date_time'])); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($booking['booking_status'] === 'PENDING'): ?>
            <form method="POST" id="paymentForm">
            
            <div class="card">
                <div class="card-title"><i class="fas fa-suitcase-rolling"></i> Baggage</div>
                
                <div class="luggage-allowance">
                    <h4><i class="fas fa-check-circle"></i> Free Allowance (<?php echo $ticketClass; ?>)</h4>
                    <div class="allowance-item"><i class="fas fa-suitcase"></i> Checked: <?php echo $baggageAllowance['checked_bags']; ?> × <?php echo $baggageAllowance['checked_weight']; ?>kg FREE</div>
                    <div class="allowance-item"><i class="fas fa-briefcase"></i> Carry-on: <?php echo $baggageAllowance['carryon']; ?> FREE</div>
                </div>
                
                <div class="card-title" style="margin-top: 20px;">Extra Baggage (Optional)</div>
                
                <div class="luggage-option">
                    <div class="luggage-label">
                        <span><i class="fas fa-suitcase"></i> Extra Checked Bag</span>
                        <small>Additional 23kg bag</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span class="luggage-price">$<?php echo number_format(EXTRA_BAG_FEE, 2); ?></span>
                        <div class="qty-control">
                            <button type="button" class="qty-btn" onclick="updateQty('extra_bags', -1)">−</button>
                            <span class="qty-value" id="extra_bags_display"><?php echo $extraBags; ?></span>
                            <button type="button" class="qty-btn" onclick="updateQty('extra_bags', 1)">+</button>
                        </div>
                        <input type="hidden" name="extra_bags" id="extra_bags" value="<?php echo $extraBags; ?>">
                    </div>
                </div>
                
                <div class="luggage-option">
                    <div class="luggage-label">
                        <span><i class="fas fa-weight-hanging"></i> Overweight Bag</span>
                        <small>Bag over <?php echo $baggageAllowance['checked_weight']; ?>kg (max 32kg)</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span class="luggage-price">$<?php echo number_format(OVERWEIGHT_FEE, 2); ?></span>
                        <div class="qty-control">
                            <button type="button" class="qty-btn" onclick="updateQty('overweight_bags', -1)">−</button>
                            <span class="qty-value" id="overweight_bags_display"><?php echo $overweightBags; ?></span>
                            <button type="button" class="qty-btn" onclick="updateQty('overweight_bags', 1)">+</button>
                        </div>
                        <input type="hidden" name="overweight_bags" id="overweight_bags" value="<?php echo $overweightBags; ?>">
                    </div>
                </div>
                
                <p style="font-size: 12px; color: #666; margin-top: 15px;"><i class="fas fa-info-circle"></i> Sports equipment & musical instruments welcome with prior notice.</p>
            </div>
            
            <div class="card">
                <div class="card-title">Price Summary</div>
                <div class="price-row">
                    <span>Ticket Price</span>
                    <span class="<?php echo $membership['discount_percentage'] > 0 ? 'price-original' : ''; ?>">$<?php echo number_format($originalAmount, 2); ?></span>
                </div>
                <?php if ($membership['discount_percentage'] > 0): ?>
                <div class="price-row"><span>Member Discount</span><span class="discount-tag">-<?php echo $membership['discount_percentage']; ?>%</span></div>
                <div class="price-row"><span>After Discount</span><span>$<?php echo number_format($ticketPrice, 2); ?></span></div>
                <?php endif; ?>
                <div class="price-row" id="luggage-fee-row" style="<?php echo $luggageFee > 0 ? '' : 'display:none;'; ?>">
                    <span>Extra Baggage</span>
                    <span id="luggage-fee-display">$<?php echo number_format($luggageFee, 2); ?></span>
                </div>
                <div class="price-row total"><span>Total</span><span id="total-display">$<?php echo number_format($finalAmount, 2); ?></span></div>
            </div>
            
            <div class="card">
                <div class="card-title">Payment Method</div>
                <label class="pay-option"><input type="radio" name="payment_method" value="wechat"><img src="image/wechat.png" alt="WeChat"><span>WeChat Pay</span></label>
                <label class="pay-option"><input type="radio" name="payment_method" value="alipay"><img src="image/alipay.png" alt="Alipay"><span>Alipay</span></label>
                <label class="pay-option"><input type="radio" name="payment_method" value="visa"><img src="image/visa.png" alt="VISA"><span>VISA / Credit Card</span></label>
                <button type="submit" name="confirm_payment" class="btn-pay">Pay <span id="pay-amount">$<?php echo number_format($finalAmount, 2); ?></span></button>
            </div>
            </form>
            
            <?php else: ?>
            <div class="card" style="text-align: center;">
                <p style="color: #155724; font-size: 18px;"><i class="fas fa-check-circle"></i> Payment Complete</p>
                <p style="margin-top: 10px;"><a href="myOrders.php">View My Orders</a></p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        const EXTRA_BAG_FEE = <?php echo EXTRA_BAG_FEE; ?>;
        const OVERWEIGHT_FEE = <?php echo OVERWEIGHT_FEE; ?>;
        const TICKET_PRICE = <?php echo $ticketPrice; ?>;
        
        function updateQty(field, delta) {
            const input = document.getElementById(field);
            const display = document.getElementById(field + '_display');
            let value = parseInt(input.value) + delta;
            if (value < 0) value = 0;
            if (value > 5) value = 5;
            input.value = value;
            display.textContent = value;
            updateTotal();
        }
        
        function updateTotal() {
            const extraBags = parseInt(document.getElementById('extra_bags').value) || 0;
            const overweightBags = parseInt(document.getElementById('overweight_bags').value) || 0;
            const luggageFee = (extraBags * EXTRA_BAG_FEE) + (overweightBags * OVERWEIGHT_FEE);
            const total = TICKET_PRICE + luggageFee;
            
            document.getElementById('luggage-fee-row').style.display = luggageFee > 0 ? 'flex' : 'none';
            document.getElementById('luggage-fee-display').textContent = '$' + luggageFee.toFixed(2);
            document.getElementById('total-display').textContent = '$' + total.toFixed(2);
            document.getElementById('pay-amount').textContent = '$' + total.toFixed(2);
        }
        
        document.querySelectorAll('.pay-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.pay-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input').checked = true;
            });
        });
    </script>
</body>
</html>
