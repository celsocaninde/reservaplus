<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

class ReservationService
{
    public function requiresApproval(): bool
    {
        $config = Config::getSingleton();
        $mode = (string) ($config->fields['approval_mode'] ?? 'rules');

        return $mode !== 'never';
    }
}
