<?php
require_once '../config/database.php';
require_once '../includes/image_helper.php'; // Assuming this helper contains file upload functions
requireStudent();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $order_id = intval(clean($_POST['order_id']));
    $user_id = $_SESSION['user_id'];
    
    // 1. Basic Validation
    if ($order_id <= 0) {
        $response['message'] = 'Invalid Order ID.';
        echo json_encode($response);
        exit();
    }
    
    // Check if a file was uploaded
    if (empty($_FILES['receipt_image']['name'])) {
        $response['message'] = 'Please upload a receipt image.';
        echo json_encode($response);
        exit();
    }

    // 2. Verify Order and Payment Status
    $order_q = "SELECT o.order_status, o.payment_method, i.payment_status 
                FROM orders o 
                LEFT JOIN invoices i ON o.order_id = i.order_id 
                WHERE o.order_id = $order_id AND o.user_id = $user_id FOR UPDATE";
    $order_res = mysqli_query($conn, $order_q);
    $order_data = $order_res ? mysqli_fetch_assoc($order_res) : null;

    if (!$order_data) {
        $response['message'] = 'Order not found or access denied.';
        echo json_encode($response);
        exit();
    }
    
    if ($order_data['order_status'] !== 'pending_payment' || $order_data['payment_method'] !== 'gcash') {
        $response['message'] = 'Receipt upload is not allowed for this order status/method.';
        echo json_encode($response);
        exit();
    }

    // 3. Handle File Upload
    $uploadDir = '../assets/uploads/gcash_receipts';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Assuming image_helper.php has a function like uploadGcashReceiptImage
    // Since I cannot access image_helper.php, I will create simple upload logic here.
    $fileName = basename($_FILES['receipt_image']['name']);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png'];
    
    if (!in_array($fileExt, $allowedExts)) {
        $response['message'] = 'Invalid file type. Only JPG and PNG allowed.';
        echo json_encode($response);
        exit();
    }

    $newFileName = 'receipt_' . $order_id . '_' . time() . '.' . $fileExt;
    $uploadPath = $uploadDir . '/' . $newFileName;
    $dbPath = 'assets/uploads/gcash_receipts/' . $newFileName; // Path relative to /unineed

    if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $uploadPath)) {
        
        // 4. Update Database
        mysqli_begin_transaction($conn);
        try {
            // Update the invoice record to store the proof path and flag for admin review
            // Assuming 'invoices' table has a 'payment_proof_path' column (if not, you need to add it)
            // SQL Manual Action Required: ALTER TABLE invoices ADD COLUMN payment_proof_path VARCHAR(255) NULL;
            $update_q = "UPDATE invoices 
                         SET payment_proof_path = '$dbPath', 
                             payment_status = 'pending_proof' /* Set to a specific status indicating proof uploaded */
                         WHERE order_id = $order_id";
            
            if (!mysqli_query($conn, $update_q)) {
                throw new Exception("Failed to update invoice: " . mysqli_error($conn));
            }

            // Notify admin that payment proof is ready
            $message = "GCash Payment Proof uploaded for order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . ". Requires verification.";
            $notif_q = "INSERT INTO notifications (user_id, message, type) 
                        SELECT user_id, '$message', 'payment_proof' FROM users WHERE user_type = 'admin'";
            mysqli_query($conn, $notif_q);

            mysqli_commit($conn);
            $response['success'] = true;
            $response['message'] = 'Receipt uploaded successfully! Awaiting admin verification.';
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $response['message'] = 'Database Error: ' . $e->getMessage();
        }
        
    } else {
        $response['message'] = 'File upload failed. Check permissions.';
    }
} else {
    $response['message'] = 'Invalid request.';
}

echo json_encode($response);
?>