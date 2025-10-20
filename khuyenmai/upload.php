<?php
// promotions_public.php
// Hiển thị khuyến mãi theo KHOẢNG NGÀY người dùng chọn (không giới hạn 2 tuần)
// Upload ảnh HN/MT/SG: mỗi shop chỉ 1 ảnh; upload mới sẽ xoá ảnh cũ và thay thế

include '../config.php'; // sửa đường dẫn nếu cần
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ---------- Helpers ----------
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function vnd($n){ return number_format((int)$n, 0, ',', '.'); }
function dtVN($s){ return $s?date('d/m/Y H:i', strtotime($s)) : ''; }

// ---------- Nhập khoảng ngày từ người dùng ----------
$fromQ = trim($_GET['from'] ?? '');
$toQ   = trim($_GET['to']   ?? '');

// Mặc định: từ hôm nay -> cuối tháng hiện tại
$today = new DateTime('today');
$defaultStart = clone $today;
$defaultEnd   = new DateTime($today->format('Y-m-t 23:59:59'));

try {
  $start = $fromQ ? new DateTime($fromQ.' 00:00:00') : $defaultStart;
} catch(Exception $e) { $start = $defaultStart; }

try {
  $end   = $toQ   ? new DateTime($toQ.' 23:59:59')   : $defaultEnd;
} catch(Exception $e) { $end = $defaultEnd; }

$startStr = $start->format('Y-m-d H:i:s');
$endStr   = $end->format('Y-m-d H:i:s');

// ---------- Upload ảnh: mỗi shop 1 ảnh, upload mới thay thế ----------
$ALLOWED_MIME = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$MAX_BYTES = 1024*1024; // 1MB
$UPLOAD_DIR = __DIR__ . '/uploads/promo_images';
$REL_BASE   = 'uploads/promo_images/';

if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

$flash = $flash_err = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'upload_images') {
      $pid = (int)($_POST['promotion_id'] ?? 0);
      if ($pid <= 0) throw new Exception('Thiếu promotion_id');

      $fi = new finfo(FILEINFO_MIME_TYPE);

      // Map input theo shop
      $map = [
        'HN' => 'image_hn',
        'MT' => 'image_mt',
        'SG' => 'image_sg',
      ];

      // Chuẩn bị câu lệnh insert & delete
      $stmtSel = $conn->prepare("SELECT id, file_path FROM promotion_images WHERE promotion_id=? AND shop_type=? LIMIT 1");
      $stmtDel = $conn->prepare("DELETE FROM promotion_images WHERE id=?");
      $stmtIns = $conn->prepare("INSERT INTO promotion_images (promotion_id, shop_type, file_path, uploaded_at) VALUES (?,?,?,NOW())");

      foreach ($map as $shop => $input) {
        if (!isset($_FILES[$input]) || $_FILES[$input]['error'] === UPLOAD_ERR_NO_FILE) continue;

        $f = $_FILES[$input];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception("Lỗi upload {$shop}: code ".$f['error']);
        if ($f['size'] > $MAX_BYTES) throw new Exception("Ảnh {$shop} vượt quá 1MB.");

        $mime = $fi->file($f['tmp_name']) ?: '';
        if (!isset($ALLOWED_MIME[$mime])) throw new Exception("Ảnh {$shop} định dạng không hợp lệ (chỉ jpg/png/webp).");

        // Nếu đã có ảnh cho shop này -> xoá file + bản ghi cũ
        $stmtSel->bind_param('is', $pid, $shop);
        $stmtSel->execute();
        $old = $stmtSel->get_result()->fetch_assoc();
        if ($old) {
          $oldAbs = $UPLOAD_DIR . '/' . basename($old['file_path']);
          if (is_file($oldAbs)) { @unlink($oldAbs); }
          $oid = (int)$old['id'];
          $stmtDel->bind_param('i', $oid);
          $stmtDel->execute();
        }

        // Lưu ảnh mới
        $ext = $ALLOWED_MIME[$mime];
        $safeBase = "promo_{$pid}_{$shop}_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4));
        $fileName = $safeBase . "." . $ext;
        $destPath = $UPLOAD_DIR . '/' . $fileName;

        if (!move_uploaded_file($f['tmp_name'], $destPath)) {
          throw new Exception("Không thể lưu ảnh {$shop}.");
        }

        $relPath = $REL_BASE . $fileName;
        $stmtIns->bind_param('iss', $pid, $shop, $relPath);
        $stmtIns->execute();
      }
      $flash = 'Đã cập nhật ảnh khuyến mại.';
    }
  } catch (Exception $e) {
    $flash_err = 'Lỗi: ' . $e->getMessage();
  }
}

