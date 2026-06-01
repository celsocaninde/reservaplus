<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;
use Session;

class Rule extends CommonDBTM
{
    public static $table = 'glpi_plugin_reservaplus_rules';
    public static $rightname = 'plugin_reservaplus_rule';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_rules';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Regra do Reserva Plus', 'reservaplus');
    }

    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) > 0;
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(static::$rightname, CREATE) > 0;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(static::$rightname, PURGE) > 0;
    }
}
