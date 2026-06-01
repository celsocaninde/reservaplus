<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, CREATE);

function plugin_reservaplus_normalize_datetime(string $value): string
{
    $timestamp = strtotime(str_replace('T', ' ', $value));
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function plugin_reservaplus_get_item_display_name(string $itemtype, int $itemsId): string
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

function plugin_reservaplus_get_reservable_items(): array
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
        $row['_label'] = plugin_reservaplus_get_item_display_name(
            (string) ($row['itemtype'] ?? ''),
            (int) ($row['items_id'] ?? 0)
        );
        $items[] = $row;
    }

    return $items;
}

function plugin_reservaplus_get_active_users(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_users')) {
        return [];
    }

    $users = [];
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => [
            'is_deleted' => 0,
            'is_active'  => 1,
        ],
        'ORDER' => ['realname ASC', 'firstname ASC'],
        'LIMIT' => 500,
    ]) as $row) {
        $first = trim((string) ($row['firstname'] ?? ''));
        $last  = trim((string) ($row['realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        $row['_label'] = $full !== '' ? $full : (string) ($row['name'] ?? '#' . $row['id']);
        $users[] = $row;
    }

    return $users;
}

function plugin_reservaplus_can_reserve_for_others(): bool
{
    return ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
}

if (isset($_POST['add'])) {
    global $DB;

    $reservationItemsId = (int) ($_POST['reservationitems_id'] ?? 0);
    $begin   = plugin_reservaplus_normalize_datetime((string) ($_POST['begin'] ?? ''));
    $end     = plugin_reservaplus_normalize_datetime((string) ($_POST['end'] ?? ''));
    $comment = (string) ($_POST['comment'] ?? '');

    // Reservar para outro usuário (somente admin/gestor)
    $usersIdFor = (int) Session::getLoginUserID();
    if (plugin_reservaplus_can_reserve_for_others()) {
        $forPosted = (int) ($_POST['users_id_for'] ?? 0);
        if ($forPosted > 0) {
            $usersIdFor = $forPosted;
        }
    }

    if ($reservationItemsId <= 0 || $begin === '' || $end === '' || strtotime($begin) >= strtotime($end)) {
        Session::addMessageAfterRedirect(__('Período ou item da reserva inválido.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $conflicts = (new AvailabilityService())->getConflicts($reservationItemsId, $begin, $end);
    if ($conflicts !== []) {
        Session::addMessageAfterRedirect(__('Este item não está disponível no período selecionado.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $reservation = new Reservation();
    $newId = $reservation->add([
        'reservationitems_id' => $reservationItemsId,
        'begin'               => $begin,
        'end'                 => $end,
        'users_id'            => $usersIdFor,
        'comment'             => $comment,
    ]);

    if (!$newId) {
        Session::addMessageAfterRedirect(__('Não foi possível registrar a reserva.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $DB->insert(ReservationRequest::getTable(), [
        'entities_id'              => method_exists(Session::class, 'getActiveEntity') ? (int) Session::getActiveEntity() : 0,
        'reservationitems_id'      => $reservationItemsId,
        'users_id_requester'       => (int) Session::getLoginUserID(),
        'users_id_for'             => $usersIdFor,
        'status'                   => ReservationRequest::STATUS_CREATED,
        'begin'                    => $begin,
        'end'                      => $end,
        'comment'                  => $comment,
        'recurrence_json'          => null,
        'native_reservations_json' => json_encode([(int) $newId]),
        'date_creation'            => date('Y-m-d H:i:s'),
        'date_mod'                 => date('Y-m-d H:i:s'),
    ]);

    $msg = $usersIdFor !== (int) Session::getLoginUserID()
        ? __('Reserva criada com sucesso para o usuário selecionado.', 'reservaplus')
        : __('Reserva criada com sucesso.', 'reservaplus');
    Session::addMessageAfterRedirect($msg);
    Html::redirect(Dashboard::getUrl('reservation.php'));
}

$items        = plugin_reservaplus_get_reservable_items();
$canForOthers = plugin_reservaplus_can_reserve_for_others();
$users        = $canForOthers ? plugin_reservaplus_get_active_users() : [];

// Load source reservation when duplicating
$duplicateId = (int) ($_GET['duplicate'] ?? 0);
$source      = null;
if ($duplicateId > 0 && $DB->tableExists(ReservationRequest::getTable())) {
    $source = $DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => ['id' => $duplicateId],
        'LIMIT' => 1,
    ])->current() ?: null;
}

$selectedItem = $source !== null ? (int) $source['reservationitems_id'] : 0;
$comment      = $source !== null ? (string) ($source['comment'] ?? '') : '';
$selectedFor  = $source !== null ? (int) $source['users_id_for'] : (int) Session::getLoginUserID();

$now = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['begin']))
    : date('Y-m-d\TH:00');
$end = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['end']))
    : date('Y-m-d\TH:00', strtotime('+1 hour'));

$pageTitle = $source !== null ? __('Duplicar reserva', 'reservaplus') : __('Nova reserva', 'reservaplus');

Html::header(__('Nova reserva do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<form method='post' action='" . Dashboard::getUrl('reservation.form.php') . "'>";
echo "<section class='reservaplus-panel reservaplus-form-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . $pageTitle . '</h1>';
echo '<p>' . __('Verifique a disponibilidade no calendário e reserve o item desejado.', 'reservaplus') . '</p>';
echo '</div>';
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Voltar', 'reservaplus') . '</a>';
echo '</div>';

echo "<div class='reservaplus-form-grid reservaplus-reservation-form'>";
echo '<label class="reservaplus-field-item"><span>' . __('Item reservável', 'reservaplus') . "</span><select name='reservationitems_id' class='form-select' required>";
echo '<option value="">' . __('Selecione um item', 'reservaplus') . '</option>';
foreach ($items as $item) {
    $itemId   = (int) ($item['id'] ?? 0);
    $selected = $itemId === $selectedItem ? ' selected' : '';
    echo "<option value='" . $itemId . "'" . $selected . '>' . Html::cleanInputText((string) ($item['_label'] ?? '#' . $itemId)) . '</option>';
}
echo '</select></label>';
echo '<label><span>' . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' value='" . Html::cleanInputText($now) . "' required></label>";
echo '<label><span>' . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' value='" . Html::cleanInputText($end) . "' required></label>";

if ($canForOthers) {
    echo '<label class="reservaplus-field-wide reservaplus-for-others"><span>';
    echo "<i class='ti ti-user-share' style='color:#0f766e;margin-right:4px'></i>";
    echo __('Reservar para', 'reservaplus');
    echo "</span><select name='users_id_for' class='form-select'>";
    echo "<option value='" . (int) Session::getLoginUserID() . "'" . ($selectedFor === (int) Session::getLoginUserID() ? ' selected' : '') . '>' . __('Eu mesmo', 'reservaplus') . '</option>';
    foreach ($users as $user) {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid === (int) Session::getLoginUserID()) {
            continue;
        }
        $sel = $uid === $selectedFor ? ' selected' : '';
        echo "<option value='" . $uid . "'" . $sel . '>' . Html::cleanInputText((string) ($user['_label'] ?? '#' . $uid)) . '</option>';
    }
    echo '</select></label>';
}

echo '<label class="reservaplus-field-wide"><span>' . __('Comentário', 'reservaplus') . "</span><textarea class='form-control' name='comment' rows='4' placeholder='" . __('Motivo, sala, observações...', 'reservaplus') . "'>" . Html::cleanInputText($comment) . '</textarea></label>';
echo '</div>';

echo "<div class='reservaplus-actions mt-3'>";
echo Html::submit(__('Reservar', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary']);
echo "<a class='btn btn-outline-primary' href='" . Dashboard::getUrl('calendar.php') . "'><i class='ti ti-calendar'></i> " . __('Ver calendário', 'reservaplus') . '</a>';
echo '</div>';
echo '</section>';
Html::closeForm();
echo '</div>';

Html::footer();
