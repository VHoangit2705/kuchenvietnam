<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
?>
<?php
// Kết nối đến cơ sở dữ liệu
include 'config.php';

// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Lấy `order_id` từ URL
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    echo "ID đơn hàng không hợp lệ.";
    exit();
}

// Lấy thông tin đơn hàng từ bảng `orders`
$order_query = $conn->query("SELECT * FROM orders WHERE id = $order_id");
$order = $order_query->fetch_assoc();

if (!$order) {
    echo "Không tìm thấy đơn hàng.";
    exit();
}

// Lấy danh sách sản phẩm của đơn hàng từ bảng `order_products`
$products_query = $conn->query("
    SELECT op.*, p.price AS actual_price 
    FROM order_products op
    JOIN products p ON op.product_name = p.product_name
    WHERE op.order_id = $order_id
");

// Định nghĩa trạng thái đơn hàng
$status_labels = [
    'Đang chờ quét QR' => 'Đang xử lý',
    'Đã quét QR'       => 'Đã hoàn thành',
    'canceled'         => 'Đã hủy'
];

// Lấy thông tin bảo hành từ bảng `product_warranties`
$warranties_query = $conn->query("
    SELECT * FROM product_warranties 
    WHERE order_product_id IN (
      SELECT id FROM order_products WHERE order_id = $order_id
    )
");
$warranties = [];
while ($warranty = $warranties_query->fetch_assoc()) {
    $warranties[$warranty['order_product_id']][] = $warranty['warranty_code'];
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <title>Chi tiết đơn hàng</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="icon" href="logoblack.ico" type="image/x-icon">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    :root{
      --pane-gutter: 16px;
      --card-radius: 16px;
    }
    html, body {
      height: 100%;
      background: #f6f8fb;
    }
    
body {
  overflow-y: auto;   /* Cho phép cuộn dọc toàn trang */
  overflow-x: hidden; /* Tránh cuộn ngang ngoài ý muốn */
}

    .app-shell {
  min-height: 100vh;               /* không chặn nội dung cao hơn viewport */
  display: grid;
  grid-template-rows: auto 1fr auto;
}
    .app-header {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #fff;
      border-bottom: 1px solid #e9eef5;
    }
    .app-main {
  /* height: auto để nội dung có thể dài và trang sẽ cuộn */
  display: grid;
  grid-template-columns: 360px 1fr;
  gap: var(--pane-gutter);
  padding: var(--pane-gutter);
}
    .pane {
      background: transparent;
      min-height: 0; /* cho phép children set overflow */
    }
    .pane-left {
      display: grid;
      grid-auto-rows: min-content;
      gap: var(--pane-gutter);
      overflow: hidden; /* không cuộn cột trái */
    }
    .pane-right {
      display: grid;
      grid-template-rows: 1fr auto; /* trên: sản phẩm & bảo hành (cuộn), dưới: footer nút */
      gap: var(--pane-gutter);
      min-height: 0; /* cần cho overflow child */
    }
    .panel-scroll {
      background: #fff;
      border: 1px solid #e9eef5;
      border-radius: var(--card-radius);
      padding: 0;
      overflow: hidden;
      display: grid;
      grid-template-rows: auto 1fr auto;
      min-height: 0;
    }
    .panel-header {
      padding: 12px 16px;
      background: #0d6efd;
      color: #fff;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .panel-body {
      padding: 12px 16px;
      overflow: auto; /* chỉ vùng giữa cuộn */
    }
    .panel-footer {
      padding: 12px 16px;
      border-top: 1px solid #e9eef5;
      background: #fafbff;
    }
    .card-soft {
      background: #fff;
      border: 1px solid #e9eef5;
      border-radius: var(--card-radius);
      box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .card-soft .card-header{
      border-bottom: 1px solid #eef2f7;
      border-top-left-radius: var(--card-radius);
      border-top-right-radius: var(--card-radius);
      background: #0d6efd;
      color: #fff;
      padding: 10px 14px;
    }
    .card-soft .card-body{ padding: 14px; }

    /* Bảng sản phẩm */
    .table-sticky thead th {
      position: sticky;
      top: 0;
      background: #f1f4f9;
      z-index: 5;
    }
    .table-sticky tfoot td {
      position: sticky;
      bottom: 0;
      background: #fff;
      z-index: 4;
    }
    .badge-status {
      padding: .5em .75em;
      border-radius: 999px;
      font-weight: 600;
    }
    .btn-circle {
      width: 36px; height: 36px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
    }

    /* Toast message (success_message) */
    .toast-container {
      position: fixed;
      top: 12px;
      right: 12px;
      z-index: 1080;
    }

    /* Responsive: cột trái thu hẹp */
    @media (max-width: 1100px){
      .app-main { grid-template-columns: 320px 1fr; }
    }
    @media (max-width: 900px){
      body { overflow: auto; } /* cho phép cuộn trang trên màn hình nhỏ */
      .app-main { grid-template-columns: 1fr; }
      .pane-left { order: 2; }
      .pane-right { order: 1; min-height: 60vh; }
    }

    /* Nhẹ nhàng hơn cho input bảo hành trong bảng */
    .sn-input .form-control{
      min-width: 200px;
    }
  </style>
</head>
<body>
<div class="app-shell">

  <!-- Header -->
  <header class="app-header">
    <div class="container-fluid py-2">
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <a href="admin.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Quay lại
          </a>
          <h5 class="mb-0">
            Chi tiết đơn hàng:
            <span class="text-primary fw-bold"><?php echo htmlspecialchars($order['order_code2']); ?></span>
            <span class="text-muted">— <?php echo htmlspecialchars($order['zone']); ?></span>
          </h5>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php
            $statusText = $status_labels[$order['status']] ?? $order['status'];
            $statusClass = ($order['status'] == 'Đã quét QR') ? 'bg-success' : (($order['status']=='Đang chờ quét QR')?'bg-warning text-dark':'bg-secondary');
          ?>
          <span class="badge badge-status <?php echo $statusClass; ?>">
            <?php echo htmlspecialchars($statusText); ?>
          </span>
          <?php if (isset($_SESSION['position']) && $_SESSION['position'] == 'admin'): ?>
            <a href="change_status.php?id=<?php echo $order_id; ?>" class="btn btn-primary btn-sm">
              <i class="fa-solid fa-repeat me-1"></i> Chuyển trạng thái
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- Main -->
  <main class="app-main container-fluid">
    <!-- Pane Left: Customer & Payment -->
    <section class="pane pane-left">

      <!-- Thông tin khách hàng -->
      <div class="card-soft">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>I. Thông tin Khách hàng</span>
          <button type="button" class="btn btn-light btn-circle" data-bs-toggle="modal" data-bs-target="#editModal" title="Chỉnh sửa">
            <i class="fa-solid fa-pen"></i>
          </button>
        </div>
        <div class="card-body">
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


          <ul class="list-unstyled mb-0 small">
            <li class="mb-2"><strong>Tên KH:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></li>
            <li class="mb-2"><strong>SĐT KH:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></li>
            <li class="mb-2">
  <strong>Địa chỉ:</strong>
  <?php echo htmlspecialchars($addressDisplay, ENT_QUOTES, 'UTF-8'); ?>
</li>

            <li class="mb-2"><strong>Tên đại lý:</strong> <?php echo htmlspecialchars($order['agency_name']); ?></li>
            <li class="mb-2"><strong>SĐT đại lý:</strong> <?php echo htmlspecialchars($order['agency_phone']); ?></li>
            <li class="mb-2"><strong>Ngày đặt hàng:</strong> <?php echo date("d/m/Y", strtotime($order['created_at'])); ?></li>
            <li class="mb-2"><strong>Ghi chú:</strong> <?php echo htmlspecialchars($order['note']); ?></li>
          </ul>
        </div>
      </div>

      <!-- Thông tin thanh toán -->
      <div class="card-soft">
        <div class="card-header">
          Thông tin Thanh toán
        </div>
        <div class="card-body">
          <div class="d-flex flex-column gap-2">
            <div>
              <div class="text-muted small">Tổng giá trị đơn hàng</div>
              <div class="fs-5 fw-bold text-primary"><?php echo number_format($order['total_price']); ?> VND</div>
            </div>
            <div>
              <div class="text-muted small">Phương thức thanh toán</div>
              <div class="fw-semibold">
                <?php echo htmlspecialchars($order['payment_method'] ?? "Chưa xác định"); ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Voucher + Tổng cộng (động) – hiển thị đồng bộ với bảng -->
      <div class="card-soft">
        <div class="card-header">
          Tổng kết
        </div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Mã giảm giá:</strong>
            <div>
              <?php if (!empty($order['discount_code'])): ?>
                Voucher <?php echo number_format($order['discount_code']); ?> VNĐ
              <?php else: ?>
                <span class="text-muted">Không</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <strong>Tổng tiền cần thanh toán:</strong>
            <span id="totalPrice" class="fs-5 text-primary fw-bold">0 VND</span>
          </div>
        </div>
      </div>

      <a href="admin.php" class="btn btn-outline-secondary w-100">
        <i class="fa-solid fa-list me-1"></i> Quay lại danh sách Đơn hàng
      </a>
    </section>

    <!-- Pane Right: Products & Warranty (scrollable panel) -->
    <section class="pane pane-right">
      <div class="panel-scroll">
        <div class="panel-header">
          <h6 class="mb-0">II. Sản phẩm trong Đơn hàng</h6>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
              <i class="fa-solid fa-plus me-1"></i> Thêm Sản phẩm
            </button>
          </div>
        </div>

        <div class="panel-body">
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle text-center table-sticky mb-0">
              <thead>
                <tr>
                  <th style="min-width:240px">Tên Sản phẩm</th>
                  <th style="min-width:90px">Số lượng</th>
                  <th style="min-width:120px">Đơn giá</th>
                  <th style="min-width:160px">Giá bù tiền đổi quà</th>
                  <th style="min-width:115px">Khuyến mãi</th>
                  <th style="min-width:140px">Thành tiền</th>
                  <th colspan="2" style="min-width:140px">Hành động</th>
                </tr>
              </thead>
              <tbody id="productTableBody">
              <?php
                $total_price = 0;
                // reset con trỏ
                $products_query->data_seek(0);
                while ($product = $products_query->fetch_assoc()) :
                    $subtotal = ($product['quantity'] * $product['actual_price']) + $product['price_difference'];
                    if ($product['is_promotion']) {
                        $subtotal -= $product['voucher_discount'];
                    }
                    $total_price += $subtotal;
              ?>
                <tr data-product-id="<?php echo $product['id']; ?>">
                  <td class="text-start"><?php echo htmlspecialchars($product['product_name']); ?></td>
                  <td style="width:110px">
                    <input type="text" class="form-control form-control-sm quantity text-center" value="<?php echo $product['quantity']; ?>" readonly>
                  </td>
                  <td>
                    <?php if ($product['is_promotion']) : ?>
                      <del class="text-danger"><?php echo number_format($product['actual_price']); ?> VND</del>
                    <?php else : ?>
                      <?php echo number_format($product['actual_price']); ?> VND
                    <?php endif; ?>
                  </td>
                  <td><?php echo number_format($product['price_difference']); ?> VND</td>
                  <td>
                    <span class="badge <?php echo $product['is_promotion'] ? 'bg-success' : 'bg-secondary'; ?>">
                      <?php echo $product['is_promotion'] ? "Có" : "Không"; ?>
                    </span>
                  </td>
                  <td class="subtotal">
                    <?php if ($product['is_promotion']) : ?>
                      <del class="text-danger"><?php echo number_format($subtotal); ?> VND</del>
                    <?php else : ?>
                      <?php echo number_format($subtotal); ?> VND
                    <?php endif; ?>
                  </td>
                  <td>
                    <button class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo $product['id']; ?>)">
                      <i class="fa-solid fa-pen-to-square"></i> Sửa
                    </button>
                  </td>
                  <td>
                    <a href="https://kuchenvietnam.vn/kuchen/khokuchen/delete_product.php?id=<?php echo $product['id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('bạn có chắc chắn xóa sản phẩm này khỏi đơn hàng?')">
                       <i class="fa-solid fa-trash"></i> Xóa
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel-footer">
          <div class="small text-muted">
            * Thay đổi số lượng/khuyến mãi (nếu có) sẽ tự động cập nhật tổng tiền. Bạn có thể thêm sản phẩm bằng nút bên trên.
          </div>
        </div>
      </div>

      <!-- Bảo hành -->
      <div class="panel-scroll mt-3">
        <div class="panel-header">
          <h6 class="mb-0">III. Thông tin Bảo hành</h6>
        </div>
        <div class="panel-body" style="height: 62vh; overflow-y: auto; overflow-x: hidden;">
          <?php /* giữ nguyên form & tên field để không đổi logic */ ?>
          <form method="POST" action="process_scan_admin.php">
            <input type="hidden" name="order_code" value="<?php echo $order['order_code2']; ?>">
            <div class="table-responsive">
              <table class="table table-sm table-bordered table-hover align-middle text-center mb-0 table-sticky">
                <thead>
                  <tr>
                    <th class="text-start">Tên Sản phẩm</th>
                    <th>Mã Bảo hành</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  // Render lại từ đầu cho bảng bảo hành
                  $products_query->data_seek(0);
                  while ($product = $products_query->fetch_assoc()) :
                      $warranty_codes = $warranties[$product['id']] ?? [];
                      for ($i = 0; $i < $product['quantity']; $i++) :
                          $existingCode = $warranty_codes[$i] ?? '';
                ?>
                  <tr>
                    <td class="text-start align-middle">
                      <?php echo htmlspecialchars($product['product_name']); ?>
                    </td>
                    <td class="align-middle">
                      <div class="d-flex justify-content-center align-items-center">
                        <div class="sn-display me-2 <?php echo $existingCode ? '' : 'text-muted'; ?>">
                          <?php if ($existingCode) : ?>
                            <span class="badge bg-success"><?php echo htmlspecialchars($existingCode); ?></span>
                          <?php else: ?>
                            Không có
                          <?php endif; ?>
                        </div>
                        <div class="sn-input input-group input-group-sm me-2 d-none">
                          <input type="text" name="sn[<?php echo $product['id']; ?>][]" class="form-control" placeholder="Nhập SN" value="<?php echo htmlspecialchars($existingCode); ?>" required>
                          <button type="button" class="btn btn-outline-secondary cancel-sn">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        </div>
                        <button type="button" class="btn btn-link btn-sm edit-sn" title="Chỉnh sửa SN">
                          <i class="bi bi-pencil-square"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endfor; endwhile; ?>
                </tbody>
              </table>
            </div>
            <div class="text-end mt-3">
              <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk me-1"></i> Lưu SN
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>
  </main>

  <!-- Footer (nhẹ) -->
  <footer class="py-2 text-center small text-muted">
    © <?php echo date('Y'); ?> Kuchen – Order Detail
  </footer>
</div>

<!-- Toast success (nếu có) -->
<div class="toast-container">
  <?php
  if (isset($_SESSION['success_message'])) {
      echo '<div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex">
                <div class="toast-body">'.htmlspecialchars($_SESSION['success_message']).'</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
              </div>
            </div>';
      unset($_SESSION['success_message']);
  }
  ?>
</div>

<!-- Modals: Add Product / Edit Product / Edit Customer -->
<!-- Modal Thêm Sản phẩm -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="addProductModalLabel">Thêm Sản phẩm mới</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="addProductForm" method="POST">
          <input type="hidden" id="orderId" name="orderId" value="<?php echo $order_id;?>">
          <div class="mb-3">
            <label for="searchProductName" class="form-label">Tên sản phẩm</label>
            <input type="text" class="form-control" id="searchProductName" placeholder="Tìm kiếm sản phẩm..." onkeyup="searchProduct()">
            <select class="form-select mt-2" id="newProductName" name="newProductName" required onchange="updatePrice()"></select>
          </div>
          <div class="mb-3">
            <label for="newQuantity" class="form-label">Số lượng</label>
            <input type="number" class="form-control" id="newQuantity" name="newQuantity" required min="1">
          </div>
          <div class="mb-3">
            <label for="newPrice" class="form-label">Đơn giá</label>
            <input type="number" class="form-control" id="newPrice" name="newPrice" readonly>
          </div>
          <div class="mb-3">
            <label for="newPriceDifference" class="form-label">Giá bù tiền đổi quà</label>
            <input type="number" class="form-control" id="newPriceDifference" name="newPriceDifference">
          </div>
          <div class="mb-3">
            <label class="form-label">Sản phẩm này là hàng khuyến mãi</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newIsPromotion" name="newIsPromotion">
              <label class="form-check-label" for="newIsPromotion"></label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Sản phẩm này không quét mã bảo hành</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newwarranty_scan" name="newwarranty_scan">
              <label class="form-check-label" for="newwarranty_scan"></label>
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Thêm sản phẩm</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Sửa Sản phẩm -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="editProductModalLabel">Chỉnh sửa Sản phẩm</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editProductForm" method="POST">
          <input type="hidden" id="orderId" name="orderId" value="<?php echo $order_id;?>">
          <div class="mb-3">
            <label for="productName" class="form-label">Tên sản phẩm</label>
            <input type="text" class="form-control" id="productName" name="productName" readonly>
          </div>
          <div class="mb-3">
            <label for="quantity" class="form-label">Số lượng</label>
            <input type="number" class="form-control" id="quantity" name="quantity" required>
          </div>
          <div class="mb-3">
            <label for="priceDifference" class="form-label">Giá bù tiền đổi quà</label>
            <input type="number" class="form-control" id="priceDifference" name="priceDifference">
          </div>
          <div class="mb-3">
            <label class="form-label">Sản phẩm này là hàng khuyến mãi</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newIsPromotion" name="newIsPromotion">
              <label class="form-check-label" for="newIsPromotion"></label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Sản phẩm này không quét mã bảo hành</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newwarranty_scan" name="newwarranty_scan">
              <label class="form-check-label" for="newwarranty_scan"></label>
            </div>
          </div>
          <input type="hidden" id="productId" name="productId">
          <input type="hidden" id="initialQuantity" name="initialQuantity">
          <button type="submit" class="btn btn-primary">Cập nhật</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Chỉnh sửa thông tin KH -->
<!-- Modal Chỉnh sửa thông tin KH -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="editModalLabel">Chỉnh sửa Thông tin Khách hàng</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="update_thongtinkhach.php">
        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_id); ?>">

        <div class="modal-body">
          <!-- Loại đơn hàng -->
          <div class="mb-3">
            <label for="orderType" class="form-label">Loại đơn hàng</label>
            <select name="order_type" id="orderType" class="form-select"
                    data-original-code1="<?php echo htmlspecialchars($order['order_code1']); ?>">
              <option value="droppii" <?php echo ($order['type'] ?? '') === 'droppii' ? 'selected' : ''; ?>>
                Droppii Viettel Post
              </option>
              <option value="droppii_ghtk" <?php echo ($order['type'] ?? '') === 'droppii_ghtk' ? 'selected' : ''; ?>>
                Droppii Giao hàng tiết kiệm
              </option>
              <option value="outside" <?php echo ($order['type'] ?? '') === 'outside' ? 'selected' : ''; ?>>
                Droppii qua kho
              </option>
            </select>
            <div class="form-text">
              Chọn “Droppii qua kho” sẽ tự điền Mã ĐVVC là “Khách nhận hàng tại kho Droppii”.
            </div>
          </div>

          <!-- Mã ĐVVC (luôn được phép sửa, không phân biệt tài khoản) -->
          <div class="mb-3">
            <label for="order_code1" class="form-label">Mã ĐVVC</label>
            <input type="text" name="order_code1" id="order_code1" class="form-control"
                   value="<?php
                     $val = ($order['type'] ?? '') === 'outside'
                       ? 'Khách nhận hàng tại kho Droppii'
                       : ($order['order_code1'] ?? '');
                     echo htmlspecialchars($val);
                   ?>">
          </div>

          <!-- Mã đơn hàng (luôn được phép sửa) -->
          <div class="mb-3">
            <label for="order_code2" class="form-label">Mã đơn hàng</label>
            <input type="text" name="order_code2" id="order_code2" class="form-control"
                   value="<?php echo htmlspecialchars($order['order_code2']); ?>">
          </div>

          <!-- Các thông tin khách hàng khác -->
          <div class="mb-3">
            <label for="customerName" class="form-label">Tên Khách hàng</label>
            <input type="text" name="customer_name" id="customerName" class="form-control"
                   value="<?php echo htmlspecialchars($order['customer_name']); ?>">
          </div>
          <div class="mb-3">
            <label for="customerPhone" class="form-label">Số điện thoại KH</label>
            <input type="text" name="customer_phone" id="customerPhone" class="form-control"
                   value="<?php echo htmlspecialchars($order['customer_phone']); ?>">
          </div>
          <div class="mb-3">
            <label for="customerAddress" class="form-label">Địa chỉ</label>
            <input type="text" name="customer_address" id="customerAddress" class="form-control"
                   value="<?php echo htmlspecialchars($order['customer_address']); ?>">
          </div>
          <div class="mb-3">
            <label for="agencyName" class="form-label">Tên đại lý</label>
            <input type="text" name="agency_name" id="agencyName" class="form-control"
                   value="<?php echo htmlspecialchars($order['agency_name']); ?>">
          </div>
          <div class="mb-3">
            <label for="agencyPhone" class="form-label">SĐT đại lý</label>
            <input type="text" name="agency_phone" id="agencyPhone" class="form-control"
                   value="<?php echo htmlspecialchars($order['agency_phone']); ?>">
          </div>
          <div class="mb-3">
            <label for="noteAdmin" class="form-label">Ghi chú dành cho admin</label>
            <input type="text" name="note_admin" id="noteAdmin" class="form-control"
                   value="<?php echo htmlspecialchars($order['note_admin']); ?>">
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
        </div>
      </form>

    </div>
  </div>
</div>


<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===== Auto-điền Mã ĐVVC theo Loại đơn hàng =====
  document.addEventListener('DOMContentLoaded', function () {
    const typeSel = document.getElementById('orderType');
    const code1   = document.getElementById('order_code1');
    if (!typeSel || !code1) return;

    const ORIGINAL = typeSel.dataset.originalCode1 || '';

    function syncCarrierCode() {
      if (typeSel.value === 'outside') {
        code1.value = 'Khách nhận hàng tại kho Droppii';
        code1.readOnly = false; // cho phép chỉnh tiếp nếu cần
      } else {
        // “tải sẵn Mã ĐVVC đã có của đơn”
        code1.value = ORIGINAL;
        code1.readOnly = false;
      }
    }

    // Lần đầu mở modal
    syncCarrierCode();
    // Khi đổi loại
    typeSel.addEventListener('change', syncCarrierCode);
  });
/* ========= Giữ nguyên logic tính tổng & cập nhật ========= */
$(document).ready(function() {
  function recalculateTotal() {
    let totalPrice = 0;
    $('#productTableBody tr').each(function() {
      const quantity = $(this).find('.quantity').val();
      const price = parseFloat($(this).find('td:eq(2)').text().replace(/[^0-9.-]+/g, "")) || 0;
      const priceDifference = parseFloat($(this).find('td:eq(3)').text().replace(/[^0-9.-]+/g, "")) || 0;
      const isPromotion = $(this).find('span.badge').hasClass('bg-success');
      const voucherDiscount = isPromotion ? parseFloat($(this).find('td:eq(5) del').text().replace(/[^0-9.-]+/g, "")) || 0 : 0;

      let subtotal = (quantity * price) + priceDifference - voucherDiscount;
      $(this).find('.subtotal').text(subtotal.toLocaleString() + ' VND');
      totalPrice += subtotal;
    });

    const orderDiscountCode = parseFloat('<?php echo !empty($order["discount_code"]) ? $order["discount_code"] : 0; ?>') || 0;
    totalPrice -= orderDiscountCode;

    $('#totalPrice').text(totalPrice.toLocaleString() + ' VND');

    updateOrderTotalPrice(totalPrice);
  }

  function updateOrderTotalPrice(totalPrice) {
    $.ajax({
      url: 'update_order_total.php',
      type: 'POST',
      data: {
        orderId: '<?php echo $order_id; ?>',
        totalPrice: totalPrice
      },
      success: function(response) {
        try {
          const res = (typeof response === 'string') ? JSON.parse(response) : response;
          if (!res.success) {
            console.error('Error updating total price:', res.message);
          }
        } catch(e){
          console.log('Raw response:', response);
        }
      },
      error: function(xhr, status, error) {
        console.error('AJAX request failed:', error);
      }
    });
  }

  $('.quantity').on('input', function() {
    recalculateTotal();
  });

  recalculateTotal();
});

/* ========= Logic modal Sửa sản phẩm (giữ nguyên) ========= */
function openEditModal(productId) {
  fetch('get_product_data.php?id=' + productId)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        document.getElementById('productName').value = data.product.product_name;
        document.getElementById('quantity').value = data.product.quantity;
        document.getElementById('priceDifference').value = data.product.price_difference;
        document.getElementById('productId').value = data.product.id;
        document.getElementById('initialQuantity').value = data.product.quantity;
        new bootstrap.Modal(document.getElementById('editProductModal')).show();
      } else {
        alert('Không thể tải dữ liệu sản phẩm');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Có lỗi xảy ra khi tải dữ liệu sản phẩm');
    });
}
document.getElementById('editProductForm').addEventListener('submit', function(event) {
  event.preventDefault();
  const formData = new FormData(this);
  fetch('update_product.php', { method: 'POST', body: formData })
    .then(response => response.text())
    .then(data => {
      if (data.includes("Lỗi")) {
        alert('Có lỗi xảy ra: ' + data);
      } else {
        alert('Sản phẩm đã được cập nhật thành công');
        window.location.href = 'order_detail.php?id=' + document.getElementById('orderId').value;
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Có lỗi xảy ra khi xử lý yêu cầu');
    });
});

/* ========= Add Product modal: giữ nguyên API & hành vi ========= */
let products = [];
fetch('get_products.php')
  .then(response => response.json())
  .then(data => {
    if (data.length > 0) {
      products = data;
      populateProductDropdown(products);
      updatePrice();
    }
  })
  .catch(error => console.error('Error:', error));

function populateProductDropdown(products) {
  const productSelect = document.getElementById('newProductName');
  productSelect.innerHTML = '';
  products.forEach(product => {
    const option = document.createElement('option');
    option.value = product.product_name;
    option.text = `${product.product_name}`;
    option.dataset.price = product.price;
    productSelect.add(option);
  });
}
function searchProduct() {
  const searchValue = document.getElementById('searchProductName').value.toLowerCase();
  const filtered = products.filter(p => p.product_name.toLowerCase().includes(searchValue));
  populateProductDropdown(filtered);
  updatePrice();
}
function getProductPrice(productId){ return 100; } // placeholder giữ nguyên như cũ
function updatePrice() {
  const selectedOption = document.getElementById('newProductName').selectedOptions[0];
  document.getElementById('newPrice').value = selectedOption ? selectedOption.dataset.price : '';
}
document.getElementById('addProductForm').addEventListener('submit', function(event) {
  event.preventDefault();
  const formData = new FormData(this);
  fetch('add_product.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('Sản phẩm mới đã được thêm. Vui lòng thực hiện quét mã bảo hành bổ sung cho đơn hàng này!');
        bootstrap.Modal.getInstance(document.getElementById('addProductModal')).hide();
        window.location.href = 'order_detail.php?id=' + document.getElementById('orderId').value;
      } else {
        alert('Lỗi thêm sản phẩm mới');
      }
    })
    .catch(error => console.error('Error:', error));
});
$(document).ready(function() {
  $('#newIsPromotion').change(function() {
    if ($(this).is(':checked')) {
      $('#newPrice').val(0);
    } else {
      updatePrice();
    }
  });
  $('#newQuantity').on('input', function() {
    if ($('#newIsPromotion').is(':checked')) {
      $('#newPrice').val(0);
    } else {
      updatePrice();
    }
  });
});

