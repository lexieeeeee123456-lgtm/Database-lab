<?php
/**
 * Avatar Helper - Handles avatar caching and compression
 * This file helps reduce page load time by:
 * 1. Caching avatar thumbnails in session
 * 2. Compressing avatars to smaller sizes for navigation display
 */

function getNavAvatar($conn, $customerId) {
    $result = [
        'display' => '',
        'initials' => ''
    ];
    
    // Check session cache first (valid for 5 minutes)
    if (isset($_SESSION['avatar_cache']) && 
        isset($_SESSION['avatar_cache_time']) && 
        (time() - $_SESSION['avatar_cache_time']) < 300 &&
        isset($_SESSION['avatar_customer_id']) &&
        $_SESSION['avatar_customer_id'] === $customerId) {
        return [
            'display' => $_SESSION['avatar_cache'],
            'initials' => $_SESSION['avatar_initials'] ?? ''
        ];
    }
    
    // Query database
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT first_name, last_name, avatar_blob FROM customer WHERE customer_id = ?");
        $stmt->bind_param("s", $customerId);
        $stmt->execute();
        $queryResult = $stmt->get_result();
        $row = $queryResult->fetch_assoc();
        $stmt->close();
    } elseif ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT first_name, last_name, avatar_blob FROM customer WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        return $result;
    }
    
    if (!$row) {
        return $result;
    }
    
    $result['initials'] = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
    
    if (!empty($row['avatar_blob'])) {
        $avatarData = $row['avatar_blob'];
        
        // Try to compress the avatar for faster loading
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($avatarData);
            if ($img !== false) {
                $width = imagesx($img);
                $height = imagesy($img);
                $maxSize = 80; // Small thumbnail for navigation
                
                if ($width > $maxSize || $height > $maxSize) {
                    $ratio = min($maxSize / $width, $maxSize / $height);
                    $newWidth = (int)($width * $ratio);
                    $newHeight = (int)($height * $ratio);
                    
                    $thumb = imagecreatetruecolor($newWidth, $newHeight);
                    
                    // Handle transparency for PNG/GIF
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                    $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                    imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
                    
                    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    
                    ob_start();
                    imagejpeg($thumb, null, 70); // Quality 70 for smaller size
                    $compressedData = ob_get_clean();
                    
                    imagedestroy($thumb);
                    $result['display'] = 'data:image/jpeg;base64,' . base64_encode($compressedData);
                } else {
                    // Image is already small, just convert to JPEG for consistency
                    ob_start();
                    imagejpeg($img, null, 70);
                    $compressedData = ob_get_clean();
                    $result['display'] = 'data:image/jpeg;base64,' . base64_encode($compressedData);
                }
                imagedestroy($img);
            } else {
                // GD failed, use original with mime detection
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($avatarData);
                $result['display'] = 'data:' . $mime . ';base64,' . base64_encode($avatarData);
            }
        } else {
            // GD not available, use original
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($avatarData);
            $result['display'] = 'data:' . $mime . ';base64,' . base64_encode($avatarData);
        }
    }
    
    // Cache in session
    $_SESSION['avatar_cache'] = $result['display'];
    $_SESSION['avatar_initials'] = $result['initials'];
    $_SESSION['avatar_cache_time'] = time();
    $_SESSION['avatar_customer_id'] = $customerId;
    
    return $result;
}

/**
 * Clear avatar cache - call this after avatar update
 */
function clearAvatarCache() {
    unset($_SESSION['avatar_cache']);
    unset($_SESSION['avatar_initials']);
    unset($_SESSION['avatar_cache_time']);
    unset($_SESSION['avatar_customer_id']);
}

/**
 * Compress uploaded avatar before storing in database
 * Max size: 500x500, Quality: 80
 */
function compressAvatarForStorage($fileData, $maxWidth = 500, $maxHeight = 500, $quality = 80) {
    if (!function_exists('imagecreatefromstring')) {
        return $fileData; // GD not available
    }
    
    $img = @imagecreatefromstring($fileData);
    if ($img === false) {
        return $fileData; // Invalid image
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    
    // Only resize if larger than max dimensions
    if ($width <= $maxWidth && $height <= $maxHeight) {
        // Still compress to JPEG for consistency
        ob_start();
        imagejpeg($img, null, $quality);
        $compressed = ob_get_clean();
        imagedestroy($img);
        return $compressed;
    }
    
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    ob_start();
    imagejpeg($thumb, null, $quality);
    $compressed = ob_get_clean();
    
    imagedestroy($img);
    imagedestroy($thumb);
    
    return $compressed;
}
?>
