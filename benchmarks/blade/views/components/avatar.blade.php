@props(['name', 'size' => 'md'])
<span {{ $attributes->merge(['class' => 'avatar avatar-'.$size]) }}>{{ \Illuminate\Support\Str::of($name)->explode(' ')->map(fn ($p) => $p[0])->take(2)->implode('') }}</span>
