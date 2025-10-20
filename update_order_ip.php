<?php
include 'config.php';  // File cấu hình kết nối CSDL; biến $conn được khởi tạo ở đây

if (!isset($_POST['orderId'])) {
    die("Thiếu orderId.");
}

$orderId = $_POST['orderId'];
$ip = $_SERVER['REMOTE_ADDR'];

// Cập nhật trường ip trong bảng orders
$sql = "UPDATE orders SET ip_rate = ? WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Prepare failed: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "si", $ip, $orderId);
mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) > 0) {
    echo "Cập nhật IP thành công.";
} else {
    echo "Không cập nhật được hoặc không có thay đổi.";
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
