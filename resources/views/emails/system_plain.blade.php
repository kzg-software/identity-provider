{{ $heading }}
@foreach ($body as $line)

{{ $line }}
@endforeach
@if ($actionUrl && $actionLabel)

{{ $actionLabel }}: {{ $actionUrl }}
@endif

{{ $systemName }}
Automatische Nachricht. Bitte nicht auf diese E-Mail antworten.
