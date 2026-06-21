<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

/** Nome amigável de quem vai usar o horário (com cache por usuário). */
function plugin_reservaplus_user_name(int $userId): string
{
    static $cache = [];
    if ($userId <= 0) {
        return __('Reservado', 'reservaplus');
    }
    if (!array_key_exists($userId, $cache)) {
        $user           = new User();
        $cache[$userId] = $user->getFromDB($userId) ? trim((string) $user->getFriendlyName()) : '';
    }
    return $cache[$userId] !== '' ? $cache[$userId] : __('Reservado', 'reservaplus');
}

function plugin_reservaplus_calendar_events(string $start, string $end): array
{
    global $DB;

    $events          = [];
    $linkedNativeIds = [];

    // Visibilidade: o calendário mostra quem vai usar cada horário. Reservas de
    // terceiros aparecem com o nome de quem reservou, mas somente-leitura (sem
    // item nem ação) — só o dono/admin pode cancelar/excluir.
    $isAdmin = ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
    $uid     = (int) Session::getLoginUserID();

    // Requests ativos primeiro (deduplicação igual ao ajax/events.php)
    if ($DB->tableExists(ReservationRequest::getTable())) {
        foreach ($DB->request([
            'FROM'  => ReservationRequest::getTable(),
            'WHERE' => [
                'end'    => ['>', $start],
                'begin'  => ['<', $end],
                'status' => [
                    ReservationRequest::STATUS_CREATED,
                    ReservationRequest::STATUS_PENDING,
                    ReservationRequest::STATUS_APPROVED,
                ],
            ],
            'LIMIT' => 500,
        ]) as $row) {
            $decoded = json_decode((string) ($row['native_reservations_json'] ?? ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $nativeId) {
                    $nativeId = (int) $nativeId;
                    if ($nativeId > 0) {
                        $linkedNativeIds[$nativeId] = true;
                    }
                }
            }
            $forId       = (int) ($row['users_id_for'] ?? 0);
            $requesterId = (int) ($row['users_id_requester'] ?? 0);
            $owns        = $uid === $requesterId || $uid === $forId;
            if (!$isAdmin && !$owns) {
                $events[] = [
                    'title'    => plugin_reservaplus_user_name($forId > 0 ? $forId : $requesterId),
                    'start'    => (string) ($row['begin'] ?? ''),
                    'type'     => 'native',
                    'readonly' => true,
                ];
                continue;
            }
            $events[] = [
                'title' => ReservationRequest::getStatusLabel((string) ($row['status'] ?? ReservationRequest::STATUS_PENDING)) . ' #' . (int) ($row['reservationitems_id'] ?? 0),
                'start' => (string) ($row['begin'] ?? ''),
                'type'  => 'request',
            ];
        }
    }

    // Nativas não vinculadas a requests (criadas direto no GLPI)
    if ($DB->tableExists('glpi_reservations')) {
        foreach ($DB->request([
            'FROM'  => 'glpi_reservations',
            'WHERE' => [
                'end'   => ['>', $start],
                'begin' => ['<', $end],
            ],
            'LIMIT' => 500,
        ]) as $row) {
            if (isset($linkedNativeIds[(int) ($row['id'] ?? 0)])) {
                continue;
            }
            if (!$isAdmin && (int) ($row['users_id'] ?? 0) !== $uid) {
                $events[] = [
                    'title'    => plugin_reservaplus_user_name((int) ($row['users_id'] ?? 0)),
                    'start'    => (string) ($row['begin'] ?? ''),
                    'type'     => 'native',
                    'readonly' => true,
                ];
                continue;
            }
            $events[] = [
                'title' => __('Reserva confirmada', 'reservaplus') . ' #' . (int) ($row['reservationitems_id'] ?? 0),
                'start' => (string) ($row['begin'] ?? ''),
                'type'  => 'native',
            ];
        }
    }

    if ($DB->tableExists('glpi_plugin_reservaplus_blocks')) {
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_reservaplus_blocks',
            'WHERE' => [
                'is_active' => 1,
                'end'       => ['>', $start],
                'begin'     => ['<', $end],
            ],
            'LIMIT' => 500,
        ]) as $row) {
            $events[] = [
                'title' => __('Bloqueado', 'reservaplus'),
                'start' => (string) ($row['begin'] ?? ''),
                'type'  => 'block',
            ];
        }
    }

    return $events;
}

