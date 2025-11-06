// assets/js/script.js

// Sidebar Toggle for Mobile
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('appSidebar');
    
    // mobile toggle (existing topbar button)
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
    }
});

// Confirmation Dialog
function confirmAction(message) {
    return confirm(message || 'Are you sure you want to perform this action?');
}

// Delete Confirmation
function confirmDelete(itemName) {
    return confirm(`Are you sure you want to delete ${itemName}? This action cannot be undone.`);
}



// Format Currency
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Show Toast Notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 5000);
}

// Add to Cart - Simple version without loading indicators
function addToCart(productId, quantity = 1) {
    // Disable the button that was clicked
    const clickedButton = document.querySelector(`button[onclick*="addToCart(${productId}"]`);
    if (clickedButton) {
        clickedButton.disabled = true;
        clickedButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Adding...';
    }

    fetch('../api/add-to-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update cart count immediately
            updateCartCount();
            
            // Show small success message
            showToast(data.message, 'success');
            
            // Close modal if open
            const modal = document.getElementById(`addModal${productId}`);
            if (modal) {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) modalInstance.hide();
            }
        } else {
            showToast(data.message, 'danger');
        }
    })
    .catch(error => {
        showToast('An error occurred. Please try again.', 'danger');
        console.error('Error:', error);
    })
    .finally(() => {
        // Re-enable the button and restore original text
        if (clickedButton) {
            clickedButton.disabled = false;
            clickedButton.innerHTML = '<i class="bi bi-cart-plus me-2"></i>Add to Cart';
        }
    });
}

// Update Cart Count
function updateCartCount() {
    const cartCount = document.getElementById('cartCount');
    if (cartCount) {
        fetch('../api/get-cart-count.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    cartCount.textContent = data.count;
                    if (data.count > 0) {
                        cartCount.style.display = 'inline-block';
                    } else {
                        cartCount.style.display = 'none';
                    }
                }
            });
    }
}

// Cancel Order (user side) - cancels only if order is pending
function cancelOrder(orderId) {
    if (!confirm('Are you sure you want to cancel this order?')) return;

    const btn = document.querySelector(`button[onclick*="cancelOrder(${orderId}"]`);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cancelling...';
    }

    const formData = new FormData();
    formData.append('order_id', orderId);

    fetch('../api/cancel-order.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(data.message, 'danger');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cancel Order';
            }
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Failed to cancel order. Please try again.', 'danger');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cancel Order';
        }
    });
}

// Remove from Cart
function removeFromCart(itemId) {
    if (!confirmAction('Remove this item from cart?')) {
        return;
    }
    
    // Disable the remove button that was clicked
    const removeButton = document.querySelector(`button[onclick*="removeFromCart(${itemId}"]`);
    if (removeButton) {
        removeButton.disabled = true;
        removeButton.innerHTML = '<i class="bi bi-trash me-2"></i>Removing...';
    }
    
    fetch('../api/remove-from-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            item_id: itemId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            // Refresh the page after a short delay
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message, 'danger');
            // Re-enable the button if there was an error
            if (removeButton) {
                removeButton.disabled = false;
                removeButton.innerHTML = '<i class="bi bi-trash me-2"></i>Remove';
            }
        }
    })
    .catch(error => {
        showToast('An error occurred. Please try again.', 'danger');
        console.error('Error:', error);
        // Re-enable the button on error
        if (removeButton) {
            removeButton.disabled = false;
            removeButton.innerHTML = '<i class="bi bi-trash me-2"></i>Remove';
        }
    });
}

// Update Order Status
function updateOrderStatus(orderId, status) {
    if (!confirmAction(`Update order status to ${status}?`)) {
        return;
    }
    
    // Disable the status button that was clicked
    const statusButton = document.querySelector(`button[onclick*="updateOrderStatus(${orderId}"]`);
    if (statusButton) {
        statusButton.disabled = true;
        statusButton.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Updating...';
    }
    
    fetch('../api/update-order-status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            order_id: orderId,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            // Refresh the page after a short delay
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message, 'danger');
            // Re-enable the button if there was an error
            if (statusButton) {
                statusButton.disabled = false;
                statusButton.innerHTML = `<i class="bi bi-arrow-clockwise me-2"></i>Update to ${status}`;
            }
        }
    })
    .catch(error => {
        showToast('An error occurred. Please try again.', 'danger');
        console.error('Error:', error);
        // Re-enable the button on error
        if (statusButton) {
            statusButton.disabled = false;
            statusButton.innerHTML = `<i class="bi bi-arrow-clockwise me-2"></i>Update to ${status}`;
        }
    });
}

// Image Preview
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
}

// Search/Filter Products
function filterProducts() {
    const searchInput = document.getElementById('searchInput');
    const categoryFilter = document.getElementById('categoryFilter');
    
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    const category = categoryFilter ? categoryFilter.value : '';
    
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        const title = product.querySelector('.product-title').textContent.toLowerCase();
        const productCategory = product.dataset.category || '';
        
        const matchesSearch = title.includes(searchTerm);
        const matchesCategory = !category || productCategory === category;
        
        if (matchesSearch && matchesCategory) {
            product.closest('.col-md-3').style.display = 'block';
        } else {
            product.closest('.col-md-3').style.display = 'none';
        }
    });
}

