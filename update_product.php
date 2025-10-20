<?php
session_start();
include 'config.php';

// Kiểm tra kết nối
if ($conn->connect_error) {
    die('Kết nối thất bại: ' . $conn->connect_error);
}

// Nhận dữ liệu từ form
$order_id = $_POST['orderId'];
$productId = $_POST['productId'];
$quantity = $_POST['quantity'];
$initialQuantity = $_POST['initialQuantity'];
$priceDifference = $_POST['priceDifference'];
$name = $_POST['productName'];

// Gán giá trị từ session cho người sửa sản phẩm
$editedBy = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Không xác định';

// Kiểm tra ô checkbox
$isPromotion = isset($_POST['newIsPromotion']) ? 1 : 0;
$warrantyScan = 1; // Mặc định: yêu cầu quét QR

$sql_check = "SELECT `print` FROM products WHERE product_name = '$name' LIMIT 1";
$result_check = $conn->query($sql_check);
if ($result_check && $result_check->num_rows > 0) {
    $row = $result_check->fetch_assoc();
    $warrantyScan = ($row['print'] == 1) ? 0 : 1; // Nếu print = 1 thì KHÔNG quét
}

// Cập nhật sản phẩm
$sql = "UPDATE order_products SET quantity = ?, price_difference = ?, is_promotion = ?, warranty_scan = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("idiii", $quantity, $priceDifference, $isPromotion, $warrantyScan, $productId);

if ($stmt->execute()) {
    // Kiểm tra nếu số lượng thay đổi
    if ($quantity != $initialQuantity) {
        // Cập nhật trạng thái của đơn hàng
        $updateOrderStatusSql = "UPDATE orders SET status = 'Đang chờ quét QR' WHERE id = ?";
        $stmtUpdateStatus = $conn->prepare($updateOrderStatusSql);
        $stmtUpdateStatus->bind_param("i", $order_id);

        if (!$stmtUpdateStatus->execute()) {
            echo 'Lỗi cập nhật trạng thái đơn hàng: ' . $stmtUpdateStatus->error;
            $stmtUpdateStatus->close();
            $stmt->close();
            $conn->close();
            exit;
        }

        // Đóng kết nối
        $stmtUpdateStatus->close();
    }

    // Lưu lịch sử chỉnh sửa vào bảng order_edit_history
    $actionType = 'edit';  // Loại hành động (sửa sản phẩm)
    $comments = 'Chỉnh sửa sản phẩm';

    // Câu lệnh INSERT hợp lệ
    $sqlInsertHistory = "
        INSERT INTO order_edit_history (order_id, action_type, product_id, product_name, quantity_old, quantity_new, edited_by, comments) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsertHistory = $conn->prepare($sqlInsertHistory);
    $stmtInsertHistory->bind_param("isisiiss", $order_id, $actionType, $productId, $name, $initialQuantity, $quantity, $editedBy, $comments);

    // Thực thi câu lệnh lưu lịch sử
    if ($stmtInsertHistory->execute()) {
        // Cập nhật thành công, chuyển hướng về trang chi tiết đơn hàng
        header('Location: order_detail.php?id=' . $order_id);
        exit;
    } else {
        echo 'Lỗi lưu lịch sử: ' . $stmtInsertHistory->error;
    }

    // Đóng kết nối
    $stmtInsertHistory->close();
} else {
    echo 'Lỗi cập nhật sản phẩm: ' . $stmt->error;
}

// Đóng kết nối
$stmt->close();
$conn->close();
?>
