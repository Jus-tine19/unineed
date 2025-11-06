// product-variants.js - Completely New Variant System
// Simple and clean variant management

// Add variant type for Add Product Modal
document.getElementById('addVariantType')?.addEventListener('click', function() {
    const container = document.getElementById('variantTypes');
    
    const variantGroup = document.createElement('div');
    variantGroup.className = 'variant-type-group mb-3 border p-3 rounded bg-light';
    variantGroup.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="flex-grow-1">
                <label class="form-label mb-1">Variant Type</label>
                <input type="text" class="form-control" name="variant_types[]" placeholder="e.g., Color, Size, Material" required>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger mt-4" onclick="removeVariantType(this)">
                <i class="bi bi-trash"></i> Remove Type
            </button>
        </div>
        <div class="variant-values">
            <div class="variant-value-row mb-2">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1">Value</label>
                        <input type="text" class="form-control" name="variant_values[]" placeholder="e.g., Red, Large" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Price (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="variant_prices[]" placeholder="0.00" required min="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Stock</label>
                        <input type="number" class="form-control" name="variant_stocks[]" placeholder="0" required min="0">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeVariantValue(this)">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariantValue(this)">
                <i class="bi bi-plus"></i> Add Another Value
            </button>
        </div>
    `;
    
    container.appendChild(variantGroup);
    checkVariantFields();
});

// Add variant value to a variant type
function addVariantValue(button) {
    const valuesContainer = button.parentElement;
    const newRow = document.createElement('div');
    newRow.className = 'variant-value-row mb-2';
    newRow.innerHTML = `
        <div class="row g-2">
            <div class="col-md-4">
                <input type="text" class="form-control" name="variant_values[]" placeholder="e.g., Blue, Medium" required>
            </div>
            <div class="col-md-3">
                <input type="number" step="0.01" class="form-control" name="variant_prices[]" placeholder="0.00" required min="0">
            </div>
            <div class="col-md-3">
                <input type="number" class="form-control" name="variant_stocks[]" placeholder="0" required min="0">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeVariantValue(this)">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    `;
    
    valuesContainer.insertBefore(newRow, button);
    // Re-evaluate fields after adding a new value
    checkVariantFields();
}

// Remove variant value
function removeVariantValue(button) {
    const row = button.closest('.variant-value-row');
    const valuesContainer = row.parentElement;
    
    // Only remove if there's more than one value
    const valueRows = valuesContainer.querySelectorAll('.variant-value-row');
    if (valueRows.length > 1) {
        row.remove();
    } else {
        alert('Each variant type must have at least one value. Remove the entire variant type if you want to delete it.');
    }
    // Re-evaluate fields after removing a value
    checkVariantFields();
}

// Remove variant type
function removeVariantType(button) {
    const variantGroup = button.closest('.variant-type-group');
    variantGroup.remove();
    checkVariantFields();
}

/**
 * @summary Checks if variants exist in the Add Product Modal and toggles base price/stock fields accordingly.
 */
function checkVariantFields() {
    const variantGroups = document.querySelectorAll('#variantTypes .variant-type-group');
    const noVariantsFields = document.getElementById('noVariantsFields');
    const basePriceField = document.getElementById('basePriceFieldAdd');
    const baseStockField = document.getElementById('baseStockFieldAdd');
    const priceInput = document.getElementById('priceInputAdd');
    const stockInput = document.getElementById('stockInputAdd');
    const priceRequired = document.getElementById('priceRequiredAdd');
    const stockRequired = document.getElementById('stockRequiredAdd');

    // Consider variants present either when there are variant groups OR when any
    // variant price/stock input contains a non-empty value (user already filled values).
    const variantPriceInputs = document.querySelectorAll('#variantTypes input[name="variant_prices[]"]');
    const variantStockInputs = document.querySelectorAll('#variantTypes input[name="variant_stocks[]"]');

    let hasFilledVariantValues = false;
    variantPriceInputs.forEach(function(pi) {
        if (pi.value !== null && pi.value.toString().trim() !== '') hasFilledVariantValues = true;
    });
    variantStockInputs.forEach(function(si) {
        if (si.value !== null && si.value.toString().trim() !== '') hasFilledVariantValues = true;
    });

    if (variantGroups.length === 0 && !hasFilledVariantValues) {
        // No variants - show info message and base fields with required
        if (noVariantsFields) noVariantsFields.style.display = 'block';
        if (basePriceField) basePriceField.style.display = 'block';
        if (baseStockField) baseStockField.style.display = 'block';
        if (priceInput) {
            priceInput.setAttribute('required', 'required');
            priceInput.disabled = false;
        }
        if (stockInput) {
            stockInput.setAttribute('required', 'required');
            stockInput.disabled = false;
        }
        if (priceRequired) priceRequired.style.display = 'inline';
        if (stockRequired) stockRequired.style.display = 'inline';
    } else {
        // Has variants - hide everything and remove required
        if (noVariantsFields) noVariantsFields.style.display = 'none';
        if (basePriceField) basePriceField.style.display = 'none';
        if (baseStockField) baseStockField.style.display = 'none';
        if (priceInput) {
            priceInput.removeAttribute('required');
            priceInput.disabled = true;
        }
        if (stockInput) {
            stockInput.removeAttribute('required');
            stockInput.disabled = true;
        }
        if (priceRequired) priceRequired.style.display = 'none';
        if (stockRequired) stockRequired.style.display = 'none';
    }
}

// For Edit Modal - add variant type with modal ID
function addVariantType(productId) {
    const container = document.getElementById('variantTypes' + productId);
    
    const variantGroup = document.createElement('div');
    variantGroup.className = 'variant-type-group mb-3 border p-3 rounded bg-light';
    variantGroup.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="flex-grow-1">
                <label class="form-label mb-1">Variant Type</label>
                <input type="text" class="form-control" name="variant_types[]" placeholder="e.g., Color, Size, Material" required>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger mt-4" onclick="removeVariantTypeEdit(this, ${productId})">
                <i class="bi bi-trash"></i> Remove Type
            </button>
        </div>
        <div class="variant-values">
            <div class="variant-value-row mb-2">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1">Value</label>
                        <input type="text" class="form-control" name="variant_values[]" placeholder="e.g., Red, Large" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Price (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="variant_prices[]" placeholder="0.00" required min="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Stock</label>
                        <input type="number" class="form-control" name="variant_stocks[]" placeholder="0" required min="0">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeVariantValue(this)">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariantValue(this)">
                <i class="bi bi-plus"></i> Add Another Value
            </button>
        </div>
    `;
    
    container.appendChild(variantGroup);
    checkVariantFieldsEdit(productId);
}

// Remove variant type in edit modal
function removeVariantTypeEdit(button, productId) {
    const variantGroup = button.closest('.variant-type-group');
    variantGroup.remove();
    checkVariantFieldsEdit(productId);
}

/**
 * @summary Checks if variants exist in the Edit Product Modal and toggles base price/stock fields accordingly.
 * @param {number} productId - The ID of the product being edited.
 */
function checkVariantFieldsEdit(productId) {
    const container = document.getElementById('variantTypes' + productId);
    const basePriceContainer = document.getElementById('basePriceContainer' + productId);
    const baseStockContainer = document.getElementById('baseStockContainer' + productId);
    const variantGroups = container ? container.querySelectorAll('.variant-type-group') : [];
    
    if (basePriceContainer) {
        const priceInput = document.getElementById('priceInput' + productId);
        const priceRequired = document.getElementById('priceRequired' + productId);
        
        if (variantGroups.length === 0) {
            // No variants - show and require base price
            basePriceContainer.style.display = 'block';
            if (priceInput) {
                priceInput.setAttribute('required', 'required');
                priceInput.disabled = false;
            }
            if (priceRequired) {
                priceRequired.style.display = 'inline';
            }
        } else {
            // Has variants - hide base price and remove required
            basePriceContainer.style.display = 'none';
            if (priceInput) {
                priceInput.removeAttribute('required');
                priceInput.disabled = true;
            }
            if (priceRequired) {
                priceRequired.style.display = 'none';
            }
        }
    }
    
    if (baseStockContainer) {
        const stockInput = document.getElementById('stockInput' + productId);
        const stockRequired = document.getElementById('stockRequired' + productId);
        
        if (variantGroups.length === 0) {
            // No variants - show and require base stock
            baseStockContainer.style.display = 'block';
            if (stockInput) {
                stockInput.setAttribute('required', 'required');
                stockInput.disabled = false;
            }
            if (stockRequired) {
                stockRequired.style.display = 'inline';
            }
        } else {
            // Has variants - hide base stock and remove required
            baseStockContainer.style.display = 'none';
            if (stockInput) {
                stockInput.removeAttribute('required');
                stockInput.disabled = true;
            }
            if (stockRequired) {
                stockRequired.style.display = 'none';
            }
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check Add Product Modal
    checkVariantFields();
    
    // Check all Edit Product Modals
    document.querySelectorAll('[id^="variantTypes"]').forEach(function(container) {
        const productId = container.id.replace('variantTypes', '');
        if (productId && !isNaN(productId)) {
            checkVariantFieldsEdit(productId);
        }
    });

    // Attach delegated input listeners so we re-check whenever variant inputs change
    const addVariantTypesContainer = document.getElementById('variantTypes');
    if (addVariantTypesContainer) {
        addVariantTypesContainer.addEventListener('input', function(e) {
            const target = e.target;
            if (target && (target.name === 'variant_values[]' || target.name === 'variant_prices[]' || target.name === 'variant_stocks[]')) {
                checkVariantFields();
            }
        });
    }

    // For edit modals: delegated listeners per container id
    document.querySelectorAll('[id^="variantTypes"]').forEach(function(container) {
        const productId = container.id.replace('variantTypes', '');
        if (!productId || isNaN(productId)) return;
        container.addEventListener('input', function(e) {
            const target = e.target;
            if (target && (target.name === 'variant_values[]' || target.name === 'variant_prices[]' || target.name === 'variant_stocks[]')) {
                checkVariantFieldsEdit(productId);
            }
        });
    });
});