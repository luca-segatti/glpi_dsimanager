<?php

/**
 * Module Stock (Lot 2) - "Création de modèle" : modèles des bons de remise
 * (sortie) et de reprise (entrée). Logo commun, titre, corps en texte riche
 * avec placeholders, signatures, pied de page, phrases de la liste du
 * matériel. Aperçu PDF avec des données d'exemple.
 */

use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;
use GlpiPlugin\Dsimanager\StockDocument;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockConfig::canView()) {
    throw new AccessDeniedHttpException();
}
$canedit = (bool) Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
$base    = Plugin::getWebDir('dsimanager') . '/front';
$self    = $base . '/stockdoctemplate.php';

$type = (int) ($_REQUEST['type'] ?? StockDocument::TYPE_REMISE);
if (!in_array($type, [StockDocument::TYPE_REMISE, StockDocument::TYPE_REPRISE], true)) {
    $type = StockDocument::TYPE_REMISE;
}

// --- Actions POST -------------------------------------------------------
$actions = ['save_template', 'reset_template', 'save_phrases', 'upload_logo', 'delete_logo', 'save_company'];
if (count(array_intersect($actions, array_keys($_POST)))) {
    if (!$canedit) {
        throw new AccessDeniedHttpException();
    }
    if (isset($_POST['save_template'])) {
        StockDocument::saveTemplate($type, $_POST);
        Session::addMessageAfterRedirect(htmlspecialchars(__('Modèle enregistré.', 'dsimanager')));
    } elseif (isset($_POST['reset_template'])) {
        StockDocument::resetTemplate($type);
        Session::addMessageAfterRedirect(htmlspecialchars(__('Modèle remis aux valeurs par défaut.', 'dsimanager')));
    } elseif (isset($_POST['save_phrases'])) {
        StockDocument::savePhrases((array) ($_POST['phrases'] ?? []));
        Session::addMessageAfterRedirect(htmlspecialchars(__('Phrases enregistrées.', 'dsimanager')));
    } elseif (isset($_POST['upload_logo'])) {
        $err = StockDocument::saveLogo($_FILES['logo'] ?? []);
        Session::addMessageAfterRedirect(htmlspecialchars($err !== '' ? $err : __('Logo enregistré.', 'dsimanager')), false, $err !== '' ? ERROR : INFO);
    } elseif (isset($_POST['save_company'])) {
        StockDocument::saveCompanyName((string) ($_POST['company_name'] ?? ''));
        Session::addMessageAfterRedirect(htmlspecialchars(__('Nom de la société enregistré.', 'dsimanager')));
    } elseif (isset($_POST['delete_logo'])) {
        StockDocument::deleteLogo();
        Session::addMessageAfterRedirect(htmlspecialchars(__('Logo supprimé.', 'dsimanager')));
    }
    Html::redirect($self . '?type=' . $type);
}

// --- Affichage ----------------------------------------------------------
Html::header(__('Modèles de bon', 'dsimanager'), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);
Stock::showNavBar('config');

$tpl = StockDocument::getTemplate($type);
$dis = $canedit ? '' : ' disabled';

// Onglets Remise / Reprise
echo "<ul class='nav nav-tabs mb-3'>";
foreach ([StockDocument::TYPE_REMISE => __('Bon de remise (sortie)', 'dsimanager'), StockDocument::TYPE_REPRISE => __('Bon de reprise (entrée)', 'dsimanager')] as $t => $label) {
    $active = $t === $type ? ' active' : '';
    echo "<li class='nav-item'><a class='nav-link$active' href='$self?type=$t'>" . htmlspecialchars($label) . "</a></li>";
}
echo "<li class='nav-item ms-auto'><a class='nav-link' href='$base/stockconfig.php'>" . __('← Configuration', 'dsimanager') . "</a></li>";
echo "</ul>";

echo "<div class='row'>";

