@if (session('status'))
    <x-alert type="success">{{ session('status') }}</x-alert>
@endif
@if (session('error'))
    <x-alert type="danger">{{ session('error') }}</x-alert>
@endif
@if ($errors->any())
    <x-alert type="danger">
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif
