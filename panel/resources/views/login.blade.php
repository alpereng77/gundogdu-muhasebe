<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @php($theme = \App\Support\Theme::get())
  <meta name="theme-color" content="{{ $theme['menu'] }}">
  <title>Giriş | Öztoprak İnşaat Muhasebe Sistemi</title>
  <link rel="icon" href="{{ $theme['logo'] ?: asset('assets/favicon.svg') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=Mrs+Saint+Delafield&display=swap">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/login.css') }}">
  {{-- Tema renkleri ve logo kullanılır; giriş sayfası kendi yazı tiplerini ve ölçülerini korur. --}}
</head>
<body class="lp" style="--ink: {{ $theme['menu'] }}; --acc: {{ $theme['accent'] }}; --paper: {{ $theme['bg'] }}">
  <main class="lp-sheet">
    {{-- Sol: mimari pafta görünümü --}}
    <section class="lp-plan" aria-hidden="true">
      <header class="lp-plan-head">
        <span>MUHASEBE · FİNANS · ŞANTİYE</span>
        <span>{{ now()->locale('tr')->translatedFormat('d F Y, l') }}</span>
      </header>

      <div class="lp-title">
        <h1>Öztoprak<br><em>İnşaat</em></h1>
        <p>Her fatura, her hakediş, her kuruş<br>kayıt altında.</p>
      </div>

      <div class="lp-drawwrap"><svg class="lp-drawing" viewBox="0 0 620 420" role="presentation" preserveAspectRatio="xMaxYMax meet">
        <defs>
          <pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="6" /></pattern>
        </defs>
        <!-- zemin -->
        <line class="l2" x1="10" y1="372" x2="610" y2="372" />
        <rect class="hatch" x="10" y="372" width="600" height="10" />
        <!-- tamamlanan katlar -->
        <rect class="l2" x="150" y="172" width="230" height="200" />
        <g class="l1">
          <line x1="150" y1="212" x2="380" y2="212" /><line x1="150" y1="252" x2="380" y2="252" />
          <line x1="150" y1="292" x2="380" y2="292" /><line x1="150" y1="332" x2="380" y2="332" />
        </g>
        <g class="win">
          @foreach ([180, 220, 260, 300] as $y)
            @foreach ([164, 208, 252, 296, 340] as $x)
              <rect x="{{ $x }}" y="{{ $y }}" width="26" height="22" />
            @endforeach
          @endforeach
          <rect x="246" y="340" width="38" height="32" />
        </g>
        <!-- yapım aşamasındaki katlar -->
        <g class="dash">
          <rect x="150" y="92" width="230" height="80" />
          <line x1="150" y1="132" x2="380" y2="132" />
          <line x1="196" y1="92" x2="196" y2="172" /><line x1="242" y1="92" x2="242" y2="172" />
          <line x1="288" y1="92" x2="288" y2="172" /><line x1="334" y1="92" x2="334" y2="172" />
        </g>
        <!-- iskele -->
        <g class="l1">
          <line x1="138" y1="96" x2="138" y2="372" /><line x1="392" y1="96" x2="392" y2="372" />
          @foreach ([132, 172, 212, 252, 292, 332] as $y)
            <line x1="130" y1="{{ $y }}" x2="146" y2="{{ $y }}" /><line x1="384" y1="{{ $y }}" x2="400" y2="{{ $y }}" />
          @endforeach
        </g>
        <!-- kule vinç -->
        <g class="l2">
          <line x1="470" y1="372" x2="470" y2="46" /><line x1="486" y1="372" x2="486" y2="46" />
          <line x1="240" y1="46" x2="590" y2="46" /><line x1="240" y1="58" x2="470" y2="58" />
          <line x1="486" y1="58" x2="590" y2="58" />
          <line x1="478" y1="46" x2="478" y2="16" /><line x1="478" y1="16" x2="300" y2="46" /><line x1="478" y1="16" x2="580" y2="46" />
          <rect x="556" y="58" width="30" height="18" class="fill" />
        </g>
        <g class="l1">
          @for ($y = 372; $y > 60; $y -= 26)
            <line x1="470" y1="{{ $y }}" x2="486" y2="{{ $y - 26 }}" /><line x1="486" y1="{{ $y }}" x2="470" y2="{{ $y - 26 }}" />
          @endfor
          @for ($x = 250; $x < 470; $x += 22)
            <line x1="{{ $x }}" y1="58" x2="{{ $x + 11 }}" y2="46" /><line x1="{{ $x + 11 }}" y1="46" x2="{{ $x + 22 }}" y2="58" />
          @endfor
          <line x1="318" y1="58" x2="318" y2="104" />
        </g>
        <rect class="l2 fill" x="306" y="104" width="24" height="12" />
        <!-- ölçü çizgileri -->
        <g class="dim">
          <line x1="104" y1="92" x2="104" y2="372" /><line x1="98" y1="92" x2="110" y2="92" /><line x1="98" y1="372" x2="110" y2="372" />
          <line x1="150" y1="398" x2="380" y2="398" /><line x1="150" y1="392" x2="150" y2="404" /><line x1="380" y1="392" x2="380" y2="404" />
        </g>
        <text class="mono" x="96" y="236" transform="rotate(-90 96 236)">+28.00</text>
        <text class="mono" x="265" y="414" text-anchor="middle">23.00</text>
        <!-- aks balonları -->
        <g class="axis">
          <circle cx="150" cy="30" r="10" /><text x="150" y="34" text-anchor="middle">A</text>
          <circle cx="380" cy="30" r="10" /><text x="380" y="34" text-anchor="middle">B</text>
        </g>
        <line class="dash" x1="150" y1="40" x2="150" y2="92" /><line class="dash" x1="380" y1="40" x2="380" y2="92" />
      </svg></div>

      <table class="lp-stamp">
        <tr><th>Proje</th><td colspan="3">Öztoprak İnşaat Muhasebe Sistemi</td></tr>
        <tr><th>Pafta</th><td>Giriş</td><th>Ölçek</th><td>1/100</td></tr>
        <tr><th>Geliştirici</th><td colspan="3"><a class="devlink" href="https://alperengundogdu.com.tr" target="_blank" rel="noopener" aria-label="Geliştirici: alperengundogdu.com.tr"><span class="sigswap"><span class="devsig">alperengundogdu</span><span class="devurl">alperengundogdu.com.tr <i aria-hidden="true">↗</i></span></span></a></td></tr>
      </table>
    </section>

    {{-- Sağ: giriş formu --}}
    <section class="lp-form">
      @if($theme['logo'])
        <div class="lp-mark"><img src="{{ $theme['logo'] }}" alt="Öztoprak İnşaat"></div>
      @endif

      <h2>Oturum aç</h2>
      <p class="lp-lead">Yetkili olduğunuz inşaatların muhasebe kayıtlarına erişmek için hesabınızla giriş yapın.</p>

      @if (session('error'))
        <p class="lp-error" role="alert">{{ session('error') }}</p>
      @endif

      <form method="POST" action="{{ url('/giris') }}" novalidate>
        @csrf
        <label class="lp-field">
          <span>E-posta</span>
          <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus spellcheck="false">
        </label>
        <label class="lp-field">
          <span>Şifre</span>
          <span class="lp-pass">
            <input name="password" type="password" autocomplete="current-password" required id="lp-password">
            <button type="button" class="lp-show" aria-controls="lp-password" aria-pressed="false">Göster</button>
          </span>
        </label>
        <label class="lp-remember"><input type="checkbox" name="remember" value="1"><span></span>Bu cihazda oturumum açık kalsın</label>
        <button class="lp-submit" type="submit"><span>Giriş yap</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6" /></svg></button>
      </form>

      <a class="lp-dev" href="https://alperengundogdu.com.tr" target="_blank" rel="noopener" aria-label="Geliştirici: Alperen Gündoğdu Yazılım, alperengundogdu.com.tr">
        <span class="lp-dev-text">
          <small>Tasarım &amp; geliştirme</small>
          <span class="sigswap"><span class="devsig">alperengundogdu</span><span class="devurl">alperengundogdu.com.tr <i aria-hidden="true">↗</i></span></span>
        </span>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8" /></svg>
      </a>
      <p class="lp-copy">© {{ date('Y') }} Öztoprak İnşaat · Tüm hakları saklıdır.</p>
    </section>
  </main>
  <script>
    document.querySelector('.lp-show').addEventListener('click', function () {
      var i = document.getElementById('lp-password'), show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      this.textContent = show ? 'Gizle' : 'Göster';
      this.setAttribute('aria-pressed', show);
      i.focus();
    });
    document.querySelector('.lp form').addEventListener('submit', function () {
      var b = this.querySelector('.lp-submit'); b.disabled = true; b.querySelector('span').textContent = 'Kontrol ediliyor…';
    });
  </script>
</body>
</html>
