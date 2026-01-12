<?php
// ===============================
// KẾT NỐI DATABASE
// ===============================
include '../config.php';
$conn->set_charset('utf8mb4');

// ===============================
// 1. CẬP NHẬT ĐƠN ĐÃ HỦY
// ===============================
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

if ($resultCancelled && $resultCancelled->num_rows > 0) {
    while ($row = $resultCancelled->fetch_assoc()) {
        $maDon = $row['MaDonHang'];

        $stmt = $conn->prepare(
            "UPDATE orders SET status = 'Đã hủy đơn hàng' WHERE order_code2 = ?"
        );
        $stmt->bind_param("s", $maDon);
        $stmt->execute();

        echo "🗑️ Đã cập nhật đơn <b>$maDon</b> → Đã hủy đơn hàng<br>";
    }
}

// ===============================
// 2. LẤY ĐƠN SHOPEE CHƯA CÓ TRONG ORDERS
// ===============================
$sqlOrders = "
    SELECT DISTINCT ds.MaDonHang, ds.NguoiMuaHang
    FROM donhang_shopee ds
    WHERE ds.TrangThaiDonHang NOT IN ('CANCELLED', 'UNPAID')
      AND ds.DVXuat = 'shopee Korea'
      AND NOT EXISTS (
          SELECT 1 FROM orders o WHERE o.order_code2 = ds.MaDonHang
      )
";

$result = $conn->query($sqlOrders);

if (!$result || $result->num_rows === 0) {
    echo "✅ Không có đơn hàng mới cần xử lý.";
    exit;
}

// ===============================
// 3. XỬ LÝ TỪNG ĐƠN HÀNG
// ===============================
while ($order = $result->fetch_assoc()) {

    $maDonHang     = $order['MaDonHang'];
    $nguoiMuaHang  = $order['NguoiMuaHang'];

    echo "<hr>";
    echo "📦 Xử lý đơn <b>$maDonHang</b> – KH: <b>$nguoiMuaHang</b><br>";

    // -------------------------------
    // TÍNH TỔNG TIỀN
    // -------------------------------
    $stmtSum = $conn->prepare(
        "SELECT SUM(ThanhTien) AS total FROM donhang_shopee WHERE MaDonHang = ?"
    );
    $stmtSum->bind_param("s", $maDonHang);
    $stmtSum->execute();
    $sum = $stmtSum->get_result()->fetch_assoc();
    $totalPrice = (float)($sum['total'] ?? 0);

    // -------------------------------
    // TẠO ORDER
    // -------------------------------
    $created_at = date('Y-m-d H:i:s');

    $stmtOrder = $conn->prepare("
        INSERT INTO orders (
            order_code2, customer_name, total_price,
            payment_method, created_at, note,
            status, status_tracking, staff,
            zone, type, shipping_unit,
            send_camon, send_khbh, ip_rate
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $payment_method  = 'Tiền mặt';
    $note            = 'Đơn hàng xuất tại Kho Hà Nội. Vui lòng điều chỉnh nếu xuất từ kho khác !!!';
    $status          = 'Đang chờ quét QR';
    $status_tracking = 'Đang giao hàng';
    $staff           = 'Shopee HUROM KOREA';
    $zone            = 'Đơn hàng HaNoi';
    $type            = 'shopee_korea';
    $shipping_unit   = 'SPX';
    $send_camon      = 0;
    $send_khbh       = 0;
    $ip_rate         = '';

    $stmtOrder->bind_param(
        "ssdsssssssssiis",
        $maDonHang,
        $nguoiMuaHang,
        $totalPrice,
        $payment_method,
        $created_at,
        $note,
        $status,
        $status_tracking,
        $staff,
        $zone,
        $type,
        $shipping_unit,
        $send_camon,
        $send_khbh,
        $ip_rate
    );

    $stmtOrder->execute();
    $orderId = $stmtOrder->insert_id;

    echo "📝 Đã tạo order ID: <b>$orderId</b><br>";

    // -------------------------------
    // 4. THÊM SẢN PHẨM
    // -------------------------------
    $stmtItems = $conn->prepare(
        "SELECT * FROM donhang_shopee WHERE MaDonHang = ?"
    );
    $stmtItems->bind_param("s", $maDonHang);
    $stmtItems->execute();
    $items = $stmtItems->get_result();

    while ($item = $items->fetch_assoc()) {

        $mavt     = $item['MAVT'];
        $qty      = (int)$item['SoLuong'];
        $price    = (float)$item['DonGia'];

        echo "🔍 MAVT: <b>$mavt</b><br>";

        // LẤY SẢN PHẨM + ID
        $stmtProdFind = $conn->prepare(
            "SELECT id, product_name, print, is_promotion 
             FROM products 
             WHERE Maketoantmdt = ?
             LIMIT 1"
        );
        $stmtProdFind->bind_param("s", $mavt);
        $stmtProdFind->execute();
        $product = $stmtProdFind->get_result()->fetch_assoc();

        if (!$product) {
            echo "❌ Không tìm thấy sản phẩm: <b>$mavt</b><br>";
            continue;
        }

        $productId   = (int)$product['id'];          // ✅ ID SẢN PHẨM
        $productName = $product['product_name'];
        $isPromotion = (int)($product['is_promotion'] ?? 0);

        // LOGIC QUÉT QR
        $warranty_scan = ($product['print'] == 1) ? 0 : 1;

        // INSERT ORDER_PRODUCTS (ĐÃ CÓ product_id)
        $stmtInsertOp = $conn->prepare("
            INSERT INTO order_products (
                order_id, product_id, product_name,
                quantity, price, is_promotion, warranty_scan
            ) VALUES (?,?,?,?,?,?,?)
        ");

        $stmtInsertOp->bind_param(
            "iisidii",
            $orderId,
            $productId,
            $productName,
            $qty,
            $price,
            $isPromotion,
            $warranty_scan
        );

        $stmtInsertOp->execute();

        echo "✅ Thêm SP: <b>$productName</b> (ID: $productId)<br>";
    }

    // -------------------------------
    // 5. XÁC ĐỊNH TRẠNG THÁI ĐƠN
    // -------------------------------
    $stmtCheck = $conn->prepare("
        SELECT COUNT(*) total,
               SUM(p.khoa_tem = 1) total_khoa_tem,
               SUM(p.print != 1) total_need_qr
        FROM order_products op
        JOIN products p ON op.product_id = p.id
        WHERE op.order_id = ?
    ");
    $stmtCheck->bind_param("i", $orderId);
    $stmtCheck->execute();
    $chk = $stmtCheck->get_result()->fetch_assoc();

    if ($chk['total'] > 0 && $chk['total'] == $chk['total_khoa_tem']) {
        $status = 'Đã quét QR';
    } elseif ($chk['total_need_qr'] == 0) {
        $status = 'Hàng linh kiện';
    } else {
        $status = 'Đang chờ quét QR';
    }

    $stmtUpdate = $conn->prepare(
        "UPDATE orders SET status = ? WHERE id = ?"
    );
    $stmtUpdate->bind_param("si", $status, $orderId);
    $stmtUpdate->execute();

    echo "📌 Trạng thái cuối: <b>$status</b><br>";
}

$conn->close();
