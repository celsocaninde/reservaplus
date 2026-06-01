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
        echo '<p>' . __('Comportamento padrão para aprovações, recorrência, horários e notificações.', 'reservaplus') . '</p>';
        echo '</div>';
        echo '</div>';

        echo "<div class='reservaplus-form-grid'>";
        self::fieldSelect('approval_mode', __('Modo de aprovação', 'reservaplus'), [
            'rules'  => __('Usar regras', 'reservaplus'),
            'always' => __('Sempre exigir aprovação', 'reservaplus'),
            'never'  => __('Criar diretamente', 'reservaplus'),
        ], (string) ($this->fields['approval_mode'] ?? 'rules'), $canEdit);
        self::fieldNumber('default_duration_minutes', __('Duração padrão em minutos', 'reservaplus'), (int) ($this->fields['default_duration_minutes'] ?? 60), $canEdit);
        self::fieldTime('business_hours_start', __('Início do horário comercial', 'reservaplus'), (string) ($this->fields['business_hours_start'] ?? '08:00:00'), $canEdit);
        self::fieldTime('business_hours_end', __('Fim do horário comercial', 'reservaplus'), (string) ($this->fields['business_hours_end'] ?? '18:00:00'), $canEdit);
        self::fieldToggle('allow_recurring', __('Permitir reservas recorrentes', 'reservaplus'), (int) ($this->fields['allow_recurring'] ?? 1), $canEdit);
        self::fieldToggle('notify_requester', __('Notificar solicitante', 'reservaplus'), (int) ($this->fields['notify_requester'] ?? 1), $canEdit);
        self::fieldToggle('notify_approver', __('Notificar aprovador', 'reservaplus'), (int) ($this->fields['notify_approver'] ?? 1), $canEdit);
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
        $input['approval_mode'] = in_array(($input['approval_mode'] ?? 'rules'), ['rules', 'always', 'never'], true)
            ? (string) $input['approval_mode']
            : 'rules';
        $input['default_duration_minutes'] = max(15, (int) ($input['default_duration_minutes'] ?? 60));
        $input['allow_recurring'] = isset($input['allow_recurring']) ? 1 : 0;
        $input['notify_requester'] = isset($input['notify_requester']) ? 1 : 0;
        $input['notify_approver'] = isset($input['notify_approver']) ? 1 : 0;
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

    private static function fieldToggle(string $name, string $label, int $value, bool $enabled): void
    {
        echo "<label class='reservaplus-toggle'>";
        echo "<input type='checkbox' name='" . Html::cleanInputText($name) . "' value='1'" . ($value ? ' checked' : '') . ($enabled ? '' : ' disabled') . '>';
        echo '<span>' . Html::cleanInputText($label) . '</span>';
        echo '</label>';
    }
}
