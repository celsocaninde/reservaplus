<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

global $CFG_GLPI, $DB;

$start = date('Y-m-d 00:00:00', strtotime((string) ($_GET['start'] ?? 'first day of this month')));
$end   = date('Y-m-d 23:59:59', strtotime((string) ($_GET['end'] ?? 'last day of this month')));

$pluginBase = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus';
$events     = [];

// Visibilidade: o calendário mostra QUEM vai usar cada horário. As reservas de
// terceiros aparecem com o nome de quem reservou, mas em modo somente-leitura
// (sem item, comentário ou link de ação). Apenas o dono — ou um admin/manager —
// pode cancelar/excluir a própria reserva.
$isAdmin       = ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
$currentUserId = (int) Session::getLoginUserID();

/** Nome amigável de quem vai usar o horário (com cache por usuário). */
$userName = static function (int $userId): string {
    static $cache = [];
    if ($userId <= 0) {
        return __('Reservado', 'reservaplus');
    }
    if (!array_key_exists($userId, $cache)) {
        $user          = new User();
        $cache[$userId] = $user->getFromDB($userId) ? trim((string) $user->getFriendlyName()) : '';
    }
    return $cache[$userId] !== '' ? $cache[$userId] : __('Reservado', 'reservaplus');
};

/** Evento de reserva de terceiro: mostra quem reservou, sem detalhes editáveis. */
$othersEvent = static function (string $id, string $begin, string $end, int $userId) use ($userName): array {
    return [
        'id'       => $id,
        'title'    => $userName($userId),
        'start'    => $begin,
        'end'      => $end,
        'type'     => 'native',
        'readonly' => true,
    ];
};

function plugin_reservaplus_events_item_name(string $itemtype, int $itemsId): string
{
    if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
        return $itemtype !== '' ? $itemtype : 'Item';
    }

    $obj = new $itemtype();
    if (!$obj->getFromDB($itemsId)) {
        return $itemtype;
    }

    $name = method_exists($obj, 'getName') ? $obj->getName() : '';
    return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
}

// Reservas do plugin (requests) primeiro. Cada request "ativo" (criada,
// pendente, aprovada) representa a reserva no calendário. Guardamos os IDs das
// reservas nativas vinculadas para NÃO exibi-las duas vezes mais abaixo.
$linkedNativeIds = [];

// Resolve accessible item IDs for the current user (null = no filter needed)
$allowedItemIds = ItemGroup::getAllowedItemIds();

// If user has access to no items at all, return early
if ($allowedItemIds !== null && $allowedItemIds === []) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['events' => []], JSON_THROW_ON_ERROR);
    exit;
}

if ($DB->tableExists(ReservationRequest::getTable())) {
    $requestWhere = [
        ReservationRequest::getTable() . '.end'    => ['>', $start],
        ReservationRequest::getTable() . '.begin'  => ['<', $end],
        ReservationRequest::getTable() . '.status' => [
            ReservationRequest::STATUS_CREATED,
        ],
    ];
    if ($allowedItemIds !== null) {
        $requestWhere[ReservationRequest::getTable() . '.reservationitems_id'] = $allowedItemIds;
    }

    foreach ($DB->request([
        'SELECT'    => [
            ReservationRequest::getTable() . '.id',
            ReservationRequest::getTable() . '.begin',
            ReservationRequest::getTable() . '.end',
            ReservationRequest::getTable() . '.status',
            ReservationRequest::getTable() . '.comment',
            ReservationRequest::getTable() . '.users_id_requester',
            ReservationRequest::getTable() . '.users_id_for',
            ReservationRequest::getTable() . '.native_reservations_json',
            'glpi_reservationitems.itemtype AS _itemtype',
            'glpi_reservationitems.items_id AS _items_id',
        ],
        'FROM'      => ReservationRequest::getTable(),
        'LEFT JOIN' => [
            'glpi_reservationitems' => [
                'FKEY' => [
                    ReservationRequest::getTable() => 'reservationitems_id',
                    'glpi_reservationitems'        => 'id',
                ],
            ],
        ],
        'WHERE' => $requestWhere,
        'LIMIT' => 500,
    ]) as $row) {
        // Coleta IDs nativos vinculados para deduplicar
        $decoded = json_decode((string) ($row['native_reservations_json'] ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $nativeId) {
                $nativeId = (int) $nativeId;
                if ($nativeId > 0) {
                    $linkedNativeIds[$nativeId] = true;
                }
            }
        }

        $requesterId = (int) ($row['users_id_requester'] ?? 0);
        $forId       = (int) ($row['users_id_for'] ?? 0);
        $owns        = $currentUserId === $requesterId || $currentUserId === $forId;
        if (!$isAdmin && !$owns) {
            $events[] = $othersEvent(
                'request-' . (int) ($row['id'] ?? 0),
                (string) ($row['begin'] ?? ''),
                (string) ($row['end'] ?? ''),
                $forId > 0 ? $forId : $requesterId
            );
            continue;
        }

        $name = plugin_reservaplus_events_item_name(
            (string) ($row['_itemtype'] ?? ''),
            (int) ($row['_items_id'] ?? 0)
        );
        $events[] = [
            'id'                  => 'request-' . (int) ($row['id'] ?? 0),
            'requestId'           => (int) ($row['id'] ?? 0),
            'title'               => $name,
            'start'               => (string) ($row['begin'] ?? ''),
            'end'                 => (string) ($row['end'] ?? ''),
            'type'                => 'request',
            'status'              => (string) ($row['status'] ?? ReservationRequest::STATUS_CREATED),
            'url'                 => $pluginBase . '/front/reservation.form.php?duplicate=' . (int) ($row['id'] ?? 0),
            'users_id_requester'  => (int) ($row['users_id_requester'] ?? 0),
            'users_id_for'        => (int) ($row['users_id_for'] ?? 0),
        ];
    }
}

