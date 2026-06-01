<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonGLPI;
use Session;

class Report extends CommonGLPI
{
    public static $rightname = 'plugin_reservaplus_report';

    public static function getTypeName($nb = 0): string
    {
        return __('Relatório do Reserva Plus', 'reservaplus');
    }

    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) > 0;
    }
}
