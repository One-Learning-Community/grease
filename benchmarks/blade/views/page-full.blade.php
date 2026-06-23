@extends('layouts.primary')

@section('title', 'Quarterly Report')

@push('head')
    <meta name="description" content="Quarterly performance report">
@endpush

@push('styles')
    <link rel="stylesheet" href="/css/reports.css">
@endpush

@section('content')
    @php
        $rows = [];
        for ($r = 1; $r <= 100; $r++) {
            $rows[] = [
                'name' => 'User '.$r,
                'email' => 'user'.$r.'@example.com',
                'status' => $r % 3 === 0 ? 'inactive' : 'active',
                'score' => ($r * 37) % 100,
            ];
        }
    @endphp

    <h1 class="page-title">Quarterly Report</h1>

    <section class="stats grid grid-cols-3 gap-4">
        <x-stat label="Users" :value="100" />
        <x-stat label="Active" :value="67" />
        <x-stat label="Revenue" :value="48230" />
    </section>

    <table class="data w-full">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th class="text-right">Score</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td><span class="badge badge-{{ $row['status'] }}">{{ ucfirst($row['status']) }}</span></td>
                    <td class="text-right">{{ number_format($row['score']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

@section('footer')
    @parent
    <small class="generated">Report generated for the current period.</small>
@endsection

@push('scripts')
    <script src="/js/reports.js"></script>
@endpush
