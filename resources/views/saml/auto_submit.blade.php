<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weiterleitung …</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body onload="document.forms[0].submit();" class="min-h-screen flex items-center justify-center bg-gray-100 font-sans">
    <div class="text-center">
        <svg class="animate-spin h-8 w-8 text-[#FF2D20] mx-auto mb-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        <p class="text-sm text-gray-500">Weiterleitung wird durchgeführt &hellip;</p>
    </div>
    <form method="POST" action="{{ $acsUrl }}">
        <input type="hidden" name="{{ $paramName ?? 'SAMLResponse' }}" value="{{ $samlResponse }}">
        @if (! empty($relayState))
            <input type="hidden" name="RelayState" value="{{ $relayState }}">
        @endif
        <noscript>
            <p class="text-sm text-gray-600 mt-2">JavaScript ist deaktiviert. Bitte klicken Sie auf den Button, um fortzufahren.</p>
            <button type="submit" class="mt-2 px-4 py-2 rounded-md bg-[#FF2D20] text-white text-sm font-semibold">Weiter</button>
        </noscript>
    </form>
</body>
</html>
