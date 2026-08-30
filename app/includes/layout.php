<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function page_start(string $title, array $user, string $activePage = 'dashboard'): void
{
    $name = e($user['full_name']);
    preg_match('/^./u', (string) $user['full_name'], $initialMatch);
    $accountInitial = e(strtoupper($initialMatch[0] ?? '?'));
    $role = e(ucfirst($user['role']));
    $safeTitle = e($title);
    $csrfToken = csrf_token();
    $stylesheetVersion = (string) filemtime(__DIR__ . '/../../assets/css/app.css');
    $companyLogo = app_setting('company_logo_path');
    $logoMarkup = $companyLogo !== null && $companyLogo !== ''
        ? '<img class="brand-logo" src="' . e($companyLogo) . '" alt="Company logo">'
        : '';
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="8" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="15" width="7" height="6" rx="1"/>',
        'dormitories' => '<path d="M3 21V3h12v5h6v13M7 7h4M7 11h4M7 15h4M17 12h1M17 16h1M2 21h20"/>',
        'employers' => '<path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M4 21h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Zm-2-8h20M10 13v2h4v-2"/>',
        'agencies' => '<path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6M9 11h.01M15 11h.01"/>',
        'tenants' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'payments' => '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'visitors' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM18 8v6M15 11h6"/>',
        'maintenance' => '<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 8.4 7.2 6.1 4.9a4 4 0 0 0 5 5L4 17a2.1 2.1 0 0 0 3 3l7.1-7.1a4 4 0 0 0 5-5l-2.3 2.3-3-3L16.1 5a4 4 0 0 0-1.4 1.3Z"/>',
        'calendar' => '<path d="M3 9h18M8 2v4M16 2v4M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2ZM7 13h2M11 13h2M15 13h2M7 17h2M11 17h2"/>',
        'reports' => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'admin_control' => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.86 2.86-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1H9.6V21a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.86-2.86.06-.06A1.7 1.7 0 0 0 4.1 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1V9.6h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.34-1.88l-.06-.06L6.56 3.7l.06.06A1.7 1.7 0 0 0 8.5 4.1a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4.04v.1a1.7 1.7 0 0 0 1.06 1.6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.86 2.86-.06.06a1.7 1.7 0 0 0-.34 1.88c.16.42.37.75.6 1 .3.27.64.4 1 .4h.1v4.04H21a1.7 1.7 0 0 0-1.6 1.06Z"/>',
    ];
    $icon = static function (string $key) use ($icons): string {
        $paths = $icons[$key] ?? $icons['dashboard'];
        return '<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $paths . '</svg></span>';
    };
    $navLink = static function (string $key, string $href, string $label, bool $checkPermission = true) use ($activePage, $icon): string {
        if ($checkPermission && !has_permission($key)) {
            return '';
        }
        $active = $activePage === $key;
        return '<a class="' . ($active ? 'active' : '') . '" href="' . $href . '"' . ($active ? ' aria-current="page"' : '') . '>' . $icon($key) . '<span class="nav-label">' . $label . '</span><span class="nav-chevron" aria-hidden="true">›</span></a>';
    };
    $navigation = $navLink('dashboard', 'dashboard.php', 'Dashboard')
        . $navLink('dormitories', 'dormitories.php', 'Dormitories &amp; Rooms')
        . ($user['role'] === 'admin' ? $navLink('employers', 'employers.php', 'Employers', false) . $navLink('agencies', 'agencies.php', 'Agencies', false) : '')
        . $navLink('tenants', 'tenants.php', 'Tenants')
        . $navLink('payments', 'payments.php', 'Payments')
        . $navLink('visitors', 'visitors.php', 'Visitor Log')
        . $navLink('maintenance', 'maintenance.php', 'Maintenance')
        . $navLink('calendar', 'calendar.php', 'Calendar')
        . $navLink('reports', 'reports.php', 'Reports')
        . ($user['role'] === 'admin' ? $navLink('admin_control', 'admin_control.php', 'Administrator Control', false) : '');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} | OFW Dormitory System</title>
  <link rel="stylesheet" href="assets/css/app.css?v={$stylesheetVersion}">
</head>
<body>
  <aside class="sidebar">
    <a class="brand" href="dashboard.php">{$logoMarkup}<span class="brand-text"><small>Dormitory System</small></span></a>
    <nav aria-label="Main navigation">
      {$navigation}
    </nav>
    <div class="account">
      <div class="account-avatar" aria-hidden="true">{$accountInitial}</div>
      <div class="account-details"><strong>{$name}</strong><span>{$role}</span></div>
      <form action="logout.php" method="post">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <button type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg><span>Log out</span></button>
      </form>
    </div>
  </aside>
  <main class="content">
HTML;
}

function page_end(): void
{
    echo "  </main>\n</body>\n</html>";
}
