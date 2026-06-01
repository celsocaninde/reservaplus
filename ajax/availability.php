<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

$reservationItemsId = (int) ($_GET['reservationitems_id'] ?? 0);
$begin = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string) ($_GET['begin'] ?? 'now'))));
$end = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string) ($_GET['end'] ?? '+1 hour'))));
$conflicts = (new AvailabilityService())->getConflicts($reservationItemsId, $begin, $end);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'available' => $conflicts === [],
    'conflicts' => $conflicts,
], JSON_THROW_ON_ERROR);
