<?php
declare(strict_types=1);

/**
 * Map a raw panel_profile value to brand display info.
 *
 * Logos are self-hosted under public/assets/img/panels/ so no CSP
 * exception is needed. Unknown values fall back to plain text.
 *
 * @return array{slug: string, label: string, logo: string|null, logo_dark: string|null}
 */
function panel_brand(string $raw): array
{
    $key = strtolower(trim($raw));
    $map = [
        'cpanel' => ['slug' => 'cpanel', 'label' => 'cPanel', 'logo' => 'assets/img/panels/cpanel.svg'],
        'cpanel_mail' => ['slug' => 'cpanel', 'label' => 'cPanel', 'logo' => 'assets/img/panels/cpanel.svg'],
        'cpanel_email' => ['slug' => 'cpanel', 'label' => 'cPanel', 'logo' => 'assets/img/panels/cpanel.svg'],
        'plesk' => ['slug' => 'plesk', 'label' => 'Plesk', 'logo' => 'assets/img/panels/plesk.svg'],
        'directadmin' => ['slug' => 'directadmin', 'label' => 'DirectAdmin', 'logo' => 'assets/img/panels/directadmin.svg'],
        'cyberpanel' => ['slug' => 'cyberpanel', 'label' => 'CyberPanel', 'logo' => 'assets/img/panels/cyberpanel.png'],
        'cybperpanel' => ['slug' => 'cyberpanel', 'label' => 'CyberPanel', 'logo' => 'assets/img/panels/cyberpanel.png'],
        'aapanel' => ['slug' => 'aapanel', 'label' => 'aaPanel', 'logo' => 'assets/img/panels/aapanel.png'],
    ];
    if (isset($map[$key])) {
        $brand = $map[$key];
    } else {
        $brand = ['slug' => 'generic', 'label' => $raw === '' ? 'generic' : $raw, 'logo' => null];
    }
    $brand['logo_dark'] = $brand['slug'] === 'cyberpanel' ? 'assets/img/panels/cyberpanel-light.png' : null;
    return $brand;
}

/**
 * Brand map for client-side row rendering (dashboard.js, public.js).
 * Same source of truth as panel_brand(), with versioned logo URLs.
 * Unknown values are handled JS-side with a generic fallback.
 *
 * @return array<string, array{slug: string, label: string, logo: string|null, logo_dark: string|null}>
 */
function panel_brands_for_js(): array
{
    $keys = ['cpanel', 'cpanel_mail', 'cpanel_email', 'plesk', 'directadmin', 'cyberpanel', 'cybperpanel', 'aapanel', 'generic'];
    $out = [];
    foreach ($keys as $key) {
        $brand = panel_brand($key);
        if ($brand['logo'] !== null) {
            $brand['logo'] = asset_url($brand['logo']);
        }
        if ($brand['logo_dark'] !== null) {
            $brand['logo_dark'] = asset_url($brand['logo_dark']);
        }
        $out[$key] = $brand;
    }
    return $out;
}

/**
 * Render a logo-only chip for a panel_profile value. The brand name stays
 * available as tooltip + image alt text. Unknown values fall back to text.
 * All output escaped.
 */
function panel_brand_chip(string $raw, string $extraClass = ''): string
{
    $brand = panel_brand($raw);
    $class = trim('panel-brand panel-' . $brand['slug'] . ' ' . $extraClass);
    if ($brand['logo'] === null) {
        return '<span class="' . e($class) . '">' . e($brand['label']) . '</span>';
    }
    $html = '<span class="' . e($class) . '" title="' . e($brand['label']) . '">';
    $logoClass = $brand['logo_dark'] !== null ? 'panel-logo panel-logo-day' : 'panel-logo';
    $html .= '<img class="' . $logoClass . '" src="' . e(asset_url($brand['logo'])) . '" alt="' . e($brand['label']) . ' logo" loading="lazy">';
    if ($brand['logo_dark'] !== null) {
        $html .= '<img class="panel-logo panel-logo-dark" src="' . e(asset_url($brand['logo_dark'])) . '" alt="" aria-hidden="true" loading="lazy">';
    }
    return $html . '</span>';
}
