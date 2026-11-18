<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1b3c48">
  <title>Giriş | Gündoğdu İnşaat</title>
  <link rel="icon" href="{{ asset('assets/favicon.svg') }}" type="image/svg+xml">
  <link rel="stylesheet" href="{{ asset('assets/components.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ filemtime(public_path('assets/panel.css')) }}">
</head>
<body class="loginpage">
  <main class="loginwrap">
    <section class="loginbrand">
      <div class="brand">
        <div class="brandmark"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 22V3h12v19M3 22h18M9 6h1m4 0h1M9 10h1m4 0h1M9 14h1m4 0h1M10 22v-4h4v4"/></svg></div>
        <div class="brandtext">Gündoğdu Yazılım<small>İŞLETME TAKİP SİSTEMİ</small></div>
      </div>
      <h1>Projenizin finansal kontrol merkezi</h1>
      <p>Faturalar, ödemeler, tevkifat, çek/senet ve cari hesaplar tek panelde. Yetkiniz olan inşaatların verilerini görüntüleyin.</p>
    </section>
    <section class="card logincard">
      <h2>Panele giriş</h2>
      <p>Hesap bilgilerinizle oturum açın.</p>
      @if (session('error'))
        <div class="loginerror" role="alert">{{ session('error') }}</div>
      @endif
      <form method="POST" action="{{ url('/giris') }}">
        @csrf
        <label class="field"><span>E-posta</span><input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
        <label class="field"><span>Şifre</span><input name="password" type="password" autocomplete="current-password" required></label>
        <label class="checkboxline"><input type="checkbox" name="remember" value="1"> Beni hatırla</label>
        <button class="primary loginbutton" type="submit">Giriş yap</button>
      </form>
    </section>
  </main>
</body>
</html>
