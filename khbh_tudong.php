<?php
include 'config.php';

$limit = 100;
$cutoff_date = date('Y-m-d', strtotime('-7 days'));
$today = date('Y-m-d');
$cutoff_agency = date('Y-m-d', strtotime('-90 days'));

$sql = "
    SELECT o.id, o.order_code2, o.customer_name, o.customer_phone, o.customer_address, o.created_at, o.agency_name, o.agency_phone
    FROM orders o
    WHERE o.status = 'Đã quét QR' 
      AND o.status_tracking = 'Giao thành công' 
      AND o.send_camon = 1
      AND (o.send_khbh IS NULL OR o.send_khbh NOT IN (1, 2, 3))
      AND o.created_at < '$cutoff_date'
    ORDER BY o.created_at DESC
    LIMIT $limit
";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $HoVaTenKQ = $row['customer_name'];
        $DienThoaiKQ = $row['customer_phone'];
        $DiaChiKQ = $row['customer_address'];
        $NgayMuaKQ = date('Y-m-d', strtotime($row['created_at']));
        $order_id = $row['id'];

        $is_agency_order = (
            trim($row['customer_name']) === trim($row['agency_name']) &&
            trim($row['customer_phone']) === trim($row['agency_phone'])
        );

        // Nếu là đơn đại lý nhưng chưa đủ 90 ngày thì bỏ qua
        if ($is_agency_order && $NgayMuaKQ > $cutoff_agency) {
            echo "⏳ Đơn $order_id là đại lý, chưa đủ 90 ngày để kích hoạt bảo hành.<br>";
            continue;
        }

        // Lấy sản phẩm cần quét bảo hành
        $sql_order_product = "
            SELECT id AS order_product_id, product_name 
            FROM order_products 
            WHERE order_id = '$order_id' 
              AND warranty_scan = 1
        ";
        $result_order_product = $conn->query($sql_order_product);

        if ($result_order_product->num_rows > 0) {
            while ($row_op = $result_order_product->fetch_assoc()) {
                $order_product_id = $row_op['order_product_id'];
                $TenSanPham = $row_op['product_name'];

                // Lấy mã serial
                $sql_serial = "
                    SELECT warranty_code 
                    FROM product_warranties 
                    WHERE order_product_id = '$order_product_id'
                ";
                $result_serial = $conn->query($sql_serial);

                if ($result_serial->num_rows > 0) {
                    while ($row_serial = $result_serial->fetch_assoc()) {
                        $Serial = $row_serial['warranty_code'];

                        // Truy vấn số tháng bảo hành
                        $sql_warranty_month = "
                            SELECT month 
                            FROM products 
                            WHERE product_name = '" . $conn->real_escape_string($TenSanPham) . "'
                            LIMIT 1
                        ";
                        $result_month = $conn->query($sql_warranty_month);
                        $so_thang_bao_hanh = 0;

                        if ($result_month->num_rows > 0) {
                            $row_month = $result_month->fetch_assoc();
                            $so_thang_bao_hanh = (int)$row_month['month'];
                        } else {
                            echo "⚠️ Không tìm thấy thời gian bảo hành cho sản phẩm '$TenSanPham'.<br>";
                        }

                        $warranty_end = date('Y-m-d', strtotime("+$so_thang_bao_hanh months", strtotime($NgayMuaKQ)));

                        $data = array(
                            'serial' => $Serial,
                            'full_name' => $HoVaTenKQ,
                            'phone_number' => $DienThoaiKQ,
                            'product_name' => $TenSanPham,
                            'address' => $DiaChiKQ,
                            'shipment_date' => $NgayMuaKQ,
                            'order_code' => $row['order_code2'],
                            'warranty_end' => $warranty_end
                        );

                        // CURL gửi dữ liệu
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, 'https://kuchenvietnam.vn/dang-ki-bao-hanh-kuchen/active.php');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $send_status = 3;

                        if (curl_errno($ch)) {
                            echo "❌ CURL error đơn $order_id: " . curl_error($ch) . "<br>";
                        } else {
                            if ($http_code === 200) {
                                $send_status = 1;
                                echo "✅ Đơn $order_id - $TenSanPham - Serial $Serial: Đăng ký thành công (Hết hạn: $warranty_end).<br>";
                            } elseif ($http_code === 400) {
                                $send_status = 2;
                                echo "⚠️ Đơn $order_id - $TenSanPham - Serial $Serial: Đã đăng ký trước đó.<br>";
                            } else {
                                echo "❌ Đơn $order_id - $TenSanPham - Serial $Serial: HTTP $http_code - $response<br>";
                            }

                            // Cập nhật trạng thái
                            $update = "UPDATE orders SET send_khbh = '$send_status' WHERE id = '$order_id'";
                            if (!$conn->query($update)) {
                                echo "⚠️ Lỗi cập nhật send_khbh cho đơn $order_id: " . $conn->error . "<br>";
                            }
                        }

                        curl_close($ch);
                    }
                } else {
                    echo "🚫 Không có serial cho sản phẩm $TenSanPham (đơn $order_id).<br>";
                }
            }
        } else {
            echo "🚫 Không có sản phẩm cần quét bảo hành cho đơn hàng $order_id.<br>";
        }
    }
} else {
    echo "🎉 Không có đơn hàng nào cần xử lý.<br>";
}

$conn->close();
?>
