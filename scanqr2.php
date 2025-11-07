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

    /* =========================
       2. Xác định chi nhánh để chọn đúng cờ nhập tay & khóa tem
    ==========================*/
    // === CHỌN CỘT THEO CHI NHÁNH (TRONG BẢNG products) ===
$position  = $_SESSION['position'] ?? '';
$nhapField = 'p.nhap_tay';     // mặc định
$khoaField = 'p.khoa_tem';     // mặc định

if (stripos($position, 'vinh') !== false) {
    $nhapField = 'p.nhap_tay_vinh';
    $khoaField = 'p.khoa_tem_vinh';
} elseif (stripos($position, 'hanoi') !== false || stripos($position, 'ha noi') !== false) {
    $nhapField = 'p.nhap_tay_hanoi';
    $khoaField = 'p.khoa_tem_hanoi';
} elseif (stripos($position, 'hcm') !== false || stripos($position, 'ho chi minh') !== false) {
    $nhapField = 'p.nhap_tay_hcm';
    $khoaField = 'p.khoa_tem_hcm';
}

    // Admin => giữ nguyên p.nhap_tay, p.khoa_tem

    // 3. Lấy danh sách order_products (JOIN theo product_id)
    $sql = "
  SELECT 
    op.id AS order_product_id,
    op.product_id,
    COALESCE(p.product_name, op.product_name) AS display_name,
    op.quantity,
    op.is_promotion,

    -- CHUẨN HÓA MODE NHẬP TAY: 0=quét QR, 1=nhập tay 3-4 số cuối, 2=quét 3 số cuối ghép snnew
    CASE
      WHEN {$nhapField} IN (0,1,2) THEN {$nhapField}
      WHEN {$nhapField} IS NULL OR {$nhapField} = '' 
        THEN CASE WHEN p.snnew IS NOT NULL AND p.snnew <> '' THEN 2 ELSE 0 END
      ELSE 0
    END AS nhap_mode,

    p.snnew,
    COUNT(pw.id) AS scanned_count
  FROM order_products op
  LEFT JOIN products p 
    ON p.id = op.product_id
  LEFT JOIN product_warranties pw 
    ON pw.order_product_id = op.id
  WHERE op.order_id = ?
    AND op.warranty_scan = 1
    AND ({$khoaField} = 0 OR {$khoaField} IS NULL)
    AND (p.print = 0 OR p.print IS NULL)
  GROUP BY
    op.id, op.product_id, display_name, op.quantity, op.is_promotion, nhap_mode, p.snnew
  ORDER BY op.is_promotion ASC, op.id ASC
";


    $stmt = $conn->prepare($sql);
if (!$stmt) { die("Prepare failed: " . $conn->error); }
$stmt->bind_param('i', $orderId);
$stmt->execute();
$result = $stmt->get_result();

