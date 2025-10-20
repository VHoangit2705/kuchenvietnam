<?php
include 'config.php';

if (isset($_GET['order_code'])) {
    $orderCode = $_GET['order_code'];

    $sql = "SELECT status FROM orders WHERE order_code2 = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $orderCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if ($order) {
        echo json_encode(['status' => $order['status']]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
} else {
    echo json_encode(['status' => 'error']);
}
?>
