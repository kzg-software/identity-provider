@props([
    'background',
    // uniform: gleichmäßige Abdunklung (zentriertes Layout)
    // gradient: Verlauf von unten (geteiltes Layout, damit der Text unten lesbar bleibt)
    'shade' => 'uniform',
])

@php
    $urls = $background['urls'] ?? [];
    $overlay = (int) ($background['overlay'] ?? 40);
    $isSlideshow = count($urls) > 1;
    $intervalMs = max(3, (int) ($background['interval'] ?? 12)) * 1000;
@endphp

@if ($urls !== [])
    <div class="idp-login-bg pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true"
         @if ($isSlideshow) data-idp-slideshow data-interval="{{ $intervalMs }}" @endif>
        @foreach ($urls as $i => $url)
            {{-- Nur das erste Bild wird sofort geladen; die übrigen erst kurz bevor sie an der Reihe sind. --}}
            <div class="idp-login-bg-image absolute inset-0 bg-cover bg-center bg-no-repeat"
                 data-idp-slide
                 @if ($i === 0)
                     style="background-image:url('{{ $url }}');opacity:1;"
                 @else
                     data-src="{{ $url }}" style="opacity:0;"
                 @endif
            ></div>
        @endforeach

        @if ($overlay > 0)
            @if ($shade === 'gradient')
                <div class="idp-login-bg-overlay absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-black/30"
                     style="opacity:{{ min(1, round($overlay / 40, 2)) }};"></div>
            @else
                <div class="idp-login-bg-overlay absolute inset-0" style="background-color:rgba(0,0,0,{{ round($overlay / 100, 2) }});"></div>
            @endif
        @endif
    </div>

    @if ($isSlideshow)
        @once
            @push('scripts')
                <style>
                    [data-idp-slide] { transition: opacity 1.5s ease-in-out; }
                    @media (prefers-reduced-motion: reduce) { [data-idp-slide] { transition: none; } }
                </style>
                <script>
                    (function () {
                        document.querySelectorAll('[data-idp-slideshow]').forEach(function (root) {
                            var slides = Array.prototype.slice.call(root.querySelectorAll('[data-idp-slide]'));
                            var interval = parseInt(root.getAttribute('data-interval'), 10) || 12000;
                            var current = 0;

                            function load(slide) {
                                var src = slide.getAttribute('data-src');
                                if (src) {
                                    slide.style.backgroundImage = "url('" + src.replace(/'/g, "%27") + "')";
                                    slide.removeAttribute('data-src');
                                }
                            }

                            // Das nächste Bild schon vorab laden, damit die Überblendung nicht ins Leere geht.
                            if (slides[1]) { load(slides[1]); }

                            window.setInterval(function () {
                                if (document.hidden) { return; }
                                var next = (current + 1) % slides.length;
                                load(slides[next]);
                                slides[next].style.opacity = '1';
                                slides[current].style.opacity = '0';
                                current = next;
                                load(slides[(current + 1) % slides.length]);
                            }, interval);
                        });
                    })();
                </script>
            @endpush
        @endonce
    @endif
@endif