$orderProducts = [];
while ($row = $result->fetch_assoc()) {
    $remaining = (int)$row['quantity'] - (int)$row['scanned_count'];
    if ($remaining > 0) {
        $row['remaining'] = $remaining;

        // đảm bảo luôn có int 0/1/2
        $row['nhap_mode'] = (int)$row['nhap_mode'];
        if (!in_array($row['nhap_mode'], [0,1,2], true)) {
            $row['nhap_mode'] = 0;
        }

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
    .scan-ok-overlay{
      position:fixed; left:50%; top:50%; transform:translate(-50%,-50%) scale(0.9);
      background:rgba(40,167,69,.96); color:#fff; border-radius:14px; padding:18px 22px; z-index:1055;
      display:none; box-shadow:0 10px 25px rgba(0,0,0,.25);
      text-align:center; min-width:260px;
    }
    .scan-ok-overlay.show{ display:block; animation:popfade .7s ease-out forwards; }
    .scan-ok-overlay .big-check{ font-size:42px; line-height:1; margin-bottom:6px;}
    .scan-ok-overlay .small{ opacity:.9; font-size:12px; }
    @keyframes popfade { 0%{ transform:translate(-50%,-50%) scale(.7); opacity:0; } 40%{ transform:translate(-50%,-50%) scale(1.05); opacity:1; } 100%{ transform:translate(-50%,-50%) scale(1); opacity:1; } }
    .blink-success{ box-shadow:0 0 0 3px rgba(40,167,69,.35) inset, 0 0 0 2px rgba(40,167,69,.25); transition: box-shadow .25s ease; }
    #toastStack{ position:fixed; right:12px; bottom:12px; z-index:1080; display:flex; flex-direction:column; gap:8px; align-items:flex-end; }
    .toast{ min-width:280px; }
    .error-details pre { white-space:pre-wrap; word-break:break-word; margin:0; }
    .remain-pill{ display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; margin-left:6px; background:#e9ecef; color:#495057; }
    .remain-pill.green{ background:#d4edda; color:#155724; }
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
    <!-- CHÚ Ý: action trỏ đúng file xử lý bạn dùng (process_scan_test.php như bạn ghi) -->
    <form id="scanForm" method="POST" action="process_scan.php">
      <input type="hidden" name="order_code" value="<?= htmlspecialchars($orderCode) ?>">
      <div class="product-list">
        <?php foreach ($orderProducts as $prod): ?>
  <?php 
    $mode = (int)$prod['nhap_mode'];   // 0/1/2
    $snnew = (string)($prod['snnew'] ?? '');
    $isManual = ($mode === 1);
    $placeholder = $isManual ? 'Nhập 3–4 ký tự cuối...' : 'Quét SN bằng camera...';
  ?>
  <div
    class="product-item card shadow-sm mb-3"
    data-order-product-id="<?= (int)$prod['order_product_id'] ?>"
    data-product-id="<?= (int)$prod['product_id'] ?>"
    data-product-name="<?= htmlspecialchars($prod['display_name']) ?>"
    data-nhap-tay="<?= $mode ?>"
    <?= $snnew !== '' ? 'data-sn-new="'.htmlspecialchars($snnew).'"' : '' ?>
    title="Mode: <?= $mode ?><?= $snnew ? ' | SNNEW: '.htmlspecialchars($snnew) : '' ?>"
  >
    <div class="card-body">
      <h4 class="card-title text-secondary mb-1 d-flex align-items-center">
        <span><?= htmlspecialchars($prod['display_name']) ?></span>
        <span class="remain-pill" data-remain-pill>Còn: <b><?= (int)$prod['remaining'] ?></b></span>
        <?php if (!empty($prod['is_promotion'])): ?>
          <small class="text-warning ml-2">(KM)</small>
        <?php endif; ?>
      </h4>

      <p class="card-text mb-2">
        Yêu cầu: 
        <?php if ($mode === 0): ?>
          <span class="text-primary">Quét QR/Mã vạch</span>
        <?php elseif ($mode === 1): ?>
          <span class="text-danger">Nhập tay 3–4 ký tự cuối</span>
        <?php else: ?>
          <span class="text-info">Quét QR/Mã vạch (ghép SNNEW)</span>
        <?php endif; ?>
      </p>

      <p class="card-text mb-2">Số lượng cần quét: <strong data-need><?= (int)$prod['remaining'] ?></strong></p>

      <div class="mt-2">
        <?php for ($i = 0; $i < (int)$prod['remaining']; $i++): ?>
          <input type="text"
                 name="sn[<?= (int)$prod['order_product_id'] ?>][]"
                 class="form-control mb-2 serial-input"
                 placeholder="<?= htmlspecialchars($placeholder) ?>"
                 <?= $isManual ? '' : 'readonly' ?>
                 <?= $isManual ? 'maxlength="4"' : '' ?>
                 required>
        <?php endfor; ?>

        <?php if ($mode === 1): ?>
          <p class="text-danger small mb-0">⚠️ Nhập tay 3–4 ký tự cuối (SP in sai QR không được quét).</p>
        <?php elseif ($mode === 2): ?>
          <p class="text-info small mb-0">ℹ️ Quét 3 ký tự cuối, hệ thống sẽ ghép với <code>SNNEW</code> của sản phẩm.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>

      </div>
      <div class="text-center">
        <button id="submitBtn" type="submit" class="btn btn-success btn-lg px-5 mt-3">Hoàn tất</button>
      </div>
    </form>
  </div>

</div>

<!-- Error Modal -->
<div class="modal fade" id="errorModal" tabindex="-1" role="dialog" aria-labelledby="errorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-danger">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="errorModalLabel">CẢNH BÁO</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Đóng"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-2"><strong>Loại lỗi:</strong> <span id="errorType" class="text-monospace">UNKNOWN</span></p>
        <div class="error-details p-2 bg-light rounded"><pre id="errorMessage">Đã xảy ra lỗi không xác định.</pre></div>
      </div>
      <div class="modal-footer">
        <a href="xem_donhang.php" class="btn btn-outline-secondary">Thoát quét đơn hàng</a>
        <button type="button" class="btn btn-danger" data-dismiss="modal">Thử lại</button>
      </div>
    </div>
  </div>
</div>

<!-- Success Modal (MỚI) -->
<div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-success">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="successModalLabel">Hoàn tất thành công</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Đóng"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Đơn hàng <b><?= htmlspecialchars($orderCode) ?></b> đã được cập nhật trạng thái <b>Đã quét QR</b>.</p>
        <div id="successSummary" class="small text-muted"></div>
      </div>
      <div class="modal-footer">
        <a href="xem_donhang.php" class="btn btn-success">Tiếp tục</a>
      </div>
    </div>
  </div>
</div>

<!-- Scan success overlay + toast stack (đang dùng cho mỗi lần quét) -->
<div id="scanOk" class="scan-ok-overlay" aria-live="polite" aria-atomic="true">
  <div class="big-check">✓</div>
  <div id="scanOkText">Đã ghi nhận</div>
  <div class="small" id="scanOkSub"></div>
</div>
<div id="toastStack" aria-live="polite" aria-atomic="true"></div>

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

  function vibrateOk(){ if(navigator.vibrate){ try{ navigator.vibrate([10,30,10]); }catch(e){} } }
  function pushToast({title='Đã quét', body='', sub=''}) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role','alert'); el.setAttribute('aria-live','assertive'); el.setAttribute('aria-atomic','true'); el.setAttribute('data-delay','3500');
    el.innerHTML = `<div class="toast-header">
        <strong class="mr-auto">${title}</strong><small>${sub}</small>
        <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div><div class="toast-body">${body}</div>`;
    document.getElementById('toastStack').appendChild(el);
    $(el).toast('show').on('hidden.bs.toast',()=>el.remove());
  }
  function showScanOK(sn, productName, remaining) {
    const ok = document.getElementById('scanOk');
    document.getElementById('scanOkText').textContent = 'Đã ghi nhận';
    document.getElementById('scanOkSub').textContent  = `${productName} • SN: ${sn} • Còn: ${remaining}`;
    ok.classList.add('show'); setTimeout(()=> ok.classList.remove('show'), 700);
    pushToast({title:'Quét thành công', body:`<b>${productName}</b><br>SN: <code>${sn}</code><br>Còn: <b>${remaining}</b>`, sub:'vừa xong'});
    vibrateOk(); try{ scanSound.play(); }catch(e){}
  }
  function highlightCard(card){ card.classList.add('blink-success'); setTimeout(()=> card.classList.remove('blink-success'), 1200); }
  function updateRemainingUI(card){
    const inputs = Array.from(card.querySelectorAll('input.serial-input'));
    const remaining = inputs.filter(i => i.value.trim() === '').length;
    const needEl = card.querySelector('[data-need]'); const pillEl = card.querySelector('[data-remain-pill] b');
    if (needEl) needEl.textContent = remaining; if (pillEl) pillEl.textContent = remaining;
    const pillWrap = card.querySelector('.remain-pill');
    if (pillWrap) { if (remaining === 0) pillWrap.classList.add('green'); else pillWrap.classList.remove('green'); }
    if (remaining === 0) {
      card.classList.add('border','border-success');
      inputs.forEach(i => i.readOnly = true);
      if (!card.querySelector('.done-badge')) {
        const tag = document.createElement('div');
        tag.className = 'done-badge text-success mt-1'; tag.innerHTML = '✅ Đã đủ số lượng SN';
        card.querySelector('.card-body').appendChild(tag);
      }
    }
    return remaining;
  }
  function displayMessage(msg, type='success'){
    const el = document.getElementById('scan-message'); el.textContent = msg; el.className=''; el.classList.add(type,'visible'); setTimeout(()=> el.classList.remove('visible'), 2500);
  }

  function unlockOrder(){ navigator.sendBeacon('unlock_order.php', JSON.stringify({ order_code: orderCode })); }
  window.addEventListener('beforeunload', unlockOrder);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') unlockOrder(); else {
      fetch(`check_order_status.php?order_code=${orderCode}`).then(r=>r.json()).then(d=>{
        if (d.status !== 'Đang quét QR') { alert('Đơn hàng đã chuyển trạng thái. Quay về danh sách.'); window.location.href='xem_donhang.php'; }
      }).catch(console.error);
    }
  });

  if (navigator.connection) {
    if (navigator.connection.downlink < 0.1) alert('Mạng yếu, không nên quét QR!');
    navigator.connection.addEventListener('change', () => { if (navigator.connection.downlink < 0.1) alert('Mạng yếu, không nên quét QR!'); });
  }

  function extractSerial(data){
    data = data.trim();
    try { const url = new URL(data); const s = url.searchParams.get('serial'); if (s) data = s.trim(); } catch {}
    if (/^SN:/i.test(data)) data = data.replace(/^SN:/i,'').trim();
    return data.replace(/[^0-9A-Za-z:]/g,'');
  }

  async function lookupSerial(sn){
    const res = await fetch(`http://localhost/khokuchen/api/lookup_sn.php?sn=${encodeURIComponent(sn)}`);
    if (!res.ok) return null; return res.json();
  }

  async function processScanned(raw){
    if (busy) return; busy = true;
    const rawSer = extractSerial(raw);
    if (!rawSer){ displayMessage('❌ Dữ liệu không hợp lệ','error'); try{scanSoundError.play();}catch(e){} busy=false; return; }
    if (scannedSet.has(rawSer)){ displayMessage(`⚠️ SN "${rawSer}" đã quét trước đó.`,'error'); try{scanSoundError.play();}catch(e){} busy=false; return; }

    let fullSn=null, productId=null, productName=null, matchedMode=null;

    const res0 = await lookupSerial(rawSer);
    if (res0 && res0.product_id){
      const pid = String(res0.product_id).trim();
      const items = Array.from(document.querySelectorAll('.product-item[data-nhap-tay="0"]'));
      for (let item of items){
        if ((item.dataset.productId||'').trim() === pid){
          fullSn=rawSer; productId=pid; productName=(res0.product_name||item.dataset.productName||'').trim(); matchedMode=0; break;
        }
      }
    }

    if (!fullSn && rawSer.length>=3){
      const last3 = rawSer.slice(-3);
      const items = Array.from(document.querySelectorAll('.product-item[data-nhap-tay="2"]'));
      for (let item of items){
        const prefix = item.dataset.snNew || '';
        const candidate = prefix + last3;
        if (scannedSet.has(candidate)) continue;
        const res2 = await lookupSerial(candidate);
        if (res2 && res2.product_id){
          const pid2 = String(res2.product_id).trim();
          if ((item.dataset.productId||'').trim() === pid2){
            fullSn=candidate; productId=pid2; productName=(res2.product_name||item.dataset.productName||'').trim(); matchedMode=2; break;
          }
        }
      }
    }

    if (!fullSn){
      const hasManual = document.querySelector('.product-item[data-nhap-tay="1"]');
      if (hasManual){ displayMessage('⚠️ Dòng này đang ở chế độ nhập tay. Vui lòng nhập 3 ký tự cuối.','error'); }
      else { displayMessage(`❌ Không tìm thấy sản phẩm cho SN "${rawSer}".`,'error'); }
      try{scanSoundError.play();}catch(e){} busy=false; return;
    }

    const targets = Array.from(document.querySelectorAll(`.product-item[data-product-id="${productId}"][data-nhap-tay="${matchedMode}"]`));
    for (let item of targets){
      const empty = Array.from(item.querySelectorAll('input.serial-input')).find(i=>i.value.trim()==='');
      if (empty){
        empty.value = fullSn; scannedSet.add(fullSn);
        const remain = updateRemainingUI(item); highlightCard(item); showScanOK(fullSn, (productName||item.dataset.productName||'Sản phẩm'), remain);
        item.scrollIntoView({behavior:'smooth', block:'center'}); busy=false; return;
      }
    }

    displayMessage(`❌ Đã quét đủ SN cho "${productName || 'Sản phẩm'}".`,'error'); try{scanSoundError.play();}catch(e){} busy=false;
  }

  const html5QrCode = new Html5Qrcode('qr-reader');
  const config = { fps:30, qrbox:{width:250,height:90}, formatsToSupport:['QR_CODE','CODE_128','CODE_39','EAN_13'] };
  html5QrCode.start({facingMode:{exact:'environment'}}, config, decodedText=>processScanned(decodedText), console.error)
  .catch(err=>{
    console.error('Không thể khởi động camera sau:', err);
    html5QrCode.start({facingMode:'environment'}, config, decodedText=>processScanned(decodedText), console.error);
  });

  // ===== Submit AJAX + Success Modal / Error Modal =====
  const form = document.getElementById('scanForm');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    submitBtn.disabled = true;
    try{
      const res = await fetch(form.action, {
        method:'POST', body:new FormData(form),
        headers:{ 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
        credentials:'same-origin'
      });

      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')){
        const txt = await res.text();
        // server cũ trả script -> cứ chuyển trang
        if (/window\.location\.href\s*=/.test(txt)) { window.location.href='xem_donhang.php'; return; }
        alert('Không nhận được phản hồi JSON hợp lệ.\n' + txt);
        return;
      }

      const data = await res.json();

      if (data.success){
        // >>> Hiển thị Success Modal trước khi chuyển trang <<<
        // data.summary có thể có inserted, products...
        const summaryEl = document.getElementById('successSummary');
        if (summaryEl){
          const inserted = (data.summary && typeof data.summary.inserted !== 'undefined') ? data.summary.inserted : null;
          const text = inserted !== null ? `Đã ghi nhận ${inserted} mã SN.` : (data.message || 'Hoàn tất cập nhật.');
          summaryEl.textContent = text;
        }
        $('#successModal').modal('show');
        try{ vibrateOk(); scanSound.play(); }catch(e){}
        // tự động chuyển sau 1.2s (vẫn có nút "Về danh sách" nếu muốn đi ngay)
        setTimeout(()=> { window.location.href = 'xem_donhang.php'; }, 2000);
      } else {
        document.getElementById('errorType').textContent = data.error_code || 'UNKNOWN';
        document.getElementById('errorMessage').textContent = data.message || 'Đã xảy ra lỗi không xác định.';
        $('#errorModal').modal('show'); try{ scanSoundError.play(); }catch(e){}
      }
    }catch(err){
      document.getElementById('errorType').textContent = 'NETWORK_OR_SERVER';
      document.getElementById('errorMessage').textContent = 'Không thể gửi yêu cầu. Vui lòng kiểm tra mạng hoặc thử lại sau.';
      $('#errorModal').modal('show'); try{ scanSoundError.play(); }catch(e){}
    }finally{
      submitBtn.disabled = false;
    }
  });
});
</script>
</body>
</html>
