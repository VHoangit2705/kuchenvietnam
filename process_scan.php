<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    echo "<script>alert('Bạn chưa đăng nhập! Vui lòng đăng nhập trước.'); window.location.href = 'index.php';</script>";
    exit();
}

include 'config.php'; // Kết nối cơ sở dữ liệu

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sn'])) {
    $snData = $_POST['sn'];
    $orderCode = $_POST['order_code'];
    $fullName = $_SESSION['full_name'];
    $position = $_SESSION['position'] ?? '';
    $conn->begin_transaction();

    try {
        foreach ($snData as $orderProductId => $snList) {
            // ✅ Nếu không phải admin, bắt buộc phải nhập đầy đủ SN và không được bỏ trống
            if ($position !== 'admin') {
                foreach ($snList as $sn) {
                    if (empty(trim($sn))) {
                        throw new Exception("Tất cả các mã SN phải được nhập đầy đủ cho sản phẩm có ID: " . $orderProductId);
                    }
                }
            } else {
                // ✅ Nếu là admin, loại bỏ các SN trống (chấp nhận thiếu)
                $snList = array_filter($snList, function ($sn) {
                    return trim($sn) !== '';
                });
            }

            // 1. Lấy thông tin order_product
            $sqlCheck = "SELECT quantity, order_id, product_name FROM order_products WHERE id = ?";
            $stmtCheck = $conn->prepare($sqlCheck);
            if (!$stmtCheck) {
                throw new Exception("Lỗi truy vấn: " . $conn->error);
            }
            $stmtCheck->bind_param('i', $orderProductId);
            $stmtCheck->execute();
            $resultCheck = $stmtCheck->get_result();
            if (!$row = $resultCheck->fetch_assoc()) {
                throw new Exception("Không tìm thấy thông tin sản phẩm cho orderProductId: " . $orderProductId);
            }

            $expectedQuantity = (int)$row['quantity'];
            $order_id = $row['order_id'];
            $product_name = $row['product_name'];

            // ✅ Kiểm tra số lượng SN có đủ không (admin được phép thiếu)
            if (count($snList) !== $expectedQuantity) {
                if ($position !== 'admin') {
                    throw new Exception("Số lượng mã SN không khớp với số lượng sản phẩm cho sản phẩm '$product_name'.");
                }
            }

            // 2. Kiểm tra có nhập tay không
            $nhapTay = 0;
            $sqlProduct = "SELECT nhap_tay, snnew FROM products WHERE product_name = ?";
            $stmtProduct = $conn->prepare($sqlProduct);
            if ($stmtProduct) {
                $stmtProduct->bind_param('s', $product_name);
                $stmtProduct->execute();
                $resultProduct = $stmtProduct->get_result();
                if ($rowProduct = $resultProduct->fetch_assoc()) {
                    $nhapTay = (int)$rowProduct['nhap_tay'];
                    $snnewPrefix = $rowProduct['snnew'];
                }
            }

            foreach ($snList as $sn) {
                if ($nhapTay === 1) {
                     // giả sử snnew lưu sẵn phần đầu cần ghép, ví dụ '2025010100'
                     $warranty_code = $snnewPrefix . $sn;
                   }else {
                    $warranty_code = $sn;
                 }

                // 3. Kiểm tra trùng SN
                $sqlDuplicate = "SELECT id FROM product_warranties WHERE warranty_code = ?";
                $stmtDuplicate = $conn->prepare($sqlDuplicate);
                if (!$stmtDuplicate) {
                    throw new Exception("Lỗi kiểm tra trùng lặp: " . $conn->error);
                }
                $stmtDuplicate->bind_param('s', $warranty_code);
                $stmtDuplicate->execute();
                $resultDuplicate = $stmtDuplicate->get_result();
                if ($resultDuplicate->num_rows > 0) {
                    throw new Exception("Cảnh báo : Mã SN '$warranty_code' đã tồn tại trong một đơn hàng khác trên hệ thống.Vui lòng báo quản trị viên để xử lý");
                }

                // 4. Thêm vào bảng product_warranties
                $sqlInsert = "INSERT INTO product_warranties (order_product_id, warranty_code, name) VALUES (?, ?, ?)";
                $stmtInsert = $conn->prepare($sqlInsert);
                if (!$stmtInsert) {
                    throw new Exception("Lỗi khi chèn dữ liệu: " . $conn->error);
                }
                $stmtInsert->bind_param('iss', $orderProductId, $warranty_code, $fullName);
                $stmtInsert->execute();

                // 5. Lấy zone từ đơn hàng để cập nhật tồn kho
                $sqlZone = "SELECT zone FROM orders WHERE id = ?";
                $stmtZone = $conn->prepare($sqlZone);
                if (!$stmtZone) {
                    throw new Exception("Prepare failed (zone): " . $conn->error);
                }
                $stmtZone->bind_param('i', $order_id);
                $stmtZone->execute();
                $resultZone = $stmtZone->get_result();
                if ($rowZone = $resultZone->fetch_assoc()) {
                    $zone = $rowZone['zone'];
                    if ($zone == 'Đơn hàng Vinh') {
                        $updateField = 'stock_vinh';
                    } elseif ($zone == 'Đơn hàng HaNoi') {
                        $updateField = 'stock_hanoi';
                    } elseif ($zone == 'Đơn hàng HCM') {
                        $updateField = 'stock_hcm';
                    } else {
                        throw new Exception("Zone không hợp lệ cho đơn hàng: " . $order_id);
                    }

                    // 6. Trừ tồn kho theo zone
                    $sqlUpdateStock = "UPDATE products SET $updateField = $updateField - 1 WHERE product_name = ?";
                    $stmtUpdateStock = $conn->prepare($sqlUpdateStock);
                    if (!$stmtUpdateStock) {
                        throw new Exception("Prepare failed (update stock): " . $conn->error);
                    }
                    $stmtUpdateStock->bind_param('s', $product_name);
                    $stmtUpdateStock->execute();
                } else {
                    throw new Exception("Không tìm thấy thông tin zone cho đơn hàng: " . $order_id);
                }
            }
        }

        // 7. Cập nhật trạng thái đơn hàng
        $sqlUpdateOrder = "UPDATE orders SET status = 'Đã quét QR' WHERE order_code2 = ? OR order_code1 = ?";
        $stmtUpdateOrder = $conn->prepare($sqlUpdateOrder);
        if (!$stmtUpdateOrder) {
            throw new Exception("Lỗi khi cập nhật đơn hàng: " . $conn->error);
        }
        $stmtUpdateOrder->bind_param('ss', $orderCode, $orderCode);
        $stmtUpdateOrder->execute();

        $conn->commit();
        echo "<script>alert('Đã quét mã bảo hành đơn hàng thành công!'); window.location.href = 'xem_donhang.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Lỗi: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit();
    }
}
?>
