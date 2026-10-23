<?php

/**
 * Module Stock (Lot 3) - Statistiques : indicateurs de la période,
 * évolution des entrées / sorties, matériels les plus distribués,
 * consommables les plus utilisés, état actuel du stock par catégorie et
 * par emplacement, articles sous le seuil d'alerte. Export CSV.
 */

use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockOrder;
use GlpiPlugin\Dsimanager\StockStats;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockStats::canView()) {
    throw new AccessDeniedHttpException();
}

$presets  = StockStats::getPeriodPresets();
$period   = isset($presets[$_GET['period'] ?? '']) ? $_GET['period'] : '12m';
[$from, $to] = StockStats::resolvePeriod($period, (string) ($_GET['date_from'] ?? ''), (string) ($_GET['date_to'] ?? ''));
$location = (int) ($_GET['location'] ?? 0);
$all_locs = Stock::getLocations(false);
if ($location > 0 && !isset($all_locs[$location])) {
    $location = 0;
}

$stats = StockStats::compute($from, $to, $location);
$state = StockStats::getStockState($location);
$T = $stats['totals'];

// --- Export CSV ---------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="statistiques_stock_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $loc_label = $location > 0 ? $all_locs[$location] : 'Tous';
    fputcsv($out, ['Période', $from . ' au ' . $to, 'Emplacement', $loc_label], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Indicateur', 'Commandes', 'Éléments'], ';');
    foreach ([StockOrder::TYPE_IN => 'Entrées', StockOrder::TYPE_OUT => 'Sorties', StockOrder::TYPE_TRANSFER => 'Transferts', StockOrder::TYPE_DISPOSAL => 'Sortis du parc'] as $t => $l) {
        fputcsv($out, [$l, $T[$t]['orders'], $T[$t]['elements']], ';');
    }
    fputcsv($out, ['Ajustements (écart net)', $T[StockOrder::TYPE_ADJUST]['orders'], $stats['adjust_delta']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Tranche', 'Entrées', 'Sorties'], ';');
    foreach ($stats['buckets'] as $b) {
        fputcsv($out, [$b['label'], $b[StockOrder::TYPE_IN], $b[StockOrder::TYPE_OUT]], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Matériels les plus distribués', 'Quantité'], ';');
    foreach ($stats['top_units'] as $k => $v) {
        fputcsv($out, [$k, $v], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Consommables les plus utilisés', 'Quantité'], ';');
    foreach ($stats['top_articles'] as $k => $v) {
        fputcsv($out, [$k, $v], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, array_merge(['État du stock - catégorie'], array_column($state['locations'], 'name'), ['Total']), ';');
    foreach ($state['categories'] as $name => $c) {
        $row = [$name];
        foreach (array_keys($state['locations']) as $lid) {
            $row[] = $c['by_location'][$lid] ?? 0;
        }
        $row[] = $c['total'];
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit();
}

// --- Affichage ----------------------------------------------------------
Html::header(StockStats::getTypeName(), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockStats::class);
Stock::showNavBar('stats');
echo StockStats::getCss();

$self = Plugin::getWebDir('dsimanager') . '/front/stockstats.php';
echo "<div class='at-stats'>";

// Filtres : une seule ligne au-dessus des graphiques (GET, pas de CSRF).
echo "<form method='get' action='$self' class='card mb-3'><div class='card-body py-2'><div class='row g-2 align-items-end'>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Période', 'dsimanager') . "</label><select name='period' class='form-select' onchange=\"document.getElementById('at-custom').style.display = this.value === 'custom' ? '' : 'none';\">";
foreach ($presets as $k => $v) {
    echo "<option value='$k'" . ($k === $period ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-4' id='at-custom'" . ($period === 'custom' ? '' : " style='display:none'") . "><div class='row g-2'>"
   . "<div class='col'><label class='form-label'>" . __('Du', 'dsimanager') . "</label><input type='date' name='date_from' class='form-control' value='$from'></div>"
   . "<div class='col'><label class='form-label'>" . __('Au', 'dsimanager') . "</label><input type='date' name='date_to' class='form-control' value='$to'></div></div></div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Emplacement', 'dsimanager') . "</label><select name='location' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach ($all_locs as $k => $v) {
    echo "<option value='$k'" . ($k === $location ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";
echo "<div class='col-auto d-flex gap-2'><button type='submit' class='btn btn-primary'><i class='ti ti-refresh'></i> " . __('Actualiser', 'dsimanager') . "</button>";
$csv = $_GET;
$csv['export'] = 'csv';
echo "<a class='btn btn-outline-success' href='$self?" . htmlspecialchars(http_build_query($csv)) . "'><i class='ti ti-file-spreadsheet'></i> CSV</a></div>";
echo "</div><div class='text-muted small mt-1'>" . sprintf(__('Du %1$s au %2$s - commandes validées uniquement ; les commandes correctives sont déduites des mouvements qu\'elles corrigent.', 'dsimanager'), Html::convDate($from), Html::convDate($to)) . "</div>";
echo "</div></form>";

// --- Indicateurs ---------------------------------------------------------
$kpi = static function (string $label, string $value, string $sub, string $icon, bool $alert = false, string $url = ''): void {
    echo "<div class='col-6 col-md-3 col-xl mb-3'>";
    echo $url !== '' ? "<a href='$url' class='text-reset text-decoration-none'>" : '';
    echo "<div class='at-kpi" . ($alert ? ' at-alert' : '') . "'><div class='at-kpi-label'><i class='$icon'></i> " . htmlspecialchars($label) . "</div>"
       . "<div class='at-kpi-value'>" . $value . "</div><div class='at-kpi-sub'>" . htmlspecialchars($sub) . "</div></div>";
    echo $url !== '' ? "</a>" : '';
    echo "</div>";
};
$history = Plugin::getWebDir('dsimanager') . '/front/stockorder.php?status=' . StockOrder::STATUS_VALIDATED . '&date_from=' . $from . '&date_to=' . $to . ($location ? '&location=' . $location : '');
$cmd = static fn(int $n) => sprintf(_n('%d commande', '%d commandes', $n, 'dsimanager'), $n);
$units_in_stock = array_sum(array_column($state['locations'], 'units'));
$articles_in_stock = array_sum(array_column($state['locations'], 'articles'));
$nb_alerts = count($state['alerts']);
$delta = $stats['adjust_delta'];

echo "<div class='row'>";
$kpi(__('Entrées', 'dsimanager'), (string) $T[StockOrder::TYPE_IN]['elements'], $cmd($T[StockOrder::TYPE_IN]['orders']), 'ti ti-arrow-bar-to-down', false, $history . '&type=' . StockOrder::TYPE_IN);
$kpi(__('Sorties', 'dsimanager'), (string) $T[StockOrder::TYPE_OUT]['elements'], $cmd($T[StockOrder::TYPE_OUT]['orders']), 'ti ti-arrow-bar-up', false, $history . '&type=' . StockOrder::TYPE_OUT);
$kpi(__('Transferts', 'dsimanager'), (string) $T[StockOrder::TYPE_TRANSFER]['elements'], $cmd($T[StockOrder::TYPE_TRANSFER]['orders']), 'ti ti-arrows-exchange', false, $history . '&type=' . StockOrder::TYPE_TRANSFER);
$kpi(__('Sortis du parc', 'dsimanager'), (string) $T[StockOrder::TYPE_DISPOSAL]['elements'], $cmd($T[StockOrder::TYPE_DISPOSAL]['orders']), 'ti ti-trash-x', false, Plugin::getWebDir('dsimanager') . '/front/stockdisposed.php?date_from=' . $from . '&date_to=' . $to);
$kpi(__('Ajustements', 'dsimanager'), (string) $T[StockOrder::TYPE_ADJUST]['orders'], sprintf(__('écart net : %s', 'dsimanager'), ($delta > 0 ? '+' : '') . $delta), 'ti ti-adjustments', false, $history . '&type=' . StockOrder::TYPE_ADJUST);
$kpi(__('En stock actuellement', 'dsimanager'), (string) ($units_in_stock + $articles_in_stock), sprintf(__('%1$d actifs, %2$d articles', 'dsimanager'), $units_in_stock, $articles_in_stock), 'ti ti-packages', false, Plugin::getWebDir('dsimanager') . '/front/stock.php' . ($location ? '?location=' . $location : ''));
$kpi(__('Sous le seuil d\'alerte', 'dsimanager'), ($nb_alerts > 0 ? "<i class='ti ti-alert-triangle'></i> " : '') . $nb_alerts, $nb_alerts > 0 ? __('à réapprovisionner', 'dsimanager') : __('aucune alerte', 'dsimanager'), 'ti ti-bell', $nb_alerts > 0);
echo "</div>";

// --- Évolution -----------------------------------------------------------
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='mb-1'>" . ($stats['by_week'] ? __('Mouvements par semaine', 'dsimanager') : __('Mouvements par mois', 'dsimanager')) . "</h3>";
echo "<div class='text-muted small mb-2'>" . __('Nombre d\'éléments entrés et sortis (1 par actif, quantité pour les articles). Survolez une barre pour la valeur exacte.', 'dsimanager') . "</div>";
echo "<div class='at-legend'><span style='--c:var(--at-s-in)'>" . __('Entrées', 'dsimanager') . "</span><span style='--c:var(--at-s-out)'>" . __('Sorties', 'dsimanager') . "</span></div>";
echo StockStats::renderMovementsChart($stats['buckets']);
echo "<details class='mt-2'><summary class='text-muted small'>" . __('Voir les données', 'dsimanager') . "</summary>";
echo "<table class='table table-sm w-auto at-data mt-2'><thead><tr><th>" . __('Tranche', 'dsimanager') . "</th><th class='text-end'>" . __('Entrées', 'dsimanager') . "</th><th class='text-end'>" . __('Sorties', 'dsimanager') . "</th></tr></thead><tbody>";
foreach ($stats['buckets'] as $b) {
    echo "<tr><td>" . htmlspecialchars($b['label']) . "</td><td class='text-end'>" . (int) $b[StockOrder::TYPE_IN] . "</td><td class='text-end'>" . (int) $b[StockOrder::TYPE_OUT] . "</td></tr>";
}
echo "</tbody></table></details>";
echo "</div></div>";

// --- Tops ------------------------------------------------------------------
echo "<div class='row'>";
echo "<div class='col-lg-6 mb-3'><div class='card h-100'><div class='card-body'>";
echo "<h3 class='mb-1'>" . __('Matériels les plus distribués', 'dsimanager') . "</h3>";
echo "<div class='text-muted small mb-3'>" . __('Actifs unitaires sortis sur la période, par type et modèle (10 premiers).', 'dsimanager') . "</div>";
echo StockStats::renderHbars($stats['top_units']);
echo "</div></div></div>";
echo "<div class='col-lg-6 mb-3'><div class='card h-100'><div class='card-body'>";
echo "<h3 class='mb-1'>" . __('Consommables les plus utilisés', 'dsimanager') . "</h3>";
echo "<div class='text-muted small mb-3'>" . __('Articles sortis sur la période, en quantité (10 premiers).', 'dsimanager') . "</div>";
echo StockStats::renderHbars($stats['top_articles']);
echo "</div></div></div>";
echo "</div>";

// --- État actuel -----------------------------------------------------------
echo "<div class='row'>";
echo "<div class='col-lg-6 mb-3'><div class='card h-100'><div class='card-body'>";
echo "<h3 class='mb-1'>" . __('État du stock par catégorie', 'dsimanager') . "</h3>";
echo "<div class='text-muted small mb-3'>" . __('Situation actuelle (indépendante de la période).', 'dsimanager') . "</div>";
$cat_values = [];
foreach ($state['categories'] as $name => $c) {
    $cat_values[$name] = $c['total'];
}
echo StockStats::renderHbars($cat_values);
echo "</div></div></div>";

echo "<div class='col-lg-6 mb-3'><div class='card h-100'><div class='card-body'>";
echo "<h3 class='mb-1'>" . __('État du stock par emplacement', 'dsimanager') . "</h3>";
echo "<div class='text-muted small mb-3'>" . __('Situation actuelle (indépendante de la période).', 'dsimanager') . "</div>";
if (!count($state['locations'])) {
    echo "<p class='text-muted'>" . __('Aucun emplacement.', 'dsimanager') . "</p>";
} else {
    $stock_url = Plugin::getWebDir('dsimanager') . '/front/stock.php?location=';
    echo "<table class='table table-sm align-middle at-data'><thead><tr><th>" . __('Emplacement', 'dsimanager') . "</th><th class='text-end'>" . __('Actifs unitaires', 'dsimanager')
       . "</th><th class='text-end'>" . __('Articles (qté)', 'dsimanager') . "</th><th class='text-end'>" . __('Alertes', 'dsimanager') . "</th></tr></thead><tbody>";
    foreach ($state['locations'] as $lid => $l) {
        echo "<tr><td><a href='$stock_url$lid'>" . htmlspecialchars($l['name']) . "</a></td><td class='text-end'>" . $l['units'] . "</td><td class='text-end'>" . $l['articles'] . "</td><td class='text-end'>"
           . ($l['alerts'] > 0 ? "<span class='badge' style='background:#d63939;color:#fff;'><i class='ti ti-alert-triangle'></i> " . $l['alerts'] . "</span>" : "<span class='text-muted'>0</span>")
           . "</td></tr>";
    }
    echo "</tbody></table>";
    // Détail catégorie x emplacement
    if (count($state['categories']) && count($state['locations']) > 1) {
        echo "<details><summary class='text-muted small'>" . __('Détail par catégorie et emplacement', 'dsimanager') . "</summary>";
        echo "<div class='table-responsive'><table class='table table-sm at-data mt-2'><thead><tr><th>" . __('Catégorie', 'dsimanager') . "</th>";
        foreach ($state['locations'] as $l) {
            echo "<th class='text-end'>" . htmlspecialchars($l['name']) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($state['categories'] as $name => $c) {
            echo "<tr><td>" . htmlspecialchars($name) . "</td>";
            foreach (array_keys($state['locations']) as $lid) {
                echo "<td class='text-end'>" . ($c['by_location'][$lid] ?? 0) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div></details>";
    }
}
echo "</div></div></div>";
echo "</div>";

// --- Alertes -----------------------------------------------------------------
echo "<div class='card mb-3" . ($nb_alerts ? " border-danger" : '') . "'><div class='card-body'>";
echo "<h3 class='mb-2'><i class='ti ti-alert-triangle'></i> " . __('Articles sous le seuil d\'alerte', 'dsimanager') . "</h3>";
if (!$nb_alerts) {
    echo "<p class='text-muted mb-0'><i class='ti ti-check'></i> " . __('Aucun article sous son seuil.', 'dsimanager') . "</p>";
} else {
    echo "<table class='table table-sm w-auto at-data'><thead><tr><th>" . __('Article', 'dsimanager') . "</th><th>" . __('Emplacement', 'dsimanager')
       . "</th><th class='text-end'>" . __('En stock', 'dsimanager') . "</th><th class='text-end'>" . __('Seuil', 'dsimanager') . "</th><th class='text-end'>" . __('Manque', 'dsimanager') . "</th></tr></thead><tbody>";
    foreach ($state['alerts'] as $a) {
        echo "<tr><td><strong>" . htmlspecialchars($a['name']) . "</strong></td><td>" . htmlspecialchars($a['location']) . "</td><td class='text-end'>" . $a['quantity']
           . "</td><td class='text-end'>" . $a['threshold'] . "</td><td class='text-end text-danger'>" . ($a['threshold'] - $a['quantity']) . "</td></tr>";
    }
    echo "</tbody></table>";
}
echo "</div></div>";

echo "</div>";
Html::footer();
