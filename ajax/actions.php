<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, UPDATE);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok'      => false,
    'message' => __('O endpoint de ações do Reserva Plus está pronto para a próxima fase de implementação.', 'reservaplus'),
], JSON_THROW_ON_ERROR);
