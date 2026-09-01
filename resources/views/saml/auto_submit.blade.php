<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weiterleitung …</title>
    {{--
        Bewusst kein Tailwind/CDN: diese Auto-POST-Seite blinkt nur kurz auf
        und lädt eine externe Ressource würde sie unnötig verzögern.
    --}}
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
        button {
            margin-top: .5rem; padding: .5rem 1rem; border: 0; border-radius: .375rem;
            background: #ff2d20; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #171a21; }
            p { color: #98a0ac; }
        }
    </style>
</head>
<body onload="document.forms[0].submit();">
    <div class="box">
        <div class="spinner" aria-hidden="true"></div>
        <p>Weiterleitung wird durchgeführt &hellip;</p>
    </div>
    <form method="POST" action="{{ $acsUrl }}">
        <input type="hidden" name="{{ $paramName ?? 'SAMLResponse' }}" value="{{ $samlResponse }}">
        @if (! empty($relayState))
            <input type="hidden" name="RelayState" value="{{ $relayState }}">
        @endif
        <noscript>
            <p>JavaScript ist deaktiviert. Bitte klicken Sie auf den Button, um fortzufahren.</p>
            <button type="submit">Weiter</button>
        </noscript>
    </form>
</body>
</html>
