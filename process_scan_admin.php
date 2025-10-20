<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    echo "<script>alert('Bạn chưa đăng nhập! Vui lòng đăng nhập trước.'); window.location.href = 'index.php';</script>";
    exit();
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sn'])) {
    $snData = $_POST['sn'];
    $orderCode = $_POST['order_code'];
    $fullName = $_SESSION['full_name'];

    $conn->begin_transaction();

    try {
        $totalValidSN = 0;

        foreach ($snData as $orderProductId => $snList) {

            // Lấy thông tin order_product
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

            $order_id = $row['order_id'];
            $product_name = $row['product_name'];
            $redirectId = $order_id;
            // Kiểm tra có nhập tay không
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
                $sn = trim($sn);
                if ($sn === '') continue;

                if ($nhapTay === 1) {
                 // giả sử snnew lưu sẵn phần đầu cần ghép, ví dụ '2025010100'
                 $warranty_code = $snnewPrefix . $sn;
                 } else {
                 $warranty_code = $sn;
                }

                // Kiểm tra trùng SN
                $sqlDuplicate = "SELECT id FROM product_warranties WHERE warranty_code = ?";
                $stmtDuplicate = $conn->prepare($sqlDuplicate);
                if (!$stmtDuplicate) {
                    throw new Exception("Lỗi kiểm tra trùng lặp: " . $conn->error);
                }
                $stmtDuplicate->bind_param('s', $warranty_code);
                $stmtDuplicate->execute();
                $resultDuplicate = $stmtDuplicate->get_result();
                if ($resultDuplicate->num_rows > 0) {
                    throw new Exception("Cảnh báo : Mã SN '$warranty_code' đã tồn tại trong một đơn hàng khác trên hệ thống. Vui lòng báo quản trị viên để xử lý");
                }

                // Thêm vào bảng product_warranties
                $sqlInsert = "INSERT INTO product_warranties (order_product_id, warranty_code, name) VALUES (?, ?, ?)";
                $stmtInsert = $conn->prepare($sqlInsert);
                if (!$stmtInsert) {
                    throw new Exception("Lỗi khi chèn dữ liệu: " . $conn->error);
                }
                $stmtInsert->bind_param('iss', $orderProductId, $warranty_code, $fullName);
                $stmtInsert->execute();
                $totalValidSN++;

                // Lấy zone từ đơn hàng để cập nhật tồn kho
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

                    // Trừ tồn kho theo zone
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

        if ($totalValidSN === 0) {
            throw new Exception("Bạn chưa nhập mã SN nào hợp lệ.");
        }

        // Cập nhật trạng thái đơn hàng
        $sqlUpdateOrder = "UPDATE orders SET status = 'Đã quét QR' WHERE order_code2 = ? OR order_code1 = ?";
        $stmtUpdateOrder = $conn->prepare($sqlUpdateOrder);
        if (!$stmtUpdateOrder) {
            throw new Exception("Lỗi khi cập nhật đơn hàng: " . $conn->error);
        }
        $stmtUpdateOrder->bind_param('ss', $orderCode, $orderCode);
        $stmtUpdateOrder->execute();

        $conn->commit();
       echo "<script>alert('Đã them thành công mã bảo hành vào hệ thống!'); window.location.href = 'order_detail.php?id={$redirectId}';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Lỗi: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit();
    }
}
?>
