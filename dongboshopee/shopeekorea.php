<?php
// Kết nối MySQL
include '../config.php';
// 1. Cập nhật trạng thái ĐÃ HỦY cho các đơn đã tồn tại
$sqlCancelled = "
    SELECT ds.MaDonHang
    FROM donhang_shopee ds
    WHERE ds.TrangThaiDonHang = 'CANCELLED'
    AND ds.DVXuat = 'shopee Korea'
      AND EXISTS (
          SELECT 1 FROM orders o WHERE o.order_code2 = ds.MaDonHang
      )
";

$resultCancelled = $conn->query($sqlCancelled);
if ($resultCancelled->num_rows > 0) {
    while ($row = $resultCancelled->fetch_assoc()) {
        $maDon = $row['MaDonHang'];
        $update = $conn->prepare("UPDATE orders SET status = 'Đã hủy đơn hàng' WHERE order_code2 = ?");
        $update->bind_param("s", $maDon);
        $update->execute();
        echo "🗑️ Cập nhật trạng thái đơn <strong>$maDon</strong> thành <strong>Đã hủy đơn hàng</strong><br>";
    }
}

// Lấy danh sách mã đơn hàng chưa có trong bảng orders
$sql = "
    SELECT DISTINCT ds.MaDonHang, ds.NguoiMuaHang
    FROM donhang_shopee ds
    WHERE ds.TrangThaiDonHang != 'CANCELLED' 
      AND ds.TrangThaiDonHang != 'UNPAID'
      AND ds.DVXuat = 'shopee Korea'
      AND NOT EXISTS (
          SELECT 1 FROM orders o WHERE o.order_code2 = ds.MaDonHang
      )
";


$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($order = $result->fetch_assoc()) {
        $maDonHang = $order['MaDonHang'];
        $nguoiMuaHang = $order['NguoiMuaHang'];

        echo "<hr>";
        echo "📦 Xử lý đơn hàng <strong>$maDonHang</strong> cho khách <strong>$nguoiMuaHang</strong><br>";

        // Tính tổng tiền của đơn hàng này
        $sumQuery = $conn->prepare("SELECT SUM(ThanhTien) AS total FROM donhang_shopee WHERE MaDonHang = ?");
        $sumQuery->bind_param("s", $maDonHang);
        $sumQuery->execute();
        $sumResult = $sumQuery->get_result()->fetch_assoc();
        $thanhTien = (float)$sumResult['total'];

        // Tạo đơn hàng mới
        $orderInsert = "
            INSERT INTO orders (order_code2, customer_name, total_price,payment_method, note, status, status_tracking,staff, zone, type, shipping_unit,send_camon, send_khbh, ip_rate) 
            VALUES (?, ?, ?, 'Tiền mặt', 'Đơn hàng xuất tại Kho Hà Nội. Vui lòng điều chỉnh nếu xuất từ kho khác !!!', 'Đang chờ quét QR', 'Đang giao hàng', 
                      'Shopee HUROM KOREA', 'Đơn hàng HaNoi', 'shopee_korea', 'SPX',
                      0, 0, '')";
        $stmtOrder = $conn->prepare($orderInsert);
        $stmtOrder->bind_param("ssi", $maDonHang, $nguoiMuaHang, $thanhTien);
        $stmtOrder->execute();
        $orderId = $stmtOrder->insert_id;

        echo "📝 Đã tạo đơn hàng mới với ID: <strong>$orderId</strong><br>";

        // Lấy tất cả các dòng sản phẩm thuộc đơn hàng này
        $productQuery = $conn->prepare("SELECT * FROM donhang_shopee WHERE MaDonHang = ?");
        $productQuery->bind_param("s", $maDonHang);
        $productQuery->execute();
        $productsResult = $productQuery->get_result();

        while ($row = $productsResult->fetch_assoc()) {
            $mavt = $row['MAVT'];
            $soLuong = (int)$row['SoLuong'];
            $donGia = (float)$row['DonGia'];

            echo "🔍 Xử lý MAVT: <strong>$mavt</strong><br>";

            // Tìm sản phẩm tương ứng
            $productSql = "SELECT * FROM products WHERE Maketoantmdt = ? LIMIT 1";
            $stmt = $conn->prepare($productSql);
            $stmt->bind_param("s", $mavt);
            $stmt->execute();
            $productResult = $stmt->get_result();

            if ($product = $productResult->fetch_assoc()) {
                $productName = $product['product_name'];
                $is_promotion = isset($product['is_promotion']) ? (int)$product['is_promotion'] : 0;

                // Kiểm tra in tem để xác định cần quét QR không
                $no_warranty_scan = 1;
                if ($product['print'] == 1) {
                    $no_warranty_scan = 0;
                }

                $warranty_scan = $no_warranty_scan;

                // Thêm vào order_products
                $sql_product = "
                    INSERT INTO order_products 
                        (order_id, product_name, quantity, price, is_promotion, warranty_scan)
                    VALUES (?, ?, ?, ?, ?, ?)
                ";
                $stmtProd = $conn->prepare($sql_product);
                $stmtProd->bind_param("isisii", $orderId, $productName, $soLuong, $donGia, $is_promotion, $warranty_scan);
                $stmtProd->execute();

                echo "✅ Đã thêm sản phẩm <strong>$productName</strong> vào đơn hàng.<br>";
            } else {
                echo "❌ Không tìm thấy sản phẩm với MAVT: <strong>$mavt</strong><br>";
            }
        }

        // Kiểm tra lại trạng thái đơn hàng sau khi thêm xong sản phẩm
        $sql_check_khoa_tem = "
            SELECT COUNT(*) AS total, 
                   SUM(CASE WHEN p.khoa_tem = 1 THEN 1 ELSE 0 END) AS total_khoa_tem,
                   SUM(CASE WHEN p.print != 1 THEN 1 ELSE 0 END) AS total_need_qr
            FROM order_products op
            JOIN products p ON op.product_name = p.product_name
            WHERE op.order_id = ?
        ";
        $stmtCheck = $conn->prepare($sql_check_khoa_tem);
        $stmtCheck->bind_param("i", $orderId);
        $stmtCheck->execute();
        $row_khoa_tem = $stmtCheck->get_result()->fetch_assoc();

        if ($row_khoa_tem) {
            $total = (int)$row_khoa_tem['total'];
            $total_khoa_tem = (int)$row_khoa_tem['total_khoa_tem'];
            $total_need_qr = (int)$row_khoa_tem['total_need_qr'];

            if ($total > 0 && $total === $total_khoa_tem) {
                $status = "Đã quét QR";
            } elseif ($total_need_qr === 0) {
                $status = "Hàng linh kiện";
            } else {
                $status = "Đang chờ quét QR";
            }
        } else {
            $status = "Đang chờ quét QR";
        }

        // Cập nhật trạng thái đơn hàng
        $updateStatus = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $updateStatus->bind_param("si", $status, $orderId);
        $updateStatus->execute();

        echo "📌 Trạng thái đơn hàng cập nhật: <strong>$status</strong><br>";
    }
} else {
    echo "✅ Không có đơn hàng mới cần xử lý.<br>";
}

$conn->close();
?>
