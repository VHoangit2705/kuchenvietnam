<?php
// ================== AUTH & SESSION ==================
include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
  header("Location: index.php");
  exit();
}
include 'config.php';
$conn->set_charset('utf8mb4');

// ================== HELPERS (PHP) ===================
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function getZoneFilterSql(mysqli $conn, string $position): string {
  if (in_array($position, ['Đơn hàng Vinh','Đơn hàng HaNoi','Đơn hàng HCM'], true)) {
    return " AND o.zone = '" . $conn->real_escape_string($position) . "'";
  }
  return '';
}
function isNew5m($createdAt){
  $ts = strtotime($createdAt ?: '1970-01-01');
  return (time() - $ts) <= 300; // 300s = 5 phút
}
// Đổi type → nhãn hiển thị trong lịch sử
function labelOrderType($t){
  switch (strtolower(trim((string)$t))) {
    case 'outside':            return 'Tại kho';
    case 'droppii':            return 'DROPPII VIETTEL';
    case 'warehouse_viettel':  return 'KHÁCH LẺ VIETTEL';
    case 'warehouse_ghtk':     return 'KHÁCH LẺ GHTK';
    case 'warehouse_branch':   return 'KHÁCH LẺ QUA KHO';
    default:                   return $t;
  }
}
// Warehouse label theo vị trí
$position = $_SESSION['position'] ?? '';
switch ($position) {
  case 'Đơn hàng Vinh':  $warehouseLabel = 'Giao hàng từ kho Vinh'; break;
  case 'Đơn hàng HaNoi': $warehouseLabel = 'Giao hàng từ kho HaNoi'; break;
  case 'Đơn hàng HCM':   $warehouseLabel = 'Giao hàng từ kho HCM'; break;
  default:               $warehouseLabel = 'Giao hàng từ kho ADMIN'; break;
}

// ================== LỊCH SỬ 10 ĐƠN ==================
$zoneFilter = getZoneFilterSql($conn, $position);
$sqlHist = "
  SELECT 
    o.id, o.order_code1, o.order_code2, o.customer_name, o.customer_phone, 
    o.type, o.created_at,
    COUNT(od.id) AS items, COALESCE(SUM(od.price),0) AS total
  FROM orders o
  LEFT JOIN order_products od ON od.order_id = o.id
  WHERE 1=1 $zoneFilter
  GROUP BY o.id
  ORDER BY o.id DESC
  LIMIT 10
