@props(['color' => 'gray', 'title'])

@php
$dots = [
    'gray' => 'bg-gray-400',
    'green' => 'bg-emerald-500',
    'amber' => 'bg-amber-500',
    'red' => 'bg-red-500',
];
@endphp

{{-- Hover-Text wird an <body> gehängt, damit Tabellen mit overflow ihn nicht abschneiden. --}}
<span class="inline-flex"
      x-data="{
          open: false, x: 0, y: 0,
          show() {
              const r = this.$refs.dot.getBoundingClientRect();
              this.x = Math.max(8, Math.min(r.left, window.innerWidth - 296));
              this.y = r.bottom + 6;
              this.open = true;
              this.$nextTick(() => {
                  const h = this.$refs.tip.offsetHeight;
                  if (this.y + h > window.innerHeight) { this.y = Math.max(8, r.top - h - 6); }
              });
          },
      }"
      @mouseenter="show()" @mouseleave="open = false" @focusin="show()" @focusout="open = false">
    <span x-ref="dot" tabindex="0" role="img" aria-label="{{ $title }}: {{ trim($slot) }}"
          class="inline-block h-2.5 w-2.5 shrink-0 rounded-full {{ $dots[$color] ?? $dots['gray'] }}"></span>

    <template x-teleport="body">
        <div x-ref="tip" x-show="open" x-transition.opacity x-cloak :style="`left: ${x}px; top: ${y}px`"
             class="pointer-events-none fixed z-50 w-72 rounded-md border border-gray-200 bg-white p-3 text-left text-xs leading-relaxed text-gray-600 shadow-lg">
            <span class="block font-medium text-gray-900">{{ $title }}</span>
            <span class="mt-0.5 block">{{ $slot }}</span>
        </div>
    </template>
</span>
