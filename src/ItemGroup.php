<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;

class ItemGroup extends CommonDBTM
{
    public static $table    = 'glpi_plugin_reservaplus_item_groups';
    public static $rightname = 'plugin_reservaplus_reservation';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_reservaplus_item_groups';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Grupo por sala — Reserva Plus', 'reservaplus');
    }

    /** Creates the table on first use (idempotent). */
    public static function ensureTable(): void
    {
        global $DB;

        if ($DB->tableExists(static::getTable())) {
            return;
        }

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_item_groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_group` (`reservationitems_id`, `groups_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `groups_id` (`groups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Group IDs the current user belongs to (from GLPI session). */
    public static function getUserGroupIds(): array
    {
        return array_values(array_map('intval', $_SESSION['glpigroups'] ?? []));
    }

    /** True if the current user can see and reserve the given item. */
    public static function isAllowed(int $reservationItemsId): bool
    {
        if ($reservationItemsId <= 0) {
            return false;
        }

        if (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests()) {
            return true;
        }

        $groups = self::getGroupsForItem($reservationItemsId);

        // No group restriction → visible to everyone
        if ($groups === []) {
            return true;
        }

        $userGroups = self::getUserGroupIds();
        foreach ($groups as $groupId) {
            if (in_array($groupId, $userGroups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the reservationitems_id values the current user can access.
     * Returns null when no filter is needed (admin or no restrictions exist).
     * Returns [] when the user has access to no items at all.
     */
    public static function getAllowedItemIds(): ?array
    {
        global $DB;

        if (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests()) {
            return null;
        }

        if (!$DB->tableExists(static::getTable())) {
            return null;
        }

        // Items that have at least one group restriction
        $restrictedItems = [];
        foreach ($DB->request([
            'SELECT'  => ['reservationitems_id'],
            'FROM'    => static::getTable(),
            'GROUPBY' => ['reservationitems_id'],
        ]) as $row) {
            $restrictedItems[] = (int) $row['reservationitems_id'];
        }

        if ($restrictedItems === []) {
            return null; // no restrictions configured at all
        }

        // Of the restricted items, which ones match the user's groups?
        $allowedRestrictedIds = [];
        $userGroups = self::getUserGroupIds();

        if ($userGroups !== []) {
            foreach ($DB->request([
                'SELECT'  => ['reservationitems_id'],
                'FROM'    => static::getTable(),
                'WHERE'   => [
                    'groups_id'           => $userGroups,
                    'reservationitems_id' => $restrictedItems,
                ],
                'GROUPBY' => ['reservationitems_id'],
            ]) as $row) {
                $allowedRestrictedIds[] = (int) $row['reservationitems_id'];
            }
        }

        // All active item IDs
        $allItemIds = [];
        if ($DB->tableExists('glpi_reservationitems')) {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_reservationitems',
                'WHERE'  => ['is_active' => 1],
            ]) as $row) {
                $allItemIds[] = (int) $row['id'];
            }
        }

        // Unrestricted = items not present in the item_groups table
        $unrestrictedIds = array_values(array_diff($allItemIds, $restrictedItems));

        return array_values(array_unique(array_merge($unrestrictedIds, $allowedRestrictedIds)));
    }

    /** Group IDs assigned to the item. Empty array means no restriction. */
    public static function getGroupsForItem(int $reservationItemsId): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => static::getTable(),
            'WHERE'  => ['reservationitems_id' => $reservationItemsId],
        ]) as $row) {
            $ids[] = (int) $row['groups_id'];
        }

        return $ids;
    }

    /** Replaces all group assignments for the item atomically. */
    public static function setGroupsForItem(int $reservationItemsId, array $groupIds): void
    {
        global $DB;

        self::ensureTable();

        $DB->delete(static::getTable(), ['reservationitems_id' => $reservationItemsId]);

        foreach (array_values(array_unique(array_filter(array_map('intval', $groupIds)))) as $groupId) {
            if ($groupId <= 0) {
                continue;
            }
            $DB->insert(static::getTable(), [
                'reservationitems_id' => $reservationItemsId,
                'groups_id'           => $groupId,
                'date_creation'       => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
