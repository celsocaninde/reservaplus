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

    public static function getApplicableRules(int $profileId, int $entityId, string $itemtype): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $rules = [];
        foreach ($DB->request(['FROM' => static::getTable(), 'WHERE' => ['is_active' => 1]]) as $row) {
            $rp = (int) ($row['profiles_id'] ?? 0);
            $re = (int) ($row['entities_id'] ?? 0);
            $ri = (string) ($row['itemtype'] ?? '');

            if ($rp > 0 && $rp !== $profileId) {
                continue;
            }
            if ($re > 0 && $re !== $entityId) {
                continue;
            }
            if ($ri !== '' && $ri !== $itemtype) {
                continue;
            }

            $rules[] = $row;
        }

        return $rules;
    }

    public static function validateForCreate(
        int $profileId,
        int $entityId,
        string $itemtype,
        string $begin,
        string $end
    ): ?string {
        $rules = self::getApplicableRules($profileId, $entityId, $itemtype);
        if ($rules === []) {
            return null;
        }

        $beginTs         = strtotime($begin);
        $endTs           = strtotime($end);
        $nowTs           = time();
        $durationMinutes = (int) round(($endTs - $beginTs) / 60);
        $noticeMinutes   = (int) round(($beginTs - $nowTs) / 60);
        $daysAhead       = (int) round(($beginTs - $nowTs) / 86400);

        foreach ($rules as $rule) {
            $maxDuration  = (int) ($rule['max_duration_minutes'] ?? 0);
            $minNotice    = (int) ($rule['min_notice_minutes'] ?? 0);
            $maxDaysAhead = (int) ($rule['max_days_ahead'] ?? 0);
            $name         = (string) ($rule['name'] ?? '');

            if ($maxDuration > 0 && $durationMinutes > $maxDuration) {
                return sprintf(
                    __('Regra "%s": duração máxima é %d minutos (solicitado: %d min).', 'reservaplus'),
                    $name,
                    $maxDuration,
                    $durationMinutes
                );
            }
            if ($minNotice > 0 && $noticeMinutes < $minNotice) {
                return sprintf(
                    __('Regra "%s": reserva deve ser feita com %d minuto(s) de antecedência.', 'reservaplus'),
                    $name,
                    $minNotice
                );
            }
            if ($maxDaysAhead > 0 && $daysAhead > $maxDaysAhead) {
                return sprintf(
                    __('Regra "%s": não é possível reservar com mais de %d dia(s) de antecedência.', 'reservaplus'),
                    $name,
                    $maxDaysAhead
                );
            }
        }

        return null;
    }
}
