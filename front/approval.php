<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Approval;
use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\NotificationService;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(Approval::$rightname, READ);

if ((isset($_POST['approve']) || isset($_POST['refuse'])) && Approval::canUpdate()) {
    global $DB;

    $requestId = (int) ($_POST['id'] ?? 0);
    $decision = isset($_POST['approve']) ? ReservationRequest::STATUS_APPROVED : ReservationRequest::STATUS_REFUSED;
    $request = $DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => ['id' => $requestId],
        'LIMIT' => 1,
    ])->current();

    if (!$request) {
        Session::addMessageAfterRedirect(__('Solicitação não encontrada.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    if ($decision === ReservationRequest::STATUS_APPROVED) {
        $conflicts = (new AvailabilityService())->getConflicts(
            (int) $request['reservationitems_id'],
            (string) $request['begin'],
            (string) $request['end']
        );

        if ($conflicts !== []) {
            Session::addMessageAfterRedirect(__('Não foi possível aprovar porque o período não está mais disponível.', 'reservaplus'), false, ERROR);
            Html::back();
        }

        $DB->beginTransaction();
        try {
            $reservation = new Reservation();
            $newId = $reservation->add([
                'reservationitems_id' => (int) $request['reservationitems_id'],
                'begin'               => (string) $request['begin'],
                'end'                 => (string) $request['end'],
                'users_id'            => (int) $request['users_id_for'],
                'comment'             => (string) ($request['comment'] ?? ''),
            ]);

            if (!$newId) {
                throw new \RuntimeException('native reservation add failed');
            }

            $DB->update(ReservationRequest::getTable(), [
                'status'                   => ReservationRequest::STATUS_APPROVED,
                'native_reservations_json' => json_encode([(int) $newId]),
                'date_mod'                 => date('Y-m-d H:i:s'),
            ], ['id' => $requestId]);

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            Session::addMessageAfterRedirect(__('Não foi possível criar a reserva nativa do GLPI.', 'reservaplus'), false, ERROR);
            Html::back();
        }
    } else {
        $DB->update(ReservationRequest::getTable(), [
            'status'   => ReservationRequest::STATUS_REFUSED,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $requestId]);
    }

    $decisionComment = (string) ($_POST['decision_comment'] ?? '');
    $DB->update(Approval::getTable(), [
        'users_id_approver' => Session::getLoginUserID(),
        'status'            => $decision,
        'decision_comment'  => $decisionComment,
        'decided_at'        => date('Y-m-d H:i:s'),
    ], ['plugin_reservaplus_requests_id' => $requestId]);

    if ($decision === ReservationRequest::STATUS_APPROVED) {
        NotificationService::approved((array) $request);
    } else {
        NotificationService::refused((array) $request, $decisionComment);
    }

    Session::addMessageAfterRedirect(__('Decisão de aprovação salva.', 'reservaplus'));
    Html::redirect(Dashboard::getUrl('approval.php'));
}

global $DB;
$rows = [];
if ($DB->tableExists(ReservationRequest::getTable())) {
    foreach ($DB->request([
        'SELECT'    => [
            ReservationRequest::getTable() . '.*',
            'glpi_reservationitems.itemtype AS _itemtype',
            'glpi_reservationitems.items_id AS _items_id',
        ],
        'FROM'      => ReservationRequest::getTable(),
        'LEFT JOIN' => [
            'glpi_reservationitems' => [
                'FKEY' => [
                    ReservationRequest::getTable() => 'reservationitems_id',
                    'glpi_reservationitems'        => 'id',
                ],
            ],
        ],
        'WHERE' => ['status' => ReservationRequest::STATUS_PENDING],
        'ORDER' => ['begin ASC'],
        'LIMIT' => 100,
    ]) as $row) {
        $rows[] = $row;
    }
}

// Fetch user names in batch for all requester and for-user IDs
$userIdsTofetch = [];
foreach ($rows as $row) {
    $userIdsTofetch[] = (int) ($row['users_id_requester'] ?? 0);
    $userIdsTofetch[] = (int) ($row['users_id_for'] ?? 0);
}
$userIdsTofetch = array_values(array_unique(array_filter($userIdsTofetch)));
$userNames = [];
if ($userIdsTofetch !== [] && $DB->tableExists('glpi_users')) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['id' => $userIdsTofetch],
    ]) as $u) {
        $first = trim((string) ($u['firstname'] ?? ''));
        $last  = trim((string) ($u['realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        $userNames[(int) $u['id']] = $full !== '' ? $full : (string) ($u['name'] ?? '#' . $u['id']);
    }
}

Html::header(__('Aprovações do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

$csrfToken = Session::getNewCSRFToken();

echo "<div class='reservaplus-shell'>";

echo "<div class='reservaplus-toolbar'>";
echo '<div>';
echo '<h1>' . __('Aprovações pendentes', 'reservaplus') . '</h1>';
echo '<p>' . __('Aprove ou recuse as solicitações de reserva abaixo.', 'reservaplus') . '</p>';
echo '</div>';
$pendingCount = count($rows);
if ($pendingCount > 0) {
    echo "<span class='reservaplus-badge reservaplus-badge-pending'>" . $pendingCount . ' ' . ($pendingCount === 1 ? __('pendente', 'reservaplus') : __('pendentes', 'reservaplus')) . '</span>';
}
echo '</div>';

if ($rows === []) {
    echo "<section class='reservaplus-panel'>";
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-checklist'></i>";
    echo '<strong>' . __('Nenhuma aprovação pendente.', 'reservaplus') . '</strong>';
    echo '<span>' . __('Solicitações que precisam de decisão aparecerão aqui.', 'reservaplus') . '</span>';
    echo '</div>';
    echo '</section>';
} else {
    echo "<div class='reservaplus-approval-cards'>";

    foreach ($rows as $row) {
        $requestId = (int) ($row['id'] ?? 0);

        // Resolve item name
        $itemtype = (string) ($row['_itemtype'] ?? '');
        $itemsId  = (int) ($row['_items_id'] ?? 0);
        $itemName = __('Item', 'reservaplus') . ' #' . (int) ($row['reservationitems_id'] ?? 0);
        if ($itemtype !== '' && class_exists($itemtype) && $itemsId > 0) {
            $obj = new $itemtype();
            if ($obj->getFromDB($itemsId)) {
                $n = method_exists($obj, 'getName') ? $obj->getName() : '';
                if ($n !== '') {
                    $itemName = $n;
                }
            }
        }

        // Resolve requester and for-user names from pre-fetched batch
        $reqId   = (int) ($row['users_id_requester'] ?? 0);
        $forId   = (int) ($row['users_id_for'] ?? 0);
        $reqName = $userNames[$reqId] ?? '#' . $reqId;
        $forName = ($forId > 0 && $forId !== $reqId) ? ($userNames[$forId] ?? '#' . $forId) : null;

        // Format dates
        $begin    = (string) ($row['begin'] ?? '');
        $end      = (string) ($row['end'] ?? '');
        $beginFmt = $begin !== '' ? date('d/m/Y H:i', strtotime($begin)) : '-';
        $endFmt   = $end   !== '' ? date('d/m/Y H:i', strtotime($end))   : '-';
        $comment  = trim((string) ($row['comment'] ?? ''));

        echo "<form method='post' action='" . Dashboard::getUrl('approval.php') . "'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
        echo Html::hidden('id', ['value' => $requestId]);
        echo "<div class='reservaplus-approval-card'>";

        // Card header
        echo "<div class='reservaplus-approval-card-header'>";
        echo "<div>";
        echo '<strong>' . Html::cleanInputText($itemName) . '</strong>';
        echo "</div>";
        echo "<span class='reservaplus-badge reservaplus-badge-pending'><i class='ti ti-clock' style='font-size:.72rem'></i>&nbsp;" . __('Pendente', 'reservaplus') . '</span>';
        echo '</div>';

        // Meta grid
        echo "<div class='reservaplus-approval-card-body'>";
        echo "<div class='reservaplus-approval-meta'>";

        echo "<div class='reservaplus-approval-meta-item'>";
        echo '<small>' . __('Início', 'reservaplus') . '</small>';
        echo '<span><i class="ti ti-calendar" style="font-size:.85rem;margin-right:4px;color:#0f766e"></i>' . Html::cleanInputText($beginFmt) . '</span>';
        echo '</div>';

        echo "<div class='reservaplus-approval-meta-item'>";
        echo '<small>' . __('Fim', 'reservaplus') . '</small>';
        echo '<span><i class="ti ti-calendar-off" style="font-size:.85rem;margin-right:4px;color:#667085"></i>' . Html::cleanInputText($endFmt) . '</span>';
        echo '</div>';

        echo "<div class='reservaplus-approval-meta-item'>";
        echo '<small>' . __('Solicitante', 'reservaplus') . '</small>';
        echo '<span><i class="ti ti-user" style="font-size:.85rem;margin-right:4px;color:#667085"></i>' . Html::cleanInputText($reqName) . '</span>';
        if ($forName !== null) {
            echo '<span style="font-size:.78rem;color:#0f766e"><i class="ti ti-user-share" style="font-size:.75rem"></i> ' . __('para', 'reservaplus') . ' ' . Html::cleanInputText($forName) . '</span>';
        }
        echo '</div>';

        echo '</div>';

        if ($comment !== '') {
            echo "<div style='background:#f8fafc;border:1px solid #e4e7ec;border-radius:7px;color:#475467;font-size:.85rem;padding:10px 12px'>";
            echo '<i class="ti ti-message" style="font-size:.85rem;margin-right:6px;color:#98a2b3"></i>';
            echo Html::cleanInputText($comment);
            echo '</div>';
        }

        echo "<label style='display:grid;gap:5px'>";
        echo "<span style='color:#475467;font-size:.82rem;font-weight:700'>" . __('Comentário da decisão', 'reservaplus') . ' <span style="font-weight:400;color:#98a2b3">(' . __('opcional', 'reservaplus') . ')</span></span>';
        echo "<textarea class='form-control' name='decision_comment' rows='2' placeholder='" . __('Motivo da aprovação ou recusa...', 'reservaplus') . "'></textarea>";
        echo '</label>';

        echo '</div>';

        // Footer with action buttons
        echo "<div class='reservaplus-approval-card-footer'>";
        if (Approval::canUpdate()) {
            echo Html::submit(__('Recusar', 'reservaplus'), ['name' => 'refuse', 'class' => 'btn btn-outline-danger']);
            echo Html::submit(__('Aprovar', 'reservaplus'), ['name' => 'approve', 'class' => 'btn btn-primary']);
        }
        echo '</div>';

        echo '</div>';
        Html::closeForm();
    }

    echo '</div>';
}

echo '</div>';

Html::footer();
