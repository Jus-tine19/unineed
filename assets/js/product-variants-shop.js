document.addEventListener('DOMContentLoaded', function() {
    // Utility to format currency
    function formatCurrency(amount) {
        return '₱' + parseFloat(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    // Update total and add-button state for a product
    function updateTotalAndButton(productId, selectedPrice, availableStock) {
        const qtyInput = document.getElementById(`quantityInput${productId}`);
        const totalDisplay = document.getElementById(`displayTotal${productId}`);
        // Prefer modal submit button, fallback to page-level add button
        const addBtn = document.getElementById(`modalAddBtn${productId}`) || document.getElementById(`addToCartBtn${productId}`);

        let qty = 1;
        if (qtyInput) qty = parseInt(qtyInput.value) || 1;

        const total = (parseFloat(selectedPrice) || 0) * qty;
        if (totalDisplay) totalDisplay.textContent = formatCurrency(total);

        // Enable add button only if we have sufficient stock and a valid price (or no variant required)
        if (addBtn) {
            if ((availableStock === null || availableStock === undefined) || (availableStock > 0 && qty > 0 && qty <= availableStock)) {
                addBtn.disabled = false;
            } else {
                addBtn.disabled = true;
            }
        }
    }

    // Handle variant selection for every product's selects
    document.querySelectorAll('.variant-select').forEach(select => {
        select.addEventListener('change', function() {
            const productId = this.dataset.productId;
            const variantSelects = document.querySelectorAll(`select[data-product-id="${productId}"]`);
            const quantityInput = document.getElementById(`quantityInput${productId}`);
            const displayPrice = document.getElementById(`displayPrice${productId}`);
            const displayStock = document.getElementById(`displayStock${productId}`);
            const variantPriceInput = document.getElementById(`variantPrice${productId}`);

            // Check if all variants are selected
            let allSelected = true;
            let selectedPrice = 0;
            let selectedStock = Infinity;

            variantSelects.forEach(s => {
                const selectedOption = s.options[s.selectedIndex];
                if (!selectedOption || !selectedOption.value) {
                    allSelected = false;
                } else {
                    const p = parseFloat(selectedOption.dataset.price) || 0;
                    const st = parseInt(selectedOption.dataset.stock) || 0;
                    // If multiple variant types, price might differ per option; we take the sum or the last selected depending on your pricing model.
                    // Here we assume variant options map to a single variant row per type and the selectedPrice should be the price of the selected option combination.
                    // Since the system stores price per variant row (type+value), for simplicity take the maximum price among selected options if multiple types exist.
                    selectedPrice = Math.max(selectedPrice, p);
                    selectedStock = Math.min(selectedStock, st);
                }
            });

            if (allSelected) {
                displayPrice.textContent = formatCurrency(selectedPrice);
                displayStock.textContent = `${selectedStock} units`;
                variantPriceInput.value = selectedPrice;
                quantityInput.disabled = false;
                quantityInput.max = selectedStock;
                if (parseInt(quantityInput.value) > selectedStock) {
                    quantityInput.value = selectedStock;
                }
                updateTotalAndButton(productId, selectedPrice, selectedStock);
            } else {
                displayPrice.textContent = 'Select variants to see price';
                displayStock.textContent = 'Select variants to see stock';
                variantPriceInput.value = '';
                quantityInput.disabled = true;
                updateTotalAndButton(productId, 0, 0);
            }
        });
    });

    // Handle quantity changes to update total
    document.querySelectorAll('input[id^="quantityInput"]').forEach(q => {
        q.addEventListener('input', function() {
            const productId = this.id.replace('quantityInput', '');
            const variantPriceInput = document.getElementById(`variantPrice${productId}`);
            const price = variantPriceInput && variantPriceInput.value ? parseFloat(variantPriceInput.value) : document.querySelector(`#displayPrice${productId}`) ? parseFloat(document.getElementById(`displayPrice${productId}`).textContent.replace(/[^0-9.-]+/g, '')) : 0;
            const displayStockEl = document.getElementById(`displayStock${productId}`);
            let availableStock = null;
            if (displayStockEl) {
                const m = displayStockEl.textContent.match(/(\d+)/);
                availableStock = m ? parseInt(m[0]) : null;
            }
            // Clamp quantity to min/max
            const max = parseInt(this.max) || availableStock || 9999;
            if (parseInt(this.value) > max) this.value = max;
            if (parseInt(this.value) < 1 || !this.value) this.value = 1;
            updateTotalAndButton(productId, price, availableStock);
        });
    });

    // Quantity +/- buttons
    document.querySelectorAll('.qty-decrease').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.querySelector(this.dataset.target);
            if (!target) return;
            let val = parseInt(target.value) || 1;
            val = Math.max(1, val - 1);
            target.value = val;
            target.dispatchEvent(new Event('input'));
        });
    });

    document.querySelectorAll('.qty-increase').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.querySelector(this.dataset.target);
            if (!target) return;
            let val = parseInt(target.value) || 1;
            const max = parseInt(target.max) || 9999;
            val = Math.min(max, val + 1);
            target.value = val;
            target.dispatchEvent(new Event('input'));
        });
    });

    // Initialize modal state when opened (Bootstrap 5 'show.bs.modal')
    document.querySelectorAll('.modal').forEach(modalEl => {
        modalEl.addEventListener('show.bs.modal', function (event) {
            const modalId = this.id; // e.g., addModal123
            const productId = modalId.replace('addModal', '');

            const variantPriceInput = document.getElementById(`variantPrice${productId}`);
            const displayPrice = document.getElementById(`displayPrice${productId}`);
            const displayStock = document.getElementById(`displayStock${productId}`);
            const quantityInput = document.getElementById(`quantityInput${productId}`);
            const totalDisplay = document.getElementById(`displayTotal${productId}`);
            const addBtn = document.getElementById(`addToCartBtn${productId}`);

            // If there are no variant selects, set base price and stock from server-rendered values
            const variantSelects = document.querySelectorAll(`select[data-product-id="${productId}"]`);
            if (!variantSelects || variantSelects.length === 0) {
                // base price and stock already rendered server-side, ensure inputs enabled
                if (quantityInput) {
                    quantityInput.disabled = false;
                    // ensure total is correct
                    const basePriceText = displayPrice ? displayPrice.textContent : '0';
                    const basePrice = parseFloat((basePriceText || '').replace(/[^0-9.-]+/g, '')) || 0;
                    updateTotalAndButton(productId, basePrice, parseInt((displayStock && displayStock.textContent.match(/(\d+)/)) ? displayStock.textContent.match(/(\d+)/)[0] : null));
                }
                return;
            }

            // Trigger change on all selects to initialize state if options pre-selected
            variantSelects.forEach(s => {
                const evt = new Event('change');
                s.dispatchEvent(evt);
            });
        });
    });
});