// Reservas nativas do GLPI. Pula as que já são representadas por um request
// do plugin (evita evento duplicado). Sobram só as criadas direto no GLPI.
if ($DB->tableExists('glpi_reservations')) {
    $nativeWhere = [
        'glpi_reservations.end'   => ['>', $start],
        'glpi_reservations.begin' => ['<', $end],
    ];
    if ($allowedItemIds !== null) {
        $nativeWhere['glpi_reservations.reservationitems_id'] = $allowedItemIds;
    }

    foreach ($DB->request([
        'SELECT'    => [
            'glpi_reservations.id',
            'glpi_reservations.begin',
            'glpi_reservations.end',
            'glpi_reservations.comment',
            'glpi_reservations.users_id',
            'glpi_reservationitems.itemtype AS _itemtype',
            'glpi_reservationitems.items_id AS _items_id',
        ],
        'FROM'      => 'glpi_reservations',
        'LEFT JOIN' => [
            'glpi_reservationitems' => [
                'FKEY' => [
                    'glpi_reservations'     => 'reservationitems_id',
                    'glpi_reservationitems' => 'id',
                ],
            ],
        ],
        'WHERE' => $nativeWhere,
        'LIMIT' => 500,
    ]) as $row) {
        $nativeId = (int) ($row['id'] ?? 0);
        if (isset($linkedNativeIds[$nativeId])) {
            continue; // já exibida como request do plugin
        }

        if (!$isAdmin && (int) ($row['users_id'] ?? 0) !== $currentUserId) {
            $events[] = $othersEvent(
                'native-' . $nativeId,
                (string) ($row['begin'] ?? ''),
                (string) ($row['end'] ?? ''),
                (int) ($row['users_id'] ?? 0)
            );
            continue;
        }

        $name = plugin_reservaplus_events_item_name(
            (string) ($row['_itemtype'] ?? ''),
            (int) ($row['_items_id'] ?? 0)
        );
        $events[] = [
            'id'    => 'native-' . $nativeId,
            'title' => $name,
            'start' => (string) ($row['begin'] ?? ''),
            'end'   => (string) ($row['end'] ?? ''),
            'type'  => 'native',
        ];
    }
}

if ($DB->tableExists(Block::getTable())) {
    foreach ($DB->request([
        'FROM'  => Block::getTable(),
        'WHERE' => [
            'is_active' => 1,
            'end'       => ['>', $start],
            'begin'     => ['<', $end],
        ],
        'LIMIT' => 500,
    ]) as $row) {
        $reason = trim((string) ($row['reason'] ?? ''));
        $events[] = [
            'id'    => 'block-' . (int) ($row['id'] ?? 0),
            // O motivo do bloqueio só é exposto a quem administra reservas.
            'title' => ($isAdmin && $reason !== '') ? $reason : __('Bloqueado', 'reservaplus'),
            'start' => (string) ($row['begin'] ?? ''),
            'end'   => (string) ($row['end'] ?? ''),
            'type'  => 'block',
        ];
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['events' => $events], JSON_THROW_ON_ERROR);
