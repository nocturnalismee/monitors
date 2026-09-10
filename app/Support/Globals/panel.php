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
 * Render a logo + label chip for a panel_profile value. All output escaped.
 */
function panel_brand_chip(string $raw, string $extraClass = ''): string
{
    $brand = panel_brand($raw);
    $class = trim('panel-brand panel-' . $brand['slug'] . ' ' . $extraClass);
    $html = '<span class="' . e($class) . '">';
    if ($brand['logo'] !== null) {
        $logoClass = $brand['logo_dark'] !== null ? 'panel-logo panel-logo-day' : 'panel-logo';
        $html .= '<img class="' . $logoClass . '" src="' . e(asset_url($brand['logo'])) . '" alt="" aria-hidden="true" loading="lazy">';
        if ($brand['logo_dark'] !== null) {
            $html .= '<img class="panel-logo panel-logo-dark" src="' . e(asset_url($brand['logo_dark'])) . '" alt="" aria-hidden="true" loading="lazy">';
        }
    }
    return $html . e($brand['label']) . '</span>';
}
