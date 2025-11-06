-- Add cart table if it doesn't exist
CREATE TABLE IF NOT EXISTS `cart` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE
);

-- Make sure products table has correct columns
ALTER TABLE `products`
    MODIFY COLUMN `product_id` INT AUTO_INCREMENT,
    MODIFY COLUMN `product_name` VARCHAR(255) NOT NULL,
    MODIFY COLUMN `description` TEXT,
    MODIFY COLUMN `price` DECIMAL(10,2) NOT NULL,
    MODIFY COLUMN `stock_quantity` INT NOT NULL DEFAULT 0,
    MODIFY COLUMN `image_url` VARCHAR(255),
    MODIFY COLUMN `image_path` VARCHAR(255);

-- Make sure orders table has correct columns
ALTER TABLE `orders`
    MODIFY COLUMN `order_id` INT AUTO_INCREMENT,
    MODIFY COLUMN `user_id` INT NOT NULL,
    MODIFY COLUMN `order_status` ENUM('pending', 'ready for pickup', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    MODIFY COLUMN `total_amount` DECIMAL(10,2) NOT NULL,
    MODIFY COLUMN `order_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Make sure order_items table has correct columns
ALTER TABLE `order_items`
    MODIFY COLUMN `item_id` INT AUTO_INCREMENT,
    MODIFY COLUMN `order_id` INT NOT NULL,
    MODIFY COLUMN `product_id` INT NOT NULL,
    MODIFY COLUMN `quantity` INT NOT NULL,
    MODIFY COLUMN `price` DECIMAL(10,2) NOT NULL;

-- Add foreign keys if they don't exist
SET @fk_exists = (SELECT COUNT(1) 
                  FROM information_schema.TABLE_CONSTRAINTS 
                  WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_items' 
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = 'order_items_ibfk_1');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `order_items` ADD FOREIGN KEY (`order_id`) REFERENCES `orders`(`order_id`) ON DELETE CASCADE',
    'SELECT "Foreign key order_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(1) 
                  FROM information_schema.TABLE_CONSTRAINTS 
                  WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_items' 
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = 'order_items_ibfk_2');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `order_items` ADD FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE RESTRICT',
    'SELECT "Foreign key product_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;