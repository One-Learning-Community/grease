@extends('layouts.app')

@section('title', 'Dashboard')

@push('head')
    <link rel="stylesheet" href="/app.css">
@endpush

@section('content')
    <h1 class="page-title">Activity</h1>
    <div class="feed">
        @for ($i = 0; $i < $count; $i++)
            <article class="card">
                <header class="card-head">
                    <span class="badge">#{{ $i }}</span>
                    <h3>Item number {{ $i }}</h3>
                </header>
                <p class="card-body">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Item {{ $i }} body text with enough markup to make the rendered section content a realistically sized string to scan during layout assembly.</p>
                <footer class="card-foot"><a href="/items/{{ $i }}">View</a></footer>
            </article>
        @endfor
    </div>
@endsection

@push('scripts')
    <script src="/app.js"></script>
@endpush
