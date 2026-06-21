<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Config;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\NotificationService;
use GlpiPlugin\Reservaplus\ReservationRequest;
use GlpiPlugin\Reservaplus\Rule;

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
        $itemtype = (string) ($row['itemtype'] ?? '');
        $row['_label'] = plugin_reservaplus_get_item_display_name(
            $itemtype,
            (int) ($row['items_id'] ?? 0)
        );
        $row['_typelabel'] = ($itemtype !== '' && class_exists($itemtype) && method_exists($itemtype, 'getTypeName'))
            ? (string) $itemtype::getTypeName(2)
            : ($itemtype !== '' ? $itemtype : __('Outros', 'reservaplus'));
        $items[] = $row;
    }

    // Filtra itens por acesso de grupo
    $allowedIds = ItemGroup::getAllowedItemIds();
    if ($allowedIds !== null) {
        $items = array_values(array_filter($items, static fn(array $i): bool => in_array((int) ($i['id'] ?? 0), $allowedIds, true)));
    }

    // Ordena por tipo e depois por nome para agrupar no <optgroup>
    usort($items, static function (array $a, array $b): int {
        return [$a['_typelabel'], $a['_label']] <=> [$b['_typelabel'], $b['_label']];
    });

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

/** Rótulo amigável de um item reservável (para os resumos de reserva em massa). */
function plugin_reservaplus_reservationitem_label(int $reservationItemsId): string
{
    global $DB;

    if ($reservationItemsId <= 0 || !$DB->tableExists('glpi_reservationitems')) {
        return '#' . $reservationItemsId;
    }
    $row = $DB->request([
        'SELECT' => ['itemtype', 'items_id'],
        'FROM'   => 'glpi_reservationitems',
        'WHERE'  => ['id' => $reservationItemsId],
        'LIMIT'  => 1,
    ])->current();
    if (!$row) {
        return '#' . $reservationItemsId;
    }
    return plugin_reservaplus_get_item_display_name((string) ($row['itemtype'] ?? ''), (int) ($row['items_id'] ?? 0));
}

/**
 * Gera as ocorrências (begin/end) de uma reserva conforme a recorrência, sempre
 * no mesmo horário do dia. Tipos: none, daily, weekly (dias da semana 0=Dom..6=Sáb),
 * monthly (mesmo dia do mês). Limitado por contagem, data final e teto de segurança.
 *
 * @param array{type:string,weekdays:array<int,int|string>,count:int,until:string} $rec
 * @return array<int,array{begin:string,end:string}>
 */
function plugin_reservaplus_occurrences(string $baseBegin, string $baseEnd, array $rec): array
{
    $startTs = strtotime($baseBegin);
    $endTs   = strtotime($baseEnd);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return [];
    }

    $type = (string) ($rec['type'] ?? 'none');
    if (!in_array($type, ['daily', 'weekly', 'monthly'], true)) {
        return [['begin' => date('Y-m-d H:i:s', $startTs), 'end' => date('Y-m-d H:i:s', $endTs)]];
    }

    $durationSec = $endTs - $startTs;
    $timeOfDay   = date('H:i:s', $startTs);
    $baseDate    = (int) strtotime(date('Y-m-d', $startTs));

    $maxOcc   = 100;
    $count    = (int) ($rec['count'] ?? 0);
    $limit    = $count > 0 ? min($count, $maxOcc) : $maxOcc;
    $untilTs  = !empty($rec['until']) ? strtotime((string) $rec['until'] . ' 23:59:59') : 0;
    $hardStop = (int) strtotime('+366 days', $baseDate);

    $occ  = [];
    $push = static function (int $dayTs) use (&$occ, $timeOfDay, $durationSec): void {
        $b = (int) strtotime(date('Y-m-d', $dayTs) . ' ' . $timeOfDay);
        $occ[] = ['begin' => date('Y-m-d H:i:s', $b), 'end' => date('Y-m-d H:i:s', $b + $durationSec)];
    };

    if ($type === 'daily') {
        for ($d = $baseDate; $d <= $hardStop && count($occ) < $limit; $d = (int) strtotime('+1 day', $d)) {
            if ($untilTs && $d > $untilTs) {
                break;
            }
            $push($d);
        }
    } elseif ($type === 'weekly') {
        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($rec['weekdays'] ?? [])),
            static fn(int $w): bool => $w >= 0 && $w <= 6
        )));
        if ($weekdays === []) {
            $weekdays = [(int) date('w', $startTs)];
        }
        for ($d = $baseDate; $d <= $hardStop && count($occ) < $limit; $d = (int) strtotime('+1 day', $d)) {
            if ($untilTs && $d > $untilTs) {
                break;
            }
            if (in_array((int) date('w', $d), $weekdays, true)) {
                $push($d);
            }
        }
    } else { // monthly — mesmo dia do mês
        $dom = (int) date('j', $startTs);
        for ($m = 0; $m < 60 && count($occ) < $limit; $m++) {
            $monthTs = (int) strtotime("+{$m} month", $baseDate);
            if ((int) date('t', $monthTs) < $dom) {
                continue; // mês não tem esse dia (ex.: 31 em fevereiro)
            }
            $d = (int) strtotime(date('Y-m', $monthTs) . '-' . str_pad((string) $dom, 2, '0', STR_PAD_LEFT));
            if ($d < $baseDate) {
                continue;
            }
            if (($untilTs && $d > $untilTs) || $d > $hardStop) {
                break;
            }
            $push($d);
        }
    }

    return $occ;
}

