<x-layout title="Dashboard">
    <x-slot:nav>
        @include('partials.nav')
    </x-slot>

    <div class="feed">
        @for ($i = 0; $i < $count; $i++)
            <x-card :title="'Item '.$i" :elevated="$i % 3 === 0" class="mb-4">
                <x-slot:actions>
                    <x-avatar :name="'Taylor Otwell'" class="mt-1" />
                </x-slot>

                <p class="text-sm text-gray-600">Lorem ipsum body number {{ $i }} with some inline markup.</p>

                <div class="tags">
                    @each('partials.tag', ['alpha', 'beta', 'gamma'], 'tag')
                </div>

                <x-stat :label="'Views'" :value="$i * 7" class="mt-2" />
            </x-card>
        @endfor
    </div>

    <x-slot:footer>
        @include('partials.footer')
    </x-slot>
</x-layout>
