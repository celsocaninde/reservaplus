<?php

declare(strict_types=1);

function plugin_reservaplus_run_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_reservaplus_notification_logs',
        'glpi_plugin_reservaplus_item_groups',
        'glpi_plugin_reservaplus_blocks',
        'glpi_plugin_reservaplus_rules',
        'glpi_plugin_reservaplus_requests',
        'glpi_plugin_reservaplus_configs',
    ];

    foreach ($tables as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `$table`");
    }

    return true;
}
