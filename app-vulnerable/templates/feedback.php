<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Feedback Wall</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <main class="container py-5">
    <h1 class="h3">Feedback Wall</h1>
    <p><a href="/feedback">Quay lại đánh giá</a> · <a href="/">Mini Store</a></p>
    <?php foreach ($items as $item): ?>
      <article class="bg-white border rounded-2 p-3 mb-3">
        <div class="small text-muted"><?= \Lab\Http::escape($item["username"]) ?> - <?= \Lab\Http::escape($item["created_at"]) ?></div>
        <div><?= $item["message"] /* VULN-04: intentionally raw HTML */ ?></div>
      </article>
    <?php endforeach; ?>
  </main>
</body>
</html>
