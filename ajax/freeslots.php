<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

$raw = $_GET['reservationitems_id'] ?? [];
if (!is_array($raw)) {
    $raw = [$raw];
}
$itemIds = array_values(array_unique(array_filter(
    array_map('intval', $raw),
    static fn(int $v): bool => $v > 0
)));

// Respeita o acesso de grupo do usuário.
$itemIds = array_values(array_filter($itemIds, static fn(int $id): bool => ItemGroup::isAllowed($id)));

$date  = (string) ($_GET['date'] ?? date('Y-m-d'));
$slots = $itemIds === [] ? [] : (new AvailabilityService())->freeSlots($itemIds, $date);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['slots' => $slots], JSON_THROW_ON_ERROR);
