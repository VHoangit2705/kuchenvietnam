<?php
session_start();

header_remove('X-Powered-By');

function wants_json() {
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return ($xrw === 'xmlhttprequest') || (stripos($accept, 'application/json') !== false);
}

function send_json($arr, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_error($code, $message, $http_code = 400, $extra = []) {
    $payload = array_merge([
        'success'    => false,
        'error_code' => $code,
        'message'    => $message,
    ], $extra);
    if (wants_json()) {
        send_json($payload, $http_code);
    } else {
        echo "<script>alert('Lỗi ($code): " . addslashes($message) . "'); window.history.back();</script>";
        exit;
    }
}

function send_success($message = 'Đã quét mã bảo hành đơn hàng thành công!', $summary = []) {
    if (wants_json()) {
        send_json(['success' => true, 'message' => $message, 'summary' => $summary], 200);
    } else {
        echo "<script>alert('".addslashes($message)."'); window.location.href = 'xem_donhang.php';</script>";
        exit;
    }
}

if (!isset($_SESSION['full_name'])) {
    if (wants_json()) {
        send_error('UNAUTHENTICATED', 'Bạn chưa đăng nhập! Vui lòng đăng nhập trước.', 401);
    } else {
        echo "<script>alert('Bạn chưa đăng nhập! Vui lòng đăng nhập trước.'); window.location.href = 'index.php';</script>";
        exit();
    }
}

include 'config.php'; // Kết nối CSDL

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sn'])) {

    $snData    = $_POST['sn'];            // sn[order_product_id][] = value
    $orderCode = $_POST['order_code'] ?? '';
    $fullName  = $_SESSION['full_name'];
    $position  = $_SESSION['position'] ?? '';

    $conn->begin_transaction();

    try {
        $totalInserted = 0;

        foreach ($snData as $orderProductId => $snList) {
            if ($position !== 'admin') {
                foreach ($snList as $sn) {
                    if (trim($sn) === '') {
                        throw new Exception('[VALIDATION_EMPTY_SN] Tất cả các mã SN phải được nhập đầy đủ cho sản phẩm có ID: ' . (int)$orderProductId);
                    }
                }
            } else {
                $snList = array_values(array_filter($snList, fn($sn) => trim($sn) !== ''));
            }

            // 1. Lấy thông tin order_product
            $sqlCheck = "SELECT quantity, order_id, product_name FROM order_products WHERE id = ?";
            $stmtCheck = $conn->prepare($sqlCheck);
            if (!$stmtCheck) { throw new Exception('[DB_PREPARE] Lỗi truy vấn: ' . $conn->error); }
            $stmtCheck->bind_param('i', $orderProductId);
            $stmtCheck->execute();
            $resultCheck = $stmtCheck->get_result();
            if (!$row = $resultCheck->fetch_assoc()) {
                throw new Exception('[NOT_FOUND_PRODUCT] Không tìm thấy thông tin sản phẩm cho orderProductId: ' . (int)$orderProductId);
            }

            $expectedQuantity = (int)$row['quantity'];
            $order_id         = (int)$row['order_id'];
            $product_name     = $row['product_name'];

            if (count($snList) !== $expectedQuantity) {
                if ($position !== 'admin') {
                    throw new Exception("[QUANTITY_MISMATCH] Số lượng mã SN không khớp với số lượng sản phẩm cho sản phẩm '{$product_name}'.");
                }
            }

            // 2. Kiểm tra nhập tay / prefix
            $nhapTay = 0; $snnewPrefix = '';
            $sqlProduct = "SELECT nhap_tay, snnew FROM products WHERE product_name = ?";
            $stmtProduct = $conn->prepare($sqlProduct);
            if ($stmtProduct) {
                $stmtProduct->bind_param('s', $product_name);
                $stmtProduct->execute();
                $resultProduct = $stmtProduct->get_result();
                if ($rowProduct = $resultProduct->fetch_assoc()) {
                    $nhapTay     = (int)$rowProduct['nhap_tay'];
                    $snnewPrefix = (string)$rowProduct['snnew'];
                }
            }

            foreach ($snList as $sn) {
                $sn = trim($sn);
                $warranty_code = ($nhapTay === 1) ? ($snnewPrefix . $sn) : $sn;

                // 3. Trùng SN?
                $sqlDuplicate = "SELECT id FROM product_warranties WHERE warranty_code = ?";
                $stmtDuplicate = $conn->prepare($sqlDuplicate);
                if (!$stmtDuplicate) { throw new Exception('[DB_PREPARE] Lỗi kiểm tra trùng lặp: ' . $conn->error); }
                $stmtDuplicate->bind_param('s', $warranty_code);
                $stmtDuplicate->execute();
                $resultDuplicate = $stmtDuplicate->get_result();
                if ($resultDuplicate->num_rows > 0) {
                    throw new Exception("[DUPLICATE_SN] Cảnh báo: Mã Seri '{$warranty_code}' đã được quét trong một đơn hàng khác trên hệ thống. Vui lòng thực hiện quét trả lại hàng hoặc báo quản trị viên xử lý.");
                }

                // 4. Ghi product_warranties
                $sqlInsert = "INSERT INTO product_warranties (order_product_id, warranty_code, name) VALUES (?, ?, ?)";
                $stmtInsert = $conn->prepare($sqlInsert);
                if (!$stmtInsert) { throw new Exception('[DB_PREPARE] Lỗi khi chèn dữ liệu: ' . $conn->error); }
                $stmtInsert->bind_param('iss', $orderProductId, $warranty_code, $fullName);
                $stmtInsert->execute();
                $totalInserted++;

                // 5. Zone -> trừ kho
                $sqlZone = "SELECT zone FROM orders WHERE id = ?";
                $stmtZone = $conn->prepare($sqlZone);
                if (!$stmtZone) { throw new Exception('[DB_PREPARE] Prepare failed (zone): ' . $conn->error); }
                $stmtZone->bind_param('i', $order_id);
                $stmtZone->execute();
                $resultZone = $stmtZone->get_result();
                if ($rowZone = $resultZone->fetch_assoc()) {
                    $zone = $rowZone['zone'];
                    if ($zone === 'Đơn hàng Vinh')      $updateField = 'stock_vinh';
                    elseif ($zone === 'Đơn hàng HaNoi') $updateField = 'stock_hanoi';
                    elseif ($zone === 'Đơn hàng HCM')   $updateField = 'stock_hcm';
                    else throw new Exception('[INVALID_ZONE] Zone không hợp lệ cho đơn hàng: ' . $order_id);

                    $sqlUpdateStock = "UPDATE products SET $updateField = $updateField - 1 WHERE product_name = ?";
                    $stmtUpdateStock = $conn->prepare($sqlUpdateStock);
                    if (!$stmtUpdateStock) { throw new Exception('[DB_PREPARE] Prepare failed (update stock): ' . $conn->error); }
                    $stmtUpdateStock->bind_param('s', $product_name);
                    $stmtUpdateStock->execute();
                } else {
                    throw new Exception('[NOT_FOUND_ZONE] Không tìm thấy thông tin zone cho đơn hàng: ' . $order_id);
                }
            }
        }

        // 6. Cập nhật trạng thái đơn hàng
        $sqlUpdateOrder = "UPDATE orders SET status = 'Đã quét QR' WHERE order_code2 = ? OR order_code1 = ?";
        $stmtUpdateOrder = $conn->prepare($sqlUpdateOrder);
        if (!$stmtUpdateOrder) { throw new Exception('[UPDATE_ORDER_FAIL] Lỗi khi cập nhật đơn hàng: ' . $conn->error); }
        $stmtUpdateOrder->bind_param('ss', $orderCode, $orderCode);
        $stmtUpdateOrder->execute();

        $conn->commit();
        send_success('Đã quét mã bảo hành đơn hàng thành công!', ['inserted' => $totalInserted]);
    } catch (Exception $e) {
        $conn->rollback();
        $raw = $e->getMessage(); $code = 'GENERAL_ERROR'; $msg = $raw;
        if (preg_match('/^\[([A-Z0-9_]+)\]\s*(.*)$/', $raw, $m)) { $code = $m[1]; $msg = $m[2]; }
        $http = 400;
        if ($code === 'UNAUTHENTICATED') $http = 401;
        if (in_array($code, ['DB_PREPARE','UPDATE_ORDER_FAIL'], true)) $http = 500;
        send_error($code, $msg, $http);
    }
} else {
    send_error('BAD_REQUEST', 'Yêu cầu không hợp lệ hoặc thiếu dữ liệu SN.');
}
