<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

// CSRF: o kernel do GLPI 11 (CheckCsrfListener) já valida e consome o token
// _glpi_csrf_token em todo POST não-AJAX. Chamar Session::checkCSRF() aqui de
// novo falharia, pois o token já foi removido do pool.

Session::checkRight(ReservationRequest::$rightname, READ);

if (isset($_POST['delete'])) {
    $requestId = (int) ($_POST['id'] ?? 0);
    if (ReservationRequest::deleteRequest($requestId)) {
        Session::addMessageAfterRedirect(__('Reserva apagada com sucesso.', 'reservaplus'));
    } else {
        Session::addMessageAfterRedirect(__('Você só pode apagar reservas criadas por você.', 'reservaplus'), false, ERROR);
    }
}

if (isset($_POST['cancel'])) {
    $requestId = (int) ($_POST['id'] ?? 0);
    if (ReservationRequest::cancelRequest($requestId)) {
        Session::addMessageAfterRedirect(__('Reserva cancelada com sucesso.', 'reservaplus'));
    } else {
        Session::addMessageAfterRedirect(__('Não foi possível cancelar esta reserva.', 'reservaplus'), false, ERROR);
    }
}

if (isset($_POST['cancel_series'])) {
    $group = trim((string) ($_POST['recurrence_group'] ?? ''));
    $n     = ReservationRequest::cancelSeries($group);
    if ($n > 0) {
        Session::addMessageAfterRedirect(sprintf(
            _n('%d reserva da série cancelada.', '%d reservas da série canceladas.', $n, 'reservaplus'),
            $n
        ));
    } else {
        Session::addMessageAfterRedirect(__('Nenhuma reserva futura da série pôde ser cancelada.', 'reservaplus'), false, ERROR);
    }
}

$redirect = trim((string) ($_POST['redirect'] ?? ''));
if (!in_array($redirect, ['reservation.php', 'calendar.php'], true)) {
    $redirect = 'reservation.php';
}
Html::redirect(Dashboard::getUrl($redirect));
