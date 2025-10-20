<?php
// ——————————————————————————————————————————
// 1) TẮT warning deprecated "${var}" trên PHP 8.2
//    (“Using ${var} in strings is deprecated…”) :contentReference[oaicite:0]{index=0}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

// ——————————————————————————————————————————
// 2) Autoload PhpSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// ——————————————————————————————————————————
// 3) Đọc & lọc dữ liệu Excel
function importExcel(string $filePath): array {
    $type        = IOFactory::identify($filePath);
    $reader      = IOFactory::createReader($type);
    $spreadsheet = $reader->load($filePath);
    $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

    $allowed = ['Nghệ An(Kuchen)', 'Hà Nội(Kuchen)', 'Tp.Hồ Chí Minh(Kuchen)'];
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i === 1) continue;  // bỏ header
        $code = trim($r['B'] ?? '');
        $id   = trim($r['C'] ?? '');
        $cd   = trim($r['D'] ?? '');
        $send = trim($r['E'] ?? '');
        $st   = trim($r['AG'] ?? '');
        $ps   = trim($r['AJ'] ?? '');
        $ds   = trim($r['AP'] ?? '');

        if ($code === '' || $id === '' || $send === '' || $st === '') {
            continue;
        }
        if (!in_array($send, $allowed, true)) {
            continue;
        }

        $out[] = [
            'order_code'     => $code,
            'order_id'       => $id,
            'created_date'   => $cd,
            'sender'         => $send,
            'status'         => $st,
            'payment_status' => $ps,
            'date_status'    => $ds,
        ];
        if (count($out) >= 3000) break;
    }
    return $out;
}

// ——————————————————————————————————————————
// 4) Lưu/UPSERT vào DB
function saveToDatabase(array $data): string {
    include 'config.php';
    if ($conn->connect_error) {
        die("Kết nối thất bại: " . $conn->connect_error);
    }

    // UPSERT: nếu order_code tồn tại thì UPDATE, không thì INSERT :contentReference[oaicite:1]{index=1}
    $sql = "
      INSERT INTO order_tracking
        (order_code, order_id, created_date, sender, status, payment_status, date_status)
      VALUES (?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        status         = VALUES(status),
        payment_status = VALUES(payment_status),
        date_status    = VALUES(date_status)
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Lỗi prepare UPSERT: " . $conn->error);
    }

    $msg = '';
    foreach ($data as $r) {
        // parse created_date
        $cd = null;
        $dt1 = DateTime::createFromFormat('d/m/Y H:i:s', $r['created_date']);
        if ($dt1 !== false) {
            $cd = $dt1->format('Y-m-d H:i:s');
        }
        // parse date_status
        $ds = null;
        $dt2 = DateTime::createFromFormat('d/m/Y H:i:s', $r['date_status']);
        if ($dt2 !== false) {
            $ds = $dt2->format('Y-m-d H:i:s');
        }

        $stmt->bind_param(
            "sssssss",
            $r['order_code'],
            $r['order_id'],
            $cd,
            $r['sender'],
            $r['status'],
            $r['payment_status'],
            $ds
        );

        if ($stmt->execute()) {
            $msg .= "✔️ Lưu/ cập nhật: {$r['order_code']}<br>";
        } else {
            $msg .= "❌ Lỗi với {$r['order_code']}: " . $stmt->error . "<br>";
        }
    }

    $stmt->close();
    $conn->close();
    return $msg;
}

// ——————————————————————————————————————————
// 5) Xử lý form, preview Excel và hiển thị kết quả lưu
$data = [];
$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excel_file']['tmp_name'])) {
    $data   = importExcel($_FILES['excel_file']['tmp_name']);
    $result = saveToDatabase($data);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <title>Import Excel</title>
</head>
<body>
  <div class="container my-5">
    <h1 class="text-center mb-4">Nhập dữ liệu từ Excel</h1>
    <div class="card mb-4">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="excel_file" accept=".xls,.xlsx" class="form-control mb-3" required>
      <button class="btn btn-primary w-100 mb-2">Import</button>
    </form>
    
    <a href="capnhatvanchuyen.php" class="btn btn-success w-100">Cập nhật vận chuyển</a>
  </div>
</div>
</div>

    <?php if (count($data) > 0): ?>
      <h5>Preview dữ liệu (<?= count($data) ?> dòng):</h5>
      <div class="table-responsive mb-4">
        <table class="table table-bordered table-sm">
          <thead class="table-light"><tr>
            <th>#</th><th>order_code</th><th>order_id</th><th>created_date</th>
            <th>sender</th><th>status</th><th>payment_status</th><th>date_status</th>
          </tr></thead>
          <tbody>
            <?php foreach ($data as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= htmlspecialchars($r['order_code']) ?></td>
                <td><?= htmlspecialchars($r['order_id']) ?></td>
                <td><?= htmlspecialchars($r['created_date']) ?></td>
                <td><?= htmlspecialchars($r['sender']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['payment_status']) ?></td>
                <td><?= htmlspecialchars($r['date_status']) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>

    <?php if ($result): ?>
      <div class="alert alert-secondary">
        <h5>Kết quả lưu vào DB:</h5>
        <?= $result ?>
      </div>
    <?php endif ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