/* ========= UX cho bảng bảo hành (giữ nguyên logic field) ========= */
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form[action="process_scan_admin.php"]');
  if (!form) return;

  form.addEventListener('submit', function(){
    form.querySelectorAll('.sn-input.d-none input').forEach(input => {
      input.disabled = true;
      input.removeAttribute('required');
    });
  });

  document.querySelectorAll('.edit-sn').forEach(btn => {
    btn.addEventListener('click', function() {
      const cell = this.closest('td');
      const group = cell.querySelector('.sn-input');
      const inp   = group.querySelector('input');
      cell.querySelector('.sn-display').classList.add('d-none');
      group.classList.remove('d-none');
      inp.disabled = false;
      inp.required = true;
      inp.focus();
    });
  });
  document.querySelectorAll('.cancel-sn').forEach(btn => {
    btn.addEventListener('click', function() {
      const cell = this.closest('td');
      const group = cell.querySelector('.sn-input');
      const inp   = group.querySelector('input');
      group.classList.add('d-none');
      cell.querySelector('.sn-display').classList.remove('d-none');
      inp.value = '';
      inp.disabled = true;
      inp.required = false;
    });
  });
  document.querySelectorAll('.sn-input').forEach(group => {
    const inp = group.querySelector('input');
    group.classList.add('d-none');
    inp.disabled = true;
    inp.required = false;
  });
});
</script>

<?php
// Đóng kết nối
$conn->close();
?>
</body>
</html>
