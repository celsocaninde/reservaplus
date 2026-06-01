<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Profile;

include('../../../inc/includes.php');

Session::checkRight('profile', UPDATE);

$profileId = (int) ($_POST['profiles_id'] ?? 0);
if ($profileId <= 0) {
    Session::addMessageAfterRedirect(__('Perfil não encontrado.', 'reservaplus'), false, ERROR);
    Html::back();
}

Profile::saveRightsForProfile($profileId, (array) ($_POST['plugin_reservaplus_rights'] ?? []));
Session::addMessageAfterRedirect(__('Permissões do Reserva Plus atualizadas com sucesso.', 'reservaplus'));
Html::redirect('/front/profile.form.php?id=' . $profileId);
