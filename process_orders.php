<?php
include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}

include 'config.php';
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $orders = $_POST['orders'] ?? null;
    if (empty($orders)) {
        echo "Không có dữ liệu đơn hàng!";
        exit;
    }

    foreach ($orders as $order) {
        // --------- LẤY INPUT CƠ BẢN ----------
        $order_code1       = $conn->real_escape_string($order['order_code1'] ?? '');
        $order_code2       = $conn->real_escape_string($order['order_code2'] ?? '');
        $customer_name     = $conn->real_escape_string($order['customer_name'] ?? '');
        $customer_phone    = $conn->real_escape_string($order['customer_phone'] ?? '');
        $customer_address  = $conn->real_escape_string($order['customer_address'] ?? '');
        $agency_name       = $conn->real_escape_string($order['agency_name'] ?? '');
        $agency_phone      = $conn->real_escape_string($order['agency_phone'] ?? '');
        $type              = $conn->real_escape_string($order['type'] ?? '');
        $shipping_unit     = $conn->real_escape_string($order['shipping_unit'] ?? '');
        $payment_method    = "Tiền mặt";
        $status            = "Đang chờ quét QR";
        $staff             = $_SESSION['full_name'] ?? '';
        $zone              = $_SESSION['position'] ?? '';

        // --------- KHUYẾN MÃI / GIẢM GIÁ ----------
        $discount_code     = (isset($order['discount_code']) && $order['discount_code'] !== 'other') ? (int)$order['discount_code'] : 0;
        $custom_discount   = isset($order['custom_discount']) ? (int)preg_replace('/[.,\sVNĐ]/', '', $order['custom_discount']) : 0;
        $applied_discount  = $discount_code > 0 ? $discount_code : $custom_discount;

        // --------- BỔ SUNG: ĐỊA GIỚI (province bắt buộc) ----------
        // Từ UI gửi id dạng số; nếu rỗng -> 0
        $province_id = isset($order['province']) ? (int)$order['province'] : 0;
        $district_id = isset($order['district']) ? (is_numeric($order['district']) ? (int)$order['district'] : 0) : 0;
        $wards_id    = isset($order['wards'])    ? (is_numeric($order['wards'])    ? (int)$order['wards']    : 0) : 0;

        if ($province_id <= 0) {
            // Bắt buộc có tỉnh
            echo "Thiếu Tỉnh/Thành phố cho đơn hàng " . htmlspecialchars($order_code2);
            exit;
        }

        // --------- INSERT ĐƠN HÀNG ----------
        $total_price = 0; // sẽ tính sau khi thêm sản phẩm

        // Lưu ý: bổ sung 3 cột province, district, wards
        $sql_order = "
            INSERT INTO orders 
                (order_code1, order_code2, customer_name, customer_phone, customer_address,
                 agency_name, agency_phone, discount_code, total_price, payment_method, status,
                 staff, zone, type, shipping_unit, province, district, wards)
            VALUES 
                ('$order_code1', '$order_code2', '$customer_name', '$customer_phone', '$customer_address',
                 '$agency_name', '$agency_phone', $applied_discount, 0, '$payment_method', '$status',
                 '$staff', '$zone', '$type', '$shipping_unit', $province_id, $district_id, $wards_id)
        ";

        if ($conn->query($sql_order) === TRUE) {
            $order_id = $conn->insert_id;

            // --------- THÊM SẢN PHẨM ----------
            if (!empty($order['products']) && is_array($order['products'])) {
                $all_no_warranty = true;

                foreach ($order['products'] as $product) {
                    $product_name      = $conn->real_escape_string($product['product_name'] ?? '');
                    $quantity          = (int)($product['quantity'] ?? 0);
                    $excluding_VAT     = (int)preg_replace('/[.,\sVNĐ]/', '', $product['original_price'] ?? '0');
                    $VAT               = "10%";
                    $VAT_price         = (int)preg_replace('/[.,\sVNĐ]/', '', $product['vat'] ?? '0');
                    $price             = (int)preg_replace('/[.,\sVNĐ]/', '', $product['price'] ?? '0');
                    $price_difference  = isset($product['price_difference']) ? (int)preg_replace('/[.,\sVNĐ]/', '', $product['price_difference']) : 0;
                    $sub_address       = $conn->real_escape_string($product['sub_address'] ?? '');
                    $is_promotion      = isset($product['is_promotion']) ? 1 : 0;
                    $no_warranty_scan  = isset($product['no_warranty_scan']) ? 0 : 1; // theo code cũ
                    $install_flag      = 0; // MẶC ĐỊNH: 0

                    // Kiểm tra `print` và `install` từ bảng products
                    $sql_check = "SELECT `print`, `install` FROM products WHERE product_name = '$product_name' LIMIT 1";
                    $result_check = $conn->query($sql_check);
                    if ($result_check && $result_check->num_rows > 0) {
                        $row = $result_check->fetch_assoc();

                        // print: quyết định warranty_scan như cũ
                        if ((int)$row['print'] === 1) {
                            $no_warranty_scan = 0;
                        } else {
                            // nếu print != 1 thì vẫn bắt quét
                            $no_warranty_scan = 1;
                        }

                        // NEW: nếu sản phẩm có install = 1 → set cờ install cho order_products
                        if (!empty($row['install']) && (int)$row['install'] === 1) {
                            $install_flag = 1;
                        }
                    }

                    if ($no_warranty_scan === 1) {
                        $all_no_warranty = false;
                    }
                    $warranty_scan = $no_warranty_scan;

                    // Cộng tổng tiền (giữ logic cũ)
                    $total_price += $is_promotion ? ($price + $price_difference) : $price;

                    // INSERT order_products (BỔ SUNG CỘT install)
                    $sql_product = "
                        INSERT INTO order_products 
                            (order_id, product_name, quantity, excluding_VAT, VAT, VAT_price, price, 
                             price_difference, sub_address, is_promotion, warranty_scan, install)
                        VALUES 
                            ($order_id, '$product_name', $quantity, $excluding_VAT, '$VAT', $VAT_price, $price, 
                             $price_difference, '$sub_address', $is_promotion, $warranty_scan, $install_flag)
                    ";

                    if (!$conn->query($sql_product)) {
                        echo "Lỗi khi thêm sản phẩm: " . $conn->error;
                        // không exit để cố lưu các sp khác; tuỳ nghiệp vụ có thể rollback
                    }
                }

                // --------- CẬP NHẬT TRẠNG THÁI ĐƠN THEO 'khoa_tem' ----------
                $sql_check_khoa_tem = "
                    SELECT COUNT(*) AS total, 
                           SUM(CASE WHEN p.khoa_tem = 1 THEN 1 ELSE 0 END) AS total_khoa_tem
                    FROM order_products op
                    JOIN products p ON op.product_name = p.product_name
                    WHERE op.order_id = $order_id
                ";
                $result_khoa_tem = $conn->query($sql_check_khoa_tem);
                if ($result_khoa_tem && $row_khoa_tem = $result_khoa_tem->fetch_assoc()) {
                    $total = (int)$row_khoa_tem['total'];
                    $total_khoa_tem = (int)$row_khoa_tem['total_khoa_tem'];

                    if ($total > 0 && $total === $total_khoa_tem) {
                        $status = "Đã quét QR";
                    } elseif ($all_no_warranty) {
                        $status = "Hàng linh kiện";
                    } else {
                        $status = "Đang chờ quét QR";
                    }
                } else {
                    // fallback
                    if ($all_no_warranty) {
                        $status = "Hàng linh kiện";
                    } else {
                        $status = "Đang chờ quét QR";
                    }
                }

                // Update status
                $sql_update_status = "UPDATE orders SET status = '$status' WHERE id = $order_id";
                if (!$conn->query($sql_update_status)) {
                    echo "Lỗi khi cập nhật trạng thái đơn hàng: " . $conn->error;
                }
            }

            // --------- CẬP NHẬT TỔNG TIỀN + GIẢM GIÁ ----------
            $total_price -= $applied_discount;
            $total_price = max($total_price, 0);
            $sql_update_total = "UPDATE orders SET total_price = $total_price WHERE id = $order_id";
            if (!$conn->query($sql_update_total)) {
                echo "Lỗi khi cập nhật tổng giá trị đơn hàng: " . $conn->error;
            }

        } else {
            echo "Lỗi khi thêm đơn hàng: " . $conn->error;
        }
    }

    // Lưu session hiển thị sau chuyển trang (giữ logic cũ)
    if (!isset($_SESSION['orders'])) {
        $_SESSION['orders'] = [];
    }
    // Lưu đơn cuối (tùy bạn có muốn lưu toàn bộ mảng hay chỉ đơn cuối)
    $_SESSION['orders'][] = [
        'order_code1'      => $order_code1 ?? '',
        'order_code2'      => $order_code2 ?? '',
        'customer_name'    => $customer_name ?? '',
        'customer_phone'   => $customer_phone ?? '',
        'customer_address' => $customer_address ?? '',
        'province'         => (int)$province_id,
        'district'         => (int)$district_id,
        'wards'            => (int)$wards_id,
        'agency_name'      => $agency_name ?? '',
        'agency_phone'     => $agency_phone ?? '',
        'discount_code'    => $applied_discount ?? 0,
        'total_price'      => $total_price ?? 0,
        'payment_method'   => $payment_method ?? 'Tiền mặt',
        'status'           => $status ?? 'Đang chờ quét QR',
        'type'             => $type ?? '',
        'products'         => $orders[array_key_last($orders)]['products'] ?? [],
    ];

    header("Location: success.php");
    exit();
} else {
    echo "Phương thức không được hỗ trợ!";
}

$conn->close();
