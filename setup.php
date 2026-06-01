<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Profile as ReservaPlusProfile;

define('PLUGIN_RESERVAPLUS_VERSION', '0.1.1');
define('PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION', '11.1.0');

function plugin_init_reservaplus(): void
{
    global $PLUGIN_HOOKS;

    ReservaPlusProfile::syncCurrentProfileRights();

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['reservaplus'] = true;
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['reservaplus'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['reservaplus'] = ['css/reservaplus.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['reservaplus'] = ['js/reservaplus.js'];
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['reservaplus'] = [
        'tools' => Dashboard::class,
    ];

    Plugin::registerClass(ReservaPlusProfile::class, [
        'addtabon' => [\Profile::class],
    ]);
}

function plugin_version_reservaplus(): array
{
    return [
        'name'         => __('Reserva Plus', 'reservaplus'),
        'version'      => PLUGIN_RESERVAPLUS_VERSION,
        'author'       => 'Celso, Codex',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://glpi-project.org',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION,
                'max' => PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_reservaplus_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION, '<')
        || version_compare(GLPI_VERSION, PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION, '>=')
    ) {
        echo sprintf(
            __('O Reserva Plus requer GLPI >= %1$s e < %2$s.', 'reservaplus'),
            PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION,
            PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION
        );
        return false;
    }

    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo __('O Reserva Plus requer PHP 8.2 ou superior e está preparado para PHP 8.5.', 'reservaplus');
        return false;
    }

    return true;
}

function plugin_reservaplus_check_config(bool $verbose = false): bool
{
    return true;
}
