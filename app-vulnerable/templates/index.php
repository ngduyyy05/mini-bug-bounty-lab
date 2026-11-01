<?php $isVulnerable = $mode === 'vulnerable'; ?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mini Store</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{color:#172033;background:#f5f7fb}nav{background:#101828}.wrap{max-width:1100px;margin:auto;padding:24px}
    .hero{padding:64px 0}.card{border-color:#d8dee8}.product-icon{font-size:48px;background:#edf5f3;padding:28px;text-align:center}
    .muted{color:#697386}h1{font-size:clamp(36px,5vw,56px);font-weight:750;letter-spacing:-1px}
  </style>
</head>
<body>
<nav><div class="wrap d-flex flex-wrap align-items-center gap-4 py-3">
  <a class="text-white text-decoration-none fw-bold fs-5 me-auto" href="/">Mini Store</a>
  <a class="text-white" href="/orders">Đơn hàng</a><a class="text-white" href="/feedback">Đánh giá</a>
  <a class="text-white" href="/login">Đăng nhập</a><a class="btn btn-light btn-sm" href="/register">Tạo tài khoản</a>
</div></nav>
<main class="wrap">
  <section class="hero">
    <p class="text-success fw-semibold">PHỤ KIỆN CHO GÓC LÀM VIỆC</p>
    <h1>Nhỏ gọn, tiện dụng.<br>Sẵn sàng cho mỗi ngày.</h1>
    <p class="muted mt-3">Chọn phụ kiện yêu thích, quản lý đơn hàng và chia sẻ trải nghiệm của bạn.</p>
    <a class="btn btn-dark mt-2" href="#products">Khám phá sản phẩm</a>
  </section>
  <section id="products" class="pb-5">
    <h2 class="h4 mb-4">Sản phẩm nổi bật</h2>
    <div class="row g-4">
      <?php foreach ([[1, 'Wireless Mouse', 'Chuột không dây cho bàn làm việc gọn gàng.', '19.99', '◉'], [2, 'USB-C Hub', 'Kết nối các thiết bị bạn cần mỗi ngày.', '39.99', '⊞'], [3, 'Pentest Notebook', 'Ghi lại ý tưởng và công việc của bạn.', '12.50', '▤']] as [$id, $name, $description, $price, $icon]): ?>
      <div class="col-md-4"><article class="card h-100 overflow-hidden">
        <div class="product-icon" aria-hidden="true"><?= $icon ?></div>
        <div class="card-body d-flex flex-column"><h3 class="h5"><?= \Lab\Http::escape($name) ?></h3>
          <p class="muted"><?= \Lab\Http::escape($description) ?></p>
          <div class="d-flex align-items-center justify-content-between mt-auto"><strong>$<?= $price ?></strong><a class="btn btn-outline-dark" href="/checkout?product=<?= $id ?>">Mua ngay</a></div>
        </div>
      </article></div>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<footer class="border-top"><div class="wrap d-flex flex-wrap justify-content-between gap-3 small muted">
  <span>Mini Store · Môi trường thực hành local<?= $isVulnerable ? '' : ' · Bản đã sửa' ?></span>
  <a data-lab-port="8001" href="/fixes">Thông tin bản đã sửa</a>
</div></footer>
<script>
  for (const link of document.querySelectorAll('[data-lab-port]')) {
    const url = new URL(link.getAttribute('href'), location.origin);
    url.port = link.dataset.labPort;
    link.href = url.href;
  }
</script>
</body></html>
