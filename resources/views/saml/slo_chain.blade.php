<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abmeldung …</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: #f3f4f6;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .box { text-align: center; }
        .spinner {
            width: 2rem; height: 2rem; margin: 0 auto .75rem;
            border: 3px solid rgba(0, 0, 0, .12); border-top-color: #ff2d20;
            border-radius: 9999px; animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { font-size: .875rem; color: #6b7280; }
        iframe { position: absolute; width: 0; height: 0; border: 0; visibility: hidden; }
        @media (prefers-color-scheme: dark) {
            body { background: #171a21; }
            p { color: #98a0ac; }
        }
    </style>
</head>
<body>
    <div class="box">
        <div class="spinner" aria-hidden="true"></div>
        <p>Sie werden von allen Anwendungen abgemeldet &hellip;</p>
        <noscript><p><a href="{{ $finalUrl ?? $finalForm['action'] }}">Weiter</a></p></noscript>
    </div>

    @foreach ($logoutRequests as $request)
        <iframe src="{{ $request['url'] }}" title="Abmeldung {{ $request['name'] }}" tabindex="-1" aria-hidden="true"></iframe>
    @endforeach

    @isset($finalForm)
        <form id="final" method="POST" action="{{ $finalForm['action'] }}">
            @foreach ($finalForm['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
        </form>
    @endisset

    <script>
        (function () {
            var frames = document.querySelectorAll('iframe');
            var pending = frames.length;
            var done = false;

            function proceed() {
                if (done) { return; }
                done = true;
                @isset($finalForm)
                    document.getElementById('final').submit();
                @else
                    window.location.replace(@json($finalUrl));
                @endisset
            }

            frames.forEach(function (frame) {
                frame.addEventListener('load', function () {
                    if (--pending <= 0) { setTimeout(proceed, 150); }
                });
            });

            setTimeout(proceed, 5000);
        })();
    </script>
</body>
</html>
