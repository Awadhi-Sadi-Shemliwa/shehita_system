<?php
/**
 * SHEHITA EMS — Per-tenant theming
 *
 * Turns a tenant's stored brand palette into CSS, and resolves their logo.
 *
 * How the theming works:
 * The app's stylesheets already reference colours through
 * var(--brown-900) ... var(--brown-100). This file emits a second :root block
 * that redefines exactly those nine variables. Because it is injected AFTER
 * each page's own :root, the later rule wins and the whole UI re-themes --
 * without deleting or rewriting the existing palettes, which stay as the
 * fallback if this include is ever missing.
 */

require_once __DIR__ . '/brand.php';

/**
 * The palette for the currently resolved tenant.
 * Falls back to SHEHITA's own brown when there is no tenant or no stored ramp.
 */
function tenant_palette(): array
{
    static $palette = null;
    if ($palette !== null) {
        return $palette;
    }

    global $TENANT;

    if (!is_array($TENANT) || empty($TENANT['brand_ramp'])) {
        return $palette = brand_default();
    }

    $stored = json_decode((string)$TENANT['brand_ramp'], true);
    if (!is_array($stored) || empty($stored['ramp'])) {
        return $palette = brand_default();
    }

    // Guard against a partially written ramp: fill any missing step from default.
    $default = brand_default();
    foreach (array_keys($default['ramp']) as $step) {
        if (empty($stored['ramp'][$step])) {
            $stored['ramp'][$step] = $default['ramp'][$step];
        }
    }
    $stored['navbar_bg'] = $stored['navbar_bg'] ?? $default['navbar_bg'];
    $stored['primary']   = $stored['primary']   ?? $default['primary'];

    return $palette = $stored;
}

/**
 * The <style> block to drop into any page's <head>, after its own styles.
 */
function tenant_theme_css(): string
{
    $palette = tenant_palette();

    $lines = [];
    foreach ($palette['ramp'] as $step => $hex) {
        $lines[] = "--brown-{$step}:" . htmlspecialchars($hex, ENT_QUOTES) . ';';
    }
    $lines[] = '--navbar-bg:' . htmlspecialchars($palette['navbar_bg'], ENT_QUOTES) . ';';
    $lines[] = '--brand-primary:' . htmlspecialchars($palette['primary'], ENT_QUOTES) . ';';

    $css = "<style id=\"tenant-theme\">:root{" . implode('', $lines) . "}</style>\n";

    // The print/export popups (invoice, operations, projects, status) build a
    // BRAND-NEW document with window.open() + document.write(). That document
    // inherits none of the CSS above, so var(--brown-900) would resolve to
    // nothing there. Expose the raw hex values for those template literals.
    $json = json_encode([
        'ramp'    => $palette['ramp'],
        'primary' => $palette['primary'],
    ], JSON_UNESCAPED_SLASHES);

    $css .= "<script>window.SHEHITA_BRAND=" . $json . ";"
          . "window.brandColor=function(s){return (window.SHEHITA_BRAND&&window.SHEHITA_BRAND.ramp&&window.SHEHITA_BRAND.ramp[s])||'#3e2b1f';};"
          . "</script>\n";

    return $css;
}

/**
 * Where this tenant's logo lives, as a path relative to the current page.
 *
 * Relative on purpose: pages are served from /<slug>/, so "uploads/..." resolves
 * to /<slug>/uploads/... which .htaccess rewrites back to /uploads/... . That
 * keeps every tenant's URLs self-consistent with no absolute-path special cases.
 *
 * @return string Empty string when there is no logo (callers show a text wordmark).
 */
function tenant_logo_url(): string
{
    global $TENANT;

    if (is_array($TENANT) && !empty($TENANT['logo_path'])) {
        $path = (string)$TENANT['logo_path'];
        if (is_file(__DIR__ . '/../' . $path)) {
            return $path;
        }
    }

    // Fall back to SHEHITA's own mark, matching the paths the app used before
    // multi-tenancy so a single-tenant install keeps working unchanged.
    foreach ([
        'uploads/systemlogo/shehita-logo.jpg',
        'uploads/systemlogo/Shehita_Logo.jpg',
        'uploads/systemlogo/Shehita_Logo.jpeg',
        'uploads/systemlogo/logo.png',
        'uploads/systemlogo/logo.jpg',
    ] as $candidate) {
        if (is_file(__DIR__ . '/../' . $candidate)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * "Acme Traders" -> used for <title>, the navbar wordmark and emails.
 */
function tenant_display_name(): string
{
    global $TENANT;

    if (is_array($TENANT) && !empty($TENANT['company_name'])) {
        return (string)$TENANT['company_name'];
    }

    return 'SHEHITA';
}

/**
 * "Acme Traders EMS" — the branded product name shown in page titles.
 */
function tenant_product_name(): string
{
    return tenant_display_name() . ' EMS';
}

/**
 * Absolute URL to a path inside this tenant's portal, for emails and redirects
 * where a relative path has no meaning.
 */
function tenant_url(string $path = ''): string
{
    $base = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
    $slug = defined('TENANT_SLUG') ? TENANT_SLUG : '';

    if ($slug === '') {
        return $base . '/' . ltrim($path, '/');
    }

    return $base . '/' . $slug . '/' . ltrim($path, '/');
}

/**
 * Absolute URL to a platform (non-tenant) path such as /admin or /signup.
 */
function platform_url(string $path = ''): string
{
    $base = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');

    return $base . '/' . ltrim($path, '/');
}
