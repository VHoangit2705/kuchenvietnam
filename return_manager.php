<?php
include 'auth.php';
include 'config.php';
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset('utf8mb4');

$zone = $_SESSION['position'] ?? '';
$isAdmin = ($zone === 'admin' || $zone === 'Admin' || $zone === 'ADMIN');

// ====== Helpers ======
function esc($v) {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Kiểm tra cột tồn tại */
function tableHasColumn(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
            FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
           LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $stmt->store_result();
  $exists = ($stmt->num_rows > 0);
  $stmt->close();
  return $exists;
}

// Tạo biểu thức lấy SN/Mã BH an toàn
$RH_HAS_WARRANTY_CODE = tableHasColumn($conn, 'return_history', 'warranty_code');
$WARRANTY_CODE_EXPR = $RH_HAS_WARRANTY_CODE
  ? "COALESCE(rh.warranty_code, pw.warranty_code, '')"
  : "COALESCE(pw.warranty_code, '')";

// ====== Input filters ======
$status = $_GET['status'] ?? 'pending'; // pending|approved|rejected|all
// NEW: user thường không được xem tab "rejected"
if (!$isAdmin && $status === 'rejected') {
  $status = 'pending';
}
$search = trim($_GET['q'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to']   ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(5, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// dd/mm/YYYY -> Y-m-d
function vn2sql($d){
  if (!$d) return null;
  $parts = explode('/', $d);
  if (count($parts) !== 3) return null;
  return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
}
$fromSql = vn2sql($from);
$toSql   = vn2sql($to);
if ($toSql) $toSql .= ' 23:59:59';

// Map trạng thái
$actionWhere = '';
switch ($status) {
  case 'pending':  $actionWhere = "rh.action IN ('warranty_return', 'order_return')"; break;
  case 'approved': $actionWhere = "rh.action = 'return_accepted'"; break;
  case 'rejected': $actionWhere = "rh.action = 'reject_return'"; break;
  case 'all':
  default:
    $actionWhere = "rh.action IN ('warranty_return','order_return','return_accepted','reject_return')";
    break;
}

// ====== Base SQL ======
$selectSQL = "
  SELECT
    rh.id AS rh_id,
    {$WARRANTY_CODE_EXPR} AS warranty_code,
    COALESCE(rh.order_code2, o.order_code2)       AS order_code,
    COALESCE(rh.customer_name, o.customer_name)   AS customer_name,
    COALESCE(rh.customer_phone, o.customer_phone) AS customer_phone,
    COALESCE(
      rh.product_name,
      op_pw.product_name,
      op_rh.product_name,
      (SELECT op2.product_name FROM order_products op2
        WHERE op2.order_id = COALESCE(op_pw.order_id, op_rh.order_id, rh.order_id)
        ORDER BY op2.id LIMIT 1)
    ) AS product_name,
    COALESCE(
      rh.quantity,
      op_pw.quantity,
      op_rh.quantity,
      (SELECT op2.quantity FROM order_products op2
        WHERE op2.order_id = COALESCE(op_pw.order_id, op_rh.order_id, rh.order_id)
        ORDER BY op2.id LIMIT 1)
    ) AS quantity,
    rh.action_time,
    rh.scanned_by,
    rh.reason,
    rh.action,
    rh.approved_by,
    rh.action_time,
    o.zone
  FROM return_history rh
  LEFT JOIN product_warranties pw ON rh.warranty_id = pw.id
  LEFT JOIN order_products op_pw   ON pw.order_product_id = op_pw.id
  LEFT JOIN order_products op_rh   ON op_rh.id = rh.warranty_id
  LEFT JOIN orders o               ON o.id = COALESCE(op_pw.order_id, op_rh.order_id, rh.order_id)
  WHERE $actionWhere
";

$countSQL = "
  SELECT COUNT(*) AS cnt
  FROM return_history rh
  LEFT JOIN product_warranties pw ON rh.warranty_id = pw.id
  LEFT JOIN order_products op_pw   ON pw.order_product_id = op_pw.id
  LEFT JOIN order_products op_rh   ON op_rh.id = rh.warranty_id
  LEFT JOIN orders o               ON o.id = COALESCE(op_pw.order_id, op_rh.order_id, rh.order_id)
  WHERE $actionWhere
";

// ====== Dynamic filters (zone, search, date range) ======
$params = []; $types = '';
$filters = [];

if (!$isAdmin) {
  $filters[] = "o.zone = ?";
  $types    .= 's';
  $params[]  = $zone;
}

if ($search !== '') {
  $filters[] = "(
      COALESCE(rh.order_code2, o.order_code2) LIKE CONCAT('%', ?, '%')
   OR {$WARRANTY_CODE_EXPR} LIKE CONCAT('%', ?, '%')
   OR COALESCE(rh.customer_phone, o.customer_phone) LIKE CONCAT('%', ?, '%')
  )";
  $types .= 'sss';
  $params[] = $search; $params[] = $search; $params[] = $search;
}

if ($fromSql) { $filters[] = "rh.action_time >= ?"; $types .= 's'; $params[] = $fromSql.' 00:00:00'; }
if ($toSql)   { $filters[] = "rh.action_time <= ?"; $types .= 's'; $params[] = $toSql; }

if ($filters) {
  $whereExtra = ' AND ' . implode(' AND ', $filters);
  $selectSQL .= $whereExtra;
  $countSQL  .= $whereExtra;
}

// ====== Count for pagination ======
$stmtCnt = $conn->prepare($countSQL);
if ($types) $stmtCnt->bind_param($types, ...$params);
$stmtCnt->execute();
$total = (int)$stmtCnt->get_result()->fetch_assoc()['cnt'];
$stmtCnt->close();

$pages = max(1, (int)ceil($total / $limit));

// ====== Final select ======
$selectSQL .= " ORDER BY rh.action_time DESC LIMIT ? OFFSET ?";
$typesPage   = $types . 'ii';
$paramsPage  = $params;
$paramsPage[] = $limit;
$paramsPage[] = $offset;

$stmt = $conn->prepare($selectSQL);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Quản lý hàng trả về</title>
  <link rel="icon" href="logoblack.ico" type="image/x-icon">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
        href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body { padding: 20px; background-color: #f8f9fa; }
    table th, table td { vertical-align: middle !important; }
    .message-box { margin-top: 20px; }
    .nowrap { white-space: nowrap; }
    .pill { padding: .15rem .5rem; border-radius: .5rem; font-size: .8rem;}
    .pill-pending { background:#fff3cd; color:#856404; }
    .pill-ok     { background:#d4edda; color:#155724; }
    .pill-rej    { background:#f8d7da; color:#721c24; }
  </style>
</head>
<body>
<div class="container-fluid">

  <div class="row align-items-center mb-3">
    <div class="col-auto">
      <a href="admin.php" class="btn btn-outline-secondary">← Trang chủ</a>
    </div>
    <div class="col text-center">
      <h4 class="mb-0 font-weight-bold">📦 QUẢN LÝ HÀNG HOÀN VỀ – <?= htmlspecialchars($isAdmin ? 'admin' : $zone) ?></h4>
    </div>
  </div>

  <?php
    function linkTab($st){
      $q = $_GET; $q['status'] = $st; $q['page'] = 1;
      return basename(__FILE__) . '?' . http_build_query($q);
    }
  ?>
  <ul class="nav nav-pills mb-3">
  <ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $status==='pending'?'active':'' ?>" href="<?= linkTab('pending') ?>">Chờ duyệt</a></li>
  <li class="nav-item"><a class="nav-link <?= $status==='approved'?'active':'' ?>" href="<?= linkTab('approved') ?>">Đã duyệt</a></li>

  <?php if ($isAdmin): // NEW: chỉ Admin mới nhìn thấy tab Bị từ chối ?>
    <li class="nav-item"><a class="nav-link <?= $status==='rejected'?'active':'' ?>" href="<?= linkTab('rejected') ?>">Bị từ chối</a></li>
  <?php endif; ?>

  <li class="nav-item"><a class="nav-link <?= $status==='all'?'active':'' ?>" href="<?= linkTab('all') ?>">Tất cả</a></li>
</ul>


  <!-- Bộ lọc -->
  <form class="card card-body mb-3" method="get">
    <div class="form-row">
      <div class="col-md-3 mb-2">
        <label class="small mb-1">Tìm kiếm</label>
        <input type="text" class="form-control" name="q" placeholder="Mã đơn / Mã BH / SĐT" value="<?= esc($search) ?>">
      </div>
      <div class="col-md-2 mb-2">
        <label class="small mb-1">Từ ngày</label>
        <input type="text" class="form-control" name="from" placeholder="dd/mm/YYYY" value="<?= esc($from) ?>">
      </div>
      <div class="col-md-2 mb-2">
        <label class="small mb-1">Đến ngày</label>
        <input type="text" class="form-control" name="to" placeholder="dd/mm/YYYY" value="<?= esc($to) ?>">
      </div>
      <div class="col-md-2 mb-2">
        <label class="small mb-1">Số dòng/trang</label>
        <input type="number" class="form-control" name="limit" min="5" max="100" value="<?= (int)$limit ?>">
      </div>
      <div class="col-md-3 mb-2">
        <label class="small mb-1 d-block"> </label>
        <input type="hidden" name="status" value="<?= esc($status) ?>">
        <button class="btn btn-primary mr-2">Lọc</button>
        <a class="btn btn-light" href="<?= basename(__FILE__) ?>?status=<?= urlencode($status) ?>">Xóa lọc</a>
      </div>
    </div>
  </form>

  <form id="returnForm">
    <div class="table-responsive">
      <table class="table table-bordered table-striped table-sm">
        <thead class="thead-dark">
          <tr>
            <th class="nowrap">#</th>
            <th class="nowrap">
              <?php if ($status==='pending' || ($status==='rejected' && $isAdmin)): ?>
              <input type="checkbox" id="checkAll">
              <?php endif; ?>
              Chọn
            </th>
            <th>Trạng thái</th>
            <th>SN / Mã BH</th>
            <th>Mã đơn</th>
            <th>Khách hàng</th>
            <th>Điện thoại</th>
            <th>Sản phẩm</th>
            <th class="text-right">SL</th>
            <th class="nowrap">Ngày quét</th>
            <th>Nhân viên</th>
            <th>Ghi chú</th>
            <th class="nowrap">Duyệt bởi</th>
            <th class="nowrap">Ngày duyệt</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $i = $offset + 1;
          while ($row = $result->fetch_assoc()):
            $isPending  = in_array($row['action'], ['warranty_return','order_return']);
            $isAccepted = ($row['action'] === 'return_accepted');
            $isRejected = ($row['action'] === 'reject_return');
            $pill = $isPending ? 'pill-pending' : ($isAccepted?'pill-ok':'pill-rej');
            $label= $isPending ? 'Chờ duyệt' : ($isAccepted?'Đã duyệt':'Từ chối');
          ?>
          <tr>
            <td class="nowrap"><?= $i++ ?></td>
            <td class="nowrap">
              <?php if ($status==='pending' || ($status==='rejected' && $isAdmin)): ?>
                <input type="checkbox" class="row-select" data-id="<?= (int)$row['rh_id'] ?>">
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td><span class="pill <?= $pill ?>"><?= $label ?></span></td>
            <td><?= esc($row['warranty_code']) ?></td>
            <td><?= esc($row['order_code']) ?></td>
            <td><?= esc($row['customer_name']) ?></td>
            <td><?= esc($row['customer_phone']) ?></td>
            <td><?= esc($row['product_name']) ?></td>
            <td class="text-right"><?= (int)($row['quantity'] ?? 0) ?></td>
            <td class="nowrap"><?= !empty($row['action_time']) ? date('d/m/Y H:i', strtotime($row['action_time'])) : '' ?></td>
            <td><?= esc($row['scanned_by']) ?></td>
            <td><?= esc($row['reason']) ?></td>
            <td><?= esc($row['approved_by']) ?></td>
            <td class="nowrap"><?= !empty($row['action_time']) ? date('d/m/Y H:i', strtotime($row['action_time'])) : '' ?></td>
          </tr>
          <?php endwhile; ?>
          <?php if ($total === 0): ?>
          <tr><td colspan="14" class="text-center text-muted">Không có dữ liệu.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($status==='pending'): ?>
      <div class="mb-3">
        <button type="submit" id="confirmBtn" class="btn btn-success">✅ Xác nhận hoàn hàng</button>
        <button type="button" id="rejectBtn" class="btn btn-danger ml-2">🚫 Từ chối hoàn hàng</button>
      </div>
    <?php elseif ($status==='rejected' && $isAdmin): ?>
      <div class="mb-3">
        <button type="button" id="adminDeleteBtn" class="btn btn-warning">
          🧹 Xóa số seri (Admin)
        </button>
      </div>
    <?php endif; ?>
  </form>

  <!-- Pagination -->
  <nav aria-label="Page nav">
    <ul class="pagination">
      <?php
        $qs = $_GET; 
        for ($p=1; $p<=$pages; $p++):
          $qs['page'] = $p;
          $href = basename(__FILE__) . '?' . http_build_query($qs);
      ?>
      <li class="page-item <?= $p===$page?'active':'' ?>">
        <a class="page-link" href="<?= $href ?>"><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>

  <div id="message" class="message-box"></div>
</div>

<!-- Modal từ chối -->
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form id="rejectForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">🛑 Lý do từ chối hoàn hàng</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <textarea class="form-control" id="rejectReason" rows="4" placeholder="Nhập lý do từ chối..." required></textarea>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal xác nhận xóa (Admin) -->
<div class="modal fade" id="adminDeleteModal" tabindex="-1" role="dialog" aria-labelledby="adminDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form id="adminDeleteForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">🧹 Xóa số seri đã bị từ chối</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">
        Bạn có chắc muốn xóa <b>các số seri</b> tương ứng với dòng <i>Bị từ chối</i> đang chọn? Hành động này không thể hoàn tác.
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-warning">Xác nhận xóa</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const form = document.getElementById('returnForm');
const messageBox = document.getElementById('message');
const confirmBtn = document.getElementById('confirmBtn');
const rejectBtn = document.getElementById('rejectBtn');
const rejectForm = document.getElementById('rejectForm');
const rejectReasonInput = document.getElementById('rejectReason');
const checkAll = document.getElementById('checkAll');
const adminDeleteBtn = document.getElementById('adminDeleteBtn');
const adminDeleteForm = document.getElementById('adminDeleteForm');

function showMessage(msg, isError) {
  messageBox.innerHTML = '<div class="alert ' + (isError ? 'alert-danger' : 'alert-success') + '">' + msg + '</div>';
}

if (checkAll) {
  checkAll.addEventListener('change', () => {
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = checkAll.checked);
  });
}

// ✅ Xác nhận hoàn hàng (tab pending)
if (form && confirmBtn) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Đang xử lý...';

    const approveIds = Array.from(document.querySelectorAll('.row-select:checked'))
      .map(cb => parseInt(cb.dataset.id));

    if (approveIds.length === 0) {
      showMessage("❌ Vui lòng chọn ít nhất một hàng để xác nhận.", true);
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Xác nhận hoàn hàng';
      return;
    }

    try {
      const res = await fetch('update_return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ approve_ids: approveIds })
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
      showMessage(`✅ Đã duyệt ${j.approved_updated || 0} dòng.`, false);
      setTimeout(() => location.reload(), 1000);
    } catch (err) {
      showMessage('❌ ' + err.message, true);
    } finally {
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Xác nhận hoàn hàng';
    }
  });
}

// ❌ Từ chối hoàn hàng
if (rejectBtn) {
  rejectBtn.addEventListener('click', () => {
    const selected = document.querySelectorAll('.row-select:checked');
    if (selected.length === 0) {
      showMessage("❌ Vui lòng chọn ít nhất một hàng để từ chối.", true);
      return;
    }
    $('#rejectModal').modal('show');
  });
}

if (rejectForm) {
  rejectForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const reason = rejectReasonInput.value.trim();
    if (!reason) return alert("Vui lòng nhập lý do từ chối.");

    const rejectIds = Array.from(document.querySelectorAll('.row-select:checked'))
      .map(cb => parseInt(cb.dataset.id));

    try {
      const res = await fetch('update_return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reject_ids: rejectIds, reject_reason: reason })
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
      showMessage(`🚫 Đã từ chối ${j.rejected_updated || 0} dòng.`, false);
      $('#rejectModal').modal('hide');
      setTimeout(() => location.reload(), 1000);
    } catch (err) {
      showMessage('❌ ' + err.message, true);
    }
  });
}

// 🧹 Xóa số seri (Admin) ở tab “Bị từ chối”
if (adminDeleteBtn) {
  adminDeleteBtn.addEventListener('click', () => {
    const selected = Array.from(document.querySelectorAll('.row-select:checked'));
    if (selected.length === 0) {
      showMessage("❌ Vui lòng chọn ít nhất một dòng 'Bị từ chối' để xóa seri.", true);
      return;
    }
    $('#adminDeleteModal').modal('show');
  });
}

if (adminDeleteForm) {
  adminDeleteForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const ids = Array.from(document.querySelectorAll('.row-select:checked'))
      .map(cb => parseInt(cb.dataset.id));

    try {
      const res = await fetch('update_return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ delete_rejected_ids: ids })
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
      showMessage(`🧹 Đã xóa ${j.deleted_warranties || 0} số seri từ các dòng bị từ chối.`, false);
      $('#adminDeleteModal').modal('hide');
      setTimeout(() => location.reload(), 1000);
    } catch (err) {
      showMessage('❌ ' + err.message, true);
    }
  });
}
</script>
</body>
</html>
