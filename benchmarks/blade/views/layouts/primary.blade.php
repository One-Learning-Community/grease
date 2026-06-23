<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') — Acme</title>
    @stack('head')
    @stack('styles')
</head>
<body class="@yield('bodyClass', 'app')">
    <header class="topbar">
        <a class="brand" href="/">Acme</a>
        <nav class="topnav">
            @foreach (['Dashboard', 'Reports', 'Team', 'Settings'] as $item)
                <a href="/{{ strtolower($item) }}">{{ $item }}</a>
            @endforeach
        </nav>
    </header>

    <main class="container">
        @yield('content')
    </main>

    <footer class="site-footer">
        @section('footer')
            <small>© Acme, Inc.</small>
        @show
    </footer>

    @stack('scripts')
</body>
</html>
