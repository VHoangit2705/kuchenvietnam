<?php
include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}

include 'config.php';
$conn->set_charset('utf8mb4');

// ====== Helpers ======
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function getZoneFilterSql(mysqli $conn, string $position): string {
    if (in_array($position, ['Đơn hàng Vinh', 'Đơn hàng HaNoi', 'Đơn hàng HCM'], true)) {
        return " AND o.zone = '" . $conn->real_escape_string($position) . "'";
    }
    return '';
}

/** Map type gộp marketplace */
function normalizeType(string $type): string {
    return in_array($type, ['shopee', 'lazada', 'tiktok'], true) ? 'marketplace' : $type;
}

/** Class hiển thị trạng thái hàng tại kho */
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

// ====== Session & filters ======
$position = $_SESSION['position'] ?? '';
$zoneFilter = getZoneFilterSql($conn, $position);
$current_date = date('Y-m-d');

// Lấy GET filters (cho form tìm kiếm)
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

// Build WHERE chung (chỉ dùng khi render tab active server-side; với tab lazy sẽ dùng lại logic này trong fetch_tab.php)
$whereParts = ["1=1"];
if ($zoneFilter) $whereParts[] = substr($zoneFilter, 5); // bỏ " AND "
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
// ====== Điều kiện riêng cho thống kê & top SP (áp dụng toàn bộ bộ lọc).
// Nếu KHÔNG chọn khoảng ngày -> mặc định "hôm nay".
$WHERE_FOR_STATS = $WHERE_COMMON;
if ($filters['start_date'] === '' && $filters['end_date'] === '') {
    $todayEsc = $conn->real_escape_string($current_date);
    $WHERE_FOR_STATS .= " AND DATE(o.created_at) = '{$todayEsc}'";
}

// ====== Thống kê hôm nay (gộp 1 query) ======
$sql_stats = "
    SELECT
        COUNT(*)                                                                                           AS total_today,
        SUM(CASE WHEN o.status = 'Đã quét QR' THEN 1 ELSE 0 END)                                           AS count_scanned,
        SUM(CASE WHEN o.status = 'Đang chờ quét QR' THEN 1 ELSE 0 END)                                     AS count_pending,
        SUM(CASE WHEN o.status IN ('Đã huỷ đơn hàng','Đã hủy đơn hàng') THEN 1 ELSE 0 END)                AS count_cancelled
    FROM orders o
    WHERE $WHERE_FOR_STATS
";
$resStats = $conn->query($sql_stats);
$stats = $resStats ? ($resStats->fetch_assoc() ?: []) : [];
$stats += ['total_today'=>0,'count_scanned'=>0,'count_pending'=>0,'count_cancelled'=>0];

// ====== Top 6 sản phẩm xuất hôm nay ======
// ====== Top 6 sản phẩm theo bộ lọc (nếu không chọn ngày -> mặc định "hôm nay") ======
$sql_top_products = "
    SELECT od.product_name, SUM(od.quantity) AS total_quantity
    FROM order_products od
    INNER JOIN orders o ON o.id = od.order_id
    WHERE $WHERE_FOR_STATS
    GROUP BY od.product_name
    ORDER BY total_quantity DESC
    LIMIT 6
";
$top_products = [];
$resTop = $conn->query($sql_top_products);
if ($resTop) {
    while ($row = $resTop->fetch_assoc()) {
        $top_products[] = $row;
    }
}


// ====== Tab groups ======
$tabGroups = [
    'all'               => 'Tất cả',
    'droppii'           => 'Droppii Viettel',
    'droppii_ghtk'      => 'Droppii GHTK',
    'outside'           => 'Nhận hàng tại kho Droppii',
    'warehouse_viettel' => 'Khách lẻ Viettel',
    'warehouse_ghtk'    => 'Khách lẻ GHTK',
    'warehouse_branch'  => 'Khách lẻ qua kho',
    'shopee_korea'      => 'Shopee Korea (New)',
    'marketplace'       => 'KUCHEN (Shopee, Lazada, TikTok)'
];

// ====== Xác định tab active (server sẽ render sẵn tab đầu có dữ liệu; nếu chưa biết, mặc định 'all') ======
// Để tránh query nặng, ta mặc định 'all', người dùng bấm tab khác sẽ load AJAX nhanh.
$activeTab = $_GET['active_tab'] ?? 'all';
if (!isset($tabGroups[$activeTab])) $activeTab = 'all';

// ====== Phân trang cho tab active (server-side) ======
$perPage = 50;
$pageKey = "tab_page_$activeTab";
$page = isset($_GET[$pageKey]) ? max((int)$_GET[$pageKey], 1) : 1;
$offset = ($page - 1) * $perPage;

// Điều kiện lọc theo tab (type)
$typeCondition = '';
if ($activeTab !== 'all') {
    if ($activeTab === 'marketplace') {
        $typeCondition = " AND (o.type IN ('shopee','lazada','tiktok')) ";
    } else {
        $typeCondition = " AND o.type = '".$conn->real_escape_string($activeTab)."' ";
    }
}

// Đếm tổng dòng (tab active)
$sql_count = "SELECT COUNT(*) AS total FROM orders o WHERE $WHERE_COMMON $typeCondition";
$total = (int)($conn->query($sql_count)->fetch_assoc()['total'] ?? 0);

