<?php

/**
 * Module Stock - Configuration : listes (emplacements, articles,
 * catégories), réglages (statuts, localisations, groupe tablette, types
 * gérés à l'unité), seuils d'alerte par article et emplacement, et outils
 * de contrôle de cohérence.
 */

use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockArticle;
use GlpiPlugin\Dsimanager\StockArticleCategory;
use GlpiPlugin\Dsimanager\StockConfig;
use GlpiPlugin\Dsimanager\StockLocation;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockConfig::canView()) {
    throw new AccessDeniedHttpException();
}
$canedit = (bool) Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
$self    = Plugin::getWebDir('dsimanager') . '/front/stockconfig.php';
$base    = Plugin::getWebDir('dsimanager') . '/front';

global $DB;

// --- Actions POST -------------------------------------------------------
if (isset($_POST['save_settings']) || isset($_POST['save_thresholds']) || isset($_POST['apply_recompute'])) {
    if (!$canedit) {
        throw new AccessDeniedHttpException();
    }
    if (isset($_POST['save_settings'])) {
        Stock::saveConfig([
            'state_in'       => (int) ($_POST['state_in'] ?? 0),
            'state_out'      => (int) ($_POST['state_out'] ?? 0),
            'state_disposed' => (int) ($_POST['state_disposed'] ?? 0),
            'location_out'   => (int) ($_POST['location_out'] ?? 0),
            'tablet_group'   => (int) ($_POST['tablet_group'] ?? 0),
            'unit_itemtypes' => array_keys(array_filter((array) ($_POST['unit_itemtypes'] ?? []))),
        ]);
        Session::addMessageAfterRedirect(htmlspecialchars(__('Réglages enregistrés.', 'dsimanager')));
    }
    if (isset($_POST['save_thresholds']) && is_array($_POST['thr'] ?? null)) {
        foreach ($_POST['thr'] as $articles_id => $by_loc) {
            if (!is_array($by_loc)) {
                continue;
            }
            foreach ($by_loc as $loc_id => $value) {
                Stock::saveThreshold((int) $articles_id, (int) $loc_id, (int) $value);
            }
        }
        Session::addMessageAfterRedirect(htmlspecialchars(__('Seuils enregistrés.', 'dsimanager')));
    }
    if (isset($_POST['apply_recompute'])) {
        $DB->beginTransaction();
        try {
            $diffs = Stock::recomputeLevels(true);
            $DB->commit();
            Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('%d niveau(x) de stock corrigé(s) à partir de l\'historique.', 'dsimanager'), count($diffs))));
        } catch (Throwable $e) {
            $DB->rollBack();
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }
    }
    Html::back();
}

// --- Affichage ----------------------------------------------------------
Html::header(__('Configuration du stock', 'dsimanager'), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);
Stock::showNavBar('config');

$cfg = Stock::getConfig();

// 1. Listes
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Listes', 'dsimanager') . "</h3><div class='d-flex flex-wrap gap-2'>";
foreach ([
    [StockLocation::class, 'stocklocation', 'ti ti-building-warehouse'],
    [StockArticle::class, 'stockarticle', 'ti ti-box'],
    [StockArticleCategory::class, 'stockarticlecategory', 'ti ti-category'],
] as [$class, $page, $icon]) {
    echo "<a class='btn btn-outline-primary' href='$base/$page.php'><i class='$icon'></i>&nbsp;" . htmlspecialchars($class::getTypeName(2)) . "</a>";
}
echo "<a class='btn btn-outline-primary' href='$base/stockdisposalreason.php'><i class='ti ti-trash-x'></i>&nbsp;" . __('Motifs de sortie du parc', 'dsimanager') . "</a>";
echo "<a class='btn btn-outline-primary' href='$base/stockkit.php'><i class='ti ti-briefcase'></i>&nbsp;" . __('Profils de dotation', 'dsimanager') . "</a>";
echo "<a class='btn btn-outline-primary' href='$base/stockdoctemplate.php'><i class='ti ti-file-text'></i>&nbsp;" . __('Modèles de bon (remise / reprise)', 'dsimanager') . "</a>";
echo "</div></div></div>";

