<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['order_code'])) {
    $orderCode = $_GET['order_code'];
    if (empty($orderCode)) {
        die('Mã đơn hàng không được để trống hoặc không hợp lệ.');
    }

    // 1. Lấy order và kiểm tra status
    $checkStatusSql = "
        SELECT o.id, o.status
        FROM orders o
        WHERE o.order_code2 = ? OR o.order_code1 = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($checkStatusSql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('ss', $orderCode, $orderCode);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order || $order['status'] !== 'Đang quét QR') {
        die('Đơn hàng đã được xử lý hoặc không hợp lệ.');
    }
    $orderId = (int)$order['id'];

    // 2. Lấy danh sách order_products (CHỈNH: join theo product_id thay vì product_name)
    $sql = "
      SELECT 
        op.id AS order_product_id,
        op.product_id,                            -- dùng cho data-* và so khớp
        COALESCE(p.product_name, op.product_name) AS display_name, -- tên hiển thị
        op.quantity,
        op.is_promotion,
        p.nhap_tay,
        p.snnew,
        COUNT(pw.id) AS scanned_count
      FROM order_products op
      LEFT JOIN product_warranties pw 
        ON pw.order_product_id = op.id
      LEFT JOIN products p 
        ON p.id = op.product_id                   -- CHỈNH Ở ĐÂY
      WHERE op.order_id       = ?
        AND op.warranty_scan   = 1
        AND (p.print = 0 OR p.print IS NULL)      -- an toàn nếu p null
        AND (p.khoa_tem = 0 OR p.khoa_tem IS NULL)
      GROUP BY
        op.id, op.product_id, display_name,
        op.quantity, op.is_promotion, p.nhap_tay, p.snnew
      ORDER BY op.is_promotion ASC, op.id ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $orderProducts = [];
    while ($row = $result->fetch_assoc()) {
        $remaining = (int)$row['quantity'] - (int)$row['scanned_count'];
        if ($remaining > 0) {
            $row['remaining'] = $remaining;
            // Nếu sản phẩm bị xóa/không còn trong bảng products, gán mặc định nhap_tay=1 để cho nhập tay
            if ($row['nhap_tay'] === null) $row['nhap_tay'] = 1;
            $orderProducts[] = $row;
        }
    }
    if (empty($orderProducts)) {
        die('Đơn hàng không tồn tại hoặc không có sản phẩm nào cần quét QR.');
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Quét QR - <?= htmlspecialchars($orderCode) ?></title>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.4.4/dist/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <style>
    body, html { margin:0; padding:0; height:100%; background:#f4f4f4; font-family:Arial, sans-serif; }
    .split-layout { display:flex; flex-direction:column; height:100vh; }
    .scanner-fixed { flex:1; background:#fff; box-shadow:0 2px 5px rgba(0,0,0,.1); display:flex; flex-direction:column; justify-content:flex-start; align-items:center; padding:10px; position:relative; }
    #qr-reader { width:100%; height:100%; }
    .scanner-overlay { width:150px; height:150px; border:2px solid red; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); z-index:2; pointer-events:none; }
    #scan-message { width:90%; max-width:600px; margin:5px auto; padding:8px 12px; font-size:1em; text-align:center; border-radius:5px; transition:opacity .3s,transform .3s; opacity:0; transform:translateY(-20px); }
    #scan-message.visible { opacity:1; transform:translateY(0); }
    #scan-message.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    #scan-message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .product-area { flex:1; overflow-y:auto; padding:15px; }
  </style>
</head>
<body>
<div class="split-layout">

  <!-- Camera (50%) -->
  <div class="scanner-fixed">
    <button class="btn btn-primary btn-block mb-2">
      Đang quét mã đơn hàng: <?= htmlspecialchars($orderCode) ?>
    </button>
    <div id="qr-reader"></div>
    <div class="scanner-overlay"></div>
    <div id="scan-message"></div>
  </div>

  <!-- Danh sách sản phẩm (50%) -->
  <div class="product-area">
    <form method="POST" action="process_scan.php">
      <input type="hidden" name="order_code" value="<?= htmlspecialchars($orderCode) ?>">
      <div class="product-list">
        <?php foreach ($orderProducts as $prod): ?>
          <div
            class="product-item card shadow-sm mb-3"
            data-order-product-id="<?= (int)$prod['order_product_id'] ?>"
            data-product-id="<?= (int)$prod['product_id'] ?>"
            data-product-name="<?= htmlspecialchars($prod['display_name']) ?>"
            data-nhap-tay="<?= (int)$prod['nhap_tay'] ?>"
            <?php if (in_array((int)$prod['nhap_tay'], [1, 2])): ?>
              data-sn-new="<?= htmlspecialchars((string)$prod['snnew']) ?>"
            <?php endif; ?>
          >
            <div class="card-body">
              <h4 class="card-title text-secondary">
                <?= htmlspecialchars($prod['display_name']) ?>
                <?php if (!empty($prod['is_promotion'])): ?>
                  <small class="text-warning">(SP khuyến mại)</small>
                <?php endif; ?>
              </h4>
              <p class="card-text">
                Số lượng cần quét: <strong><?= (int)$prod['remaining'] ?></strong>
              </p>
              <div class="mt-3">
                <?php for ($i = 0; $i < (int)$prod['remaining']; $i++): ?>
                  <input type="text"
                         name="sn[<?= (int)$prod['order_product_id'] ?>][]"
                         class="form-control mb-2 serial-input"
                         placeholder="Quét mã SN..."
                         <?= (int)$prod['nhap_tay'] == 1 ? '' : 'readonly' ?>
                         maxlength="4"
                         required>
                <?php endfor; ?>
                <?php if ((int)$prod['nhap_tay'] == 1): ?>
                  <p class="text-danger small">
                    ⚠️ Nhập tay 3 ký tự cuối hoặc 4 kí tự cuối, sản phẩm in sai QR không được quét.
                  </p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-center">
        <button type="submit" class="btn btn-success btn-lg px-5 mt-3">Hoàn tất</button>
      </div>
    </form>
  </div>

</div>

  <audio id="scanSound" src="beep.mp3"></audio>
  <audio id="scanSoundError" src="beepstop.mp3"></audio>
  <script src="https://unpkg.com/html5-qrcode"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const scanSound      = document.getElementById('scanSound');
  const scanSoundError = document.getElementById('scanSoundError');
  const scannedSet     = new Set();
  const orderCode      = '<?= addslashes($orderCode) ?>';
  let busy             = false;

  // 1. Unlock order khi user rời trang
  function unlockOrder() {
    navigator.sendBeacon('unlock_order.php',
      JSON.stringify({ order_code: orderCode })
    );
  }
  window.addEventListener('beforeunload', unlockOrder);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') unlockOrder();
    else {
      fetch(`check_order_status.php?order_code=${orderCode}`)
        .then(r => r.json())
        .then(data => {
          if (data.status !== 'Đang quét QR') {
            alert('Đơn hàng đã chuyển trạng thái. Quay về danh sách.');
            window.location.href = 'xem_donhang.php';
          }
        })
        .catch(console.error);
    }
  });

  // 2. Cảnh báo mạng yếu
  if (navigator.connection) {
    if (navigator.connection.downlink < 0.1)
      alert('Mạng yếu, không nên quét QR!');
    navigator.connection.addEventListener('change', () => {
      if (navigator.connection.downlink < 0.1)
        alert('Mạng yếu, không nên quét QR!');
    });
  }

  // 3. Chuẩn hoá dữ liệu QR
  function extractSerial(data) {
    data = data.trim();
    try {
      const url = new URL(data);
      const s = url.searchParams.get('serial');
      if (s) data = s.trim();
    } catch {}
    if (/^SN:/i.test(data)) data = data.replace(/^SN:/i, '').trim();
    return data.replace(/[^0-9A-Za-z:]/g, '');
  }

  // 4. Hiển thị popup
  function displayMessage(msg, type = 'success') {
    const el = document.getElementById('scan-message');
    el.textContent = msg;
    el.className = '';
    el.classList.add(type, 'visible');
    setTimeout(() => el.classList.remove('visible'), 3000);
  }

  // 5. Gọi API tra cứu SN (API trả { product_id, product_name })
  async function lookupSerial(sn) {
    const res = await fetch(
      `https://kuchenvietnam.vn/kuchen/khokuchen/api/lookup_sn.php?sn=${encodeURIComponent(sn)}`
    );
    if (!res.ok) return null;
    return res.json();
  }

  // 6. Xử lý mỗi lần quét (CHỈNH: so khớp theo product_id)
  async function processScanned(raw) {
    if (busy) return;
    busy = true;

    const rawSer = extractSerial(raw);
    if (!rawSer) {
      displayMessage('❌ Dữ liệu không hợp lệ', 'error');
      scanSoundError.play();
      busy = false;
      return;
    }

    if (scannedSet.has(rawSer)) {
      displayMessage(`⚠️ SN "${rawSer}" đã quét trước đó.`, 'error');
      scanSoundError.play();
      busy = false;
      return;
    }

    let fullSn = null;
    let productId = null;
    let productName = null;
    let matchedMode = null;

    // --- MODE 0: Lookup full serial và tìm dòng phù hợp (nhap_tay = 0)
    const res0 = await lookupSerial(rawSer);
    if (res0 && res0.product_id) {
      const pid = String(res0.product_id).trim();
      // Tìm item có nhap_tay = 0 và product_id = pid
      const items = Array.from(document.querySelectorAll('.product-item[data-nhap-tay="0"]'));
      for (let item of items) {
        if ((item.dataset.productId || '').trim() === pid) {
          fullSn      = rawSer;
          productId   = pid;
          productName = (res0.product_name || item.dataset.productName || '').trim();
          matchedMode = 0;
          break;
        }
      }
    }

    // --- MODE 2: Nếu không khớp mode=0 thì thử prefix+last3 (nhap_tay = 2)
    if (!fullSn && rawSer.length >= 3) {
      const last3 = rawSer.slice(-3);
      const items = Array.from(document.querySelectorAll('.product-item[data-nhap-tay="2"]'));
      for (let item of items) {
        const prefix = item.dataset.snNew || '';
        const candidate = prefix + last3;
        if (scannedSet.has(candidate)) continue;
        const res2 = await lookupSerial(candidate);
        if (res2 && res2.product_id) {
          const pid2 = String(res2.product_id).trim();
          if ((item.dataset.productId || '').trim() === pid2) {
            fullSn      = candidate;
            productId   = pid2;
            productName = (res2.product_name || item.dataset.productName || '').trim();
            matchedMode = 2;
            break;
          }
        }
      }
    }

    // --- MODE 1: Không tìm được → kiểm tra có dòng nhập tay không
    if (!fullSn) {
      const hasManual = document.querySelector('.product-item[data-nhap-tay="1"]');
      if (hasManual) {
        displayMessage('⚠️ Dòng này đang ở chế độ nhập tay. Vui lòng nhập 3 ký tự cuối.', 'error');
      } else {
        displayMessage(`❌ Không tìm thấy sản phẩm cho SN "${rawSer}".`, 'error');
      }
      scanSoundError.play();
      busy = false;
      return;
    }

    // --- Gán SN vào ô trống trong dòng có product_id & nhap_tay phù hợp
    const targets = Array.from(
      document.querySelectorAll(`.product-item[data-product-id="${productId}"][data-nhap-tay="${matchedMode}"]`)
    );
    for (let item of targets) {
      const empty = Array.from(item.querySelectorAll('input.serial-input')).find(i => i.value.trim() === '');
      if (empty) {
        empty.value = fullSn;
        scannedSet.add(fullSn);
        displayMessage(`✅ Đã thêm SN "${fullSn}" cho "${productName || 'Sản phẩm'}".`);
        scanSound.play();
        item.scrollIntoView({ behavior: 'smooth', block: 'center' });
        busy = false;
        return;
      }
    }

    // --- Nếu không còn ô trống để điền SN
    displayMessage(`❌ Đã quét đủ SN cho "${productName || 'Sản phẩm'}".`, 'error');
    scanSoundError.play();
    busy = false;
  }

  // 7. Khởi động html5-qrcode (giữ nguyên)
  const html5QrCode = new Html5Qrcode('qr-reader');
  const config = {
    fps: 30,
    qrbox: { width: 250, height: 90 },
    formatsToSupport: ['QR_CODE','CODE_128','CODE_39','EAN_13']
  };

  html5QrCode.start(
    { facingMode: { exact: 'environment' } },
    config,
    decodedText => processScanned(decodedText),
    errorMessage => { console.error('Scan error:', errorMessage); }
  ).catch(err => {
    console.error('Không thể khởi động camera sau:', err);
    html5QrCode.start(
      { facingMode: 'environment' },
      config,
      decodedText => processScanned(decodedText),
      console.error
    );
  });

});
</script>
</body>
</html>
