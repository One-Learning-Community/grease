@props([
    'name',
    'src' => null,
    'size' => 'md',
    'status' => null,
    'squared' => false,
])
@php
    $dimensions = ['sm' => 'h-8 w-8 text-xs', 'md' => 'h-10 w-10 text-sm', 'lg' => 'h-14 w-14 text-base'][$size] ?? 'h-10 w-10 text-sm';
    $shape = $squared ? 'rounded-md' : 'rounded-full';
    $initials = \Illuminate\Support\Str::of($name)->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('');
    $ring = $status === 'online' ? 'bg-green-400' : 'bg-gray-300';
@endphp
<span {{ $attributes->merge(['class' => "relative inline-flex items-center justify-center $dimensions $shape bg-gray-100 font-medium text-gray-700"]) }}>
    @if ($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="{{ $dimensions }} {{ $shape }} object-cover">
    @else
        <span aria-hidden="true">{{ $initials }}</span>
    @endif
    @if ($status)
        <span class="absolute bottom-0 right-0 block h-2.5 w-2.5 rounded-full ring-2 ring-white {{ $ring }}"></span>
    @endif
    <span class="sr-only">{{ $name }}</span>
</span>
