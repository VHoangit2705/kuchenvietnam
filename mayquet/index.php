
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KHO HÀNG KUCHEN - MÁY QUÉT</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .scanner-container {
            border: 1px solid #dee2e6;
            padding: 30px;
            border-radius: 10px;
            background-color: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="scanner-container">
        <h4 class="text-center mb-4">ĐĂNG NHẬP HỆ THỐNG KHO</h4>
        <form action="login.php" method="POST" id="scannerForm">
            <div class="form-group">
                <label for="barcodeInput">Quét mã vạch chứa mã truy cập kho</label>
                <input type="password" class="form-control" id="barcodeInput" name="password" placeholder="Scan here..." autocomplete="off" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Đăng nhập</button>
        </form>
    </div>

    <script>
        // Automatically submit form when barcode is scanned
        const barcodeInput = document.getElementById('barcodeInput');
        const scannerForm = document.getElementById('scannerForm');

        barcodeInput.addEventListener('input', () => {
            if (barcodeInput.value.trim() !== '') {
                scannerForm.submit();
            }
        });
    </script>
</body>
</html>
