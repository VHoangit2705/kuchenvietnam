<?php
include 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
if (isset($data['order_code'])) {
    $orderCode = $data['order_code'];
    $sql = "
        UPDATE orders 
        SET status = 'Đang chờ quét QR', lock_timestamp = NULL
        WHERE order_code2 = ? AND status = 'Đang quét QR'
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $orderCode);
    $stmt->execute();
}
?>
