<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

Dashboard::renderHeader(__('Reservas do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();
ReservationRequest::showList();
Dashboard::renderFooter();
