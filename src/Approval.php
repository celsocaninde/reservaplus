<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;
use Session;

class Approval extends CommonDBTM
{
    public static $table = 'glpi_plugin_reservaplus_approvals';
    public static $rightname = 'plugin_reservaplus_approval';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_approvals';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Aprovação do Reserva Plus', 'reservaplus');
    }

    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) > 0;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }
}
