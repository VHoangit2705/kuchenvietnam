<?php
// promotions_images_download.php
// Chỉ dùng để TẢI XUỐNG ảnh khuyến mại, có lọc THEO SHOP (HN/MT/SG):
// - Hỏi người dùng truy cập thuộc shop nào -> chỉ hiển thị ảnh của shop đó
// - Tải trực tiếp 1 ảnh (chỉ nếu ảnh thuộc shop đã chọn)
// - ZIP ảnh của 1 khuyến mại (chỉ ảnh thuộc shop đã chọn)
// - ZIP tất cả ảnh hiển thị (chỉ ảnh thuộc shop đã chọn)

include '../config.php'; // chỉnh đường dẫn nếu cần
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Ho_Chi_Minh');

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function dtVN($s){ return $s?date('d/m/Y H:i', strtotime($s)) : ''; }

// ==== Xác định shop truy cập ====
$VALID_SHOPS = ['HN','MT','SG'];
$shop = strtoupper(trim($_GET['shop'] ?? ($_COOKIE['promo_shop'] ?? '')));
if (!in_array($shop, $VALID_SHOPS, true)) {
  // Nếu chưa chọn shop -> hiển thị form chọn shop, sau đó reload lại giữ tham số thời gian nếu có
  $fromQ = esc($_GET['from'] ?? '');
  $toQ   = esc($_GET['to'] ?? '');
  ?>
  <!doctype html>
  <html lang="vi">
  <head>
    <meta charset="utf-8">
    <title>Chọn shop tải ảnh</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>body{background:#f8f9fa}</style>
  </head>
  <body>
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card shadow-sm">
          <div class="card-header"><b>Chọn shop để hiển thị & tải ảnh khuyến mại</b></div>
          <div class="card-body">
            <form method="get">
              <?php if($fromQ): ?><input type="hidden" name="from" value="<?= $fromQ ?>"><?php endif; ?>
              <?php if($toQ):   ?><input type="hidden" name="to"   value="<?= $toQ   ?>"><?php endif; ?>
              <div class="form-group">
                <label>Shop</label>
                <select name="shop" class="form-control" required>
                  <option value="">-- Chọn shop --</option>
                  <option value="HN">Shop Hà Nội (HN)</option>
                  <option value="MT">Shop Miền Trung (MT)</option>
                  <option value="SG">Shop Sài Gòn (SG)</option>
                </select>
              </div>
              <button class="btn btn-primary btn-block">Tiếp tục</button>
            </form>
            <div class="small text-muted mt-3">Lựa chọn sẽ được ghi nhớ cho các lần truy cập tiếp theo.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  </body>
  </html>
  <?php
  exit;
}
// Nhớ lựa chọn shop 7 ngày
setcookie('promo_shop', $shop, time() + 7*24*3600, '/', '', false, true);

// ==== Cửa sổ thời gian mặc định: now -> min(now+14d, endOfMonth) ====
$now = new DateTime('now');
$endCandidate = (clone $now)->modify('+14 days');
$endOfMonth = new DateTime($now->format('Y-m-t 23:59:59'));
$defaultEnd = $endCandidate < $endOfMonth ? $endCandidate : $endOfMonth;

// Cho phép override bằng query ?from=YYYY-mm-dd&to=YYYY-mm-dd
$fromQ = trim($_GET['from'] ?? '');
$toQ   = trim($_GET['to']   ?? '');

try {
  $start = $fromQ ? new DateTime($fromQ.' 00:00:00') : $now;
  $end   = $toQ   ? new DateTime($toQ.' 23:59:59')   : $defaultEnd;
} catch(Exception $e) {
  $start = $now; $end = $defaultEnd;
}

$startStr = $start->format('Y-m-d H:i:s');
$endStr   = $end->format('Y-m-d H:i:s');

$UPLOAD_DIR = __DIR__ . '/uploads/promo_images'; // thư mục chứa file ảnh
$REL_BASE   = 'uploads/promo_images/';

// ==== Hành động tải xuống ====
// action=download_one&id=IMAGE_ID
// action=zip_promotion&pid=PROMOTION_ID
// action=zip_all (lấy tất cả ảnh hiển thị trong khoảng thời gian)
$action = $_GET['action'] ?? '';

function send_file_force_download($absPath, $downloadName){
  if (!is_file($absPath)) { http_response_code(404); exit('File not found'); }
  $size = filesize($absPath);
  header('Content-Description: File Transfer');
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.basename($downloadName).'"');
  header('Content-Transfer-Encoding: binary');
  header('Content-Length: '.$size);
  header('Cache-Control: private, no-transform, no-store, must-revalidate');
  readfile($absPath);
  exit;
}