if (isset($_POST['add'])) {
    global $DB;

    // Reserva em massa: um ou vários itens no MESMO período. Cada item é validado
    // e reservado de forma independente; os itens livres são reservados e os
    // indisponíveis são apenas reportados (sucesso parcial).
    $rawItems = $_POST['reservationitems_id'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [$rawItems];
    }
    $itemIds = array_values(array_unique(array_filter(
        array_map('intval', $rawItems),
        static fn(int $v): bool => $v > 0
    )));

    $begin   = plugin_reservaplus_normalize_datetime((string) ($_POST['begin'] ?? ''));
    $end     = plugin_reservaplus_normalize_datetime((string) ($_POST['end'] ?? ''));
    $comment = (string) ($_POST['comment'] ?? '');

    if ($itemIds === [] || $begin === '' || $end === '' || strtotime($begin) >= strtotime($end)) {
        Session::addMessageAfterRedirect(__('Selecione ao menos um item e um período válido.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    // Horário comercial (mesmo período para todos os itens) — valida uma vez.
    $hoursError = Config::businessHoursViolation($begin, $end);
    if ($hoursError !== null) {
        Session::addMessageAfterRedirect($hoursError, false, ERROR);
        Html::back();
    }

    // Reservar para outro usuário (somente admin/gestor). O ID postado é
    // validado contra a lista de usuários elegíveis para não aceitar qualquer
    // valor arbitrário vindo do formulário.
    $usersIdFor = (int) Session::getLoginUserID();
    if (plugin_reservaplus_can_reserve_for_others()) {
        $forPosted = (int) ($_POST['users_id_for'] ?? 0);
        if ($forPosted > 0 && $forPosted !== $usersIdFor) {
            $eligibleIds = array_map(
                static fn(array $u): int => (int) ($u['id'] ?? 0),
                plugin_reservaplus_get_active_users()
            );
            if (!in_array($forPosted, $eligibleIds, true)) {
                Session::addMessageAfterRedirect(__('Usuário selecionado para a reserva é inválido.', 'reservaplus'), false, ERROR);
                Html::back();
            }
            $usersIdFor = $forPosted;
        }
    }

    $requester    = (int) Session::getLoginUserID();
    $profileId    = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    $entityId     = method_exists(Session::class, 'getActiveEntity') ? (int) Session::getActiveEntity() : 0;
    $availability = new AvailabilityService();

    // Recorrência: gera as ocorrências (mesmo horário do dia) a reservar.
    $recType = (string) ($_POST['recurrence_type'] ?? 'none');
    $recEnd  = (string) ($_POST['recurrence_end'] ?? 'count');
    $rec = [
        'type'     => $recType,
        'weekdays' => (array) ($_POST['recurrence_weekdays'] ?? []),
        'count'    => $recEnd === 'count' ? (int) ($_POST['recurrence_count'] ?? 0) : 0,
        'until'    => $recEnd === 'until' ? (string) ($_POST['recurrence_until'] ?? '') : '',
    ];
    $occurrences = plugin_reservaplus_occurrences($begin, $end, $rec);
    if ($occurrences === []) {
        Session::addMessageAfterRedirect(__('Não foi possível gerar as datas da recorrência.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $isRecurring     = in_array($recType, ['daily', 'weekly', 'monthly'], true) && count($occurrences) > 1;
    $recurrenceGroup = $isRecurring ? bin2hex(random_bytes(16)) : null;
    $recurrenceJson  = $isRecurring
        ? json_encode(['type' => $recType, 'weekdays' => array_values(array_map('intval', $rec['weekdays'])), 'count' => $rec['count'], 'until' => $rec['until']])
        : null;

    // Metadados por item (permissão + itemtype), resolvidos uma única vez.
    $itemMeta = [];
    foreach ($itemIds as $iid) {
        $label   = plugin_reservaplus_reservationitem_label($iid);
        $allowed = ItemGroup::isAllowed($iid);
        $itemtype = '';
        if ($allowed) {
            $r = $DB->request(['SELECT' => ['itemtype'], 'FROM' => 'glpi_reservationitems', 'WHERE' => ['id' => $iid], 'LIMIT' => 1])->current();
            $itemtype = $r ? (string) ($r['itemtype'] ?? '') : '';
        }
        $itemMeta[$iid] = ['allowed' => $allowed, 'label' => $label, 'itemtype' => $itemtype];
    }

    $maxTotal    = 200; // teto de segurança por envio
    $created     = 0;
    $skipped     = [];
    $datesHit    = [];
    $capped      = false;
    $lastPayload = null;

    foreach ($occurrences as $occ) {
        $oBegin    = $occ['begin'];
        $oEnd      = $occ['end'];
        $dateLabel = date('d/m', (int) strtotime($oBegin));

        foreach ($itemIds as $iid) {
            if ($created >= $maxTotal) {
                $capped = true;
                break 2;
            }
            $meta  = $itemMeta[$iid];
            $label = $meta['label'];
            $tag   = $isRecurring ? sprintf('%s (%s)', $label, $dateLabel) : $label;

            if (!$meta['allowed']) {
                $skipped[] = sprintf(__('%s — sem permissão', 'reservaplus'), $tag);
                continue;
            }
            if ($availability->getConflicts($iid, $oBegin, $oEnd) !== []) {
                $skipped[] = sprintf(__('%s — já ocupado', 'reservaplus'), $tag);
                continue;
            }
            $ruleError = Rule::validateForCreate($profileId, $entityId, $meta['itemtype'], $oBegin, $oEnd);
            if ($ruleError !== null) {
                $skipped[] = sprintf('%s — %s', $tag, $ruleError);
                continue;
            }

            // Reserva nativa + registro do plugin em transação.
            $DB->beginTransaction();
            try {
                $reservation = new Reservation();
                $newId = $reservation->add([
                    'reservationitems_id' => $iid,
                    'begin'               => $oBegin,
                    'end'                 => $oEnd,
                    'users_id'            => $usersIdFor,
                    'comment'             => $comment,
                ]);
                if (!$newId) {
                    throw new \RuntimeException('native reservation add failed');
                }
                $DB->insert(ReservationRequest::getTable(), [
                    'entities_id'              => $entityId,
                    'reservationitems_id'      => $iid,
                    'users_id_requester'       => $requester,
                    'users_id_for'             => $usersIdFor,
                    'status'                   => ReservationRequest::STATUS_CREATED,
                    'begin'                    => $oBegin,
                    'end'                      => $oEnd,
                    'comment'                  => $comment,
                    'recurrence_json'          => $recurrenceJson,
                    'recurrence_group'         => $recurrenceGroup,
                    'native_reservations_json' => json_encode([(int) $newId]),
                    'date_creation'            => date('Y-m-d H:i:s'),
                    'date_mod'                 => date('Y-m-d H:i:s'),
                ]);
                $DB->commit();
            } catch (\Throwable $e) {
                $DB->rollBack();
                $skipped[] = sprintf(__('%s — falha ao registrar', 'reservaplus'), $tag);
                continue;
            }

            $lastPayload = [
                'reservationitems_id' => $iid,
                'users_id_requester'  => $requester,
                'users_id_for'        => $usersIdFor,
                'begin'               => $oBegin,
                'end'                 => $oEnd,
            ];
            // E-mail por reserva; webhook é disparado uma vez no fim (false aqui).
            NotificationService::reservationCreated($lastPayload, false);
            $created++;
            $datesHit[$dateLabel] = true;
        }
    }

    // Webhook: uma vez por envio (1 reserva = payload da reserva; lote = resumo).
    if ($created === 1 && $lastPayload !== null) {
        NotificationService::webhook('reservation.created', $lastPayload);
    } elseif ($created > 1) {
        NotificationService::webhook('reservation.created', [
            'batch'            => true,
            'count'            => $created,
            'recurrence_group' => $recurrenceGroup,
            'recurrence_type'  => $isRecurring ? $recType : 'none',
            'reservationitems' => array_values($itemIds),
            'from'             => $occurrences[0]['begin'] ?? null,
        ]);
    }

    if ($created > 0) {
        $msg = sprintf(_n('%d reserva criada.', '%d reservas criadas.', $created, 'reservaplus'), $created);
        if (count($datesHit) > 1) {
            $msg .= ' ' . sprintf(__('em %d datas.', 'reservaplus'), count($datesHit));
        }
        Session::addMessageAfterRedirect($msg);
    }
    if ($skipped !== []) {
        $shown = array_slice($skipped, 0, 15);
        $more  = count($skipped) - count($shown);
        Session::addMessageAfterRedirect(
            __('Não reservados:', 'reservaplus') . ' ' . implode(' · ', $shown) . ($more > 0 ? ' · +' . $more : ''),
            false,
            $created > 0 ? WARNING : ERROR
        );
    }
    if ($capped) {
        Session::addMessageAfterRedirect(sprintf(__('Limite de %d reservas por envio atingido — reduza o período/itens.', 'reservaplus'), $maxTotal), false, WARNING);
    }
    if ($created === 0 && $skipped === []) {
        Session::addMessageAfterRedirect(__('Nenhuma reserva criada.', 'reservaplus'), false, ERROR);
    }

    Html::redirect(Dashboard::getUrl('reservation.php'));
}

// No GLPI 11 este arquivo é incluído dentro de um método (LegacyFileLoadController),
// então o escopo não é global: é preciso declarar global $DB explicitamente.
global $DB;

$items        = plugin_reservaplus_get_reservable_items();
$canForOthers = plugin_reservaplus_can_reserve_for_others();
$users        = $canForOthers ? plugin_reservaplus_get_active_users() : [];

// Categorias distintas (para "selecionar todos da categoria") e horário
// comercial (para os atalhos de duração "manhã/tarde/dia todo").
$categoryLabels = [];
foreach ($items as $it) {
    $cl = (string) ($it['_typelabel'] ?? '');
    if ($cl !== '') {
        $categoryLabels[$cl] = true;
    }
}
$categoryLabels = array_keys($categoryLabels);

$rpCfg   = Config::getSingleton();
$bhStart = substr((string) ($rpCfg->fields['business_hours_start'] ?? '08:00:00'), 0, 5);
$bhEnd   = substr((string) ($rpCfg->fields['business_hours_end'] ?? '18:00:00'), 0, 5);

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

$selectedItem = $source !== null ? (int) $source['reservationitems_id'] : (int) ($_GET['item'] ?? 0);
$comment      = $source !== null ? (string) ($source['comment'] ?? '') : '';
$selectedFor  = $source !== null ? (int) $source['users_id_for'] : (int) Session::getLoginUserID();

$now = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['begin']))
    : date('Y-m-d\TH:00');
$end = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['end']))
    : date('Y-m-d\TH:00', strtotime('+1 hour'));

$pageTitle = $source !== null ? __('Duplicar reserva', 'reservaplus') : __('Nova reserva', 'reservaplus');

Dashboard::renderHeader(__('Nova reserva do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<form method='post' action='" . Dashboard::getUrl('reservation.form.php') . "'>";
echo "<section class='reservaplus-panel reservaplus-form-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . $pageTitle . '</h1>';
echo '<p>' . __('Selecione um ou mais itens e reserve todos no mesmo período. Os itens ocupados são apenas avisados.', 'reservaplus') . '</p>';
echo '</div>';
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Voltar', 'reservaplus') . '</a>';
echo '</div>';

echo "<div class='reservaplus-form-grid reservaplus-reservation-form' data-reservaplus-availability-form data-availability-url='/plugins/reservaplus/ajax/availability.php' data-slots-url='/plugins/reservaplus/ajax/freeslots.php'>";
if ($categoryLabels !== []) {
    echo "<div class='reservaplus-field-wide reservaplus-item-tools' data-reservaplus-item-tools>";
    echo "<select class='form-select form-select-sm' data-reservaplus-cat aria-label='" . __('Categoria', 'reservaplus') . "'>";
    echo "<option value=''>" . __('Categoria…', 'reservaplus') . '</option>';
    foreach ($categoryLabels as $cl) {
        echo "<option value='" . Html::cleanInputText($cl) . "'>" . Html::cleanInputText($cl) . '</option>';
    }
    echo '</select>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-cat>" . __('Selecionar categoria', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-all>" . __('Todos', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-clear-sel>" . __('Limpar', 'reservaplus') . '</button>';
    echo '</div>';
}
echo '<label class="reservaplus-field-item"><span>' . __('Itens reserváveis', 'reservaplus') . ' <small class="text-muted">' . __('(selecione um ou vários)', 'reservaplus') . "</small></span><select name='reservationitems_id[]' class='form-select' multiple size='8' required>";
$currentGroup = null;
foreach ($items as $item) {
    $itemId    = (int) ($item['id'] ?? 0);
    $typeLabel = (string) ($item['_typelabel'] ?? __('Outros', 'reservaplus'));
    if ($typeLabel !== $currentGroup) {
        if ($currentGroup !== null) {
            echo '</optgroup>';
        }
        echo "<optgroup label='" . Html::cleanInputText($typeLabel) . "'>";
        $currentGroup = $typeLabel;
    }
    $selected = $itemId === $selectedItem ? ' selected' : '';
    echo "<option value='" . $itemId . "'" . $selected . '>' . Html::cleanInputText((string) ($item['_label'] ?? '#' . $itemId)) . '</option>';
}
if ($currentGroup !== null) {
    echo '</optgroup>';
}
echo '</select></label>';
echo '<label><span>' . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' value='" . Html::cleanInputText($now) . "' required></label>";
echo '<label><span>' . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' value='" . Html::cleanInputText($end) . "' required></label>";

echo "<div class='reservaplus-field-wide reservaplus-duration-presets' data-reservaplus-presets data-bh-start='" . Html::cleanInputText($bhStart) . "' data-bh-end='" . Html::cleanInputText($bhEnd) . "'>";
echo "<span class='reservaplus-presets-label'>" . __('Duração rápida:', 'reservaplus') . '</span>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-duration='60'>1h</button>";
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-duration='120'>2h</button>";
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-duration='240'>4h</button>";
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='morning'>" . __('Manhã', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='afternoon'>" . __('Tarde', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='full'>" . __('Dia todo', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-success' data-reservaplus-find-slots><i class='ti ti-search'></i> " . __('Horários livres', 'reservaplus') . '</button>';
echo '</div>';

echo "<div class='reservaplus-availability reservaplus-field-wide' data-reservaplus-availability hidden></div>";
echo "<div class='reservaplus-field-wide reservaplus-slots' data-reservaplus-slots hidden></div>";

// --- Recorrência (diária / semanal / mensal) ---
echo "<div class='reservaplus-field-wide reservaplus-recurrence' data-reservaplus-recurrence>";
echo '<label><span>' . __('Repetir', 'reservaplus') . "</span><select name='recurrence_type' class='form-select' data-reservaplus-rec-type>";
echo "<option value='none'>" . __('Não repete', 'reservaplus') . '</option>';
echo "<option value='daily'>" . __('Diariamente', 'reservaplus') . '</option>';
echo "<option value='weekly'>" . __('Semanalmente', 'reservaplus') . '</option>';
echo "<option value='monthly'>" . __('Mensalmente (mesmo dia do mês)', 'reservaplus') . '</option>';
echo '</select></label>';

echo "<div class='reservaplus-rec-weekdays' data-reservaplus-rec-weekdays hidden>";
echo "<span class='reservaplus-rec-label'>" . __('Dias da semana', 'reservaplus') . '</span>';
foreach ([0 => __('Dom', 'reservaplus'), 1 => __('Seg', 'reservaplus'), 2 => __('Ter', 'reservaplus'), 3 => __('Qua', 'reservaplus'), 4 => __('Qui', 'reservaplus'), 5 => __('Sex', 'reservaplus'), 6 => __('Sáb', 'reservaplus')] as $val => $lbl) {
    echo "<label class='reservaplus-dow'><input type='checkbox' name='recurrence_weekdays[]' value='" . $val . "'><span>" . Html::cleanInputText((string) $lbl) . '</span></label>';
}
echo '</div>';

echo "<div class='reservaplus-rec-end' data-reservaplus-rec-end hidden>";
echo '<label><span>' . __('Terminar', 'reservaplus') . "</span><select name='recurrence_end' class='form-select' data-reservaplus-rec-endmode>";
echo "<option value='count'>" . __('Após N ocorrências', 'reservaplus') . '</option>';
echo "<option value='until'>" . __('Em uma data', 'reservaplus') . '</option>';
echo '</select></label>';
echo "<label data-reservaplus-rec-count><span>" . __('Ocorrências', 'reservaplus') . "</span><input class='form-control' type='number' name='recurrence_count' min='1' max='100' value='4'></label>";
echo "<label data-reservaplus-rec-until hidden><span>" . __('Até', 'reservaplus') . "</span><input class='form-control' type='date' name='recurrence_until'></label>";
echo '</div>';
echo '</div>';

if ($canForOthers) {
    echo '<label class="reservaplus-field-wide reservaplus-for-others"><span>';
    echo "<i class='ti ti-user-share reservaplus-ico-accent'></i>";
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

Dashboard::renderFooter();
