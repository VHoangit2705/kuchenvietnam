<?php
include 'config.php';

// Lấy danh sách các đơn hàng bị khóa quá 30 phút
$sql = "
    UPDATE orders 
    SET status = 'Đang chờ quét QR', lock_timestamp = NULL
    WHERE status = 'Đang quét QR' AND lock_timestamp < NOW() - INTERVAL 1 MINUTE
";

if ($conn->query($sql) === TRUE) {
    echo "Đã đặt lại trạng thái cho các đơn hàng quá thời gian.";
} else {
    echo "Lỗi khi đặt lại trạng thái: " . $conn->error;
}
?>
