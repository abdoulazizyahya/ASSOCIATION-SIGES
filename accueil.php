<?php
// ─────────────────────────────────────────────────────────────────────
//  accueil.php — Portail public PROMEDUCAM · SIGES
//  Rendu par index.php pour un visiteur non connecté sur la racine « / ».
//  (On NE s'appuie PAS sur « DirectoryIndex » en .htaccess : interdit par
//  certains hébergements mutualisés -> Internal Server Error.)
//  « Se connecter » ouvre index.php?login -> login.php (ou le tableau de bord).
//  La grille des écoles est DYNAMIQUE : relit promeducam_assoc.etablissement
//  à chaque affichage -> rien à régénérer quand une école change.
//  Copie statique autonome pour partage : bd/assoc/generer_accueil.php
//  (regénérée aussi à la création/modif/suppression d'une école).
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion_assoc.php';
$APP       = rtrim(APP_URL, '/');
$LOGIN_URL = $APP . '/index.php?login';
$H         = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$ecoles    = (function_exists('annuaire_dispo') && annuaire_dispo())
    ? assoc_all("SELECT nom, ville, sigle, logo FROM etablissement WHERE actif = 1 ORDER BY nom")
    : [];
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Portail PROMEDUCAM · SIGES — Système Intégré de Gestion des Établissements Scolaires. Plateforme bilingue français / arabe : inscriptions, paiements, notes, bulletins et personnel.">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#0f2942">
<meta property="og:title" content="PROMEDUCAM · SIGES — Portail de gestion scolaire">
<meta property="og:description" content="Système Intégré de Gestion des Établissements Scolaires. Plateforme bilingue français / arabe : inscriptions, paiements, notes, bulletins et personnel.">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:locale:alternate" content="ar_AR">
<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2064%2064'%3E%3Crect%20width='64'%20height='64'%20rx='14'%20fill='%230f2942'/%3E%3Ctext%20x='32'%20y='44'%20font-family='Arial,sans-serif'%20font-size='30'%20font-weight='700'%20text-anchor='middle'%20fill='%23d4a12b'%3EPM%3C/text%3E%3C/svg%3E">
<title>PROMEDUCAM · SIGES — Portail de gestion scolaire</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>
  (function () {
    try {
      var d = document.documentElement;
      d.classList.add('js');
      var t = localStorage.getItem('promeducam-theme');
      if (t === 'dark' || t === 'light') d.setAttribute('data-theme', t);
      var l = localStorage.getItem('promeducam-lang');
      if (l === 'ar') { d.lang = 'ar'; d.dir = 'rtl'; }
      else if (l === 'en') { d.lang = 'en'; }
    } catch (e) {}
  })();
