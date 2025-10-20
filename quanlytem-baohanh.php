<?php
// filter.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
include 'config.php';

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$error      = '';
$data       = [];  
// Mảng zone chính xác như trong DB
$zones = ['Đơn hàng Vinh','Đơn hàng HaNoi','Đơn hàng HCM'];

if ($end_date < $start_date) {
    list($start_date, $end_date) = [$end_date, $start_date];
}

$sql = "
  SELECT
  op.product_name,
  o.zone,
  COUNT(pw.warranty_code) AS total_sn
FROM orders o
JOIN order_products op
  ON op.order_id = o.id
JOIN product_warranties pw
  ON pw.order_product_id = op.id
WHERE o.status = 'Đã quét QR'
  AND op.warranty_scan = 1                  -- điều kiện mới
  AND pw.warranty_code <> ''
  AND DATE(o.created_at) BETWEEN ? AND ?
GROUP BY op.product_name, o.zone
ORDER BY op.product_name, o.zone;
";


if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $prod = $row['product_name'];
        $zn   = $row['zone'];
        $cnt  = (int)$row['total_sn'];
        if (!in_array($zn, $zones, true)) {
            // debug bỏ qua các zone lạ
            continue;
        }
        if (!isset($data[$prod])) {
            // khởi tạo 0 cho mỗi zone label
            foreach ($zones as $z) {
                $data[$prod][$z] = 0;
            }
        }
        $data[$prod][$zn] = $cnt;
    }
    $stmt->close();
} else {
    $error = "Lỗi chuẩn bị truy vấn: " . htmlspecialchars($conn->error);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Thống kê SN đã quét</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding: 2rem; background: #f8f9fa; }
    .card { max-width: 900px; margin: auto; }
    table { margin-top: 1rem; }
  </style>
</head>
<body>
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0">Thống kê SN đã quét theo Sản phẩm & Chi nhánh</h4>
    </div>
    <div class="card-body">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <form method="get" class="row g-3 mb-4">
        <div class="col-md-5">
          <label for="start_date" class="form-label">Từ ngày</label>
          <input type="date" id="start_date" name="start_date"
                 class="form-control" required
                 value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="col-md-5">
          <label for="end_date" class="form-label">Đến ngày</label>
          <input type="date" id="end_date" name="end_date"
                 class="form-control" required
                 value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-success w-100">Lọc</button>
        </div>
      </form>

      <?php if (!empty($data)): ?>
        <table class="table table-bordered table-striped">
          <thead class="table-light">
            <tr>
              <th>Sản phẩm</th>
              <?php foreach ($zones as $z): ?>
                <th><?= htmlspecialchars($z) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data as $prod => $counts): ?>
              <tr>
                <td><?= htmlspecialchars($prod) ?></td>
                <?php foreach ($zones as $z): ?>
                  <td><?= $counts[$z] ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert alert-info">
          Không có bản ghi SN đã quét từ <strong><?= htmlspecialchars($start_date) ?></strong>
          đến <strong><?= htmlspecialchars($end_date) ?></strong>.
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