// Lấy dữ liệu đơn hàng (tab active) + JOIN subquery đếm in 1 lần
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
$ordersToShow = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ====== Số hàng hoàn trả để hiển thị chuông + modal ======
$zoneForBell = $_SESSION['position'] ?? '';
$notifyCount = 0;
$sqlBell = "SELECT COUNT(*) AS total FROM orders WHERE check_return = 1 AND zone = '".$conn->real_escape_string($zoneForBell)."'";
$resBell = $conn->query($sqlBell);
if ($resBell && ($rowBell = $resBell->fetch_assoc())) $notifyCount = (int)$rowBell['total'];
// ====== System Notice (tùy chọn) ======
// GỢI Ý: id nên đổi mỗi lần bạn cập nhật nội dung để ép hiện lại ngay
$systemNotice = [
    'active'  => true,
    'id'      => '2025-09-26-01', // đổi id để hiển thị lại ngay
    'title'   => 'Thông báo hệ thống',
    'content' => '
        <div class="text-center mb-3">
          <img src="images/424992df-5966-497b-badc-69f678ccbac0.png" alt="Cập nhật hệ thống" class="img-fluid" style="max-height:250px;width:100%;">
        </div>
        <ul class="mb-0">
           <li><i class="fas fa-exclamation-triangle notice-urgent-icon" aria-hidden="true"></i>Phần mềm đã nâng cấp tự động điền Tỉnh/TP - Quận/Huyện - Xã/Phường với hình thức chỉ cần nhập chắc Địa chỉ giao hàng. Trân trọng thông báo</li>
           <li><i class="fas fa-exclamation-triangle notice-urgent-icon" aria-hidden="true"></i>Phần mềm đã cập nhật thêm in mã QR thanh toán lên phiếu giao hàng và bổ sung QR kích hoạt bảo hành điện tử khi in phiếu bảo hành.</li>
        </ul>
    ',
];
// ====== Warehouse label theo position ======
switch ($position) {
    case 'admin':            $warehouse = 'KHO TỔNG 3 CHI NHÁNH'; break;
    case 'Đơn hàng Vinh':    $warehouse = 'KHO VINH'; break;
    case 'Đơn hàng HaNoi':   $warehouse = 'KHO HÀ NỘI'; break;
    case 'Đơn hàng HCM':     $warehouse = 'KHO HỒ CHÍ MINH'; break;
    default:                 $warehouse = 'KHO KHÔNG XÁC ĐỊNH'; break;
}
$hasDateRange = ($filters['start_date'] !== '' && $filters['end_date'] !== '');
$panelTitleLeft  = $hasDateRange ? 'Dữ liệu theo bộ lọc' : 'Dữ liệu đơn hàng hôm nay';
$panelTitleRight = $hasDateRange ? 'Top SP theo bộ lọc'  : 'Top SP xuất kho hôm nay';
// Nếu là AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $text = trim($_GET['text'] ?? '');
    if ($text === '') {
        echo json_encode(['error' => 'Thiếu tham số text']);
        exit;
    }

    // Các tham số API mới
    $params = [
        'language'          => 'vi',
        'key'               => 'public_key', // ⚠️ thay bằng key thật
        'query'             => $text,
        'new_admin'         => 'true',
        'include_old_admin' => 'true',
    ];

    $url = 'https://maps.track-asia.com/api/v2/place/textsearch/json';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo json_encode(['error' => curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    $data = json_decode($response, true);
    $results = [];

    if (isset($data['results'])) {
        foreach ($data['results'] as $f) {
            // Chỉ lấy 2 cấp hành chính: Tỉnh/Thành & Phường/Xã
            $province = $ward = '';
            if (!empty($f['address_components'])) {
                foreach ($f['address_components'] as $comp) {
                    if (in_array('administrative_area_level_1', $comp['types'] ?? [])) {
                        $province = $comp['long_name'] ?? '';
                    }
                    if (
                        in_array('administrative_area_level_2', $comp['types'] ?? []) || 
                        in_array('administrative_area_level_3', $comp['types'] ?? []) || 
                        in_array('locality', $comp['types'] ?? [])
                    ) {
                        $ward = $comp['long_name'] ?? '';
                    }
                }
            }

            $results[] = [
                'label'    => $f['formatted_address'] ?? '',
                'province' => $province,
                'ward'     => $ward,
            ];
        }
    }

    echo json_encode(['ok' => true, 'items' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Quản lý Đơn hàng</title>
  <link rel="icon" href="logoblack.ico" type="image/x-icon">
  <!-- Chỉ dùng Bootstrap 5 (tránh load 2 bản) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

  <style>
    .order-status{font-weight:bold;padding:1px 10px;border-radius:5px;display:inline-block;text-align:center;width:165px;height:25px;color:#fff}
    .status-processing{background:#ffc107;color:#000;padding:3px 8px;border-radius:5px}
    .status-completed{background:#28a745;color:#fff;padding:3px 8px;border-radius:5px}
    .status-canceled{background:#dc3545;color:#fff;padding:3px 8px;border-radius:5px}
    .status-shipping{background:#17a2b8;color:#fff;padding:3px 8px;border-radius:5px}
    .status-delivered{background:#20c997;color:#fff;padding:3px 8px;border-radius:5px}
    .status-unknown{background:#6c757d;color:#fff;padding:3px 8px;border-radius:5px}
    .left-panel .card,.right-panel .card{border:none;border-radius:.5rem;box-shadow:0 1px 8px rgba(0,0,0,.1)}
    .left-panel .card-header,.right-panel .card-header{border-bottom:none;padding:.75rem 1rem}
    .left-panel .card-header{background:#007bff;color:#fff;border-top-left-radius:.5rem;border-top-right-radius:.5rem}
    .right-panel .card-header{background:#28a745;color:#fff;border-top-left-radius:.5rem;border-top-right-radius:.5rem}
    .search-container{background:#f8f9fa;padding:20px;border-radius:10px;box-shadow:0 0 10px rgba(0,0,0,.1)}
    .btn-search,.btn-create{width:100%}
    @media (min-width:768px){.btn-search{width:auto}}
    .bell-icon{position:absolute;top:-10px;right:-10px;color:red;font-size:24px;animation:shake 1s infinite}
    .badge-number{position:absolute;top:-8px;right:-8px;background:red;color:#fff;font-size:12px;padding:2px 6px;border-radius:50%;font-weight:bold}
    @keyframes shake {0%{transform:rotate(0)}20%{transform:rotate(-10deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-10deg)}80%{transform:rotate(10deg)}100%{transform:rotate(0)}}
    /* Backdrop làm tối nền khi chạy tour */
#tour-backdrop{
  position: fixed; inset: 0; background: rgba(0,0,0,.45);
  z-index: 1055; display: none;
}

/* Viền nổi bật phần tử đang được hướng dẫn */
.tour-highlight{
  position: relative;
  z-index: 1061 !important;
  box-shadow: 0 0 0 4px #fff, 0 0 0 10px rgba(13,110,253,.5);
  border-radius: .5rem;
  transition: box-shadow .2s ease;
}
.notice-urgent-icon{
  color:#dc3545;            /* đỏ cảnh báo */
  margin-right:.45rem;
  font-size:1rem;
  animation:sosBlink 1s ease-in-out infinite;
  vertical-align: -1px;
}
@keyframes sosBlink{
  0%,100%{ transform:scale(1);   filter:drop-shadow(0 0 0 rgba(220,53,69,0)); }
  50%   { transform:scale(1.18); filter:drop-shadow(0 0 6px rgba(220,53,69,.75)); }
}
/* Overlay loading khi in phiếu bảo hành */
#printInvoice2Modal .loading-overlay {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1056;
  font-size: 1rem;
  color: #007bff;
}
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row">

    <!-- Panel trái -->
    <div class="col-md-2 d-none d-md-block">
      <div class="left-panel">
        <div class="card">
          <div class="card-header"><h6><i class="fas fa-chart-line"></i> <?= esc($panelTitleLeft) ?></h6></div>
          <div class="card-body">
            <ul class="list-unstyled mb-0">
              <li><i class="fas fa-file-alt me-2 text-primary"></i>Số đơn đã tạo: <strong><?= (int)$stats['total_today'] ?></strong></li>
              <li><i class="fas fa-qrcode me-2 text-primary"></i>Đang chờ quét QR: <strong><?= (int)$stats['count_pending'] ?></strong></li>
              <li><i class="fas fa-check-circle me-2 text-primary"></i>Đã quét QR: <strong><?= (int)$stats['count_scanned'] ?></strong></li>
              <li><i class="fas fa-times-circle me-2 text-primary"></i>Đơn hàng đã huỷ: <strong><?= (int)$stats['count_cancelled'] ?></strong></li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Giữa -->
    <div class="col-md-8">
      <div class="container mt-5">
        <h3 class="text-center mb-4">QUẢN LÍ DANH SÁCH ĐƠN HÀNG TẠI <?= esc($warehouse) ?></h3>

        <!-- Form lọc -->
        <div class="search-container">
          <form method="get">
              <!-- Dòng hiển thị thông tin nhân viên -->

  <div class="col-12">
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between p-1 bg-light rounded shadow-sm">
      <div class="fw-bold">
        🚨 Nhân viên đi đơn: <span class="text-primary"><?= esc($_SESSION['full_name'] ?? 'Chưa đăng nhập') ?></span>
      </div>
      <div class="text-success fw-bold mt-2 mt-md-0">
        ● Đang hoạt động
      </div>
      <div id="currentDateTime" class="text-muted mt-2 mt-md-0"></div>
    </div>
  </div><hr>

            <div class="row">
              <div class="col-md-3"><input type="text" class="form-control" placeholder="Tìm theo mã đơn hàng" name="order_code" value="<?= esc($filters['order_code']) ?>"></div>
              <div class="col-md-3"><input type="text" class="form-control" placeholder="Tìm theo tên khách hàng" name="customer_name" value="<?= esc($filters['customer_name']) ?>"></div>
              <div class="col-md-3"><input type="text" class="form-control" placeholder="Tìm theo SĐT khách hàng" name="customer_phone" value="<?= esc($filters['customer_phone']) ?>"></div>
              <div class="col-md-3"><input type="text" class="form-control" placeholder="Tìm theo SĐT đại lý" name="agency_phone" value="<?= esc($filters['agency_phone']) ?>"></div>
            </div>

            <div class="row mt-2">
              <div class="col-md-3">
                <select class="form-control" name="status_tracking">
                  <option value="">Lọc theo trạng thái giao hàng</option>
                  <?php
                  $stOpts = [' '=>'Đang cập nhật','Đã tiếp nhận','Đang lấy hàng','Đã lấy hàng','Đang vận chuyển','Đang giao hàng','Chờ phát lại','Giao thành công','Chờ xử lý'];
                  foreach ($stOpts as $val => $label) {
                      $sel = ($filters['status_tracking'] === (string)$val) ? 'selected' : '';
                      $v   = esc($val);
                      echo "<option value=\"$v\" $sel>".esc($label)."</option>";
                  }
                  ?>
                </select>
              </div>

              <?php $zoneSel = $filters['zone']; $statusSel = $filters['status']; ?>

              <div class="col-md-3">
                <select class="form-control" name="zone">
                  <option value="">Lọc theo chi nhánh</option>
                  <option value="Đơn hàng HaNoi" <?= $zoneSel==='Đơn hàng HaNoi'?'selected':'' ?>>Khu vực Hà Nội</option>
                  <option value="Đơn hàng Vinh" <?= $zoneSel==='Đơn hàng Vinh'?'selected':'' ?>>Khu vực Vinh</option>
                  <option value="Đơn hàng HCM"  <?= $zoneSel==='Đơn hàng HCM' ?'selected':'' ?>>Khu vực Hồ Chí Minh</option>
                </select>
              </div>

              <div class="col-md-3">
                <select class="form-control" name="status">
                  <option value="">Lọc trạng thái hàng tại kho</option>
                  <option value="Đang chờ quét QR" <?= $statusSel==='Đang chờ quét QR'?'selected':'' ?>>Đang chờ quét QR</option>
                  <option value="Đã quét QR"       <?= $statusSel==='Đã quét QR'?'selected':'' ?>>Đã quét QR</option>
                  <option value="Đã hủy đơn hàng"       <?= $statusSel==='Đã quét QR'?'selected':'' ?>>Đã hủy đơn hàng</option>
                </select>
              </div>
             <!-- Ô hiển thị phạm vi ngày + 2 input ẩn giữ giá trị submit -->
<div class="col-md-3">
  <div class="input-group">
    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
    <input type="text"
           class="form-control"
           id="dateRangeDisplay"
           name="date_range_label"
           value="<?php
             $sd = esc($filters['start_date']);
             $ed = esc($filters['end_date']);
             echo ($sd && $ed) ? ($sd . ' → ' . $ed) : '';
           ?>"
           placeholder="Lọc theo ngày"
           readonly
           role="button"
           data-bs-toggle="modal"
           data-bs-target="#dateRangeModal">
  </div>

  <!-- giữ tham số GET cũ -->
  <input type="hidden" name="start_date" id="start_date" value="<?= esc($filters['start_date']) ?>">
  <input type="hidden" name="end_date"   id="end_date"   value="<?= esc($filters['end_date']) ?>">
</div>
<!-- Modal chọn khoảng ngày -->
<div class="modal fade" id="dateRangeModal" tabindex="-1" aria-labelledby="dateRangeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dateRangeModalLabel">Lọc theo ngày</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Ngày bắt đầu</label>
          <input type="date" class="form-control" id="modal_start_date">
        </div>
        <div class="mb-3">
          <label class="form-label">Ngày kết thúc</label>
          <input type="date" class="form-control" id="modal_end_date">
        </div>
        <div class="small text-muted">
          Gợi ý: chọn cả 2 ngày để áp dụng. Nếu chọn ngược, hệ thống sẽ tự hoán đổi.
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary" id="clearDateRange">Xoá lọc</button>
        <div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
          <button type="button" class="btn btn-primary" id="applyDateRange">Áp dụng</button>
        </div>
      </div>
    </div>
  </div>
</div>

            </div>

            <!-- Actions -->
            <div class="container mt-4">
              <div class="row">
                <div class="col-md-3 mb-3">
                  <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Tìm kiếm</button>
                </div>
                <div class="col-md-3 mb-3">
                  <a href="xem_donhang.php" class="btn btn-outline-info w-100"><i class="fas fa-qrcode"></i> Quét đơn hàng</a>
                </div>
                <div class="col-md-3 mb-3">
                  <a href="create_order.php" class="btn btn-success w-100"><i class="fas fa-plus"></i> Tạo đơn hàng</a>
                </div>
                <div class="col-md-3 mb-3">
                  <a href="logout.php" class="btn btn-danger w-100"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
                </div>
              </div>

              <div class="row mt-1">
                <?php if ($position === 'admin'): ?>
                  <div class="col-md-3 mb-3">
                    <a href="nhapfilevtp.php" class="btn btn-warning w-100">
                      <i class="fas fa-plus"></i> Nạp excel TT giao hàng
                    </a>
                  </div>
                <?php endif; ?>

                <div class="col-md-3 mb-3 position-relative">
                  <a href="return_manager.php" class="btn btn-outline-primary w-100 position-relative">
                    <i class="fas fa-undo-alt"></i> Hàng hoàn trả về
                    <?php if ($notifyCount > 0): ?>
                      <span id="notify-bell" class="bell-icon ringing">
                        <i class="fas fa-bell"></i>
                        <span class="badge-number" id="notify-count"><?= $notifyCount > 99 ? '99+' : ($notifyCount . '+') ?></span>
                      </span>
                    <?php endif; ?>
                  </a>
                  <script>
  window.__RETURNS_COUNT__ = <?= (int)$notifyCount ?>;
  window.__HAS_RETURNS__   = <?= ((int)$notifyCount > 0) ? 'true' : 'false' ?>;
</script>

                </div>
              <div class="col-md-3 mb-3">
  <a href="#" id="btnExport" class="btn btn-outline-success w-100" data-bs-toggle="modal" data-bs-target="#exportColumnModal">
    <i class="fas fa-file-excel"></i> Xuất Excel (theo bộ lọc)
  </a>
</div>
<div class="col-md-3 mb-3">
  <a href="print_component_requests.php" 
     class="btn btn-outline-warning w-100 d-flex align-items-center justify-content-center fw-bold">
    <i class="fas fa-box-open me-2"></i> Xuất linh kiện bảo hành
  </a>
</div>

<div class="col-md-3 mb-3">
  <button type="button" id="btnCheckAdmin" class="btn btn-outline-dark w-100">
    <i class="fas fa-map-marked-alt"></i> Kiểm tra địa giới sau sáp nhập
  </button>
</div>

<!-- NÚT HƯỚNG DẪN -->
<div class="col-md-3 mb-3">
  <button type="button" id="btnTourListFab" class="btn btn-primary w-100">
    <i class="fas fa-person-chalkboard me-1"></i> Hướng dẫn sử dụng
  </button>
</div>

<script>
// Hàm cập nhật ngày giờ
function updateDateTime() {
  const now = new Date();
  const options = {
    weekday: 'long', year: 'numeric', month: '2-digit',
    day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
  };
  document.getElementById('currentDateTime').textContent =
    now.toLocaleDateString('vi-VN', options);
}
// Chạy ngay và auto cập nhật mỗi giây
updateDateTime();
setInterval(updateDateTime, 1000);
</script>
<div class="modal fade" id="exportColumnModal" tabindex="-1" aria-labelledby="exportColumnModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="exportColumnModalLabel">Chọn cột để xuất Excel</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
    </div>
    <div class="modal-body">
      <div class="row">
        <!-- Tick mặc định như cũ -->
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="ma_dvvc" checked>
          <span class="form-check-label">Mã ĐVVC</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="ma_don_hang" checked>
          <span class="form-check-label">Mã đơn hàng</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="ten_khach" checked>
          <span class="form-check-label">Tên KH</span>
        </label></div>

        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="sdt_khach" checked>
          <span class="form-check-label">SĐT KH</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="ten_dai_ly" checked>
          <span class="form-check-label">Tên đại lý</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="sdt_dai_ly" checked>
          <span class="form-check-label">SĐT đại lý</span>
        </label></div>

        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="dia_chi" checked>
          <span class="form-check-label">Địa chỉ</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="san_pham" checked>
          <span class="form-check-label">Sản phẩm</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="so_luong" checked>
          <span class="form-check-label">Số lượng</span>
        </label></div>

        <!-- Cột nâng cấp -->
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="thanh_tien">
          <span class="form-check-label">Thành tiền (od.price)</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="don_gia">
          <span class="form-check-label">Đơn giá (Thành tiền / SL)</span>
        </label></div>
        <div class="col-md-4"><label class="form-check">
          <input class="form-check-input export-col" type="checkbox" value="discount_code">
          <span class="form-check-label">Mã giảm giá (orders.discount_code)</span>
        </label></div>
      </div>
      <div class="mt-3 small text-muted">*Tuỳ chọn của bạn sẽ được lưu vào trình duyệt (localStorage) để lần sau khỏi chọn lại.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
      <button class="btn btn-primary" id="confirmExportColumns">Xuất Excel</button>
    </div>
  </div></div>
</div>

              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Panel phải -->
    <div class="col-md-2 d-none d-md-block">
      <div class="right-panel">
        <div class="card">
          <div class="card-header"><h6><i class="fas fa-info-circle"></i> <?= esc($panelTitleRight) ?></h6></div>
          <div class="card-body">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light"><tr><th>Tên sản phẩm</th><th>SL</th></tr></thead>
              <tbody>
                <?php if ($top_products): foreach ($top_products as $p): ?>
                  <tr><td><?= esc($p['product_name']) ?></td><td><?= (int)$p['total_quantity'] ?></td></tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="2">Không có dữ liệu</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /container-fluid -->

<br>

<?php
// In lại mảng tab để dùng render client
$tabGroups2 = $tabGroups;
?>
<ul class="nav nav-tabs mb-3" id="orderTabs" role="tablist">
  <?php foreach ($tabGroups2 as $key => $label): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= ($key===$activeTab)?'active':'' ?>" id="tab-<?= $key ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $key ?>" type="button" role="tab" aria-controls="pane-<?= $key ?>" aria-selected="<?= ($key===$activeTab)?'true':'false' ?>" data-tab="<?= esc($key) ?>">
        <?= esc($label) ?>
      </button>
    </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content" id="orderTabContent">
  <?php foreach ($tabGroups2 as $key => $label): ?>
    <div class="tab-pane fade <?= ($key===$activeTab)?'show active':'' ?>" id="pane-<?= $key ?>" role="tabpanel" aria-labelledby="tab-<?= $key ?>">
      <?php if ($key === $activeTab): ?>
        <!-- Server render tab active để hiển thị tức thì -->
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
              <?php if ($ordersToShow): foreach ($ordersToShow as $order): ?>
                <?php
                  $cls = statusClass($order['status']);
                  $printCount  = (int)$order['print_count_giao'];
                  $printCount2 = (int)$order['print_count_bh'];
                ?>
                <tr>
                  <td><?= esc($order['zone']) ?></td>
                  <td><?= esc($order['order_code1']) ?></td>
                  <td><?= esc($order['order_code2']) ?></td>
                  <td><?= esc($order['customer_name']) ?></td>
                  <td><?= esc($order['customer_phone']) ?></td>
                  <td><?= date("H:i d/m/Y", strtotime($order['created_at'])) ?></td>
                  <td>
                    <span class="<?= $cls ?>" data-bs-toggle="tooltip" title="<?= esc($order['note_admin']) ?>"><?= esc($order['status']) ?></span>
                  </td>
                  <td>
                    <?= $order['status_tracking'] === '' 
                        ? '<span style="font-style:italic;font-weight:bold;color:red;">Đang cập nhật</span>'
                        : esc($order['status_tracking']) ?>
                  </td>
                  <td>
                    <a href="order_detail.php?id=<?= (int)$order['id'] ?>" class="btn btn-info btn-sm" data-bs-toggle="tooltip" title="Chi tiết và chỉnh sửa">
                      <i class="fas fa-edit"></i>
                    </a>

                    <button class="btn btn-secondary btn-sm" onclick="updateStatus(<?= (int)$order['id'] ?>)" data-bs-toggle="tooltip" title="Quét lại QR/Hủy đơn hàng">
                      <i class="fas fa-qrcode"></i> <i class="fas fa-times"></i>
                    </button>

                    <a href="confirm_order.php?id=<?= (int)$order['id'] ?>" 
                       class="btn btn-success btn-sm print-btn"
                       data-bs-toggle="tooltip" 
                       data-bs-html="true"
                       title="<?= $printCount>0 ? nl2br(esc($order['tooltip_giao'])) : 'In phiếu giao hàng' ?>">
                      <i class="fas fa-print"></i>
                      <?php if ($printCount>0): ?><span class="badge bg-light text-dark ms-1"><?= $printCount ?></span><?php endif; ?>
                    </a>

                    <a href="#" 
                       class="btn btn-warning btn-sm btn-print-invoice2" 
                       data-id="<?= (int)$order['id'] ?>" 
                       data-bs-toggle="tooltip" 
                       data-bs-html="true"
                       title="<?= $printCount2>0 ? nl2br(esc($order['tooltip_bh'])) : 'In phiếu bảo hành' ?>">
                       <i class="fas fa-clipboard-check"></i>
                       <?php if ($printCount2>0): ?><span class="badge bg-light text-dark ms-1"><?= $printCount2 ?></span><?php endif; ?>
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
          $prev = max(1, $page-1);
          $next = min($totalPages, $page+1);
          // giữ lại filter khi phân trang
          $qs = $_GET; $qs['active_tab'] = $activeTab;
        ?>
          <nav>
  <ul class="pagination justify-content-center">
    <li class="page-item <?= ($page==1)?'disabled':'' ?>">
      <a class="page-link" 
         href="?<?= http_build_query(array_merge($qs, [$pageKey=>1])) ?>#pane-<?= esc($activeTab) ?>" 
         data-page="1">Trang đầu</a>
    </li>
    <li class="page-item <?= ($page==1)?'disabled':'' ?>">
      <a class="page-link" 
         href="?<?= http_build_query(array_merge($qs, [$pageKey=>$prev])) ?>#pane-<?= esc($activeTab) ?>" 
         data-page="<?= $prev ?>">Trang trước</a>
    </li>
    <li class="page-item <?= ($page==$totalPages)?'disabled':'' ?>">
      <a class="page-link" 
         href="?<?= http_build_query(array_merge($qs, [$pageKey=>$next])) ?>#pane-<?= esc($activeTab) ?>" 
         data-page="<?= $next ?>">Trang sau</a>
    </li>
    <li class="page-item <?= ($page==$totalPages)?'disabled':'' ?>">
      <a class="page-link" 
         href="?<?= http_build_query(array_merge($qs, [$pageKey=>$totalPages])) ?>#pane-<?= esc($activeTab) ?>" 
         data-page="<?= $totalPages ?>">Trang cuối</a>
    </li>
  </ul>
</nav>

        <?php endif; ?>
      <?php else: ?>
        <!-- Các tab khác: lazy load -->
        <div class="py-5 text-center text-muted">Bấm vào tab để tải dữ liệu…</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<div class="modal fade" id="checkAdminModal" tabindex="-1" aria-labelledby="checkAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="checkAdminModalLabel">Kiểm tra địa giới hành chính</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
    </div>
    <div class="modal-body">
      <div class="mb-3">
        <label class="form-label">Nhập địa chỉ</label>
        <input type="text" id="checkAddressInput" class="form-control" placeholder="VD: Nhà 22, ngõ 10, Hưng Lộc, TP Vinh">
      </div>
      <div id="checkAdminResult" class="small text-muted">Chưa có dữ liệu.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
      <button type="button" id="btnRunCheckAdmin" class="btn btn-primary">Kiểm tra</button>
    </div>
  </div></div>
</div>

<!-- Modal cập nhật trạng thái -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="updateStatusModalLabel">Cập nhật trạng thái đơn hàng</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
    </div>
    <div class="modal-body">
      <form id="updateStatusForm">
        <input type="hidden" id="orderId" name="orderId" value="">
        <div class="mb-3">
          <label for="orderStatus" class="form-label">Chọn trạng thái mới:</label>
          <select class="form-control" id="orderStatus" name="orderStatus" required>
            <option value="Đang chờ quét QR">Mở khóa quét QR</option>
            <option value="Đã hủy đơn hàng">Hủy đơn hàng</option>
          </select>
        </div>
        <div class="mb-3" id="passwordField" style="display:none;">
          <label for="password" class="form-label">Nhập mật khẩu của bạn để được phép hủy đơn hàng:</label>
          <input type="password" class="form-control" id="password" name="password">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
      <button type="button" class="btn btn-primary" id="saveStatus" disabled>Lưu thay đổi</button>
    </div>
  </div></div>
</div>
<?php if (!empty($systemNotice['active']) && !empty($systemNotice['content'])): ?>
<div class="modal fade" id="systemNoticeModal" tabindex="-1" aria-labelledby="systemNoticeLabel" aria-hidden="true" data-notice-id="<?= esc($systemNotice['id']) ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-primary">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="systemNoticeLabel">
          <i class="fas fa-bullhorn me-2"></i><?= esc($systemNotice['title'] ?? 'Thông báo hệ thống') ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <div class="modal-body">
        <?= $systemNotice['content'] ?>
        <div class="mt-3 small text-muted">
          *Thông báo này sẽ hiện lại sau 12 giờ kể từ khi bạn đóng.
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
        <button type="button" class="btn btn-primary" id="remindLaterBtn">Nhắc lại sau 12 giờ</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
(function(){
  const MODAL_ID = 'systemNoticeModal';
  const modalEl  = document.getElementById(MODAL_ID);
  if (!modalEl) return;

  // ❗ Nếu còn hoàn hàng -> KHÔNG hiển thị thông báo hệ thống để tránh chồng modal
  if (window.__HAS_RETURNS__) return;

  const noticeId = modalEl.getAttribute('data-notice-id') || 'sys';
  const storageKey = `sysNotice_${noticeId}_lastDismiss`;
  const TWELVE_HOURS = 12 * 60 * 60 * 1000;

  function shouldShow() {
    try {
      const last = localStorage.getItem(storageKey);
      if (!last) return true;
      const elapsed = Date.now() - parseInt(last, 10);
      return elapsed >= TWELVE_HOURS;
    } catch (e) { return true; }
  }

  function stampDismiss() {
    try { localStorage.setItem(storageKey, String(Date.now())); } catch (e) {}
  }

  document.addEventListener('DOMContentLoaded', function(){
    if (shouldShow()) {
      new bootstrap.Modal('#' + MODAL_ID, { backdrop: 'static', keyboard: true }).show();
    }
  });

  document.getElementById('remindLaterBtn')?.addEventListener('click', function(){
    stampDismiss();
    bootstrap.Modal.getInstance(modalEl)?.hide();
  });
  modalEl.addEventListener('hide.bs.modal', stampDismiss);
})();
</script>


<!-- Modal in phiếu bảo hành -->
<div class="modal fade" id="printInvoice2Modal" tabindex="-1" aria-labelledby="printInvoice2ModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl" style="max-width:90vw;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="printInvoice2ModalLabel">In phiếu bảo hành</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" style="height:80vh;">
        <iframe id="printInvoice2Iframe" src="" frameborder="0" style="width:100%;height:100%;"></iframe>
      </div>
    </div>
  </div>
</div>

<?php
// Modal cảnh báo hoàn hàng (giữ logic cũ)
$needApproval = false;
$sql_check_return = "SELECT COUNT(*) AS count FROM orders WHERE check_return = 1 AND zone='". $conn->real_escape_string($position) ."'";
$result_check = $conn->query($sql_check_return);
if ($result_check && ($row = $result_check->fetch_assoc())) $needApproval = ((int)$row['count'] > 0);
if ($needApproval):
?>
<div class="modal fade" id="returnNotificationModal" tabindex="-1" aria-labelledby="returnNotificationLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-warning">
    <div class="modal-header bg-warning text-dark">
      <h5 class="modal-title" id="returnNotificationLabel"><i class="fas fa-exclamation-triangle me-2"></i> Thông báo hàng hoàn về kho</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
    </div>
    <div class="modal-body text-dark">
      <p><strong>⚠ Có hàng hoàn trả về kho đang chờ phê duyệt.</strong><br>Bạn bắt buộc phải xử lý hết các yêu cầu hoàn hàng để được phép tắt thông báo!!!</p>
    </div>
    <div class="modal-footer">
      <a href="return_manager.php" class="btn btn-primary">Xem danh sách hoàn hàng</a>
      <!-- Nút Đóng: bỏ data-bs-dismiss để không đóng được -->
<button type="button" class="btn btn-secondary" id="returnCloseBlocked">Đóng</button>

    </div>
  </div></div>
</div>
<script>
document.addEventListener("DOMContentLoaded", () => {
  // Nếu không có hoàn hàng thì không làm gì
  if (!window.__HAS_RETURNS__) return;

  const el = document.getElementById('returnNotificationModal');
  if (!el) return;

  // ❗ Luôn luôn backdrop 'static' + không cho Esc
  const retModal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
  retModal.show();

  // ❗ Chặn mọi hành vi đóng modal khi vẫn còn hoàn hàng
  el.addEventListener('hide.bs.modal', function(e){
    if (window.__RETURNS_COUNT__ > 0) {
      e.preventDefault();
      // hiệu ứng "rung" để báo không thể đóng
      el.querySelector('.modal-dialog')?.classList.add('shake-once');
      setTimeout(()=>el.querySelector('.modal-dialog')?.classList.remove('shake-once'), 350);
    }
  });

  // ❗ Bấm nền/ESC đều đã bị chặn bởi backdrop: 'static', keyboard: false.
  // ❗ Bấm nút Đóng -> cảnh báo thay vì đóng
  document.getElementById('returnCloseBlocked')?.addEventListener('click', function(){
    alert('Bạn cần “Xem danh sách hoàn hàng” và xử lý hết các yêu cầu hoàn hàng trước khi đóng thông báo này.');
  });

  // ❗ Nếu lỡ mở “Thông báo hệ thống” ở nơi khác, đảm bảo tắt nó khi còn hoàn hàng
  const sysEl = document.getElementById('systemNoticeModal');
  if (sysEl && window.__HAS_RETURNS__) {
    try { bootstrap.Modal.getInstance(sysEl)?.hide(); } catch(e){}
  }
});
</script>

<style>
/* Hiệu ứng rung nhẹ khi bị chặn đóng */
@keyframes shakeX {
  10%, 90% { transform: translateX(-1px); }
  20%, 80% { transform: translateX(2px); }
  30%, 50%, 70% { transform: translateX(-4px); }
  40%, 60% { transform: translateX(4px); }
}
.shake-once { animation: shakeX .35s ease; }
</style>

<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tooltip
document.addEventListener('DOMContentLoaded', function () {
  [...document.querySelectorAll('[data-bs-toggle="tooltip"]')].map(el => new bootstrap.Tooltip(el));
});

// In bảo hành trong iframe + Ctrl+P
// In bảo hành trong iframe + Ctrl+P
document.addEventListener('click', function(e){
  const t = e.target.closest('.btn-print-invoice2'); if(!t) return;
  e.preventDefault();
  const id = t.getAttribute('data-id');
  const iframe = document.getElementById('printInvoice2Iframe');
  const modalEl = document.getElementById('printInvoice2Modal');

  // Thêm overlay loading
  let overlay = modalEl.querySelector('.loading-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.innerHTML = `
      <div class="spinner-border text-primary mb-3" role="status">
        <span class="visually-hidden">Đang tải...</span>
      </div>
      <div><strong>Đang tải phiếu bảo hành…</strong></div>
    `;
    modalEl.querySelector('.modal-content').appendChild(overlay);
  }
  overlay.style.display = 'flex';

  // Khi iframe load xong -> ẩn overlay
  iframe.onload = () => { overlay.style.display = 'none'; };

  // Gán src và show modal
  iframe.src = 'print_invoice2.php?id=' + encodeURIComponent(id);
  new bootstrap.Modal(modalEl).show();
});
document.addEventListener('keydown', function (e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
    const iframe = document.getElementById('printInvoice2Iframe');
    if (iframe && iframe.contentWindow) { e.preventDefault(); iframe.contentWindow.focus(); iframe.contentWindow.print(); }
  }
});

// Modal cập nhật trạng thái (giữ logic cũ, thêm debounce)
// ===== [BEGIN PATCH] =====
function updateStatus(orderId){
  $('#orderId').val(orderId);
  $('#passwordField').hide();
  $('#password').val('');
  $('#saveStatus').prop('disabled', true);
  new bootstrap.Modal('#updateStatusModal').show();
}

// Hàm áp điều kiện UI theo trạng thái hiện tại
function applyStatusUI(){
  const v = $('#orderStatus').val();
  if (v === 'Đã hủy đơn hàng'){
    $('#passwordField').show();
    // Nếu đã có mật khẩu hợp lệ từ trước thì không khóa nút nữa
    const pwd = $('#password').val().trim();
    $('#saveStatus').prop('disabled', pwd.length === 0);
  } else {
    $('#passwordField').hide();
    $('#saveStatus').prop('disabled', false);
  }
}

// Khi modal hiện ra, cấu hình UI ngay (tránh phải đổi chọn mới bật nút)
document.getElementById('updateStatusModal')?.addEventListener('shown.bs.modal', function(){
  applyStatusUI();
});

$('#orderStatus').on('change', function(){
  applyStatusUI();
});

let debounceTimeout;
$('#password').on('input', function(){
  const pwd = $(this).val();
  const v = $('#orderStatus').val();
  clearTimeout(debounceTimeout);

  if (v === 'Đã hủy đơn hàng'){
    if (!pwd){ $('#saveStatus').prop('disabled', true); return; }
    debounceTimeout = setTimeout(function(){
      $.ajax({
        url:'check_password.php',
        type:'POST',
        data:{password:pwd},
        dataType:'json',
        success:function(r){
          if(r.success){ $('#saveStatus').prop('disabled', false); }
          else { $('#saveStatus').prop('disabled', true); alert(r.message); }
        },
        error:function(){ alert('Đã xảy ra lỗi, vui lòng thử lại.'); }
      });
    }, 700);
  } else {
    $('#saveStatus').prop('disabled', false);
  }
});

// Enter trong ô password = bấm Lưu
$('#password').on('keydown', function(e){
  if (e.key === 'Enter'){
    e.preventDefault();
    $('#saveStatus').click();
  }
});

$('#saveStatus').on('click', function(){
  const id  = $('#orderId').val();
  const stt = $('#orderStatus').val();
  const pwd = $('#password').val();

  if (stt === 'Đã hủy đơn hàng' && !pwd){
    alert('Vui lòng nhập mật khẩu trước khi hủy đơn hàng.');
    return;
  }

  $.ajax({
    url:'update_order_status.php',
    type:'POST',
    data:{id:id, status:stt},
    dataType:'json',
    success:function(r){
      if(r.success){
        alert('Cập nhật trạng thái đơn hàng thành công!');
        location.reload();
      } else {
        alert('Cập nhật thất bại: ' + r.message);
      }
    },
    error:function(){ alert('Đã xảy ra lỗi, vui lòng thử lại.'); }
  });

  bootstrap.Modal.getInstance(document.getElementById('updateStatusModal'))?.hide();
});
// ===== [END PATCH] =====

// ===== Lazy-load tab nội dung =====
// Lưu page theo từng tab (trong phiên)
const tabPages = new Map();

// Khởi tạo page của tab active từ URL (?tab_page_<tab>=N) — fallback = 1
(function initActiveTabPage() {
  const active = '<?= esc($activeTab) ?>';
  const url = new URL(location.href);
  const key = 'tab_page_' + active;
  const p = parseInt(url.searchParams.get(key) || '1', 10);
  tabPages.set(active, Math.max(1, p));
})();

// Hàm attach handler phân trang trong 1 pane (bắt link có data-page)
function attachPanePaginationHandlers(paneEl, tabKey) {
  paneEl.querySelectorAll('a.page-link[data-page]').forEach(a => {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      const page = parseInt(this.getAttribute('data-page') || '1', 10);
      loadTabPane(tabKey, page);
    }, { once: true });
  });
}

// Tải nội dung 1 tab với page cụ thể (giữ nguyên filter hiện tại)
function loadTabPane(tabKey, page = 1) {
  const params = new URLSearchParams(window.location.search);
  params.set('tab', tabKey);
  params.set('page', String(page));

  const pane = document.querySelector('#pane-' + tabKey);
  if (!pane) return;
  pane.innerHTML = '<div class="py-5 text-center text-muted">Đang tải dữ liệu…</div>';

  fetch('fetch_tab.php?' + params.toString(), { credentials: 'same-origin' })
    .then(res => res.text())
    .then(html => {
      pane.innerHTML = html;
      // Re-init tooltip
      pane.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
      // Lưu page tab
      tabPages.set(tabKey, page);
      // Gắn handler phân trang cho pane
      attachPanePaginationHandlers(pane, tabKey);
      // Cập nhật URL (giữ anchor pane) để back/refresh vẫn ra đúng tab & page
      const url = new URL(location.href);
      url.searchParams.set('active_tab', tabKey);
      url.searchParams.set('tab_page_' + tabKey, String(page));
      history.replaceState(null, '', url.toString() + '#pane-' + tabKey);
    })
    .catch(() => { pane.innerHTML = '<div class="py-5 text-center text-danger">Lỗi tải dữ liệu</div>'; });
}

// Quản lý tab đã load
const loadedTabs = new Set(['<?= esc($activeTab) ?>']);

// Khi chuyển tab
$('#orderTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
  const tabKey = e.target.getAttribute('data-tab');
  const page = tabPages.get(tabKey) || 1;
  // Luôn load lại theo page đã nhớ (đảm bảo đồng bộ filter/URL)
  loadTabPane(tabKey, page);
  loadedTabs.add(tabKey);
});

// Sau khi DOM sẵn sàng, gắn handler phân trang cho tab active (render server)
document.addEventListener('DOMContentLoaded', function(){
  const activeKey = '<?= esc($activeTab) ?>';
  const activePane = document.querySelector('#pane-' + activeKey);
  if (activePane) {
    // Thêm data-page vào các link phân trang server-side (nếu chưa có)
    activePane.querySelectorAll('nav .pagination a.page-link').forEach(a => {
      const url = new URL(a.href, location.origin);
      const pageParamKey = 'tab_page_' + activeKey;
      const p = url.searchParams.get(pageParamKey);
      if (p) a.setAttribute('data-page', p);
    });
    attachPanePaginationHandlers(activePane, activeKey);
  }
});
</script>
<script>
(function(){
  const startHidden = document.getElementById('start_date');
  const endHidden   = document.getElementById('end_date');
  const display     = document.getElementById('dateRangeDisplay');

  const modalStart  = document.getElementById('modal_start_date');
  const modalEnd    = document.getElementById('modal_end_date');

  // Khi mở modal: nạp lại giá trị đang có
  document.getElementById('dateRangeModal').addEventListener('show.bs.modal', () => {
    modalStart.value = startHidden.value || '';
    modalEnd.value   = endHidden.value   || '';
  });

  // Nút Áp dụng: validate + gán giá trị + hiển thị đẹp
  document.getElementById('applyDateRange').addEventListener('click', () => {
    let s = modalStart.value.trim();
    let e = modalEnd.value.trim();

    // Nếu 1 trong 2 rỗng -> yêu cầu đủ cả 2
    if ((s && !e) || (!s && e)) {
      alert('Vui lòng chọn đầy đủ cả Ngày bắt đầu và Ngày kết thúc.');
      return;
    }

    // Nếu cả hai đều có và s > e thì hoán đổi
    if (s && e && s > e) {
      [s, e] = [e, s];
    }

    startHidden.value = s;
    endHidden.value   = e;
    display.value     = (s && e) ? (s + ' → ' + e) : '';

    // Đóng modal
    const modalEl = document.getElementById('dateRangeModal');
    bootstrap.Modal.getInstance(modalEl)?.hide();
  });

  // Nút Xoá lọc: clear hết
  document.getElementById('clearDateRange').addEventListener('click', () => {
    modalStart.value   = '';
    modalEnd.value     = '';
    startHidden.value  = '';
    endHidden.value    = '';
    display.value      = '';
  });
})();
</script>

<script>
(function(){
  const LS_KEY = 'export_columns_v1';

  function loadSavedCols() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch(e){ return []; }
  }
  function saveCols(cols) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(cols)); } catch(e){}
  }

  document.getElementById('exportColumnModal')?.addEventListener('show.bs.modal', () => {
    const saved = new Set(loadSavedCols());
    if (saved.size) {
      document.querySelectorAll('.export-col').forEach(cb => cb.checked = saved.has(cb.value));
    }
  });

  document.getElementById('confirmExportColumns')?.addEventListener('click', function(e){
    e.preventDefault();

    // 1) Lấy danh sách cột
    const cols = Array.from(document.querySelectorAll('.export-col:checked')).map(el => el.value);
    if (!cols.length) { alert('Vui lòng chọn ít nhất 1 cột.'); return; }
    saveCols(cols);

    // 2) Tab đang active
    const activeTabBtn = document.querySelector('#orderTabs .nav-link.active');
    const domActive = activeTabBtn ? activeTabBtn.getAttribute('data-tab') : null;
    const urlNow = new URL(window.location.href);
    const activeTab = (domActive || urlNow.searchParams.get('active_tab') || 'all');

    // 3) Lấy toàn bộ filter từ form
    const form = document.querySelector('.search-container form');
    const params = new URLSearchParams();
    if (form) {
      const fd = new FormData(form);
      for (const [k, v] of fd.entries()) {
        if (v !== null && v !== '') params.set(k, v);
      }
    } else {
      urlNow.searchParams.forEach((v, k) => params.set(k, v));
    }

    // 4) Gắn active_tab + columns (CSV)
    params.set('active_tab', activeTab);
    params.set('columns', cols.join(','));

    // 5) Bỏ tham số phân trang
    for (const k of Array.from(params.keys())) if (k.startsWith('tab_page_')) params.delete(k);

    // 6) Điều hướng
    window.location.assign('export_excel.php?' + params.toString());
  });
})();
</script>
<script>
(() => {
  // ====== UxTour (dùng Popover Bootstrap 5) ======
  class UxTour {
    constructor(steps){
      this.steps = steps || [];
      this.idx = -1;
      this.pop = null;
      this.backdrop = document.getElementById('tour-backdrop');
      document.addEventListener('click', (e)=>{
        if (e.target.closest('.tour-next')) this.next();
        if (e.target.closest('.tour-prev')) this.prev();
        if (e.target.closest('.tour-end'))  this.end();
      });
      document.addEventListener('keydown', (e)=>{
        if (e.key === 'Escape') this.end();
      });
    }

    async start(){
      if (!this.steps.length) return;
      if (this.backdrop) this.backdrop.style.display = 'block';
      this.idx = -1;
      await this.next();
    }

    async next(){ await this.show(this.idx + 1); }
    async prev(){ await this.show(this.idx - 1); }

    async end(){
      this._cleanup();
      this.idx = -1;
      if (this.backdrop) this.backdrop.style.display = 'none';
      // Đóng tất cả modal từng dùng trong tour (nếu còn mở)
      const usedModalIds = [...new Set(
        this.steps.map(s => s.modalId).filter(Boolean)
      )];
      usedModalIds.forEach(id => hideModalById(id));
    }

    _cleanup(){
      document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
      if (this.pop){ try { this.pop.dispose(); } catch(e){} this.pop = null; }
    }

    async show(i){
      this._cleanup();
      if (i < 0) i = 0;
      if (i >= this.steps.length){ this.end(); return; }

      const prevStep = this.steps[this.idx] || null;
      const step = this.steps[i];

      // Nếu bước trước dùng modal A và bước này KHÔNG dùng modal A -> đóng A
      if (prevStep?.modalId && prevStep.modalId !== step.modalId) {
        hideModalById(prevStep.modalId);
      }

      // Chuẩn bị trước bước (mở modal, tạo nội dung động, v.v.)
      if (typeof step.prepare === 'function') {
        await step.prepare();
      }

      // Nếu bước này gắn với 1 modal cụ thể, chắc chắn modal đang mở
      if (step.modalId) showModalById(step.modalId);

      // Lấy phần tử mục tiêu (cho phép hàm động)
      let el = (typeof step.el === 'function') ? step.el() : step.el;
      if (typeof el === 'string') el = document.querySelector(el);

      // Nếu vẫn không tìm thấy -> bỏ qua bước này
      if (!el){
        await this.show(i + (this.idx < i ? 1 : -1));
        return;
      }

      el.scrollIntoView({behavior:'smooth', block:'center'});
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
        content: bodyHtml,
        html: true,
        trigger: 'manual',
        placement: step.placement || 'auto',
        container: 'body',
        sanitize: false
      });
      this.pop.show();
      this.idx = i;
    }
  }

  // ====== Helpers Modal ======
  function showModalById(id){
    const el = document.getElementById(id);
    if (!el) return;
    const inst = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
    inst.show();
  }
  function hideModalById(id){
    const el = document.getElementById(id);
    if (!el) return;
    const inst = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
    inst.hide();
  }

  // ====== Helper chọn phần tử động trong bảng (hàng đầu tiên) ======
  function firstRowBtn(selectorWithinRow){
    const row = document.querySelector('#orderTabContent .tab-pane.show.active table tbody tr');
    if (!row) return null;
    return row.querySelector(selectorWithinRow);
  }

  // ====== Helpers mở modal đúng chuẩn (dùng trong step.prepare) ======
  function openDateModal(){ showModalById('dateRangeModal'); }
  function openExportModal(){ showModalById('exportColumnModal'); }

  // ====== Helper xác định tab đang active ======
  function getActiveTabKey(){
    const btn = document.querySelector('#orderTabs .nav-link.active');
    if (btn) return btn.getAttribute('data-tab') || 'all';
    const url = new URL(location.href);
    return url.searchParams.get('active_tab') || 'all';
  }

  // ====== Popover cảnh báo khi xuất ở tab "all" ======
  function flashExportHint(){
    const tab = getActiveTabKey();
    if (tab !== 'all') return; // đã chọn tab cụ thể -> thôi
    const el = document.getElementById('btnExport');
    if (!el) return;
    const p = new bootstrap.Popover(el, {
      title: 'Mẹo',
      content: 'Bạn đang ở tab <b>Tất cả</b>. Nếu muốn xuất theo từng nhóm, hãy chọn tab mong muốn trước rồi bấm “Xuất Excel”.',
      html: true, trigger: 'manual', placement: 'top', container: 'body', sanitize: false
    });
    p.show();
    setTimeout(()=>{ try{ p.dispose(); }catch(e){} }, 2400);
  }
 document.getElementById('btnRunCheckAdmin')?.addEventListener('click', () => {
  const text = document.getElementById('checkAddressInput').value.trim();
  if (!text) { alert('Vui lòng nhập địa chỉ'); return; }

  const resultEl = document.getElementById('checkAdminResult');
  resultEl.textContent = '⏳ Đang kiểm tra...';

  fetch('<?= basename(__FILE__) ?>?ajax=1&text=' + encodeURIComponent(text), {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { resultEl.textContent = '❌ Lỗi: ' + (d.error || 'Không xác định'); return; }
      if (!d.items.length) { resultEl.textContent = '⚠ Không tìm thấy kết quả'; return; }

      let html = '<ul class="list-group">';
      d.items.forEach(it => {
        html += `<li class="list-group-item">
                   <div><b>${it.label}</b></div>
                   <div>Tỉnh/TP: ${it.province || '-'} | Phường/Xã: ${it.ward || '-'}</div>
                 </li>`;
      });
      html += '</ul>';
      resultEl.innerHTML = html;
    })
    .catch(err => { resultEl.textContent = '❌ Lỗi: ' + err; });
});
  // ====== Các bước tour cho trang danh sách ======
  const steps = [
    {
      el: '.search-container',
      title: 'Khu vực lọc',
      content: 'Tất cả bộ lọc nằm ở đây. Dùng để giới hạn danh sách theo nhu cầu.',
      placement: 'bottom'
    },
    {
      el: 'input[name="order_code"]',
      title: 'Tìm theo mã đơn',
      content: 'Nhập toàn bộ hoặc một phần mã đơn hàng để lọc nhanh.',
      placement: 'bottom'
    },
    {
      el: 'input[name="customer_phone"]',
      title: 'Tìm theo SĐT KH',
      content: 'Lọc theo số điện thoại khách hàng.',
      placement: 'bottom'
    },
    {
      el: 'select[name="status_tracking"]',
      title: 'Trạng thái giao hàng',
      content: 'Chọn trạng thái giao vận (Giao thành công, Đang vận chuyển, …).',
      placement: 'bottom'
    },
    {
      el: '#dateRangeDisplay',
      title: 'Lọc theo khoảng ngày',
      content: 'Bấm vào đây để chọn Ngày bắt đầu/Kết thúc. Có thể hoán đổi nếu chọn ngược.',
      placement: 'bottom',
      prepare: openDateModal,
      modalId: 'dateRangeModal'
    },
    {
      el: '#modal_start_date',
      title: 'Ngày bắt đầu',
      content: 'Chọn ngày bắt đầu cho bộ lọc.',
      placement: 'bottom',
      prepare: openDateModal,
      modalId: 'dateRangeModal'
    },
    {
      el: '#modal_end_date',
      title: 'Ngày kết thúc',
      content: 'Chọn ngày kết thúc cho bộ lọc và nhấn “Áp dụng”.',
      placement: 'bottom',
      prepare: openDateModal,
      modalId: 'dateRangeModal'
    },
    {
      el: '#applyDateRange',
      title: 'Áp dụng khoảng ngày',
      content: 'Xác nhận áp dụng bộ lọc thời gian.',
      placement: 'top',
      prepare: openDateModal,
      modalId: 'dateRangeModal'
    },

    // ===> BỔ SUNG: bắt buộc chọn tab muốn xuất trước khi xuất Excel
    {
      el: '#orderTabs',
      title: 'Chọn tab cần xuất',
      content: 'Sau khi lọc dữ liệu, hãy CHỌN SHEET chứa dữ liệu bạn muốn (VD: “Khách lẻ Viettel”, “Marketplace”, …). Nếu bạn không chọn, hệ thống sẽ MẶC ĐỊNH xuất tab “Tất cả”.',
      placement: 'top'
    },

    {
      el: '.search-container button.btn-primary[type="submit"]',
      title: 'Thực thi tìm kiếm',
      content: 'Sau khi chọn bộ lọc, bấm Tìm kiếm để tải dữ liệu.',
      placement: 'bottom'
    },
    {
      el: '#orderTabs',
      title: 'Nhóm theo loại đơn',
      content: 'Chuyển nhanh giữa các nhóm: Droppii, Khách lẻ, Marketplace… Mỗi tab có phân trang riêng.',
      placement: 'top'
    },
    {
      el: () => firstRowBtn('a.btn-info.btn-sm'),
      title: 'Xem/Chỉnh sửa',
      content: 'Mở chi tiết đơn hàng để xem và chỉnh sửa.',
      placement: 'left'
    },
    {
      el: () => firstRowBtn('button.btn.btn-secondary.btn-sm'),
      title: 'Quét lại QR / Hủy',
      content: 'Mở khoá quét QR hoặc xử lý hủy đơn (có kiểm tra mật khẩu).',
      placement: 'left'
    },
    {
      el: () => firstRowBtn('a.btn-success.btn-sm.print-btn'),
      title: 'In phiếu giao hàng',
      content: 'In phiếu giao. Số trên huy hiệu thể hiện số lần in/hành động gần nhất.',
      placement: 'left'
    },
    {
      el: () => firstRowBtn('a.btn-warning.btn-sm.btn-print-invoice2'),
      title: 'In phiếu bảo hành',
      content: 'Xem/In phiếu bảo hành trong cửa sổ lớn (hỗ trợ Ctrl+P).',
      placement: 'left'
    },
    {
      el: 'a[href="xem_donhang.php"]',
      title: 'Quét đơn hàng',
      content: 'Đi tới màn hình quét QR để cập nhật nhanh trạng thái.',
      placement: 'bottom'
    },
    {
      el: 'a[href="create_order.php"]',
      title: 'Tạo đơn hàng mới',
      content: 'Mở form “Lên đơn xuất kho”.',
      placement: 'bottom'
    },
    {
      el: 'a[href="return_manager.php"]',
      title: 'Hàng hoàn trả',
      content: 'Xem danh sách hàng hoàn về kho cần xử lý. Nếu có chuông đỏ – đang có mục chờ.',
      placement: 'bottom'
    },
   
    // Cụm Xuất Excel
    {
      el: '#btnExport',
      title: 'Xuất Excel theo bộ lọc',
      content: 'Nhớ CHỌN SHEET LOẠI ĐƠN mong muốn trước khi xuất Excel để xuất đúng nhóm. Nếu không chọn, hệ thống sẽ mặc định xuất “Tất cả”.',
      placement: 'bottom',
      prepare: openExportModal,
      modalId: 'exportColumnModal'
    },
    {
      el: '#exportColumnModal .modal-body',
      title: 'Chọn cột xuất',
      content: 'Tick các cột mong muốn, sau đó bấm “Xuất Excel”.',
      placement: 'top',
      prepare: openExportModal,
      modalId: 'exportColumnModal'
    },
    {
      el: '#confirmExportColumns',
      title: 'Xuất Excel',
      content: 'Bấm để tải file Excel tương ứng với bộ lọc & các cột đã chọn.',
      placement: 'top',
      prepare: openExportModal,
      modalId: 'exportColumnModal'
    },
    {
      el: 'a[href="print_component_requests.php"]',
      title: 'Xuất linh kiện bảo hành',
      content: 'Phòng kĩ thuật sẽ gửi yêu cầu xuất linh kiện bảo hành , bạn cần phê duyệt và xuất kho',
      placement: 'bottom'
    }
  ];

  // ====== Gắn nút mở tour ======
  let tourInstance = null;
  document.getElementById('btnTourListFab')?.addEventListener('click', () => {
    if (!tourInstance) tourInstance = new UxTour(steps);
    tourInstance.start();
  });

  // ====== Nhắc chọn tab khi nhấn Xuất Excel mà tab hiện tại là "all" ======
  document.getElementById('btnExport')?.addEventListener('click', () => {
    flashExportHint(); // chỉ hiển thị nhắc; không chặn mở modal
  });
})();
document.getElementById('btnCheckAdmin')?.addEventListener('click', () => {
  new bootstrap.Modal('#checkAdminModal').show();
});

</script>
<div id="tour-backdrop"></div>
</body>
</html>