// 2. Réglages
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Réglages', 'dsimanager') . "</h3>";
if ($canedit) {
    echo "<form method='post' action='$self'>";
}
echo "<table class='tab_cadre_fixe'>";

$dd = static function (string $itemtype, string $name, int $value) use ($canedit): void {
    if ($canedit) {
        $itemtype::dropdown(['name' => $name, 'value' => $value, 'entity' => 0, 'entity_sons' => true]);
    } else {
        echo $value > 0 ? htmlspecialchars(Dropdown::getDropdownName($itemtype::getTable(), $value)) : '-';
    }
};

echo "<tr class='tab_bg_1'><td style='width:35%'>" . __('Statut des actifs entrés en stock', 'dsimanager') . "</td><td>";
$dd(State::class, 'state_in', $cfg['state_in']);
echo "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Statut des actifs sortis du stock', 'dsimanager') . "</td><td>";
$dd(State::class, 'state_out', $cfg['state_out']);
echo "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Statut des actifs sortis du parc (suppression)', 'dsimanager') . "</td><td>";
$dd(State::class, 'state_disposed', $cfg['state_disposed']);
echo "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Localisation des actifs sortis du stock', 'dsimanager')
   . "<div class='text-muted small'>" . __('Vide : la localisation de l\'actif n\'est pas modifiée à la sortie.', 'dsimanager') . "</div></td><td>";
$dd(Location::class, 'location_out', $cfg['location_out']);
echo "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Groupe identifiant les tablettes', 'dsimanager')
   . "<div class='text-muted small'>" . __('Les téléphones de ce groupe sont affichés comme « Tablette ».', 'dsimanager') . "</div></td><td>";
$dd(Group::class, 'tablet_group', $cfg['tablet_group']);
echo "</td></tr>";

// Types gérés à l'unité : types natifs courants + actifs personnalisés GLPI 11.
$candidates = ['Phone', 'Computer', 'Monitor', 'Line', 'Peripheral', 'Printer', 'NetworkEquipment'];
try {
    if (class_exists(\Glpi\Asset\AssetDefinitionManager::class)) {
        $candidates = array_merge($candidates, \Glpi\Asset\AssetDefinitionManager::getInstance()->getCustomObjectClassNames(true));
    }
} catch (Throwable $e) {
    // Pas d'actifs personnalisés exploitables : on garde les types natifs.
}
echo "<tr class='tab_bg_1'><td>" . __('Types d\'actifs gérés à l\'unité', 'dsimanager')
   . "<div class='text-muted small'>" . __('Identifiés par leur numéro de série (numéro d\'appel pour les lignes téléphoniques).', 'dsimanager') . "</div></td><td>";
foreach (array_unique($candidates) as $it) {
    if (!Stock::isUsableUnitItemtype($it)) {
        continue;
    }
    $checked = in_array($it, $cfg['unit_itemtypes'], true) ? ' checked' : '';
    $disabled = $canedit ? '' : ' disabled';
    echo "<label class='me-3'><input type='checkbox' name='unit_itemtypes[" . htmlspecialchars($it) . "]' value='1'$checked$disabled> "
       . htmlspecialchars($it::getTypeName(1)) . "</label>";
}
echo "</td></tr>";

if ($canedit) {
    echo "<tr class='tab_bg_2'><td colspan='2' class='center'><button type='submit' name='save_settings' value='1' class='btn btn-primary'>" . __('Enregistrer les réglages', 'dsimanager') . "</button></td></tr>";
}
echo "</table>";
if ($canedit) {
    Html::closeForm();
}
echo "</div></div>";

