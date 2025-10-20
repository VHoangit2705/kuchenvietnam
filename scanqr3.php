<?php
// ===== BẢO MẬT & KHỞI TẠO PHIÊN =====
include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quét QR</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.4.4/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@latest/dist/jsQR.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 100%;
            padding: 20px;
            box-sizing: border-box;
        }
        .order-code-title {
            text-align: center;
            color: #007bff;
            font-size: 4vw;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 20px;
        }
        .scanner-overlay {
            width: 200px;
            height: 200px;
            border: 2px solid red;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
        }
        #video {
            width: 100%;
            max-width: 600px;
            border: 2px solid #333;
            border-radius: 10px;
            margin-bottom: 15px;
            position: relative;
            z-index: 0;
        }
        @media (max-width: 768px) {
            .order-code-title {
                font-size: 6vw;
            }
            #video {
                max-width: 100%;
            }
            .scanner-overlay {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
  <h2 class="order-code-title">Quét phiếu đơn hàng</h2>

  <!-- khu vực hiển thị camera -->
  <div id="reader"
       class="border border-dark rounded mb-3"
       style="width:100%; max-width:400px; margin:auto;">
  </div>

  <!-- Thêm id="scanForm" -->
  <form id="scanForm" method="POST" action="checkscanqr.php">
    <input type="hidden" name="order_code" id="qr_code">
    <div class="form-group mb-3">
      <label for="qr_code_input">Mã đơn hàng quét được là:</label>
      <input type="text" class="form-control" id="qr_code_input" readonly>
    </div>
    <div class="text-center">
      <button type="submit" class="btn btn-success btn-lg px-5">
        <i class="fas fa-check-circle me-1"></i>Xác nhận
      </button>
    </div>
  </form>
</div>

<audio id="scanSound" src="beep.mp3"></audio>
<audio id="scanSoundError" src="beepstop.mp3"></audio>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
  const scanSound       = document.getElementById('scanSound');
  const scanSoundError  = document.getElementById('scanSoundError');
  const qrHiddenInput   = document.getElementById('qr_code');
  const qrDisplayInput  = document.getElementById('qr_code_input');
  const scanForm        = document.getElementById('scanForm');

  let qrCodeScanned = false;

  function onScanSuccess(decodedText, decodedResult) {
    if (qrCodeScanned) return;
    qrCodeScanned = true;
    scanSound.play();

    // ✅ Xử lý loại bỏ phần sau dấu gạch như -2/3, nếu có
    const cleanedText = decodedText.split(/[-]/)[0];

    // Ghi giá trị vào form
    qrHiddenInput.value  = cleanedText;
    qrDisplayInput.value = cleanedText;

    // Dừng scanner rồi submit
    html5QrCode
      .stop()
      .then(() => {
        scanForm.submit();
      })
      .catch(err => {
        console.warn("Không thể dừng camera trước khi submit:", err);
        // vẫn submit dù dừng lỗi
        scanForm.submit();
      });
  }

  function onScanFailure(error) {
    // bỏ qua; bạn có thể log nếu cần
  }

  const html5QrCode = new Html5Qrcode("reader");
  const config = {
    fps: 10,
    qrbox: { width: 250, height: 250 },
    formatsToSupport: [
      Html5QrcodeSupportedFormats.QR_CODE,
      Html5QrcodeSupportedFormats.CODE_128
    ]
  };

  // Luôn khởi động camera sau
  html5QrCode
    .start(
      { facingMode: { exact: "environment" } },
      config,
      onScanSuccess,
      onScanFailure
    )
    .catch(err => {
      console.error("Không thể bắt đầu quét:", err);
      scanSoundError.play();
      alert("Không thể khởi động camera. Vui lòng kiểm tra quyền truy cập.");
    });
</script>


</body>
</html>
