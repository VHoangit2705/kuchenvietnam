<?php
include 'auth.php';
session_start();
if (!isset($_SESSION['orders']) || empty($_SESSION['orders'])) {
    header('Location: create_order.php');
    exit();
}

include 'config.php'; // $conn MySQLi

// ====== Data từ session ======
$orders = $_SESSION['orders'];
unset($_SESSION['orders']);

// ====== Helpers ======
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function vnd($n){ return number_format((float)$n, 0, ',', '.') . ' VNĐ'; }
function statusBadgeClass($s){
  $s = trim((string)$s);
  return match ($s) {
    'Đang chờ quét QR' => 'bg-warning text-dark',
    'Đã quét QR'       => 'bg-success',
    'Đã hủy đơn hàng', 'Đã huỷ đơn hàng' => 'bg-danger',
    default            => 'bg-secondary'
  };
}
function labelType($t){
  $t = strtolower(trim((string)$t));
  return match ($t) {
    'outside'            => 'Tại kho',
    'droppii'            => 'DROPPII VIETTEL',
    'warehouse_viettel'  => 'KHÁCH LẺ VIETTEL',
    'warehouse_ghtk'     => 'KHÁCH LẺ GHTK',
    'warehouse_branch'   => 'KHÁCH LẺ QUA KHO',
    default              => strtoupper($t)
  };
}

$totalOrders = count($orders);

