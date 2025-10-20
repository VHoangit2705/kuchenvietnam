<?php
session_start();
include '../config.php'; // File cấu hình kết nối CSDL, biến $conn

// --- TRUY VẤN CHỈ SỐ ĐÁNH GIÁ TRUNG BÌNH ---
$sqlAvg = "SELECT AVG(rating) AS avg_rating FROM feedbacks";
$resultAvg = mysqli_query($conn, $sqlAvg);
$avgRating = 0;
if ($resultAvg && mysqli_num_rows($resultAvg) > 0) {
    $rowAvg = mysqli_fetch_assoc($resultAvg);
    $avgRating = round($rowAvg['avg_rating'], 2);
}

// --- PHÂN TRANG & TÌM KIẾM ---
// Số dòng trên mỗi trang
$pageSize = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $pageSize;

// Lấy từ khóa tìm kiếm (theo mã đơn hoặc SĐT)
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Nếu có tìm kiếm, sử dụng câu lệnh có WHERE với LIKE
if ($search !== "") {
    $searchTerm = "%" . $search . "%";
    $sqlFeedback = "SELECT f.*, o.order_code2, o.customer_name, o.customer_phone 
                    FROM feedbacks f 
                    LEFT JOIN orders o ON f.order_id = o.id
                    WHERE o.order_code2 LIKE ? OR o.customer_phone LIKE ?
                    ORDER BY f.feedback_time DESC
                    LIMIT ?, ?";
    $stmt = mysqli_prepare($conn, $sqlFeedback);
    mysqli_stmt_bind_param($stmt, "ssii", $searchTerm, $searchTerm, $offset, $pageSize);
    mysqli_stmt_execute($stmt);
    $resultFeedback = mysqli_stmt_get_result($stmt);
    
    // Lấy tổng số dòng có điều kiện tìm kiếm để tính tổng số trang
    $countSql = "SELECT COUNT(*) as total
                 FROM feedbacks f 
                 LEFT JOIN orders o ON f.order_id = o.id
                 WHERE o.order_code2 LIKE ? OR o.customer_phone LIKE ?";
    $countStmt = mysqli_prepare($conn, $countSql);
    mysqli_stmt_bind_param($countStmt, "ss", $searchTerm, $searchTerm);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $totalRows = mysqli_fetch_assoc($countResult)['total'];
    mysqli_stmt_close($countStmt);
} else {
    // Nếu không có tìm kiếm, truy vấn tất cả
    $sqlFeedback = "SELECT f.*, o.order_code2, o.customer_name, o.customer_phone 
                    FROM feedbacks f 
                    LEFT JOIN orders o ON f.order_id = o.id
                    ORDER BY f.feedback_time DESC
                    LIMIT ?, ?";
    $stmt = mysqli_prepare($conn, $sqlFeedback);
    mysqli_stmt_bind_param($stmt, "ii", $offset, $pageSize);
    mysqli_stmt_execute($stmt);
    $resultFeedback = mysqli_stmt_get_result($stmt);
    
    // Lấy tổng số dòng của bảng feedbacks (có thể join lại nếu cần)
    $countSql = "SELECT COUNT(*) as total FROM feedbacks";
    $countResult = mysqli_query($conn, $countSql);
    $totalRows = mysqli_fetch_assoc($countResult)['total'];
}
mysqli_stmt_close($stmt);

