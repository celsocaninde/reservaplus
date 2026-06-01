<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Rule;

include('../../../inc/includes.php');

Session::checkRight(Rule::$rightname, READ);

global $DB;
$rows = [];
if ($DB->tableExists(Rule::getTable())) {
    foreach ($DB->request([
        'FROM'  => Rule::getTable(),
        'ORDER' => ['id DESC'],
        'LIMIT' => 100,
    ]) as $row) {
        $rows[] = $row;
    }
}

Html::header(__('Regras do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<section class='reservaplus-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Regras', 'reservaplus') . '</h1>';
echo '<p>' . __('Regras de aprovação e agendamento por perfil, entidade e tipo de item.', 'reservaplus') . '</p>';
echo '</div>';
if (Rule::canCreate()) {
    echo "<a class='btn btn-primary' href='" . Dashboard::getUrl('rule.form.php') . "'><i class='ti ti-plus'></i> " . __('Nova regra', 'reservaplus') . '</a>';
}
echo '</div>';
echo "<table class='table table-hover reservaplus-table'><thead><tr><th>ID</th><th>" . __('Nome', 'reservaplus') . '</th><th>' . __('Aprovação', 'reservaplus') . '</th><th>' . __('Ativa', 'reservaplus') . '</th></tr></thead><tbody>';
foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>#' . (int) ($row['id'] ?? 0) . '</td>';
    echo '<td>' . Html::cleanInputText((string) ($row['name'] ?? '')) . '</td>';
    echo '<td>' . ((int) ($row['requires_approval'] ?? 0) ? __('Obrigatória', 'reservaplus') : __('Não obrigatória', 'reservaplus')) . '</td>';
    echo '<td>' . ((int) ($row['is_active'] ?? 0) ? __('Sim', 'reservaplus') : __('Não', 'reservaplus')) . '</td>';
    echo '</tr>';
}
if ($rows === []) {
    echo "<tr><td colspan='4'><div class='reservaplus-empty reservaplus-empty-table'><i class='ti ti-adjustments'></i><strong>" . __('Ainda não há regras.', 'reservaplus') . '</strong></div></td></tr>';
}
echo '</tbody></table>';
echo '</section>';
echo '</div>';

Html::footer();