// Colonne gauche : modèle
echo "<div class='col-xl-8'>";
echo "<div class='card mb-3'><div class='card-body'>";
echo "<div class='d-flex align-items-center mb-3'><h3 class='mb-0'>" . __('Modèle', 'dsimanager') . "</h3>";
echo "<a class='btn btn-outline-primary ms-auto' target='_blank' href='$base/stockdoc.php?preview=$type'><i class='ti ti-file-type-pdf'></i> " . __('Aperçu PDF', 'dsimanager') . "</a></div>";
if ($canedit) {
    echo "<form method='post' action='$self'>";
    echo "<input type='hidden' name='type' value='$type'>";
}
echo "<div class='mb-3'><label class='form-label'>" . __('Titre (encadré)', 'dsimanager') . "</label>"
   . "<input type='text' name='title' class='form-control' value=\"" . htmlspecialchars((string) $tpl['title']) . "\"$dis></div>";
echo "<div class='mb-3'><label class='form-label'>" . __('Corps du texte', 'dsimanager') . "</label>";
if ($canedit) {
    Html::textarea([
        'name'            => 'body',
        'value'           => (string) $tpl['body'],
        'enable_richtext' => true,
        'enable_images'   => false,
        'rows'            => 14,
    ]);
} else {
    echo "<div class='border rounded p-2'>" . \Glpi\RichText\RichText::getSafeHtml((string) $tpl['body']) . "</div>";
}
echo "</div>";
echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Signature de gauche', 'dsimanager') . "</label>"
   . "<input type='text' name='signature_left' class='form-control' value=\"" . htmlspecialchars((string) $tpl['signature_left']) . "\"$dis>"
   . "<div class='text-muted small'>" . __('Le nom du technicien exécutant est ajouté dessous. Vide = pas de zone.', 'dsimanager') . "</div></div>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Signature de droite', 'dsimanager') . "</label>"
   . "<input type='text' name='signature_right' class='form-control' value=\"" . htmlspecialchars((string) $tpl['signature_right']) . "\"$dis>"
   . "<div class='text-muted small'>" . __('Le nom du bénéficiaire est ajouté dessous. Vide = pas de zone.', 'dsimanager') . "</div></div>";
echo "</div>";
echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Espace entre le logo et le titre (mm)', 'dsimanager') . "</label>"
   . "<input type='number' min='0' max='80' name='space_after_logo' class='form-control' value='" . (int) ($tpl['space_after_logo'] ?? 4) . "'$dis>"
   . "<div class='text-muted small'>" . __('Ignoré s\'il n\'y a pas de logo. Défaut : 4.', 'dsimanager') . "</div></div>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Espace entre le titre et le corps du texte (mm)', 'dsimanager') . "</label>"
   . "<input type='number' min='0' max='80' name='space_after_title' class='form-control' value='" . (int) ($tpl['space_after_title'] ?? 12) . "'$dis>"
   . "<div class='text-muted small'>" . __('Défaut : 12.', 'dsimanager') . "</div></div>";
echo "</div>";
echo "<div class='mb-3'><label><input type='hidden' name='show_place_date' value='0'><input type='checkbox' name='show_place_date' value='1'"
   . (!empty($tpl['show_place_date']) ? ' checked' : '') . "$dis> " . __('Afficher « Fait à …, le … » (à remplir à la main)', 'dsimanager') . "</label></div>";
echo "<div class='mb-3'><label class='form-label'>" . __('Pied de page (adresse, mentions…)', 'dsimanager') . "</label>"
   . "<textarea name='footer' class='form-control' rows='2'$dis>" . htmlspecialchars((string) $tpl['footer']) . "</textarea></div>";
if ($canedit) {
    echo "<div class='d-flex gap-2'>";
    echo "<button type='submit' name='save_template' value='1' class='btn btn-primary'>" . __('Enregistrer le modèle', 'dsimanager') . "</button>";
    echo "<button type='submit' name='reset_template' value='1' class='btn btn-outline-danger ms-auto' formnovalidate onclick=\"return confirm('"
       . htmlspecialchars(addslashes(__('Remettre ce modèle aux valeurs par défaut ?', 'dsimanager')), ENT_QUOTES) . "');\">"
       . __('Valeurs par défaut', 'dsimanager') . "</button>";
    echo "</div>";
    Html::closeForm();
}
echo "</div></div>";

