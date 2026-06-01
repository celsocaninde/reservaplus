<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

class AvailabilityService
{
    public function getConflicts(int $reservationItemsId, string $begin, string $end): array
    {
        global $DB;

        $conflicts = [];

        if ($reservationItemsId <= 0 || $begin === '' || $end === '') {
            return $conflicts;
        }

        if ($DB->tableExists('glpi_reservations')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_reservations',
                'WHERE' => [
                    'reservationitems_id' => $reservationItemsId,
                    'end'                 => ['>', $begin],
                    'begin'               => ['<', $end],
                ],
            ]) as $row) {
                $conflicts[] = [
                    'type'  => 'reservation',
                    'id'    => (int) ($row['id'] ?? 0),
                    'begin' => (string) ($row['begin'] ?? ''),
                    'end'   => (string) ($row['end'] ?? ''),
                ];
            }
        }

        if ($DB->tableExists(Block::getTable())) {
            foreach ($DB->request([
                'FROM'  => Block::getTable(),
                'WHERE' => [
                    'is_active'           => 1,
                    'reservationitems_id' => [0, $reservationItemsId],
                    'end'                 => ['>', $begin],
                    'begin'               => ['<', $end],
                ],
            ]) as $row) {
                $conflicts[] = [
                    'type'   => 'block',
                    'id'     => (int) ($row['id'] ?? 0),
                    'begin'  => (string) ($row['begin'] ?? ''),
                    'end'    => (string) ($row['end'] ?? ''),
                    'reason' => (string) ($row['reason'] ?? ''),
                ];
            }
        }

        return $conflicts;
    }
}
