<?php
include 'config.php';
session_start(); // Đảm bảo session đã được khởi tạo

// Lấy giá trị vị trí từ session
$position = $_SESSION['position'] ?? '';

// Xây dựng câu lệnh SQL với điều kiện lọc theo zone và status
$sql = "SELECT id, order_code2 AS order_code, customer_name, customer_phone 
        FROM orders 
        WHERE zone = ? 
        AND status = 'Đang chờ quét QR' 
        LIMIT 50"; // Giới hạn 50 đơn hàng

// Chuẩn bị và thực thi truy vấn
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $position); // Gắn giá trị $position vào truy vấn
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($orders);
?>
