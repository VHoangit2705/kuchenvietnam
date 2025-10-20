<?php
header('Content-Type: application/json; charset=utf-8');
include '../config.php';
$conn->set_charset("utf8mb4");

// Hàm chuẩn hóa zone key (loại bỏ dấu, khoảng trắng thừa, lowercase)
function normalizeZoneKey($str) {
    $str = trim($str);
    $str = preg_replace('/\s+/', ' ', $str);
    $str = mb_strtolower($str, 'UTF-8');
    return $str;
}

// Hàm viết hoa chữ cái đầu mỗi từ
function formatZoneName($str) {
    $str = trim($str);
    $str = preg_replace('/\s+/', ' ', $str);
    return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
}

$sql = "
    SELECT 
        DATE(o.created_at) AS date, 
        o.zone AS raw_zone,
        op.product_name,
        op.is_promotion,
        SUM(op.quantity) AS quantity
    FROM orders o
    JOIN order_products op ON o.id = op.order_id
    WHERE DATE(o.created_at) = CURDATE()
    GROUP BY DATE(o.created_at), o.zone, op.product_name, op.is_promotion
    ORDER BY date DESC, o.zone ASC;
";

$result = $conn->query($sql);

$data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $date      = $row["date"];
        $rawZone   = $row["raw_zone"];
        $zoneKey   = normalizeZoneKey($rawZone);
        $zoneDisplay = formatZoneName($rawZone);

        // Xử lý tên sản phẩm
        $productName = $row["product_name"];
        if ((int)$row["is_promotion"] === 1) {
            $productName .= ' (km)';
        }

        $quantity  = (int)$row["quantity"];

        if (!isset($data[$date])) {
            $data[$date] = [];
        }

        if (!isset($data[$date][$zoneKey])) {
            $data[$date][$zoneKey] = [
                "address" => $zoneDisplay,
                "product" => []
            ];
        }

        // Thêm sản phẩm (đã tách khuyến mãi vs thường do GROUP BY is_promotion)
        $data[$date][$zoneKey]["product"][] = [
            "name"     => $productName,
            "quantity" => $quantity
        ];
    }
}

// Định dạng lại output
$dataFormatted = [];
foreach ($data as $date => $zones) {
    foreach ($zones as $zoneEntry) {
        $zoneEntry["date"] = $date;
        $dataFormatted[] = $zoneEntry;
    }
}

echo json_encode([
    "status" => "success",
    "data"   => $dataFormatted
], JSON_UNESCAPED_UNICODE);

$conn->close();
