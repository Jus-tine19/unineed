// Image preview and color palette handling
function previewImage(input, previewId, placeholderId) {
    // If no specific preview/placeholder IDs provided, use default ones for add form
    const preview = document.getElementById(previewId || 'imagePreview');
    const placeholder = document.getElementById(placeholderId || 'previewPlaceholder');
    const colorPalette = document.getElementById('colorPalette');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'inline-block';
            placeholder.style.display = 'none';
            colorPalette.style.display = 'block';
            
            // Extract colors from image
            extractImageColors(preview);
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}

function extractImageColors(imgElement) {
    // Create a canvas to draw the image
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    // Set canvas size to match image
    canvas.width = imgElement.naturalWidth;
    canvas.height = imgElement.naturalHeight;
    
    // Draw image on canvas
    ctx.drawImage(imgElement, 0, 0);
    
    try {
        // Get image data
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        const colorCounts = {};
        
        // Sample every 10th pixel for performance
        for (let i = 0; i < imageData.length; i += 40) {
            const r = imageData[i];
            const g = imageData[i + 1];
            const b = imageData[i + 2];
            
            // Convert RGB to hex
            const hex = rgbToHex(r, g, b);
            
            // Count color occurrences
            colorCounts[hex] = (colorCounts[hex] || 0) + 1;
        }
        
        // Sort colors by frequency
        const sortedColors = Object.entries(colorCounts)
            .sort(([,a], [,b]) => b - a)
            .map(([color]) => color)
            .slice(0, 3);
        
        // Update color previews
        document.querySelector('.primary-color .color-preview').style.backgroundColor = sortedColors[0] || '#2E4412';
        document.querySelector('.secondary-color .color-preview').style.backgroundColor = sortedColors[1] || '#F6C500';
        document.querySelector('.accent-color .color-preview').style.backgroundColor = sortedColors[2] || '#F78C56';
        
        // Store colors in hidden inputs for form submission
        const form = imgElement.closest('form');
        if (form) {
            if (!form.querySelector('input[name="primary_color"]')) {
                form.insertAdjacentHTML('beforeend', `
                    <input type="hidden" name="primary_color" value="${sortedColors[0] || '#2E4412'}">
                    <input type="hidden" name="secondary_color" value="${sortedColors[1] || '#F6C500'}">
                    <input type="hidden" name="accent_color" value="${sortedColors[2] || '#F78C56'}">
                `);
            } else {
                form.querySelector('input[name="primary_color"]').value = sortedColors[0] || '#2E4412';
                form.querySelector('input[name="secondary_color"]').value = sortedColors[1] || '#F6C500';
                form.querySelector('input[name="accent_color"]').value = sortedColors[2] || '#F78C56';
            }
        }
    } catch (e) {
        console.error('Error extracting colors:', e);
    }
}

function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(x => {
        const hex = x.toString(16);
        return hex.length === 1 ? '0' + hex : hex;
    }).join('');
}