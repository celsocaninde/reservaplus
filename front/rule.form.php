<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Rule;

include('../../../inc/includes.php');

Session::checkRight(Rule::$rightname, CREATE);

if (isset($_POST['add'])) {
    global $DB;

    $DB->insert(Rule::getTable(), [
        'name'                 => trim((string) ($_POST['name'] ?? __('Nova regra', 'reservaplus'))),
        'entities_id'          => method_exists(Session::class, 'getActiveEntity') ? (int) Session::getActiveEntity() : 0,
        'profiles_id'          => (int) ($_POST['profiles_id'] ?? 0),
        'itemtype'             => trim((string) ($_POST['itemtype'] ?? '')),
        'requires_approval'    => isset($_POST['requires_approval']) ? 1 : 0,
        'max_duration_minutes' => (int) ($_POST['max_duration_minutes'] ?? 0) ?: null,
        'min_notice_minutes'   => (int) ($_POST['min_notice_minutes'] ?? 0) ?: null,
        'max_days_ahead'       => (int) ($_POST['max_days_ahead'] ?? 0) ?: null,
        'is_active'            => isset($_POST['is_active']) ? 1 : 0,
        'date_creation'        => date('Y-m-d H:i:s'),
        'date_mod'             => date('Y-m-d H:i:s'),
    ]);

    Session::addMessageAfterRedirect(__('Regra criada com sucesso.', 'reservaplus'));
    Html::redirect(Dashboard::getUrl('rule.php'));
}

Html::header(__('Nova regra do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<form method='post' action='" . Dashboard::getUrl('rule.form.php') . "'>";
echo "<section class='reservaplus-panel reservaplus-form-panel'>";
echo "<div class='reservaplus-panel-header'><div><h1>" . __('Nova regra', 'reservaplus') . '</h1><p>' . __('Crie uma regra de aprovação para a entidade atual.', 'reservaplus') . '</p></div></div>';
echo "<div class='reservaplus-form-grid'>";
echo '<label><span>' . __('Nome', 'reservaplus') . "</span><input class='form-control' name='name' required></label>";
echo '<label><span>' . __('ID do perfil', 'reservaplus') . "</span><input class='form-control' type='number' name='profiles_id' min='0' value='0'></label>";
echo '<label><span>' . __('Tipo do item', 'reservaplus') . "</span><input class='form-control' name='itemtype' placeholder='Computer, Printer...'></label>";
echo '<label><span>' . __('Duração máxima em minutos', 'reservaplus') . "</span><input class='form-control' type='number' name='max_duration_minutes' min='0'></label>";
echo '<label><span>' . __('Antecedência mínima em minutos', 'reservaplus') . "</span><input class='form-control' type='number' name='min_notice_minutes' min='0'></label>";
echo '<label><span>' . __('Dias máximos de antecedência', 'reservaplus') . "</span><input class='form-control' type='number' name='max_days_ahead' min='0'></label>";
echo "<label class='reservaplus-toggle'><input type='checkbox' name='requires_approval' value='1' checked><span>" . __('Exige aprovação', 'reservaplus') . '</span></label>';
echo "<label class='reservaplus-toggle'><input type='checkbox' name='is_active' value='1' checked><span>" . __('Ativa', 'reservaplus') . '</span></label>';
echo '</div>';
echo "<div class='reservaplus-actions mt-3'>";
echo Html::submit(__('Salvar', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary']);
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('rule.php') . "'>" . __('Cancelar', 'reservaplus') . '</a>';
echo '</div>';
echo '</section>';
Html::closeForm();
echo '</div>';

Html::footer();
