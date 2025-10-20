<?php
// find_order_by_sn.php
header('Content-Type: application/json; charset=utf-8');

// 1. Cấu hình kết nối DB — thay các thông số cho phù hợp
include'../config.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Không thể kết nối database']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

// 3. Lấy và validate tham số sn
$sn = isset($_GET['sn']) ? trim($_GET['sn']) : '';
if ($sn === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Thiếu tham số sn']);
    mysqli_close($conn);
    exit;
}
// Tránh SQL injection
$sn_esc = mysqli_real_escape_string($conn, $sn);

// 4. Chuẩn bị và thực thi truy vấn
$sql = "
    SELECT
      o.order_code2,
      o.customer_name,
      o.customer_phone,
      op.product_name,
      op.quantity,
      pw.created_at AS scan_date
    FROM product_warranties AS pw
    JOIN order_products   AS op ON pw.order_product_id = op.id
    JOIN orders           AS o  ON op.order_id = o.id
    WHERE pw.warranty_code = '{$sn_esc}'
    LIMIT 1
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Truy vấn thất bại']);
    mysqli_close($conn);
    exit;
}

$order = mysqli_fetch_assoc($result);
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Không tìm thấy đơn hàng cho mã SN này']);
    mysqli_free_result($result);
    mysqli_close($conn);
    exit;
}

// 5. Trả về JSON
$response = [
    'order_code2'   => $order['order_code2'],
    'customer_name' => $order['customer_name'],
    'customer_phone'=> $order['customer_phone'],
    'product_name'  => $order['product_name'],
    'quantity'      => (int)$order['quantity'],
    'scan_date'     => $order['scan_date'], // YYYY-MM-DD HH:MM:SS
];

echo json_encode($response);

// 6. Giải phóng và đóng kết nối
mysqli_free_result($result);
mysqli_close($conn);
