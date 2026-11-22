@props(['area'])

@php
    $customCss = \App\Support\CustomCss::appliesTo($area) ? \App\Support\CustomCss::css() : '';
@endphp

@if ($customCss !== '')
    {{-- Spitze Klammern sind beim Speichern verboten (siehe App\Support\CustomCss),
         daher kann der Inhalt den style-Block nicht verlassen. --}}
    <style id="custom-css">{!! $customCss !!}</style>
@endif
