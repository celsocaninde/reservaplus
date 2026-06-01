<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(Block::$rightname, READ);

function plugin_reservaplus_block_item_name(int $reservationItemsId): string
{
    if ($reservationItemsId <= 0) {
        return __('Global (todos os itens)', 'reservaplus');
    }

    $resItem = new ReservationItem();
    if (!$resItem->getFromDB($reservationItemsId)) {
        return __('Item reservável', 'reservaplus') . ' #' . $reservationItemsId;
    }

    $itype = (string) ($resItem->fields['itemtype'] ?? '');
    $iid   = (int) ($resItem->fields['items_id'] ?? 0);
    if ($itype === '' || !class_exists($itype) || $iid <= 0) {
        return __('Item reservável', 'reservaplus') . ' #' . $reservationItemsId;
    }

    $obj = new $itype();
    if (!$obj->getFromDB($iid)) {
        return $itype . ' #' . $iid;
    }

    $name = method_exists($obj, 'getName') ? $obj->getName() : '';
    return $name !== '' ? $name : $itype . ' #' . $iid;
}

global $DB;
$rows = [];
if ($DB->tableExists(Block::getTable())) {
    foreach ($DB->request([
        'FROM'  => Block::getTable(),
        'ORDER' => ['begin DESC'],
        'LIMIT' => 200,
    ]) as $row) {
        $rows[] = $row;
    }
}

$canDelete  = ReservationRequest::isGlpiAdmin() || Block::canDelete();
$csrfToken  = Session::getNewCSRFToken();
$actionUrl  = Dashboard::getUrl('block.action.php');

Html::header(__('Bloqueios de horário do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<section class='reservaplus-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Bloqueios de horário', 'reservaplus') . '</h1>';
echo '<p>' . __('Bloqueie períodos globalmente ou para itens reserváveis específicos.', 'reservaplus') . '</p>';
echo '</div>';
if (Block::canCreate() || ReservationRequest::isGlpiAdmin()) {
    echo "<a class='btn btn-primary' href='" . Dashboard::getUrl('block.form.php') . "'><i class='ti ti-plus'></i> " . __('Novo bloqueio', 'reservaplus') . '</a>';
}
echo '</div>';

if ($rows === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-calendar-x'></i>";
    echo '<strong>' . __('Nenhum bloqueio de horário cadastrado.', 'reservaplus') . '</strong>';
    echo '<span>' . __('Crie um bloqueio para indisponibilizar períodos no calendário.', 'reservaplus') . '</span>';
    echo '</div>';
} else {
    echo "<div class='reservaplus-list'>";
    foreach ($rows as $row) {
        $id       = (int) ($row['id'] ?? 0);
        $itemName = plugin_reservaplus_block_item_name((int) ($row['reservationitems_id'] ?? 0));
        $begin    = (string) ($row['begin'] ?? '');
        $end      = (string) ($row['end'] ?? '');
        $reason   = trim((string) ($row['reason'] ?? ''));
        $isActive = (int) ($row['is_active'] ?? 0);

        $beginFmt = $begin !== '' ? date('d/m/Y H:i', strtotime($begin)) : '';
        $endFmt   = $end   !== '' ? date('d/m/Y H:i', strtotime($end))   : '';

        echo "<div class='reservaplus-list-row' style='border-left-color:#be185d'>";
        echo '<div>';
        echo '<strong>' . Html::cleanInputText($itemName) . '</strong>';
        echo '<span>' . Html::cleanInputText($beginFmt . ($endFmt !== '' ? ' → ' . $endFmt : '')) . '</span>';
        if ($reason !== '') {
            echo '<small style="color:#667085">' . Html::cleanInputText($reason) . '</small>';
        }
        echo '</div>';
        echo "<div style='align-items:center;display:flex;gap:10px;flex-direction:row'>";
        if ($isActive) {
            echo "<span class='reservaplus-badge' style='background:#fce7f3;color:#be185d'>" . __('Ativo', 'reservaplus') . '</span>';
        } else {
            echo "<span class='reservaplus-badge' style='background:#f1f5f9;color:#94a3b8'>" . __('Inativo', 'reservaplus') . '</span>';
        }
        if ($canDelete) {
            echo "<form method='post' action='" . $actionUrl . "' class='reservaplus-inline-form' onsubmit='return confirm(\"" . __('Tem certeza que deseja apagar este bloqueio?', 'reservaplus') . "\")'>";
            echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
            echo "<input type='hidden' name='id' value='" . $id . "'>";
            echo "<button type='submit' name='delete' class='btn btn-sm btn-outline-danger' title='" . __('Apagar', 'reservaplus') . "'><i class='ti ti-trash'></i></button>";
            echo '</form>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
