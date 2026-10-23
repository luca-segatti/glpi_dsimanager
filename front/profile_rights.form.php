<?php

use GlpiPlugin\Dsimanager\Profile as DsimanagerProfile;

include('../../../inc/includes.php');

Session::checkRight('profile', UPDATE);

if (isset($_POST['_dsimanager_rights_submitted'], $_POST['profiles_id'])) {
    // Pas de Session::checkCSRF() manuel ici : GLPI 11 le vérifie déjà
    // automatiquement au niveau du noyau pour toute requête POST (voir
    // Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener), à
    // condition que Html::closeForm() ait posé le jeton - ce qui est le
    // cas dans src/Profile.php. Correction du 03/07/2026 : le vrai
    // problème initial (02/07/2026) n'était pas une désynchronisation
    // AJAX comme supposé à l'époque, mais un double contrôle CSRF (cet
    // appel manuel re-vérifiait un jeton déjà consommé par le contrôleur
    // automatique).
    DsimanagerProfile::saveForProfile((int) $_POST['profiles_id'], $_POST);
    Html::back();
}

Html::back();
