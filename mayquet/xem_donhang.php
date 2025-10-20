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
        <h4 class="text-center mb-4">TÌM ĐƠN HÀNG BẰNG MÁY QUÉT</h4>
        <form action="checkscanqr.php" method="POST" id="scannerForm">
            <div class="form-group">
                <label for="barcodeInput">Quét mã vạch chứa mã đơn hàng Droppii hoặc mã vạch Viettel Post hoặc mã QR trên phiếu giao hàng</label>
                <input type="text" class="form-control" id="barcodeInput" name="order_code" placeholder="Scan here..." autocomplete="off" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">CHUYỂN ĐẾN ĐƠN HÀNG</button>
        </form>
    </div>

    <script>
    const barcodeInput = document.getElementById('barcodeInput');
    const scannerForm = document.getElementById('scannerForm');
    let typingTimer;
    const delay = 500; // ms: đợi sau 500ms không nhập nữa thì submit

    barcodeInput.addEventListener('input', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            const value = barcodeInput.value.trim();
            if (value.length > 5) { // tránh submit rác
                scannerForm.submit();
            }
        }, delay);
    });
</script>

</body>
</html>
