<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — Infinity URL</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #111; color: #eee; font-family: system-ui, sans-serif; }
        main { width: 100%; max-width: 34rem; padding: 2rem; }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; }
        p { margin: 0 0 1rem; line-height: 1.5; }
        a { color: #f5c542; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
