-- Add color_palette field to products table
ALTER TABLE products 
ADD COLUMN image_path VARCHAR(255) AFTER image_url,
ADD COLUMN primary_color VARCHAR(7) AFTER image_path,
ADD COLUMN secondary_color VARCHAR(7) AFTER primary_color,
ADD COLUMN accent_color VARCHAR(7) AFTER secondary_color;