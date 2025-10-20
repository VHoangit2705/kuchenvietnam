<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_name = trim($_POST['product_name']);
    $zone         = trim($_POST['zone']);
    $quantity     = (int) $_POST['quantity'];
    
    if ($quantity <= 0) {
        die("Số lượng phải lớn hơn 0.");
    }
    $operator = $_SESSION['full_name'];
    // Lưu lệnh in với trạng thái mặc định 'pending'
    $sql = "INSERT INTO print_orders (product_name, zone, quantity, operator, status) VALUES (?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssis", $product_name, $zone, $quantity, $operator);
    if ($stmt->execute()) {
        echo "<script>
                alert('Gửi lệnh in thành công!');
                window.location.href = 'print_order.php';
              </script>";
        exit();
    } else {
        die("Lỗi: " . $conn->error);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gửi lệnh in bổ sung</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .container { width: 80%; margin: auto; }
        label { display: block; margin: 10px 0 5px; }
        input, select { width: 100%; padding: 8px; margin-bottom: 10px; }
        button { padding: 10px 20px; background-color: #28a745; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #218838; }
    </style>
</head>
<body>
<div class="container">
    <h1>Gửi lệnh in bổ sung</h1>
    <form method="POST" action="print_order.php">
        <label for="product_name">Tên sản phẩm:</label>
        <input type="text" name="product_name" id="product_name" required>
        
        <label for="zone">Chọn kho:</label>
        <select name="zone" id="zone" required>
            <option value="Đơn hàng Vinh">Đơn hàng Vinh</option>
            <option value="Đơn hàng HaNoi">Đơn hàng HaNoi</option>
            <option value="Đơn hàng HCM">Đơn hàng HCM</option>
        </select>
        
        <label for="quantity">Số lượng in bổ sung:</label>
        <input type="number" name="quantity" id="quantity" required min="1">
        
        <button type="submit">Gửi lệnh in</button>
    </form>
    <br>
    <a href="dashboard.php">Quay lại Dashboard</a> | <a href="print_orders_list.php">Xem danh sách lệnh in</a>
</div>
</body>
</html>
