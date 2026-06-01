<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Block;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Report;
use GlpiPlugin\Reservaplus\ReservationRequest;

include('../../../inc/includes.php');

Session::checkRight(Report::$rightname, READ);

global $DB;

$isAdmin = Session::haveRight(Report::$rightname, READ) && Session::haveRight('plugin_reservaplus_config', UPDATE);

$view = (string) ($_GET['view'] ?? 'week');
$view = in_array($view, ['week', 'month'], true) ? $view : 'week';

$offsetParam = (int) ($_GET['offset'] ?? 0);

if ($view === 'week') {
    $monday = new DateTimeImmutable(date('Y-m-d', strtotime('monday this week')));
    if ($offsetParam !== 0) {
        $monday = $monday->modify(($offsetParam > 0 ? '+' : '') . $offsetParam . ' weeks');
    }
    $periodStart = $monday->format('Y-m-d 00:00:00');
    $periodEnd   = $monday->modify('+6 days')->format('Y-m-d 23:59:59');
    $periodLabel = $monday->format('d/m/Y') . ' – ' . $monday->modify('+6 days')->format('d/m/Y');
} else {
    $firstDay = new DateTimeImmutable(date('Y-m-01'));
    if ($offsetParam !== 0) {
        $firstDay = $firstDay->modify(($offsetParam > 0 ? '+' : '') . $offsetParam . ' months');
    }
    $periodStart = $firstDay->format('Y-m-d 00:00:00');
    $periodEnd   = $firstDay->modify('last day of this month')->format('Y-m-d 23:59:59');
    $monthNames  = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];
    $periodLabel = ($monthNames[(int) $firstDay->format('n')] ?? $firstDay->format('m')) . ' de ' . $firstDay->format('Y');
}

$rows = [];
if ($DB->tableExists(ReservationRequest::getTable())) {
    $where = [
        ReservationRequest::getTable() . '.end'   => ['>', $periodStart],
        ReservationRequest::getTable() . '.begin' => ['<', $periodEnd],
    ];
    if (!$isAdmin) {
        $where[ReservationRequest::getTable() . '.users_id_requester'] = (int) Session::getLoginUserID();
    }
    $rows = ReservationRequest::getFiltered($where, 500);
}

$totalHours = 0.0;
foreach ($rows as $r) {
    $b = strtotime((string) ($r['begin'] ?? ''));
    $e = strtotime((string) ($r['end'] ?? ''));
    if ($b && $e && $e > $b) {
        $totalHours += ($e - $b) / 3600;
    }
}

$prevOffset = $offsetParam - 1;
$nextOffset = $offsetParam + 1;

function plugin_reservaplus_report_url(string $view, int $offset): string
{
    return Dashboard::getUrl('report.php') . '?view=' . urlencode($view) . '&offset=' . $offset;
}

