<?php
session_start();
header('Content-Type: application/json');

// Kết nối đến cơ sở dữ liệu
include 'config.php';

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Kết nối thất bại: ' . $conn->connect_error]));
}

// Kiểm tra xem biến session $_SESSION['full_name'] đã tồn tại hay chưa
if (!isset($_SESSION['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'Người dùng chưa đăng nhập.']);
    exit();
}

// Gán giá trị session vào biến $editedBy
$editedBy = $_SESSION['full_name'];

// Nhận dữ liệu từ form
$order_id = intval($_POST['orderId']);
$productName = $_POST['newProductName'];
$quantity = intval($_POST['newQuantity']);
$unitPrice = intval($_POST['newPrice']); // đây là đơn giá từ form
$priceDifference = intval($_POST['newPriceDifference']);
$isPromotion = isset($_POST['newIsPromotion']) ? 1 : 0;
$warrantyScan = isset($_POST['newwarranty_scan']) ? 0 : 1;

// Tính tổng giá tiền sản phẩm (số lượng x đơn giá + chênh lệch nếu có)
$totalPrice = ($quantity * $unitPrice) + $priceDifference;

// Các giá trị mặc định cho các cột khác
$excludingVAT = 0;
$VAT = "10%";
$VATPrice = intval($totalPrice * 0.1); 
$subAddress = "Default Address";

// Bắt đầu giao dịch
$conn->begin_transaction();

try {
    // Thêm sản phẩm mới vào bảng order_products
    $sqlInsertProduct = "
        INSERT INTO order_products 
            (order_id, product_name, quantity, excluding_VAT, VAT, VAT_price, price, price_difference, sub_address, is_promotion, warranty_scan) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsertProduct = $conn->prepare($sqlInsertProduct);
    $stmtInsertProduct->bind_param(
        "isiiisiiisi",
        $order_id,
        $productName,
        $quantity,
        $excludingVAT,
        $VAT,
        $VATPrice,
        $totalPrice,        // <-- dùng tổng giá thay vì đơn giá
        $priceDifference,
        $subAddress,
        $isPromotion,
        $warrantyScan
    );

    if (!$stmtInsertProduct->execute()) {
        throw new Exception('Lỗi thêm sản phẩm mới: ' . $stmtInsertProduct->error);
    }

    $productId = $stmtInsertProduct->insert_id;

    // Ghi lịch sử
    $actionType = 'add';
    $comments = 'Thêm sản phẩm mới vào đơn hàng';
    $sqlInsertHistory = "
        INSERT INTO order_edit_history 
            (order_id, action_type, product_id, product_name, quantity, price, edited_by, comments) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsertHistory = $conn->prepare($sqlInsertHistory);
    $stmtInsertHistory->bind_param(
        "isisiiss",
        $order_id,
        $actionType,
        $productId,
        $productName,
        $quantity,
        $totalPrice,   // <-- lưu đúng tổng giá vào lịch sử
        $editedBy,
        $comments
    );

    if (!$stmtInsertHistory->execute()) {
        throw new Exception('Lỗi ghi lịch sử chỉnh sửa: ' . $stmtInsertHistory->error);
    }

    // Update trạng thái đơn hàng
    $status = "Đang chờ quét QR";
    $sqlUpdateOrderStatus = "UPDATE orders SET status = ? WHERE id = ?";
    $stmtUpdateOrderStatus = $conn->prepare($sqlUpdateOrderStatus);
    $stmtUpdateOrderStatus->bind_param("si", $status, $order_id);
    $stmtUpdateOrderStatus->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Sản phẩm mới đã được thêm, lịch sử chỉnh sửa đã được lưu và trạng thái đơn hàng đã được cập nhật.',
        'productId' => $productId
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($stmtInsertProduct)) $stmtInsertProduct->close();
    if (isset($stmtInsertHistory)) $stmtInsertHistory->close();
    if (isset($stmtUpdateOrderStatus)) $stmtUpdateOrderStatus->close();
    $conn->close();
}
?>
