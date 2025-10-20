<?php
session_start();
if (!isset($_SESSION['full_name'])) { http_response_code(401); exit('Unauthorized'); }
include 'config.php';
$conn->set_charset('utf8mb4');

// ===== Helpers =====
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function getZoneFilterSql(mysqli $conn, string $position): string {
    if (in_array($position, ['Đơn hàng Vinh','Đơn hàng HaNoi','Đơn hàng HCM'], true)) {
        return " AND o.zone = '" . $conn->real_escape_string($position) . "'";
    }
    return '';
}
function statusClass(string $status): string {
    return match ($status) {
        'Đang chờ quét QR' => 'status-processing',
        'Đã quét QR'       => 'status-completed',
        'Đã hủy đơn hàng'  => 'status-canceled',
        'Hàng chờ đóng gói'=> 'status-shipping',
        'Đang quét QR'     => 'status-delivered',
        default            => 'status-unknown',
    };
}

// ===== Inputs =====
$position = $_SESSION['position'] ?? '';
$zoneFilter = getZoneFilterSql($conn, $position);
$tab  = $_GET['tab']  ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [
    'order_code'     => $_GET['order_code']     ?? '',
    'customer_name'  => $_GET['customer_name']  ?? '',
    'customer_phone' => $_GET['customer_phone'] ?? '',
    'agency_phone'   => $_GET['agency_phone']   ?? '',
    'status'         => $_GET['status']         ?? '',
    'status_tracking'=> $_GET['status_tracking']?? '',
    'type'           => $_GET['type']           ?? '',
    'zone'           => $_GET['zone']           ?? '',
    'start_date'     => $_GET['start_date']     ?? '',
    'end_date'       => $_GET['end_date']       ?? ''
];

// WHERE chung
$whereParts = ["1=1"];
if ($zoneFilter) $whereParts[] = substr($zoneFilter, 5);
foreach (['order_code' => 'order_code2', 'customer_name' => 'customer_name', 'customer_phone' => 'customer_phone', 'agency_phone' => 'agency_phone'] as $k => $col) {
    if ($filters[$k] !== '') {
        $val = $conn->real_escape_string($filters[$k]);
        $whereParts[] = "$col LIKE '%$val%'";
    }
}
foreach (['status', 'status_tracking', 'type', 'zone'] as $k) {
    if ($filters[$k] !== '') {
        $val = $conn->real_escape_string($filters[$k]);
        $whereParts[] = "$k = '$val'";
    }
}
if ($filters['start_date'] !== '' && $filters['end_date'] !== '') {
    $sd = $conn->real_escape_string($filters['start_date']);
    $ed = $conn->real_escape_string($filters['end_date']);
    $whereParts[] = "DATE(o.created_at) BETWEEN '$sd' AND '$ed'";
}
$WHERE_COMMON = implode(' AND ', $whereParts);

// Type theo tab
$typeCondition = '';
if ($tab !== 'all') {
    if ($tab === 'marketplace') {
        $typeCondition = " AND (o.type IN ('shopee','lazada','tiktok')) ";
    } else {
        $typeCondition = " AND o.type = '".$conn->real_escape_string($tab)."' ";
    }
}

$perPage = 50;
$offset = ($page - 1) * $perPage;

// Count
$sql_count = "SELECT COUNT(*) AS total FROM orders o WHERE $WHERE_COMMON $typeCondition";
$total = (int)($conn->query($sql_count)->fetch_assoc()['total'] ?? 0);

