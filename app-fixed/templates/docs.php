<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mini BBL REST API</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"></head>
<body><main class="container py-5"><h1>Mini Bug Bounty Lab REST API</h1>
<p>PHP 8.3 / PDO / MySQL. JSON request and response bodies; authenticated endpoints require <code>Authorization: Bearer &lt;token&gt;</code>. Uploads use multipart form data with a <code>file</code> field.</p>
<p><a href="/">Trang chủ</a> · <a href="/login">Application</a> · Environment: <?= \Lab\Http::escape($mode) ?></p>
<table class="table table-striped"><thead><tr><th>Method</th><th>Path</th><th>Request / behavior</th></tr></thead><tbody>
<?php foreach ([
 ['POST','/api/register','username, password, email, full_name (optional). Always creates role user.'],
 ['POST','/api/login','username, password. Returns access_token, token_type, role.'],
 ['POST','/api/logout','Client deletes its token. Existing JWTs expire naturally.'],
 ['GET / PUT','/api/me','Read or update own profile (email, full_name).'],
 ['GET / PUT','/api/profile/{id}','Read or update profile. '],
 ['GET','/api/orders','List own orders.'],
 ['GET','/api/orders/{id}','Order detail. '],
 ['POST','/api/avatar','Multipart image upload. Returns avatar_url. '],
 ['POST','/api/feedback','message. Stores feedback.'],
 ['GET','/api/feedback?search=','Search messages.'],
 ['GET','/api/admin/users?search=','Search users. '],
 ['POST','/api/checkout','product_id, quantity (default 1).'],
 ['GET','/feedback-wall','Customer feedback wall.'],
 ['GET','/uploads/{filename}','Uploaded avatar.'],
 ['GET','/health','Database readiness check.']
 ,['POST','/api/gift-card/preview','recipient, template. Returns preview. Supported variables: recipient, sender.']
 ,['POST','/api/shipping/track','url. Retrieves a shipment update from the carrier.']
 ,['GET','/api/help?article=shipping.php','Customer help article: shipping.php or returns.php.']
] as [$method, $path, $description]): ?>
<tr><td><?= \Lab\Http::escape($method) ?></td><td><code><?= \Lab\Http::escape($path) ?></code></td><td><?= \Lab\Http::escape($description) ?></td></tr>
<?php endforeach; ?></tbody></table>
<p>Success: 200. Errors: 400 invalid upload/preflight, 401 authentication, 403 authorization, 404 missing object, 409 conflict, 413 upload size, 422 invalid request, 429 throttled login, 500 unexpected failure. Errors use <code>{"detail":"..."}</code>.</p>
<p>Products: 1 Wireless Mouse $19.99; 2 USB-C Hub $39.99; 3 Pentest Notebook $12.50. Seed users: alice, bob, staff, admin. Password suffix: vulnerable <code>123</code>; fixed <code>12345</code>.</p>
</main></body></html>
