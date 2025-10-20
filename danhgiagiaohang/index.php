<?php
// Kết nối database
include'../config.php';
// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Kiểm tra tham số đơn hàng trên URL
if (!isset($_GET['id'])) {
    die("Mã hóa đơn hàng không được khởi tạo đúng.");
}

$orderId = $_GET['id'];
$nv = isset($_GET['nv']) ? $_GET['nv'] : "1";

// Truy vấn lấy order_code2 từ bảng orders
$sql = "SELECT order_code2 FROM orders WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $orderCode2 = $row['order_code2'];
} else {
    die("Không tìm thấy đơn hàng với ID: " . htmlspecialchars($orderId));
}
$stmt->close();

// Truy vấn lấy thông tin nhân viên giao hàng từ bảng users
$sql = "SELECT full_name,sdt FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $nv);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $nvName = $row['full_name'];
    $nvPhone = $row['sdt'];
} else {
    $nvName = "Không xác định";
    $nvPhone = "N/A";
}

// Đóng kết nối
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Đánh giá đơn hàng</title>
  <!-- Bootstrap 4 CSS -->
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 5 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <style>
    /* Reset margin, padding cho body */
    body {
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
      background-color: #f7f7f7;
    }
    
    /* Header */
    .header {
      background-color: orange;
      color: white;
      padding: 15px 20px;
      position: relative;
      text-align: center;
    }
    .header .logo {
      max-height: 50px;
      width: auto;
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
    
    /* Nội dung chính */
    .main-content {
      padding: 20px;
      padding-bottom: 100px; /* tạo khoảng cách cho footer cố định */
    }
    .section-title {
      text-align: center;
      margin-bottom: 20px;
      font-size: 18px;
      font-weight: 500;
    }
    
    /* Form đánh giá */
    .criteria-row {
      border-bottom: 1px solid #ddd;
      padding: 10px 0;
      align-items: center;
      cursor: pointer;
    }
    .criteria-label {
      font-size: 16px;
      font-weight: bold;
    }
    .icon-set {
      display: flex;
      justify-content: space-around;
    }
    /* Các icon và text bên trong icon-set */
    .icon-set i, .icon-set span {
      font-size: 20px;
      cursor: pointer;
      transition: transform 0.2s, background-color 0.2s;
      padding: 5px;
      border-radius: 5px;
    }
    .icon-set i:hover, .icon-set span:hover {
      transform: scale(1.2);
    }
    /* Lớp hiệu ứng chọn: nền màu cam nhạt */
    .selected-icon {
      background-color: #ffd699;
    }
    
    /* Footer */
    .footer {
      background-color: orange;
      color: white;
      text-align: center;
      padding: 10px 0;
      position: fixed;
      left: 0;
      bottom: 0;
      width: 100%;
      font-size: 14px;
    }
    
    /* Responsive: hiển thị theo cột khi màn hình nhỏ */
    @media (max-width: 576px) {
      .criteria-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
      }
      .icon-set {
        width: 100%;
        justify-content: space-around;
        margin-top: 10px;
      }
      .criteria-label {
        font-size: 15px;
      }
      .icon-set i, .icon-set span {
        font-size: 26px;
      }
      .header {
        position: static;
        padding: 15px;
      }
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
    <h1 class="title">ĐÁNH GIÁ DỊCH VỤ ĐƠN HÀNG MÃ SỐ: <?php echo htmlspecialchars($orderCode2); ?></h1>
  </header>
  
  <!-- Nội dung chính -->
  <main class="main-content container">
    <h5 class="section-title">Phiếu đánh giá sự hài lòng của khách hàng đối với nhân viên giao hàng</h5>
    <!-- Form gửi dữ liệu qua POST; truyền mã đơn qua URL -->
    <form method="post" action="save_evaluation.php?id=<?php echo urlencode($orderId); ?>&nv=<?php echo urlencode($nv); ?>">
      
      <!-- Tiêu chí 1: Thái độ và sự chuyên nghiệp -->
      <div class="row criteria-row" data-target="#criteria1">
        <div class="col-12 col-md-8">
          <span class="criteria-label">
            <a class="d-block" data-toggle="collapse" href="#criteria1" role="button" 
               aria-expanded="false" aria-controls="criteria1" style="color: inherit; text-decoration: none;">
              👉 Thái độ và sự chuyên nghiệp của nhân viên với bạn (nhấn để xem tiêu chí)
            </a>
          </span>
          <div class="collapse" id="criteria1">
            <ul class="list-unstyled mt-2">
              <li>- Nhân viên có nở nụ cười và thể hiện thái độ thân thiện khi giao hàng.</li>
              <li>- Đáp ứng các yêu cầu của khách hàng một cách nhanh chóng và nhiệt tình.</li>
              <li>- Giữ thái độ kiên nhẫn, lịch sự trong các tình huống khó khăn hoặc căng thẳng.</li>
            </ul>
          </div>
          <!-- Hidden input để lưu giá trị đánh giá cho tiêu chí 1 -->
          <input type="hidden" name="tc1" id="tc1" value="">
        </div>
        <div class="col-12 col-md-4">
          <div class="icon-set text-center">
            <!-- Thêm đối số "this" vào hàm setRating -->
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc1','Hài lòng', this);">
              <span style="font-size: 24px;">😍</span><br>
              <span>Hài lòng</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc1','Bình thường', this);">
              <span style="font-size: 24px;">🙂</span><br>
              <span>Bình thường</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc1','Không hài lòng', this);">
              <span style="font-size: 24px;">😞</span><br>
              <span>Không hài lòng</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tiêu chí 2: Đúng giờ và tuân thủ thời gian giao hàng -->
      <div class="row criteria-row" data-target="#criteria2">
        <div class="col-12 col-md-8">
          <span class="criteria-label">
            <a class="d-block" data-toggle="collapse" href="#criteria2" role="button" 
               aria-expanded="false" aria-controls="criteria2" style="color: inherit; text-decoration: none;">
              👉 Đúng giờ và tuân thủ thời gian giao hàng (nhấn để xem tiêu chí)
            </a>
          </span>
          <div class="collapse" id="criteria2">
            <ul class="list-unstyled mt-2">
              <li>- Nhân viên giao hàng đến đúng thời gian đã thông báo.</li>
              <li>- Nếu có sự cố về thời gian giao hàng (như giao trễ), nhân viên có thông báo cho khách hàng trước không?</li>
            </ul>
          </div>
          <input type="hidden" name="tc2" id="tc2" value="">
        </div>
        <div class="col-12 col-md-4">
          <div class="icon-set text-center">
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc2','Hài lòng', this);">
              <span style="font-size: 24px;">😍</span><br>
              <span>Hài lòng</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc2','Bình thường', this);">
              <span style="font-size: 24px;">🙂</span><br>
              <span>Bình thường</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc2','Không hài lòng', this);">
              <span style="font-size: 24px;">😞</span><br>
              <span>Không hài lòng</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tiêu chí 3: Cách thức giao hàng và bảo quản hàng hóa -->
      <div class="row criteria-row" data-target="#criteria3">
        <div class="col-12 col-md-8">
          <span class="criteria-label">
            <a class="d-block" data-toggle="collapse" href="#criteria3" role="button" 
               aria-expanded="false" aria-controls="criteria3" style="color: inherit; text-decoration: none;">
              👉 Cách thức giao hàng và bảo quản hàng hóa (nhấn để xem tiêu chí)
            </a>
          </span>
          <div class="collapse" id="criteria3">
            <ul class="list-unstyled mt-2">
              <li>- Hàng hóa được giao nguyên vẹn, không bị hư hại, móp méo hoặc vỡ.</li>
              <li>- Hàng hóa được đóng gói, bọc che chắn, cột chắc chắn trên giá giao hàng khi giao đến, v.v.</li>
              <li>- Cách thức giao hàng có nhanh chóng, chính xác không (không làm khách hàng phải đợi lâu).</li>
            </ul>
          </div>
          <input type="hidden" name="tc3" id="tc3" value="">
        </div>
        <div class="col-12 col-md-4">
          <div class="icon-set text-center">
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc3','Hài lòng', this);">
              <span style="font-size: 24px;">😍</span><br>
              <span>Hài lòng</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc3','Bình thường', this);">
              <span style="font-size: 24px;">🙂</span><br>
              <span>Bình thường</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc3','Không hài lòng', this);">
              <span style="font-size: 24px;">😞</span><br>
              <span>Không hài lòng</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tiêu chí 4: Đảm bảo sự an toàn của hàng hóa -->
      <div class="row criteria-row" data-target="#criteria4">
        <div class="col-12 col-md-8">
          <span class="criteria-label">
            <a class="d-block" data-toggle="collapse" href="#criteria4" role="button" 
               aria-expanded="false" aria-controls="criteria4" style="color: inherit; text-decoration: none;">
              👉 Đảm bảo sự an toàn của hàng hóa (nhấn để xem tiêu chí)
            </a>
          </span>
          <div class="collapse" id="criteria4">
            <ul class="list-unstyled mt-2">
              <li>- Hàng hóa được giao đến tay khách không bị thiếu, mất hoặc bị mở hàng trước trong suốt quá trình giao.</li>
            </ul>
          </div>
          <input type="hidden" name="tc4" id="tc4" value="">
        </div>
        <div class="col-12 col-md-4">
          <div class="icon-set text-center">
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc4','Hài lòng', this);">
              <span style="font-size: 24px;">😍</span><br>
              <span>Hài lòng</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc4','Bình thường', this);">
              <span style="font-size: 24px;">🙂</span><br>
              <span>Bình thường</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc4','Không hài lòng', this);">
              <span style="font-size: 24px;">😞</span><br>
              <span>Không hài lòng</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tiêu chí 5: Sự minh bạch và rõ ràng trong giao tiếp -->
      <div class="row criteria-row" data-target="#criteria5">
        <div class="col-12 col-md-8">
          <span class="criteria-label">
            <a class="d-block" data-toggle="collapse" href="#criteria5" role="button" 
               aria-expanded="false" aria-controls="criteria5" style="color: inherit; text-decoration: none;">
              👉 Sự minh bạch và rõ ràng trong giao tiếp của nhân viên với bạn (nhấn để xem tiêu chí)
            </a>
          </span>
          <div class="collapse" id="criteria5">
            <ul class="list-unstyled mt-2">
              <li>- Nhân viên giao hàng có cung cấp thông tin đầy đủ về đơn hàng cho khách (mã đơn hàng, tên sản phẩm, số tiền thu hộ nếu có, v.v.).</li>
              <li>- Giải thích rõ ràng và minh bạch về các yêu cầu của khách hàng (VD: Yêu cầu ký xác nhận giao hàng, trả tiền, v.v.).</li>
              <li>- Nhân viên giải quyết các vấn đề phát sinh một cách hiệu quả.</li>
            </ul>
          </div>
          <input type="hidden" name="tc5" id="tc5" value="">
        </div>
        <div class="col-12 col-md-4">
          <div class="icon-set text-center">
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc5','Hài lòng', this);">
              <span style="font-size: 24px;">😍</span><br>
              <span>Hài lòng</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc5','Bình thường', this);">
              <span style="font-size: 24px;">🙂</span><br>
              <span>Bình thường</span>
            </div>
            <div style="display: inline-block; margin: 10px;" onclick="setRating('tc5','Không hài lòng', this);">
              <span style="font-size: 24px;">😞</span><br>
              <span>Không hài lòng</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Ô đóng góp ý kiến chung -->
      <div class="form-group mt-4">
        <label for="userFeedback"><strong>Thêm ý kiến góp ý cho KUCHEN:</strong></label>
        <textarea class="form-control" id="userFeedback" name="userFeedback" rows="4" placeholder="Nhập ý kiến đóng góp của bạn tại đây..."></textarea>
      </div>
      
      <div class="text-center mt-4">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Gửi đánh giá</button>
      </div>
    </form>
  </main>
  
  <!-- Footer -->
  <footer class="footer">
    <h4>Nhân viên giao hàng: <?php echo htmlspecialchars($nvName); ?> - SĐT: <?php echo htmlspecialchars($nvPhone); ?></h4>
</footer>
  
  <!-- jQuery, Popper.js và Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
    // Khi click vào bất kỳ vị trí nào trong criteria-row (trừ khu vực icon), toggle collapse của phần mô tả
    document.querySelectorAll('.criteria-row').forEach(function(row) {
      row.addEventListener('click', function(e) {
        if (e.target.closest('.icon-set')) return;
        var collapseEl = row.querySelector('.collapse');
        if(collapseEl){
          $(collapseEl).collapse('toggle');
        }
      });
    });
    
    // Hàm setRating: cập nhật giá trị cho input hidden và thêm hiệu ứng nổi màu cam nhạt cho icon được chọn
    function setRating(field, value, element) {
      document.getElementById(field).value = value;
      // Xóa lớp selected-icon của tất cả icon trong cùng nhóm
      var container = element.parentNode;
      Array.prototype.forEach.call(container.children, function(child) {
        child.classList.remove("selected-icon");
      });
      // Thêm lớp selected-icon cho icon được click
      element.classList.add("selected-icon");
    }
  </script>
</body>
</html>
