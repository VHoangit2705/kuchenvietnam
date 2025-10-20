<?php
// ===== BẢO MẬT & KHỞI TẠO PHIÊN =====
include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include 'config.php';

// Bật chế độ báo lỗi dạng exception an toàn hơn
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
// ===== THÔNG BÁO HỆ THỐNG (CẤU HÌNH) =====
function renderSystemNoticeHtml(string $text): string {
  $text = str_replace(["\r\n","\r"], "\n", $text);
  $lines = explode("\n", $text);
  $bullets = []; $paras = [];
  foreach ($lines as $line) {
    $trim = trim($line); if ($trim==='') continue;
    if (strpos($trim,'- ')===0 || strpos($trim,"-\t")===0) {
      $content = ltrim(substr($trim,1)); $content = ltrim($content);
      $isNew = false;
      if (stripos($content,'[NEW]')===0) { $isNew = true; $content = ltrim(substr($content,5)); }
      $safe = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
      $bullets[] = $isNew
        ? '<li><span class="badge-new">NEW</span> <span>'.$safe.'</span></li>'
        : '<li>'.$safe.'</li>';
    } else {
      $paras[] = '<p class="mb-2">'.htmlspecialchars($trim, ENT_QUOTES, 'UTF-8').'</p>';
    }
  }
  $html = '';
  if (!empty($paras))   $html .= implode("\n",$paras);
  if (!empty($bullets)) $html .= '<ul class="mt-2 mb-0">'.implode("\n",$bullets).'</ul>';
  return $html ?: '<p class="text-secondary mb-0">(Không có nội dung)</p>';
}
// Thay đổi 2 biến này theo nhu cầu. Đổi $systemNoticeId mỗi khi cập nhật nội dung để ép hiện lại.
$systemNoticeId    = 'notice-2025-09-16-01';
$systemNoticeTitle = 'Thông báo hệ thống';
$systemNotice      = 'Bản tin hôm nay 16/09/2025:
- [NEW] Đề nghị các đơn không thể quét bảo hành do tem lỗi hoặc cần bàn giao đơn cho khách hàng gấp đề nghị chụp báo cáo nhóm zalo để được xử lý. Trân trọng thông báo !
- Vui lòng đọc kỹ hướng dẫn để đảm bảo dữ liệu chính xác. Xin cảm ơn!';

// Ảnh bìa hệ thống (có thể là URL tuyệt đối hoặc đường dẫn tương đối trong dự án)
$systemCoverUrl = 'images/424992df-5966-497b-badc-69f678ccbac0.png'; // ví dụ: /assets/system-cover.jpg
$systemCoverAlt = 'KÜCHEN • Cập nhật hệ thống';


// ===== THAM SỐ NGƯỜI DÙNG =====
$userPosition = $_SESSION['position'] ?? null;
$searchQuery  = trim($_GET['search'] ?? "");

// Map position -> zone hiển thị trong DB
$zoneMap = [
    "Kho hàng Vinh"  => "Đơn hàng Vinh",
    "Kho hàng HaNoi" => "Đơn hàng HaNoi",
    "Kho hàng HCM"   => "Đơn hàng HCM"
];

// ===== XÂY DỰNG BỘ LỌC =====
$filters = [];
$params  = [];
$types   = "";

// Chỉ lấy đơn cần quét hoặc đang quét
$filters[] = "(o.status IN ('Đang chờ quét QR','Đang quét QR'))";

// Lọc theo zone từ session nếu có
if (isset($zoneMap[$userPosition])) {
    $filters[] = "o.zone = ?";
    $params[]  = $zoneMap[$userPosition];
    $types    .= "s";
}

// Tìm kiếm theo mã đơn hoặc tên KH
if ($searchQuery !== "") {
    $filters[] = "(o.order_code2 LIKE ? OR o.customer_name LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like; $params[] = $like;
    $types .= "ss";
}

