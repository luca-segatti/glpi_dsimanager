<?php

/**
 * Module Stock - Contenu actuel du/des stock(s).
 * Deux blocs : actifs unitaires (lien vers la fiche native + historique)
 * et articles quantitatifs (quantité par emplacement, seuils d'alerte).
 * Filtres : emplacement, catégorie / type, recherche globale.
 */

use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockContent;
use GlpiPlugin\Dsimanager\StockOrder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockContent::canView()) {
    throw new AccessDeniedHttpException();
}

$flt = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'location' => (int) ($_GET['location'] ?? 0),
    'cat'      => (string) ($_GET['cat'] ?? ''), // '' | 'unit:<itemtype>' | 'unit:Phone:tablet' | 'art:<categories_id>' | 'units' | 'articles'
];

$locations   = Stock::getLocations(false);
$active_locs = Stock::getLocations(true);
$unit_types  = Stock::getUnitItemtypes();
$articles    = Stock::getArticles(true);
$levels      = Stock::getLevelsMap();
$thresholds  = Stock::getThresholdsMap();
$alerts      = Stock::getAlerts();
$history_ok  = StockOrder::canView();
$history_url = Plugin::getWebDir('dsimanager') . '/front/stockorder.php';
$self        = Plugin::getWebDir('dsimanager') . '/front/stock.php';

// Emplacements affichés en colonnes : tous les actifs, plus un inactif s'il
// contient encore du stock (pour ne rien cacher).
$columns = $active_locs;
foreach ($levels as $by_loc) {
    foreach ($by_loc as $loc_id => $qty) {
        if ($qty !== 0 && !isset($columns[$loc_id]) && isset($locations[$loc_id])) {
            $columns[$loc_id] = $locations[$loc_id] . ' ' . __('(inactif)', 'dsimanager');
        }
    }
}
if ($flt['location'] > 0) {
    $columns = isset($locations[$flt['location']]) ? [$flt['location'] => $locations[$flt['location']]] : [];
}

$show_units    = $flt['cat'] === '' || $flt['cat'] === 'units' || str_starts_with($flt['cat'], 'unit:');
$show_articles = $flt['cat'] === '' || $flt['cat'] === 'articles' || str_starts_with($flt['cat'], 'art:');
$show_kits     = $flt['cat'] === '' || $flt['cat'] === 'kits';

Html::header(__('Contenu du stock', 'dsimanager'), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockContent::class);
Stock::showNavBar('content');
// Sous-pages de "Contenu" : en stock / sorti du parc
if (Stock::canViewDisposed()) {
    echo "<ul class='nav nav-tabs mb-3'><li class='nav-item'><a class='nav-link active' href='#'>" . __('En stock', 'dsimanager') . "</a></li>"
       . "<li class='nav-item'><a class='nav-link' href='" . Plugin::getWebDir('dsimanager') . "/front/stockdisposed.php'><i class='ti ti-trash-x'></i> " . __('Matériel sorti du parc', 'dsimanager') . "</a></li></ul>";
}

// --- Alertes de seuil --------------------------------------------------
if (count($alerts)) {
    echo "<div class='alert alert-danger'><h4 class='alert-title'><i class='ti ti-alert-triangle'></i> "
       . sprintf(_n('%d article sous son seuil d\'alerte', '%d articles sous leur seuil d\'alerte', count($alerts), 'dsimanager'), count($alerts)) . "</h4><ul class='mb-0'>";
    foreach ($alerts as $a) {
        echo "<li><strong>" . htmlspecialchars($a['name']) . "</strong> — " . htmlspecialchars($a['location']) . " : "
           . sprintf(__('%1$d en stock (seuil : %2$d)', 'dsimanager'), $a['quantity'], $a['threshold']) . "</li>";
    }
    echo "</ul></div>";
}

