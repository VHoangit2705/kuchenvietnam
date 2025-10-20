<?php
session_start();

include 'config.php';

// Kiểm tra kết nối cơ sở dữ liệu
if ($conn->connect_error) {
    die("Kết nối không thành công: " . $conn->connect_error);
}

// Nhận ID sản phẩm từ URL
$productId = $_GET['id'] ?? null;

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'ID sản phẩm không hợp lệ.']);
    exit();
}

// Bắt đầu giao dịch
$conn->begin_transaction();

try {
    // Lấy thông tin chi tiết sản phẩm
    $sqlGetProductDetails = "SELECT order_id, product_name, quantity, price FROM order_products WHERE id = $productId";
    $result = $conn->query($sqlGetProductDetails);
    $productDetails = $result->fetch_assoc();

    if (!$productDetails) {
        throw new Exception('Không tìm thấy sản phẩm với ID: ' . $productId);
    }

    $orderId = $productDetails['order_id'];
    $productName = $productDetails['product_name'];
    $quantity = $productDetails['quantity'];
    $price = $productDetails['price'];

    // Ghi lịch sử chỉnh sửa
    $actionType = 'delete';
    $editedBy = $_SESSION['full_name'] ?? 'unknown_user';
    $comments = 'Xóa sản phẩm khỏi đơn hàng';

    $sqlLogDelete = "INSERT INTO order_edit_history 
        (order_id, action_type, product_id, product_name, quantity, price, edited_by, comments) 
        VALUES ($orderId, '$actionType', $productId, '$productName', $quantity, $price, '$editedBy', '$comments')";

    if (!$conn->query($sqlLogDelete)) {
        throw new Exception('Lỗi lưu lịch sử xóa sản phẩm: ' . $conn->error);
    }

    // Xóa mã bảo hành đã quét nếu có
    $sqlCheckWarrantyExist = "SELECT COUNT(*) as count FROM product_warranties WHERE order_product_id = $productId";
    $resultWarrantyCheck = $conn->query($sqlCheckWarrantyExist);
    $rowWarrantyCheck = $resultWarrantyCheck->fetch_assoc();

    if ($rowWarrantyCheck['count'] > 0) {
        // Xóa mã bảo hành nếu tồn tại
        $sqlDeleteWarranties = "DELETE FROM product_warranties WHERE order_product_id = $productId";

        if (!$conn->query($sqlDeleteWarranties)) {
            throw new Exception('Lỗi xóa mã bảo hành: ' . $conn->error);
        }
    }

    // Xóa sản phẩm khỏi bảng order_products
    $sqlDeleteProduct = "DELETE FROM order_products WHERE id = $productId";
    if (!$conn->query($sqlDeleteProduct)) {
        throw new Exception('Lỗi xóa sản phẩm: ' . $conn->error);
    }

    // Cập nhật trạng thái đơn hàng
    $sqlUpdateOrder = "UPDATE orders SET status = 'Đang chờ quét QR' WHERE id = $orderId";
    if (!$conn->query($sqlUpdateOrder)) {
        throw new Exception('Lỗi cập nhật trạng thái đơn hàng: ' . $conn->error);
    }

    // Commit giao dịch
    $conn->commit();

    // Thiết lập thông báo thành công vào session
    $_SESSION['success_message'] = 'Sản phẩm đã được xóa thành công!';

    // Redirect về trang chi tiết đơn hàng
    header("Location: order_detail.php?id=" . $orderId);
    exit();
} catch (Exception $e) {
    // Rollback nếu có lỗi
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
} finally {
    $conn->close();
}
?>
