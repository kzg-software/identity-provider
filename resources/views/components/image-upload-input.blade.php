@props(['name', 'accept' => 'image/*', 'required' => false, 'previewClass' => 'h-12 max-w-xs'])

<div {{ $attributes->only('class') }}>
    <input type="file" name="{{ $name }}" accept="{{ $accept }}" @required($required)
           onchange="var i=this.parentNode.querySelector('img'),f=this.files[0];if(i.dataset.u){URL.revokeObjectURL(i.dataset.u);i.dataset.u=''}if(f){i.dataset.u=URL.createObjectURL(f);i.src=i.dataset.u;i.classList.remove('hidden')}else{i.classList.add('hidden')}"
           class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
    <img alt="Vorschau" class="mt-2 hidden rounded-md border border-gray-200 object-contain p-2 {{ $previewClass }}">
</div>
