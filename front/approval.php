<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Approval;
use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(Approval::$rightname, READ);

if ((isset($_POST['approve']) || isset($_POST['refuse'])) && Approval::canUpdate()) {
    global $DB;

    $requestId = (int) ($_POST['id'] ?? 0);
    $decision = isset($_POST['approve']) ? ReservationRequest::STATUS_APPROVED : ReservationRequest::STATUS_REFUSED;
    $request = $DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => [
            'id' => $requestId,
        ],
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

        $reservation = new Reservation();
        $newId = $reservation->add([
            'reservationitems_id' => (int) $request['reservationitems_id'],
            'begin'               => (string) $request['begin'],
            'end'                 => (string) $request['end'],
            'users_id'            => (int) $request['users_id_for'],
            'comment'             => (string) ($request['comment'] ?? ''),
        ]);

        if (!$newId) {
            Session::addMessageAfterRedirect(__('Não foi possível criar a reserva nativa do GLPI.', 'reservaplus'), false, ERROR);
            Html::back();
        }

        $DB->update(ReservationRequest::getTable(), [
            'status'                   => ReservationRequest::STATUS_APPROVED,
            'native_reservations_json' => json_encode([(int) $newId]),
            'date_mod'                 => date('Y-m-d H:i:s'),
        ], [
            'id' => $requestId,
        ]);
    } else {
        $DB->update(ReservationRequest::getTable(), [
            'status'   => ReservationRequest::STATUS_REFUSED,
            'date_mod' => date('Y-m-d H:i:s'),
        ], [
            'id' => $requestId,
        ]);
    }

    $DB->update(Approval::getTable(), [
        'users_id_approver' => Session::getLoginUserID(),
        'status'            => $decision,
        'decision_comment'  => (string) ($_POST['decision_comment'] ?? ''),
        'decided_at'        => date('Y-m-d H:i:s'),
    ], [
        'plugin_reservaplus_requests_id' => $requestId,
    ]);

    Session::addMessageAfterRedirect(__('Decisão de aprovação salva.', 'reservaplus'));
    Html::redirect(Dashboard::getUrl('approval.php'));
}

global $DB;
$rows = [];
if ($DB->tableExists(ReservationRequest::getTable())) {
    foreach ($DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => [
            'status' => ReservationRequest::STATUS_PENDING,
        ],
        'ORDER' => ['begin ASC'],
        'LIMIT' => 100,
    ]) as $row) {
        $rows[] = $row;
    }
}

Html::header(__('Aprovações do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<section class='reservaplus-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Aprovações', 'reservaplus') . '</h1>';
echo '<p>' . __('Aprove ou recuse solicitações de reserva pendentes.', 'reservaplus') . '</p>';
echo '</div>';
echo '</div>';

if ($rows === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-checklist'></i>";
    echo '<strong>' . __('Nenhuma aprovação pendente.', 'reservaplus') . '</strong>';
    echo '<span>' . __('Solicitações que precisam de decisão aparecerão aqui.', 'reservaplus') . '</span>';
    echo '</div>';
} else {
    echo "<div class='reservaplus-list'>";
    foreach ($rows as $row) {
        echo "<form method='post' action='" . Dashboard::getUrl('approval.php') . "' class='reservaplus-approval-row'>";
        echo '<div>';
        echo '<strong>' . __('Solicitação', 'reservaplus') . ' #' . (int) ($row['id'] ?? 0) . '</strong>';
        echo '<span>' . __('Item', 'reservaplus') . ' #' . (int) ($row['reservationitems_id'] ?? 0) . ' | ' . Html::cleanInputText((string) ($row['begin'] ?? '')) . ' - ' . Html::cleanInputText((string) ($row['end'] ?? '')) . '</span>';
        echo "<textarea class='form-control' name='decision_comment' rows='2' placeholder='" . __('Comentário da decisão', 'reservaplus') . "'></textarea>";
        echo '</div>';
        echo "<div class='reservaplus-actions'>";
        echo Html::hidden('id', ['value' => (int) ($row['id'] ?? 0)]);
        if (Approval::canUpdate()) {
            echo Html::submit(__('Aprovar', 'reservaplus'), ['name' => 'approve', 'class' => 'btn btn-success']);
            echo Html::submit(__('Recusar', 'reservaplus'), ['name' => 'refuse', 'class' => 'btn btn-outline-danger']);
        }
        echo '</div>';
        Html::closeForm();
    }
    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
