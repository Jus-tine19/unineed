<?php
// Helper functions for image processing and color extraction

function uploadProductImage($file) {
    $target_dir = dirname(__DIR__) . "/assets/uploads/products/";
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    // Check if image file is a actual image or fake image
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        return [false, "File is not an image."];
    }
    
    // Check file size (5MB max)
    if ($file["size"] > 5000000) {
        return [false, "File is too large. Maximum size is 5MB."];
    }
    
    // Allow certain file formats
    if (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
        return [false, "Only JPG, JPEG, PNG & GIF files are allowed."];
    }
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return [true, "/assets/uploads/products/" . $new_filename];
    }
    
    return [false, "Failed to upload file."];
}

function extractColors($imagePath) {
    $colors = [];
    $serverPath = __DIR__ . "/.." . parse_url($imagePath, PHP_URL_PATH);
    
    if (!file_exists($serverPath)) {
        return [
            'primary' => '#2E4412',    // Dark green from your palette
            'secondary' => '#F6C500',   // Yellow from your palette
            'accent' => '#F78C56'       // Orange from your palette
        ];
    }
    
    // Create image resource
    $image = null;
    $extension = strtolower(pathinfo($serverPath, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($serverPath);
            break;
        case 'png':
            $image = imagecreatefrompng($serverPath);
            break;
        case 'gif':
            $image = imagecreatefromgif($serverPath);
            break;
        default:
            return null;
    }
    
    if (!$image) {
        return null;
    }
    
    // Resize image for faster processing
    $width = imagesx($image);
    $height = imagesy($image);
    $scale = 50; // Sample every 50th pixel
    
    $colors_count = [];
    
    // Sample colors from the image
    for ($x = 0; $x < $width; $x += $scale) {
        for ($y = 0; $y < $height; $y += $scale) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            // Convert to hex and count occurrences
            $hex = sprintf("#%02x%02x%02x", $r, $g, $b);
            if (!isset($colors_count[$hex])) {
                $colors_count[$hex] = 1;
            } else {
                $colors_count[$hex]++;
            }
        }
    }
    
    // Sort colors by frequency
    arsort($colors_count);
    
    // Get top 3 most common colors
    $dominant_colors = array_keys(array_slice($colors_count, 0, 3));
    
    imagedestroy($image);
    
    return [
        'primary' => $dominant_colors[0] ?? '#2E4412',
        'secondary' => $dominant_colors[1] ?? '#F6C500',
        'accent' => $dominant_colors[2] ?? '#F78C56'
    ];
}