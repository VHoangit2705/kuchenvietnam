<?php
// update_order_status.php
declare(strict_types=1);

ini_set('display_errors', '0');   // tránh notice chen vào JSON
error_reporting(E_ALL);

require 'config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function json_out(array $payload, int $code = 200): never {
    // Xóa toàn bộ buffer để không còn ký tự lạ/BOM làm hỏng JSON
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Yêu cầu không hợp lệ.'], 400);
}

$orderId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status  = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

if ($orderId <= 0 || $status === '') {
    json_out(['success' => false, 'message' => 'Thiếu tham số.'], 422);
}

try {
    $conn->begin_transaction();

    // 1) Cập nhật trạng thái đơn
    $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $orderId);
    $stmt->execute();
    $stmt->close();

    // 2) Nếu hủy đơn -> xóa các warranty_code theo order_product_id của order_id này
    $deleted = 0;
    if ($status === 'Đã hủy đơn hàng') {
        $sqlDelete = "
            DELETE FROM product_warranties
            WHERE order_product_id IN (
                SELECT id FROM order_products WHERE order_id = ?
            )
        ";
        $stmtDel = $conn->prepare($sqlDelete);
        $stmtDel->bind_param('i', $orderId);
        $stmtDel->execute();
        $deleted = $stmtDel->affected_rows;
        $stmtDel->close();
    }

    $conn->commit();
    json_out(['success' => true, 'deleted_warranties' => $deleted], 200);

} catch (Throwable $e) {
    // Nếu lỗi, rollback và trả JSON lỗi
    try { $conn->rollback(); } catch (\Throwable $ignore) {}
    json_out(['success' => false, 'message' => 'DB Error', 'detail' => $e->getMessage()], 500);
}