// Data + JOIN subquery print
$sql_data = "
    SELECT 
        o.id, o.order_code1, o.order_code2, o.customer_name, o.customer_phone,
        o.note_admin, o.type, o.status, o.status_tracking, o.zone, o.created_at,
        COALESCE(ph_giao.count_print,0) AS print_count_giao,
        COALESCE(ph_bh.count_print,0)   AS print_count_bh,
        COALESCE(ph_giao.tooltip,'')    AS tooltip_giao,
        COALESCE(ph_bh.tooltip,'')      AS tooltip_bh
    FROM orders o
    LEFT JOIN (
        SELECT order_id, COUNT(*) AS count_print,
               GROUP_CONCAT(CONCAT(note,' bởi ',printed_by) ORDER BY printed_at DESC SEPARATOR '\n') AS tooltip
        FROM print_history 
        WHERE loai_phieu = 1
        GROUP BY order_id
    ) ph_giao ON ph_giao.order_id = o.id
    LEFT JOIN (
        SELECT order_id, COUNT(*) AS count_print,
               GROUP_CONCAT(CONCAT(note,' bởi ',printed_by) ORDER BY printed_at DESC SEPARATOR '\n') AS tooltip
        FROM print_history 
        WHERE loai_phieu = 2
        GROUP BY order_id
    ) ph_bh ON ph_bh.order_id = o.id
    WHERE $WHERE_COMMON $typeCondition
    ORDER BY o.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql_data);
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>
<div class="table-responsive">
  <table class="table table-bordered table-striped align-middle">
    <thead class="table-light">
      <tr>
        <th>Kho</th>
        <th>Mã ĐVVC</th>
        <th>Mã đơn hàng</th>
        <th>Tên khách hàng</th>
        <th>SĐT</th>
        <th>Ngày tạo đơn</th>
        <th>Trạng thái hàng tại kho</th>
        <th>Trạng thái giao hàng</th>
        <th>Hành động</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): foreach ($rows as $order): 
        $cls = statusClass($order['status']);
        $pc1 = (int)$order['print_count_giao'];
        $pc2 = (int)$order['print_count_bh'];
      ?>
        <tr>
          <td><?= esc($order['zone']) ?></td>
          <td><?= esc($order['order_code1']) ?></td>
          <td><?= esc($order['order_code2']) ?></td>
          <td><?= esc($order['customer_name']) ?></td>
          <td><?= esc($order['customer_phone']) ?></td>
          <td><?= date("H:i d/m/Y", strtotime($order['created_at'])) ?></td>
          <td><span class="<?= $cls ?>" data-bs-toggle="tooltip" title="<?= esc($order['note_admin']) ?>"><?= esc($order['status']) ?></span></td>
          <td><?= $order['status_tracking'] === '' ? '<span style="font-style:italic;font-weight:bold;color:red;">Đang cập nhật</span>' : esc($order['status_tracking']) ?></td>
          <td>
            <a href="order_detail.php?id=<?= (int)$order['id'] ?>" class="btn btn-info btn-sm" data-bs-toggle="tooltip" title="Chi tiết và chỉnh sửa">
              <i class="fas fa-edit"></i>
            </a>
            <button class="btn btn-secondary btn-sm" onclick="updateStatus(<?= (int)$order['id'] ?>)" data-bs-toggle="tooltip" title="Quét lại QR/Hủy đơn hàng">
              <i class="fas fa-qrcode"></i> <i class="fas fa-times"></i>
            </button>
            <a href="confirm_order.php?id=<?= (int)$order['id'] ?>" class="btn btn-success btn-sm print-btn" data-bs-toggle="tooltip" data-bs-html="true" title="<?= $pc1>0 ? nl2br(esc($order['tooltip_giao'])) : 'In phiếu giao hàng' ?>">
              <i class="fas fa-print"></i><?php if ($pc1>0): ?><span class="badge bg-light text-dark ms-1"><?= $pc1 ?></span><?php endif; ?>
            </a>
            <a href="#" class="btn btn-warning btn-sm btn-print-invoice2" data-id="<?= (int)$order['id'] ?>" data-bs-toggle="tooltip" data-bs-html="true" title="<?= $pc2>0 ? nl2br(esc($order['tooltip_bh'])) : 'In phiếu bảo hành' ?>">
              <i class="fas fa-clipboard-check"></i><?php if ($pc2>0): ?><span class="badge bg-light text-dark ms-1"><?= $pc2 ?></span><?php endif; ?>
            </a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9" class="text-center text-muted">Không có đơn hàng.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($total > $perPage):
  $totalPages = (int)ceil($total / $perPage);
  $prev = max(1, $page-1); $next = min($totalPages, $page+1);
  // Giữ filters khi phân trang AJAX
  $keep = $_GET; $keep['tab']=$tab;
?>
<nav>
  <ul class="pagination justify-content-center">
    <li class="page-item <?= ($page==1)?'disabled':'' ?>">
      <a class="page-link ajax-page" href="#" data-page="1" data-tab="<?= esc($tab) ?>">Trang đầu</a>
    </li>
    <li class="page-item <?= ($page==1)?'disabled':'' ?>">
      <a class="page-link ajax-page" href="#" data-page="<?= $prev ?>" data-tab="<?= esc($tab) ?>">Trang trước</a>
    </li>
    <li class="page-item <?= ($page==$totalPages)?'disabled':'' ?>">
      <a class="page-link ajax-page" href="#" data-page="<?= $next ?>" data-tab="<?= esc($tab) ?>">Trang sau</a>
    </li>
    <li class="page-item <?= ($page==$totalPages)?'disabled':'' ?>">
      <a class="page-link ajax-page" href="#" data-page="<?= $totalPages ?>" data-tab="<?= esc($tab) ?>">Trang cuối</a>
    </li>
  </ul>
</nav>

<script>
// Phân trang AJAX ngay trong pane hiện tại
document.querySelectorAll('#pane-<?= esc($tab) ?> .ajax-page').forEach(a=>{
  a.addEventListener('click', (e)=>{
    e.preventDefault();
    const tab = a.getAttribute('data-tab');
    const page= a.getAttribute('data-page');
    const pane = document.querySelector('#pane-'+tab);

    const params = new URLSearchParams(window.location.search);
    params.set('tab', tab);
    params.set('page', page);

    pane.innerHTML = '<div class="py-5 text-center text-muted">Đang tải dữ liệu…</div>';
    fetch('fetch_tab.php?' + params.toString(), { credentials:'same-origin' })
      .then(r=>r.text())
      .then(html=>{
        pane.innerHTML = html;
        pane.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
      })
      .catch(()=>{ pane.innerHTML = '<div class="py-5 text-center text-danger">Lỗi tải dữ liệu</div>'; });
  });
});
</script>
<?php endif; ?>