// ====== Chuẩn bị mảng cho JS: truy vấn DB lấy id theo order_code2 ======
$ordersForJs = [];
$stmt = $conn->prepare("SELECT id FROM orders WHERE order_code2 = ? LIMIT 1");
foreach ($orders as $o) {
    $order_code2 = $o['order_code2'] ?? '';
    $id = null;
    if ($order_code2 !== '') {
        $stmt->bind_param("s", $order_code2);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $id = (int)$row['id'];
    }
    $ordersForJs[] = [
        'id'            => $id,
        'order_code2'   => $order_code2,
        'order_code1'   => $o['order_code1'] ?? '',
        'customer_name' => $o['customer_name'] ?? '',
    ];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Thêm đơn hàng thành công</title>

  <!-- Bootstrap / Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <style>
    body{ background:#f6f7fb; }
    .success-hero{
      background: linear-gradient(135deg,#d1f3e0 0%, #eaf7ff 100%);
      border:1px solid #e7f2ff;
      border-radius:1rem;
      padding:1.25rem;
    }
    .tick-icon{
      font-size:2.5rem; color:#28a745;
      filter: drop-shadow(0 4px 8px rgba(40,167,69,.25));
      animation: pop .6s ease;
    }
    @keyframes pop{ 0%{transform:scale(.6);opacity:.2} 100%{transform:scale(1);opacity:1} }

    .card-order{ border:none; border-radius:.75rem; box-shadow:0 8px 18px rgba(0,0,0,.06); }
    .card-order .card-header{ background:#fff; border-bottom:1px dashed #e9ecef; }
    .list-unstyled li i{ width:18px; }
    .table-products thead th{ background:#f8f9fa; }
    .btnbar .btn{ min-width:180px; }

    .progress{ height:.6rem; background:#e9ecef; }
    .progress-bar{ transition: width .35s ease; }

    /* ===== Modal phiếu bảo hành 80% màn hình ===== */
    #warrantyModal .modal-dialog{
      max-width: 80vw;         /* 80% bề ngang */
      width: 80vw;
    }
    #warrantyModal .modal-body{
      padding: 0.75rem 1rem 1rem;
    }
    .iframe-wrapper{
      position: relative;
      min-height: 70vh;        /* fallback */
      height: 80vh;            /* 80% bề dọc */
    }
    .iframe-wrapper iframe{
      width: 100%;
      height: 100%;
      border: 0;
      border-radius: .5rem;
      background: #fff;
    }
    .iframe-loading{
      position:absolute; inset:0;
      display:flex; align-items:center; justify-content:center;
      background: rgba(255,255,255,.4);
      backdrop-filter: blur(1px);
      border-radius:.5rem;
    }

    /* Mobile friendly */
    @media (max-width: 576px){
      #warrantyModal .modal-dialog{
        max-width: 95vw; width:95vw;
      }
      .iframe-wrapper{ height: 75vh; }
    }
  </style>
</head>
<body>
<div class="container py-4">

  <!-- Hero -->
  <div class="success-hero mb-4 text-center">
    <div class="mb-2">
      <i class="fa-solid fa-circle-check tick-icon"></i>
    </div>
    <h3 class="mb-1">Đã tạo <?= (int)$totalOrders ?> đơn hàng thành công!</h3>
    <p class="text-muted mb-3">
      Bạn có thể in chứng từ hoặc tiếp tục tạo đơn mới. <br class="d-none d-sm-inline">
      Hệ thống chỉ chuyển trang <b>sau khi bạn bấm “Tạo thêm đơn”</b>.
    </p>

    <!-- Progress chỉ hiện khi bấm Tạo thêm đơn -->
    <div id="redirectArea" class="mx-auto" style="max-width:420px; display:none;">
      <div class="progress mb-1">
        <div id="cdProgress" class="progress-bar bg-success" role="progressbar" style="width:100%"></div>
      </div>
      <div class="small text-muted">
        Chuyển hướng sau <span id="countdown">3</span> giây —
        <a href="create_order.php#tab-history" class="fw-semibold">về ngay</a>
      </div>
    </div>
  </div>

  <!-- Orders list -->
  <div class="row g-3">
    <?php foreach ($orders as $i => $order): ?>
      <div class="col-12">
        <div class="card card-order">
          <div class="card-header d-flex flex-wrap justify-content-between align-items-center py-2 px-3">
            <div class="d-flex align-items-center gap-2">
              <span class="badge text-bg-primary">#<?= $i+1 ?></span>
              <span class="fw-semibold">Mã ĐH:</span>
              <span class="text-dark"><?= esc($order['order_code2'] ?? '') ?></span>
              <span class="text-muted">/ <?= esc($order['order_code1'] ?? '') ?></span>
              <?php if (!empty($order['type'])): ?>
                <span class="badge text-bg-secondary ms-2"><?= esc(labelType($order['type'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($order['status'])): ?>
                <span class="badge <?= statusBadgeClass($order['status']) ?> px-3">
                  <i class="fa-solid fa-warehouse me-1"></i><?= esc($order['status']) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
         <?php
// Luôn build theo format: customer_address, wards, district, province
$addrRaw = (string)($order['customer_address'] ?? '');

$provinceId = (int)($order['province'] ?? 0);
$districtId = (int)($order['district'] ?? 0);
$wardsId    = (int)($order['wards'] ?? 0);

$provinceName = '';
$districtName = '';
$wardsName    = '';

if ($provinceId > 0) {
  $stmt = $conn->prepare("SELECT name FROM province WHERE province_id = ?");
  $stmt->bind_param('i', $provinceId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $provinceName = (string)($row['name'] ?? '');
  $stmt->close();
}
if ($districtId > 0) {
  $stmt = $conn->prepare("SELECT name FROM district WHERE district_id = ?");
  $stmt->bind_param('i', $districtId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $districtName = (string)($row['name'] ?? '');
  $stmt->close();
}
if ($wardsId > 0) {
  $stmt = $conn->prepare("SELECT name FROM wards WHERE wards_id = ?");
  $stmt->bind_param('i', $wardsId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $wardsName = (string)($row['name'] ?? '');
  $stmt->close();
}

// Ghép đúng thứ tự: customer_address, wards, district, province
// Loại phần trống để không có dấu phẩy thừa
$parts = array_filter([$addrRaw, $wardsName, $districtName, $provinceName], fn($x) => $x !== null && $x !== '');
$addressDisplay = implode(', ', $parts);
?>

          <div class="card-body">
            <div class="row gy-2">
              <div class="col-md-6">
                <ul class="list-unstyled mb-0 small">
                  <li class="mb-1">
                    <i class="fa-solid fa-user text-secondary me-2"></i>
                    <b><?= esc($order['customer_name'] ?? '') ?></b>
                    — <?= esc($order['customer_phone'] ?? '') ?>
                  </li>
                  <li class="text-truncate mb-1" title="<?= esc($addressDisplay) ?>">
  <i class="fa-solid fa-location-dot text-secondary me-2"></i>
  <?= esc($addressDisplay) ?>
</li>

                  <li>
                    <i class="fa-solid fa-store text-secondary me-2"></i>
                    <?= esc($order['agency_name'] ?? '') ?> — <?= esc($order['agency_phone'] ?? '') ?>
                  </li>
                </ul>
              </div>
              <div class="col-md-6">
                <ul class="list-unstyled mb-0 small">
                  <li class="mb-1">
                    <i class="fa-solid fa-tags text-secondary me-2"></i>
                    Mã khuyến mại: <?= esc($order['discount_code'] ?? '') ?: '<span class="text-muted">—</span>' ?>
                  </li>
                  <li class="mb-1">
                    <i class="fa-solid fa-credit-card text-secondary me-2"></i>
                    Thanh toán: <?= esc($order['payment_method'] ?? '—') ?>
                  </li>
                  <li>
                    <i class="fa-solid fa-sack-dollar text-secondary me-2"></i>
                    Tổng tiền: <b><?= vnd($order['total_price'] ?? 0) ?></b>
                  </li>
                </ul>
              </div>
            </div>

            <div class="table-responsive mt-3">
              <table class="table table-sm table-products align-middle">
                <thead>
                  <tr>
                    <th style="width:70%">Sản phẩm</th>
                    <th class="text-end">SL</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($order['products']) && is_array($order['products'])): ?>
                    <?php foreach ($order['products'] as $p): ?>
                      <tr>
                        <td><?= esc($p['product_name'] ?? '') ?></td>
                        <td class="text-end"><?= (int)($p['quantity'] ?? 0) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="2" class="text-muted">Không có dữ liệu sản phẩm.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Quick actions -->
  <div class="btnbar d-flex flex-wrap gap-2 justify-content-center mt-4">
    <a class="btn btn-primary" href="create_order.php#tab-history">
      <i class="fa-solid fa-clock-rotate-left me-1"></i> Về lịch sử đơn
    </a>

    <button id="btnCreateMore" class="btn btn-outline-secondary">
      <i class="fa-solid fa-plus me-1"></i> Tạo thêm đơn
    </button>

    <!-- In hóa đơn: chuyển hướng confirm_order.php?id=... -->
    <button id="btnPrintInvoice" class="btn btn-warning" 
            data-bs-toggle="tooltip" data-bs-html="true"
            title="In hóa đơn / phiếu giao hàng">
      <i class="fas fa-print me-1"></i> In hóa đơn
    </button>

    <!-- In phiếu bảo hành: mở modal 80% màn hình -->
    <button id="btnPrintWarranty" class="btn btn-success"
            data-bs-toggle="tooltip" data-bs-html="true"
            title="In phiếu bảo hành ngay tại trang (Ctrl+P)">
      <i class="fas fa-clipboard-check me-1"></i> In phiếu bảo hành
    </button>

    <a class="btn btn-success" href="admin.php">
      <i class="fa-solid fa-list me-1"></i> Danh sách đơn hàng
    </a>
  </div>
</div>

<!-- Modal chọn đơn (khi có nhiều đơn) -->
<div class="modal fade" id="selectOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Chọn đơn</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small text-muted">Chọn 1 đơn trong danh sách vừa tạo:</div>
        <select id="orderSelect" class="form-select"></select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" id="selectOkBtn" class="btn btn-primary">Tiếp tục</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal phiếu bảo hành 80% màn hình -->
<div class="modal fade" id="warrantyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><!-- kích cỡ set bằng CSS -->
    <div class="modal-content">
      <div class="modal-header align-items-center">
        <h6 class="modal-title d-flex align-items-center m-0">
          <i class="fas fa-clipboard-check me-2"></i> Phiếu bảo hành
        </h6>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-outline-primary btn-sm" id="btnWarrantyPrint" title="In (Ctrl+P)">
            <i class="fas fa-print me-1"></i> In
          </button>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>
      </div>
      <div class="modal-body">
        <div class="iframe-wrapper">
          <div class="iframe-loading" id="warrantyLoading">
            <div class="text-center">
              <div class="spinner-border" role="status" aria-hidden="true"></div>
              <div class="small text-muted mt-2">Đang tải nội dung...</div>
            </div>
          </div>
          <iframe id="warrantyFrame" src="about:blank" title="Warranty Preview"></iframe>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // ===== Cấu hình URL =====
  const INVOICE_URL  = 'confirm_order.php?id=';   // chuyển hướng
  const WARRANTY_URL = 'print_invoice2.php?id=';  // nạp vào iframe trong modal

  // ===== Data cho JS =====
  const orders = <?= json_encode($ordersForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // ===== Tooltip =====
  [...document.querySelectorAll('[data-bs-toggle="tooltip"]')]
    .forEach(el => new bootstrap.Tooltip(el));

  // ===== Đếm ngược khi bấm "Tạo thêm đơn" =====
  const redirectArea  = document.getElementById('redirectArea');
  const cd            = document.getElementById('countdown');
  const pb            = document.getElementById('cdProgress');
  const btnCreateMore = document.getElementById('btnCreateMore');
  let timer = null, ttlSeconds = 3;
  const dest = 'create_order.php'; // hoặc '#tab-history'

  btnCreateMore.addEventListener('click', () => {
    redirectArea.style.display = 'block';
    btnCreateMore.disabled = true;
    let timeLeft = ttlSeconds;
    cd.textContent = timeLeft;
    pb.style.width = '100%';
    timer = setInterval(() => {
      timeLeft--;
      cd.textContent = timeLeft;
      pb.style.width = Math.max(0, (timeLeft/ttlSeconds)*100) + '%';
      if (timeLeft <= 0) {
        clearInterval(timer);
        window.location.href = dest;
      }
    }, 1000);
  });

  // ===== Modal chọn đơn dùng chung =====
  const selectOrderModal = new bootstrap.Modal('#selectOrderModal');
  const orderSelect = document.getElementById('orderSelect');
  const selectOkBtn = document.getElementById('selectOkBtn');
  let nextAction = null; // 'invoice' | 'warranty'

  function ensureOrderId(o){ return o && o.id !== null && o.id !== undefined; }

  function chooseOrderThen(action){
    nextAction = action;
    if (!orders || orders.length === 0) return;

    if (orders.length === 1 && ensureOrderId(orders[0])) {
      proceedAction(action, orders[0].id);
      return;
    }
    orderSelect.innerHTML = '';
    orders.forEach((o, idx) => {
      const labelCode = (o.order_code2 || o.order_code1 || ('ID ' + (o.id ?? '')));
      const opt = document.createElement('option');
      opt.value = o.id ?? '';
      opt.textContent = `#${idx+1} - ${labelCode} - ${o.customer_name || ''}`;
      orderSelect.appendChild(opt);
    });
    selectOrderModal.show();
  }

  selectOkBtn.addEventListener('click', () => {
    const id = orderSelect.value;
    if (!id) return;
    selectOrderModal.hide();
    proceedAction(nextAction, id);
  });

  // ===== Hóa đơn vs Bảo hành =====
  document.getElementById('btnPrintInvoice').addEventListener('click',  () => chooseOrderThen('invoice'));
  document.getElementById('btnPrintWarranty').addEventListener('click', () => chooseOrderThen('warranty'));

  function proceedAction(action, id){
    if (!id) return;
    if (action === 'invoice') {
      window.location.href = INVOICE_URL + encodeURIComponent(id); // chuyển hướng
    } else if (action === 'warranty') {
      openWarrantyModal(id); // mở modal lớn 80% + iframe
    }
  }

  // ===== Modal bảo hành + phím nóng Ctrl+P / ⌘+P =====
  const warrantyModal    = new bootstrap.Modal('#warrantyModal');
  const warrantyFrame    = document.getElementById('warrantyFrame');
  const warrantyLoading  = document.getElementById('warrantyLoading');
  const btnWarrantyPrint = document.getElementById('btnWarrantyPrint');
  const warrantyModalEl  = document.getElementById('warrantyModal');

  function openWarrantyModal(id){
    warrantyLoading.style.display = 'flex';
    warrantyFrame.src = 'about:blank';
    warrantyModal.show();
    const url = WARRANTY_URL + encodeURIComponent(id);
    // Khi iframe load xong → ẩn loading
    warrantyFrame.onload = () => { warrantyLoading.style.display = 'none'; };
    warrantyFrame.src = url;
  }

  // Nút In trong modal
  btnWarrantyPrint.addEventListener('click', () => {
    if (warrantyFrame?.contentWindow) {
      warrantyFrame.contentWindow.focus();
      warrantyFrame.contentWindow.print();
    }
  });

  // Bắt phím Ctrl+P / ⌘+P khi modal đang mở → in iframe, chặn in toàn trang
  document.addEventListener('keydown', (e) => {
    const isPrintShortcut = (e.key?.toLowerCase() === 'p') && (e.ctrlKey || e.metaKey);
    const modalOpen = warrantyModalEl.classList.contains('show');
    if (isPrintShortcut && modalOpen) {
      e.preventDefault();
      if (warrantyFrame?.contentWindow) {
        warrantyFrame.contentWindow.focus();
        warrantyFrame.contentWindow.print();
      }
    }
  });

  // (Tùy chọn) Khi đóng modal → xóa src để giải phóng tài nguyên
  warrantyModalEl.addEventListener('hidden.bs.modal', () => {
    warrantyFrame.src = 'about:blank';
  });
</script>
</body>
</html>
