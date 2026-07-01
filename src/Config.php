<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;
use Html;
use Session;

class Config extends CommonDBTM
{
    public static $table = 'glpi_plugin_reservaplus_configs';
    public static $rightname = 'plugin_reservaplus_config';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_configs';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Configuração do Reserva Plus', 'reservaplus');
    }

    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) > 0;
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }

    public static function getSingleton(): self
    {
        global $DB;

        $config = new self();

        if (!$DB->tableExists(static::getTable())) {
            return $config;
        }

        $row = $DB->request([
            'FROM'  => static::getTable(),
            'LIMIT' => 1,
        ])->current();

        if ($row && isset($row['id'])) {
            $config->getFromDB((int) $row['id']);
        }

        return $config;
    }

    /**
     * Valida uma reserva contra o horário comercial configurado.
     *
     * Retorna uma mensagem de erro se o período cair fora do horário, ou null
     * se estiver ok (ou se não houver horário configurado). Reservas que
     * cruzam mais de um dia não são restringidas.
     */
    public static function businessHoursViolation(string $begin, string $end): ?string
    {
        $config   = self::getSingleton();
        $startCfg = trim((string) ($config->fields['business_hours_start'] ?? ''));
        $endCfg   = trim((string) ($config->fields['business_hours_end'] ?? ''));

        $startMin = self::timeToMinutes($startCfg);
        $endMin   = self::timeToMinutes($endCfg);
        if ($startMin === null || $endMin === null || $endMin <= $startMin) {
            return null; // sem horário comercial válido configurado
        }

        $beginTs = strtotime($begin);
        $endTs   = strtotime($end);
        if ($beginTs === false || $endTs === false) {
            return null;
        }

        // Só restringe reservas dentro de um mesmo dia.
        if (date('Y-m-d', $beginTs) !== date('Y-m-d', $endTs)) {
            return null;
        }

        $beginMin = (int) date('G', $beginTs) * 60 + (int) date('i', $beginTs);
        $endMinute = (int) date('G', $endTs) * 60 + (int) date('i', $endTs);

        if ($beginMin < $startMin || $endMinute > $endMin) {
            return sprintf(
                __('Reservas só são permitidas entre %1$s e %2$s.', 'reservaplus'),
                substr($startCfg, 0, 5),
                substr($endCfg, 0, 5)
            );
        }

        return null;
    }

    private static function timeToMinutes(string $time): ?int
    {
        if ($time === '') {
            return null;
        }
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }
        $hours   = (int) $parts[0];
        $minutes = (int) $parts[1];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    public function showForm($ID, array $options = []): bool
    {
        if (!self::canView()) {
            return false;
        }

        if (!$this->isNewID($ID)) {
            $this->getFromDB($ID);
        }

        $canEdit = self::canUpdate();

        echo "<div class='reservaplus-shell'>";
        echo "<form method='post' action='" . self::getFormURL() . "'>";
        echo "<section class='reservaplus-panel reservaplus-config'>";
        echo "<div class='reservaplus-panel-header'>";
        echo '<div>';
        echo '<h1>' . __('Configuração do Reserva Plus', 'reservaplus') . '</h1>';
        echo '<p>' . __('Comportamento padrão para recorrência, horários e notificações.', 'reservaplus') . '</p>';
        echo '</div>';
        echo '</div>';

        echo "<div class='reservaplus-form-grid'>";
        self::fieldNumber('default_duration_minutes', __('Duração padrão em minutos', 'reservaplus'), (int) ($this->fields['default_duration_minutes'] ?? 60), $canEdit);
        self::fieldTime('business_hours_start', __('Início do horário comercial', 'reservaplus'), (string) ($this->fields['business_hours_start'] ?? '08:00:00'), $canEdit);
        self::fieldTime('business_hours_end', __('Fim do horário comercial', 'reservaplus'), (string) ($this->fields['business_hours_end'] ?? '18:00:00'), $canEdit);
        self::fieldToggle('allow_recurring', __('Permitir reservas recorrentes', 'reservaplus'), (int) ($this->fields['allow_recurring'] ?? 1), $canEdit);
        self::fieldToggle('notify_requester', __('Notificar solicitante', 'reservaplus'), (int) ($this->fields['notify_requester'] ?? 1), $canEdit);
        $hasSecret = trim((string) ($this->fields['webhook_secret'] ?? '')) !== '';
        self::fieldText('webhook_url', __('URL do webhook (reserva criada/cancelada)', 'reservaplus'), (string) ($this->fields['webhook_url'] ?? ''), $canEdit, 'url', 'https://seu-sistema/endpoint');
        self::fieldText('webhook_secret', __('Segredo do webhook (HMAC-SHA256)', 'reservaplus'), '', $canEdit, 'password', $hasSecret ? __('configurado — deixe em branco para manter', 'reservaplus') : __('opcional', 'reservaplus'));
        echo '</div>';

        echo "<div class='reservaplus-actions mt-3'>";
        echo Html::hidden('id', ['value' => (int) ($this->fields['id'] ?? 0)]);
        if ($canEdit) {
            echo Html::submit(__('Salvar', 'reservaplus'), ['name' => 'update', 'class' => 'btn btn-primary']);
        }
        echo '</div>';
        echo '</section>';
        Html::closeForm();
        echo '</div>';

        return true;
    }

    public function prepareInputForAdd($input): array
    {
        return $this->normalizeInput((array) $input);
    }

    public function prepareInputForUpdate($input): array
    {
        return $this->normalizeInput((array) $input);
    }

    private function normalizeInput(array $input): array
    {
        $input['default_duration_minutes'] = max(15, (int) ($input['default_duration_minutes'] ?? 60));
        $input['allow_recurring'] = isset($input['allow_recurring']) ? 1 : 0;
        $input['notify_requester'] = isset($input['notify_requester']) ? 1 : 0;

        // Webhook: URL só aceita http(s); segredo em branco mantém o atual.
        $url = trim((string) ($input['webhook_url'] ?? ''));
        $input['webhook_url'] = preg_match('#^https?://#i', $url) === 1 ? $url : '';
        if (!array_key_exists('webhook_secret', $input) || trim((string) $input['webhook_secret']) === '') {
            unset($input['webhook_secret']);
        } else {
            $input['webhook_secret'] = trim((string) $input['webhook_secret']);
        }

        $input['date_mod'] = date('Y-m-d H:i:s');

        return $input;
    }

    private static function fieldSelect(string $name, string $label, array $options, string $value, bool $enabled): void
    {
        echo '<label><span>' . Html::cleanInputText($label) . "</span><select class='form-select' name='" . Html::cleanInputText($name) . "'" . ($enabled ? '' : ' disabled') . '>';
        foreach ($options as $optionValue => $optionLabel) {
            echo "<option value='" . Html::cleanInputText((string) $optionValue) . "'" . ($value === (string) $optionValue ? ' selected' : '') . '>' . Html::cleanInputText((string) $optionLabel) . '</option>';
        }
        echo '</select></label>';
    }

    private static function fieldNumber(string $name, string $label, int $value, bool $enabled): void
    {
        echo '<label><span>' . Html::cleanInputText($label) . "</span><input class='form-control' type='number' min='15' step='15' name='" . Html::cleanInputText($name) . "' value='" . $value . "'" . ($enabled ? '' : ' disabled') . '></label>';
    }

    private static function fieldTime(string $name, string $label, string $value, bool $enabled): void
    {
        $time = substr($value, 0, 5);
        echo '<label><span>' . Html::cleanInputText($label) . "</span><input class='form-control' type='time' name='" . Html::cleanInputText($name) . "' value='" . Html::cleanInputText($time) . "'" . ($enabled ? '' : ' disabled') . '></label>';
    }

    private static function fieldText(string $name, string $label, string $value, bool $enabled, string $type = 'text', string $placeholder = ''): void
    {
        echo '<label><span>' . Html::cleanInputText($label) . "</span><input class='form-control' type='" . Html::cleanInputText($type) . "' name='" . Html::cleanInputText($name) . "' value='" . Html::cleanInputText($value) . "'"
            . ($placeholder !== '' ? " placeholder='" . Html::cleanInputText($placeholder) . "'" : '')
            . ($enabled ? '' : ' disabled') . '></label>';
    }

    private static function fieldToggle(string $name, string $label, int $value, bool $enabled): void
    {
        echo "<label class='reservaplus-toggle'>";
        echo "<input type='checkbox' name='" . Html::cleanInputText($name) . "' value='1'" . ($value ? ' checked' : '') . ($enabled ? '' : ' disabled') . '>';
        echo '<span>' . Html::cleanInputText($label) . '</span>';
        echo '</label>';
    }
}