// Chỉ lấy đơn có ÍT NHẤT 1 sản phẩm cần quét bảo hành
$filters[] = "EXISTS (SELECT 1 FROM order_products opx WHERE opx.order_id = o.id AND opx.warranty_scan = 1)";

$whereSql = $filters ? ("WHERE " . implode(" AND ", $filters)) : "";

// ===== TRUY VẤN 1: LẤY DANH SÁCH ĐƠN =====
$sqlOrders = "
    SELECT 
        o.id, o.order_code2, o.customer_name, o.customer_address, o.status, o.zone
    FROM orders o
    $whereSql
    ORDER BY o.id DESC
    LIMIT 30
";

$stmt = $conn->prepare($sqlOrders);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resOrders = $stmt->get_result();

$orders = [];
$orderIds = [];
while ($row = $resOrders->fetch_assoc()) {
    $orders[$row['id']] = [
        'order_code2'      => $row['order_code2'],
        'customer_name'    => $row['customer_name'],
        'customer_address' => $row['customer_address'],
        'status'           => $row['status'],
        'zone'             => $row['zone'],
        'products'         => []
    ];
    $orderIds[] = (int)$row['id'];
}
$stmt->close();

// ===== TRUY VẤN 2: LẤY SẢN PHẨM CẦN QUÉT =====
if (!empty($orderIds)) {
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $sqlProducts = "
        SELECT op.order_id, op.product_name, op.quantity
        FROM order_products op
        WHERE op.warranty_scan = 1
          AND op.order_id IN ($in)
        ORDER BY op.order_id DESC, op.id ASC
    ";
    $stmtP = $conn->prepare($sqlProducts);
    $typesP = str_repeat("i", count($orderIds));
    $stmtP->bind_param($typesP, ...$orderIds);
    $stmtP->execute();
    $resProducts = $stmtP->get_result();

    while ($p = $resProducts->fetch_assoc()) {
        $oid = (int)$p['order_id'];
        if (isset($orders[$oid])) {
            $orders[$oid]['products'][] = [
                'product_name' => $p['product_name'],
                'quantity'     => (int)$p['quantity']
            ];
        }
    }
    $stmtP->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>HỆ THỐNG QUÉT QR-MÃ VẠCH-KUCHEN-HUROM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
:root{
  --app-bg:#ffffff;          /* nền trắng */
  --card-bg:#ffffff;         /* thẻ trắng */
  --text:#0f172a;            /* slate-900 */
  --muted:#475569;           /* slate-600 */
  --primary:#0ea5e9;         /* sky-500 */
  --danger:#ef4444;          /* red-500 */
  --warning:#f59e0b;         /* amber-500 */
  --border:#e5e7eb;          /* gray-200 */
  --shadow: 0 8px 24px rgba(15,23,42,.06);
}

*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:var(--app-bg);
  color:var(--text);
  -webkit-font-smoothing:antialiased;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
}

/* Top App Bar */
.appbar{
  position:sticky; top:0; z-index:1000;
  backdrop-filter: blur(8px);
  background: rgba(255,255,255,0.85);
  border-bottom:1px solid var(--border);
}
.appbar .inner{
  display:flex; gap:.5rem; align-items:center; padding:.75rem .75rem;
}
.brand{
  display:flex; align-items:center; gap:.5rem; font-weight:700; letter-spacing:.2px;
  color:#0f172a;
}
.brand .dot{width:10px; height:10px; border-radius:50%; background:var(--primary); box-shadow:0 0 10px rgba(14,165,233,.8);}
.app-actions{margin-left:auto; display:flex; gap:.5rem}

