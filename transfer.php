<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Chuyển tem giữa các kho</title>
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
    <h1>Chuyển tem giữa các kho</h1>
    <form method="POST" action="process.php">
        <input type="hidden" name="action" value="transfer">
        <label for="product_name">Tên sản phẩm:</label>
        <input type="text" name="product_name" id="product_name" required>
        
        <label for="from_zone">Kho xuất (Nguồn):</label>
        <select name="from_zone" id="from_zone" required>
            <option value="Đơn hàng Vinh">Đơn hàng Vinh</option>
            <option value="Đơn hàng HaNoi">Đơn hàng HaNoi</option>
            <option value="Đơn hàng HCM">Đơn hàng HCM</option>
        </select>
        
        <label for="to_zone">Kho nhập (Đích):</label>
        <select name="to_zone" id="to_zone" required>
            <option value="Đơn hàng Vinh">Đơn hàng Vinh</option>
            <option value="Đơn hàng HaNoi">Đơn hàng HaNoi</option>
            <option value="Đơn hàng HCM">Đơn hàng HCM</option>
        </select>
        
        <label for="quantity">Số lượng tem cần chuyển:</label>
        <input type="number" name="quantity" id="quantity" required min="1">
        
        <button type="submit">Chuyển tem</button>
    </form>
    <br>
    <a href="quanlytem-baohanh.php">Quay lại Dashboard</a>
</div>
</body>
</html>
