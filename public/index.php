<?php
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$file = __FILE__;
$dir  = dirname(__FILE__);
$home = getenv('HOME') ?: '';
$display_dir = ($home && str_starts_with($dir, $home)) ? '~' . substr($dir, strlen($home)) : $dir;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<title><?= htmlspecialchars($host) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64' stroke-linecap='round' stroke-linejoin='round'><defs><clipPath id='c'><circle cx='32' cy='32' r='28'/></clipPath></defs><g clip-path='url(%23c)'><rect width='64' height='64' fill='%23f6f1e8'/><rect y='32' width='64' height='32' fill='%233a97a9'/><path d='M 4 32 C 4 22, 12 12, 22 12 C 30 12, 34 18, 42 16 C 50 14, 58 18, 60 24 L 60 32 Z' fill='%2358b293'/><line x1='2' y1='32' x2='62' y2='32' stroke='%231c4c58' stroke-width='2.5' fill='none'/><g stroke='%231c4c58' stroke-width='2.6' fill='none'><path d='M 10 42 Q 18 38, 26 42 T 42 42 T 56 42'/><path d='M 14 50 Q 22 46, 30 50 T 46 50 T 56 50'/></g></g><circle cx='32' cy='32' r='28' stroke='%231c4c58' stroke-width='3' fill='none'/></svg>">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..600;1,9..144,400..600&family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #fbfaf7; --bg-elev: #ffffff; --bg-sunk: #f4f2ec;
  --border: #e8e4da; --text: #1a1c1b; --text-soft: #3a3d3a;
  --muted: #6b6f6a; --dim: #9a9d97;
  /* sRGB fallback first; the oklch override on the next line is ignored by
     browsers without oklch() support (Firefox <113, Chrome <111, Safari <16.4)
     so the hex value wins — otherwise the whole declaration would be invalid
     and --accent would fall back to its initial value (unset). */
  --accent: #3a97a9;       --accent-ink: #1c4c58;
  --accent: oklch(62% 0.11 190); --accent-ink: oklch(35% 0.08 190);
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0f1210; --bg-elev: #161a17; --bg-sunk: #0b0e0c;
    --border: #252925; --text: #edeee9; --text-soft: #c6c9c1;
    --muted: #8a8e85; --dim: #5d615a;
    --accent: #4db0c2;       --accent-ink: #83d2e0;
    --accent: oklch(72% 0.12 190); --accent-ink: oklch(82% 0.10 190);
  }
}
html { background: var(--bg); }
body {
  font-family: 'Geist', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
  color: var(--text); background: var(--bg);
  min-height: 100vh; display: grid; place-items: center;
  padding: 2rem; -webkit-font-smoothing: antialiased;
  font-feature-settings: "ss01", "cv11";
}
main { max-width: 560px; text-align: center; }
.mark { width: 56px; height: 56px; margin: 0 auto 1.75rem; display: block; }
h1 {
  font-family: 'Fraunces', 'Times New Roman', serif;
  font-style: italic; font-weight: 500;
  font-size: clamp(2.25rem, 5vw, 3.25rem);
  letter-spacing: -0.025em; line-height: 1.02;
  margin-bottom: 0.55rem;
}
.host {
  font-family: 'Geist Mono', ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 0.9rem; color: var(--muted); margin-bottom: 1.75rem;
}
p { color: var(--text-soft); line-height: 1.55; font-size: 1.02rem; margin-bottom: 1rem; }
.path {
  display: inline-block;
  font-family: 'Geist Mono', ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 0.82rem;
  padding: 0.45rem 0.8rem;
  background: var(--bg-sunk); border: 1px solid var(--border);
  border-radius: 7px; color: var(--text-soft);
  margin: 0.25rem 0 2rem; word-break: break-all;
}
.actions { display: inline-flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center; }
.pill {
  display: inline-flex; align-items: center; gap: 0.45em;
  padding: 0.55rem 1.05rem; border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-elev); color: var(--text-soft);
  font-family: 'Geist Mono', ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 0.85rem; text-decoration: none;
  transition: border-color 120ms, color 120ms, background 120ms;
}
.pill:hover { border-color: var(--accent); color: var(--accent-ink); background: var(--bg-sunk); }
.pill.primary { background: var(--accent); border-color: var(--accent); color: #0a1a1c; }
.pill.primary:hover { filter: brightness(1.08); background: var(--accent); color: #0a1a1c; }
footer {
  margin-top: 3rem;
  font-family: 'Geist Mono', ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 0.72rem; color: var(--dim); letter-spacing: 0.05em;
}
footer a { color: var(--muted); text-decoration: none; border-bottom: 1px solid var(--border); }
footer a:hover { color: var(--text); }
</style>
</head>
<body>
<main>
  <svg class="mark" viewBox="0 0 64 64" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <defs><clipPath id="c"><circle cx="32" cy="32" r="28"/></clipPath></defs>
    <g clip-path="url(#c)">
      <rect width="64" height="64" fill="#f6f1e8"/>
      <rect y="32" width="64" height="32" fill="#3a97a9"/>
      <path d="M 4 32 C 4 22, 12 12, 22 12 C 30 12, 34 18, 42 16 C 50 14, 58 18, 60 24 L 60 32 Z" fill="#58b293"/>
      <line x1="2" y1="32" x2="62" y2="32" stroke="#1c4c58" stroke-width="2.5" fill="none"/>
      <g stroke="#1c4c58" stroke-width="2.6" fill="none">
        <path d="M 10 42 Q 18 38, 26 42 T 42 42 T 56 42"/>
        <path d="M 14 50 Q 22 46, 30 50 T 46 50 T 56 50"/>
      </g>
    </g>
    <circle cx="32" cy="32" r="28" stroke="#1c4c58" stroke-width="3" fill="none"/>
  </svg>
  <h1>Hello.</h1>
  <div class="host"><?= htmlspecialchars($host) ?></div>
  <p>Your site is ready. Start building by editing files in:</p>
  <div class="path"><?= htmlspecialchars($display_dir) ?></div>
  <div class="actions">
    <a class="pill primary" href="https://cove.localhost/">Cove dashboard</a>
    <a class="pill" href="https://cove.run/" target="_blank" rel="noopener">cove.run ↗</a>
  </div>
  <footer>Served by <a href="https://cove.run" target="_blank" rel="noopener">Cove</a></footer>
</main>
</body>
</html>
