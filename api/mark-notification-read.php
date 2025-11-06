<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Validate input
if (!isset($_POST['notification_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
    exit();
}

$notification_id = (int)$_POST['notification_id'];
$user_id = $_SESSION['user_id'];

// Mark notification as read
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $notification_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating notification']);
}