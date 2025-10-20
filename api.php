<?php
// Thông tin kết nối CSDL
include 'config.php';

// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Truy vấn dữ liệu
$sql = "
    SELECT o.id, o.order_code2, o.customer_name, o.customer_phone, o.discount_code, o.agency_name, o.agency_phone, o.created_at, p.Ma_ERP, op.quantity, op.excluding_VAT, op.VAT, op.VAT_price, op.price_difference, op.price, op.is_promotion
    FROM orders o
    JOIN order_products op ON o.id = op.order_id
    JOIN products p ON op.product_name = p.product_name
";
$result = $conn->query($sql);

$data = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // Loại bỏ cả ký tự \n và \r khỏi Ma_ERP
        $cleanedMaERP = str_replace(["\n", "\r"], "", $row['Ma_ERP']);
        
        // Chuyển đổi customer_name và agency_name sang UTF-8
        $customer_name = mb_convert_encoding($row['customer_name'], 'UTF-8', 'auto');
        $agency_name = mb_convert_encoding($row['agency_name'], 'UTF-8', 'auto');
        
        // Thêm dữ liệu vào mảng
        $data[] = [
            'madonhang' => $row['id'],
            'madon_droppii' => $row['order_code2'],
            'customer_name' => $customer_name,
            'customer_phone' => $row['customer_phone'],
            'agency_name' => $agency_name,
            'agency_phone' => $row['agency_phone'],
            'Ma_ERP' => $cleanedMaERP,
            'quantity' => $row['quantity'],
            'excluding_VAT' => $row['excluding_VAT'],
            'VAT' => $row['VAT'],
            'VAT_price' => $row['VAT_price'],
            'discount_code' => $row['discount_code'],
            'price_difference' => $row['price_difference'],
            'price' => $row['price'],
            'is_promotion' => $row['is_promotion'],
            'created_at' => $row['created_at'],
        ];
    }
} else {
    echo "Không có dữ liệu.";
    exit;
}

// Đóng kết nối
$conn->close();

// URL đích của máy chủ erp
$url = 'https://api.example.com/save-orders';

// Khởi tạo cURL để gửi dữ liệu
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json', // Định dạng JSON
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE)); // Gửi mảng JSON
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Nhận phản hồi từ máy khách

// Thực hiện gửi và lấy phản hồi
$response = curl_exec($ch);

// Kiểm tra lỗi cURL
if (curl_errno($ch)) {
    echo 'Lỗi cURL: ' . curl_error($ch);
} else {
    // Hiển thị phản hồi từ máy chủ
    echo "Phản hồi từ máy chủ: " . $response;
}

// Đóng kết nối cURL
curl_close($ch);
?>
