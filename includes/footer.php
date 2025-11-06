        </div> <!-- End of row -->
    </div> <!-- End of container-fluid -->

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script src="/unineeds/assets/js/script.js"></script>

    <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student'): ?>
    <script>
        // Update cart count
        function updateCartCount() {
            $.get('/unineeds/api/get-cart-count.php', function(data) {
                $('#cart-count').text(data.count);
            });
        }

        // Update notification count
        function updateNotificationCount() {
            $.get('/unineeds/api/get-notification-count.php', function(data) {
                $('#notification-count').text(data.count);
            });
        }

        // Update counts every 30 seconds
        $(document).ready(function() {
            updateCartCount();
            updateNotificationCount();
            setInterval(function() {
                updateCartCount();
                updateNotificationCount();
            }, 30000);
        });
    </script>
    <?php endif; ?>
</body>
</html>