// --- Filtres -----------------------------------------------------------
echo "<form method='get' action='$self' class='card mb-3'><div class='card-body'><div class='row g-2 align-items-end'>";
echo "<div class='col-md-5'><label class='form-label'>" . __('Recherche globale (nom, S/N, IMEI, n° inventaire, article)', 'dsimanager') . "</label>"
   . "<input type='search' name='q' class='form-control' value=\"" . htmlspecialchars($flt['q']) . "\" autofocus></div>";

echo "<div class='col-md-3'><label class='form-label'>" . __('Emplacement', 'dsimanager') . "</label><select name='location' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach ($locations as $k => $v) {
    echo "<option value='$k'" . ($flt['location'] === $k ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";

echo "<div class='col-md-3'><label class='form-label'>" . __('Catégorie', 'dsimanager') . "</label><select name='cat' class='form-select'>";
echo "<option value=''>" . __('Toutes', 'dsimanager') . "</option>";
echo "<optgroup label=\"" . htmlspecialchars(__('Actifs unitaires', 'dsimanager')) . "\">";
echo "<option value='units'" . ($flt['cat'] === 'units' ? ' selected' : '') . ">" . __('Tous les actifs unitaires', 'dsimanager') . "</option>";
foreach ($unit_types as $it => $label) {
    if ($it === 'Phone') {
        foreach (['unit:Phone:phone' => __('Téléphones', 'dsimanager'), 'unit:Phone:tablet' => __('Tablettes', 'dsimanager')] as $v => $l) {
            echo "<option value='$v'" . ($flt['cat'] === $v ? ' selected' : '') . ">" . htmlspecialchars($l) . "</option>";
        }
        continue;
    }
    $v = 'unit:' . $it;
    echo "<option value=\"" . htmlspecialchars($v) . "\"" . ($flt['cat'] === $v ? ' selected' : '') . ">" . htmlspecialchars($label) . "</option>";
}
echo "</optgroup><optgroup label=\"" . htmlspecialchars(__('Articles (quantité)', 'dsimanager')) . "\">";
echo "<option value='articles'" . ($flt['cat'] === 'articles' ? ' selected' : '') . ">" . __('Tous les articles', 'dsimanager') . "</option>";
$cats = [];
foreach ($articles as $a) {
    if ($a['categories_id'] > 0) {
        $cats[$a['categories_id']] = $a['category'];
    }
}
asort($cats);
if (count(array_filter($articles, static fn($a) => $a['is_bundle']))) {
    echo "<option value='kits'" . ($flt['cat'] === 'kits' ? ' selected' : '') . ">" . __('Kits', 'dsimanager') . "</option>";
}
foreach ($cats as $cid => $cname) {
    $v = 'art:' . $cid;
    echo "<option value='$v'" . ($flt['cat'] === $v ? ' selected' : '') . ">" . htmlspecialchars($cname) . "</option>";
}
echo "</optgroup></select></div>";

echo "<div class='col-md-1 d-flex gap-1'><button type='submit' class='btn btn-primary' title=\"" . __('Filtrer', 'dsimanager') . "\"><i class='ti ti-search'></i></button>"
   . "<a class='btn btn-outline-secondary' href='$self' title=\"" . __('Réinitialiser', 'dsimanager') . "\"><i class='ti ti-x'></i></a></div>";
echo "</div></div></form>";

// --- Actifs unitaires --------------------------------------------------
if ($show_units) {
    $type_filter = '';
    if (str_starts_with($flt['cat'], 'unit:')) {
        $type_filter = substr($flt['cat'], 5); // "Computer" ou "Phone:tablet"
    }
    $units = Stock::getUnitsInStock($flt['location'], $type_filter, $flt['q']);

    // Totaux par type et emplacement (vue synthétique).
    $totals = [];
    foreach ($units as $u) {
        $totals[$u['type_label']][$u['stocklocations_id']] = ($totals[$u['type_label']][$u['stocklocations_id']] ?? 0) + 1;
    }

    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<h3><i class='ti ti-device-mobile'></i> " . __('Actifs unitaires', 'dsimanager') . " <span class='badge' style='background:#6c757d;color:#fff;'>" . count($units) . "</span></h3>";

    if (count($totals) && count($columns)) {
        echo "<div class='table-responsive mb-3'><table class='table table-sm table-bordered w-auto'><thead><tr><th>" . __('Type', 'dsimanager') . "</th>";
        foreach ($columns as $lname) {
            echo "<th class='text-end'>" . htmlspecialchars($lname) . "</th>";
        }
        echo "<th class='text-end'>" . __('Total', 'dsimanager') . "</th></tr></thead><tbody>";
        ksort($totals);
        foreach ($totals as $tl => $by_loc) {
            echo "<tr><td>" . htmlspecialchars($tl) . "</td>";
            $sum = 0;
            foreach (array_keys($columns) as $lid) {
                $n = $by_loc[$lid] ?? 0;
                $sum += $n;
                echo "<td class='text-end'>" . ($n ?: "<span class='text-muted'>0</span>") . "</td>";
            }
            echo "<td class='text-end'><strong>$sum</strong></td></tr>";
        }
        echo "</tbody></table></div>";
    }

    if (!count($units)) {
        echo "<p class='text-muted'>" . __('Aucun actif unitaire ne correspond.', 'dsimanager') . "</p>";
    } else {
        echo "<div class='table-responsive'><table class='table table-hover table-sm align-middle'><thead><tr>"
           . "<th>" . __('Type', 'dsimanager') . "</th><th>" . __('Nom', 'dsimanager') . "</th><th>" . __('Fabricant', 'dsimanager')
           . "</th><th>" . __('N° de série / IMEI / ligne', 'dsimanager') . "</th><th>" . __('N° d\'inventaire', 'dsimanager')
           . "</th><th>" . __('Emplacement', 'dsimanager') . "</th><th>" . __('En stock depuis', 'dsimanager') . "</th><th></th></tr></thead><tbody>";
        foreach ($units as $u) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($u['type_label']) . "</td>";
            echo "<td>" . Stock::getAssetLink($u['itemtype'], $u['items_id'], (string) $u['name']) . "</td>";
            echo "<td>" . htmlspecialchars((string) $u['manufacturer']) . "</td>";
            echo "<td>" . htmlspecialchars((string) $u['identifier']) . "</td>";
            echo "<td>" . htmlspecialchars((string) $u['otherserial']) . "</td>";
            echo "<td>" . htmlspecialchars($locations[$u['stocklocations_id']] ?? '') . "</td>";
            echo "<td>" . ($u['date_in'] ? Html::convDate(substr((string) $u['date_in'], 0, 10)) : '') . "</td>";
            echo "<td class='text-nowrap'>";
            if ($history_ok) {
                echo "<a class='btn btn-sm btn-ghost-secondary' title=\"" . __('Historique de cet actif', 'dsimanager') . "\" href='$history_url?itemtype="
                   . urlencode($u['itemtype']) . "&items_id=" . $u['items_id'] . "'><i class='ti ti-history'></i></a>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</div></div>";
}

// --- Articles quantitatifs ---------------------------------------------
if ($show_articles) {
    $q = mb_strtolower($flt['q']);
    $cat_filter = str_starts_with($flt['cat'], 'art:') ? (int) substr($flt['cat'], 4) : 0;

    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<h3><i class='ti ti-box'></i> " . __('Articles gérés en quantité', 'dsimanager') . "</h3>";

    $rows = [];
    foreach ($articles as $aid => $a) {
        if ($a['is_bundle']) {
            continue; // affichés dans le bloc "Kits"
        }
        if ($cat_filter > 0 && $a['categories_id'] !== $cat_filter) {
            continue;
        }
        if ($q !== '' && !str_contains(mb_strtolower($a['name'] . ' ' . $a['category']), $q)) {
            continue;
        }
        $rows[$aid] = $a;
    }

    if (!count($rows)) {
        echo "<p class='text-muted'>" . __('Aucun article ne correspond.', 'dsimanager') . "</p>";
    } else {
        echo "<div class='table-responsive'><table class='table table-hover table-sm align-middle'><thead><tr>"
           . "<th>" . __('Article', 'dsimanager') . "</th><th>" . __('Catégorie', 'dsimanager') . "</th>";
        foreach ($columns as $lname) {
            echo "<th class='text-end'>" . htmlspecialchars($lname) . "</th>";
        }
        if (count($columns) > 1) {
            echo "<th class='text-end'>" . __('Total', 'dsimanager') . "</th>";
        }
        echo "<th></th></tr></thead><tbody>";
        foreach ($rows as $aid => $a) {
            echo "<tr><td>" . htmlspecialchars($a['name']) . "</td><td>" . htmlspecialchars($a['category']) . "</td>";
            $sum = 0;
            foreach (array_keys($columns) as $lid) {
                $qty = $levels[$aid][$lid] ?? 0;
                $sum += $qty;
                $thr = $thresholds[$aid][$lid] ?? 0;
                $low = $thr > 0 && $qty < $thr;
                $title = $thr > 0 ? sprintf(__('Seuil : %d', 'dsimanager'), $thr) : '';
                echo "<td class='text-end' title=\"" . htmlspecialchars($title) . "\">"
                   . ($low ? "<span class='badge' style='background:#d63939;color:#fff;'><i class='ti ti-alert-triangle'></i> $qty</span>" : ($qty ?: "<span class='text-muted'>0</span>"))
                   . "</td>";
            }
            if (count($columns) > 1) {
                echo "<td class='text-end'><strong>$sum</strong></td>";
            }
            echo "<td class='text-nowrap'>";
            if ($history_ok) {
                echo "<a class='btn btn-sm btn-ghost-secondary' title=\"" . __('Historique de cet article', 'dsimanager') . "\" href='$history_url?article=$aid'><i class='ti ti-history'></i></a>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</div></div>";
}

// --- Kits (disponibilité calculée depuis les composants) ----------------
if ($show_kits) {
    $q = mb_strtolower($flt['q']);
    $kits = array_filter($articles, static fn($a) => $a['is_bundle']);
    if ($q !== '') {
        $kits = array_filter($kits, static fn($a, $aid) => str_contains(mb_strtolower($a['name'] . ' ' . Stock::getBundleSummary($aid)), $q), ARRAY_FILTER_USE_BOTH);
    }
    if (count($kits) || $flt['cat'] === 'kits') {
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3><i class='ti ti-packages'></i> " . __('Kits', 'dsimanager') . "</h3>";
        echo "<p class='text-muted small'>" . __('Nombre de kits constituables avec le stock actuel des composants. Sortir un kit décompte tous ses composants en une fois.', 'dsimanager') . "</p>";
        if (!count($kits)) {
            echo "<p class='text-muted'>" . __('Aucun kit ne correspond.', 'dsimanager') . "</p>";
        } else {
            echo "<div class='table-responsive'><table class='table table-hover table-sm align-middle'><thead><tr><th>" . __('Kit', 'dsimanager') . "</th><th>" . __('Composition', 'dsimanager') . "</th>";
            foreach ($columns as $lname) {
                echo "<th class='text-end'>" . htmlspecialchars($lname) . "</th>";
            }
            echo "<th></th></tr></thead><tbody>";
            foreach ($kits as $aid => $a) {
                echo "<tr><td>" . htmlspecialchars($a['name']) . "</td><td class='small text-muted'>" . htmlspecialchars(Stock::getBundleSummary($aid) ?: __('(aucun composant)', 'dsimanager')) . "</td>";
                foreach (array_keys($columns) as $lid) {
                    $n = Stock::getBundleAvailability($aid, $lid, $levels);
                    echo "<td class='text-end'>" . ($n ?: "<span class='text-muted'>0</span>") . "</td>";
                }
                echo "<td class='text-nowrap'>";
                if ($history_ok) {
                    echo "<a class='btn btn-sm btn-ghost-secondary' title=\"" . __('Historique de ce kit', 'dsimanager') . "\" href='$history_url?article=$aid'><i class='ti ti-history'></i></a>";
                }
                echo "</td></tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div>";
    }
}

Html::footer();
