<?php
// get_order_products.php

include '../config.php'; // File cấu hình kết nối CSDL, biến $conn

// Kiểm tra nếu orderId được truyền qua POST
if (!isset($_POST['orderId'])) {
    echo "Thiếu thông số orderId.";
    exit;
}

$orderId = $_POST['orderId'];

// Truy vấn bảng order_products theo order_id
$sql = "SELECT product_name, quantity FROM order_products WHERE order_id = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo "Prepare failed: " . mysqli_error($conn);
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $orderId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Hiển thị danh sách sản phẩm dưới dạng bảng
if (mysqli_num_rows($result) > 0) {
    echo '<table class="table table-bordered">';
    echo '<thead class="thead-light"><tr><th>STT</th><th>Sản phẩm</th><th>Số lượng</th></tr></thead><tbody>';
    $stt = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $product_name = htmlspecialchars($row['product_name']);
        $quantity = htmlspecialchars($row['quantity']);
        echo "<tr>
                <td>{$stt}</td>
                <td>{$product_name}</td>
                <td>{$quantity}</td>
              </tr>";
        $stt++;
    }
    echo '</tbody></table>';
} else {
    echo "<p>Không có sản phẩm nào trong đơn hàng này.</p>";
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
