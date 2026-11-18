<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Gündoğdu">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#1b3c48">
  <title>{{ $label }} | Gündoğdu İnşaat</title>
  <link rel="icon" href="{{ asset('assets/favicon.svg') }}" type="image/svg+xml">
  <link rel="stylesheet" href="{{ asset('assets/tailwind.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/components.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ filemtime(public_path('assets/panel.css')) }}">
  <script>window.APP = @json($boot);</script>
  <script src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}" defer></script>
</head>
<body data-page="{{ $label }}">
  <noscript><p style="padding:16px;background:#fff8e8">Kayıt ekleme, filtreleme ve hesaplamalar için JavaScript'i etkinleştirin.</p></noscript>
  <form id="logout-form" method="POST" action="{{ route('logout') }}" hidden>@csrf</form>
  <div id="app-root"></div>
</body>
</html>
