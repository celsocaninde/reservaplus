<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Dashboard::canView()) {
    Html::displayRightError();
}

Html::header(__('Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();
Dashboard::showDashboard();
Html::footer();