/* Search */
.search-wrap{
  padding:.5rem .75rem .75rem;
  background:rgba(255,255,255,0.9);
  border-bottom:1px solid var(--border);
}
.search-input{
  border:1px solid var(--border)!important;
  background:#ffffff!important;
  color:var(--text)!important;
  border-radius:12px!important;
  padding:.7rem .9rem!important;
}
.search-input::placeholder{color:#94a3b8}
.btn-search{
  border-radius:12px!important; padding:.7rem .95rem!important;
}

/* List & Cards */
.list{
  padding: .75rem .75rem 5.5rem;
  background:#f8fafc; /* nhẹ nhàng cho nền nội dung */
}
.card-order{
  background: var(--card-bg);
  border:1px solid var(--border);
  border-radius:16px; overflow:hidden;
  box-shadow: var(--shadow);
  margin-bottom:.9rem;
}
.card-order .card-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:.65rem .9rem; border-bottom:1px dashed var(--border);
  background:linear-gradient(180deg,#ffffff 0%, #fafafa 100%);
  color:var(--text);
}
.badge-zone{
  background:#f1f5f9; border:1px solid var(--border); color:#334155;
}
.card-order .card-body{padding:.9rem}
.meta{
  display:grid; grid-template-columns: 1fr; gap:.25rem; font-size:.95rem;
}
.meta .line{color:var(--muted)}
.meta b{color:var(--text)}
.products{
  margin:.45rem 0 .2rem; padding-left:1.1rem; font-size:.94rem;
}
.products li{ margin:.25rem 0; }

/* Buttons */
.btn-action{
  width:100%; border-radius:12px; font-weight:600; letter-spacing:.2px;
  padding:.75rem 1rem;
}
.btn-primary-app{background:var(--primary); border:none; color:#fff;}
.btn-primary-app:hover{filter:brightness(1.05)}
.btn-ghost{
  background:#ffffff; border:1px solid var(--border); color:#0f172a;
}
.btn-danger-app{background:var(--danger); border:none; color:#fff;}

/* Bottom Nav */
.navbar-bottom{
  position:fixed; left:0; right:0; bottom:0; z-index:999;
  background: rgba(255,255,255,.95); backdrop-filter: blur(6px);
  border-top:1px solid var(--border);
  box-shadow: 0 -6px 18px rgba(15,23,42,.06);
}
.navbar-bottom .inner{
  display:flex; gap:.6rem; padding:.6rem .75rem;
}
.navbar-bottom a, .navbar-bottom button{
  flex:1; border-radius:12px; padding:.7rem .9rem; font-weight:600;
}

/* Empty state */
.empty{
  text-align:center; padding:3.5rem 1rem; color:#64748b;
}

/* Small helpers */
hr{border-color:var(--border); opacity:1}
a, a:hover{color:inherit}
.icon{width:1.1rem; text-align:center}

@media (min-width:768px){
  .list{max-width:680px; margin:0 auto;}
}
.badge-new {
  display:inline-flex; align-items:center; gap:.4rem;
  background:#ffedd5; /* cam nhạt */
  color:#b45309;      /* cam đậm */
  border:1px solid #fdba74;
  border-radius:999px; padding:.15rem .55rem; font-weight:700; font-size:.8rem;
  animation: blink 1.1s linear infinite;
}
@keyframes blink { 0%, 60% { opacity:1 } 70% { opacity:.4 } 80% { opacity:1 } 90% { opacity:.4 } 100% { opacity:1 } }
/* System notice cover */
.notice-hero {
  position: relative; width: 100%; overflow: hidden; border-radius: .5rem;
  background: #0b1220; /* màu nền fallback */
}
.notice-hero img {
  width: 100%; height: auto; display: block; object-fit: cover;
}
.notice-hero .hero-overlay {
  position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.35));
}
.notice-hero .hero-title {
  position: absolute; left: 1rem; bottom: .75rem; right: 1rem;
  color: #fff; font-weight: 700; text-shadow: 0 2px 8px rgba(0,0,0,.5);
}

</style>
</head>
<body>

<!-- App Bar -->
<header class="appbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');?> • Quét QR Bảo Hành</span>
    </div>
    <div class="app-actions">
      <!-- Nút Thông báo hệ thống -->
      <button type="button" class="btn btn-ghost btn-sm" id="btnSystemNotice" title="">
        <i class="fa-solid fa-bell"></i>
      </button>
      <!-- Nút làm mới -->
      <a class="btn btn-ghost btn-sm" href="xem_donhang.php" title="Làm mới">
        <i class="fa-solid fa-rotate-right"></i>
      </a>
    </div>
  </div>
  <div class="search-wrap">
    <form method="get" class="d-flex gap-2">
      <input autofocus name="search" class="form-control search-input" placeholder="Tìm mã đơn hoặc tên khách hàng…" value="<?=htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8')?>">
      <button class="btn btn-warning btn-search" title="Tìm kiếm">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
    </form>
  </div>
</header>

<main class="list">
  <?php if (empty($orders)): ?>
    <div class="empty">
      <div class="mb-2"><i class="fa-regular fa-face-meh fa-2x"></i></div>
      <div>Không tìm thấy đơn hàng nào phù hợp.</div>
    </div>
  <?php else: ?>
    <?php foreach ($orders as $oid => $order): ?>
      <section class="card-order">
        <div class="card-header">
          <div>
            <span class="me-2">📋</span>
            <b><?=htmlspecialchars($order['order_code2'], ENT_QUOTES, 'UTF-8')?></b>
          </div>
          <span class="badge rounded-pill badge-zone">
            <?=htmlspecialchars($order['zone'] ?? '', ENT_QUOTES, 'UTF-8')?>
          </span>
        </div>
        <div class="card-body">
          <div class="meta">
            <div class="line"><span class="icon">👤</span> <b><?=htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8')?></b></div>
            <div class="line"><span class="icon">📍</span> <?=htmlspecialchars($order['customer_address'], ENT_QUOTES, 'UTF-8')?></div>
          </div>

          <hr class="my-3">

          <div class="mb-2"><b>Sản phẩm cần quét QR</b></div>
          <ul class="products">
            <?php foreach ($order['products'] as $p): ?>
              <li>📦 <?=htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8')?> <small>(SL: <?= (int)$p['quantity'] ?>)</small></li>
            <?php endforeach; ?>
          </ul>

          <?php if ($order['status'] === 'Đang chờ quét QR'): ?>
            <!-- GIỮ NGUYÊN LOGIC: submit tới checkscanqr.php -->
            <form method="POST" action="checkscanqr.php" class="mt-3">
              <input type="hidden" name="order_code" value="<?=htmlspecialchars($order['order_code2'], ENT_QUOTES, 'UTF-8')?>">
              <button class="btn btn-primary-app btn-action">
                <i class="fa-solid fa-qrcode me-1"></i> Bắt đầu quét QR
              </button>
            </form>
          <?php else: ?>
            <!-- Trạng thái đang khóa (GIỮ LOGIC) -->
            <button type="button" class="btn btn-ghost btn-action mt-2" onclick="showLocked()">
              <i class="fa-regular fa-clock me-1"></i> Đơn hàng đang khóa
            </button>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<!-- Bottom bar -->
<nav class="navbar-bottom">
  <div class="inner">
    <a class="btn btn-danger-app" href="scan_return.php">
      <i class="fa-solid fa-box-open me-1"></i> Quét hàng trả về
    </a>
    <a class="btn btn-success" href="scanqr3.php">
      <i class="fa-solid fa-magnifying-glass me-1"></i> Tìm đơn nhanh
    </a>
    <a class="btn btn-primary-app" href="mayquet/xem_donhang.php">
      <i class="fa-solid fa-qrcode me-1"></i> Máy quét
    </a>
    <a class="btn btn-ghost" href="logout.php">
      <i class="fa-solid fa-right-from-bracket me-1"></i> Đăng xuất
    </a>
  </div>
</nav>
<!-- System Notice Modal (Bootstrap 5) -->
<div class="modal fade" id="systemNoticeModal" tabindex="-1" aria-labelledby="systemNoticeLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"> 
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="systemNoticeLabel">
          <i class="fa-solid fa-bullhorn me-2 text-warning"></i><?=htmlspecialchars($systemNoticeTitle, ENT_QUOTES, 'UTF-8')?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <div class="modal-body">
  <?php if (!empty($systemCoverUrl)): ?>
    <div class="notice-hero mb-3">
      <img src="<?= htmlspecialchars($systemCoverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($systemCoverAlt, ENT_QUOTES, 'UTF-8') ?>">
      <div class="hero-overlay"></div>
      <div class="hero-title h5 mb-0"><?= htmlspecialchars($systemNoticeTitle, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  <?php endif; ?>

  <?php echo renderSystemNoticeHtml($systemNotice); ?>
</div>
      <div class="modal-footer">
        <small class="text-secondary me-auto">Mã thông báo: <code><?=htmlspecialchars($systemNoticeId, ENT_QUOTES, 'UTF-8')?></code></small>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== Toast khoá đơn (giữ nguyên) =====
function showLocked(){
  const msg = 'Đơn hàng đang được quét, vui lòng thử lại sau 1 phút.';
  if (window.bootstrap) {
    let toast = document.getElementById('lockToast');
    if (!toast){
      const div = document.createElement('div');
      div.id = 'lockToast';
      div.className = 'toast align-items-center text-bg-dark border-0 position-fixed bottom-0 start-50 translate-middle-x mb-5';
      div.setAttribute('role','alert'); div.setAttribute('aria-live','assertive'); div.setAttribute('aria-atomic','true');
      div.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
      document.body.appendChild(div);
      new bootstrap.Toast(div, {delay:2500}).show();
    } else {
      toast.querySelector('.toast-body').textContent = msg;
      new bootstrap.Toast(toast, {delay:2500}).show();
    }
  } else {
    alert(msg);
  }
}

// ===== System Notice Modal logic (hiển thị lại sau 12h) =====
(function(){
  const modalEl   = document.getElementById('systemNoticeModal');
  const btnBell   = document.getElementById('btnSystemNotice');
  const btnOk     = document.getElementById('btnNoticeOk');

  // Các giá trị bơm từ PHP
  const noticeId  = <?= json_encode($systemNoticeId) ?>;
  const hasNotice = <?= json_encode(!empty($systemNotice)) ?>;

  if (!modalEl || !hasNotice) return;

  const STORAGE_KEY = 'sysNoticeSeen_' + noticeId;
  const TTL_MS = 12 * 60 * 60 * 1000; // 12 giờ

  function shouldShow() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return true;
      const data = JSON.parse(raw);
      if (!data || !data.ts) return true;
      return (Date.now() - data.ts) > TTL_MS;
    } catch(e) { return true; }
  }

  function markSeen() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ ts: Date.now() }));
    } catch(e) {}
  }

  const modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });

  // Tự hiện modal nếu đủ điều kiện (12h/lần theo noticeId)
  if (shouldShow()) {
    modal.show();
  }

  // Nút chuông: luôn có thể mở lại thủ công
  if (btnBell) {
    btnBell.addEventListener('click', () => modal.show());
  }

  // Nút "Đã hiểu": đánh dấu đã xem để 12h sau mới hiện lại
  if (btnOk) {
    btnOk.addEventListener('click', () => {
      markSeen();
      modal.hide();
    });
  }

  // Nếu người dùng bấm nút đóng (X), cũng coi như đã xem
  modalEl.addEventListener('hide.bs.modal', markSeen);
})();

// Tự focus ô tìm kiếm trên mobile lần đầu
window.addEventListener('load', () => {
  const el = document.querySelector('input[name="search"]');
  if (el && !el.value) { el.focus({preventScroll:true}); }
});
</script>
</body>
</html>
<?php $conn->close(); ?>
