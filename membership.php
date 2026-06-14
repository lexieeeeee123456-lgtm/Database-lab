<?php
/**
 * Bon Avion Flight Booking System
 * Membership Management Module
 * 
 * Membership levels based on total payment amount (handled by database triggers):
 * - Platinum: > $20,000
 * - Gold: > $10,000
 * - Silver: > $5,000
 * - Member: <= $5,000
 * 
 * Supports both PDO and mysqli connections
 */

define('MEMBERSHIP_PLATINUM', 'Platinum');
define('MEMBERSHIP_GOLD', 'Gold');
define('MEMBERSHIP_SILVER', 'Silver');
define('MEMBERSHIP_MEMBER', 'Member');

define('THRESHOLD_PLATINUM', 20000);
define('THRESHOLD_GOLD', 10000);
define('THRESHOLD_SILVER', 5000);

define('DISCOUNT_PLATINUM', 0.85);
define('DISCOUNT_GOLD', 0.90);
define('DISCOUNT_SILVER', 0.95);
define('DISCOUNT_MEMBER', 1.00);

function getTotalSpending($conn, $customer_id) {
    $sql = "SELECT COALESCE(SUM(p.amount), 0) as total_spent
            FROM payment p
            INNER JOIN booking b ON p.PNR = b.PNR
            WHERE b.customer_id = ?";
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$customer_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
    }
    
    return (float) $row['total_spent'];
}

function getCustomerLevel($conn, $customer_id) {
    $sql = "SELECT level FROM customer WHERE customer_id = ?";
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$customer_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
    }
    
    return $row ? $row['level'] : 'Member';
}

function determineMembershipLevel($total_spending) {
    if ($total_spending > THRESHOLD_PLATINUM) {
        return MEMBERSHIP_PLATINUM;
    } elseif ($total_spending > THRESHOLD_GOLD) {
        return MEMBERSHIP_GOLD;
    } elseif ($total_spending > THRESHOLD_SILVER) {
        return MEMBERSHIP_SILVER;
    } else {
        return MEMBERSHIP_MEMBER;
    }
}

function getDiscountRate($membership_level) {
    switch ($membership_level) {
        case MEMBERSHIP_PLATINUM:
        case 'Platinum':
            return DISCOUNT_PLATINUM;
        case MEMBERSHIP_GOLD:
        case 'Gold':
            return DISCOUNT_GOLD;
        case MEMBERSHIP_SILVER:
        case 'Silver':
            return DISCOUNT_SILVER;
        default:
            return DISCOUNT_MEMBER;
    }
}

function getMembershipName($membership_level) {
    $names = [
        MEMBERSHIP_PLATINUM => 'Platinum',
        MEMBERSHIP_GOLD => 'Gold',
        MEMBERSHIP_SILVER => 'Silver',
        MEMBERSHIP_MEMBER => 'Member'
    ];
    return $names[$membership_level] ?? $membership_level;
}

function getNextLevelInfo($total_spending) {
    if ($total_spending > THRESHOLD_PLATINUM) {
        return null;
    } elseif ($total_spending > THRESHOLD_GOLD) {
        return [
            'next_level' => MEMBERSHIP_PLATINUM,
            'amount_needed' => THRESHOLD_PLATINUM - $total_spending + 0.01
        ];
    } elseif ($total_spending > THRESHOLD_SILVER) {
        return [
            'next_level' => MEMBERSHIP_GOLD,
            'amount_needed' => THRESHOLD_GOLD - $total_spending + 0.01
        ];
    } else {
        return [
            'next_level' => MEMBERSHIP_SILVER,
            'amount_needed' => THRESHOLD_SILVER - $total_spending + 0.01
        ];
    }
}

function getMembershipInfo($conn, $customer_id) {
    $total_spending = getTotalSpending($conn, $customer_id);
    $level = getCustomerLevel($conn, $customer_id);
    $discount_rate = getDiscountRate($level);
    $next_level_info = getNextLevelInfo($total_spending);
    
    return [
        'customer_id' => $customer_id,
        'total_spending' => $total_spending,
        'membership_level' => $level,
        'membership_name' => getMembershipName($level),
        'discount_rate' => $discount_rate,
        'discount_percentage' => (1 - $discount_rate) * 100,
        'next_level' => $next_level_info ? getMembershipName($next_level_info['next_level']) : null,
        'amount_to_next_level' => $next_level_info ? $next_level_info['amount_needed'] : 0
    ];
}

function applyMembershipDiscount($conn, $customer_id, $original_price) {
    $membership_info = getMembershipInfo($conn, $customer_id);
    $final_price = $original_price * $membership_info['discount_rate'];
    $savings = $original_price - $final_price;
    
    return [
        'original_price' => $original_price,
        'discount_rate' => $membership_info['discount_rate'],
        'discount_percentage' => $membership_info['discount_percentage'],
        'final_price' => round($final_price, 2),
        'savings' => round($savings, 2),
        'membership_level' => $membership_info['membership_name']
    ];
}
?>
