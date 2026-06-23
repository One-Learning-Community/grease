<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Default')</title>
    @stack('head')
</head>
<body>
    <main>
        @yield('content')
    </main>
    <footer>@yield('footer', '© Acme')</footer>
    @stack('scripts')
</body>
</html>