";
$hist = [];
if ($res = $conn->query($sqlHist)) {
  while ($r = $res->fetch_assoc()) $hist[] = $r;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <title>Lên đơn xuất kho - KUCHEN</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <style>
    body{ background:#f7f8fa; }
    .toolbar{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; justify-content:space-between; }
    .toolbar .left, .toolbar .right{ display:flex; gap:.5rem; align-items:center; }
    .card-order{ border:none; box-shadow:0 1px 8px rgba(0,0,0,.08); border-radius:.75rem; }
    .card-order .card-title{ color:#0d6efd; font-weight:700; }
    .select2-container .select2-selection--single{ height:38px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered{ line-height:38px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow{ height:38px; }
    .mini-muted{ font-size:.9rem; color:#6c757d; }
    .table-history th, .table-history td{ vertical-align:middle; }
    .required-star{ color:red; font-weight:700; }
    .section-title{ font-size:1.05rem; font-weight:700; margin-top:.75rem; }
    .badge-type{ text-transform:uppercase; }
    .border-dash{ border:1px dashed #888; border-radius:.5rem; padding:.5rem .75rem; display:inline-block; }

    .fab-submit{ position: fixed; right: 16px; bottom: 16px; z-index: 1040; }
    .fab-submit .fab-btn{
      font-size: .9rem;
      padding: .45rem .9rem;
      border-radius: 999px;
      box-shadow: 0 6px 18px rgba(13,110,253,.28);
    }

    .product-entry{ scroll-margin-top: 90px; }

    .badge-new{
      background:#ff4757; color:#fff; font-weight:700; letter-spacing:.5px;
      animation: neonBlink .9s ease-in-out infinite; border-radius:.35rem;
    }
    @keyframes neonBlink{ 0%,100%{opacity:1} 50%{opacity:.25} }
    .is-invalid { border-color:#dc3545 !important; }

    #tour-backdrop{ position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1055; display: none; }
    .tour-highlight{
      position: relative; z-index: 1061 !important;
      box-shadow: 0 0 0 4px #fff, 0 0 0 10px rgba(13,110,253,.5);
      border-radius: .5rem; transition: box-shadow .2s ease;
    }
    .debug-note{white-space:pre-wrap;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
  </style>
</head>
<body>
<div class="container py-3">
  <div class="text-center mb-3">
    <h2 class="mb-1">LÊN ĐƠN XUẤT KHO HÀNG HÓA</h2>
    <div class="mini-muted" style="color:red;"><?= esc($warehouseLabel) ?></div>
  </div>

  <form action="process_orders.php" method="POST" id="orderForm" novalidate>
    <div class="toolbar bg-white p-3 rounded-3 shadow-sm mb-3">
      <div class="left">
        <label for="order_count" class="form-label mb-0 me-2">
          <i class="fas fa-layer-group me-1"></i>
          Lựa chọn số đơn muốn lên
        </label>
        <select class="form-select" id="order_count" name="order_count" required style="min-width:220px;" disabled>
          <option value="">-- Chọn số đơn (tối đa 5) --</option>
          <?php for ($i=1;$i<=5;$i++): ?>
            <option value="<?= $i ?>" <?= $i===1 ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="right">
        <a href="admin.php" class="btn btn-success">
          <i class="fas fa-list-ul me-1"></i> Quay lại danh sách đơn hàng
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-qrcode me-1"></i> Chuyển đơn để quét QR bảo hành
        </button>
        <button type="button" class="btn btn-outline-primary" id="btnTour">
          <i class="fa-solid fa-person-chalkboard me-1"></i> Hướng dẫn
        </button>
      </div>
    </div>

    <ul class="nav nav-tabs" id="pageTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-create" data-bs-toggle="tab" data-bs-target="#pane-create" type="button" role="tab">
          <i class="fas fa-plus-circle me-1"></i> Tạo đơn
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button" role="tab">
          <i class="fas fa-history me-1"></i> Lịch sử 10 đơn gần nhất
        </button>
      </li>
    </ul>

    <div class="tab-content">
      <div class="tab-pane fade show active pt-3" id="pane-create" role="tabpanel">
        <div id="order_forms" class="row gy-3"></div>
      </div>

      <div class="tab-pane fade pt-3" id="pane-history" role="tabpanel">
        <div class="card">
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-striped table-history align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Thời gian</th>
                    <th>Mã đơn hàng</th>
                    <th>Mã ĐVVC</th>
                    <th>Khách hàng</th>
                    <th>Loại đơn</th>
                    <th class="text-end">SL SP</th>
                    <th class="text-end">Tổng tiền (VND)</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($hist): foreach ($hist as $h): ?>
                    <tr>
                      <td><?= date('H:i d/m/Y', strtotime($h['created_at'])) ?></td>
                      <td>
                        <span class="fw-semibold"><?= esc($h['order_code2']) ?></span>
                        <?php if (isNew5m($h['created_at'])): ?>
                          <span class="badge badge-new ms-1">NEW</span>
                        <?php endif; ?>
                      </td>
                      <td><?= esc($h['order_code1']) ?></td>
                      <td><?= esc($h['customer_name']) ?><div class="mini-muted"><?= esc($h['customer_phone']) ?></div></td>
                      <td><span class="badge text-bg-secondary badge-type"><?= esc(labelOrderType($h['type'])) ?></span></td>
                      <td class="text-end"><?= (int)$h['items'] ?></td>
                      <td class="text-end"><?= number_format($h['total'], 0, ',', '.') ?></td>
                      <td>
                        <a class="btn btn-sm btn-outline-primary" href="order_detail.php?id=<?= (int)$h['id'] ?>">
                          <i class="fas fa-external-link-alt"></i>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted">Chưa có dữ liệu.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div><!-- /pane-history -->
    </div><!-- /tab-content -->

    <div class="fab-submit">
      <button type="submit" class="btn btn-primary fab-btn">
        <i class="fas fa-paper-plane me-1"></i> Chuyển quét QR
      </button>
    </div>
  </form>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// ======================================================
// =============== CẤU HÌNH / DEBUG SWITCH ==============
// ======================================================
const position = <?= json_encode($position) ?>;
const warehouseLabel = <?= json_encode($warehouseLabel) ?>;

// Bật từng loại debug tùy nhu cầu
const DEBUG_ADMIN = true;              // bật log tổng quan
const DEBUG_FETCH_WRAP = true;         // log URL khi fetch API địa giới & tìm số ĐT
const DEBUG_PROVINCE_FLOW = true;      // log flow khi chọn Province
const DEBUG_DISTRICT_FLOW = true;      // log flow khi chọn District
const DEBUG_WARD_FLOW = true;          // log flow khi chọn Ward
const DEBUG_FILL_OPTIONS = true;       // log khi fillOptions
const DEBUG_SELECT2 = true;            // log init select2 & events
const DEBUG_AUTOFILL = true;           // log auto-fill theo SĐT

// Wrap console helper
function dlog(flag, ...args){ if (flag) console.log(...args); }

// ========= FETCH WRAPPER: log mọi call liên quan địa giới =========
(function(){
  const _origFetch = window.fetch;
  window.fetch = async function(url, opts){
    if (DEBUG_FETCH_WRAP && typeof url === 'string') {
      if (url.includes('get_provinces.php') || url.includes('get_districts.php') || url.includes('get_wards.php') || url.includes('get-customer-info.php')) {
        console.groupCollapsed('%c[FETCH]', 'color:#0b7285;font-weight:bold', url);
        try {
          const u = new URL(url, window.location.href);
          dlog(true, 'pathname:', u.pathname, 'search:', u.search);
          if (u.pathname.endsWith('get_districts.php')) {
            dlog(true, '→ province_id =', u.searchParams.get('province_id'));
          }
          if (u.pathname.endsWith('get_wards.php')) {
            dlog(true, '→ district_id =', u.searchParams.get('district_id'));
          }
          if (u.pathname.endsWith('get-customer-info.php')) {
            dlog(true, '→ phone =', u.searchParams.get('phone'));
          }
        } catch(e){ dlog(true, 'URL parse error:', e); }
        console.groupEnd();
      }
    }
    return _origFetch(url, opts);
  };
})();

// ======================================================
// ================== HÀM DÙNG CHUNG ====================
// ======================================================
let PRODUCTS = [];
const ORDER_TYPES_WITH_SHIP_UNIT = new Set(['lazada','shopee','tiktok','shopee_risoli']);
const ORDER_TYPES_AUTO_CODE = new Set(['warehouse_viettel','warehouse_ghtk']);
// Các loại đơn sẽ dùng giá bán lẻ (price_retail)
const ORDER_TYPES_USE_RETAIL = new Set(['warehouse_branch','warehouse_viettel','warehouse_ghtk']);
const API_CUSTOMER_BY_PHONE   = 'https://kuchenvietnam.vn/kuchen/khokuchen/api/get-customer-info.php?phone=';
const API_FIND_BY_PHONE_LOCAL = 'api/get-customer-info.php?phone=';

const formatVND = (n) => (Number(n||0)).toLocaleString('vi-VN') + ' VNĐ';
const formatNumber = (n) => (Number(n||0)).toLocaleString('vi-VN');
const parseVND = (s) => {
  if (typeof s !== 'string') return Number(s||0);
  const num = s.replace(/[^\d\-]/g,'');
  return Number(num||0);
};
const escapeHtml = (str) => (str??'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));

async function fetchProducts() {
  try {
    const resp = await fetch('get_products.php',{credentials:'same-origin'});
    PRODUCTS = await resp.json();
    dlog(DEBUG_ADMIN, '[PRODUCTS] loaded items:', PRODUCTS?.length);
  } catch(e) {
    console.error('Lỗi tải products', e);
    PRODUCTS = [];
  }
}
function productOptionsHtml() {
  return PRODUCTS.map(p => `
    <option 
      value="${escapeHtml(p.product_name)}"
      data-price="${Number(p.price)||0}"
      data-price-retail="${Number(p.price_retail)||0}">
      ${escapeHtml(p.product_name)}
    </option>
  `).join('');
}

async function isOrderCodeDuplicate(code) {
  try {
    const resp = await fetch('check_order_code2.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'order_code=' + encodeURIComponent(code)
    });
    const data = await resp.json();
    return !!data.is_duplicate;
  } catch(e) {
    console.error('Check duplicate error', e);
    return false;
  }
}
async function generateUniqueOrderCode() {
  const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  while (true) {
    const t = Date.now().toString().slice(-6);
    const code = `KU${letters[Math.floor(Math.random()*letters.length)]}${t}`;
    const isDup = await isOrderCodeDuplicate(code);
    if (!isDup) return code;
  }
}
async function ensureUniqueOrderCode2(inputEl) {
  let cur = inputEl.value.trim();
  if (!cur) return;
  const check = async (code) => {
    try {
      const resp = await fetch('check_order_code2.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'order_code=' + encodeURIComponent(code)
      });
      return await resp.json(); // {is_duplicate, previous_status?}
    } catch(e) {
      console.error('check_order_code2 error', e);
      return {is_duplicate:false};
    }
  };
  let data = await check(cur);
  if (!data.is_duplicate) return;
  const m = cur.match(/^(.*?)(?:\s\((\d+)\))?$/);
  const base = m ? m[1] : cur;
  let n = m && m[2] ? parseInt(m[2],10) : 1;
  if (data.previous_status === 'Đã hủy đơn hàng') {
    n = Math.max(1, n-1);
    let proposal = `${base} (${n})`;
    data = await check(proposal);
    if (!data.is_duplicate) {
      inputEl.value = proposal;
      alert(`Mã đã tồn tại trước đó (đã hủy). Đề xuất mã: ${proposal}`);
      return;
    }
  }
  do {
    n++;
    const proposal = `${base} (${n})`;
    data = await check(proposal);
    if (!data.is_duplicate) {
      inputEl.value = proposal;
      alert(`Mã "${cur}" đã tồn tại, đổi thành: ${proposal}`);
      return;
    }
  } while (true);
}
function validateOrderCode2Syntax(val) {
  const regex1 = /^[A-Z]{3}\d{6,}$/;
  const regex3 = /^[A-Z]{3}\d{6,12}$/;
  const regex4 = /^[A-Z]{3}\d{6,12}\(\d\)$/;
  const regex5 = /^[A-Z0-9]{14}$/;
  return regex1.test(val) || regex3.test(val) || regex4.test(val) || regex5.test(val);
}

// ======================================================
// ================== ĐƠN HÀNG: OPTIONS =================
// ======================================================
function orderModeOptionsHtml() {
  if (position === 'Đơn hàng Vinh' || position === 'admin') {
    return `
      <option value="droppii">Droppii Viettel Post</option>
      <option value="droppii_ghtk">Droppii Giao hàng tiết kiệm</option>
      <option value="outside">Droppii qua kho</option>
      <option value="warehouse_viettel">Khách lẻ Viettel Post</option>
      <option value="warehouse_ghtk">Khách lẻ Giao hàng tiết kiệm</option>
      <option value="warehouse_branch">Khách lẻ qua kho</option>
      <option value="lazada">Đơn hàng Lazada</option>
      <option value="tiktok">Đơn hàng Tiktok</option>
      <option value="shopee">Đơn hàng Shopee</option>
    `;
  } else if (position === 'Đơn hàng HaNoi') {
    return `
      <option value="droppii">Droppii Viettel Post</option>
      <option value="droppii_ghtk">Droppii Giao hàng tiết kiệm</option>
      <option value="outside">Droppii qua kho</option>
      <option value="warehouse_viettel">Khách lẻ Viettel Post</option>
      <option value="warehouse_branch">Khách lẻ qua kho</option>
      <option value="warehouse_ghtk">Khách lẻ Giao hàng tiết kiệm</option>
      <option value="shopee">Đơn hàng Shopee KUCHEN</option>
      <option value="shopee_risoli">Đơn hàng Shopee RISOLI</option>
      <option value="tiktok">Đơn hàng Tiktok</option>
    `;
  } else {
    return `
      <option value="droppii">Droppii Viettel Post</option>
      <option value="droppii_ghtk">Droppii Giao hàng tiết kiệm</option>
      <option value="outside">Droppii qua kho</option>
      <option value="warehouse_viettel">Khách lẻ Viettel Post</option>
      <option value="warehouse_ghtk">Khách lẻ Giao hàng tiết kiệm</option>
      <option value="warehouse_branch">Khách lẻ qua kho</option>
      <option value="shopee">Đơn hàng Shopee</option>
    `;
  }
}

// ======================================================
//
// ================== RENDER CARD ĐƠN ===================
//
// ======================================================
function orderCardHtml(i) {
  return `
    <div class="col-12" id="order_${i}">
      <div class="card card-order">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h5 class="card-title m-0">ĐƠN HÀNG SỐ #${i}</h5>
            <span class="mini-muted">#${i} • ${escapeHtml(warehouseLabel)}</span>
          </div>

          <div class="row g-3 mt-0">
            <div class="col-md-4">
              <label class="form-label">Loại đơn hàng #${i}:</label>
              <select class="form-select order-mode" id="order_mode_${i}" name="orders[${i}][type]" data-order="${i}">
                ${orderModeOptionsHtml()}
              </select>
              <div class="form-text">Chọn marketplace sẽ hiển thị đơn vị vận chuyển riêng.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Mã đơn vị vận chuyển #${i} <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="order_code1_${i}" name="orders[${i}][order_code1]" maxlength="30" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Mã đơn hàng #${i} <span class="required-star">*</span></label>
              <input type="text" class="form-control order-code2" id="order_code2_${i}" name="orders[${i}][order_code2]" maxlength="50" required data-order="${i}">
              <small id="warning_${i}" class="text-danger d-none">Mã đơn hàng không hợp lệ!</small>
            </div>

            <div class="col-md-4 d-none" id="shipping_unit_group_${i}">
              <label class="form-label">Đơn vị vận chuyển #${i}:</label>
              <select class="form-select" id="shipping_unit_${i}" name="orders[${i}][shipping_unit]">
                <option value="Giao Hàng Tiết Kiệm (GHTK)">Giao Hàng Tiết Kiệm (GHTK)</option>
                <option value="Giao Hàng Nhanh (GHN)">Giao Hàng Nhanh (GHN)</option>
                <option value="Viettel Post">Viettel Post</option>
                <option value="VNPost Nhanh">VNPost Nhanh</option>
                <option value="Vietnam Post Tiết Kiệm">Vietnam Post Tiết Kiệm</option>
                <option value="SPX">SPX</option>
                <option value="SPX Instant">SPX Instant</option>
                <option value="GrabExpress">GrabExpress</option>
                <option value="beDelivery">beDelivery</option>
                <option value="Ahamove">Ahamove</option>
                <option value="Ninja Van">Ninja Van</option>
                <option value="Lazada Express (LEX)">Lazada Express (LEX)</option>
                <option value="J&T Express">J&T Express</option>
              </select>
            </div>

            <!-- SĐT KHÁCH -->
            <div class="col-md-4">
              <label class="form-label">SĐT khách đơn số #${i} <span class="required-star">*</span></label>
              <input
                type="tel"
                class="form-control phone-customer"
                id="customer_phone_${i}"
                name="orders[${i}][customer_phone]"
                maxlength="10" minlength="10" pattern="\\d{10}" inputmode="numeric"
                required data-order="${i}" placeholder="Chỉ nhập SĐT">
              <small id="phoneWarning_${i}" class="text-warning d-none"><b>Hệ thống cảnh báo: Vui lòng chỉ nhập số!</b></small>
              <small id="phoneLen_${i}" class="text-danger d-none"><b>SĐT phải đúng 10 số.</b></small>
            </div>

            <div class="col-md-4">
              <label class="form-label">Tên khách hàng đơn số #${i} <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="customer_name_${i}" name="orders[${i}][customer_name]" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Địa chỉ giao hàng đơn số #${i} <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="customer_address_${i}" name="orders[${i}][customer_address]" maxlength="100" required>
            </div>

            <!-- ĐỊA GIỚI: Tỉnh/TP → Quận/Huyện → Phường/Xã -->
            <div class="col-md-4">
              <label class="form-label">Tỉnh/Thành phố #${i} <span class="required-star">*</span></label>
              <select class="form-select select-province" id="province_${i}" name="orders[${i}][province]" data-order="${i}" required>
                <option value="">-- Chọn Tỉnh/Thành phố --</option>
              </select>
              <small class="text-danger d-none" id="province_required_${i}">Vui lòng chọn Tỉnh/Thành phố.</small>
              <div class="form-text debug-note" id="debug_province_${i}"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Quận/Huyện #${i}</label>
              <select class="form-select select-district" id="district_${i}" name="orders[${i}][district]" data-order="${i}">
                <option value="">-- Chọn Quận/Huyện --</option>
              </select>
              <div class="form-text debug-note" id="debug_district_${i}"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Phường/Xã #${i}</label>
              <select class="form-select select-wards" id="wards_${i}" name="orders[${i}][wards]" data-order="${i}">
                <option value="">-- Chọn Phường/Xã --</option>
              </select>
              <div class="form-text debug-note" id="debug_wards_${i}"></div>
            </div>

            <!-- HÀNG GỘP -->
            <div class="col-md-4">
              <label class="form-label">Tên đại lý #${i} <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="agency_name_${i}" name="orders[${i}][agency_name]" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">SĐT đại lý #${i} <span class="required-star">*</span></label>
              <input
                type="tel"
                class="form-control phone-agency"
                id="agency_phone_${i}"
                name="orders[${i}][agency_phone]"
                maxlength="10" minlength="10" pattern="\\d{10}" inputmode="numeric"
                required data-order="${i}" placeholder="Chỉ nhập SĐT">
              <small id="phoneWarning2_${i}" class="text-warning d-none"><b>Hệ thống cảnh báo: Vui lòng chỉ nhập số!</b></small>
              <small id="phoneLen2_${i}" class="text-danger d-none"><b>SĐT phải đúng 10 số.</b></small>
            </div>
            <div class="col-md-4">
              <label class="form-label">Mã khuyến mại #${i}</label>
              <select class="form-select discount-select mb-2" id="discount_code_${i}" name="orders[${i}][discount_code]" data-order="${i}">
                <option value="">Chọn mã khuyến mại</option>
                <option value="100000">100,000 VND</option>
                <option value="200000">200,000 VND</option>
                <option value="300000">300,000 VND</option>
                <option value="400000">400,000 VND</option>
                <option value="500000">500,000 VND</option>
                <option value="1000000">1,000,000 VND</option>
                <option value="other">Khác</option>
              </select>
              <input type="text" class="form-control d-none custom-discount" id="custom_discount_${i}" name="orders[${i}][custom_discount]" placeholder="Nhập số tiền giảm khác (VNĐ)" data-order="${i}">
              <small id="price_difference_hint_${i}" class="text-danger d-none"></small>
            </div>
          </div>

          <hr>
          <div class="d-flex align-items-center justify-content-between">
            <div class="section-title">II. Danh sách sản phẩm đơn hàng #${i}</div>
            <button type="button" class="btn btn-info btn-sm" data-action="add-product" data-order="${i}">
              <i class="fas fa-plus-circle me-1"></i> Thêm sản phẩm
            </button>
          </div>

          <div id="product_list_${i}" class="mt-2"></div>

          <div class="mt-3 text-center">
            <p id="total_price_${i}" class="border-dash m-0">
              Thành tiền đơn hàng #${i}: <span class="text-danger fw-bold fs-6">0 VNĐ</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  `;
}

// ======================================================
// ============== SẢN PHẨM: THÊM/XÓA + TÍNH ============
// ======================================================
function addProduct(orderNumber) {
  const list = document.getElementById(`product_list_${orderNumber}`);
  const nextIndex = list.querySelectorAll('.product-entry').length + 1;

  const html = `
    <div class="card mt-2 product-entry" data-order="${orderNumber}" data-index="${nextIndex}" id="entry_${orderNumber}_${nextIndex}">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="card-title m-0">Sản phẩm ${nextIndex}</h6>
          <div class="d-flex align-items-center gap-2">
            <a class="small text-secondary text-decoration-none" data-bs-toggle="collapse" href="#price_detail_${orderNumber}_${nextIndex}">
              <i class="fas fa-info-circle me-1"></i> Nhấn dòng này để xem chi tiết giá
            </a> ||
            <button type="button" class="btn btn-danger btn-sm" data-action="delete-product">
              <i class="fas fa-times me-1"></i> Xóa
            </button>
          </div>
        </div>

        <div class="row g-3 align-items-end">
          <div class="col-md-6">
            <label class="form-label">Tên sản phẩm <span class="required-star">*</span></label>
            <select class="form-select product-select" id="product_name_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][product_name]" required>
              <option value="">Chọn sản phẩm</option>
              ${productOptionsHtml()}
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Số lượng <span class="required-star">*</span></label>
            <input type="number" class="form-control qty-input" id="quantity_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][quantity]" min="1" max="999" value="1" required>
            <small class="text-warning d-none qty-warn">SL lớn bất thường!</small>
          </div>

          <div class="col-md-4">
            <label class="form-label">Giá sau thuế</label>
            <input type="text" class="form-control price-after" id="price_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][price]" value="0 VNĐ" readonly>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-6 d-flex align-items-center">
            <div class="form-check">
              <input class="form-check-input promo-check" type="checkbox" id="is_promotion_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][is_promotion]">
              <label class="form-check-label" for="is_promotion_${orderNumber}_${nextIndex}">
                Tick nếu là <b>khuyến mãi</b>
              </label>
            </div>
          </div>
          <div class="col-md-6 d-none" id="price_difference_group_${orderNumber}_${nextIndex}">
            <label class="form-label">Mức tiền chênh khi đổi quà (nếu có)</label>
            <input type="text" class="form-control price-diff" id="price_difference_${orderNumber}_${nextIndex}" 
                   name="orders[${orderNumber}][products][${nextIndex}][price_difference]" placeholder="Nhập số tiền (VNĐ)">
            <small class="text-danger">Nhập số bằng VNĐ, ví dụ: 1.000.000</small>
          </div>
        </div>

        <div class="collapse mt-2" id="price_detail_${orderNumber}_${nextIndex}">
          <div class="row g-3">
            <div class="col-md-4" id="original_price_group_${orderNumber}_${nextIndex}">
              <label class="form-label">Đơn giá (chưa VAT)</label>
              <input type="text" class="form-control price-original" id="original_price_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][original_price]" value="0 VNĐ" readonly>
            </div>
            <div class="col-md-4" id="vat_group_${orderNumber}_${nextIndex}">
              <label class="form-label">Thuế VAT (10%)</label>
              <input type="text" class="form-control price-vat" id="vat_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][vat]" value="0 VNĐ" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Địa chỉ giao hàng phụ (nếu có)</label>
              <input type="text" class="form-control" id="sub_address_${orderNumber}_${nextIndex}" name="orders[${orderNumber}][products][${nextIndex}][sub_address]">
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end mt-3">
          <button type="button"
                  class="btn btn-outline-info btn-sm row-add-btn d-none"
                  data-action="add-product"
                  data-order="${orderNumber}">
            <i class="fas fa-plus-circle me-1"></i> Thêm sản phẩm
          </button>
        </div>
      </div>
    </div>
  `;
  list.insertAdjacentHTML('beforeend', html);

  initSelect2DropdownsForRow(orderNumber, nextIndex);

  const card = document.querySelector(`#entry_${orderNumber}_${nextIndex}`);
  if (card) {
    card.scrollIntoView({behavior:'smooth', block:'start'});
    computeProductRow(card);
    const $sel = $(`#product_name_${orderNumber}_${nextIndex}`);
    setTimeout(()=>{ $sel.select2('open'); }, 200);
  }
  renderPerRowAddButtons(orderNumber);
}
function getActiveUnitPrice(orderNumber, selEl){
  // Xác định mode của đơn
  const modeSel = document.getElementById(`order_mode_${orderNumber}`);
  const mode = modeSel ? modeSel.value : '';

  // Ưu tiên lấy từ option dataset (nhanh, đúng theo select hiện tại)
  let price = 0;
  if (selEl && selEl.selectedOptions && selEl.selectedOptions[0]) {
    const opt = selEl.selectedOptions[0];
    if (ORDER_TYPES_USE_RETAIL.has(mode)) {
      price = Number(opt.dataset.priceRetail || 0);
    } else {
      price = Number(opt.dataset.price || 0);
    }
  }

  // Nếu option chưa có dataset (edge case), fallback từ PRODUCTS
  if (!price) {
    const pname = selEl?.value || '';
    const found = PRODUCTS.find(p => p.product_name === pname);
    if (found) {
      price = ORDER_TYPES_USE_RETAIL.has(mode) 
        ? Number(found.price_retail || 0)
        : Number(found.price || 0);
    }
  }

  // Nếu retail rỗng → fallback về price thường (tránh 0)
  if (ORDER_TYPES_USE_RETAIL.has(mode) && !price && selEl?.selectedOptions?.[0]) {
    const opt = selEl.selectedOptions[0];
    price = Number(opt.dataset.price || 0);
  }

  return Number(price || 0);
}

function initSelect2DropdownsForRow(orderNumber, rowIndex) {
  const selector = `#product_name_${orderNumber}_${rowIndex}`;
  $(selector).select2({
    placeholder: "Chọn hoặc tìm kiếm sản phẩm",
    allowClear: true,
    width: '100%',
    closeOnSelect: true,
    minimumInputLength: 0
  })
  .on('select2:open', () => {
    setTimeout(()=>{ const el = document.querySelector('.select2-search__field'); if(el) el.focus(); }, 0);
  })
  .on('select2:select', function () {
    const card = this.closest('.product-entry');
    if (card) computeProductRow(card);
  });
  dlog(DEBUG_SELECT2, '[Select2][Product] init for', selector);
}

function renderPerRowAddButtons(orderNumber){
  const list = document.getElementById(`product_list_${orderNumber}`);
  if (!list) return;
  const entries = list.querySelectorAll('.product-entry');
  const show = entries.length >= 3;
  entries.forEach(entry => {
    let btn = entry.querySelector('.row-add-btn');
    if (!btn){
      const footer = document.createElement('div');
      footer.className = 'd-flex justify-content-end mt-3';
      footer.innerHTML = `
        <button type="button"
                class="btn btn-outline-info btn-sm row-add-btn"
                data-action="add-product"
                data-order="${orderNumber}">
          <i class="fas fa-plus-circle me-1"></i> Thêm sản phẩm
        </button>`;
      entry.querySelector('.card-body').appendChild(footer);
      btn = entry.querySelector('.row-add-btn');
    }
    btn.classList.toggle('d-none', !show);
  });
}

function togglePromotionFields(card) {
  const isPromo = card.querySelector('.promo-check').checked;
  const order = Number(card.dataset.order);
  const idx = Number(card.dataset.index);

  const groupDiff = document.getElementById(`price_difference_group_${order}_${idx}`);
  const g1 = document.getElementById(`original_price_group_${order}_${idx}`);
  const g2 = document.getElementById(`vat_group_${order}_${idx}`);

  if (isPromo) { groupDiff.classList.remove('d-none'); g1.classList.add('d-none'); g2.classList.add('d-none'); }
  else { groupDiff.classList.add('d-none'); g1.classList.remove('d-none'); g2.classList.remove('d-none'); }
}

function computeProductRow(card) {
  const order = Number(card.dataset.order);
  const sel = card.querySelector('.product-select');
  const qtyInput = card.querySelector('.qty-input');
  const isPromo = card.querySelector('.promo-check').checked;

  const originalEl = card.querySelector('.price-original');
  const vatEl = card.querySelector('.price-vat');
  const afterEl = card.querySelector('.price-after');

  const qty = Math.max(1, Number(qtyInput.value || 1));

   const unitAfter = getActiveUnitPrice(order, sel);


  if (isPromo) {
    originalEl.value = formatVND(0);
    vatEl.value = formatVND(0);
    afterEl.value = formatVND(0);
  } else {
    const unitBefore = Math.round(unitAfter / 1.1);
    const totalBefore = unitBefore * qty;
    const totalAfter  = unitAfter  * qty;
    const vat = totalAfter - totalBefore;

    originalEl.value = formatVND(totalBefore);
    vatEl.value = formatVND(vat);
    afterEl.value = formatVND(totalAfter);
  }
  calculateTotal(order);
}

function calculateTotal(order) {
  const list = document.getElementById(`product_list_${order}`);
  let total = 0;
  list.querySelectorAll('.product-entry').forEach(card => {
    const isPromo = card.querySelector('.promo-check').checked;
    if (isPromo) {
      const diff = parseVND(card.querySelector('.price-diff')?.value || '0');
      total += Number(diff||0);
    } else {
      const priceAfter = parseVND(card.querySelector('.price-after')?.value || '0');
      total += Number(priceAfter||0);
    }
  });

  const sel = document.getElementById(`discount_code_${order}`);
  const custom = document.getElementById(`custom_discount_${order}`);
  let discount = 0;
  if (sel && sel.value && sel.value !== 'other') discount = Number(sel.value) || 0;
  else if (sel && sel.value === 'other' && custom) discount = parseVND(custom.value || '0');

  total = Math.max(0, total - discount);

  const label = document.getElementById(`total_price_${order}`);
  if (label) label.innerHTML = `Thành tiền đơn hàng #${order}: <span class="text-danger fw-bold fs-6">${formatVND(total)}</span>`;
}

// ========================= ĐỊA GIỚI HÀNH CHÍNH (REWRITE, FULL DEBUG) =========================
const API_PROVINCES = 'get_provinces.php';
const API_DISTRICTS = (pid) => `get_districts.php?province_id=${encodeURIComponent(pid)}`;
const API_WARDS     = (did) => `get_wards.php?district_id=${encodeURIComponent(did)}`;

const CACHE_PROVINCES = [];
const CACHE_DISTRICTS = new Map();
const CACHE_WARDS     = new Map();

const DG_DEBUG = true;

// --- helpers
const dgg = (...a)=>console.groupCollapsed(...a);
const dge = ()=>console.groupEnd();

function safeDestroySelect2(sel){
  const $el = $(sel);
  if ($el.data('select2')) {
    try { $el.off('.select2'); $el.select2('destroy'); } catch(_) {}
  }
}

function ensureSelect2(sel, cfg){
  safeDestroySelect2(sel);
  $(sel).select2(Object.assign({
    placeholder: 'Chọn hoặc tìm kiếm',
    allowClear: true,
    width: '100%',
    closeOnSelect: true,
    minimumInputLength: 0
  }, cfg));

  // debug hooks
  $(sel)
    .on('select2:open', () => { if (DG_DEBUG) console.log('[DG][open]', sel.id); })
    .on('select2:close', () => { if (DG_DEBUG) console.log('[DG][close]', sel.id); })
    .on('change',       () => { if (DG_DEBUG) console.log('[DG][change]', sel.id, '→', $(sel).val()); });
}

function fillOptionsBasic(sel, items, placeholder){
  const cur = $(sel).val();
  safeDestroySelect2(sel);
  sel.innerHTML = `<option value="">${placeholder}</option>` + (items||[]).map(x=>{
    const idRaw   = x.id ?? x.province_id ?? x.district_id ?? x.wards_id;
    const nameRaw = x.name ?? x.province_name ?? x.district_name ?? x.wards_name;
    // Nếu idRaw trống thì fallback dùng nameRaw
    const id      = (idRaw !== undefined && idRaw !== null && idRaw !== "") ? idRaw : nameRaw;
    return `<option value="${id}">${(nameRaw ?? '')}</option>`;
  }).join('');
  return cur;
}


// --- APIs
async function fetchProvincesOnce(){
  if (CACHE_PROVINCES.length) return CACHE_PROVINCES;
  dgg('%c[DG] fetch PROVINCES', 'color:#0b7285');
  const r = await fetch(API_PROVINCES, {credentials:'same-origin'});
  const j = await r.json();
  console.log('success=', j?.success, 'items=', j?.data?.length);
  dge();
  if (j?.success && Array.isArray(j.data)) CACHE_PROVINCES.push(...j.data);
  return CACHE_PROVINCES;
}
async function fetchDistrictsByProvince(pid){
  if (CACHE_DISTRICTS.has(pid)) return CACHE_DISTRICTS.get(pid);
  dgg('%c[DG] fetch DISTRICTS', 'color:#0b7285', 'pid=', pid, API_DISTRICTS(pid));
  const r = await fetch(API_DISTRICTS(pid), {credentials:'same-origin'});
  const j = await r.json();
  console.log('success=', j?.success, 'items=', j?.data?.length);
  dge();
  const data = (j?.success && Array.isArray(j.data)) ? j.data : [];
  CACHE_DISTRICTS.set(pid, data);
  return data;
}
async function fetchWardsByDistrict(did){
  if (CACHE_WARDS.has(did)) return CACHE_WARDS.get(did);
  dgg('%c[DG] fetch WARDS', 'color:#0b7285', 'did=', did, API_WARDS(did));
  const r = await fetch(API_WARDS(did), {credentials:'same-origin'});
  const j = await r.json();
  console.log('success=', j?.success, 'items=', j?.data?.length);
  dge();
  const data = (j?.success && Array.isArray(j.data)) ? j.data : [];
  CACHE_WARDS.set(did, data);
  return data;
}

// --- Wiring cho 1 đơn (order)
async function initProvinceSelect(order, prePid=0, preDid='', preWid=''){
  const selP = document.getElementById(`province_${order}`);
  const selD = document.getElementById(`district_${order}`);
  const selW = document.getElementById(`wards_${order}`);
  if (!selP || !selD || !selW) return;

  console.log('[DG][init] order=', order, 'pre=', {prePid, preDid, preWid});

  // 1) nạp tỉnh
  const provinces = await fetchProvincesOnce();
  fillOptionsBasic(selP, provinces, '-- Chọn Tỉnh/Thành phố --');
  ensureSelect2(selP, { placeholder: '-- Chọn Tỉnh/Thành phố --' });

  // 2) init district/wards (trống)
  fillOptionsBasic(selD, [], '-- Chọn Quận/Huyện --');
  ensureSelect2(selD, {
    placeholder: '-- Quận/Huyện (không bắt buộc) --',
    tags: true,
    createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
  });

  fillOptionsBasic(selW, [], '-- Chọn Phường/Xã --');
  ensureSelect2(selW, {
    placeholder: '-- Phường/Xã (không bắt buộc) --',
    tags: true,
    createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
  });

  // --- one handler for each level (bind cả native & select2)
  let lastPid = null;
  async function onProvinceChanged(){
    const raw = $(selP).val();
    const pid = Number(raw||0);
    const text = selP.options[selP.selectedIndex]?.text || '';
    dgg('%c[DG][Province change]', 'color:#845ef7', 'raw=', raw, 'pid=', pid, 'text=', text);
    console.log('→ call districts:', API_DISTRICTS(pid));
    dge();

    if (!pid || pid === lastPid){
      // reset dưới nếu xóa chọn
      fillOptionsBasic(selD, [], '-- Chọn Quận/Huyện --'); ensureSelect2(selD, { placeholder: '-- Quận/Huyện (không bắt buộc) --', tags:true, createTag: p=>{const t=(p.term||'').trim();return t?{id:t,text:t,newTag:true}:null;} });
      fillOptionsBasic(selW, [], '-- Chọn Phường/Xã --'); ensureSelect2(selW, { placeholder: '-- Phường/Xã (không bắt buộc) --', tags:true, createTag: p=>{const t=(p.term||'').trim();return t?{id:t,text:t,newTag:true}:null;} });
      lastPid = pid;
      return;
    }
    lastPid = pid;

    const dists = await fetchDistrictsByProvince(pid);
    fillOptionsBasic(selD, dists, '-- Chọn Quận/Huyện --');
    ensureSelect2(selD, {
      placeholder: '-- Quận/Huyện (không bắt buộc) --',
      tags: true,
      createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
    });
    // open ngay để user thấy có data
    $(selD).select2('open');

    // reset wards
    fillOptionsBasic(selW, [], '-- Chọn Phường/Xã --');
    ensureSelect2(selW, {
      placeholder: '-- Phường/Xã (không bắt buộc) --',
      tags: true,
      createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
    });
  }

  let lastDid = null;
  async function onDistrictChanged(){
    const did = $(selD).val(); // id hoặc free-text
    const text = selD.options[selD.selectedIndex]?.text || did || '';
    dgg('%c[DG][District change]', 'color:#2b8a3e', 'did=', did, 'text=', text);
    dge();

    // chỉ fetch wards nếu did là số
    fillOptionsBasic(selW, [], '-- Chọn Phường/Xã --');
    ensureSelect2(selW, {
      placeholder: '-- Phường/Xã (không bắt buộc) --',
      tags: true,
      createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
    });

    if (!did || !/^\d+$/.test(String(did)) || did === lastDid) { lastDid = did; return; }
    lastDid = did;

    const wards = await fetchWardsByDistrict(Number(did));
    fillOptionsBasic(selW, wards, '-- Chọn Phường/Xã --');
    ensureSelect2(selW, {
      placeholder: '-- Phường/Xã (không bắt buộc) --',
      tags: true,
      createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
    });
    $(selW).select2('open');
  }

  function onWardsChanged(){
    const wid = $(selW).val();
    const text = selW.options[selW.selectedIndex]?.text || wid || '';
    dgg('%c[DG][Wards change]', 'color:#e67700', 'wid=', wid, 'text=', text); dge();
  }

  // bind an toàn (native + jQuery)
  selP.addEventListener('change', onProvinceChanged);
  $(selP).on('select2:select select2:clear', onProvinceChanged);

  selD.addEventListener('change', onDistrictChanged);
  $(selD).on('select2:select select2:clear', onDistrictChanged);

  selW.addEventListener('change', onWardsChanged);
  $(selW).on('select2:select select2:clear', onWardsChanged);

  // --- Preselect (sửa đơn / autofill)
  if (prePid) {
    $(selP).val(String(prePid)).trigger('change'); // sẽ kích hoạt onProvinceChanged
    // chờ districts về rồi set district/ward
    try {
      const dists = await fetchDistrictsByProvince(prePid);
      fillOptionsBasic(selD, dists, '-- Chọn Quận/Huyện --');
      ensureSelect2(selD, {
        placeholder: '-- Quận/Huyện (không bắt buộc) --',
        tags: true,
        createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
      });

      if (preDid) {
        if (![...selD.options].some(o => String(o.value) === String(preDid))) {
          selD.add(new Option(preDid, preDid, true, true)); // free-text
        }
        $(selD).val(String(preDid)).trigger('change');

        if (/^\d+$/.test(String(preDid))) {
          const wards = await fetchWardsByDistrict(Number(preDid));
          fillOptionsBasic(selW, wards, '-- Chọn Phường/Xã --');
          ensureSelect2(selW, {
            placeholder: '-- Phường/Xã (không bắt buộc) --',
            tags: true,
            createTag: (p)=>{ const t=(p.term||'').trim(); return t?{id:t,text:t,newTag:true}:null; }
          });
        }

        if (preWid) {
          if (![...selW.options].some(o => String(o.value) === String(preWid))) {
            selW.add(new Option(preWid, preWid, true, true)); // free-text
          }
          $(selW).val(String(preWid)).trigger('change');
        }
      }
    } catch(err){ console.error('[DG][preselect] error', err); }
  }
}
window.initProvinceSelect = initProvinceSelect;
// ==================== AUTO-FILL THEO ĐỊA CHỈ (TrackAsia) ====================
const API_TRACKASIA = "https://api.trackasia.io/vn/address/suggest?q="; 
// Bạn thay link API thực tế của mình

function debounce(fn, delay=600){
  let t=null;
  return (...args)=>{
    clearTimeout(t);
    t=setTimeout(()=>fn(...args), delay);
  };
}

// Chuẩn hoá tên tỉnh (giữ nguyên bản gốc, chỉ bỏ tiền tố)
function normalizeProvinceName(name){
  return (name||"")
    .replace(/^tỉnh\s+/i, "")
    .replace(/^thành phố\s+/i, "")
    .replace(/^tp\.\s*/i, "")
    .replace(/\s+/g," ")
    .trim();
}
function normalizeDistrictName(name){
  return (name||"")
    .replace(/^quận\s+/i, "")
    .replace(/^huyện\s+/i, "")
    .replace(/^thành phố\s+/i, "")
    .replace(/^tp\.\s*/i, "")
    .replace(/\s+/g," ")
    .trim();
}
function normalizeWardName(name){
  return (name||"")
    .replace(/^phường\s+/i, "")
    .replace(/^xã\s+/i, "")
    .replace(/^thị trấn\s+/i, "")
    .replace(/\s+/g," ")
    .trim();
}
async function fetchAddressSuggest(query){
  try{
    const url = new URL("https://maps.track-asia.com/api/v1/search");
    url.searchParams.set("lang","vi");
    url.searchParams.set("text",query);
    url.searchParams.set("focus.point.lat","10.761");   // có thể tuỳ chỉnh
    url.searchParams.set("focus.point.lon","106.68");
    url.searchParams.set("size","5");
    url.searchParams.set("key","9b50c30f1bd1758264316ca9cfdfe8b416");           // thay bằng key thật

    const r = await fetch(url.toString());
    const j = await r.json();
    if (j && j.features && j.features.length>0){
      // Lấy kết quả đầu tiên
      const f = j.features[0];
      const ctx = f.properties || {};
      return {
        region: ctx.region || "",
        county: ctx.county || "",
        locality: ctx.locality || ""
      };
    }
  }catch(e){ console.error("[TrackAsia] error", e); }
  return null;
}
async function attachAutoAddress(order) {
  const input = document.getElementById(`customer_address_${order}`);
  if (!input) return;

  input.addEventListener("input", debounce(async () => {
    const val = input.value.trim();
    if (val.length < 5) return;

    console.log("[AutoAddress] gọi API với:", val);
    const props = await fetchAddressSuggest(val);
    if (!props) return;

    const provinceNameRaw  = props.region   || "";
    const districtNameRaw  = props.county   || "";
    const wardNameRaw      = props.locality || "";
    const provinceNameNorm = normalizeProvinceName(provinceNameRaw);

    console.log("[AutoAddress] gợi ý:", { provinceNameNorm, districtNameRaw, wardNameRaw });

    // --- Map Province
    const provinces = await fetchProvincesOnce();
    const foundProvince = provinces.find(p =>
      normalizeProvinceName(p.name || p.province_name) === provinceNameNorm
    );
    if (!foundProvince) {
      console.warn("[AutoAddress] Không tìm thấy provinceId cho:", provinceNameRaw);
      return;
    }

    const pid = Number(foundProvince.province_id || foundProvince.id);
    console.log("[AutoAddress] Tìm thấy provinceId:", pid, foundProvince);

    await window.initProvinceSelect(order, pid);

    // --- Map District
    const districts = await fetchDistrictsByProvince(pid);
    console.groupCollapsed(`[AutoAddress][DEBUG] Danh sách districts cho provinceId=${pid}`);
    console.table(districts.map(d => ({
      id: d.district_id || d.id || d.name,
      name: d.name || d.district_name
    })));
    console.groupEnd();

    const districtFound = districts.find(d =>
      normalizeDistrictName(d.name || d.district_name) === normalizeDistrictName(districtNameRaw)
    );

    const selD = document.getElementById(`district_${order}`);
    if (districtFound) {
      const did = districtFound.district_id || districtFound.id || districtFound.name;
      if (![...selD.options].some(o => String(o.value) === String(did))) {
        selD.add(new Option(districtFound.name || districtFound.district_name, did, true, true));
      }
      $(selD).val(String(did)).trigger("change");
      console.log("[AutoAddress] Match district:", districtFound);

      // --- Map Ward
      if (/^\d+$/.test(String(did))) {
        const wards = await fetchWardsByDistrict(Number(did));
        console.groupCollapsed(`[AutoAddress][DEBUG] Danh sách wards cho districtId=${did}`);
        console.table(wards.map(w => ({
          id: w.wards_id || w.id || w.name,
          name: w.name || w.wards_name
        })));
        console.groupEnd();

        const wardFound = wards.find(w =>
          normalizeWardName(w.name || w.wards_name) === normalizeWardName(wardNameRaw)
        );

        const selW = document.getElementById(`wards_${order}`);
        if (wardFound) {
          const wid = wardFound.wards_id || wardFound.id || wardFound.name;
          if (![...selW.options].some(o => String(o.value) === String(wid))) {
            selW.add(new Option(wardFound.name || wardFound.wards_name, wid, true, true));
          }
          $(selW).val(String(wid)).trigger("change");
          console.log("[AutoAddress] Match ward:", wardFound);
        } else if (wardNameRaw) {
          console.warn("[AutoAddress] Không tìm thấy wardId cho:", wardNameRaw);
          selW.add(new Option(wardNameRaw, wardNameRaw, true, true));
          $(selW).val(String(wardNameRaw)).trigger("change");
        }
      }
    } else {
      console.warn("[AutoAddress] Không tìm thấy districtId cho:", districtNameRaw);
      if (districtNameRaw) {
        selD.add(new Option(districtNameRaw, districtNameRaw, true, true));
        $(selD).val(String(districtNameRaw)).trigger("change");
      }
    }
  }, 600));
}

// ======================================================
// ======= AUTO-FILL THEO SĐT (ƯU TIÊN API NỘI BỘ) ======
// ======================================================
function applyCustomerDataToForm(order, c) {
  dlog(DEBUG_AUTOFILL, '[applyCustomerDataToForm] order=', order, 'payload=', c);
  document.getElementById(`customer_name_${order}`).value    = c.customer_name    || '';
  document.getElementById(`customer_address_${order}`).value = c.customer_address || '';
  document.getElementById(`agency_name_${order}`).value      = c.agency_name      || '';
  document.getElementById(`agency_phone_${order}`).value     = c.agency_phone     || '';

  const pid = Number(c.province_id || 0);
  const did = c.district_id ?? '';
  const wid = c.wards_id ?? '';
  if (pid || did || wid) {
    dlog(DEBUG_AUTOFILL, '[applyCustomerDataToForm] initProvinceSelect with', {pid, did, wid});
    window.initProvinceSelect(order, pid, did, wid);
  }
}
// Map để giữ AbortController theo từng input (tránh chạy song song)
const PHONE_LOOKUP_CTRL = new Map();

function handleCustomerPhoneAutoFill(order) {
  const input = document.getElementById(`customer_phone_${order}`);
  const formGroup = input.closest('.col-md-4') || input.parentElement;

  // Helper: xoá TẤT CẢ các cảnh báo cũ trong formGroup
  const clearErrorMsgs = () => {
    const olds = formGroup.querySelectorAll('.error-message');
    olds.forEach(n => n.remove());
  };

  input.addEventListener('blur', async () => {
    const phone = (input.value || '').trim();

    // dọn mọi cảnh báo cũ mỗi lần blur
    clearErrorMsgs();
    if (!phone) return;

    // Nếu đã có request đang chạy cho input này → huỷ nó
    const prev = PHONE_LOOKUP_CTRL.get(input);
    if (prev) {
      try { prev.abort(); } catch(_) {}
      PHONE_LOOKUP_CTRL.delete(input);
    }

    // Tạo controller mới cho lượt này
    const ctrl = new AbortController();
    PHONE_LOOKUP_CTRL.set(input, ctrl);

    // Flag đang tra cứu để tránh double-append
    input.dataset.fetching = '1';

    try {
      // 1) ƯU TIÊN API nội bộ
      let ok = false, payload = null;

      try {
        const r1 = await fetch(API_FIND_BY_PHONE_LOCAL + encodeURIComponent(phone), {
          credentials:'same-origin',
          signal: ctrl.signal
        });
        const j1 = await r1.json();
        if (j1 && j1.success && j1.data) {
          ok = true; payload = j1.data;
        }
      } catch (err) {
        // Nếu bị abort → thoát êm
        if (err?.name === 'AbortError') return;
        // Không append lỗi ở đây; tiếp tục fallback
        console.warn('[AutoFill local] error:', err);
      }

      // 2) Nếu nội bộ không ra → fallback API ngoài
      if (!ok) {
        try {
          const r2 = await fetch(API_CUSTOMER_BY_PHONE + encodeURIComponent(phone), { signal: ctrl.signal });
          const j2 = await r2.json();
          if (j2 && j2.success && j2.data) {
            ok = true; payload = {
              customer_name:    j2.data.customer_name,
              customer_address: j2.data.customer_address,
              agency_name:      j2.data.agency_name,
              agency_phone:     j2.data.agency_phone,
              province_id:      j2.data.province_id || 0,
              district_id:      j2.data.district_id || '',
              wards_id:         j2.data.wards_id    || ''
            };
          }
        } catch (err) {
          if (err?.name === 'AbortError') return;
          console.warn('[AutoFill external] error:', err);
        }
      }

      // 3) Quyết định hiển thị
      if (ok && payload) {
        applyCustomerDataToForm(order, payload);
      } else {
        // Trước khi append, đảm bảo KHÔNG có sẵn error-message (dè chừng event race)
        clearErrorMsgs();
        const msg = document.createElement('div');
        msg.className = 'error-message text-danger';
        msg.textContent = 'SĐT này chưa có trong hệ thống, vui lòng nhập tiếp các dòng còn lại.';
        formGroup.appendChild(msg);
      }

    } finally {
      // Kết thúc lượt → gỡ controller & flag
      PHONE_LOOKUP_CTRL.delete(input);
      delete input.dataset.fetching;
    }
  });
}


// ======================================================
// ====================== UI HELPERS ====================
// ======================================================
function isVisible(el) { return !!(el && el.offsetParent !== null); }
function validatePhone10(inputEl, lenWarnId) {
  const v = (inputEl.value || '').trim();
  const is10 = /^\d{10}$/.test(v);
  const lenWarn = document.getElementById(lenWarnId);
  if (lenWarn) lenWarn.classList.toggle('d-none', is10);
  inputEl.classList.toggle('is-invalid', !is10);
  return is10;
}

// ======================================================
//
// ==================== INIT TRANG ======================
//
// ======================================================
document.addEventListener('DOMContentLoaded', async () => {
  dlog(DEBUG_ADMIN, '=== DOMContentLoaded ===');
  const countSel = document.getElementById('order_count');
  countSel.disabled = true;
  await fetchProducts();
  countSel.disabled = false;

  if (!countSel.value) countSel.value = '1';
  await generateOrderForms();
  countSel.addEventListener('change', async () => { await generateOrderForms(); });

  const form = document.getElementById('orderForm');

  form.addEventListener('change', async (e) => {
    if (e.target.matches('.order-mode')) {
      const order = Number(e.target.dataset.order);
      await handleOrderModeChange(order);
    }
    if (e.target.matches('.discount-select')) {
      const order = Number(e.target.dataset.order);
      const sel = e.target;
      const custom = document.getElementById(`custom_discount_${order}`);
      const hint = document.getElementById(`price_difference_hint_${order}`);
      if (sel.value === 'other') {
        custom.classList.remove('d-none'); custom.required = true; custom.value = ''; hint.classList.remove('d-none');
      } else {
        custom.classList.add('d-none'); custom.required = false; custom.value = ''; hint.classList.add('d-none');
      }
      calculateTotal(order);
    }
    if (e.target.matches('.product-select')) {
      const card = e.target.closest('.product-entry');
      if (card) computeProductRow(card);
    }
    if (e.target.matches('.promo-check')) {
      const card = e.target.closest('.product-entry');
      togglePromotionFields(card);
      computeProductRow(card);
    }
  });

  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-action="add-product"]')) {
      const order = Number(e.target.closest('[data-action="add-product"]').dataset.order);
      addProduct(order);
    }
    if (e.target.closest('[data-action="delete-product"]')) {
      const card = e.target.closest('.product-entry');
      const order = Number(card.dataset.order);
      card.remove();
      calculateTotal(order);
      renderPerRowAddButtons(order);
    }
  });

  form.addEventListener('blur', async (e) => {
    if (e.target.matches('.order-code2')) {
      const order = Number(e.target.dataset.order);
      const val = e.target.value.trim();
      const warn = document.getElementById(`warning_${order}`);
      if (!val) return;
      await ensureUniqueOrderCode2(e.target);
      const ok = validateOrderCode2Syntax(e.target.value.trim());
      warn.classList.toggle('d-none', ok);
      e.target.style.borderColor = ok ? '' : 'red';
    }
  }, true);

  // Kiểm tra độ dài khi blur
  form.addEventListener('focusout', (e) => {
  if (e.target.matches('.phone-customer')) {
    const order = Number(e.target.dataset.order);
    validatePhone10(e.target, `phoneLen_${order}`);
  }
  if (e.target.matches('.phone-agency')) {
    const order = Number(e.target.dataset.order);
    validatePhone10(e.target, `phoneLen2_${order}`);
  }
});

  // Submit guard
  form.addEventListener('submit', (e) => {
    let ok = true;
    const allPhones = form.querySelectorAll('.phone-customer, .phone-agency');
    for (const input of allPhones) {
      if (!isVisible(input)) continue;
      const order = Number(input.dataset.order);
      const lenWarnId = input.classList.contains('phone-customer') ? `phoneLen_${order}` : `phoneLen2_${order}`;
      const valid = validatePhone10(input, lenWarnId);
      if (!valid) {
        ok = false;
        input.focus();
        input.scrollIntoView({behavior:'smooth', block:'center'});
        break;
      }
    }

    // Bắt buộc chọn Tỉnh/TP
    if (ok) {
      const orderCards = form.querySelectorAll('[id^="order_"]');
      for (const card of orderCards) {
        const orderNo = Number(card.id.split('_')[1] || 0);
        const provinceSel = document.getElementById(`province_${orderNo}`);
        const provinceReqHint = document.getElementById(`province_required_${orderNo}`);
        if (provinceSel && provinceSel.offsetParent !== null) {
          const hasProvince = String(provinceSel.value || '') !== '';
          if (!hasProvince) {
            ok = false;
            if (provinceReqHint) provinceReqHint.classList.remove('d-none');
            provinceSel.scrollIntoView({behavior:'smooth', block:'center'});
            $(provinceSel).select2('open');
            break;
          } else {
            if (provinceReqHint) provinceReqHint.classList.add('d-none');
          }
        }
      }
    }
    if (!ok) e.preventDefault();
  });

  // Input guards
  form.addEventListener('input', (e) => {
    if (e.target.matches('.phone-customer, .phone-agency')) {
      const order = Number(e.target.dataset.order);
      const clean = e.target.value.replace(/\D/g,'');
      if (clean !== e.target.value) {
        e.target.value = clean;
        const w1 = document.getElementById(`phoneWarning_${order}`);
        const w2 = document.getElementById(`phoneWarning2_${order}`);
        if (e.target.classList.contains('phone-customer') && w1) w1.classList.remove('d-none');
        if (e.target.classList.contains('phone-agency') && w2) w2.classList.remove('d-none');
      } else {
        const w1 = document.getElementById(`phoneWarning_${order}`);
        const w2 = document.getElementById(`phoneWarning2_${order}`);
        if (w1) w1.classList.add('d-none');
        if (w2) w2.classList.add('d-none');
      }
    }
    if (e.target.matches('.qty-input')) {
      const card = e.target.closest('.product-entry');
      const qty = Number(e.target.value||1);
      e.target.value = Math.max(1, qty);
      const warn = card.querySelector('.qty-warn');
      if (warn) warn.classList.toggle('d-none', !(Number(e.target.value)>20));
      computeProductRow(card);
    }
    if (e.target.matches('.price-diff')) {
      const v = parseVND(e.target.value);
      e.target.value = formatNumber(v);
      const card = e.target.closest('.product-entry');
      computeProductRow(card);
    }
    if (e.target.matches('.custom-discount')) {
      const order = Number(e.target.dataset.order);
      const v = parseVND(e.target.value);
      e.target.value = formatNumber(v);
      const hint = document.getElementById(`price_difference_hint_${order}`);
      if (hint) hint.textContent = `Bạn đang nhập số tiền ${formatNumber(v)} VNĐ`;
      calculateTotal(order);
    }
  });
});

// ======================================================
// ============ TẠO FORM + MODE ĐƠN HÀNG =================
// ======================================================
async function handleOrderModeChange(order) {
  const modeSel = document.getElementById(`order_mode_${order}`);
  const mode = modeSel.value;
  const oc1 = document.getElementById(`order_code1_${order}`);
  const oc2 = document.getElementById(`order_code2_${order}`);
  const shipGroup = document.getElementById(`shipping_unit_group_${order}`);

  const agencyNameEl  = document.getElementById(`agency_name_${order}`);
  const agencyPhoneEl = document.getElementById(`agency_phone_${order}`);
  const custPhoneEl   = document.getElementById(`customer_phone_${order}`);

  const agencyNameGroup  = agencyNameEl  ? agencyNameEl.closest('[class*="col-"]') : null;
  const agencyPhoneGroup = agencyPhoneEl ? agencyPhoneEl.closest('[class*="col-"]') : null;
  const custPhoneGroup   = custPhoneEl   ? custPhoneEl.closest('[class*="col-"]')   : null;

  oc1.readOnly = false; oc2.readOnly = false; oc1.value = '';
  if (!ORDER_TYPES_AUTO_CODE.has(mode)) oc2.value = '';

  shipGroup.classList.toggle('d-none', !ORDER_TYPES_WITH_SHIP_UNIT.has(mode));

  const hideAgency = ['lazada','shopee','tiktok','shopee_risoli'].includes(mode);
  [agencyNameGroup, agencyPhoneGroup, custPhoneGroup].forEach(g => g && g.classList.toggle('d-none', hideAgency));

  if (agencyNameEl)  agencyNameEl.required  = !hideAgency;
  if (agencyPhoneEl) agencyPhoneEl.required = !hideAgency;
  if (custPhoneEl)   custPhoneEl.required   = !hideAgency;

  if (mode === 'droppii') {
    oc1.placeholder = "Nhập mã đơn vị vận chuyển";
    oc2.placeholder = "Nhập mã đơn hàng";
  } else if (mode === 'outside') {
    oc1.value = "Khách nhận hàng tại kho Droppii";
    oc1.readOnly = true;
    oc2.placeholder = "Nhập mã đơn hàng";
  } else if (ORDER_TYPES_AUTO_CODE.has(mode)) {
    oc1.placeholder = "Nhập mã đơn vị vận chuyển";
    oc2.value = await generateUniqueOrderCode();
    oc2.readOnly = true;
  } else if (mode === 'warehouse_branch') {
    oc1.value = warehouseLabel;
    oc1.readOnly = true;
    oc2.value = await generateUniqueOrderCode();
    oc2.readOnly = true;
  } else {
    oc1.placeholder = "Nhập mã đơn vị vận chuyển";
    oc2.placeholder = "Nhập mã đơn hàng";
  }
    // --- Sau khi đổi mode: tính lại toàn bộ dòng sản phẩm của đơn này
  const list = document.getElementById(`product_list_${order}`);
  if (list) {
    list.querySelectorAll('.product-entry').forEach(card => computeProductRow(card));
    calculateTotal(order);
  }

}

async function generateOrderForms() {
  const count = Number(document.getElementById('order_count').value || 0);
  const container = document.getElementById('order_forms');
  container.innerHTML = '';
  for (let i = 1; i <= count; i++) {
    container.insertAdjacentHTML('beforeend', orderCardHtml(i));
  }
  for (let i = 1; i <= count; i++) {
    handleCustomerPhoneAutoFill(i);
    attachAutoAddress(i);
    await handleOrderModeChange(i);
    await window.initProvinceSelect(i);  // Tải danh sách tỉnh cho đơn #i + gắn debug
    renderPerRowAddButtons(i);
  }
}

// ======================================================
// ================== TOUR (giữ nguyên) =================
// ======================================================
(() => {
  class UxTour {
    constructor(steps){
      this.steps = steps || [];
      this.idx = -1; this.pop = null;
      this.backdrop = document.getElementById('tour-backdrop');
      document.addEventListener('click', (e)=>{
        if (e.target.closest('.tour-next')) this.next();
        if (e.target.closest('.tour-prev')) this.prev();
        if (e.target.closest('.tour-end'))  this.end();
      });
      document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') this.end(); });
    }
    async start(){ if (!this.steps.length) return; this.backdrop.style.display = 'block'; this.idx = -1; await this.next(); }
    async next(){ await this.show(this.idx + 1); }
    async prev(){ await this.show(this.idx - 1); }
    async end(){ this._cleanup(); this.idx = -1; if (this.backdrop) this.backdrop.style.display = 'none'; }
    _cleanup(){
      document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
      if (this.pop){ try { this.pop.dispose(); } catch(e){} this.pop = null; }
    }
    async show(i){
      this._cleanup();
      if (i < 0){ i = 0; }
      if (i >= this.steps.length){ this.end(); return; }
      const step = this.steps[i];
      if (typeof step.prepare === 'function'){ await step.prepare(); }
      let el = step.el;
      if (typeof el === 'string') el = document.querySelector(el);
      if (!el){ await this.show(i + (this.idx < i ? 1 : -1)); return; }
      el.scrollIntoView({behavior:'smooth', block:'center', inline:'nearest'});
      el.classList.add('tour-highlight');
      const total = this.steps.length;
      const isFirst = (i === 0);
      const isLast  = (i === total - 1);
      const bodyHtml = `
        <div style="max-width: 280px;">
          <div class="mb-2">${step.content || ''}</div>
          <div class="d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-sm btn-light border tour-end">Đóng</button>
            <div class="d-flex gap-1">
              <button type="button" class="btn btn-sm btn-outline-secondary tour-prev" ${isFirst?'disabled':''}>Trước</button>
              <button type="button" class="btn btn-sm btn-primary tour-next">${isLast?'Xong':'Tiếp'}</button>
            </div>
          </div>
        </div>`;
      this.pop = new bootstrap.Popover(el, {
        title: step.title || 'Hướng dẫn',
        content: bodyHtml, html: true, trigger: 'manual',
        placement: step.placement || 'auto', container: 'body', sanitize: false
      });
      this.pop.show(); this.idx = i;
    }
  }

  async function ensureOrder1AndFirstProduct(){
    const orderForms = document.getElementById('order_forms');
    if (!orderForms.querySelector('#order_1')) {
      const countSel = document.getElementById('order_count');
      countSel.value = '1';
      await generateOrderForms();
    }
    const list = document.getElementById('product_list_1');
    if (list && !list.querySelector('.product-entry')) {
      addProduct(1);
      await new Promise(r => setTimeout(r, 200));
    }
  }
  function openPriceDetail(){
    const c = document.getElementById('price_detail_1_1');
    if (!c) return;
    const bsCol = bootstrap.Collapse.getOrCreateInstance(c, {toggle:false});
    bsCol.show();
  }

  const steps = [
    { el: '#order_count',   title: 'Chọn số đơn', content: 'Chọn nhanh số lượng đơn cần tạo (mặc định 1).', placement: 'bottom' },
    { el: '#order_mode_1',  title: 'Loại đơn hàng', content: 'Chọn kênh/loại đơn. Một số loại sẽ bật ô đơn vị vận chuyển riêng.', placement: 'bottom', prepare: ensureOrder1AndFirstProduct },
    { el: '#order_code1_1', title: 'Mã đơn vị vận chuyển', content: 'Nhập đúng mã ĐVVC (GHTK/Viettel Post/...).', placement: 'bottom' },
    { el: '#order_code2_1', title: 'Mã đơn hàng', content: 'Hệ thống tự kiểm tra trùng và gợi ý mã mới nếu cần.', placement: 'bottom' },
    { el: '#customer_phone_1', title: 'SĐT khách hàng', content: 'Nhập đủ 10 số. Hệ thống tự điền tên/địa chỉ/địa giới nếu đã có.', placement: 'bottom' },
    { el: '#discount_code_1', title: 'Mã khuyến mại', content: 'Chọn nhanh hoặc nhập “Khác” để gõ số tiền giảm.', placement: 'bottom' },
    { el: 'button[data-action="add-product"][data-order="1"]', title: 'Thêm sản phẩm', content: 'Nhấn để thêm sản phẩm vào đơn.', placement: 'left', prepare: ensureOrder1AndFirstProduct },
    { el: '#product_name_1_1', title: 'Chọn sản phẩm', content: 'Giá đã gồm VAT (Giá sau thuế).', placement: 'bottom', prepare: ensureOrder1AndFirstProduct },
    { el: '#quantity_1_1', title: 'Số lượng', content: 'Nhập số lượng; có cảnh báo nếu lớn bất thường.', placement: 'bottom' },
    { el: '#price_1_1', title: 'Giá sau thuế', content: 'Tự động tính; mở “chi tiết giá” để xem VAT.', placement: 'bottom', prepare: () => { ensureOrder1AndFirstProduct(); openPriceDetail(); } },
    { el: '.fab-submit .fab-btn', title: 'Gửi đơn để quét QR', content: 'Nhấn để chuyển sang bước quét QR bảo hành.', placement: 'left' }
  ];

  let tourInstance = null;
  document.getElementById('btnTour')?.addEventListener('click', async () => {
    if (!document.querySelector('#order_1')) {
      const countSel = document.getElementById('order_count');
      if (!countSel.value) countSel.value = '1';
      await generateOrderForms();
      await new Promise(r => setTimeout(r, 100));
    }
    if (!tourInstance) tourInstance = new UxTour(steps);
    tourInstance.start();
  });
})();
</script>
<div id="tour-backdrop"></div>
</body>
</html>
