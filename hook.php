<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Profile;

function plugin_reservaplus_install(): bool
{
    require_once __DIR__ . '/sql/install.php';

    if (!plugin_reservaplus_run_install()) {
        return false;
    }

    Profile::ensureProfileRights();

    return true;
}

function plugin_reservaplus_uninstall(): bool
{
    require_once __DIR__ . '/sql/uninstall.php';

    plugin_reservaplus_run_uninstall();

    $rights = array_column(Profile::getAllRights(), 'field');
    if ($rights !== []) {
        ProfileRight::deleteProfileRights($rights);
    }

    return true;
}