function zip_and_stream($zipName, $fileMap){ // fileMap: [relativePath => absolutePath]
  if (empty($fileMap)) { http_response_code(404); exit('Không có file để nén.'); }
  $tmpZip = tempnam(sys_get_temp_dir(), 'promo_zip_');
  $zip = new ZipArchive();
  if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== TRUE) {
    http_response_code(500); exit('Không tạo được ZIP.');
  }
  foreach ($fileMap as $rel => $abs) {
    if (is_file($abs)) $zip->addFile($abs, $rel);
  }
  $zip->close();

  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.basename($zipName).'"');
  header('Content-Length: ' . filesize($tmpZip));
  header('Cache-Control: private, no-transform, no-store, must-revalidate');
  readfile($tmpZip);
  @unlink($tmpZip);
  exit;
}

// --- Download 1 ảnh theo image id (chỉ nếu trùng shop) ---
if ($action === 'download_one') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); exit('Thiếu id.'); }
  $stmt = $conn->prepare("SELECT pi.*, p.main_product, pi.shop_type
                          FROM promotion_images pi
                          JOIN promotions p ON p.id = pi.promotion_id
                          WHERE pi.id=? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if (!$row) { http_response_code(404); exit('Không tìm thấy ảnh.'); }
  if (strtoupper($row['shop_type']) !== $shop) { http_response_code(403); exit('Ảnh không thuộc shop bạn chọn.'); }

  // Chỉ cho phép tải file nằm trong thư mục ảnh
  $rel = $row['file_path'];
  $abs = realpath($UPLOAD_DIR . '/' . basename($rel));
  if (!$abs || strpos($abs, realpath($UPLOAD_DIR)) !== 0) {
    http_response_code(403); exit('Đường dẫn không hợp lệ.');
  }

  $downloadName = sprintf('promo_%s_%s', $shop, basename($abs));
  send_file_force_download($abs, $downloadName);
}

