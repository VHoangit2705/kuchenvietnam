<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Báo cáo chỉ số vận hành</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 30px;
        }
        .report-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 30px;
        }
        .card-metric {
            border-left: 5px solid #007bff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .card-metric .card-body {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .metric-title {
            font-weight: 600;
            font-size: 16px;
        }
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .time-range {
            margin-bottom: 20px;
        }
        .revenue-card {
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="text-center report-title">Báo cáo chỉ số vận hành</div>

    <!-- Bộ lọc theo thời gian -->
    <div class="row justify-content-center time-range">
        <div class="col-md-3">
            <select class="form-control" id="timeFilter">
                <option value="today">Hôm nay</option>
                <option value="this_month">Tháng này</option>
                <option value="this_year">Năm nay</option>
            </select>
        </div>
    </div>

    <!-- Cards chỉ số -->
    <div class="row">
        <div class="col-md-2 col-sm-6 mb-4">
            <div class="card card-metric">
                <div class="card-body text-center">
                    <div class="metric-title">Tổng đơn</div>
                    <div class="metric-value" id="total-orders">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-4">
            <div class="card card-metric">
                <div class="card-body text-center">
                    <div class="metric-title">Chờ quét QR</div>
                    <div class="metric-value" id="pending-qr">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-4">
            <div class="card card-metric">
                <div class="card-body text-center">
                    <div class="metric-title">Đã quét QR</div>
                    <div class="metric-value" id="scanned-qr">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-4">
            <div class="card card-metric">
                <div class="card-body text-center">
                    <div class="metric-title">Hủy</div>
                    <div class="metric-value" id="cancelled-orders">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-sm-12 mb-4">
            <div class="card revenue-card">
                <div class="card-body text-center">
                    <div class="metric-title">Tổng doanh thu</div>
                    <div class="metric-value text-danger" id="total-revenue">0₫</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Biểu đồ tỷ lệ đơn hàng -->
    <div class="row mt-4">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header text-center font-weight-bold">
                    Biểu đồ tỷ lệ đơn hàng
                </div>
                <div class="card-body">
                    <canvas id="orderPieChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Danh sách sản phẩm đã bán -->
    <div class="row mt-5">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header font-weight-bold">Danh sách sản phẩm đã xuất bán</div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-sm table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Tên sản phẩm</th>
                                <th>Số lượng</th>
                                <th>Đơn giá</th>
                                <th>Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody id="product-list">
                            <!-- Dữ liệu sẽ được JS render -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- JS Bootstrap + jQuery -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    const mockData = {
        today: {
            total: 23, pending: 5, scanned: 15, cancelled: 3,
            revenue: 7850000,
            products: [
                { name: "Máy ép chậm KU-01", quantity: 3, price: 1500000 },
                { name: "Nồi chiên không dầu KU-02", quantity: 2, price: 1200000 },
                { name: "Máy lọc nước KU-03", quantity: 1, price: 1950000 }
            ]
        },
        this_month: {
            total: 412, pending: 47, scanned: 330, cancelled: 35,
            revenue: 87250000,
            products: [
                { name: "Máy ép chậm KU-01", quantity: 123, price: 1500000 },
                { name: "Nồi chiên không dầu KU-02", quantity: 85, price: 1200000 },
                { name: "Máy lọc nước KU-03", quantity: 35, price: 1950000 }
            ]
        },
        this_year: {
            total: 5123, pending: 405, scanned: 4380, cancelled: 338,
            revenue: 911250000,
            products: [
                { name: "Máy ép chậm KU-01", quantity: 1234, price: 1500000 },
                { name: "Nồi chiên không dầu KU-02", quantity: 982, price: 1200000 },
                { name: "Máy lọc nước KU-03", quantity: 540, price: 1950000 }
            ]
        }
    };

    let pieChart;

    function formatCurrency(num) {
        return num.toLocaleString('vi-VN') + '₫';
    }

    function updateMetrics(range) {
        const data = mockData[range] || mockData.today;

        $('#total-orders').text(data.total);
        $('#pending-qr').text(data.pending);
        $('#scanned-qr').text(data.scanned);
        $('#cancelled-orders').text(data.cancelled);
        $('#total-revenue').text(formatCurrency(data.revenue));

        updatePieChart(data);
        updateProductList(data.products);
    }

    function updatePieChart(data) {
        const ctx = document.getElementById('orderPieChart').getContext('2d');
        const chartData = {
            labels: ['Đã quét QR', 'Chưa quét QR', 'Đơn hủy'],
            datasets: [{
                data: [data.scanned, data.pending, data.cancelled],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545']
            }]
        };

        const chartOptions = {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        };

        if (pieChart) {
            pieChart.data = chartData;
            pieChart.update();
        } else {
            pieChart = new Chart(ctx, {
                type: 'pie',
                data: chartData,
                options: chartOptions
            });
        }
    }

    function updateProductList(products) {
        const tbody = $('#product-list');
        tbody.empty();
        products.forEach((p, i) => {
            const total = p.quantity * p.price;
            tbody.append(`
                <tr>
                    <td>${i + 1}</td>
                    <td>${p.name}</td>
                    <td>${p.quantity}</td>
                    <td>${formatCurrency(p.price)}</td>
                    <td>${formatCurrency(total)}</td>
                </tr>
            `);
        });
    }

    $('#timeFilter').on('change', function () {
        updateMetrics(this.value);
    });

    updateMetrics('today');
</script>

</body>
</html>
