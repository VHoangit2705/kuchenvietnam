<?php
include 'config.php';
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

$sql_orders = "SELECT order_code1, status_tracking FROM orders";
$result_orders = $conn->query($sql_orders);

if ($result_orders->num_rows > 0) {
    while ($order = $result_orders->fetch_assoc()) {
        $order_code1 = $order['order_code1'];
        $current_status = trim($order['status_tracking']); // lấy trạng thái hiện tại trong bảng orders

        if (trim($order_code1) === "Khách nhận hàng tại kho Droppii") {
            echo "⏭️ Bỏ qua đơn hàng đặc biệt: <strong>$order_code1</strong><br>";
            continue;
        }

        $sql_tracking = "SELECT status FROM order_tracking WHERE order_code = ? LIMIT 1";
        $stmt_tracking = $conn->prepare($sql_tracking);
        $stmt_tracking->bind_param("s", $order_code1);
        $stmt_tracking->execute();
        $result_tracking = $stmt_tracking->get_result();

        if ($result_tracking->num_rows > 0) {
            $tracking = $result_tracking->fetch_assoc();
            $new_status = trim($tracking['status']);

            // Chỉ cập nhật nếu trạng thái mới khác trạng thái hiện tại
            if ($new_status !== $current_status) {
                $sql_update = "UPDATE orders SET status_tracking = ? WHERE order_code1 = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("ss", $new_status, $order_code1);

                if ($stmt_update->execute()) {
                    echo "✅ Đã cập nhật trạng thái cho đơn hàng <strong>$order_code1</strong> từ <em>" . ($current_status ?: "[trống]") . "</em> thành <em>" . ($new_status ?: "[trống]") . "</em><br>";
                } else {
                    echo "❌ Lỗi khi cập nhật đơn hàng <strong>$order_code1</strong>: " . $stmt_update->error . "<br>";
                }

                $stmt_update->close();
            } else {
                echo "ℹ️ Đơn hàng <strong>$order_code1</strong> đã có đúng trạng thái '<em>$new_status</em>' - bỏ qua cập nhật.<br>";
            }
        } else {
            echo "⚠️ Không tìm thấy trạng thái cho đơn hàng <strong>$order_code1</strong> trong bảng order_tracking.<br>";
        }

        $stmt_tracking->close();
    }
} else {
    echo "Không có đơn hàng nào trong bảng orders.<br>";
}

$conn->close();
?>