// --- ZIP 1 khuyến mại (chỉ ảnh của shop đã chọn) ---
if ($action === 'zip_promotion') {
  $pid = (int)($_GET['pid'] ?? 0);
  if ($pid <= 0) { http_response_code(400); exit('Thiếu pid.'); }
  $stmt = $conn->prepare("SELECT p.id, p.main_product, pi.id as img_id, pi.shop_type, pi.file_path
                          FROM promotions p
                          LEFT JOIN promotion_images pi ON pi.promotion_id=p.id
                          WHERE p.id=? AND (pi.shop_type IS NULL OR pi.shop_type=?)");
  $stmt->bind_param('is', $pid, $shop);
  $stmt->execute();
  $res = $stmt->get_result();

  $files = [];
  $mainName = 'promotion_'.$pid.'_'.$shop;
  while($r = $res->fetch_assoc()){
    if (empty($r['file_path'])) continue;
    if (strtoupper($r['shop_type']) !== $shop) continue;
    $rel = $r['file_path'];
    $abs = realpath($UPLOAD_DIR . '/' . basename($rel));
    if ($abs && strpos($abs, realpath($UPLOAD_DIR)) === 0 && is_file($abs)) {
      // trong zip: <promotion_id>_<shop>/<filename>
      $relInZip = $mainName . '/' . basename($abs);
      $files[$relInZip] = $abs;
    }
  }
  $zipName = $mainName . '.zip';
  zip_and_stream($zipName, $files);
}

// --- ZIP tất cả ảnh trong khoảng hiển thị (chỉ shop đã chọn) ---
if ($action === 'zip_all') {
  // tìm promotions trong khoảng [+ giao thoa]
  $sql = "SELECT id FROM promotions WHERE NOT (end_at < ? OR start_at > ?) ORDER BY id ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $startStr, $endStr);
  $stmt->execute();
  $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

  $files = [];
  if (!empty($ids)) {
    $in = implode(',', array_map('intval', $ids));
    $rs = $conn->query("SELECT p.id as pid, pi.shop_type, pi.file_path
                        FROM promotions p
                        JOIN promotion_images pi ON pi.promotion_id=p.id
                        WHERE p.id IN ($in) AND pi.shop_type='". $conn->real_escape_string($shop) ."'
                        ORDER BY p.id ASC");
    while($r = $rs->fetch_assoc()){
      if (empty($r['file_path'])) continue;
      $rel = $r['file_path'];
      $abs = realpath($UPLOAD_DIR . '/' . basename($rel));
      if ($abs && strpos($abs, realpath($UPLOAD_DIR)) === 0 && is_file($abs)) {
        $relInZip = 'promotion_'.$r['pid'].'_'.$shop.'/' . basename($abs);
        $files[$relInZip] = $abs;
      }
    }
  }
  $zipName = 'promo_images_'.$shop.'_'.date('Ymd_His').'.zip';
  zip_and_stream($zipName, $files);
}

// ==== Nếu không phải hành động tải, hiển thị UI chọn tải xuống ====
// Lấy promotions trong cửa sổ để render danh sách
$sql = "SELECT * FROM promotions
        WHERE NOT (end_at < ? OR start_at > ?) AND type = 2
        ORDER BY start_at ASC, id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $startStr, $endStr);
$stmt->execute();
$promos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$ids = array_column($promos, 'id');
$imagesByPromo = [];
if (!empty($ids)) {
  $in = implode(',', array_map('intval', $ids));
  // chỉ lấy ảnh thuộc shop đã chọn, mỗi promotion giữ 1 ảnh mới nhất
  $rs = $conn->query("SELECT * FROM promotion_images
                      WHERE promotion_id IN ($in) AND shop_type='".$conn->real_escape_string($shop)."'
                      ORDER BY promotion_id ASC, uploaded_at DESC, id DESC");
  while($r = $rs->fetch_assoc()){
    $pid = (int)$r['promotion_id'];
    if (!isset($imagesByPromo[$pid])) {
      $imagesByPromo[$pid] = $r; // newest
    }
  }
}

?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Tải ảnh khuyến mại (Shop <?= esc($shop) ?>)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<style>
  body{background:#f8f9fa}
  .shadow-card{border:none;border-radius:.5rem;box-shadow:0 1px 10px rgba(0,0,0,.08)}
  .img-thumb{max-height:60px;border:1px solid #e5e5e5;border-radius:6px;padding:2px;margin:2px}
  .tiny{font-size:12px;color:#6c757d}
  @media (max-width:768px){ .table th,.table td{white-space:nowrap} }
</style>
</head>
<body>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h5 class="mb-2">Tải ảnh khuyến mại — <span class="badge badge-info">Shop <?= esc($shop) ?></span></h5>
    <div class="tiny">
      Khoảng hiển thị: <b><?= esc(dtVN($startStr)) ?></b> → <b><?= esc(dtVN($endStr)) ?></b> |
      <a href="?shop=HN&from=<?= esc($start->format('Y-m-d')) ?>&to=<?= esc($end->format('Y-m-d')) ?>">HN</a> ·
      <a href="?shop=MT&from=<?= esc($start->format('Y-m-d')) ?>&to=<?= esc($end->format('Y-m-d')) ?>">MT</a> ·
      <a href="?shop=SG&from=<?= esc($start->format('Y-m-d')) ?>&to=<?= esc($end->format('Y-m-d')) ?>">SG</a>
    </div>
  </div>

  <div class="card shadow-card mb-3">
    <div class="card-body">
      <form class="form-inline">
        <input type="hidden" name="shop" value="<?= esc($shop) ?>">
        <label class="mr-2 mb-2">Từ ngày</label>
        <input type="date" name="from" class="form-control mr-3 mb-2" value="<?= esc($start->format('Y-m-d')) ?>">
        <label class="mr-2 mb-2">Đến ngày</label>
        <input type="date" name="to" class="form-control mr-3 mb-2" value="<?= esc($end->format('Y-m-d')) ?>">
        <button class="btn btn-primary mb-2">Lọc</button>
        <a href="?action=zip_all&shop=<?= esc($shop) ?>&from=<?= esc($start->format('Y-m-d')) ?>&to=<?= esc($end->format('Y-m-d')) ?>" class="btn btn-success ml-auto mb-2">
          <i class="fas fa-file-archive"></i> ZIP tất cả (<?= esc($shop) ?>)
        </a>
        <a href="?<?php
          // Link "đổi shop": xóa cookie, trở lại màn hình chọn shop
          $qs = $_GET; unset($qs['shop']); $qs['clear_shop']='1'; echo http_build_query($qs);
        ?>" class="btn btn-outline-secondary ml-2 mb-2">Đổi shop</a>
      </form>
    </div>
  </div>

  <?php
  // clear_shop? Xoá cookie rồi redirect về chính URL không có clear_shop
  if (isset($_GET['clear_shop'])) {
    setcookie('promo_shop', '', time()-3600, '/', '', false, true);
    $qs = $_GET; unset($qs['clear_shop']); unset($qs['shop']);
    $to = basename($_SERVER['PHP_SELF']).'?'.http_build_query($qs);
    header("Location: $to"); exit;
  }
  ?>

  <div class="card shadow-card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-striped mb-0">
          <thead class="thead-light text-center">
            <tr>
              <th style="width:60px">STT</th>
              <th>Sản phẩm chính</th>
              <th>Ảnh (<?= esc($shop) ?>)</th>
              <th style="min-width:240px">Tải xuống</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!empty($promos)): $stt=1; foreach($promos as $p):
              $pid = (int)$p['id'];
              $im  = $imagesByPromo[$pid] ?? null;
            ?>
            <tr>
              <td class="text-center align-middle"><?= $stt++ ?></td>
              <td class="align-middle">
                <div class="font-weight-bold"><?= esc($p['main_product']) ?></div>
                <div class="tiny">BĐ: <?= esc(dtVN($p['start_at'])) ?> — KT: <?= esc(dtVN($p['end_at'])) ?></div>
              </td>

              <td class="text-center align-middle">
                <?php if($im): ?>
                  <a href="?action=download_one&shop=<?= esc($shop) ?>&id=<?= (int)$im['id'] ?>">
                    <img class="img-thumb" src="<?= esc($im['file_path']) ?>" alt="<?= esc($shop) ?>">
                  </a>
                  <div class="tiny mt-1">
                    <a href="?action=download_one&shop=<?= esc($shop) ?>&id=<?= (int)$im['id'] ?>">Tải ảnh (<?= esc($shop) ?>)</a>
                  </div>
                <?php else: ?><span class="tiny text-muted">Chưa có ảnh <?= esc($shop) ?></span><?php endif; ?>
              </td>

              <td class="align-middle text-center">
                <a class="btn btn-sm btn-outline-primary" href="?action=zip_promotion&shop=<?= esc($shop) ?>&pid=<?= $pid ?>">
                  <i class="fas fa-file-archive"></i> ZIP ảnh KM #<?= $pid ?> (<?= esc($shop) ?>)
                </a>
              </td>
            </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="text-center text-muted p-4">Không có khuyến mãi trong khoảng thời gian đã chọn.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between">
      <small class="text-muted">Khoảng hiển thị: từ <?= esc(dtVN($startStr)) ?> đến <?= esc(dtVN($endStr)) ?>.</small>
      <small class="text-muted">Đang xem: Shop <b><?= esc($shop) ?></b></small>
    </div>
  </div>
</div>

<!-- Icons (tuỳ chọn) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
