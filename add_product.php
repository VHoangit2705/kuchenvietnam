<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

/* ================== CHECK ================== */
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Kết nối thất bại: ' . $conn->connect_error]));
}

if (!isset($_SESSION['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'Người dùng chưa đăng nhập.']);
    exit();
}
$editedBy = $_SESSION['full_name'];

/* ================== INPUT ================== */
$order_id        = intval($_POST['orderId']);
$productName     = trim($_POST['newProductName']);
$quantity        = intval($_POST['newQuantity']);
$unitPrice       = intval($_POST['newPrice']);
$priceDifference = intval($_POST['newPriceDifference']);
$isPromotion     = isset($_POST['newIsPromotion']) ? 1 : 0;
$warrantyScan    = isset($_POST['newwarranty_scan']) ? 0 : 1;

/* ================== CALC ================== */
$totalPrice   = ($quantity * $unitPrice) + $priceDifference;
$excludingVAT = 0;
$VAT          = "10%";
$VATPrice     = intval($totalPrice * 0.1);
$subAddress   = "Default Address";

/* ================== MAP PRODUCT ================== */
$productMasterId = 0;
$stmt = $conn->prepare("SELECT id, Maketoantmdt FROM products WHERE product_name = ? LIMIT 1");
$stmt->bind_param("s", $productName);
$stmt->execute();
$res = $stmt->get_result();

$newMAVT = null;
if ($row = $res->fetch_assoc()) {
    $productMasterId = (int)$row['id'];
    $newMAVT         = $row['Maketoantmdt'];
}
$stmt->close();

/* ================== TRANSACTION ================== */
$conn->begin_transaction();

try {
    /* ===== 1. INSERT order_products ===== */
    $stmtInsertProduct = $conn->prepare(
        "INSERT INTO order_products
        (order_id, product_id, product_name, quantity, excluding_VAT, VAT, VAT_price,
         price, price_difference, sub_address, is_promotion, warranty_scan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmtInsertProduct->bind_param(
        "iisiisiiisii",
        $order_id,
        $productMasterId,
        $productName,
        $quantity,
        $excludingVAT,
        $VAT,
        $VATPrice,
        $totalPrice,
        $priceDifference,
        $subAddress,
        $isPromotion,
        $warrantyScan
    );

    if (!$stmtInsertProduct->execute()) {
        throw new Exception('Lỗi thêm sản phẩm: ' . $stmtInsertProduct->error);
    }

    $orderProductId = $stmtInsertProduct->insert_id;
    $stmtInsertProduct->close();

    /* ===== 2. LẤY order_code2 ===== */
    $stmt = $conn->prepare("SELECT order_code2 FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $orderCode2 = null;
    if ($row = $res->fetch_assoc()) {
        $orderCode2 = $row['order_code2'];
    }
    $stmt->close();

    /* ===== 3. SHOPEE LOGIC (NÂNG CẤP) ===== */
    if (!empty($orderCode2) && !empty($newMAVT)) {

        // 3.1 Check đã tồn tại MAVT trong MaDonHang chưa
        $stmtCheck = $conn->prepare(
            "SELECT id FROM donhang_shopee
             WHERE MaDonHang = ? AND MAVT = ? LIMIT 1"
        );
        $stmtCheck->bind_param("ss", $orderCode2, $newMAVT);
        $stmtCheck->execute();
        $exists = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        // 3.2 Nếu CHƯA tồn tại → COPY & INSERT
        if (!$exists) {

            // Lấy dòng mẫu
            $stmtTpl = $conn->prepare(
                "SELECT * FROM donhang_shopee WHERE MaDonHang = ? LIMIT 1"
            );
            $stmtTpl->bind_param("s", $orderCode2);
            $stmtTpl->execute();
            $tpl = $stmtTpl->get_result()->fetch_assoc();
            $stmtTpl->close();

            if ($tpl) {
                $stmtInsertShopee = $conn->prepare(
                    "INSERT INTO donhang_shopee
                    (TrangThaiDonHang, MaDonHang, Ngaytaodon, NgayCapNhat,
                     MaKhoXuat, DVXuat, NguoiMuaHang,
                     MAVT, SoLuong, DonGia, ThanhTien, updated_at, MaGiaoDich)
                     VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, 0, 0, NOW(), ?)"
                );

                $stmtInsertShopee->bind_param(
                    "sssssssis",
                    $tpl['TrangThaiDonHang'],
                    $tpl['MaDonHang'],
                    $tpl['Ngaytaodon'],
                    $tpl['MaKhoXuat'],
                    $tpl['DVXuat'],
                    $tpl['NguoiMuaHang'],
                    $newMAVT,
                    $quantity,
                    $tpl['MaGiaoDich']
                );

                if (!$stmtInsertShopee->execute()) {
                    throw new Exception('Lỗi thêm Shopee: ' . $stmtInsertShopee->error);
                }

                $stmtInsertShopee->close();
            }
        }
    }

    /* ===== 4. HISTORY ===== */
    $stmtHistory = $conn->prepare(
        "INSERT INTO order_edit_history
        (order_id, action_type, product_id, product_name, quantity, price, edited_by, comments)
        VALUES (?, 'add', ?, ?, ?, ?, ?, ?)"
    );

    $comment = 'Thêm sản phẩm mới vào đơn hàng';
    $stmtHistory->bind_param(
        "iisisss",
        $order_id,
        $orderProductId,
        $productName,
        $quantity,
        $totalPrice,
        $editedBy,
        $comment
    );

    if (!$stmtHistory->execute()) {
        throw new Exception('Lỗi ghi lịch sử');
    }
    $stmtHistory->close();

    /* ===== 5. UPDATE ORDER ===== */
    $status = "Đang chờ quét QR";
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success'    => true,
        'message'    => 'Thêm sản phẩm thành công (Shopee được xử lý thông minh)',
        'productId'  => $orderProductId,
        'product_fk' => $productMasterId
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
