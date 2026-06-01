<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

if (isset($_POST['delete'])) {
    Session::checkCSRF($_POST);

    $requestId = (int) ($_POST['id'] ?? 0);
    if (ReservationRequest::deleteRequest($requestId)) {
        Session::addMessageAfterRedirect(__('Reserva apagada com sucesso.', 'reservaplus'));
    } else {
        Session::addMessageAfterRedirect(__('Você só pode apagar reservas criadas por você.', 'reservaplus'), false, ERROR);
    }
}

$redirect = trim((string) ($_POST['redirect'] ?? ''));
if (!in_array($redirect, ['reservation.php', 'calendar.php'], true)) {
    $redirect = 'reservation.php';
}
Html::redirect(Dashboard::getUrl($redirect));
