-- Update products table to make price optional
ALTER TABLE products 
MODIFY COLUMN price DECIMAL(10,2) NULL DEFAULT NULL;