// 3. Seuils d'alerte (un seul formulaire englobant tout le tableau : piège n°14)
$articles  = array_filter(Stock::getArticles(true), static fn($a) => !$a['is_bundle']); // pas de seuil sur un kit (stock calculé)
$locations = Stock::getLocations(true);
$thr       = Stock::getThresholdsMap();
$levels    = Stock::getLevelsMap();

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Seuils d\'alerte (par article et par emplacement)', 'dsimanager') . "</h3>";
echo "<p class='text-muted'>" . __('Alerte quand la quantité passe sous le seuil. 0 ou vide = pas d\'alerte.', 'dsimanager') . "</p>";
if (!count($articles) || !count($locations)) {
    echo "<p class='text-muted'>" . __('Créez d\'abord des articles et des emplacements.', 'dsimanager') . "</p>";
} else {
    if ($canedit) {
        echo "<form method='post' action='$self'>";
    }
    echo "<div class='table-responsive'><table class='table table-sm table-bordered align-middle w-auto'><thead><tr><th>" . __('Article', 'dsimanager') . "</th>";
    foreach ($locations as $lname) {
        echo "<th>" . htmlspecialchars($lname) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($articles as $aid => $a) {
        echo "<tr><td>" . htmlspecialchars($a['name']) . ($a['category'] !== '' ? " <span class='text-muted small'>(" . htmlspecialchars($a['category']) . ")</span>" : '') . "</td>";
        foreach (array_keys($locations) as $lid) {
            $v   = $thr[$aid][$lid] ?? 0;
            $qty = $levels[$aid][$lid] ?? 0;
            echo "<td><div class='d-flex align-items-center gap-2'>";
            echo "<input type='number' min='0' name='thr[$aid][$lid]' value='" . ($v ?: '') . "' class='form-control form-control-sm' style='width:80px'" . ($canedit ? '' : ' disabled') . ">";
            echo "<span class='text-muted small' title=\"" . __('Stock actuel', 'dsimanager') . "\">(" . $qty . ")</span>";
            echo "</div></td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div>";
    if ($canedit) {
        echo "<button type='submit' name='save_thresholds' value='1' class='btn btn-primary'>" . __('Enregistrer les seuils', 'dsimanager') . "</button>";
        Html::closeForm();
    }
}
echo "</div></div>";

// 3 bis. Formulaires natifs GLPI 11 (Lot 4)
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Formulaires natifs GLPI', 'dsimanager') . "</h3>";
$dest_ok = class_exists(\Glpi\Form\Destination\FormDestinationManager::class)
    && method_exists(\Glpi\Form\Destination\FormDestinationManager::class, 'registerPluginDestinationType');
if ($dest_ok) {
    echo "<p class='text-success mb-2'><i class='ti ti-check'></i> " . __('La destination « Commande de stock (brouillon) » est disponible dans l\'onglet Destinations des formulaires (Administration > Formulaires).', 'dsimanager') . "</p>";
    $used = [];
    foreach ($DB->request([
        'SELECT'     => ['f.id', 'f.name'],
        'FROM'       => 'glpi_forms_destinations_formdestinations AS d',
        'INNER JOIN' => ['glpi_forms_forms AS f' => ['FKEY' => ['d' => 'forms_forms_id', 'f' => 'id']]],
        'WHERE'      => ['d.itemtype' => \GlpiPlugin\Dsimanager\FormDestinationStockOrder::class],
    ]) as $row) {
        $used[(int) $row['id']] = $row['name'];
    }
    if (count($used)) {
        echo "<p class='mb-0'>" . __('Formulaires qui créent un brouillon de stock :', 'dsimanager') . " ";
        $links = [];
        foreach ($used as $fid => $fname) {
            $links[] = "<a href='" . \Glpi\Form\Form::getFormURLWithID($fid) . "'>" . htmlspecialchars((string) $fname) . "</a>";
        }
        echo implode(', ', $links) . "</p>";
    } else {
        echo "<p class='text-muted mb-0'>" . __('Aucun formulaire ne l\'utilise pour l\'instant.', 'dsimanager') . "</p>";
    }
} else {
    echo "<p class='text-warning mb-0'><i class='ti ti-alert-triangle'></i> " . __('Cette version de GLPI ne permet pas aux plugins d\'ajouter une destination de formulaire. Utilisez l\'onglet Stock du ticket créé par le formulaire.', 'dsimanager') . "</p>";
}
echo "</div></div>";

// 4. Contrôles de cohérence
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Contrôles de cohérence', 'dsimanager') . "</h3>";

// 4.a Niveaux quantitatifs vs historique
$diffs = Stock::recomputeLevels(false);
$all_articles = Stock::getArticles(false);
$all_locs = Stock::getLocations(false);
echo "<h4>" . __('Quantités en stock comparées à l\'historique', 'dsimanager') . "</h4>";
if (!count($diffs)) {
    echo "<p class='text-success'><i class='ti ti-check'></i> " . __('Aucun écart : les quantités correspondent exactement à l\'historique des mouvements validés.', 'dsimanager') . "</p>";
} else {
    echo "<table class='table table-sm w-auto'><thead><tr><th>" . __('Article', 'dsimanager') . "</th><th>" . __('Emplacement', 'dsimanager')
       . "</th><th>" . __('Affiché', 'dsimanager') . "</th><th>" . __('Selon l\'historique', 'dsimanager') . "</th></tr></thead><tbody>";
    foreach ($diffs as $d) {
        echo "<tr><td>" . htmlspecialchars($all_articles[$d['articles_id']]['name'] ?? ('#' . $d['articles_id'])) . "</td><td>"
           . htmlspecialchars($all_locs[$d['stocklocations_id']] ?? ('#' . $d['stocklocations_id'])) . "</td><td>" . $d['cached'] . "</td><td><strong>" . $d['computed'] . "</strong></td></tr>";
    }
    echo "</tbody></table>";
    if ($canedit) {
        echo "<form method='post' action='$self' onsubmit=\"return confirm('" . htmlspecialchars(addslashes(__('Remplacer les quantités affichées par celles calculées depuis l\'historique ?', 'dsimanager')), ENT_QUOTES) . "');\">";
        echo "<button type='submit' name='apply_recompute' value='1' class='btn btn-warning'>" . __('Corriger depuis l\'historique', 'dsimanager') . "</button>";
        Html::closeForm();
    }
}

// 4.b Actifs unitaires : modifiés hors du module ?
echo "<h4 class='mt-3'>" . __('Actifs en stock modifiés en dehors du module', 'dsimanager') . "</h4>";
$issues = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_dsimanager_stockunits']) as $u) {
    $itemtype = (string) $u['itemtype'];
    $label = $itemtype . ' #' . (int) $u['items_id'];
    if (!class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
        $issues[] = [$label, __('Type d\'actif inconnu', 'dsimanager')];
        continue;
    }
    $asset = new $itemtype();
    if (!$asset->getFromDB((int) $u['items_id'])) {
        $issues[] = [$label, __('Actif supprimé de l\'inventaire', 'dsimanager')];
        continue;
    }
    $link = Stock::getAssetLink($itemtype, (int) $u['items_id'], (string) $asset->fields['name']);
    if (!empty($asset->fields['is_deleted'])) {
        $issues[] = [$link, __('Actif mis à la corbeille', 'dsimanager'), true];
    }
    if ($cfg['state_in'] > 0 && isset($asset->fields['states_id']) && (int) $asset->fields['states_id'] !== $cfg['state_in']) {
        $issues[] = [$link, __('Statut différent de « En stock »', 'dsimanager'), true];
    }
    $expected_loc = Stock::getNativeLocationId((int) $u['plugin_dsimanager_stocklocations_id']);
    if ($expected_loc > 0 && isset($asset->fields['locations_id']) && (int) $asset->fields['locations_id'] !== $expected_loc) {
        $issues[] = [$link, sprintf(__('Localisation différente de celle de l\'emplacement « %s »', 'dsimanager'), Stock::getLocationName((int) $u['plugin_dsimanager_stocklocations_id'])), true];
    }
}
if (!count($issues)) {
    echo "<p class='text-success'><i class='ti ti-check'></i> " . __('Aucune incohérence détectée.', 'dsimanager') . "</p>";
} else {
    echo "<p class='text-muted'>" . __('Ces actifs sont en stock selon le module, mais leur fiche a été modifiée directement dans l\'inventaire. Corrigez la fiche, ou faites une sortie si l\'actif n\'est réellement plus en stock.', 'dsimanager') . "</p>";
    echo "<ul>";
    foreach ($issues as $i) {
        echo "<li>" . (!empty($i[2]) ? $i[0] : htmlspecialchars($i[0])) . " — " . htmlspecialchars($i[1]) . "</li>";
    }
    echo "</ul>";
}
echo "</div></div>";

Html::footer();
