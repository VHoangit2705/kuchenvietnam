<?php
// Kết nối database
include 'config.php';

// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Ngày hiện tại
$currentDate = date('Y-m-d');

// Truy vấn kiểm tra và cập nhật
$sql = "
    UPDATE orders
    SET status_tracking = 'Giao thành công'
    WHERE order_code1 IN ('Giao hàng từ kho Vinh', 'Giao hàng từ kho HaNoi', 'Giao hàng từ kho HCM', 'Vui lòng đăng nhập lại.Lỗi không tìm thấy kho') AND status_tracking = 'Đang giao hàng'
      AND DATE(created_at) <= DATE_ADD('$currentDate', INTERVAL -1 DAY)
      AND (status = 'Đã quét QR' OR status = 'Hàng chờ đóng gói')
";
// Thực thi truy vấn
if ($conn->query($sql) === TRUE) {
    echo "Cập nhật thành công.";
} else {
    echo "Lỗi khi cập nhật: " . $conn->error;
}

// Đóng kết nối
$conn->close();
?>
