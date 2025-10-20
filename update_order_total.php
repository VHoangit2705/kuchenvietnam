<?php
header('Content-Type: application/json');
include 'config.php';

$orderId = $_POST['orderId'];
$totalPrice = $_POST['totalPrice'];

// Validate input
if (!is_numeric($orderId) || !is_numeric($totalPrice)) {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ']);
    exit;
}

// Update the database
$sql = "UPDATE orders SET total_price = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("di", $totalPrice, $orderId);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Tổng giá cập nhật thành công']);
} else {
    echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật tổng giá: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
