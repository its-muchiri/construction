<?php
/**
 * Global layout — header/nav/footer per planning/00-portfolio/design-system.md
 * §Global layout and artcollect-design-system.md §6. `$content` and
 * optional `$title` are provided by View::render(). `$critical` (bool),
 * when set true by a page, marks <main> with the `.critical-flow` class —
 * see this platform's README: milestone funding/escrow-release and any
 * equipment-damage-dispute page get zero decorative motion
 * (artcollect-design-system.md §7).
 */
$pageTitle = isset($title) ? $title . ' — construction.co.ke' : 'construction.co.ke';
$isCritical = $critical ?? false;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
  <header class="site-header container">
    <a href="/" style="text-decoration:none;color:inherit;"><strong>construction.co.ke</strong></a>
    <nav aria-label="Primary" style="display:flex; align-items:center; gap: var(--ac-space-3); flex-wrap: wrap;">
      <a href="/equipment" class="btn btn--secondary">Browse equipment</a>
      <a href="/crew" class="btn btn--secondary">Browse crews</a>
      <a href="/projects/new" class="btn btn--primary">Post a project</a>
      <span id="nav-account"></span>
    </nav>
  </header>

  <main class="container<?= $isCritical ? ' critical-flow' : '' ?>" style="padding-block: var(--ac-space-8);">
    <?= $content ?>
  </main>

  <footer class="site-footer container">
    <p class="card__meta">&copy; <?= date('Y') ?> construction.co.ke — part of the artcollect.co.ke network. <a href="/showcase.html">Component showcase</a></p>
  </footer>

  <script type="module" src="/assets/js/main.js"></script>
  <script type="module">
    import { getUser, clearSession } from "/assets/js/lib/auth-session.js";

    const accountEl = document.getElementById("nav-account");
    const user = getUser();

    if (user) {
      accountEl.innerHTML = `<span class="card__meta">Hi, ${user.full_name.split(" ")[0]}</span> <button type="button" class="btn btn--secondary" id="nav-logout">Log out</button>`;
      document.getElementById("nav-logout").addEventListener("click", () => {
        clearSession();
        window.location.href = "/";
      });
    } else {
      accountEl.innerHTML = `<a href="/login" class="btn btn--secondary">Log in</a> <a href="/signup" class="btn btn--secondary">Sign up</a>`;
    }
  </script>
</body>
</html>
