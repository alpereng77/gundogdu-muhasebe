<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Alperen Gündoğdu Yazılım">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#1b3c48">
  <title>{{ $label }} | Öztoprak İnşaat Muhasebe Sistemi</title>
  <link rel="icon" href="{{ asset('assets/favicon.svg') }}" type="image/svg+xml">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Mrs+Saint+Delafield&display=swap">
  <link rel="stylesheet" href="{{ asset('assets/tailwind.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/components.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/panel.css') }}">
  @php($theme = \App\Support\Theme::get())
  @if($url = \App\Support\Theme::fontUrl($theme))<link id="theme-font" rel="stylesheet" href="{{ $url }}">@endif
  <style id="theme-style">{!! \App\Support\Theme::css($theme) !!}</style>
  @if($theme['logo'])<link id="theme-icon" rel="icon" href="{{ $theme['logo'] }}">@endif
  <script>window.APP = @json($boot);</script>
  <script src="{{ \App\Support\Asset::url('assets/app.js') }}" defer></script>
</head>
<body data-page="{{ $label }}">
  <noscript><p style="padding:16px;background:#fff8e8">Kayıt ekleme, filtreleme ve hesaplamalar için JavaScript'i etkinleştirin.</p></noscript>
  <form id="logout-form" method="POST" action="{{ route('logout') }}" hidden>@csrf</form>
  <div id="app-root"></div>
</body>
</html>