// Print Invoice
function printInvoice() {
    window.print();
}

// Export to PDF
function exportToPDF() {
    window.print();
}

// Reorder items from a previous order
function reorder(orderId) {
    if (!confirm('Would you like to add these items to your cart?')) return;

    const btn = document.querySelector(`button[onclick*="reorder(${orderId}"]`);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i>Processing...';
    }

    fetch('../api/reorder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ order_id: orderId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message first
            showToast(data.message, 'success');
            
            // Update cart count in navbar
            const cartCountElement = document.querySelector('#cartCount');
            if (cartCountElement && data.cart_count) {
                cartCountElement.textContent = data.cart_count;
                if (data.cart_count > 0) {
                    cartCountElement.style.display = 'inline-block';
                } else {
                    cartCountElement.style.display = 'none';
                }
            }
            
            // Show detailed messages for insufficient stock if any
            if (data.insufficient_stock && data.insufficient_stock.length > 0) {
                setTimeout(() => {
                    const stockMsg = data.insufficient_stock.map(item => 
                        `${item.product_name || item.name} (Available: ${item.available}, Requested: ${item.requested})`
                    ).join('\n');
                    showToast('Limited stock available for:\n' + stockMsg, 'warning');
                }, 500);
            }
            
            // Redirect to cart after a short delay to allow reading the messages
            setTimeout(() => {
                window.location.href = 'cart.php';
            }, data.insufficient_stock?.length > 0 ? 3000 : 1000);
            
        } else {
            showToast(data.message, 'danger');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i>Reorder';
            }
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Failed to add items to cart. Please try again.', 'danger');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i>Reorder';
        }
    });
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert:not(.alert-dismissible)');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});

// Quantity Spinner
document.addEventListener('DOMContentLoaded', function() {
    const quantityInputs = document.querySelectorAll('.quantity-input');
    
    quantityInputs.forEach(input => {
        const minusBtn = input.previousElementSibling;
        const plusBtn = input.nextElementSibling;
        
        if (minusBtn) {
            minusBtn.addEventListener('click', function() {
                let value = parseInt(input.value);
                if (value > 1) {
                    input.value = value - 1;
                }
            });
        }
        
        if (plusBtn) {
            plusBtn.addEventListener('click', function() {
                let value = parseInt(input.value);
                let max = parseInt(input.max) || 999;
                if (value < max) {
                    input.value = value + 1;
                }
            });
        }
    });
});

// Form Validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;
    
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}

// Mark Notification as Read
function markAsRead(notificationId) {
    fetch('../api/mark-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            notification_id: notificationId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const notifElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notifElement) {
                notifElement.classList.remove('unread');
            }
        }
    });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Course -> year level mapping and dynamic year select population
document.addEventListener('DOMContentLoaded', function() {
    const mapping = {
        '4': ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        '2': ['1st Year', '2nd Year'],
        '3': ['1st Year', '2nd Year', '3rd Year'],
        '1': ['1st Year']
    };

    const courseGroups = {
        'Bachelor of Science in Information Systems (BSIS)': '4',
        'Bachelor of Science in Office Management (BSOM)': '4',
        'Bachelor of Science in Accounting Information System (BSAIS)': '4',
        'Bachelor of Technical Vocational Teacher Education (BTVTED)': '4',
        'Bachelor of Science in Customs Administration (BSCA)': '4',
        'Associate in Computer Technology': '2',
        'Diploma in Hotel and Restaurant Management Technology (DHRMT)': '3',
        'Hotel and Restaurant Services (Bundled) HB': '1',
        'Shielded Metal Arc Welding (SMAW)': '1',
        'Bookkeeping': '1',
        'Electrical Installations and Maintenance (EIM)': '1'
    };

    function populateYearSelect(courseSelect, yearSelect, selected) {
        const course = courseSelect.value;
        const group = courseGroups[course];
        yearSelect.innerHTML = '<option value="">Select Year Level</option>';
        if (group && mapping[group]) {
            mapping[group].forEach(function(y) {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                if (selected && selected === y) opt.selected = true;
                yearSelect.appendChild(opt);
            });
        }
    }

    // Attach listeners to existing add/edit modals
    document.querySelectorAll('.course-select').forEach(function(select) {
        const wrapper = select.closest('.modal') || document;
        const yearSelect = wrapper.querySelector('.year-select');
        // initial populate if edit modal has preselected value
        if (yearSelect) {
            const selectedYear = yearSelect.dataset.value || yearSelect.querySelector('option[selected]')?.textContent;
            populateYearSelect(select, yearSelect, selectedYear);
        }

        select.addEventListener('change', function() {
            const wrapper = this.closest('.modal') || document;
            const yearSel = wrapper.querySelector('.year-select');
            if (yearSel) populateYearSelect(this, yearSel);
        });
    });
});

// Initialize cart count on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCartCount();
});