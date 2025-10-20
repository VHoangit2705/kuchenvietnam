<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Không thể kết nối CSDL']);
    exit;
}

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if ($phone === '') {
    echo json_encode(['success' => false, 'message' => 'Số điện thoại là bắt buộc']);
    exit;
}

$sql = "
  SELECT 
    o.customer_name,
    o.customer_address,
    o.agency_name,
    o.agency_phone,
    o.province      AS province_id,
    o.district      AS district_id,
    o.wards         AS wards_id,
    p.name          AS province_name,
    d.name          AS district_name,
    w.name          AS wards_name,
    o.created_at
  FROM orders o
  LEFT JOIN province p ON p.province_id = o.province
  LEFT JOIN district d ON d.district_id = o.district
  LEFT JOIN wards w    ON w.wards_id    = o.wards
  WHERE o.customer_phone = ?
  ORDER BY o.created_at DESC
  LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Lỗi prepare: ' . $conn->error]);
    exit;
}

$stmt->bind_param('s', $phone);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy khách hàng']);
}

$stmt->close();
$conn->close();
