<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$v = @filemtime(__DIR__ . '/assets/app.js') ?: 1;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Dictionary</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="icon" href="assets/icon-32.png" sizes="32x32" type="image/png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<meta name="theme-color" content="#5356e6">
<script>try { var t = localStorage.getItem('dd-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/app.css?v=<?= max($v, (int)@filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<div id="app"><p class="boot">กำลังโหลด…</p></div>
<div id="toasts" aria-live="polite"></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
