@extends('layouts.app')

@section('title', 'Assets')

@pushOnce('head')
    <meta name="generator" content="grease">
@endPushOnce

@section('content')
    <h1 class="page-title">Asset stacks</h1>
    <div class="feed">
        @for ($i = 0; $i < $count; $i++)
            <article class="row">
                <span class="n">#{{ $i }}</span>
                @push('scripts')
                    <script>init({{ $i }});</script>
                @endpush
                @prepend('head')
                    <link rel="stylesheet" href="/row{{ $i }}.css">
                @endprepend
            </article>
        @endfor
    </div>
@endsection
