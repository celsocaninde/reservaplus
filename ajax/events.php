<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

global $CFG_GLPI, $DB;

$start = date('Y-m-d 00:00:00', strtotime((string) ($_GET['start'] ?? 'first day of this month')));
$end   = date('Y-m-d 23:59:59', strtotime((string) ($_GET['end'] ?? 'last day of this month')));

$pluginBase = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus';
$events     = [];

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

if ($DB->tableExists('glpi_reservations')) {
    foreach ($DB->request([
        'SELECT'    => [
            'glpi_reservations.id',
            'glpi_reservations.begin',
            'glpi_reservations.end',
            'glpi_reservations.comment',
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
        'WHERE' => [
            'glpi_reservations.end'   => ['>', $start],
            'glpi_reservations.begin' => ['<', $end],
        ],
        'LIMIT' => 500,
    ]) as $row) {
        $name = plugin_reservaplus_events_item_name(
            (string) ($row['_itemtype'] ?? ''),
            (int) ($row['_items_id'] ?? 0)
        );
        $events[] = [
            'id'    => 'native-' . (int) ($row['id'] ?? 0),
            'title' => $name,
            'start' => (string) ($row['begin'] ?? ''),
            'end'   => (string) ($row['end'] ?? ''),
            'type'  => 'native',
        ];
    }
}

if ($DB->tableExists(ReservationRequest::getTable())) {
    foreach ($DB->request([
        'SELECT'    => [
            ReservationRequest::getTable() . '.id',
            ReservationRequest::getTable() . '.begin',
            ReservationRequest::getTable() . '.end',
            ReservationRequest::getTable() . '.status',
            ReservationRequest::getTable() . '.comment',
            ReservationRequest::getTable() . '.users_id_requester',
            ReservationRequest::getTable() . '.users_id_for',
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
        'WHERE' => [
            ReservationRequest::getTable() . '.end'   => ['>', $start],
            ReservationRequest::getTable() . '.begin' => ['<', $end],
        ],
        'LIMIT' => 500,
    ]) as $row) {
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
            'title' => $reason !== '' ? $reason : __('Bloqueado', 'reservaplus'),
            'start' => (string) ($row['begin'] ?? ''),
            'end'   => (string) ($row['end'] ?? ''),
            'type'  => 'block',
        ];
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['events' => $events], JSON_THROW_ON_ERROR);
