<?php
$orderId = isset($_GET['orderId']) ? htmlspecialchars($_GET['orderId']) : "N/A";
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cảm ơn bạn đã đánh giá</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(to right, #6dd5ed, #2193b0);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: white;
        }
        .thank-you-box {
            background: rgba(255, 255, 255, 0.2);
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
        }
        .thank-you-box img {
            width: 80px;
            margin-bottom: 20px;
        }
        .thank-you-box h2 {
            font-weight: bold;
            font-size: 24px;
        }
        .thank-you-box p {
            font-size: 18px;
        }
        .btn-back {
            background: white;
            color: #2193b0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="thank-you-box">
        <img src="https://cdn-icons-png.flaticon.com/512/845/845646.png" alt="Thank You">
        <h2>Cảm ơn quý khách đã dành thời gian!</h2>
        <p>Đánh giá của bạn cho đơn hàng mã số <strong><?php echo $orderId; ?></strong> đã được ghi nhận.</p>
        <p>Chúng tôi rất trân trọng sự đóng góp của bạn!</p>
        
    </div>
</body>
</html>
