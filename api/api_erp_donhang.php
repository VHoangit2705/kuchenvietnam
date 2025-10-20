<?php
include '../config.php';

// Hiển thị lỗi (chỉ dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Fallback getallheaders
if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        $hdrs = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $hdrs[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $hdrs;
    }
}

// Xác thực API KEY
$headers      = getallheaders();
$apiKeyHeader = $headers['Authorization'] ?? '';
$validApiKey  = '1736b9887f02254b9004c9bbe8bc3bb1';

if (empty($apiKeyHeader) || $apiKeyHeader !== $validApiKey) {
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Unauthorized: invalid API key'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== KẾT NỐI MEMCACHED ======
$mc = new Memcached();
$mc->addServer('127.0.0.1', 11211);

$cacheKey = 'api_donhang_last100';
$ttl      = 1200; // 10 phút

// Thử lấy cache
$cachedData = $mc->get($cacheKey);
if ($mc->getResultCode() === Memcached::RES_SUCCESS && $cachedData !== false) {
    echo $cachedData;
    exit;
}

// ====== Truy vấn DB ======
$sql = "
    SELECT 
      o.id,
      o.order_code1,  
      o.order_code2, 
      o.customer_name, 
      o.customer_phone,  
      o.created_at,
      o.zone,
      o.type, 
      op.product_name, 
      op.quantity,  
      op.price,
      op.is_promotion
    FROM orders o
    JOIN order_products op ON o.id = op.order_id
    JOIN products p ON op.product_name = p.product_name 
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ORDER BY o.id DESC
";


$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Query failed: ' . $conn->error
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Nối thêm " (km)" nếu đang khuyến mãi
    $productName = $row['product_name'];
    if ((int)$row['is_promotion'] === 1) {
        $productName .= ' (km)';
    }

    $data[] = [
        'madonhang'        => $row['id'],
        'mavandon'         => $row['order_code1'],
        'madon_droppii'    => $row['order_code2'],
        'customer_name'    => mb_convert_encoding($row['customer_name'], 'UTF-8', 'auto'),
        'customer_phone'   => $row['customer_phone'],
        'product'          => $productName,
        'is_promotion'     => $row['is_promotion'],
        'quantity'         => $row['quantity'],
        'price'            => $row['price'],
        'zone'             => $row['zone'],
        'type'             => $row['type'],
        'created_at'       => $row['created_at'],
    ];
}

$conn->close();

$response = json_encode([
    'status' => 'success',
    'count'  => count($data),
    'data'   => $data
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Lưu cache Memcached
$mc->set($cacheKey, $response, $ttl);

// Trả response
echo $response;
