<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonGLPI;
use Html;
use Profile as CoreProfile;
use Session;

class Profile extends CoreProfile
{
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => ReservationRequest::class,
                'label'    => __('Reservas do Reserva Plus', 'reservaplus'),
                'field'    => ReservationRequest::$rightname,
                'rights'   => [READ, CREATE, UPDATE, PURGE],
            ],
            [
                'itemtype' => Approval::class,
                'label'    => __('Aprovações do Reserva Plus', 'reservaplus'),
                'field'    => Approval::$rightname,
                'rights'   => [READ, UPDATE],
            ],
            [
                'itemtype' => Rule::class,
                'label'    => __('Regras do Reserva Plus', 'reservaplus'),
                'field'    => Rule::$rightname,
                'rights'   => [READ, CREATE, UPDATE, PURGE],
            ],
            [
                'itemtype' => Block::class,
                'label'    => __('Bloqueios de horário do Reserva Plus', 'reservaplus'),
                'field'    => Block::$rightname,
                'rights'   => [READ, CREATE, UPDATE, PURGE],
            ],
            [
                'itemtype' => Report::class,
                'label'    => __('Relatórios do Reserva Plus', 'reservaplus'),
                'field'    => Report::$rightname,
                'rights'   => [READ],
            ],
            [
                'itemtype' => Config::class,
                'label'    => __('Configuração do Reserva Plus', 'reservaplus'),
                'field'    => Config::$rightname,
                'rights'   => [READ, UPDATE],
            ],
        ];
    }

    public static function ensureProfileRights(): void
    {
        global $DB;

        $rights = static::getAllRights();
        $fields = array_column($rights, 'field');
        if ($fields === []) {
            return;
        }

        $profiles = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_profiles',
        ]) as $row) {
            $profileId = (int) ($row['id'] ?? 0);
            if ($profileId > 0) {
                $profiles[$profileId] = (string) ($row['name'] ?? '');
            }
        }

        if ($profiles === []) {
            return;
        }

        $existing = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'profiles_id', 'name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'name' => $fields,
            ],
        ]) as $row) {
            $existing[(int) $row['profiles_id'] . '|' . (string) $row['name']] = [
                'id'     => (int) $row['id'],
                'rights' => (int) ($row['rights'] ?? 0),
            ];
        }

        foreach ($profiles as $profileId => $profileName) {
            foreach ($rights as $right) {
                $field = (string) $right['field'];
                $key = $profileId . '|' . $field;
                $defaultRights = static::getDefaultRightsForProfile($profileName, $right);

                if (isset($existing[$key])) {
                    if (static::shouldUpdateDefaultRights($profileName, (int) $existing[$key]['rights'], $defaultRights)) {
                        $DB->update('glpi_profilerights', [
                            'rights' => $defaultRights,
                        ], [
                            'id' => $existing[$key]['id'],
                        ]);
                    }
                    continue;
                }

                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profileId,
                    'name'        => $field,
                    'rights'      => $defaultRights,
                ]);
            }
        }
    }

    public static function saveRightsForProfile(int $profileId, array $submittedRights): void
    {
        global $DB;

        if ($profileId <= 0) {
            return;
        }

        foreach (static::getAllRights() as $right) {
            $field = (string) $right['field'];
            $selected = isset($submittedRights[$field]) && is_array($submittedRights[$field])
                ? array_map('intval', $submittedRights[$field])
                : [];

            $mask = 0;
            foreach ($right['rights'] as $value) {
                $value = (int) $value;
                if (in_array($value, $selected, true)) {
                    $mask |= $value;
                }
            }

            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'profiles_id' => $profileId,
                    'name'        => $field,
                ],
                'LIMIT' => 1,
            ])->current();

            if ($existing) {
                $DB->update('glpi_profilerights', [
                    'rights' => $mask,
                ], [
                    'id' => (int) $existing['id'],
                ]);
            } else {
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profileId,
                    'name'        => $field,
                    'rights'      => $mask,
                ]);
            }
        }
    }

    public static function getCurrentRightsForProfile(int $profileId): array
    {
        global $DB;

        if ($profileId <= 0) {
            return [];
        }

        $fields = array_column(static::getAllRights(), 'field');
        if ($fields === []) {
            return [];
        }

        $result = [];
        foreach ($DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $profileId,
                'name'        => $fields,
            ],
        ]) as $row) {
            $result[(string) $row['name']] = (int) ($row['rights'] ?? 0);
        }

        return $result;
    }

    public static function syncCurrentProfileRights(): void
    {
        global $DB;

        if (
            !isset($DB)
            || !isset($_SESSION['glpiactiveprofile'])
            || !is_array($_SESSION['glpiactiveprofile'])
        ) {
            return;
        }

        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0 || !$DB->tableExists('glpi_profilerights')) {
            return;
        }

        foreach (static::getCurrentRightsForProfile($profileId) as $field => $rights) {
            $_SESSION['glpiactiveprofile'][$field] = $rights;
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof CoreProfile) {
            return __('Reserva Plus', 'reservaplus');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $CFG_GLPI;

        if (!$item instanceof CoreProfile) {
            return true;
        }

        $profileId = (int) $item->getID();
        $currentRights = static::getCurrentRightsForProfile($profileId);
        $canEdit = Session::haveRight('profile', UPDATE) > 0;
        $shortLabels = [
            READ   => __('Ler', 'reservaplus'),
            CREATE => __('Criar', 'reservaplus'),
            UPDATE => __('Atualizar', 'reservaplus'),
            PURGE  => __('Excluir', 'reservaplus'),
        ];

        Dashboard::includeAssets(false);

        echo "<div class='reservaplus-shell reservaplus-perms'>";
        echo "<form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/reservaplus/front/profile.rights.php' class='reservaplus-panel'>";
        echo Html::hidden('profiles_id', ['value' => $profileId]);

        // Cabeçalho com identidade
        echo "<div class='reservaplus-perms-head'>";
        echo "<span class='reservaplus-perms-icon'><i class='ti ti-calendar-bolt'></i></span>";
        echo '<div>';
        echo "<span class='reservaplus-kicker'>" . __('Reserva Plus', 'reservaplus') . '</span>';
        echo '<h2>' . __('Permissões', 'reservaplus') . '</h2>';
        echo '<p>' . __('Defina o que este perfil pode fazer no Reserva Plus.', 'reservaplus') . '</p>';
        echo '</div>';
        echo '</div>';

        echo "<div class='reservaplus-perms-list'>";
        foreach (static::getAllRights() as $right) {
            $field = (string) $right['field'];
            $mask  = (int) ($currentRights[$field] ?? 0);
            $meta  = static::getRightMeta($field);

            echo "<div class='reservaplus-perm-row'>";
            echo "<span class='reservaplus-perm-ico'><i class='" . Html::cleanInputText($meta['icon']) . "'></i></span>";
            echo "<div class='reservaplus-perm-info'>";
            echo '<strong>' . Html::cleanInputText((string) $right['label']) . '</strong>';
            if ($meta['desc'] !== '') {
                echo '<span>' . Html::cleanInputText($meta['desc']) . '</span>';
            }
            echo '<code>' . Html::cleanInputText($field) . '</code>';
            echo '</div>';

            echo "<div class='reservaplus-perm-actions'>";
            foreach ($shortLabels as $permission => $label) {
                if (!in_array((int) $permission, $right['rights'], true)) {
                    continue;
                }
                $checked = ($mask & (int) $permission) === (int) $permission;
                echo "<label class='reservaplus-perm-toggle'>";
                echo '<input type="checkbox" name="plugin_reservaplus_rights[' . Html::cleanInputText($field) . '][]" value="' . (int) $permission . '"'
                    . ($checked ? ' checked' : '')
                    . ($canEdit ? '' : ' disabled')
                    . '>';
                echo '<span>' . Html::cleanInputText($label) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        if ($canEdit) {
            echo "<div class='reservaplus-perms-foot'>";
            echo Html::submit(__('Salvar permissões', 'reservaplus'), ['name' => 'save_reservaplus_rights', 'class' => 'btn btn-primary']);
            echo '</div>';
        }

        Html::closeForm();
        echo '</div>';

        return true;
    }

    /**
     * Ícone + descrição amigável para cada permissão.
     */
    private static function getRightMeta(string $field): array
    {
        $map = [
            ReservationRequest::$rightname => [
                'icon' => 'ti ti-calendar-check',
                'desc' => __('Criar, ver e gerenciar reservas de itens.', 'reservaplus'),
            ],
            Approval::$rightname => [
                'icon' => 'ti ti-thumb-up',
                'desc' => __('Aprovar ou recusar solicitações pendentes.', 'reservaplus'),
            ],
            Rule::$rightname => [
                'icon' => 'ti ti-adjustments',
                'desc' => __('Definir regras de aprovação, duração e antecedência.', 'reservaplus'),
            ],
            Block::$rightname => [
                'icon' => 'ti ti-calendar-x',
                'desc' => __('Bloquear horários por item ou globais.', 'reservaplus'),
            ],
            Report::$rightname => [
                'icon' => 'ti ti-chart-bar',
                'desc' => __('Visualizar relatórios e métricas de uso.', 'reservaplus'),
            ],
            Config::$rightname => [
                'icon' => 'ti ti-settings',
                'desc' => __('Ajustar as configurações gerais do plugin.', 'reservaplus'),
            ],
        ];

        return $map[$field] ?? ['icon' => 'ti ti-shield-lock', 'desc' => ''];
    }

    public static function hasAnyRight(): bool
    {
        foreach (static::getAllRights() as $right) {
            foreach ($right['rights'] as $permission) {
                if (Session::haveRight((string) $right['field'], (int) $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function shouldUpdateDefaultRights(string $profileName, int $currentRights, int $defaultRights): bool
    {
        if (strcasecmp($profileName, 'Self-Service') === 0) {
            return $currentRights !== $defaultRights;
        }

        return $defaultRights > 0 && $currentRights === 0;
    }

    private static function getDefaultRightsForProfile(string $profileName, array $rightDefinition): int
    {
        $field = (string) ($rightDefinition['field'] ?? '');
        $allowedRights = (array) ($rightDefinition['rights'] ?? []);

        if (strcasecmp($profileName, 'Self-Service') === 0) {
            if ($field === ReservationRequest::$rightname) {
                return READ | CREATE | PURGE;
            }

            return 0;
        }

        if (
            strcasecmp($profileName, 'Super-Admin') !== 0
            && strcasecmp($profileName, 'Admin') !== 0
        ) {
            return 0;
        }

        $mask = 0;
        foreach ($allowedRights as $right) {
            $mask |= (int) $right;
        }

        return $mask;
    }
}
