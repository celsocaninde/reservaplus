<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

if (!ReservationRequest::isGlpiAdmin()) {
    Session::checkRight(Block::$rightname, CREATE);
}

function plugin_reservaplus_block_form_item_name(string $itemtype, int $itemsId): string
{
    if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
        return $itemtype !== '' ? $itemtype . ' #' . $itemsId : '#' . $itemsId;
    }

    $obj = new $itemtype();
    if (!$obj->getFromDB($itemsId)) {
        return $itemtype . ' #' . $itemsId;
    }

    $name = method_exists($obj, 'getName') ? $obj->getName() : '';
    return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
}

function plugin_reservaplus_block_form_items(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_reservationitems')) {
        return [];
    }

    $items = [];
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
        'LIMIT' => 200,
    ]) as $row) {
        $row['_label'] = plugin_reservaplus_block_form_item_name(
            (string) ($row['itemtype'] ?? ''),
            (int) ($row['items_id'] ?? 0)
        );
        $items[] = $row;
    }

    return $items;
}

function plugin_reservaplus_block_datetime(string $value): string
{
    $timestamp = strtotime(str_replace('T', ' ', $value));
    return $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp);
}

if (isset($_POST['add'])) {
    // CSRF já validado/consumido pelo kernel do GLPI 11 (CheckCsrfListener).
    $begin = plugin_reservaplus_block_datetime((string) ($_POST['begin'] ?? ''));
    $end   = plugin_reservaplus_block_datetime((string) ($_POST['end'] ?? ''));

    if ($begin === '' || $end === '' || strtotime($begin) >= strtotime($end)) {
        Session::addMessageAfterRedirect(__('Período de bloqueio inválido.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $reservationItemsId = (int) ($_POST['reservationitems_id'] ?? 0);

    global $DB;
    $DB->insert(Block::getTable(), [
        'entities_id'           => method_exists(Session::class, 'getActiveEntity') ? (int) Session::getActiveEntity() : 0,
        'reservationitems_id'   => $reservationItemsId,
        'itemtype'              => '',
        'begin'                 => $begin,
        'end'                   => $end,
        'reason'                => trim((string) ($_POST['reason'] ?? '')),
        'is_active'             => isset($_POST['is_active']) ? 1 : 0,
        'date_creation'         => date('Y-m-d H:i:s'),
        'date_mod'              => date('Y-m-d H:i:s'),
    ]);

    Session::addMessageAfterRedirect(__('Bloqueio de horário criado com sucesso.', 'reservaplus'));
    Html::redirect(Dashboard::getUrl('block.php'));
}

$reservableItems = plugin_reservaplus_block_form_items();
$now             = date('Y-m-d\TH:00');
$endDefault      = date('Y-m-d\TH:00', strtotime('+1 hour'));

Html::header(__('Novo bloqueio de horário do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<form method='post' action='" . Dashboard::getUrl('block.form.php') . "'>";
echo "<section class='reservaplus-panel reservaplus-form-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Novo bloqueio de horário', 'reservaplus') . '</h1>';
echo '<p>' . __('Bloqueie janelas de manutenção ou períodos indisponíveis no calendário.', 'reservaplus') . '</p>';
echo '</div>';
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('block.php') . "'><i class='ti ti-arrow-left'></i> " . __('Voltar', 'reservaplus') . '</a>';
echo '</div>';

echo "<div class='reservaplus-form-grid'>";

// Item reservável (0 = global)
echo '<label class="reservaplus-field-wide reservaplus-field-item"><span>' . __('Item reservável', 'reservaplus') . "</span>";
echo "<select name='reservationitems_id' class='form-select'>";
echo "<option value='0'>" . __('Global (bloquear todos os itens)', 'reservaplus') . '</option>';
foreach ($reservableItems as $item) {
    echo "<option value='" . (int) ($item['id'] ?? 0) . "'>" . Html::cleanInputText((string) ($item['_label'] ?? '#' . (int) ($item['id'] ?? 0))) . '</option>';
}
echo '</select></label>';

echo '<label><span>' . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' value='" . Html::cleanInputText($now) . "' required></label>";
echo '<label><span>' . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' value='" . Html::cleanInputText($endDefault) . "' required></label>";
echo '<label class="reservaplus-field-wide"><span>' . __('Motivo', 'reservaplus') . "</span><textarea class='form-control' name='reason' rows='3' placeholder='" . __('Ex: Manutenção preventiva, feriado...', 'reservaplus') . "'></textarea></label>";
echo "<label class='reservaplus-toggle reservaplus-field-wide'><input type='checkbox' name='is_active' value='1' checked><span>" . __('Ativar bloqueio imediatamente', 'reservaplus') . '</span></label>';
echo '</div>';

echo "<div class='reservaplus-actions mt-3'>";
echo Html::submit(__('Salvar bloqueio', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary']);
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('block.php') . "'>" . __('Cancelar', 'reservaplus') . '</a>';
echo '</div>';
echo '</section>';
Html::closeForm();
echo '</div>';

Html::footer();
