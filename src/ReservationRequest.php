<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;
use Html;
use Session;

class ReservationRequest extends CommonDBTM
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CREATED = 'created';

    public static $table = 'glpi_plugin_reservaplus_requests';
    public static $rightname = 'plugin_reservaplus_reservation';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_requests';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Reserva do Reserva Plus', 'reservaplus');
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

    public static function canManageAllRequests(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }

    public static function isGlpiAdmin(): bool
    {
        // Super admin tem direito 'config' com UPDATE — proxy seguro para admin GLPI
        return Session::haveRight('config', UPDATE) > 0
            || (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] === 2);
    }

    public static function canDeleteRow(array $row): bool
    {
        // Super admin GLPI pode apagar qualquer reserva
        if (self::isGlpiAdmin()) {
            return true;
        }

        // Admin / manager do plugin pode apagar qualquer reserva
        if (self::canManageAllRequests()) {
            return true;
        }

        // Dono pode apagar a própria reserva (basta ter leitura)
        $hasRead = Session::haveRight(static::$rightname, READ) > 0;
        if ($hasRead) {
            $isRequester = (int) ($row['users_id_requester'] ?? 0) === (int) Session::getLoginUserID();
            $isFor       = (int) ($row['users_id_for'] ?? 0)       === (int) Session::getLoginUserID();
            return $isRequester || $isFor;
        }

        return false;
    }

    public static function deleteRequest(int $requestId): bool
    {
        global $DB;

        if ($requestId <= 0 || !$DB->tableExists(static::getTable())) {
            return false;
        }

        $request = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['id' => $requestId],
            'LIMIT' => 1,
        ])->current();

        if (!$request || !self::canDeleteRow($request)) {
            return false;
        }

        $nativeReservationIds = self::getNativeReservationIds($request);
        if (!self::canDeleteNativeReservations($nativeReservationIds)) {
            return false;
        }

        if (!self::deleteNativeReservations($nativeReservationIds)) {
            return false;
        }

        if ($DB->tableExists(Approval::getTable())) {
            $DB->delete(Approval::getTable(), [
                'plugin_reservaplus_requests_id' => $requestId,
            ]);
        }

        // Deleta diretamente — permissão já verificada acima via canDeleteRow()
        return (bool) $DB->delete(static::getTable(), ['id' => $requestId]);
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING   => __('Pendente', 'reservaplus'),
            self::STATUS_APPROVED  => __('Aprovada', 'reservaplus'),
            self::STATUS_REFUSED   => __('Recusada', 'reservaplus'),
            self::STATUS_CANCELLED => __('Cancelada', 'reservaplus'),
            self::STATUS_CREATED   => __('Criada', 'reservaplus'),
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        $options = self::getStatusOptions();
        return (string) ($options[$status] ?? $status);
    }

    public static function getStatusClass(string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED, self::STATUS_CREATED => 'reservaplus-badge-approved',
            self::STATUS_REFUSED, self::STATUS_CANCELLED => 'reservaplus-badge-danger',
            default => 'reservaplus-badge-pending',
        };
    }

    public static function getRecent(int $limit = 10): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'  => static::getTable(),
            'ORDER' => ['id DESC'],
            'LIMIT' => max(1, $limit),
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getFiltered(array $extraWhere = [], int $limit = 100): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $where = $extraWhere;
        $rows  = [];

        foreach ($DB->request([
            'SELECT'    => [
                static::getTable() . '.*',
                'glpi_reservationitems.itemtype AS _itemtype',
                'glpi_reservationitems.items_id AS _items_id',
                'glpi_users.name        AS _user_login',
                'glpi_users.realname    AS _user_realname',
                'glpi_users.firstname   AS _user_firstname',
            ],
            'FROM'      => static::getTable(),
            'LEFT JOIN' => [
                'glpi_reservationitems' => [
                    'FKEY' => [
                        static::getTable()       => 'reservationitems_id',
                        'glpi_reservationitems'  => 'id',
                    ],
                ],
                'glpi_users' => [
                    'FKEY' => [
                        static::getTable() => 'users_id_requester',
                        'glpi_users'       => 'id',
                    ],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => [static::getTable() . '.id DESC'],
            'LIMIT'     => max(1, $limit),
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getUserDisplayName(array $row): string
    {
        $first = trim((string) ($row['_user_firstname'] ?? ''));
        $last  = trim((string) ($row['_user_realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }
        $login = trim((string) ($row['_user_login'] ?? ''));
        return $login !== '' ? $login : '#' . (int) ($row['users_id_requester'] ?? 0);
    }

    public static function getItemDisplayName(array $row): string
    {
        $itemtype = (string) ($row['_itemtype'] ?? '');
        $itemsId  = (int) ($row['_items_id'] ?? 0);

        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return __('Item reservável', 'reservaplus') . ' #' . (int) ($row['reservationitems_id'] ?? 0);
        }

        $obj = new $itemtype();
        if (!$obj->getFromDB($itemsId)) {
            return $itemtype . ' #' . $itemsId;
        }

        $name = method_exists($obj, 'getName') ? $obj->getName() : '';
        return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
    }

    public static function getTodayNativeReservations(int $limit = 10): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_reservations')) {
            return [];
        }

        $today = date('Y-m-d 00:00:00');
        $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $rows = [];

        foreach ($DB->request([
            'FROM'  => 'glpi_reservations',
            'WHERE' => [
                'begin' => ['<', $tomorrow],
                'end'   => ['>', $today],
            ],
            'ORDER' => ['begin ASC'],
            'LIMIT' => max(1, $limit),
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private static function fetchUserNames(array $userIds): array
    {
        global $DB;

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $names = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $userIds],
        ]) as $u) {
            $first = trim((string) ($u['firstname'] ?? ''));
            $last  = trim((string) ($u['realname'] ?? ''));
            $full  = trim($first . ' ' . $last);
            $names[(int) $u['id']] = $full !== '' ? $full : (string) ($u['name'] ?? '#' . $u['id']);
        }

        return $names;
    }

    public static function showList(): void
    {
        $mine    = isset($_GET['mine']) && (string) $_GET['mine'] === '1';
        $where   = [];
        $isAdmin = self::isGlpiAdmin() || self::canManageAllRequests();

        if ($mine || !$isAdmin) {
            $where[static::getTable() . '.users_id_requester'] = (int) Session::getLoginUserID();
        }

        $rows = self::getFiltered($where, 200);

        // Pré-carrega nomes dos usuários "para quem" a reserva foi feita
        $forUserIds  = array_map(fn($r) => (int) ($r['users_id_for'] ?? 0), $rows);
        $forUserNames = self::fetchUserNames($forUserIds);

        $mineUrl = Dashboard::getUrl('reservation.php') . '?mine=1';
        $allUrl  = Dashboard::getUrl('reservation.php');

        echo "<div class='reservaplus-shell'>";
        echo "<div class='reservaplus-toolbar'>";
        echo '<div>';
        echo '<h1>' . __('Reservas', 'reservaplus') . '</h1>';
        echo '<p>' . __('Gerencie e acompanhe as reservas de itens.', 'reservaplus') . '</p>';
        echo '</div>';
        echo "<div class='reservaplus-actions'>";

        if ($isAdmin) {
            echo "<a class='btn " . ($mine ? 'btn-outline-secondary' : 'btn-secondary') . "' href='" . Html::cleanInputText($allUrl) . "'>" . __('Todas', 'reservaplus') . '</a>';
            echo "<a class='btn " . ($mine ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText($mineUrl) . "'><i class='ti ti-user'></i> " . __('Minhas', 'reservaplus') . '</a>';
        }

        if (self::canCreate()) {
            echo "<a class='btn btn-primary' href='" . Dashboard::getUrl('reservation.form.php') . "'><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</a>';
        }
        echo "<a class='btn btn-outline-primary' href='" . Dashboard::getUrl('calendar.php') . "'><i class='ti ti-calendar'></i> " . __('Calendário', 'reservaplus') . '</a>';
        echo '</div>';
        echo '</div>';

        echo "<section class='reservaplus-panel'>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover reservaplus-table'>";
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . __('Item', 'reservaplus') . '</th>';
        if ($isAdmin && !$mine) {
            echo '<th>' . __('Solicitante', 'reservaplus') . '</th>';
        }
        echo '<th>' . __('Início', 'reservaplus') . '</th>';
        echo '<th>' . __('Fim', 'reservaplus') . '</th>';
        echo '<th>' . __('Ações', 'reservaplus') . '</th>';
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            $colspan = ($isAdmin && !$mine) ? 6 : 5;
            echo "<tr><td colspan='{$colspan}'>";
            echo "<div class='reservaplus-empty reservaplus-empty-table'>";
            echo "<i class='ti ti-calendar-plus'></i>";
            echo '<strong>' . __('Nenhuma reserva encontrada.', 'reservaplus') . '</strong>';
            echo '<span>' . __('As reservas nativas do GLPI também ficam visíveis no calendário.', 'reservaplus') . '</span>';
            echo '</div>';
            echo '</td></tr>';
        }

        foreach ($rows as $row) {
            $begin     = (string) ($row['begin'] ?? '');
            $end       = (string) ($row['end'] ?? '');
            $beginFmt  = $begin !== '' ? date('d/m/Y H:i', strtotime($begin)) : '-';
            $endFmt    = $end   !== '' ? date('d/m/Y H:i', strtotime($end))   : '-';
            $reqId     = (int) ($row['users_id_requester'] ?? 0);
            $forId     = (int) ($row['users_id_for'] ?? 0);
            $hasForOther = $forId > 0 && $forId !== $reqId;
            $forName   = $hasForOther ? ($forUserNames[$forId] ?? '#' . $forId) : '';

            echo '<tr>';
            echo '<td>#' . (int) ($row['id'] ?? 0) . '</td>';

            // Item + badge "Para" quando reservado para outra pessoa
            $itemCell = Html::cleanInputText(self::getItemDisplayName($row));
            if ($hasForOther) {
                $itemCell .= " <span class='reservaplus-badge reservaplus-badge-pending' title='" . __('Reservado para', 'reservaplus') . "'><i class='ti ti-user-share' style='font-size:0.75rem'></i> " . Html::cleanInputText($forName) . '</span>';
            }
            echo '<td>' . $itemCell . '</td>';

            if ($isAdmin && !$mine) {
                echo '<td>' . Html::cleanInputText(self::getUserDisplayName($row)) . '</td>';
            }
            echo '<td>' . Html::cleanInputText($beginFmt) . '</td>';
            echo '<td>' . Html::cleanInputText($endFmt) . '</td>';
            echo "<td class='reservaplus-row-actions'>";
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . Dashboard::getUrl('reservation.form.php') . '?duplicate=' . (int) ($row['id'] ?? 0) . "'><i class='ti ti-copy'></i> " . __('Duplicar', 'reservaplus') . '</a>';
            if (self::canDeleteRow($row)) {
                echo "<form method='post' action='" . Dashboard::getUrl('reservation.action.php') . "' class='reservaplus-inline-form' onsubmit=\"return confirm('" . __('Apagar esta reserva?', 'reservaplus') . "');\">";
                echo Html::hidden('id', ['value' => (int) ($row['id'] ?? 0)]);
                echo "<button type='submit' name='delete' value='1' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i> " . __('Apagar', 'reservaplus') . '</button>';
                Html::closeForm();
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
        echo '</div>';
    }

    private static function getNativeReservationIds(array $request): array
    {
        $rawValue = (string) ($request['native_reservations_json'] ?? '');
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function canDeleteNativeReservations(array $reservationIds): bool
    {
        if ($reservationIds === []) {
            return true;
        }

        if (self::isGlpiAdmin() || self::canManageAllRequests()) {
            return true;
        }

        $userId = (int) Session::getLoginUserID();
        foreach ($reservationIds as $reservationId) {
            $reservation = new \Reservation();
            if (!$reservation->getFromDB((int) $reservationId)) {
                continue;
            }

            if ((int) ($reservation->fields['users_id'] ?? 0) !== $userId) {
                return false;
            }
        }

        return true;
    }

    private static function deleteNativeReservations(array $reservationIds): bool
    {
        global $DB;

        foreach ($reservationIds as $reservationId) {
            $id = (int) $reservationId;
            if ($id <= 0) {
                continue;
            }

            // Tenta via objeto GLPI primeiro (respeita hooks)
            $reservation = new \Reservation();
            if ($reservation->getFromDB($id)) {
                if (!$reservation->delete(['id' => $id], true, false)) {
                    // Fallback: delete direto se o usuário é dono ou admin
                    if (!$DB->delete('glpi_reservations', ['id' => $id])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
