<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Config;
use GlpiPlugin\Reservaplus\Dashboard;

include('../../../inc/includes.php');

Session::checkRight(Config::$rightname, READ);

$config = Config::getSingleton();

if (isset($_POST['update'])) {
    Session::checkRight(Config::$rightname, UPDATE);

    if ((int) ($config->fields['id'] ?? 0) > 0) {
        $config->update($_POST);
    } else {
        $config->add($_POST);
    }

    Session::addMessageAfterRedirect(__('Configuração do Reserva Plus salva.', 'reservaplus'));
    Html::redirect(Dashboard::getUrl('config.form.php'));
}

Html::header(__('Configuração do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();
$config->showForm((int) ($config->fields['id'] ?? 0));
Html::footer();
