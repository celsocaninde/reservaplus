<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(Block::$rightname, READ);

// CSRF já validado/consumido pelo kernel do GLPI 11 (CheckCsrfListener).
if (isset($_POST['delete'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && (ReservationRequest::isGlpiAdmin() || Block::canDelete())) {
        global $DB;
        $DB->delete(Block::getTable(), ['id' => $id]);
        Session::addMessageAfterRedirect(__('Bloqueio apagado com sucesso.', 'reservaplus'));
    } else {
        Session::addMessageAfterRedirect(__('Sem permissão para apagar este bloqueio.', 'reservaplus'), false, ERROR);
    }
}

Html::redirect(Dashboard::getUrl('block.php'));