// Phrases de la liste du matériel (communes aux deux modèles)
$phrases = StockDocument::getPhrases();
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Phrases de la liste du matériel', 'dsimanager') . "</h3>";
echo "<p class='text-muted'>" . __('Communes aux deux modèles. Variables : {fabricant} {modele} {serie} (IMEI / S/N / numéro) {nom} {inventaire} {type} {sim}. Pour les articles en quantité, c\'est la « Phrase sur le bon » de chaque article qui est utilisée.', 'dsimanager') . "</p>";
if ($canedit) {
    echo "<form method='post' action='$self'><input type='hidden' name='type' value='$type'>";
}
echo "<table class='table table-sm align-middle'>";
foreach (StockDocument::getPhraseLabels() as $key => $label) {
    echo "<tr><td style='width:30%'>" . htmlspecialchars($label) . "</td><td><input type='text' class='form-control form-control-sm' name='phrases["
       . htmlspecialchars($key) . "]' value=\"" . htmlspecialchars((string) ($phrases[$key] ?? '')) . "\"$dis>";
    if ($key === 'sim') {
        echo "<div class='text-muted small'>" . __('Ajouté à la place de {sim} quand une ligne est associée au téléphone. Variable : {numero}.', 'dsimanager') . "</div>";
    }
    echo "</td></tr>";
}
echo "</table>";
if ($canedit) {
    echo "<button type='submit' name='save_phrases' value='1' class='btn btn-primary'>" . __('Enregistrer les phrases', 'dsimanager') . "</button>";
    Html::closeForm();
}
echo "</div></div>";
echo "</div>";

// Colonne droite : société + logo + placeholders
echo "<div class='col-xl-4'>";
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Société', 'dsimanager') . "</h3>";
if ($canedit) {
    echo "<form method='post' action='$self'><input type='hidden' name='type' value='$type'>";
}
echo "<input type='text' name='company_name' maxlength='255' class='form-control mb-2' value=\"" . htmlspecialchars(StockDocument::getCompanyName()) . "\"$dis>";
echo "<div class='text-muted small mb-2'>" . __('Remplace la variable {societe} dans les deux modèles. Vide = pointillés à compléter à la main.', 'dsimanager') . "</div>";
if ($canedit) {
    echo "<button type='submit' name='save_company' value='1' class='btn btn-primary btn-sm'>" . __('Enregistrer', 'dsimanager') . "</button>";
    Html::closeForm();
}
echo "</div></div>";
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Logo', 'dsimanager') . "</h3>";
if (StockDocument::getLogoPath() !== '') {
    echo "<div class='border rounded p-2 mb-2 text-center bg-white'><img src='$base/stockdoc.php?logo=1&amp;t=" . time() . "' alt='logo' style='max-height:80px;max-width:100%'></div>";
} else {
    echo "<p class='text-muted'>" . __('Aucun logo.', 'dsimanager') . "</p>";
}
if ($canedit) {
    echo "<form method='post' action='$self' enctype='multipart/form-data'><input type='hidden' name='type' value='$type'>";
    echo "<input type='file' name='logo' accept='image/png,image/jpeg' class='form-control mb-2' required>";
    echo "<div class='text-muted small mb-2'>" . __('PNG ou JPEG, 2 Mo max. Commun aux deux modèles.', 'dsimanager') . "</div>";
    echo "<button type='submit' name='upload_logo' value='1' class='btn btn-primary btn-sm'>" . __('Envoyer', 'dsimanager') . "</button>";
    Html::closeForm();
    if (StockDocument::getLogoPath() !== '') {
        echo "<form method='post' action='$self' class='mt-2'><input type='hidden' name='type' value='$type'>";
        echo "<button type='submit' name='delete_logo' value='1' class='btn btn-outline-danger btn-sm'>" . __('Supprimer le logo', 'dsimanager') . "</button>";
        Html::closeForm();
    }
}
echo "</div></div>";

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Variables du corps', 'dsimanager') . "</h3>";
echo "<p class='text-muted small'>" . __('À taper telles quelles dans le corps du texte : elles sont remplacées à la création du bon. Une valeur absente laisse des pointillés à compléter à la main.', 'dsimanager') . "</p>";
echo "<table class='table table-sm'>";
foreach (StockDocument::getPlaceholders() as $ph => $desc) {
    echo "<tr><td><code>" . htmlspecialchars($ph) . "</code></td><td class='small'>" . htmlspecialchars($desc) . "</td></tr>";
}
echo "</table>";
echo "</div></div>";
echo "</div>";

echo "</div>";

Html::footer();
