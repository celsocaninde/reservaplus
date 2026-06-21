<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Profile as ReservaPlusProfile;
use GlpiPlugin\Reservaplus\ReservationRequest;

define('PLUGIN_RESERVAPLUS_VERSION', '0.2.2');
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

    // Interface simplificada (self-service): em vez do hook helpdesk_menu_entry
    // — que sempre aninha o item dentro do dropdown "Plug-ins" — usamos
    // redefine_menus para inserir "Reserva Plus" como item de TOPO do menu
    // simplificado (irmão de Home/Chamados/FAQ). Aplica-se apenas à interface
    // helpdesk; ver plugin_reservaplus_redefine_menus().
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['reservaplus'] = 'plugin_reservaplus_redefine_menus';

    // Sem isto, Profile::cleanProfile() do GLPI remove o direito do plugin da
    // sessão dos perfis de interface simplificada — e o self-service perderia o
    // acesso às reservas mesmo tendo a permissão gravada no banco.
    if (!in_array(ReservationRequest::$rightname, \Profile::$helpdesk_rights, true)) {
        \Profile::$helpdesk_rights[] = ReservationRequest::$rightname;
    }

    Plugin::registerClass(ReservaPlusProfile::class, [
        'addtabon' => [\Profile::class],
    ]);
}

/**
 * Hook redefine_menus: adiciona "Reserva Plus" como item de topo na interface
 * simplificada (self-service), fora do dropdown "Plug-ins".
 *
 * O hook também é chamado para o menu da interface central, por isso só agimos
 * quando o usuário está na interface helpdesk e tem direito de ver reservas — na
 * interface central o item já vem pelo menu 'tools'.
 *
 * @param array $menu Menu gerado pelo GLPI.
 * @return array
 */
function plugin_reservaplus_redefine_menus(array $menu): array
{
    if (
        Session::getCurrentInterface() === 'helpdesk'
        && Session::haveRight(ReservationRequest::$rightname, READ)
    ) {
        $menu['reservaplus'] = [
            'default' => Dashboard::getUrl('reservation.php'),
            'title'   => Dashboard::getTypeName(),
            'icon'    => Dashboard::getIcon(),
        ];
    }

    return $menu;
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
