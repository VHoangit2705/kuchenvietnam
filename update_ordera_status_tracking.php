<?php
include 'config.php';

if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

$currentDate = date('Y-m-d');

$sql = "
    UPDATE orders
    SET status_tracking = 'Giao thành công'
    WHERE 
    (
        -- Đơn khách nhận tại kho hoặc warehouse_ghtk, sau 1 ngày
        (
            (
                order_code1 = 'Khách nhận hàng tại kho Droppii' 
                OR type = 'warehouse_ghtk'
            )
            AND DATE(created_at) <= DATE_ADD('$currentDate', INTERVAL -1 DAY)
        )
        -- Đơn droppii_ghtk, sau 7 ngày
        OR
        (
            type = 'droppii_ghtk'
            AND DATE(created_at) <= DATE_ADD('$currentDate', INTERVAL -15 DAY)
        )
    )
    AND (status_tracking = '' OR status_tracking = 'Đang giao hàng')
    AND (status = 'Đã quét QR' OR status = 'Hàng chờ đóng gói')
";

if ($conn->query($sql) === TRUE) {
    echo "Cập nhật thành công.";
} else {
    echo "Lỗi khi cập nhật: " . $conn->error;
}

$conn->close();
?>
