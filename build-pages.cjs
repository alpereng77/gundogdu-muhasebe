/* Author: Gündoğdu — HTML dosyalarını ortak şablondan yeniden üretir. */
const fs=require('node:fs');const vm=require('node:vm');const path=require('node:path');
const root=__dirname;const source=fs.readFileSync(path.join(root,'assets/app.js'),'utf8');const ctx=vm.createContext({console,Date,Intl,URLSearchParams});
vm.runInContext(source+'\nglobalThis.output={PAGES,state,shell};',ctx);
for(const [file,label] of ctx.output.PAGES){ctx.output.state.page=label;const html=`<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Gündoğdu">
  <meta name="description" content="Gündoğdu İnşaat Muhasebe Takip Paneli — ${label}">
  <meta name="theme-color" content="#1b3c48">
  <title>${label} | Gündoğdu İnşaat</title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/tailwind.css">
  <link rel="stylesheet" href="assets/components.css">
  <script src="assets/app.js" defer></script>
</head>
<body data-page="${label}">
  <!-- Author: Gündoğdu. Sayfalar doğrudan HTML içerir; ortak JS etkileşimleri yönetir. -->
  <noscript><p style="padding:16px;background:#fff8e8">Kayıt ekleme, filtreleme ve hesaplamalar için JavaScript'i etkinleştirin.</p></noscript>
  <div id="app-root">${ctx.output.shell()}</div>
</body>
</html>
`;fs.writeFileSync(path.join(root,file),html);console.log(file)}
