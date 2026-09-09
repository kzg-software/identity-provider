@extends('emails.layout')

@section('content')
<h1 style="margin:0 0 16px; font-size:19px; font-weight:600; color:#111827;">{{ $heading }}</h1>

@foreach ($body as $line)
    <p style="margin:0 0 14px;">{{ $line }}</p>
@endforeach

@if ($actionUrl && $actionLabel)
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">
        <tr>
            <td style="border-radius:8px; background-color:{{ $accent['600'] }};">
                <a href="{{ $actionUrl }}" style="display:inline-block; padding:11px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $actionLabel }}</a>
            </td>
        </tr>
    </table>
    <p style="margin:12px 0 0; font-size:13px; color:#6b7280;">
        Falls der Button nicht funktioniert, kopiere diese Adresse in deinen Browser:<br>
        <span style="color:{{ $accent['700'] }}; word-break:break-all;">{{ $actionUrl }}</span>
    </p>
@endif
@endsection