// ---------- Query promotions trong khoảng người dùng chọn ----------
$sql = "SELECT * FROM promotions
        WHERE NOT (end_at < ? OR start_at > ?) AND type = 2
        ORDER BY start_at ASC, id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $startStr, $endStr);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Nạp quà
$ids = array_column($rows, 'id');
$giftMap = [];
if (!empty($ids)) {
  $in = implode(',', array_map('intval', $ids));
  $rs = $conn->query("SELECT promotion_id, gift_product, gift_qty, gift_value FROM promotion_gifts WHERE promotion_id IN ($in) ORDER BY id ASC");
  while($r = $rs->fetch_assoc()){
    $giftMap[(int)$r['promotion_id']][] = $r;
  }
}
// legacy fallback
foreach ($rows as $r) {
  $pid = (int)$r['id'];
  if (empty($giftMap[$pid]) && !empty($r['gift_product'])) {
    $giftMap[$pid][] = [
      'gift_product' => $r['gift_product'],
      'gift_qty'     => (int)$r['gift_qty'],
      'gift_value'   => (int)$r['gift_value'],
    ];
  }
}

// Ảnh đã upload (mỗi shop lấy ảnh mới nhất nếu có)
$imgMap = []; // pid => shop => row
if (!empty($ids)) {
  $in = implode(',', array_map('intval', $ids));
  // Lấy ảnh mới nhất cho mỗi (promotion_id, shop_type)
  $rs2 = $conn->query("
    SELECT t1.*
    FROM promotion_images t1
    JOIN (
      SELECT promotion_id, shop_type, MAX(uploaded_at) as mx
      FROM promotion_images
      WHERE promotion_id IN ($in)
      GROUP BY promotion_id, shop_type
    ) t2
      ON t1.promotion_id=t2.promotion_id
     AND t1.shop_type=t2.shop_type
     AND t1.uploaded_at=t2.mx
    ORDER BY t1.uploaded_at DESC, t1.id DESC
  ");
  while($r2 = $rs2->fetch_assoc()){
    $imgMap[(int)$r2['promotion_id']][$r2['shop_type']] = $r2;
  }
}

?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Khuyến mãi theo khoảng ngày</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<style>
  body { background:#f8f9fa; font-size:14px; }
  .shadow-card{ border:none; border-radius:.5rem; box-shadow:0 1px 10px rgba(0,0,0,.08); }
  .gift-chip{ display:inline-block; padding:2px 6px; border-radius:12px; background:#eef; border:1px solid #dde; margin:2px 2px; white-space:nowrap; }
  .img-thumb{ max-height:60px; border:1px solid #e5e5e5; border-radius:6px; padding:2px; margin:2px; }
  .tiny{ font-size:15px; color:#000000; }
  .upload-block h6{ font-size:13px; margin-bottom:.35rem; }
  .img-wrap{ display:flex; flex-wrap:wrap; align-items:flex-start; }
  @media (max-width:768px){
    .table th, .table td{ white-space:nowrap; }
    body{ font-size:13px; }
  }
   /* Scrollbar ngang nổi phía trên, dính khi cuộn dọc */
.top-scrollbar {
  position: sticky;           /* dính ở đầu khung khi cuộn */
  top: 0;
  z-index: 1025;              /* nổi lên trên bảng */
  height: 16px;               /* đủ để hiện thanh cuộn */
  overflow-x: auto;
  overflow-y: hidden;
  background: rgba(255,255,255,.9);
  border-bottom: 1px solid #e9ecef;
  -webkit-overflow-scrolling: touch; /* mượt trên iOS */
}

/* để bảng có thể cuộn ngang như cũ (dưới) */
.table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

</style>
</head>
<body>
<div class="container-fluid py-3">

  <div class="card shadow-card mb-3">
    <div class="card-body">
      <form class="form-inline">
        <label class="mr-2 mb-2">Từ ngày</label>
        <input type="date" class="form-control mr-3 mb-2" name="from" value="<?= esc($start->format('Y-m-d')) ?>">
        <label class="mr-2 mb-2">Đến ngày</label>
        <input type="date" class="form-control mr-3 mb-2" name="to" value="<?= esc($end->format('Y-m-d')) ?>">
        <button class="btn btn-primary mb-2"><i class="fas fa-search"></i> Lọc</button>
        <a class="btn btn-outline-secondary ml-2 mb-2" href="?"><i class="fas fa-undo"></i> Xoá lọc</a>
      </form>
      <div class="mt-2 text-muted">
        Hiển thị khuyến mãi có thời gian <b>giao thoa</b> với khoảng đã chọn: <b><?= esc(dtVN($startStr)) ?></b> → <b><?= esc(dtVN($endStr)) ?></b>.
      </div>
    </div>
  </div>

  <?php if(!empty($flash)): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= esc($flash) ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
  <?php endif; ?>
  <?php if(!empty($flash_err)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= esc($flash_err) ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
  <?php endif; ?>

  <div class="card shadow-card">
    <div class="card-body p-0">
        <!-- Thanh cuộn ngang nổi phía trên, đồng bộ với bảng -->
<div class="top-scrollbar" id="topScrollbar">
  <div id="topScrollbarInner" style="height:1px;"></div>
</div>

      <div class="table-responsive" id="tableWrapper">
        <table class="table table-bordered table-striped mb-0 table-hover">
          <thead class="thead-light text-center">
            <tr>
              <th style="width:60px">STT</th>
              <th style="min-width:200px">Sản phẩm chính</th>
              <th>Giá trị SP</th>
              <th style="min-width:280px">Quà tặng</th>
              <th>Bắt đầu</th>
              <th>Kết thúc</th>
              <th>Ảnh hiện tại (HN / MT / SG)</th>
              <th style="min-width:260px">Nơi nạp ảnh (mỗi ảnh ≤ 1MB)</th>
            </tr>
          </thead>
          <tbody>
<?php if(!empty($rows)):
  $stt = 1;
  $grouped = [];

  // Nhóm theo ngày bắt đầu (YYYY-MM-DD)
  foreach($rows as $r){
    $groupKey = date('Y-m-d', strtotime($r['start_at']));
    $grouped[$groupKey][] = $r;
  }

  foreach($grouped as $groupDate => $items):
    $groupTitle = date('d/m/Y', strtotime($groupDate));
?>
    <!-- Hàng tiêu đề nhóm -->
    <tr class="table-secondary">
      <td colspan="8" class="font-weight-bold">
        📅 Ngày bắt đầu: <?= esc($groupTitle) ?>
      </td>
    </tr>

    <?php foreach($items as $r):
      $pid = (int)$r['id'];
      $gifts = $giftMap[$pid] ?? [];
      $imHN = $imgMap[$pid]['HN'] ?? null;
      $imMT = $imgMap[$pid]['MT'] ?? null;
      $imSG = $imgMap[$pid]['SG'] ?? null;
      $giftTotalValue=0; $giftTotalQty=0;
      foreach($gifts as $g){ 
        $giftTotalValue += ((int)$g['gift_qty'])*((int)$g['gift_value']); 
        $giftTotalQty += (int)$g['gift_qty']; 
      }
    ?>
    <tr>
      <td class="text-center align-middle"><?= $stt++ ?></td>
      <td class="align-middle">
        <div class="font-weight-bold"><?= esc($r['main_product']) ?></div>
        <?php if(!empty($r['note'])): ?><div class="tiny"><?= esc($r['note']) ?></div><?php endif; ?>
        <div class="tiny" style="color:red;"><b>Mua <?= (int)($r['main_qty'] ?? 1) ?> tặng <?= (int)$giftTotalQty ?></b></div>
      </td>
      <td class="text-right align-middle"><?= vnd($r['main_value']) ?></td>
      <td class="align-middle">
        <?php if(!empty($gifts)): foreach($gifts as $g): ?>
          <span class="gift-chip"><?= esc($g['gift_product']) ?> × <?= (int)$g['gift_qty'] ?><?php if((int)$g['gift_value']>0): ?> — <?= vnd($g['gift_value']) ?>đ<?php endif; ?></span>
        <?php endforeach; ?>
        <div class="tiny mt-1">Tổng: <?= vnd($giftTotalValue) ?>đ</div>
        <?php else: ?>
          <span class="text-muted">Không có quà</span>
        <?php endif; ?>
      </td>
      <td class="text-center align-middle"><?= esc(dtVN($r['start_at'])) ?></td>
      <td class="text-center align-middle"><?= esc(dtVN($r['end_at'])) ?></td>

      <!-- Ảnh hiện tại -->
      <td class="align-middle">
        <div class="tiny mb-1"><b>HN</b></div>
        <div class="img-wrap">
          <?php if($imHN): ?>
            <a href="<?= esc($imHN['file_path']) ?>" target="_blank"><img class="img-thumb" src="<?= esc($imHN['file_path']) ?>" alt="HN"></a>
          <?php else: ?>
            <span class="tiny text-muted">Chưa có</span>
          <?php endif; ?>
        </div>

        <div class="tiny mt-2 mb-1"><b>MT</b></div>
        <div class="img-wrap">
          <?php if($imMT): ?>
            <a href="<?= esc($imMT['file_path']) ?>" target="_blank"><img class="img-thumb" src="<?= esc($imMT['file_path']) ?>" alt="MT"></a>
          <?php else: ?>
            <span class="tiny text-muted">Chưa có</span>
          <?php endif; ?>
        </div>

        <div class="tiny mt-2 mb-1"><b>SG</b></div>
        <div class="img-wrap">
          <?php if($imSG): ?>
            <a href="<?= esc($imSG['file_path']) ?>" target="_blank"><img class="img-thumb" src="<?= esc($imSG['file_path']) ?>" alt="SG"></a>
          <?php else: ?>
            <span class="tiny text-muted">Chưa có</span>
          <?php endif; ?>
        </div>
      </td>

      <!-- Upload ảnh -->
      <td class="align-middle">
        <form method="post" enctype="multipart/form-data" class="tiny">
          <input type="hidden" name="action" value="upload_images">
          <input type="hidden" name="promotion_id" value="<?= $pid ?>">

          <div class="upload-block mb-2">
            <h6>Ảnh <span class="badge badge-primary">Shop Hà Nội (HN)</span></h6>
            <input type="file" name="image_hn" accept=".jpg,.jpeg,.png,.webp" class="form-control-file mb-1">
          </div>

          <div class="upload-block mb-2">
            <h6>Ảnh <span class="badge badge-warning">Shop Miền Trung (MT)</span></h6>
            <input type="file" name="image_mt" accept=".jpg,.jpeg,.png,.webp" class="form-control-file mb-1">
          </div>

          <div class="upload-block mb-3">
            <h6>Ảnh <span class="badge badge-success">Shop Sài Gòn (SG)</span></h6>
            <input type="file" name="image_sg" accept=".jpg,.jpeg,.png,.webp" class="form-control-file mb-1">
          </div>

          <button class="btn btn-sm btn-primary btn-block">Tải lên / Thay thế</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
<?php 
  endforeach; 
else: ?>
  <tr><td colspan="8" class="text-center text-muted p-4">Không có khuyến mãi trong khoảng đã chọn.</td></tr>
<?php endif; ?>
</tbody>

        </table>
      </div>
    </div>
    <div class="card-footer bg-white">
      <small class="text-muted">
        Hiển thị các khuyến mãi giao thoa trong khoảng:
        <b><?= esc(dtVN($startStr)) ?></b> → <b><?= esc(dtVN($endStr)) ?></b>.
      </small>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.css">
<!-- jQuery phải load trước Bootstrap -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<!-- Bootstrap Bundle (bao gồm cả Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>


<script>
  (function(){
    var topBar   = document.getElementById('topScrollbar');
    var topInner = document.getElementById('topScrollbarInner');
    var wrap     = document.getElementById('tableWrapper');

    if (!topBar || !topInner || !wrap) return;

    function syncWidths(){
      // Độ rộng cuộn ngang thực tế của nội dung bảng
      var targetWidth = wrap.scrollWidth;
      // Gán cho div trống bên trong topBar để tạo thanh cuộn đúng độ dài
      topInner.style.width = targetWidth + 'px';
    }

    // Đồng bộ cuộn trái/phải giữa 2 thanh
    var syncing = false;
    topBar.addEventListener('scroll', function(){
      if (syncing) return; syncing = true;
      wrap.scrollLeft = topBar.scrollLeft;
      syncing = false;
    });
    wrap.addEventListener('scroll', function(){
      if (syncing) return; syncing = true;
      topBar.scrollLeft = wrap.scrollLeft;
      syncing = false;
    });

    // Cập nhật khi load/resize/ảnh load
    function rafSync(){ window.requestAnimationFrame(syncWidths); }
    window.addEventListener('load', rafSync);
    window.addEventListener('resize', rafSync);

    // Nếu trong bảng có ảnh/ảnh lazy, cập nhật sau mỗi lần ảnh load
    document.querySelectorAll('#tableWrapper img').forEach(function(img){
      img.addEventListener('load', rafSync, { passive: true });
    });

    // Lần đầu
    rafSync();
  })();
</script>

</body>
</html>
