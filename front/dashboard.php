<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Dashboard::canView()) {
    Html::displayRightError();
}

Dashboard::renderHeader(__('Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();
Dashboard::showDashboard();
Dashboard::renderFooter();
