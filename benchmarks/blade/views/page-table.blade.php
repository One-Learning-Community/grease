@php($rows = array_chunk(range(1, $count), 6))
<table class="data">
    <tbody>
        @foreach ($rows as $row)
            <tr class="{{ $loop->even ? 'bg-gray-50' : 'bg-white' }}{{ $loop->last ? ' last' : '' }}">
                <th scope="row">{{ $loop->iteration }}</th>
                @foreach ($row as $cell)
                    <td data-col="{{ $loop->index }}" class="{{ $loop->first ? 'first' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
