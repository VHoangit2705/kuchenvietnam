<?php
include 'config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Content-Type: application/json; charset=utf-8');

// Ép collation cho kết nối (tránh mix collation)
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// --------- Tham số số ngày tính độ phổ biến ----------
$popularDays = isset($_GET['popular_days']) ? (int)$_GET['popular_days'] : 7;
if ($popularDays < 1 || $popularDays > 3650) $popularDays = 7;
$sinceDate = (new DateTime("-{$popularDays} days"))->format('Y-m-d 00:00:00');

// --------- Truy vấn tổng hợp gợi ý ----------
$sql = "
(
  /* NHÓM 1: Sản phẩm có khuyến mãi trong ngày hôm nay, ưu tiên sold nhiều */
  SELECT 
    p.product_name,
    p.price,
    p.price_retail, 
    1 AS is_promo,
    COALESCE(s.sold_qty, 0) AS sort_sold,
    CASE 
      WHEN p.`view` = 1 THEN 0
      WHEN p.`view` = 3 THEN 1
      WHEN p.`view` = 4 THEN 2
      WHEN p.`view` = 2 THEN 3
      ELSE 4
    END AS sort_view,
    0 AS group_rank,
    p.product_name AS sort_name
  FROM products p
  /* Sold qty trong N ngày cho mọi sản phẩm */
  LEFT JOIN (
    SELECT op.product_name, SUM(op.quantity) AS sold_qty
    FROM order_products op
    JOIN orders o ON o.id = op.order_id
    WHERE o.created_at >= ?
    GROUP BY op.product_name
  ) s ON s.product_name COLLATE utf8mb4_unicode_ci = p.product_name COLLATE utf8mb4_unicode_ci
  WHERE EXISTS (
    SELECT 1 
    FROM promotions pr
    WHERE pr.main_product COLLATE utf8mb4_unicode_ci = p.product_name COLLATE utf8mb4_unicode_ci
      AND DATE(NOW()) BETWEEN DATE(pr.start_at) AND DATE(pr.end_at)
  )
)
UNION ALL
(
  /* NHÓM 2: Sản phẩm KHÔNG khuyến mãi, xếp theo số bán trong N ngày */
  SELECT
    p.product_name,
    p.price,
    p.price_retail, 
    0 AS is_promo,
    COALESCE(s.sold_qty, 0) AS sort_sold,
    CASE 
      WHEN p.`view` = 1 THEN 0
      WHEN p.`view` = 3 THEN 1
      WHEN p.`view` = 2 THEN 2
      WHEN p.`view` = 4 THEN 3
      ELSE 4
    END AS sort_view,
    1 AS group_rank,
    p.product_name AS sort_name
  FROM products p
  LEFT JOIN (
    SELECT op.product_name, SUM(op.quantity) AS sold_qty
    FROM order_products op
    JOIN orders o ON o.id = op.order_id
    WHERE o.created_at >= ?
    GROUP BY op.product_name
  ) s ON s.product_name COLLATE utf8mb4_unicode_ci = p.product_name COLLATE utf8mb4_unicode_ci
  WHERE NOT EXISTS (
    SELECT 1 
    FROM promotions pr
    WHERE pr.main_product COLLATE utf8mb4_unicode_ci = p.product_name COLLATE utf8mb4_unicode_ci
      AND DATE(NOW()) BETWEEN DATE(pr.start_at) AND DATE(pr.end_at)
  )
)
/* Thứ tự cuối cùng:
   - group_rank: khuyến mãi trước (0), không KM sau (1)
   - nhóm KM: sold_qty DESC → view (1→3→4→2) → tên
   - nhóm không KM: sold_qty DESC → view (1→3→2→4) → tên
*/
ORDER BY group_rank ASC, sort_sold DESC, sort_view ASC, sort_name ASC
";

try {
    $stmt = $conn->prepare($sql);
    // 2 tham số ? tương ứng 2 subquery sold_qty (nhóm 1 và nhóm 2)
    $stmt->bind_param('ss', $sinceDate, $sinceDate);
    $stmt->execute();
    $res = $stmt->get_result();

    $products = [];
   while ($row = $res->fetch_assoc()) {
    $products[] = [
        'product_name'  => $row['product_name'],
        'price'         => (float)$row['price'],
        'price_retail'  => isset($row['price_retail']) ? (float)$row['price_retail'] : 0.0, // THÊM
    ];
}

    echo json_encode($products, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[products_suggest] '.$e->getMessage());
    echo json_encode([]); // trả mảng rỗng để frontend không vỡ .map()
} finally {
    if (isset($stmt)) $stmt->close();
    $conn->close();
}