</script>
<style>
  :root{
    --navy:#0f2942;
    --navy-2:#153a5c;
    --gold:#d4a12b;
    --gold-2:#e8bd52;
    --ink:#1c2a36;
    --muted:#6b7c8c;
    --bg:#f4f6f8;
    --bg-2:#eaeef3;
    --card:#ffffff;
    --line:#e2e8ee;
    --green:#2f9e5c;
    --chip-bg:#fff4dd;
    --chip-line:#f0d9a3;
    --chip-ink:#8a6413;
    --shadow:0 4px 18px rgba(15,41,66,.06);
    --shadow-lg:0 20px 44px rgba(15,41,66,.13);
    --ring:rgba(212,161,43,.45);
  }
  :root[data-theme="dark"]{
    --ink:#e7eef5;
    --muted:#93a7b9;
    --bg:#0b1a2b;
    --bg-2:#0f2137;
    --card:#122842;
    --line:#213a53;
    --green:#43b676;
    --chip-bg:rgba(212,161,43,.14);
    --chip-line:rgba(212,161,43,.34);
    --chip-ink:#e8bd52;
    --shadow:0 4px 18px rgba(0,0,0,.32);
    --shadow-lg:0 22px 48px rgba(0,0,0,.5);
  }
  @media (prefers-color-scheme:dark){
    :root:not([data-theme="light"]){
      --ink:#e7eef5;
      --muted:#93a7b9;
      --bg:#0b1a2b;
      --bg-2:#0f2137;
      --card:#122842;
      --line:#213a53;
      --green:#43b676;
      --chip-bg:rgba(212,161,43,.14);
      --chip-line:rgba(212,161,43,.34);
      --chip-ink:#e8bd52;
      --shadow:0 4px 18px rgba(0,0,0,.32);
      --shadow-lg:0 22px 48px rgba(0,0,0,.5);
    }
  }

  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  html{scroll-behavior:smooth;}
  body{
    font-family:"Manrope","Segoe UI",Tahoma,Arial,sans-serif;
    background:var(--bg);
    color:var(--ink);
    min-height:100vh;
    display:flex;
    flex-direction:column;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
  }
  body[dir="rtl"]{font-family:"Cairo","Segoe UI",Tahoma,Arial,sans-serif;}
  body::before{
    content:"";
    position:fixed;
    inset:0;
    z-index:-1;
    background:
      radial-gradient(680px 460px at 12% -6%, rgba(212,161,43,.16), transparent 60%),
      radial-gradient(760px 520px at 92% 4%, rgba(21,58,92,.20), transparent 62%);
    pointer-events:none;
  }
  ::selection{background:var(--gold);color:#12202e;}
  a{color:inherit;}
  :focus-visible{outline:3px solid var(--ring);outline-offset:3px;border-radius:8px;}

  .skip{
    position:absolute;left:-999px;top:8px;z-index:100;
    background:var(--gold);color:var(--navy);
    padding:10px 16px;border-radius:10px;font-weight:700;text-decoration:none;
  }
  .skip:focus{left:12px;}

  /* ---------- Top bar ---------- */
  .topbar{
    position:sticky;top:0;z-index:40;
    width:100%;
    background:linear-gradient(90deg,var(--navy),var(--navy-2));
    color:#fff;
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    padding:12px clamp(16px,4vw,32px);
    border-bottom:1px solid rgba(255,255,255,.08);
    backdrop-filter:saturate(1.2);
  }
  .brand{display:flex;align-items:center;gap:12px;min-width:0;flex-shrink:1;}
  .brand-badge{
    width:44px;height:44px;border-radius:11px;flex-shrink:0;
    background:linear-gradient(135deg,var(--gold-2),var(--gold));
    display:flex;align-items:center;justify-content:center;
    font-weight:800;color:var(--navy);font-size:17px;letter-spacing:.5px;
    box-shadow:0 6px 16px rgba(212,161,43,.35);
  }
  .brand-text{min-width:0;}
  .brand-text .name{font-weight:800;font-size:16px;letter-spacing:.6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .brand-text .sub{
    font-size:11px;color:#c9d6e3;letter-spacing:.4px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:52vw;
  }
  .controls{display:flex;align-items:center;gap:8px;flex-shrink:0;}
  .seg{display:flex;gap:4px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);border-radius:22px;padding:3px;}
  .seg button{
    background:transparent;border:0;color:#fff;
    padding:6px 13px;border-radius:18px;font:inherit;font-size:12.5px;font-weight:600;
    cursor:pointer;transition:background .18s,color .18s;line-height:1;
  }
  .seg button[aria-pressed="true"]{background:var(--gold);color:var(--navy);}
  .seg button:hover{background:rgba(255,255,255,.16);}
  .seg button[aria-pressed="true"]:hover{background:var(--gold-2);}
  .icon-btn{
    width:38px;height:38px;flex-shrink:0;
    background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);
    border-radius:50%;color:#fff;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:background .18s;
  }
  .icon-btn:hover{background:rgba(255,255,255,.18);}
  .icon-btn svg{width:18px;height:18px;}
  .icon-btn .moon{display:none;}
  :root[data-theme="dark"] .icon-btn .moon{display:block;}
  :root[data-theme="dark"] .icon-btn .sun{display:none;}
  @media (prefers-color-scheme:dark){
    :root:not([data-theme="light"]) .icon-btn .moon{display:block;}
    :root:not([data-theme="light"]) .icon-btn .sun{display:none;}
  }
  .btn-top{
    display:inline-flex;align-items:center;gap:7px;flex-shrink:0;
    background:var(--gold);color:var(--navy);
    padding:9px 17px;border-radius:21px;
    font-weight:800;font-size:13px;text-decoration:none;white-space:nowrap;
    box-shadow:0 6px 16px rgba(212,161,43,.32);
    transition:background .18s,transform .18s,box-shadow .18s;
  }
  .btn-top:hover{background:var(--gold-2);transform:translateY(-1px);box-shadow:0 9px 20px rgba(212,161,43,.4);}
  .btn-top svg{width:15px;height:15px;}

  /* ---------- Layout ---------- */
  main{flex:1;width:100%;}
  .wrap{width:100%;max-width:1060px;margin:0 auto;padding:0 clamp(18px,5vw,32px);}
  section{padding:clamp(24px,4vw,42px) 0;}
  section + section{border-top:1px solid var(--line);}
  section[id]{scroll-margin-top:84px;}

  .eyebrow{
    display:inline-block;
    font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;
    color:var(--gold);margin-bottom:12px;
  }
  h2{
    font-size:clamp(21px,3.2vw,27px);
    margin:0 0 12px;color:var(--ink);font-weight:800;line-height:1.25;
  }
  .section-sub{color:var(--muted);max-width:620px;margin:0 0 16px;font-size:15px;}
  body[dir="rtl"] .section-sub{margin-left:auto;}

  /* ---------- Hero ---------- */
  .hero{text-align:center;padding-top:clamp(18px,3vw,32px);}
  .hero-inner{display:flex;flex-direction:column;align-items:center;}
  .emblem{width:112px;height:112px;margin-bottom:22px;animation:float 4s ease-in-out infinite;}
  .emblem svg{width:100%;height:100%;display:block;}
  @keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}

  h1{
    font-size:clamp(26px,4.6vw,40px);
    line-height:1.18;margin:0 0 10px;color:var(--navy);font-weight:800;
    max-width:18ch;
  }
  :root[data-theme="dark"] h1{color:var(--ink);}
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]) h1{color:var(--ink);}}
  h1{overflow-wrap:break-word;}
  h1 span{color:var(--gold);}

  .lede{font-size:16px;color:var(--muted);max-width:620px;margin:0 0 16px;}

  .hero-cta{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-bottom:16px;}
  .btn-ghost{
    display:inline-flex;align-items:center;gap:8px;flex-shrink:0;white-space:nowrap;
    padding:13px 24px;border-radius:26px;
    border:1px solid var(--line);background:var(--card);color:var(--ink);
    font-weight:700;font-size:14.5px;text-decoration:none;
    transition:border-color .18s,transform .18s;
  }
  .btn-ghost:hover{border-color:var(--gold);transform:translateY(-2px);}
  .btn-ghost svg{width:16px;height:16px;flex-shrink:0;}
  .btn.btn-lg{padding:14px 30px;font-size:15px;flex-shrink:0;white-space:nowrap;}

  .stats{
    display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:8px;padding:0;
  }
  .stats li{
    list-style:none;
    background:var(--card);border:1px solid var(--line);border-radius:22px;
    padding:8px 15px;font-size:12.5px;font-weight:600;color:var(--ink);
    display:flex;align-items:center;gap:7px;
  }
  .stats li::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--gold);flex-shrink:0;}

  /* ---------- Grids / cards ---------- */
  .grid{display:grid;gap:13px;}
  .grid.mods{grid-template-columns:repeat(auto-fit,minmax(230px,1fr));}
  .grid.roles{grid-template-columns:repeat(auto-fit,minmax(210px,1fr));}
  .card{
    background:var(--card);border:1px solid var(--line);border-radius:14px;
    padding:17px 18px;box-shadow:var(--shadow);
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
  }
  .card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--chip-line);}
  .card .ico{
    width:44px;height:44px;border-radius:11px;margin-bottom:14px;
    background:linear-gradient(135deg,rgba(21,58,92,.12),rgba(212,161,43,.16));
    display:flex;align-items:center;justify-content:center;color:var(--navy-2);
  }
  :root[data-theme="dark"] .card .ico{color:var(--gold-2);background:linear-gradient(135deg,rgba(212,161,43,.14),rgba(21,58,92,.28));}
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .card .ico{color:var(--gold-2);background:linear-gradient(135deg,rgba(212,161,43,.14),rgba(21,58,92,.28));}}
  .card .ico svg{width:24px;height:24px;}
  .card h3{margin:0 0 6px;font-size:15.5px;font-weight:700;color:var(--ink);}
  .card p{margin:0;font-size:13.5px;color:var(--muted);}

  /* ---------- Ecoles membres ---------- */
  .grid.schools{grid-template-columns:repeat(auto-fit,minmax(230px,1fr));}
  .school{
    background:var(--card);border:1px solid var(--line);border-radius:14px;
    padding:22px 18px;box-shadow:var(--shadow);text-align:center;
    display:flex;flex-direction:column;align-items:center;gap:12px;
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
  }
  .school:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--chip-line);}
  .school-logo{
    width:86px;height:86px;border-radius:15px;flex-shrink:0;
    background:#fff;border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;overflow:hidden;
  }
  .school-logo img{width:100%;height:100%;object-fit:contain;}
  .school-logo .mono{font-weight:800;color:var(--navy-2);font-size:13px;line-height:1.15;padding:6px;}
  .school-name{font-weight:700;font-size:14px;color:var(--ink);line-height:1.32;}
  .school-city{font-size:12.5px;color:var(--muted);display:inline-flex;align-items:center;gap:5px;}
  .school-city svg{width:13px;height:13px;flex-shrink:0;}

  /* ---------- Steps ---------- */
  .steps{display:flex;flex-direction:column;gap:2px;max-width:720px;}
  .step{position:relative;display:grid;grid-template-columns:auto 1fr;gap:16px;padding:12px 4px;}
  .step:not(:last-child)::before{
    content:"";position:absolute;
    inset-inline-start:17px;top:44px;bottom:-4px;width:2px;background:var(--line);
  }
  .step.done:not(:last-child)::before{background:var(--green);}
  .marker{
    width:36px;height:36px;border-radius:50%;flex-shrink:0;z-index:1;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;
    background:var(--card);border:2px solid var(--line);color:var(--muted);
  }
  .step.done .marker{background:var(--green);border-color:var(--green);color:#fff;}
  .step.current .marker{
    border-color:var(--gold);color:var(--gold);
    box-shadow:0 0 0 4px var(--ring);animation:beat 1.8s ease-in-out infinite;
  }
  @keyframes beat{0%,100%{box-shadow:0 0 0 4px var(--ring);}50%{box-shadow:0 0 0 8px rgba(212,161,43,.12);}}
  .marker svg{width:18px;height:18px;}
  .step-body h3{margin:0 0 3px;font-size:15.5px;font-weight:700;color:var(--ink);}
  .step-body p{margin:0 0 8px;font-size:13.5px;color:var(--muted);}
  .badge{
    display:inline-block;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
    padding:3px 10px;border-radius:20px;border:1px solid var(--line);color:var(--muted);
  }
  .step.done .badge{background:rgba(47,158,92,.12);border-color:transparent;color:var(--green);}
  .step.current .badge{background:var(--chip-bg);border-color:var(--chip-line);color:var(--chip-ink);}

  /* ---------- Guide ---------- */
  .url-chip{
    display:inline-flex;align-items:center;gap:9px;
    background:var(--card);border:1px solid var(--line);border-radius:10px;
    padding:8px 15px;margin:0 0 12px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:14px;font-weight:700;color:var(--ink);
  }
  .url-chip svg{width:15px;height:15px;color:var(--gold);flex-shrink:0;}
  .notecards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px;max-width:820px;}
  .notecard{
    background:var(--card);border:1px solid var(--line);border-inline-start:3px solid var(--gold);
    border-radius:12px;padding:18px 20px;box-shadow:var(--shadow);
  }
  .notecard h3{margin:0 0 6px;font-size:14.5px;font-weight:700;color:var(--ink);}
  .notecard p{margin:0;font-size:13.5px;color:var(--muted);}

  /* ---------- FAQ ---------- */
  .faq{max-width:760px;display:flex;flex-direction:column;gap:8px;}
  .faq details{
    background:var(--card);border:1px solid var(--line);border-radius:12px;
    padding:2px 18px;box-shadow:var(--shadow);
  }
  .faq summary{
    list-style:none;cursor:pointer;
    padding:15px 28px 15px 0;font-weight:700;font-size:15px;color:var(--ink);position:relative;
  }
  body[dir="rtl"] .faq summary{padding:15px 0 15px 28px;}
  .faq summary::-webkit-details-marker{display:none;}
  .faq summary::after{
    content:"+";position:absolute;inset-inline-end:0;top:50%;transform:translateY(-50%);
    font-size:20px;font-weight:400;color:var(--gold);transition:transform .2s;
  }
  .faq details[open] summary::after{content:"\2013";}
  .faq details[open] summary{border-bottom:1px solid var(--line);}
  .faq p{margin:0;padding:14px 0 16px;font-size:14px;color:var(--muted);}

  /* ---------- Contact ---------- */
  .contact{text-align:center;}
  .contact-card{
    background:linear-gradient(135deg,var(--navy),var(--navy-2));
    border-radius:18px;padding:clamp(28px,5vw,44px);color:#fff;box-shadow:var(--shadow-lg);
  }
  .contact-card .eyebrow{color:var(--gold-2);}
  .contact-card h2{color:#fff;}
  .contact-card p{color:#cdd9e6;max-width:520px;margin:0 auto 16px;font-size:15px;}
  .btn{
    display:inline-flex;align-items:center;gap:9px;
    background:var(--gold);color:var(--navy);
    padding:13px 26px;border-radius:26px;font-weight:800;font-size:14.5px;text-decoration:none;
    transition:transform .18s,box-shadow .18s,background .18s;
    box-shadow:0 10px 24px rgba(212,161,43,.32);
  }
  .btn:hover{transform:translateY(-2px);background:var(--gold-2);box-shadow:0 14px 30px rgba(212,161,43,.4);}
  .btn svg{width:17px;height:17px;}

  /* ---------- Footer ---------- */
  footer{
    background:var(--card);border-top:1px solid var(--line);
    padding:24px 18px;text-align:center;font-size:12.5px;color:var(--muted);
  }
  footer .fbrand{font-weight:800;color:var(--ink);letter-spacing:.5px;}
  footer div{margin:2px 0;}
  .foot-credit{margin-top:8px !important;line-height:1.9;}
  .foot-credit strong{color:var(--ink);font-weight:700;}
  .foot-credit a{color:var(--muted);text-decoration:none;white-space:nowrap;}
  .foot-credit a:hover{color:var(--gold);}
  .foot-sep{opacity:.45;margin:0 5px;}

  /* ---------- Reveal ---------- */
  .js .reveal{opacity:0;transform:translateY(12px);transition:opacity .45s ease,transform .45s ease;}
  .js .reveal.in{opacity:1;transform:none;}

  @media (max-width:720px){
    .brand-text .sub{display:none;}
    section + section{border-top:0;}
    .card{padding:18px 16px;}
  }
  @media (max-width:600px){
    .topbar{flex-wrap:wrap;row-gap:9px;padding-top:10px;padding-bottom:10px;}
    .brand{order:1;}
    .controls{order:2;width:100%;justify-content:flex-end;gap:8px;}
    .seg button{padding:6px 12px;}
    .btn-top{padding:9px 16px;}
  }
  @media (prefers-reduced-motion:reduce){
    html{scroll-behavior:auto;}
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;}
    .js .reveal{opacity:1;transform:none;}
  }
</style>
</head>
<body dir="ltr">

<a class="skip" href="#content" data-i18n="a11y.skip">Aller au contenu</a>

<header class="topbar">
  <div class="brand">
    <div class="brand-badge" aria-hidden="true">PM</div>
    <div class="brand-text">
      <div class="name">PROMEDUCAM</div>
      <div class="sub" data-i18n="brand.sub">SIGES — Système Intégré de Gestion des Établissements Scolaires</div>
    </div>
  </div>
  <div class="controls">
    <div class="seg" role="group" aria-label="Langue / Language / اللغة">
      <button id="lang-fr" type="button" aria-pressed="true" onclick="setLang('fr')">FR</button>
      <button id="lang-en" type="button" aria-pressed="false" onclick="setLang('en')">EN</button>
      <button id="lang-ar" type="button" aria-pressed="false" onclick="setLang('ar')">AR</button>
    </div>
    <button id="theme-btn" class="icon-btn" type="button" aria-label="Thème clair / sombre" onclick="toggleTheme()">
      <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>
      <svg class="moon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 14.5A8 8 0 019.5 4a8.5 8.5 0 100 16.9c3.6 0 6.7-2.2 8-5.4a.6.6 0 00-.7-.8 6.3 6.3 0 01-1.1.2z"/></svg>
    </button>
    <!-- Bouton de connexion : ouvre la page d'accueil de SIGES (voir SIGES_URL dans le script). -->
    <a class="btn-top js-login" href="<?= $H($LOGIN_URL) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/></svg>
      <span data-i18n="ctl.login">Connexion</span>
    </a>
  </div>
</header>

<main id="content">

  <!-- ============ HERO ============ -->
  <section class="hero">
    <div class="wrap hero-inner">
      <div class="emblem" aria-hidden="true">
        <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
          <circle cx="100" cy="100" r="94" fill="rgba(21,58,92,.10)"/>
          <path d="M100 52 24 86l76 34 62-27.7V132a6 6 0 1 0 12 0V88.5L100 52Z" fill="#153a5c"/>
          <path d="M62 118v22c0 12.7 17 23 38 23s38-10.3 38-23v-22l-38 17-38-17Z" fill="#d4a12b"/>
        </svg>
      </div>

      <h1 data-i18n-html="h1">Portail <span>PROMEDUCAM · SIGES</span></h1>
      <p class="lede" data-i18n="lede">SIGES centralise tout le pilotage de votre établissement : de l'inscription d'un élève à la remise de son bulletin, en passant par les paiements, les notes et la gestion du personnel. Une plateforme trilingue (français, anglais, arabe) qui s'adapte au rôle de chaque utilisateur.</p>

      <div class="hero-cta">
        <a class="btn btn-lg js-login" href="<?= $H($LOGIN_URL) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/></svg>
          <span data-i18n="cta.login">Se connecter</span>
        </a>
        <a class="btn-ghost" href="#guide">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
          <span data-i18n="cta.guide">Voir le guide</span>
        </a>
      </div>

      <ul class="stats">
        <li data-i18n="stat.1">5 modules clés</li>
        <li data-i18n="stat.2">Trilingue FR · EN · AR</li>
        <li data-i18n="stat.3">Aucune installation</li>
        <li data-i18n="stat.4">Accès selon le rôle</li>
      </ul>
    </div>
  </section>

  <!-- ============ MODULES ============ -->
  <section class="reveal">
    <div class="wrap">
      <span class="eyebrow" data-i18n="sec.modules.eyebrow">La plateforme</span>
      <h2 data-i18n="sec.modules.title">Tout l'établissement, dans un seul outil</h2>
      <p class="section-sub" data-i18n="sec.modules.sub">Cinq domaines connectés entre eux, plus le pilotage de l'association.</p>
      <div class="grid mods">
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg></div>
          <h3 data-i18n="mod.1.t">Inscriptions &amp; élèves</h3>
          <p data-i18n="mod.1.d">Dossiers, classes, effectifs et matricules centralisés.</p>
        </div>
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/></svg></div>
          <h3 data-i18n="mod.2.t">Paiements &amp; scolarité</h3>
          <p data-i18n="mod.2.d">Échéanciers, reçus, relances et suivi des impayés.</p>
        </div>
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 12l2.5 2.5L16 9"/></svg></div>
          <h3 data-i18n="mod.3.t">Notes &amp; évaluations</h3>
          <p data-i18n="mod.3.d">Saisie des notes, moyennes et appréciations par matière.</p>
        </div>
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10a2 2 0 012 2v16l-7-3-7 3V5a2 2 0 012-2z"/><path d="M9 8h6M9 12h6"/></svg></div>
          <h3 data-i18n="mod.4.t">Bulletins &amp; résultats</h3>
          <p data-i18n="mod.4.d">Génération automatique des bulletins et des palmarès.</p>
        </div>
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9.5" r="2.4"/><path d="M3 20c0-3.2 2.7-5 6-5s6 1.8 6 5M16 20c0-2.3 1.6-3.9 4-3.9"/></svg></div>
          <h3 data-i18n="mod.5.t">Personnel &amp; affectations</h3>
          <p data-i18n="mod.5.d">Fiches agents, rôles et affectation entre établissements.</p>
        </div>
        <div class="card">
          <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></div>
          <h3 data-i18n="mod.6.t">Pilotage de l'association</h3>
          <p data-i18n="mod.6.d">Tableau de bord multi-écoles, journal d'activité et sécurité.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ============ ECOLES MEMBRES ============ -->
  <section class="reveal">
    <div class="wrap">
      <span class="eyebrow" data-i18n="sec.schools.eyebrow">L'association</span>
      <h2 data-i18n="sec.schools.title">Les écoles membres</h2>
      <p class="section-sub" data-i18n="sec.schools.sub">Les établissements qui utilisent SIGES au sein de PROMEDUCAM.</p>
      <div class="grid schools">
<?php if (!$ecoles): ?>
        <p class="section-sub" style="grid-column:1/-1;text-align:center" data-i18n="sec.schools.empty">La liste des établissements s'affichera ici.</p>
<?php endif; ?>
<?php foreach ($ecoles as $e):
        $logo = trim((string) $e['logo']);
        $ok   = $logo !== '' && is_file(__DIR__ . '/assets/uploads/' . $logo);
        $mono = $e['sigle'] ?: $e['nom']; ?>
        <div class="school">
          <div class="school-logo"><?php if ($ok): ?><img src="<?= $H($APP . '/assets/uploads/' . $logo) ?>" alt="Logo <?= $H($e['nom']) ?>" loading="lazy"><?php else: ?><span class="mono"><?= $H($mono) ?></span><?php endif; ?></div>
          <div class="school-name"><?= $H($e['nom']) ?></div>
<?php if ($e['ville']): ?>          <div class="school-city"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.5-7-11a7 7 0 0114 0c0 4.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg><?= $H($e['ville']) ?></div>
<?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ============ GUIDE DE DEMARRAGE ============ -->
  <section class="reveal" id="guide">
    <div class="wrap">
      <span class="eyebrow" data-i18n="sec.guide.eyebrow">Guide de démarrage</span>
      <h2 data-i18n="sec.guide.title">Se connecter à SIGES, pas à pas</h2>
      <p class="section-sub" data-i18n="sec.guide.sub">Adresse du système, à saisir dans le navigateur :</p>
      <p class="url-chip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18"/></svg>
        <span dir="ltr">promeducam.beero.cm</span>
      </p>

      <div class="steps guide">
        <div class="step">
          <div class="marker">1</div>
          <div class="step-body">
            <h3 data-i18n="guide.1.t">Ouvrir la page de connexion</h3>
            <p data-i18n="guide.1.d">Rendez-vous sur promeducam.beero.cm, ou cliquez sur le bouton « Connexion » en haut à droite de cette page.</p>
          </div>
        </div>
        <div class="step">
          <div class="marker">2</div>
          <div class="step-body">
            <h3 data-i18n="guide.2.t">Choisir votre établissement</h3>
            <p data-i18n="guide.2.d">Si une liste d'écoles apparaît, sélectionnez la vôtre. Sinon, passez directement à l'étape suivante.</p>
          </div>
        </div>
        <div class="step">
          <div class="marker">3</div>
          <div class="step-body">
            <h3 data-i18n="guide.3.t">Saisir vos identifiants</h3>
            <p data-i18n="guide.3.d">Entrez l'identifiant et le mot de passe remis par la direction, puis cliquez sur le bouton bleu « Se connecter ».</p>
          </div>
        </div>
        <div class="step">
          <div class="marker">4</div>
          <div class="step-body">
            <h3 data-i18n="guide.4.t">Définir vos 2 questions secrètes</h3>
            <p data-i18n="guide.4.d">À la première connexion, le système demande de choisir 2 questions secrètes différentes et d'y répondre. C'est obligatoire : ces réponses vous permettront de récupérer seul votre mot de passe, sans passer par la direction. Notez-les — la casse et les espaces n'ont pas d'importance.</p>
          </div>
        </div>
        <div class="step">
          <div class="marker">5</div>
          <div class="step-body">
            <h3 data-i18n="guide.5.t">Accéder à votre espace</h3>
            <p data-i18n="guide.5.d">Vous arrivez sur le tableau de bord, adapté à votre rôle (direction, enseignant, comptabilité…).</p>
          </div>
        </div>
      </div>

      <div class="notecards">
        <div class="notecard">
          <h3 data-i18n="guide.pwd.t">🔑 Mot de passe oublié ?</h3>
          <p data-i18n="guide.pwd.d">Sur la page de connexion, cliquez sur « Mot de passe oublié ? », saisissez votre identifiant, répondez à vos 2 questions secrètes, puis choisissez un nouveau mot de passe. Après 5 réponses incorrectes, la procédure recommence depuis le début.</p>
        </div>
        <div class="notecard">
          <h3 data-i18n="guide.btn.t">📍 Où est le bouton de connexion ?</h3>
          <p data-i18n="guide.btn.d">Sur cette page : bouton « Connexion », en haut à droite. Sur promeducam.beero.cm : bouton bleu « Se connecter », au centre de l'écran.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ============ ROLES ============ -->
  <section class="reveal">
    <div class="wrap">
      <span class="eyebrow" data-i18n="sec.roles.eyebrow">Pour qui ?</span>
      <h2 data-i18n="sec.roles.title">Une vue adaptée à chaque rôle</h2>
      <p class="section-sub" data-i18n="sec.roles.sub">Chacun accède uniquement à ce qui le concerne.</p>
      <div class="grid roles">
        <div class="card"><h3 data-i18n="role.1.t">Direction</h3><p data-i18n="role.1.d">Vue complète : effectifs, finances et résultats.</p></div>
        <div class="card"><h3 data-i18n="role.2.t">Enseignants</h3><p data-i18n="role.2.d">Saisie des notes et suivi de leurs classes.</p></div>
        <div class="card"><h3 data-i18n="role.3.t">Comptabilité</h3><p data-i18n="role.3.d">Encaissements, reçus et état des paiements.</p></div>
        <div class="card"><h3 data-i18n="role.4.t">Parents &amp; élèves</h3><p data-i18n="role.4.d">Consultation des bulletins et des soldes.</p></div>
        <div class="card"><h3 data-i18n="role.5.t">Bureau de l'association</h3><p data-i18n="role.5.d">Pilotage de plusieurs établissements à la fois.</p></div>
      </div>
    </div>
  </section>

  <!-- ============ FAQ ============ -->
  <section class="reveal">
    <div class="wrap">
      <span class="eyebrow" data-i18n="sec.faq.eyebrow">Questions fréquentes</span>
      <h2 data-i18n="sec.faq.title">Ce que vous vous demandez sûrement</h2>
      <div class="faq">
        <details open>
          <summary data-i18n="faq.1.q">Qu'est-ce que SIGES ?</summary>
          <p data-i18n="faq.1.a">SIGES est la plateforme de gestion de votre établissement : inscriptions, paiements, notes, bulletins et personnel, réunis dans un seul outil accessible depuis un navigateur.</p>
        </details>
        <details>
          <summary data-i18n="faq.2.q">Comment obtenir un compte ?</summary>
          <p data-i18n="faq.2.a">Les comptes sont créés par la direction de votre établissement. Elle vous remet votre identifiant et un mot de passe provisoire à changer à la première connexion.</p>
        </details>
        <details>
          <summary data-i18n="faq.3.q">Dois-je m'inscrire ou installer quelque chose ?</summary>
          <p data-i18n="faq.3.a">Non. SIGES fonctionne dans le navigateur, sans installation. Vos identifiants vous seront remis par la direction de votre établissement.</p>
        </details>
        <details>
          <summary data-i18n="faq.4.q">Mes données sont-elles en sécurité ?</summary>
          <p data-i18n="faq.4.a">Les données de chaque école sont isolées et hébergées sur le serveur de l'association, avec sauvegardes régulières et double authentification pour les comptes administrateurs.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- ============ CONTACT ============ -->
  <section class="contact reveal">
    <div class="wrap">
      <div class="contact-card">
        <span class="eyebrow" data-i18n="sec.contact.eyebrow">Besoin d'aide ?</span>
        <h2 data-i18n="sec.contact.title">Une question ?</h2>
        <p data-i18n="sec.contact.text">Contactez la direction de votre établissement ou le bureau de l'association PROMEDUCAM.</p>
        <a class="btn" href="mailto:abdoulazizyahya@gmail.com">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>
          <span data-i18n="contact.btn">Écrire à PROMEDUCAM</span>
        </a>
      </div>
    </div>
  </section>

</main>

<footer>
  <div class="fbrand">PROMEDUCAM</div>
  <div data-i18n="foot.text">Système Intégré de Gestion des Établissements Scolaires (SIGES)</div>
  <div class="foot-credit">
    <span data-i18n="foot.credit">Conçu et développé par</span>
    <strong data-i18n="foot.designer">Ing. ABDOUL-AZIZ Yahya</strong>
    <span class="foot-sep" aria-hidden="true">·</span>
    <a href="tel:+237699758612" dir="ltr">+237&nbsp;699&nbsp;758&nbsp;612</a>
    <span class="foot-sep" aria-hidden="true">/</span>
    <a href="tel:+237621400800" dir="ltr">621&nbsp;400&nbsp;800</a>
  </div>
  <div>© <span id="year"></span> — <span data-i18n="foot.rights">Tous droits réservés</span></div>
</footer>

<script>
  var DICT = {
    fr: {
      "meta.title": "PROMEDUCAM · SIGES — Portail de gestion scolaire",
      "a11y.skip": "Aller au contenu",
      "brand.sub": "SIGES — Système Intégré de Gestion des Établissements Scolaires",
      "h1": "Portail <span>PROMEDUCAM · SIGES</span>",
      "lede": "SIGES centralise tout le pilotage de votre établissement : de l'inscription d'un élève à la remise de son bulletin, en passant par les paiements, les notes et la gestion du personnel. Une plateforme trilingue (français, anglais, arabe) qui s'adapte au rôle de chaque utilisateur.",
      "cta.login": "Se connecter",
      "cta.guide": "Voir le guide",
      "stat.1": "5 modules clés",
      "stat.2": "Trilingue FR · EN · AR",
      "stat.3": "Aucune installation",
      "stat.4": "Accès selon le rôle",
      "sec.modules.eyebrow": "La plateforme",
      "sec.modules.title": "Tout l'établissement, dans un seul outil",
      "sec.modules.sub": "Cinq domaines connectés entre eux, plus le pilotage de l'association.",
      "mod.1.t": "Inscriptions & élèves", "mod.1.d": "Dossiers, classes, effectifs et matricules centralisés.",
      "mod.2.t": "Paiements & scolarité", "mod.2.d": "Échéanciers, reçus, relances et suivi des impayés.",
      "mod.3.t": "Notes & évaluations", "mod.3.d": "Saisie des notes, moyennes et appréciations par matière.",
      "mod.4.t": "Bulletins & résultats", "mod.4.d": "Génération automatique des bulletins et des palmarès.",
      "mod.5.t": "Personnel & affectations", "mod.5.d": "Fiches agents, rôles et affectation entre établissements.",
      "mod.6.t": "Pilotage de l'association", "mod.6.d": "Tableau de bord multi-écoles, journal d'activité et sécurité.",
      "sec.schools.eyebrow": "L'association",
      "sec.schools.title": "Les écoles membres",
      "sec.schools.sub": "Les établissements qui utilisent SIGES au sein de PROMEDUCAM.", "sec.schools.empty": "La liste des établissements s'affichera ici.",
      "ctl.login": "Connexion",
      "sec.guide.eyebrow": "Guide de démarrage",
      "sec.guide.title": "Se connecter à SIGES, pas à pas",
      "sec.guide.sub": "Adresse du système, à saisir dans le navigateur :",
      "guide.1.t": "Ouvrir la page de connexion",
      "guide.1.d": "Rendez-vous sur promeducam.beero.cm, ou cliquez sur le bouton « Connexion » en haut à droite de cette page.",
      "guide.2.t": "Choisir votre établissement",
      "guide.2.d": "Si une liste d'écoles apparaît, sélectionnez la vôtre. Sinon, passez directement à l'étape suivante.",
      "guide.3.t": "Saisir vos identifiants",
      "guide.3.d": "Entrez l'identifiant et le mot de passe remis par la direction, puis cliquez sur le bouton bleu « Se connecter ».",
      "guide.4.t": "Définir vos 2 questions secrètes",
      "guide.4.d": "À la première connexion, le système demande de choisir 2 questions secrètes différentes et d'y répondre. C'est obligatoire : ces réponses vous permettront de récupérer seul votre mot de passe, sans passer par la direction. Notez-les — la casse et les espaces n'ont pas d'importance.",
      "guide.5.t": "Accéder à votre espace",
      "guide.5.d": "Vous arrivez sur le tableau de bord, adapté à votre rôle (direction, enseignant, comptabilité…).",
      "guide.pwd.t": "🔑 Mot de passe oublié ?",
      "guide.pwd.d": "Sur la page de connexion, cliquez sur « Mot de passe oublié ? », saisissez votre identifiant, répondez à vos 2 questions secrètes, puis choisissez un nouveau mot de passe. Après 5 réponses incorrectes, la procédure recommence depuis le début.",
      "guide.btn.t": "📍 Où est le bouton de connexion ?",
      "guide.btn.d": "Sur cette page : bouton « Connexion », en haut à droite. Sur promeducam.beero.cm : bouton bleu « Se connecter », au centre de l'écran.",
      "sec.roles.eyebrow": "Pour qui ?",
      "sec.roles.title": "Une vue adaptée à chaque rôle",
      "sec.roles.sub": "Chacun accède uniquement à ce qui le concerne.",
      "role.1.t": "Direction", "role.1.d": "Vue complète : effectifs, finances et résultats.",
      "role.2.t": "Enseignants", "role.2.d": "Saisie des notes et suivi de leurs classes.",
      "role.3.t": "Comptabilité", "role.3.d": "Encaissements, reçus et état des paiements.",
      "role.4.t": "Parents & élèves", "role.4.d": "Consultation des bulletins et des soldes.",
      "role.5.t": "Bureau de l'association", "role.5.d": "Pilotage de plusieurs établissements à la fois.",
      "sec.faq.eyebrow": "Questions fréquentes",
      "sec.faq.title": "Ce que vous vous demandez sûrement",
      "faq.1.q": "Qu'est-ce que SIGES ?",
      "faq.1.a": "SIGES est la plateforme de gestion de votre établissement : inscriptions, paiements, notes, bulletins et personnel, réunis dans un seul outil accessible depuis un navigateur.",
      "faq.2.q": "Comment obtenir un compte ?",
      "faq.2.a": "Les comptes sont créés par la direction de votre établissement. Elle vous remet votre identifiant et un mot de passe provisoire à changer à la première connexion.",
      "faq.3.q": "Dois-je m'inscrire ou installer quelque chose ?",
      "faq.3.a": "Non. SIGES fonctionne dans le navigateur, sans installation. Vos identifiants vous seront remis par la direction de votre établissement.",
      "faq.4.q": "Mes données sont-elles en sécurité ?",
      "faq.4.a": "Les données de chaque école sont isolées et hébergées sur le serveur de l'association, avec sauvegardes régulières et double authentification pour les comptes administrateurs.",
      "sec.contact.eyebrow": "Besoin d'aide ?",
      "sec.contact.title": "Une question ?",
      "sec.contact.text": "Contactez la direction de votre établissement ou le bureau de l'association PROMEDUCAM.",
      "contact.btn": "Écrire à PROMEDUCAM",
      "foot.text": "Système Intégré de Gestion des Établissements Scolaires (SIGES)",
      "foot.credit": "Conçu et développé par",
      "foot.designer": "Ing. ABDOUL-AZIZ Yahya",
      "foot.rights": "Tous droits réservés",
      "ctl.theme": "Thème clair / sombre"
    },
    en: {
      "meta.title": "PROMEDUCAM · SIGES — School Management Portal",
      "a11y.skip": "Skip to content",
      "brand.sub": "SIGES — Integrated School Management System",
      "h1": "The <span>PROMEDUCAM · SIGES</span> portal",
      "lede": "SIGES brings your entire school into a single tool: from enrolling a student to issuing their report card, covering fees, grades and staff management. A trilingual platform (French, English, Arabic) that adapts to each user's role.",
      "cta.login": "Log in",
      "cta.guide": "View the guide",
      "stat.1": "5 core modules",
      "stat.2": "Trilingual FR · EN · AR",
      "stat.3": "No installation",
      "stat.4": "Role-based access",
      "sec.modules.eyebrow": "The platform",
      "sec.modules.title": "Your whole school, in one tool",
      "sec.modules.sub": "Five connected areas, plus association-wide oversight.",
      "mod.1.t": "Enrolment & students", "mod.1.d": "Records, classes, headcounts and student IDs in one place.",
      "mod.2.t": "Fees & tuition", "mod.2.d": "Payment schedules, receipts, reminders and arrears tracking.",
      "mod.3.t": "Grades & assessment", "mod.3.d": "Enter marks, averages and subject comments.",
      "mod.4.t": "Report cards & results", "mod.4.d": "Automatic report cards and honour rolls.",
      "mod.5.t": "Staff & assignments", "mod.5.d": "Staff files, roles and cross-school assignment.",
      "mod.6.t": "Association oversight", "mod.6.d": "Multi-school dashboard, activity log and security.",
      "sec.schools.eyebrow": "The association",
      "sec.schools.title": "Member schools",
      "sec.schools.sub": "The schools using SIGES within PROMEDUCAM.", "sec.schools.empty": "The list of schools will appear here.",
      "ctl.login": "Log in",
      "sec.guide.eyebrow": "Getting started",
      "sec.guide.title": "Logging in to SIGES, step by step",
      "sec.guide.sub": "System address, to type into your browser:",
      "guide.1.t": "Open the login page",
      "guide.1.d": "Go to promeducam.beero.cm, or click the “Log in” button at the top right of this page.",
      "guide.2.t": "Choose your school",
      "guide.2.d": "If a list of schools appears, select yours. Otherwise, go straight to the next step.",
      "guide.3.t": "Enter your credentials",
      "guide.3.d": "Type the username and password given to you by the school office, then click the blue “Log in” button.",
      "guide.4.t": "Set your 2 security questions",
      "guide.4.d": "On first login, the system asks you to choose 2 different security questions and answer them. This is required: these answers let you recover your password on your own, without the school office. Write them down — capitalisation and spaces don't matter.",
      "guide.5.t": "Reach your workspace",
      "guide.5.d": "You land on the dashboard, tailored to your role (management, teacher, accounting…).",
      "guide.pwd.t": "🔑 Forgot your password?",
      "guide.pwd.d": "On the login page, click “Forgot password?”, enter your username, answer your 2 security questions, then choose a new password. After 5 wrong answers, the process restarts from the beginning.",
      "guide.btn.t": "📍 Where is the login button?",
      "guide.btn.d": "On this page: the “Log in” button, top right. On promeducam.beero.cm: the blue “Log in” button, in the centre of the screen.",
      "sec.roles.eyebrow": "Who is it for?",
      "sec.roles.title": "A view tailored to each role",
      "sec.roles.sub": "Everyone sees only what concerns them.",
      "role.1.t": "Management", "role.1.d": "Full view: enrolment, finances and results.",
      "role.2.t": "Teachers", "role.2.d": "Enter grades and follow their classes.",
      "role.3.t": "Accounting", "role.3.d": "Payments, receipts and payment status.",
      "role.4.t": "Parents & students", "role.4.d": "View report cards and balances.",
      "role.5.t": "Association office", "role.5.d": "Oversee several schools at once.",
      "sec.faq.eyebrow": "FAQ",
      "sec.faq.title": "What you're probably wondering",
      "faq.1.q": "What is SIGES?",
      "faq.1.a": "SIGES is your school's management platform: enrolment, fees, grades, report cards and staff, brought together in a single browser-based tool.",
      "faq.2.q": "How do I get an account?",
      "faq.2.a": "Accounts are created by your school's management. They give you your username and a temporary password to change on first login.",
      "faq.3.q": "Do I need to register or install anything?",
      "faq.3.a": "No. SIGES runs in the browser, with no installation. Your credentials will be given to you by your school's management.",
      "faq.4.q": "Is my data safe?",
      "faq.4.a": "Each school's data is isolated and hosted on the association's server, with regular backups and two-factor authentication for administrator accounts.",
      "sec.contact.eyebrow": "Need help?",
      "sec.contact.title": "A question?",
      "sec.contact.text": "Contact your school's management or the PROMEDUCAM association office.",
      "contact.btn": "Email PROMEDUCAM",
      "foot.text": "Integrated School Management System (SIGES)",
      "foot.credit": "Designed and developed by",
      "foot.designer": "Eng. ABDOUL-AZIZ Yahya",
      "foot.rights": "All rights reserved",
      "ctl.theme": "Light / dark theme"
    },
    ar: {
      "meta.title": "بروميديكام · سيجيس — بوابة الإدارة المدرسية",
      "a11y.skip": "الانتقال إلى المحتوى",
      "brand.sub": "سيجيس — النظام المتكامل لإدارة المؤسسات المدرسية",
      "h1": "بوابة <span>بروميديكام · سيجيس</span>",
      "lede": "يوفّر نظام سيجيس إدارة شاملة لمؤسستكم التعليمية: من تسجيل التلميذ إلى تسليم كشف نقاطه، مرورًا بالمدفوعات والنقاط وإدارة الموظفين. منصة ثلاثية اللغة (الفرنسية والإنجليزية والعربية) تتكيّف مع دور كل مستخدم.",
      "cta.login": "تسجيل الدخول",
      "cta.guide": "دليل الاستخدام",
      "stat.1": "5 وحدات رئيسية",
      "stat.2": "ثلاثية اللغة FR · EN · AR",
      "stat.3": "بدون تثبيت",
      "stat.4": "دخول حسب الدور",
      "sec.modules.eyebrow": "المنصة",
      "sec.modules.title": "كامل المؤسسة في أداة واحدة",
      "sec.modules.sub": "خمسة مجالات مترابطة، إضافة إلى إدارة الجمعية.",
      "mod.1.t": "التسجيلات والتلاميذ", "mod.1.d": "الملفات والفصول والأعداد وأرقام التسجيل في مكان واحد.",
      "mod.2.t": "المدفوعات والرسوم", "mod.2.d": "جداول الدفع والإيصالات والتذكيرات ومتابعة المتأخرات.",
      "mod.3.t": "النقاط والتقييمات", "mod.3.d": "إدخال النقاط والمعدلات والملاحظات لكل مادة.",
      "mod.4.t": "كشوف النقاط والنتائج", "mod.4.d": "إنشاء تلقائي لكشوف النقاط ولوائح التفوّق.",
      "mod.5.t": "الموظفون والتعيينات", "mod.5.d": "بطاقات الموظفين والأدوار والتعيين بين المؤسسات.",
      "mod.6.t": "إدارة الجمعية", "mod.6.d": "لوحة قيادة متعددة المدارس وسجل النشاط والأمان.",
      "sec.schools.eyebrow": "الجمعية",
      "sec.schools.title": "المدارس الأعضاء",
      "sec.schools.sub": "المؤسسات التي تستخدم سيجيس ضمن بروميديكام.", "sec.schools.empty": "ستظهر قائمة المؤسسات هنا.",
      "ctl.login": "دخول",
      "sec.guide.eyebrow": "دليل البدء",
      "sec.guide.title": "الدخول إلى سيجيس خطوة بخطوة",
      "sec.guide.sub": "عنوان النظام، يُكتب في المتصفح:",
      "guide.1.t": "افتح صفحة الدخول",
      "guide.1.d": "انتقل إلى promeducam.beero.cm، أو اضغط زر «دخول» في أعلى هذه الصفحة.",
      "guide.2.t": "اختر مؤسستك",
      "guide.2.d": "إذا ظهرت قائمة بالمدارس، فاختر مدرستك. وإلا فانتقل مباشرة إلى الخطوة التالية.",
      "guide.3.t": "أدخل بيانات الدخول",
      "guide.3.d": "أدخل المعرّف وكلمة المرور المسلَّمَين من الإدارة، ثم اضغط الزر الأزرق «تسجيل الدخول».",
      "guide.4.t": "حدِّد سؤالَي الأمان",
      "guide.4.d": "عند أول دخول، يطلب النظام اختيار سؤالَي أمان مختلفين والإجابة عنهما. هذا إلزامي: تتيح لك هذه الإجابات استعادة كلمة المرور بنفسك دون الرجوع إلى الإدارة. دوِّنها — لا فرق بين الحروف الكبيرة والصغيرة ولا تُحتسب المسافات.",
      "guide.5.t": "ادخل إلى مساحتك",
      "guide.5.d": "تصل إلى لوحة القيادة المناسبة لدورك (إدارة، معلّم، محاسبة…).",
      "guide.pwd.t": "🔑 نسيت كلمة المرور؟",
      "guide.pwd.d": "في صفحة الدخول، اضغط «نسيت كلمة المرور؟»، وأدخل معرّفك، وأجب عن سؤالَي الأمان، ثم اختر كلمة مرور جديدة. بعد 5 إجابات خاطئة تبدأ العملية من جديد.",
      "guide.btn.t": "📍 أين زر الدخول؟",
      "guide.btn.d": "في هذه الصفحة: زر «دخول» في أعلى الصفحة. في promeducam.beero.cm: الزر الأزرق «تسجيل الدخول» في وسط الشاشة.",
      "sec.roles.eyebrow": "لمن؟",
      "sec.roles.title": "واجهة مناسبة لكل دور",
      "sec.roles.sub": "كل مستخدم يرى ما يخصّه فقط.",
      "role.1.t": "الإدارة", "role.1.d": "رؤية شاملة: الأعداد والمالية والنتائج.",
      "role.2.t": "المعلمون", "role.2.d": "إدخال النقاط ومتابعة فصولهم.",
      "role.3.t": "المحاسبة", "role.3.d": "التحصيلات والإيصالات وحالة المدفوعات.",
      "role.4.t": "أولياء الأمور والتلاميذ", "role.4.d": "الاطّلاع على كشوف النقاط والأرصدة.",
      "role.5.t": "مكتب الجمعية", "role.5.d": "إدارة عدة مؤسسات في آنٍ واحد.",
      "sec.faq.eyebrow": "أسئلة شائعة",
      "sec.faq.title": "ما تتساءلون عنه غالبًا",
      "faq.1.q": "ما هو نظام سيجيس؟",
      "faq.1.a": "سيجيس منصة إدارة مؤسستكم: التسجيلات والمدفوعات والنقاط وكشوف النقاط والموظفون في أداة واحدة تُستخدَم عبر المتصفح.",
      "faq.2.q": "كيف أحصل على حساب؟",
      "faq.2.a": "تُنشئ إدارة مؤسستكم الحسابات، وتسلّمكم المعرّف وكلمة مرور مؤقتة تُغيَّر عند أول دخول.",
      "faq.3.q": "هل عليّ التسجيل أو تثبيت شيء؟",
      "faq.3.a": "لا. يعمل سيجيس داخل المتصفح دون تثبيت. ستصلكم بيانات الدخول من إدارة مؤسستكم.",
      "faq.4.q": "هل بياناتي آمنة؟",
      "faq.4.a": "بيانات كل مدرسة معزولة ومستضافة على خادم الجمعية، مع نسخ احتياطي منتظم وتحقّق ثنائي لحسابات المسؤولين.",
      "sec.contact.eyebrow": "بحاجة إلى مساعدة؟",
      "sec.contact.title": "هل لديكم سؤال؟",
      "sec.contact.text": "تواصلوا مع إدارة مؤسستكم أو مع مكتب جمعية بروميديكام.",
      "contact.btn": "مراسلة بروميديكام",
      "foot.text": "النظام المتكامل لإدارة المؤسسات المدرسية (سيجيس)",
      "foot.credit": "تصميم وتطوير",
      "foot.designer": "المهندس عبد العزيز يحيى",
      "foot.rights": "جميع الحقوق محفوظة",
      "ctl.theme": "السمة الفاتحة / الداكنة"
    }
  };

  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  var LANGS = ['fr', 'en', 'ar'];

  function setLang(lang) {
    if (LANGS.indexOf(lang) === -1) lang = 'fr';
    var dict = DICT[lang];
    var root = document.documentElement;
    root.lang = lang;
    root.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.body.dir = root.dir;
    document.title = dict['meta.title'];

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      var v = dict[el.getAttribute('data-i18n')];
      if (v != null) el.textContent = v;
    });
    document.querySelectorAll('[data-i18n-html]').forEach(function (el) {
      var v = dict[el.getAttribute('data-i18n-html')];
      if (v != null) el.innerHTML = v;
    });
    document.querySelectorAll('[data-i18n-aria]').forEach(function (el) {
      var v = dict[el.getAttribute('data-i18n-aria')];
      if (v != null) el.setAttribute('aria-label', v);
    });

    LANGS.forEach(function (l) {
      var b = document.getElementById('lang-' + l);
      if (b) b.setAttribute('aria-pressed', String(l === lang));
    });
    var tb = document.getElementById('theme-btn');
    if (tb) tb.setAttribute('aria-label', dict['ctl.theme']);

    store.set('promeducam-lang', lang);
  }

  function currentTheme() {
    var explicit = document.documentElement.getAttribute('data-theme');
    if (explicit) return explicit;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function toggleTheme() {
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    store.set('promeducam-theme', next);
  }

  // Adresse d'accueil de SIGES ouverte par les boutons « Connexion ».
  // >>> Changez cette seule ligne si l'adresse du systeme evolue. <<<
  var SIGES_URL = <?= json_encode($LOGIN_URL) ?>;
  document.querySelectorAll('.js-login').forEach(function (a) { a.href = SIGES_URL; });

  document.getElementById('year').textContent = new Date().getFullYear();
  setLang(store.get('promeducam-lang') || 'fr');

  (function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var items = document.querySelectorAll('.reveal');
    function showAll() { items.forEach(function (el) { el.classList.add('in'); }); }

    if (reduce || !('IntersectionObserver' in window)) { showAll(); return; }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(function (el) { io.observe(el); });

    // Filets de securite : rien ne doit rester invisible si l'observer ne se declenche pas.
    window.addEventListener('load', function () {
      items.forEach(function (el) {
        if (el.getBoundingClientRect().top < window.innerHeight) el.classList.add('in');
      });
    });
    setTimeout(showAll, 2500);
  })();
</script>
</body>
</html>
