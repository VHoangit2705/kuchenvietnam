<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'config.php';
require_once('libs/fpdf.php');
require_once('libs/barcode128.php');

// Sinh mã vạch dạng: ID + phút giây hiện tại
function generateBarcodeCode($productId) {
    return $productId . date('YmdHi');
}

// Tạo PDF mã vạch với kích thước chỉ định
function generateBarcodePDF($barcode, $labelSize = '60x20') {
    $filename = "barcodes/{$barcode}.pdf";
    if (!file_exists('barcodes')) mkdir('barcodes');

    $sizeMap = [
        '60x20' => ['width' => 60, 'height' => 12],
        '40x30' => ['width' => 40, 'height' => 20],
        '50x25' => ['width' => 50, 'height' => 15]
    ];
    $size = $sizeMap[$labelSize] ?? $sizeMap['60x20'];

    $pdf = new PDF_Code128('P', 'mm', 'A5');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 8);

    $pageWidth = 148;
    $barcodeX = ($pageWidth - $size['width']) / 2;
    $barcodeY = 40;

    $pdf->Code128($barcodeX, $barcodeY, $barcode, $size['width'], $size['height']);
    $barcodeText = substr($barcode, 0, 7) . ' ' . substr($barcode, 7);
    $pdf->SetY($barcodeY + $size['height'] + 2);
    $pdf->Cell(0, 5, $barcodeText, 0, 1, 'C');

    $pdf->Output('F', $filename);
    return $filename;
}

// Xử lý khi gửi form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)$_POST['product_id'];
    $batchCode = $_POST['batch_code'];
    $quantity  = (int)$_POST['quantity'];
    $barcode   = $_POST['barcode'];
    $labelSize = $_POST['label_size'] ?? '60x20';

    // Kiểm tra sản phẩm đã có mã vạch chưa
    $check = $conn->prepare("SELECT COUNT(*) FROM product_batches WHERE product_id = ?");
    $check->bind_param('i', $productId);
    $check->execute();
    $check->bind_result($count);
    $check->fetch();
    $check->close();

    if ($count > 0) {
        echo "<script>alert('❌ Sản phẩm này đã được tạo mã vạch trước đó.'); window.location.href='" . $_SERVER['PHP_SELF'] . "';</script>";
        exit;
    }

    // Sinh file PDF và lưu dữ liệu
    generateBarcodePDF($barcode, $labelSize);

    $stmt = $conn->prepare("INSERT INTO product_batches (product_id, batch_code, barcode, quantity) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('issi', $productId, $batchCode, $barcode, $quantity);
    $stmt->execute();
    $stmt->close();

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Xử lý AJAX tạo barcode tự động
if (isset($_GET['ajax']) && $_GET['ajax'] == 'barcode' && isset($_GET['product_id'])) {
    echo generateBarcodeCode((int)$_GET['product_id']);
    exit;
}

// Lấy danh sách sản phẩm có thể tạo barcode
$products = $conn->query("SELECT * FROM products WHERE view = 1 ORDER BY product_name");

// Lấy danh sách các lô đã tạo
$batches = $conn->query("
  SELECT b.*, p.product_name
  FROM product_batches b
  JOIN products p ON b.product_id = p.id
  ORDER BY b.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Quản lý mã vạch theo lô hàng</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <style>
    body { padding: 30px; background: #f9f9f9; font-family: Arial; }
    .table td, .table th { vertical-align: middle; }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="mb-4">📦 Quản lý mã vạch theo lô hàng</h2>

    <form method="POST" class="card shadow p-4 mb-4">
      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Sản phẩm</label>
          <select name="product_id" class="form-control" id="productSelect" required>
            <option value="">-- Chọn sản phẩm --</option>
            <?php while ($p = $products->fetch_assoc()): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group col-md-3">
          <label>Thông tin lô hàng</label>
          <input type="text" name="batch_code" class="form-control" required>
        </div>
        <div class="form-group col-md-2">
          <label>Số lượng</label>
          <input type="number" name="quantity" class="form-control" required>
        </div>
        <div class="form-group col-md-2">
          <label>Mã vạch</label>
          <input type="text" name="barcode" id="barcodeInput" class="form-control" readonly required>
        </div>
        <div class="form-group col-md-2">
          <label>Kích thước tem</label>
          <select name="label_size" class="form-control">
            <option value="60x20">60×20 mm</option>
            <option value="50x25">50×25 mm</option>
            <option value="40x30">40×30 mm</option>
            <option value="40x15">40×15 mm</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">➕ Tạo mã vạch & thêm lô hàng</button>
    </form>

    <h5 class="mb-3">📋 Danh sách lô hàng</h5>
    <table class="table table-bordered table-striped bg-white shadow-sm">
      <thead class="thead-dark">
        <tr>
          <th>#</th>
          <th>Sản phẩm</th>
          <th>Mã lô</th>
          <th>Mã vạch</th>
          <th>Barcode (PDF)</th>
          <th>Số lượng</th>
          <th>Ngày tạo</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($b = $batches->fetch_assoc()): ?>
          <tr>
            <td><?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['product_name']) ?></td>
            <td><?= htmlspecialchars($b['batch_code']) ?></td>
            <td><code><?= htmlspecialchars($b['barcode']) ?></code></td>
            <td><a href="barcodes/<?= $b['barcode'] ?>.pdf" target="_blank" class="btn btn-sm btn-outline-secondary">📄 Xem PDF</a></td>
            <td><?= $b['quantity'] ?></td>
            <td><?= $b['created_at'] ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- jQuery + Select2 -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script>
    $(document).ready(function () {
      $('#productSelect').select2({
        placeholder: "🔍 Tìm sản phẩm...",
        width: '100%'
      });

      $('#productSelect').on('change', function () {
        const productId = $(this).val();
        if (!productId) return $('#barcodeInput').val('');
        fetch('?ajax=barcode&product_id=' + productId)
          .then(res => res.text())
          .then(barcode => $('#barcodeInput').val(barcode));
      });
    });
  </script>
</body>
</html>