// Tính tổng số trang
$totalPages = ceil($totalRows / $pageSize);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Quản lý kết quả đánh giá nhân viên giao hàng</title>
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
      background-color: #007bff;
      color: white;
      padding: 20px;
      text-align: center;
    }
    .header h1 {
      margin: 0;
      font-size: 28px;
    }
    .avg-card {
      margin: 20px 0;
    }
    .avg-card .card-body {
      text-align: center;
    }
    .avg-card h2 {
      font-size: 48px;
      margin-bottom: 10px;
    }
    .avg-card p {
      font-size: 18px;
      margin: 0;
    }
    .table-responsive {
      margin-top: 20px;
    }
    /* Icon style cho modal trigger */
    .modal-trigger {
      cursor: pointer;
      color: #007bff;
      margin-left: 5px;
    }
    .modal-trigger:hover {
      color: #0056b3;
    }
    /* Pagination style (sử dụng Bootstrap mặc định) */
    @media (max-width: 576px) {
      .header h1 {
        font-size: 24px;
      }
      .avg-card h2 {
        font-size: 36px;
      }
      .avg-card p {
        font-size: 16px;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <header class="header">
    <h1>Quản lý kết quả đánh giá đơn hàng</h1>
  </header>
  
  <div class="container">
    <!-- Form tìm kiếm -->
    <div class="row my-3">
      <div class="col-md-6 offset-md-3">
        <form method="get" action="manage_evaluation.php">
          <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo mã đơn hoặc SĐT" value="<?php echo htmlspecialchars($search); ?>">
            <div class="input-group-append">
              <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Tìm kiếm</button>
            </div>
          </div>
        </form>
      </div>
    </div>
    
    <!-- Card hiển thị chỉ số đánh giá trung bình -->
    <div class="card avg-card">
      <div class="card-body">
        <h2><?php echo $avgRating; ?></h2>
        <p>Chỉ số đánh giá trung bình</p>
      </div>
    </div>
    
    <!-- Bảng hiển thị lịch sử đánh giá -->
    <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead class="thead-dark">
          <tr>
            <th>STT</th>
            <th>Mã đơn</th>
            <th>Tên KH</th>
            <th>SĐT KH</th>
            <th>Điểm đánh giá (thang 5)</th>
            <th>Ý kiến</th>
            <th>Thời gian</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if ($resultFeedback && mysqli_num_rows($resultFeedback) > 0) {
              $stt = $offset + 1;
              while ($row = mysqli_fetch_assoc($resultFeedback)) {
                  // Lấy các thông tin từ feedback và đơn hàng
                  $orderCode = isset($row['order_code2']) ? htmlspecialchars($row['order_code2']) : 'N/A';
                  $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
                  $customerPhone = isset($row['customer_phone']) ? htmlspecialchars($row['customer_phone']) : 'N/A';
                  $rating = htmlspecialchars($row['rating']);
                  $comment = htmlspecialchars($row['comment']);
                  $createdAt = htmlspecialchars($row['feedback_time']);
                  $formattedTime = date("H:i d/m/Y", strtotime($createdAt));
                  // Lấy các vote chi tiết
                  $tc1 = isset($row['tc1']) ? htmlspecialchars($row['tc1']) : '';
                  $tc2 = isset($row['tc2']) ? htmlspecialchars($row['tc2']) : '';
                  $tc3 = isset($row['tc3']) ? htmlspecialchars($row['tc3']) : '';
                  $tc4 = isset($row['tc4']) ? htmlspecialchars($row['tc4']) : '';
                  $tc5 = isset($row['tc5']) ? htmlspecialchars($row['tc5']) : '';
                  $order_id = $row['order_id'];
                  echo "<tr>
                          <td>{$stt}</td>
                          <td>{$orderCode} 
                            <i class='fas fa-eye modal-trigger' title='Xem sản phẩm' onclick='showOrderProducts({$order_id});'></i>
                          </td>
                          <td>{$customerName}</td>
                          <td>{$customerPhone}</td>
                          <td>{$rating}</td>
                          <td>{$comment} 
                            <i class='fas fa-eye modal-trigger' title='Xem chi tiết vote' onclick=\"showFeedbackDetails('{$tc1}', '{$tc2}', '{$tc3}', '{$tc4}', '{$tc5}');\"></i>
                          </td>
                          <td>{$formattedTime}</td>
                        </tr>";
                  $stt++;
              }
          } else {
              echo "<tr><td colspan='7' class='text-center'>Không có lịch sử đánh giá nào.</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
    
    <!-- Phân trang -->
    <nav aria-label="Page navigation example">
      <ul class="pagination justify-content-center">
        <?php if ($page > 1): ?>
          <li class="page-item"><a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">Trước</a></li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link">Trước</span></li>
        <?php endif; ?>
        
        <?php
          // Hiển thị một số trang xung quanh trang hiện tại, ví dụ 5 trang
          $range = 2;
          for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++): 
        ?>
          <?php if ($i == $page): ?>
            <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a></li>
          <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
          <li class="page-item"><a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">Sau</a></li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link">Sau</span></li>
        <?php endif; ?>
      </ul>
    </nav>
    
  </div>
  
  <!-- Modal hiển thị danh sách sản phẩm của đơn hàng -->
  <div class="modal fade" id="orderProductsModal" tabindex="-1" role="dialog" aria-labelledby="orderProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="orderProductsModalLabel">Danh sách sản phẩm</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <!-- Nội dung danh sách sản phẩm sẽ được load qua AJAX -->
          <div id="orderProductsContent">Đang tải dữ liệu...</div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Modal hiển thị chi tiết vote của feedback -->
  <div class="modal fade" id="feedbackDetailsModal" tabindex="-1" role="dialog" aria-labelledby="feedbackDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="feedbackDetailsModalLabel">Chi tiết đánh giá các tiêu chí</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <ul class="list-group">
            <li class="list-group-item"><strong>Thái độ và sự chuyên nghiệp của nhân viên:</strong> <span id="detailTc1"></span></li>
            <li class="list-group-item"><strong>Đúng giờ và tuân thủ thời gian giao hàng với KH:</strong> <span id="detailTc2"></span></li>
            <li class="list-group-item"><strong>Cách thức giao hàng và bảo quản hàng hóa:</strong> <span id="detailTc3"></span></li>
            <li class="list-group-item"><strong>Đảm bảo sự an toàn của hàng hóa:</strong> <span id="detailTc4"></span></li>
            <li class="list-group-item"><strong>Sự minh bạch và rõ ràng trong giao tiếp với KH:</strong> <span id="detailTc5"></span></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  
  <!-- jQuery, Popper.js và Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
    // Hàm showOrderProducts: Load danh sách sản phẩm của đơn hàng qua AJAX
    function showOrderProducts(orderId) {
      $.ajax({
        url: 'get_order_products.php',
        method: 'POST',
        data: { orderId: orderId },
        success: function(response) {
          $('#orderProductsContent').html(response);
        },
        error: function(jqXHR, textStatus, errorThrown) {
          $('#orderProductsContent').html("Lỗi khi tải dữ liệu sản phẩm: " + errorThrown);
        }
      });
      $('#orderProductsModal').modal('show');
    }
    
    // Hàm showFeedbackDetails: Hiển thị chi tiết các tiêu chí vote
    function showFeedbackDetails(tc1, tc2, tc3, tc4, tc5) {
      $('#detailTc1').text(tc1);
      $('#detailTc2').text(tc2);
      $('#detailTc3').text(tc3);
      $('#detailTc4').text(tc4);
      $('#detailTc5').text(tc5);
      $('#feedbackDetailsModal').modal('show');
    }
  </script>
</body>
</html>