function plugin_reservaplus_calendar_item_name(string $itemtype, int $itemsId): string
{
    if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
        return $itemtype !== '' ? $itemtype . ' #' . $itemsId : '#' . $itemsId;
    }

    $obj = new $itemtype();
    if (!$obj->getFromDB($itemsId)) {
        return $itemtype . ' #' . $itemsId;
    }

    $name = method_exists($obj, 'getName') ? $obj->getName() : '';
    return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
}

function plugin_reservaplus_calendar_reservable_items(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_reservationitems')) {
        return [];
    }

    $items = [];
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
        'LIMIT' => 200,
    ]) as $row) {
        $row['_label'] = plugin_reservaplus_calendar_item_name(
            (string) ($row['itemtype'] ?? ''),
            (int) ($row['items_id'] ?? 0)
        );
        $items[] = $row;
    }

    return $items;
}

function plugin_reservaplus_calendar_active_users(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_users')) {
        return [];
    }

    $users = [];
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['is_deleted' => 0, 'is_active' => 1],
        'ORDER'  => ['realname ASC', 'firstname ASC'],
        'LIMIT'  => 500,
    ]) as $row) {
        $first = trim((string) ($row['firstname'] ?? ''));
        $last  = trim((string) ($row['realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        $row['_label'] = $full !== '' ? $full : (string) ($row['name'] ?? '#' . $row['id']);
        $users[] = $row;
    }

    return $users;
}

function plugin_reservaplus_render_month(DateTimeImmutable $month, array $events): void
{
    $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $months = [
        1  => 'janeiro',
        2  => 'fevereiro',
        3  => 'março',
        4  => 'abril',
        5  => 'maio',
        6  => 'junho',
        7  => 'julho',
        8  => 'agosto',
        9  => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];
    $first = $month->modify('first day of this month');
    $start = $first->modify('-' . (int) $first->format('w') . ' days');
    $eventMap = [];

    foreach ($events as $event) {
        $key = substr((string) ($event['start'] ?? ''), 0, 10);
        if ($key !== '') {
            $eventMap[$key][] = $event;
        }
    }

    $monthLabel = ($months[(int) $first->format('n')] ?? $first->format('m')) . ' de ' . $first->format('Y');
    echo "<div class='reservaplus-calendar-title'>" . Html::cleanInputText(ucfirst($monthLabel)) . '</div>';
    echo "<div class='reservaplus-calendar-grid'>";
    foreach ($weekdays as $weekday) {
        echo "<div class='reservaplus-calendar-weekday'>" . Html::cleanInputText($weekday) . '</div>';
    }

    for ($index = 0; $index < 42; $index++) {
        $day = $start->modify('+' . $index . ' days');
        $key = $day->format('Y-m-d');
        $classes = ['reservaplus-calendar-day'];
        if ($day->format('m') !== $month->format('m')) {
            $classes[] = 'is-muted';
        }
        if ($key === date('Y-m-d')) {
            $classes[] = 'is-today';
        }

        echo "<div class='" . implode(' ', $classes) . "' data-reservaplus-day='" . Html::cleanInputText($key) . "' role='button' tabindex='0' aria-label='" . sprintf(__('Criar reserva em %s', 'reservaplus'), Html::cleanInputText($day->format('d/m/Y'))) . "'>";
        echo "<div class='reservaplus-calendar-day-number'>" . (int) $day->format('j') . '</div>';

        foreach (array_slice($eventMap[$key] ?? [], 0, 3) as $event) {
            $type = (string) preg_replace('/[^a-z0-9_-]/i', '', (string) ($event['type'] ?? 'request'));
            echo "<div class='reservaplus-calendar-event " . Html::cleanInputText($type) . "' title='" . Html::cleanInputText((string) ($event['title'] ?? '')) . "'>";
            echo Html::cleanInputText((string) ($event['title'] ?? ''));
            echo '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}

$month = new DateTimeImmutable(date('Y-m-01'));
$events = plugin_reservaplus_calendar_events(
    $month->format('Y-m-01 00:00:00'),
    $month->modify('last day of this month')->format('Y-m-d 23:59:59')
);
$reservableItems  = plugin_reservaplus_calendar_reservable_items();
$canForOthers     = ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
$calendarUsers    = $canForOthers ? plugin_reservaplus_calendar_active_users() : [];
$csrfToken        = Session::getNewCSRFToken();
$currentUserId    = (int) Session::getLoginUserID();
$isAdmin          = ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
$canCreate        = ReservationRequest::canCreate();
$deleteActionUrl  = Dashboard::getUrl('reservation.action.php');

Dashboard::renderHeader(__('Calendário do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<section class='reservaplus-panel reservaplus-calendar-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Calendário', 'reservaplus') . '</h1>';
echo '<p>' . __('Visualize e gerencie reservas. Clique em um dia para reservar rapidamente.', 'reservaplus') . '</p>';
echo '</div>';
echo "<div class='reservaplus-actions'>";
echo "<div class='reservaplus-view-switcher'>";
echo "<button type='button' class='btn btn-sm btn-secondary' data-reservaplus-view='month'>" . __('Mês', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-view='week'>" . __('Semana', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-view='day'>" . __('Dia', 'reservaplus') . '</button>';
echo '</div>';
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-prev'><i class='ti ti-chevron-left'></i></button>";
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-today'>" . __('Hoje', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-next'><i class='ti ti-chevron-right'></i></button>";
if (ReservationRequest::canCreate()) {
    echo "<button type='button' class='btn btn-primary' data-reservaplus-open-today><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</button>';
}
echo '</div>';
echo '</div>';
echo "<div class='reservaplus-calendar' data-events-url='/plugins/reservaplus/ajax/events.php'>";
plugin_reservaplus_render_month($month, $events);
echo '</div>';
echo "<div class='reservaplus-calendar-legend'>";
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-native'></span>" . __('Reserva confirmada (GLPI)', 'reservaplus') . '</div>';
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-active'></span>" . __('Reserva ativa', 'reservaplus') . '</div>';
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-pending'></span>" . __('Aguardando aprovação', 'reservaplus') . '</div>';
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-block'></span>" . __('Bloqueio de horário', 'reservaplus') . '</div>';
echo '</div>';
echo '</section>';
if (ReservationRequest::canCreate()) {
    echo "<div class='reservaplus-modal' data-reservaplus-modal hidden>";
    echo "<div class='reservaplus-modal-backdrop' data-reservaplus-modal-close></div>";
    echo "<div class='reservaplus-modal-dialog' role='dialog' aria-modal='true' aria-labelledby='reservaplus-modal-title'>";
    echo "<form method='post' action='" . Dashboard::getUrl('reservation.form.php') . "' data-reservaplus-availability-form data-availability-url='/plugins/reservaplus/ajax/availability.php'>";
    echo "<div class='reservaplus-modal-header'>";
    echo '<div>';
    echo "<span class='reservaplus-kicker'>" . __('Reserva rápida', 'reservaplus') . '</span>';
    echo "<h2 id='reservaplus-modal-title'>" . __('Nova reserva', 'reservaplus') . '</h2>';
    echo "<p data-reservaplus-modal-date>" . __('Selecione um dia no calendário.', 'reservaplus') . '</p>';
    echo '</div>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-modal-close aria-label='" . __('Fechar', 'reservaplus') . "'><i class='ti ti-x'></i></button>";
    echo '</div>';
    echo "<div class='reservaplus-modal-body'>";

    // Itens reserváveis (um ou vários — reserva em massa)
    echo "<div class='reservaplus-field-wide reservaplus-item-tools' data-reservaplus-item-tools>";
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-all>" . __('Todos', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-clear-sel>" . __('Limpar', 'reservaplus') . '</button>';
    echo '</div>';
    echo '<label class="reservaplus-field-wide"><span>' . __('Itens reserváveis', 'reservaplus') . ' <small class="text-muted">' . __('(um ou vários)', 'reservaplus') . "</small></span><select name='reservationitems_id[]' class='form-select' multiple size='6' required>";
    foreach ($reservableItems as $item) {
        echo "<option value='" . (int) ($item['id'] ?? 0) . "'>" . Html::cleanInputText((string) ($item['_label'] ?? '#' . (int) ($item['id'] ?? 0))) . '</option>';
    }
    echo '</select></label>';

    // Início e Fim
    echo '<label><span>' . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' data-reservaplus-begin required></label>";
    echo '<label><span>' . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' data-reservaplus-end required></label>";

    // Indicador de disponibilidade em tempo real
    echo "<div class='reservaplus-availability reservaplus-field-wide' data-reservaplus-availability hidden></div>";

    // Reservar para (somente admin/gestor)
    if ($canForOthers && $calendarUsers !== []) {
        echo "<label class='reservaplus-field-wide reservaplus-for-others'>";
        echo "<span><i class='ti ti-user-share reservaplus-ico-accent'></i>" . __('Reservar para', 'reservaplus') . '</span>';
        echo "<select name='users_id_for' class='form-select'>";
        echo "<option value='" . (int) Session::getLoginUserID() . "'>" . __('Eu mesmo', 'reservaplus') . '</option>';
        foreach ($calendarUsers as $user) {
            if ((int) ($user['id'] ?? 0) === (int) Session::getLoginUserID()) {
                continue;
            }
            echo "<option value='" . (int) ($user['id'] ?? 0) . "'>" . Html::cleanInputText((string) ($user['_label'] ?? '#' . $user['id'])) . '</option>';
        }
        echo '</select></label>';
    }

    // Comentário
    echo '<label class="reservaplus-field-wide"><span>' . __('Comentário', 'reservaplus') . "</span><textarea class='form-control' name='comment' rows='3' placeholder='" . __('Motivo, sala, observações...', 'reservaplus') . "'></textarea></label>";
    echo '</div>';
    echo "<div class='reservaplus-modal-footer'>";
    echo "<button type='button' class='btn btn-outline-secondary' data-reservaplus-modal-close>" . __('Cancelar', 'reservaplus') . '</button>';
    echo Html::submit(__('Criar reserva', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary']);
    echo '</div>';
    Html::closeForm();
    echo '</div>';
    echo '</div>';
}

echo "<div class='reservaplus-daydetail' data-reservaplus-daydetail hidden>";
echo "<div class='reservaplus-modal-backdrop' data-reservaplus-daydetail-close></div>";
echo "<div class='reservaplus-daydetail-dialog' role='dialog' aria-modal='true' aria-labelledby='reservaplus-daydetail-title'>";
echo "<div class='reservaplus-modal-header'>";
echo '<div>';
echo "<span class='reservaplus-kicker'>" . __('Reservas do dia', 'reservaplus') . '</span>';
echo "<h2 id='reservaplus-daydetail-title' data-reservaplus-daydetail-date>-</h2>";
echo '</div>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-daydetail-close aria-label='" . __('Fechar', 'reservaplus') . "'><i class='ti ti-x'></i></button>";
echo '</div>';
echo "<div class='reservaplus-daydetail-body' data-reservaplus-daydetail-body></div>";
echo "<div class='reservaplus-modal-footer' data-reservaplus-daydetail-footer>";
echo "<button type='button' class='btn btn-outline-secondary' data-reservaplus-daydetail-close>" . __('Fechar', 'reservaplus') . '</button>';
if ($canCreate) {
    echo "<button type='button' class='btn btn-primary' data-reservaplus-daydetail-reserve><i class='ti ti-plus'></i> " . __('Nova reserva', 'reservaplus') . '</button>';
}
echo '</div>';
echo '</div>';
echo '</div>';

$jsConfig = json_encode([
    'csrfToken'       => $csrfToken,
    'currentUserId'   => $currentUserId,
    'isAdmin'         => $isAdmin,
    'canCreate'       => $canCreate,
    'deleteActionUrl' => $deleteActionUrl,
], JSON_THROW_ON_ERROR);
echo Html::scriptBlock('window.reservaplusConfig = ' . $jsConfig . ';');
echo '</div>';

Dashboard::renderFooter();
