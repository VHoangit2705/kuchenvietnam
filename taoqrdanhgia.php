<?php
session_start();
include 'config.php'; // File cấu hình kết nối CSDL; biến $conn được khởi tạo ở đây

// Kiểm tra biến session chứa tên nhân viên (full_name)
if (!isset($_SESSION['full_name'])) {
    // Nếu chưa có, bạn có thể gán giá trị mặc định hoặc chuyển hướng đến trang đăng nhập
    $_SESSION['full_name'] = "admin";
}

$full_name = $_SESSION['full_name'];

// Truy vấn bảng users dựa trên full_name để lấy id và vị trí của nhân viên
$sql_user = "SELECT id, position FROM users WHERE full_name = ?";
$stmt_user = mysqli_prepare($conn, $sql_user);
if (!$stmt_user) {
    die("Prepare failed (users): " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt_user, "s", $full_name);
mysqli_stmt_execute($stmt_user);
$result_user = mysqli_stmt_get_result($stmt_user);

if ($row_user = mysqli_fetch_assoc($result_user)) {
    $nv = $row_user['id']; // Lấy id nhân viên
    $userPosition = $row_user['position']; // Lấy vị trí của người dùng
    // Kiểm tra nếu vị trí của người dùng không phải "Kho hàng Vinh", thì không cho phép truy cập
    if ($userPosition !== "Kho hàng Vinh") {
        die("Bạn không có quyền truy cập trang này.");
    }
} else {
    // Nếu không tìm thấy người dùng, gán $nv = 0 hoặc xử lý theo yêu cầu nghiệp vụ
    die("Không tìm thấy thông tin người dùng.");
}
mysqli_stmt_close($stmt_user);

// Xử lý tìm kiếm (nếu có)
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
    $searchTerm = "%" . $search . "%";
    $sql = "SELECT * FROM orders 
            WHERE status = 'Đã quét QR' 
              AND zone = 'Đơn hàng Vinh' 
              AND (type = 'outside' OR type = 'warehouse_branch')
              AND (order_code2 LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)
            ORDER BY id DESC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Prepare failed (orders with search): " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "sss", $searchTerm, $searchTerm, $searchTerm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $sql = "SELECT * FROM orders 
            WHERE status = 'Đã quét QR' 
              AND zone = 'Đơn hàng Vinh' 
              AND status_tracking = 'Đang giao hàng'
            ORDER BY id DESC";
    $result = mysqli_query($conn, $sql);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tạo QR đánh giá đơn hàng</title>
  <!-- Bootstrap 4 CSS -->
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 5 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <style>
    body {
      background-color: #f7f7f7;
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
    }
    .header {
      background-color: orange;
      color: white;
      padding: 15px 20px;
      text-align: center;
      position: relative;
    }
    .header .logo {
      max-height: 50px;
      position: absolute;
      left: 20px;
      top: 50%;
      transform: translateY(-50%);
    }
    .header .title {
      font-size: 22px;
      font-weight: bold;
      margin: 0;
      line-height: 1.2;
    }
    .search-box {
      margin: 20px 0;
    }
    /* Card item */
    .order-card {
      border: 1px solid #ddd;
      border-radius: 5px;
      background: white;
      padding: 15px;
      margin-bottom: 20px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      transition: transform 0.2s;
    }
    .order-card:hover {
      transform: translateY(-5px);
    }
    .order-info {
      margin-bottom: 10px;
    }
    .order-info strong {
      display: block;
      font-size: 16px;
    }
    .btn-create {
      width: 100%;
    }
    /* Modal style: center image */
    .modal-body {
      text-align: center;
    }
    /* Responsive adjustments */
    @media (max-width: 576px) {
      .header .logo {
        position: static;
        transform: none;
        margin-bottom: 10px;
      }
      .header .title {
        text-align: center;
      }
    }
  </style>
</head>
<body>
  
  <!-- Header -->
  <header class="header">
    <img src="logokuchen.png" alt="Logo Công ty" class="logo">
    <h1 class="title">TẠO PHIẾU ĐÁNH GIÁ CHO KHÁCH HÀNG</h1>
  </header>
  
  <div class="container mt-4">
    <!-- Search Form -->
    <div class="search-box">
      <form method="get" action="taoqrdanhgia.php">
        <div class="input-group">
          <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo mã đơn, tên khách, SĐT..." value="<?php echo htmlspecialchars($search); ?>">
          <div class="input-group-append">
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Tìm kiếm</button>
          </div>
        </div>
      </form>
    </div>
    
    <!-- Danh sách đơn hàng -->
    <div class="row">
<?php
// Giả sử bạn đã có kết nối CSDL trong biến $conn

// Truy vấn các đơn hàng đã được đánh giá từ bảng feedbacks (lấy các order_id và delivery_person_id)
$sqlFeedback = "SELECT order_id, delivery_person_id FROM feedbacks";
$resultFeedback = mysqli_query($conn, $sqlFeedback);
$feedback_delivery = array();
if ($resultFeedback) {
    while ($fb = mysqli_fetch_assoc($resultFeedback)) {
        // Tạo mảng ánh xạ: key = order_id, value = delivery_person_id
        $feedback_delivery[$fb['order_id']] = $fb['delivery_person_id'];
    }
}

// Hiển thị danh sách đơn hàng
if ($result && mysqli_num_rows($result) > 0) {
    while ($order = mysqli_fetch_assoc($result)) {
        // Lấy các thông tin cần hiển thị
        $order_code2    = htmlspecialchars($order['order_code2']);
        $customer_name  = htmlspecialchars($order['customer_name']);
        $customer_phone = htmlspecialchars($order['customer_phone']);
        $date           = htmlspecialchars($order['created_at']);
        $formattedDate  = date("H:i d/m/Y", strtotime($date));
        $customer_address = htmlspecialchars($order['customer_address']);
        $order_id       = $order['id'];
        ?>
        <div class="col-md-4">
            <div class="order-card">
                <div class="order-info">
                    <strong>📋 Mã đơn: <?php echo $order_code2; ?></strong><br>
                    <span>👉 Tên KH: <?php echo $customer_name; ?></span><br>
                    <span>👉 SĐT: <?php echo $customer_phone; ?></span><br>
                    <span>👉 Ngày đặt hàng: <?php echo $formattedDate; ?></span><br>
                    <span>💥 Địa chỉ: <?php echo $customer_address; ?></span><br>
                    <?php
                    // Nếu đơn hàng đã được đánh giá, lấy tên NVGH từ bảng users
                    if (array_key_exists($order_id, $feedback_delivery)) {
                        $delivery_person_id = $feedback_delivery[$order_id];
                        $sqlUser = "SELECT full_name FROM users WHERE id = ?";
                        $stmtUser = mysqli_prepare($conn, $sqlUser);
                        if ($stmtUser) {
                            mysqli_stmt_bind_param($stmtUser, "i", $delivery_person_id);
                            mysqli_stmt_execute($stmtUser);
                            $resultUser = mysqli_stmt_get_result($stmtUser);
                            if ($rowUser = mysqli_fetch_assoc($resultUser)) {
                                $nvName = htmlspecialchars($rowUser['full_name']);
                            } else {
                                $nvName = "Chưa xác định";
                            }
                            mysqli_stmt_close($stmtUser);
                        } else {
                            $nvName = "Chưa xác định";
                        }
                        echo "<span>🚴 Nhân viên giao hàng: " . $nvName . "</span><br>";
                    }
                    ?>
                </div>
                <?php if (array_key_exists($order_id, $feedback_delivery)) { ?>
                    <button class="btn btn-success btn-create" disabled>Khách đã đánh giá</button>
                <?php } else { ?>
                    <button class="btn btn-primary btn-create" onclick="showQR(<?php echo $order_id; ?>)">Tạo mã QR</button>
                <?php } ?>
            </div>
        </div>
        <?php
    }
} else {
    echo "<div class='col-12'><p class='text-center'>Không có đơn hàng nào thỏa mãn điều kiện.</p></div>";
}
?>
    </div>
  </div>
  
  <!-- Modal hiển thị mã QR -->
  <div class="modal fade" id="qrModal" tabindex="-1" role="dialog" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="qrModalLabel">Hãy đưa mã QR để khách hàng quét và đánh giá dịch vụ</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <img id="qrImage" src="" style="width: 100%;" alt="QR Code" title="">
          <p class="mt-3">Quét mã QR để đánh giá đơn hàng <strong id="qrOrderId"></strong></p>
        </div>
      </div>
    </div>
  </div>
  
  <!-- jQuery, Popper.js và Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
  // Hàm hiển thị modal với mã QR được tạo động
  function showQR(orderId) {
    // Lấy tên nhân viên từ biến PHP $nv (đã được truy vấn trước đó)
    var nv = "<?php echo addslashes($nv); ?>";
    var mdh = "<?php echo addslashes($order_code2); ?>";
    // Xây dựng URL QR: thay {orderId} và nv
    var baseUrl = "https://api.qrserver.com/v1/create-qr-code/";
    var dataParam = "https://vuhoang.name.vn/kuchen/khokuchen/danhgiagiaohang/index.php?id=" + orderId + "&nv=" + encodeURIComponent(nv);
    var sizeParam = "200x200";
    var qrUrl = baseUrl + "?data=" + encodeURIComponent(dataParam) + "&size=" + sizeParam;
    
    // Cập nhật hình ảnh và text trong modal
    document.getElementById("qrImage").src = qrUrl;
    document.getElementById("qrImage").title = "Đang tạo QR cho đơn hàng " + orderId;
    document.getElementById("qrOrderId").innerText = mdh;
    
    // Mở modal sử dụng Bootstrap jQuery API
    $('#qrModal').modal('show');
    
    // Gọi AJAX để cập nhật trường IP trong bảng orders cho đơn hàng có id = orderId
    $.ajax({
      url: 'update_order_ip.php',
      method: 'POST',
      data: { orderId: orderId },
      success: function(response) {
        console.log("Order IP updated: " + response);
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.log("Error updating order IP: " + errorThrown);
      }
    });
  }
</script>

</body>
</html>
