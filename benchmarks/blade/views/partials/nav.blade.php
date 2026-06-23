<nav class="flex gap-4">
    <ul class="nav-links">
        @foreach ($links as $link)
            <li class="nav-item"><a href="#">{{ $link }}</a></li>
        @endforeach
    </ul>
</nav>
