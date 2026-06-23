<article {{ $attributes->merge(['class' => 'card '.($elevated ? 'shadow-lg' : 'shadow-sm')]) }}>
    <header class="card-head flex items-center justify-between">
        <h3 class="card-title">{{ $title }}</h3>
        <div class="card-actions">{{ $actions ?? '' }}</div>
    </header>
    <div class="card-body">{{ $slot }}</div>
</article>
