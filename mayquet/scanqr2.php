<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}

include '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['order_code'])) {
    $orderCode = $_GET['order_code'];
    if (empty($orderCode)) die('Mã đơn hàng không hợp lệ.');

    $stmt = $conn->prepare("SELECT id, status FROM orders WHERE order_code2 = ? OR order_code1 = ? LIMIT 1");
    $stmt->bind_param('ss', $orderCode, $orderCode);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order || $order['status'] !== 'Đang quét QR') die('Đơn hàng đã xử lý hoặc không hợp lệ.');
    $orderId = (int)$order['id'];

    $sql = "
      SELECT 
        op.id AS order_product_id,
        op.product_name,
        op.quantity,
        op.is_promotion,
        p.nhap_tay,
        p.snnew,
        COUNT(pw.id) AS scanned_count
      FROM order_products op
      LEFT JOIN product_warranties pw ON pw.order_product_id = op.id
      LEFT JOIN products p ON p.product_name = op.product_name
      WHERE op.order_id = ?
        AND op.warranty_scan = 1
        AND p.print = 0
        AND p.khoa_tem = 0
      GROUP BY op.id, op.product_name, op.quantity, op.is_promotion, p.nhap_tay, p.snnew
      ORDER BY op.is_promotion ASC, op.id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $orderProducts = [];
    while ($row = $result->fetch_assoc()) {
        $remaining = (int)$row['quantity'] - (int)$row['scanned_count'];
        if ($remaining > 0) {
            $row['remaining'] = $remaining;
            $orderProducts[] = $row;
        }
    }

    if (empty($orderProducts)) die('Không có sản phẩm cần quét.');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Quét mã bằng máy bắn - <?= htmlspecialchars($orderCode) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    body { background: #f9f9f9; padding: 20px; font-family: sans-serif; }
    .product-item { border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #fff; }
    .scanner-input { font-size: 1.2em; padding: 10px; margin-bottom: 20px; width: 100%; }
    .alert-area { min-height: 40px; margin-bottom: 15px; }
  </style>
</head>
<body>
  <h4>Quét đơn hàng: <?= htmlspecialchars($orderCode) ?></h4>

  <input type="text" id="scannerInput" class="form-control scanner-input" placeholder="Quét mã vạch ở đây..." autofocus>
  <div class="alert-area" id="scan-message"></div>

  <form method="POST" action="process_scan.php">
    <input type="hidden" name="order_code" value="<?= htmlspecialchars($orderCode) ?>">
    <div id="productList">
      <?php foreach ($orderProducts as $prod): ?>
        <div class="product-item" 
             data-product-name="<?= htmlspecialchars($prod['product_name']) ?>"
             data-nhap-tay="<?= (int)$prod['nhap_tay'] ?>"
             data-snnew="<?= htmlspecialchars($prod['snnew']) ?>">
          <h5><?= htmlspecialchars($prod['product_name']) ?> <?= $prod['is_promotion'] ? '<span class="badge badge-warning">KM</span>' : '' ?></h5>
          <p>Số lượng cần quét: <strong><?= $prod['remaining'] ?></strong></p>
          <?php for ($i = 0; $i < $prod['remaining']; $i++): ?>
            <input type="text" class="form-control serial-input mb-1" name="sn[<?= $prod['order_product_id'] ?>][]" <?= $prod['nhap_tay'] == 1 ? '' : 'readonly' ?>>
          <?php endfor; ?>
          <?php if ($prod['nhap_tay'] == 1): ?>
            <small class="text-danger">⚠️ Dòng này cần nhập tay 3 ký tự cuối</small>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-success btn-lg mt-4">Hoàn tất</button>
  </form>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('scannerInput');
    const messageBox = document.getElementById('scan-message');
    const scanned = new Set();

    input.addEventListener('keypress', async function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        const raw = input.value.trim();
        input.value = '';

        let sn = extractSerial(raw);
        if (!sn) return showMessage('❌ Mã không hợp lệ', 'danger');
        if (scanned.has(sn)) return showMessage(`⚠️ SN "${sn}" đã quét`, 'warning');

        const res = await fetch(`https://kuchenvietnam.vn/kuchen/khokuchen/api/lookup_sn.php?sn=${encodeURIComponent(sn)}`);
        const data = await res.json();
        if (!data || !data.product_name) return showMessage(`❌ Không tìm thấy sản phẩm cho "${sn}"`, 'danger');

        const productName = data.product_name.trim();
        let matched = false;

        document.querySelectorAll('.product-item').forEach(item => {
          const name = item.dataset.productName.trim();
          const mode = parseInt(item.dataset.nhapTay);
          const prefix = item.dataset.snnew || '';

          if (matched) return;

          // Mode 0: full SN trùng product
          if (mode === 0 && name === productName) {
            const empty = [...item.querySelectorAll('input.serial-input')].find(i => i.value === '');
            if (empty) {
              empty.value = sn;
              scanned.add(sn);
              showMessage(`✅ Quét SN "${sn}" cho "${productName}"`, 'success');
              matched = true;
            }
          }

          // Mode 2: ghép prefix + last3
          if (mode === 2 && sn.length >= 3) {
            const guess = prefix + sn.slice(-3);
            if (name === productName && !scanned.has(guess)) {
              const empty = [...item.querySelectorAll('input.serial-input')].find(i => i.value === '');
              if (empty) {
                empty.value = guess;
                scanned.add(guess);
                showMessage(`✅ Ghép "${guess}" cho "${productName}"`, 'success');
                matched = true;
              }
            }
          }
        });

        if (!matched) {
          const manual = document.querySelector('.product-item[data-nhap-tay="1"]');
          if (manual) {
            showMessage('⚠️ Dòng này yêu cầu nhập tay 3 ký tự cuối', 'warning');
          } else {
            showMessage(`❌ Không khớp sản phẩm cho "${sn}"`, 'danger');
          }
        }
      }
    });
function extractSerial(data) {
  // Ưu tiên: tách từ chuỗi có chứa serial=... hoặc sẻial=...
  const regex = /[?&]s[eêẻẽẹéè]rial=([^&#\s]+)/i;  // hỗ trợ cả 'serial' và 'sẻial'
  const match = data.match(regex);
  if (match) {
    return match[1].trim();
  }

  // Nếu có tiền tố SN:
  if (/^SN:/i.test(data)) {
    data = data.replace(/^SN:/i, '').trim();
  }

  // Cuối cùng, loại ký tự lạ nếu là chuỗi đơn lẻ
  return data.replace(/[^0-9A-Za-z]/g, '');
}




    function showMessage(msg, type = 'info') {
      messageBox.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
      setTimeout(() => messageBox.innerHTML = '', 10000);
    }
  });
  </script>
  <script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('scannerInput');

    // Auto focus khi trang tải xong
    input.focus();

    // Nếu người dùng click ra chỗ khác, sau 200ms focus lại
    document.addEventListener('click', function () {
        setTimeout(() => input.focus(), 10000);
    });

    // Hoặc mỗi vài giây nhắc lại (chống máy quét bị mất focus)
    setInterval(() => {
        if (document.activeElement !== input) {
            input.focus();
        }
    }, 5000); // mỗi 5 giây
});
</script>

</body>
</html>
