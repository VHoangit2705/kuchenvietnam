<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}

include 'config.php'; // Kết nối CSDL

// Hàm lấy tên cột tồn kho dựa vào zone
function getStockField($zone) {
    if ($zone == 'Đơn hàng Vinh') return 'stock_vinh';
    elseif ($zone == 'Đơn hàng HaNoi') return 'stock_hanoi';
    elseif ($zone == 'Đơn hàng HCM') return 'stock_hcm';
    else return false;
}

$operator = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Xử lý chuyển tem giữa các kho ---
    if (isset($_POST['action']) && $_POST['action'] == 'transfer') {
        $product_name = trim($_POST['product_name']);
        $from_zone    = trim($_POST['from_zone']);
        $to_zone      = trim($_POST['to_zone']);
        $quantity     = (int) $_POST['quantity'];
        
        if ($from_zone === $to_zone) {
            die("Kho chuyển phải khác nhau.");
        }
        if ($quantity <= 0) {
            die("Số lượng phải lớn hơn 0.");
        }
        
        $fromField = getStockField($from_zone);
        $toField   = getStockField($to_zone);
        if (!$fromField || !$toField) {
            die("Zone không hợp lệ.");
        }
        
        $conn->begin_transaction();
        try {
            // Lấy tồn kho hiện tại tại kho nguồn và kho đích
            $sql = "SELECT $fromField, $toField FROM products WHERE product_name = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $product_name);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $current_from_stock = (int)$row[$fromField];
                $current_to_stock   = (int)$row[$toField];
                
                if ($current_from_stock < $quantity) {
                    throw new Exception("Không đủ tem tại kho nguồn ($from_zone). Tồn kho hiện tại: $current_from_stock");
                }
                
                // Cập nhật tồn kho: trừ kho nguồn, cộng kho đích
                $sqlUpdateFrom = "UPDATE products SET $fromField = $fromField - ? WHERE product_name = ?";
                $stmtUpdateFrom = $conn->prepare($sqlUpdateFrom);
                $stmtUpdateFrom->bind_param("is", $quantity, $product_name);
                $stmtUpdateFrom->execute();
                
                $sqlUpdateTo = "UPDATE products SET $toField = $toField + ? WHERE product_name = ?";
                $stmtUpdateTo = $conn->prepare($sqlUpdateTo);
                $stmtUpdateTo->bind_param("is", $quantity, $product_name);
                $stmtUpdateTo->execute();
                
                // Lưu lịch sử chuyển tem vào bảng sticker_history
                $sqlHistorya = "INSERT INTO sticker_history (product_name, event_type, from_zone, to_zone, quantity, operator) VALUES (?, 'transfer', ?, ?, ?, ?)";
                $stmtHistorya = $conn->prepare($sqlHistorya);
                $stmtHistorya->bind_param("sssis", $product_name, $from_zone, $to_zone, $quantity, $operator);
                $stmtHistorya->execute();
                
                $conn->commit();
                
                // Kiểm tra cảnh báo nếu tồn kho dưới 10 tem
                $alerts = [];
                if (($current_from_stock - $quantity) < 10) {
                    $alerts[] = "Tồn kho tại $from_zone của sản phẩm '$product_name' dưới 10 tem.";
                }
                if (($current_to_stock + $quantity) < 10) {
                    $alerts[] = "Tồn kho tại $to_zone của sản phẩm '$product_name' dưới 10 tem.";
                }
                
                $message = "Chuyển tem thành công!";
                if (!empty($alerts)) {
                    $message .= " Cảnh báo: " . implode(" ", $alerts);
                }
                
                echo "<script>
                        alert('$message');
                        window.location.href = 'transfer.php';
                      </script>";
                exit();
            } else {
                throw new Exception("Không tìm thấy sản phẩm: $product_name");
            }
        } catch (Exception $e) {
            $conn->rollback();
            die("Lỗi: " . $e->getMessage());
        }
    }
    
    // --- Xử lý nhập số lượng tem in mới ---
    if (isset($_POST['action']) && $_POST['action'] == 'add_new') {
        $product_name = trim($_POST['product_name_new']);
        $zone         = trim($_POST['zone_new']);
        $quantity     = (int) $_POST['quantity_new'];
        
        if ($quantity <= 0) {
            die("Số lượng phải lớn hơn 0.");
        }
        
        $stockField = getStockField($zone);
        if (!$stockField) {
            die("Zone không hợp lệ.");
        }
        
        $conn->begin_transaction();
        try {
            // Lấy tồn kho hiện tại của sản phẩm tại kho được chọn
            $sql = "SELECT $stockField FROM products WHERE product_name = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $product_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $current_stock = (int)$row[$stockField];
                
                // Cập nhật tồn kho: cộng số lượng tem in mới
                $sqlUpdate = "UPDATE products SET $stockField = $stockField + ? WHERE product_name = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param("is", $quantity, $product_name);
                $stmtUpdate->execute();
                
                // Lưu lịch sử nhập tem mới vào bảng sticker_history
                // Ở trường hợp nhập thêm, from_zone sẽ để NULL và to_zone lưu kho nhận
                $sqlHistory = "INSERT INTO sticker_history (product_name, event_type, to_zone, quantity, operator) VALUES (?, 'addition', ?, ?, ?)";
                $stmtHistory = $conn->prepare($sqlHistory);
                $stmtHistory->bind_param("ssis", $product_name, $zone, $quantity, $operator);
                $stmtHistory->execute();
                
                $conn->commit();
                
                $new_stock = $current_stock + $quantity;
                $alert = "";
                if ($new_stock < 10) {
                    $alert = "Cảnh báo: Tồn kho tại $zone của sản phẩm '$product_name' dưới 10 tem.";
                }
                
                echo "<script>
                        alert('Đã thêm $quantity tem in mới vào kho $zone. $alert');
                        window.location.href = 'add_new.php';
                      </script>";
                exit();
            } else {
                throw new Exception("Không tìm thấy sản phẩm: $product_name");
            }
        } catch (Exception $e) {
            $conn->rollback();
            die("Lỗi: " . $e->getMessage());
        }
    }
}
?>
