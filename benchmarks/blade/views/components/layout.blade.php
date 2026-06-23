<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body class="bg-gray-50">
    <header class="topbar">{{ $nav ?? '' }}</header>
    <main class="container mx-auto">{{ $slot }}</main>
    <footer class="footer">{{ $footer ?? '' }}</footer>
</body>
</html>
