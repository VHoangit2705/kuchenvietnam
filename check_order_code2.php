<?php
// check_order_code2.php
require_once 'config.php'; // Kết nối cơ sở dữ liệu

if (isset($_POST['order_code'])) {
    $orderCode = $_POST['order_code'];

    // Kiểm tra mã đơn hàng trong cơ sở dữ liệu
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_code2 = ?");
    $stmt->bind_param("s", $orderCode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        // Kiểm tra trạng thái của đơn hàng
        $response = [
            'is_duplicate' => true,
            'previous_status' => $order['status'] // Trả về trạng thái của đơn hàng
        ];
    } else {
        $response = [
            'is_duplicate' => false
        ];
    }

    echo json_encode($response);
} else {
    echo json_encode(['is_duplicate' => false]);
}
?>