Html::header(__('Relatórios do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<style>
@media print {
  .reservaplus-no-print { display: none !important; }
  .reservaplus-shell { padding: 0 !important; max-width: 100% !important; }
  .reservaplus-panel { box-shadow: none !important; border: 1px solid #ccc !important; }
  .reservaplus-report-header { background: none !important; }
  body { font-size: 12px; }
  table { page-break-inside: avoid; }
  tr { page-break-inside: avoid; }
}
.reservaplus-report-header { background: linear-gradient(90deg, rgba(15,118,110,.08), rgba(37,99,235,.04) 220px); border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.reservaplus-report-stat { text-align: center; min-width: 100px; }
.reservaplus-report-stat strong { display: block; font-size: 2rem; color: #1f2937; line-height: 1; }
.reservaplus-report-stat span { font-size: 0.82rem; color: #667085; font-weight: 600; }
.reservaplus-view-tabs { display: flex; gap: 6px; }
.reservaplus-period-nav { display: flex; align-items: center; gap: 8px; }
.reservaplus-period-nav strong { font-size: 1rem; color: #1f2937; min-width: 180px; text-align: center; }
.reservaplus-report-table th { background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; color: #475467; }
.reservaplus-report-table td { vertical-align: middle; }
.reservaplus-duration { font-size: 0.82rem; color: #667085; }
</style>";

echo "<div class='reservaplus-shell'>";

echo "<div class='reservaplus-toolbar reservaplus-no-print'>";
echo '<div>';
echo '<h1>' . __('Relatórios', 'reservaplus') . '</h1>';
echo '<p>' . __('Acompanhe o uso das reservas por semana ou mês.', 'reservaplus') . '</p>';
echo '</div>';
echo "<div class='reservaplus-actions'>";
echo "<div class='reservaplus-view-tabs'>";
echo "<a class='btn " . ($view === 'week' ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText(plugin_reservaplus_report_url('week', 0)) . "'><i class='ti ti-calendar-week'></i> " . __('Semana', 'reservaplus') . '</a>';
echo "<a class='btn " . ($view === 'month' ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText(plugin_reservaplus_report_url('month', 0)) . "'><i class='ti ti-calendar-month'></i> " . __('Mês', 'reservaplus') . '</a>';
echo '</div>';
echo "<button type='button' class='btn btn-outline-primary' onclick='window.print()'><i class='ti ti-printer'></i> " . __('Exportar PDF', 'reservaplus') . '</button>';
echo '</div>';
echo '</div>';

echo "<section class='reservaplus-panel'>";

echo "<div class='reservaplus-report-header'>";
echo '<div>';
echo "<span class='reservaplus-kicker'>" . ($view === 'week' ? __('Relatório semanal', 'reservaplus') : __('Relatório mensal', 'reservaplus')) . '</span>';
echo "<h2 style='margin:4px 0 0; font-size:1.3rem; color:#1f2937'>" . Html::cleanInputText($periodLabel) . '</h2>';
echo '</div>';
echo "<div class='reservaplus-period-nav reservaplus-no-print'>";
echo "<a class='btn btn-sm btn-outline-secondary' href='" . Html::cleanInputText(plugin_reservaplus_report_url($view, $prevOffset)) . "'><i class='ti ti-chevron-left'></i></a>";
echo '<strong>' . Html::cleanInputText($periodLabel) . '</strong>';
echo "<a class='btn btn-sm btn-outline-secondary' href='" . Html::cleanInputText(plugin_reservaplus_report_url($view, $nextOffset)) . "'><i class='ti ti-chevron-right'></i></a>";
echo '</div>';
echo "<div style='display:flex; gap:24px'>";
echo "<div class='reservaplus-report-stat'><strong>" . count($rows) . '</strong><span>' . __('Reservas', 'reservaplus') . '</span></div>';
echo "<div class='reservaplus-report-stat'><strong>" . number_format($totalHours, 1, ',', '.') . 'h</strong><span>' . __('Horas reservadas', 'reservaplus') . '</span></div>';
echo '</div>';
echo '</div>';

if ($rows === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-calendar-off'></i>";
    echo '<strong>' . __('Nenhuma reserva no período.', 'reservaplus') . '</strong>';
    echo '</div>';
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table table-hover reservaplus-table reservaplus-report-table'>";
    echo '<thead><tr>';
    echo '<th>ID</th>';
    echo '<th>' . __('Item', 'reservaplus') . '</th>';
    if ($isAdmin) {
        echo '<th>' . __('Solicitante', 'reservaplus') . '</th>';
    }
    echo '<th>' . __('Início', 'reservaplus') . '</th>';
    echo '<th>' . __('Fim', 'reservaplus') . '</th>';
    echo '<th>' . __('Duração', 'reservaplus') . '</th>';
    echo '<th>' . __('Comentário', 'reservaplus') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $b = strtotime((string) ($row['begin'] ?? ''));
        $e = strtotime((string) ($row['end'] ?? ''));
        $durMins = ($b && $e && $e > $b) ? (int) round(($e - $b) / 60) : 0;
        $durH    = intdiv($durMins, 60);
        $durM    = $durMins % 60;
        $durStr  = $durH > 0 ? "{$durH}h" . ($durM > 0 ? "{$durM}min" : '') : ($durM > 0 ? "{$durM}min" : '-');
        $beginFmt = $b ? date('d/m/Y H:i', $b) : '-';
        $endFmt   = $e ? date('d/m/Y H:i', $e) : '-';
        $comment = Html::cleanInputText((string) ($row['comment'] ?? ''));

        echo '<tr>';
        echo '<td>#' . (int) ($row['id'] ?? 0) . '</td>';
        echo '<td>' . Html::cleanInputText(ReservationRequest::getItemDisplayName($row)) . '</td>';
        if ($isAdmin) {
            echo '<td>' . Html::cleanInputText(ReservationRequest::getUserDisplayName($row)) . '</td>';
        }
        echo '<td>' . Html::cleanInputText($beginFmt) . '</td>';
        echo '<td>' . Html::cleanInputText($endFmt) . '</td>';
        echo "<td class='reservaplus-duration'>" . Html::cleanInputText($durStr) . '</td>';
        echo '<td>' . ($comment !== '' ? $comment : '<span style="color:#98a2b3">—</span>') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
