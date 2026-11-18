<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Installable app (PWA, D-052) --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#171717">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Synop">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
