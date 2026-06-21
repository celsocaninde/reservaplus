<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, UPDATE);

ItemGroup::ensureTable();

global $DB;

if (isset($_POST['save']) && (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests())) {
    $itemId   = (int) ($_POST['reservationitems_id'] ?? 0);
    $groupIds = array_map('intval', (array) ($_POST['groups_id'] ?? []));

    if ($itemId > 0) {
        ItemGroup::setGroupsForItem($itemId, $groupIds);
        Session::addMessageAfterRedirect(__('Grupos da sala atualizados.', 'reservaplus'));
    }

    Html::redirect(Dashboard::getUrl('itemgroup.php'));
}

// All active reservable items
$items = [];
if ($DB->tableExists('glpi_reservationitems')) {
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
    ]) as $row) {
        $itemtype = (string) ($row['itemtype'] ?? '');
        $itemsId  = (int) ($row['items_id'] ?? 0);
        $label    = $itemtype . ' #' . $itemsId;
        if ($itemtype !== '' && class_exists($itemtype) && $itemsId > 0) {
            $obj = new $itemtype();
            if ($obj->getFromDB($itemsId)) {
                $name = method_exists($obj, 'getName') ? $obj->getName() : '';
                if ($name !== '') {
                    $label = $name;
                }
            }
        }
        $row['_label']  = $label;
        $row['_groups'] = ItemGroup::getGroupsForItem((int) $row['id']);
        $items[] = $row;
    }
}

// All GLPI user groups
$groups = [];
if ($DB->tableExists('glpi_groups')) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'completename'],
        'FROM'   => 'glpi_groups',
        'WHERE'  => ['is_usergroup' => 1],
        'ORDER'  => ['completename ASC'],
    ]) as $row) {
        $groups[] = $row;
    }
}

Html::header(__('Grupos por sala — Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

$csrfToken = Session::getNewCSRFToken();

echo "<div class='reservaplus-shell'>";

// Page header
echo "<div class='reservaplus-toolbar'>";
echo '<div>';
echo '<h1>' . __('Grupos por sala', 'reservaplus') . '</h1>';
echo '<p>' . __('Salas sem grupo ficam visíveis a todos. Salas com grupo(s) só aparecem para membros desses grupos.', 'reservaplus') . '</p>';
echo '</div>';
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Reservas', 'reservaplus') . '</a>';
echo '</div>';

echo "<section class='reservaplus-panel'>";

if ($items === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-building'></i>";
    echo '<strong>' . __('Nenhuma sala reservável cadastrada.', 'reservaplus') . '</strong>';
    echo '<span>' . __('Cadastre itens reserváveis no GLPI para configurar restrições de acesso.', 'reservaplus') . '</span>';
    echo '</div>';
} else {
    echo "<div class='reservaplus-item-cards'>";

    foreach ($items as $item) {
        $itemId      = (int) ($item['id'] ?? 0);
        $itemLabel   = (string) ($item['_label'] ?? '#' . $itemId);
        $itemGroups  = (array) ($item['_groups'] ?? []);
        $isRestricted = $itemGroups !== [];

        echo "<form method='post' action='" . Dashboard::getUrl('itemgroup.php') . "'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
        echo "<input type='hidden' name='reservationitems_id' value='" . $itemId . "'>";
        echo "<div class='reservaplus-item-card'>";

        // Card header
        echo "<div class='reservaplus-item-card-header'>";
        echo "<div class='reservaplus-item-card-header-left'>";
        echo "<span class='reservaplus-item-card-icon'><i class='ti ti-door'></i></span>";
        echo '<strong title="' . Html::cleanInputText($itemLabel) . '">' . Html::cleanInputText($itemLabel) . '</strong>';
        echo '</div>';
        if ($isRestricted) {
            echo "<span class='reservaplus-badge reservaplus-badge-pending'><i class='ti ti-lock' style='font-size:.72rem'></i>&nbsp;" . __('Restrita', 'reservaplus') . '</span>';
        } else {
            echo "<span class='reservaplus-badge reservaplus-badge-approved'><i class='ti ti-world' style='font-size:.72rem'></i>&nbsp;" . __('Pública', 'reservaplus') . '</span>';
        }
        echo '</div>';

        // Card body
        echo "<div class='reservaplus-item-card-body'>";
        echo '<small>' . __('Grupos com acesso', 'reservaplus') . '</small>';

        if ($groups === []) {
            echo "<div class='reservaplus-no-groups'><i class='ti ti-alert-circle'></i>" . __('Nenhum grupo de usuários cadastrado no GLPI.', 'reservaplus') . '</div>';
        } else {
            echo "<div class='reservaplus-checkgroup'>";
            foreach ($groups as $g) {
                $gid     = (int) $g['id'];
                $gname   = (string) ($g['completename'] ?? $g['name'] ?? '#' . $gid);
                $checked = in_array($gid, $itemGroups, true);
                $checkedAttr  = $checked ? ' checked' : '';
                $checkedClass = $checked ? ' is-checked' : '';
                echo "<label class='reservaplus-check-label" . $checkedClass . "'>";
                echo "<input type='checkbox' name='groups_id[]' value='" . $gid . "'" . $checkedAttr . ' onchange="this.closest(\'.reservaplus-check-label\').classList.toggle(\'is-checked\',this.checked)"> ';
                echo '<span>' . Html::cleanInputText($gname) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo "<p style='color:#98a2b3;font-size:.78rem;margin:0'>" . __('Sem seleção = acesso público.', 'reservaplus') . '</p>';
        }

        echo '</div>';

        // Card footer
        echo "<div class='reservaplus-item-card-footer'>";
        echo Html::submit(__('Salvar', 'reservaplus'), ['name' => 'save', 'class' => 'btn btn-sm btn-primary']);
        echo '</div>';

        echo '</div>';
        Html::closeForm();
    }

    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
