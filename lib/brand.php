<?php
/**
 * White-label branding: display name, logo and primary colour.
 *  - Per DB user:  users.brand (JSON)
 *  - Super admin's own:  app_settings 'brand_super'
 *  - Default for everyone (and the login page):  app_settings 'brand_default'
 * The super admin sets all of these (see the branding UI in Settings).
 */

const BRAND_BUILTIN = ['name' => 'TrakrHub', 'logo' => 'T', 'logo_url' => '', 'color' => '#2563eb'];

/** Clean + clamp an incoming brand to safe values. */
function brand_sanitize($in): array
{
    $in = is_array($in) ? $in : [];
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    if ($name === '') {
        $name = BRAND_BUILTIN['name'];
    }
    $name = mb_substr($name, 0, 40);
    $logo = trim((string)($in['logo'] ?? ''));
    $logo = $logo === '' ? mb_strtoupper(mb_substr($name, 0, 1)) : mb_substr($logo, 0, 3);
    $url = trim((string)($in['logo_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https://[^\s]+$#i', $url)) {
        $url = ''; // only https image URLs
    }
    $color = strtolower(trim((string)($in['color'] ?? '')));
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
        $color = BRAND_BUILTIN['color'];
    }
    return ['name' => $name, 'logo' => $logo, 'logo_url' => $url, 'color' => $color];
}

function brand_setting_get(string $key): ?array
{
    try {
        $v = q("SELECT value FROM app_settings WHERE name = ?", ["brand_$key"])->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if (!$v) {
        return null;
    }
    $d = json_decode((string)$v, true);
    return is_array($d) ? brand_sanitize($d) : null;
}

function brand_setting_save(string $key, ?array $brand): void
{
    if ($brand === null) {
        q("DELETE FROM app_settings WHERE name = ?", ["brand_$key"]);
        return;
    }
    q("REPLACE INTO app_settings (name, value) VALUES (?, ?)", ["brand_$key", json_encode(brand_sanitize($brand))]);
}

function brand_user_get(string $username): ?array
{
    try {
        $v = q("SELECT brand FROM users WHERE username = ?", [strtolower($username)])->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if (!$v) {
        return null;
    }
    $d = json_decode((string)$v, true);
    return is_array($d) ? brand_sanitize($d) : null;
}

function brand_user_save(string $username, ?array $brand): void
{
    q("UPDATE users SET brand = ? WHERE username = ?",
      [$brand === null ? null : json_encode(brand_sanitize($brand)), strtolower($username)]);
}

/** The brand shown to a given user (null = the login page). */
function brand_for_user(?array $u): array
{
    if ($u && !empty($u['super'])) {
        return brand_setting_get('super') ?? brand_setting_get('default') ?? BRAND_BUILTIN;
    }
    if ($u) {
        return brand_user_get($u['username']) ?? brand_setting_get('default') ?? BRAND_BUILTIN;
    }
    return brand_setting_get('default') ?? BRAND_BUILTIN;
}

/** Lighten a hex colour toward white by $p (0..1). */
function brand_tint(string $hex, float $p): string
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $mix = fn($c) => (int)round($c + (255 - $c) * $p);
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/** :root override so brand colour drives accents across the app. */
function brand_css(array $b): string
{
    $c = $b['color'];
    return ':root{--accent:' . $c . ';--accent-2:' . brand_tint($c, 0.82) . ';--accent-3:' . brand_tint($c, 0.93) . ';}';
}

/** Logo + name badge markup (matches the original .brand structure). */
function brand_badge_html(array $b): string
{
    $logo = $b['logo_url'] !== ''
        ? '<span class="logo"><img src="' . h($b['logo_url']) . '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit"></span>'
        : '<span class="logo">' . h($b['logo']) . '</span>';
    $parts = explode(' ', $b['name'], 2);
    $main = h($parts[0]);
    $b2 = isset($parts[1]) && $parts[1] !== '' ? ' <span class="b2">' . h($parts[1]) . '</span>' : '';
    return $logo . ' ' . $main . $b2;
}
