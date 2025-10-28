<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Kết nối thất bại: ' . $conn->connect_error]));
}
if (!isset($_SESSION['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'Người dùng chưa đăng nhập.']);
    exit();
}
$editedBy = $_SESSION['full_name'];

/* ===== Nhận input ===== */
$order_id        = intval($_POST['orderId']);
$productName     = $_POST['newProductName'];
$quantity        = intval($_POST['newQuantity']);
$unitPrice       = intval($_POST['newPrice']);            // đơn giá từ form
$priceDifference = intval($_POST['newPriceDifference']);
$isPromotion     = isset($_POST['newIsPromotion'])   ? 1 : 0;
$warrantyScan    = isset($_POST['newwarranty_scan']) ? 0 : 1;

/* ===== Tính toán giữ nguyên ===== */
$totalPrice   = ($quantity * $unitPrice) + $priceDifference;
$excludingVAT = 0;
$VAT          = "10%";
$VATPrice     = intval($totalPrice * 0.1);
$subAddress   = "Default Address";

/* ===== Lấy product_id theo product_name (match chính xác) ===== */
$productMasterId = null;
$stmtFind = $conn->prepare("SELECT id FROM products WHERE product_name = ? LIMIT 1");
$stmtFind->bind_param("s", $productName);
$stmtFind->execute();
$resFind = $stmtFind->get_result();
if ($row = $resFind->fetch_assoc()) {
    $productMasterId = (int)$row['id'];
}
$stmtFind->close();
/* Nếu không tìm thấy product_id, vẫn cho phép null/0 để không chặn thao tác */
if ($productMasterId === null) $productMasterId = 0;

/* ===== Giao dịch ===== */
$conn->begin_transaction();

try {
    // INSERT vào order_products: thêm cột product_id ngay trước product_name
    $sqlInsertProduct = "
        INSERT INTO order_products 
            (order_id, product_id, product_name, quantity, excluding_VAT, VAT, VAT_price, price, price_difference, sub_address, is_promotion, warranty_scan) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsertProduct = $conn->prepare($sqlInsertProduct);
    // Kiểu dữ liệu: i i s i i s i i i s i i  -> "iisiisiiisii"
    $stmtInsertProduct->bind_param(
        "iisiisiiisii",
        $order_id,
        $productMasterId,
        $productName,
        $quantity,
        $excludingVAT,
        $VAT,
        $VATPrice,
        $totalPrice,        // vẫn lưu tổng giá như logic cũ
        $priceDifference,
        $subAddress,
        $isPromotion,
        $warrantyScan
    );

    if (!$stmtInsertProduct->execute()) {
        throw new Exception('Lỗi thêm sản phẩm mới: ' . $stmtInsertProduct->error);
    }

    // ID của dòng order_products vừa thêm
    $orderProductId = $stmtInsertProduct->insert_id;

    // Ghi lịch sử (giữ nguyên cấu trúc bảng hiện tại)
    $actionType = 'add';
    $comments   = 'Thêm sản phẩm mới vào đơn hàng';
    $sqlInsertHistory = "
        INSERT INTO order_edit_history 
            (order_id, action_type, product_id, product_name, quantity, price, edited_by, comments) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsertHistory = $conn->prepare($sqlInsertHistory);
    // Lưu product_id ở đây CHÍNH LÀ id của order_products (giữ nguyên logic cũ)
    $stmtInsertHistory->bind_param(
        "isisiiss",
        $order_id,
        $actionType,
        $orderProductId,
        $productName,
        $quantity,
        $totalPrice,
        $editedBy,
        $comments
    );
    if (!$stmtInsertHistory->execute()) {
        throw new Exception('Lỗi ghi lịch sử chỉnh sửa: ' . $stmtInsertHistory->error);
    }

    // Update trạng thái đơn hàng (giữ nguyên)
    $status = "Đang chờ quét QR";
    $sqlUpdateOrderStatus = "UPDATE orders SET status = ? WHERE id = ?";
    $stmtUpdateOrderStatus = $conn->prepare($sqlUpdateOrderStatus);
    $stmtUpdateOrderStatus->bind_param("si", $status, $order_id);
    $stmtUpdateOrderStatus->execute();

    $conn->commit();

    echo json_encode([
        'success'      => true,
        'message'      => 'Sản phẩm mới đã được thêm, lịch sử chỉnh sửa đã được lưu và trạng thái đơn hàng đã được cập nhật.',
        'productId'    => $orderProductId,  // id dòng order_products
        'product_fk'   => $productMasterId  // id sản phẩm (products.id) đã map
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($stmtInsertProduct))   $stmtInsertProduct->close();
    if (isset($stmtInsertHistory))   $stmtInsertHistory->close();
    if (isset($stmtUpdateOrderStatus)) $stmtUpdateOrderStatus->close();
    $conn->close();
}
?>
