<?php

/**
 * Module Stock (v2.4.0) - "Matériel sorti du parc" (sous-page de Contenu) :
 * tout ce qui est sorti définitivement par une commande "Suppression"
 * validée, avec le motif, la référence (bordereau, contrat...) et l'état
 * actuel de l'actif dans GLPI (corbeille, restauré, supprimé définitivement).
 */

use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockDisposalReason;
use GlpiPlugin\Dsimanager\StockDisposed;
use GlpiPlugin\Dsimanager\StockOrder;
use GlpiPlugin\Dsimanager\StockOrderLine;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockDisposed::canView()) {
    throw new AccessDeniedHttpException();
}

global $DB;

$flt = [
    'q'         => trim((string) ($_GET['q'] ?? '')),
    'reason'    => (int) ($_GET['reason'] ?? 0),
    'kind'      => (int) ($_GET['kind'] ?? 0),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to'   => (string) ($_GET['date_to'] ?? ''),
];
foreach (['date_from', 'date_to'] as $k) {
    if ($flt[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $flt[$k])) {
        $flt[$k] = '';
    }
}

$where = [
    'o.type'   => StockOrder::TYPE_DISPOSAL,
    'o.status' => StockOrder::STATUS_VALIDATED,
];
if ($flt['reason'] > 0) {
    $where['o.plugin_dsimanager_stockdisposalreasons_id'] = $flt['reason'];
}
if (in_array($flt['kind'], [StockOrderLine::KIND_UNIT, StockOrderLine::KIND_QTY], true)) {
    $where['l.kind'] = $flt['kind'];
}
if ($flt['date_from'] !== '') {
    $where[] = ['o.date_validation' => ['>=', $flt['date_from'] . ' 00:00:00']];
}
if ($flt['date_to'] !== '') {
    $where[] = ['o.date_validation' => ['<=', $flt['date_to'] . ' 23:59:59']];
}
if ($flt['q'] !== '') {
    $like = ['LIKE', '%' . $flt['q'] . '%'];
    $where[] = ['OR' => ['l.label' => $like, 'l.serial' => $like, 'l.otherserial' => $like, 'o.supplier_ref' => $like, 'o.comment' => $like]];
}

$criteria = [
    'SELECT'     => [
        'l.id AS lines_id', 'l.kind', 'l.itemtype', 'l.items_id', 'l.label', 'l.serial', 'l.otherserial', 'l.quantity', 'l.plugin_dsimanager_stockarticles_id AS articles_id',
        'o.id AS orders_id', 'o.date_validation', 'o.supplier_ref', 'o.comment', 'o.users_id_executor',
        'o.plugin_dsimanager_stockdisposalreasons_id AS reasons_id', 'o.stocklocations_id_from',
    ],
    'FROM'       => StockOrderLine::getTable() . ' AS l',
    'INNER JOIN' => [StockOrder::getTable() . ' AS o' => ['FKEY' => ['l' => 'plugin_dsimanager_stockorders_id', 'o' => 'id']]],
    'WHERE'      => $where,
    'ORDER'      => ['o.date_validation DESC', 'l.id ASC'],
];

$reasons = StockDisposalReason::getReasons();

/** État actuel d'un actif sorti du parc. */
$assetState = static function (array $r): array {
    if ((int) $r['kind'] !== StockOrderLine::KIND_UNIT) {
        return ['', '#6c757d', ''];
    }
    $itemtype = (string) $r['itemtype'];
    $id = (int) $r['items_id'];
    if (!class_exists($itemtype) || $id <= 0) {
        return [__('Inconnu', 'dsimanager'), '#6c757d', ''];
    }
    $asset = new $itemtype();
    if (!$asset->getFromDB($id)) {
        return [__('Supprimé définitivement', 'dsimanager'), '#495057', ''];
    }
    if (!empty($asset->fields['is_deleted'])) {
        return [__('À la corbeille', 'dsimanager'), '#d63939', $itemtype::getFormURLWithID($id)];
    }
    $loc = Stock::getUnitLocation($itemtype, $id);
    return [
        $loc > 0 ? sprintf(__('Restauré (en stock : %s)', 'dsimanager'), Stock::getLocationName($loc)) : __('Restauré dans le parc', 'dsimanager'),
        '#2fb344',
        $itemtype::getFormURLWithID($id),
    ];
};

// Commandes correctives (annulations) validées, par commande d'origine.
$corrections = [];
foreach ($DB->request([
    'SELECT' => ['id', 'type', 'corrects_id'],
    'FROM'   => StockOrder::getTable(),
    'WHERE'  => ['status' => StockOrder::STATUS_VALIDATED, 'corrects_id' => new QuerySubQuery([
        'SELECT' => 'id', 'FROM' => StockOrder::getTable(), 'WHERE' => ['type' => StockOrder::TYPE_DISPOSAL],
    ])],
]) as $c) {
    $corrections[(int) $c['corrects_id']][] = StockOrder::formatReference((int) $c['type'], (int) $c['id']);
}

// --- Export CSV ---------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="materiel_sorti_du_parc_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Commande', 'Type', 'Désignation', 'N° de série / IMEI', 'N° inventaire', 'Quantité', 'Motif', 'Référence', 'Exécutant', 'État actuel', 'Annulée par', 'Commentaire'], ';');
    foreach ($DB->request($criteria) as $r) {
        [$state] = $assetState($r);
        $kind = (int) $r['kind'] === StockOrderLine::KIND_UNIT ? Stock::getUnitTypeLabel((string) $r['itemtype'], 0) : (Stock::isBundle((int) $r['articles_id']) ? __('Kit', 'dsimanager') : __('Article', 'dsimanager'));
        fputcsv($out, [
            $r['date_validation'], StockOrder::formatReference(StockOrder::TYPE_DISPOSAL, (int) $r['orders_id']), $kind,
            $r['label'], $r['serial'], $r['otherserial'], $r['quantity'], $reasons[(int) $r['reasons_id']] ?? '', $r['supplier_ref'],
            $r['users_id_executor'] ? getUserName($r['users_id_executor']) : '', $state,
            implode(', ', $corrections[(int) $r['orders_id']] ?? []), $r['comment'],
        ], ';');
    }
    fclose($out);
    exit();
}

// --- Pagination ---------------------------------------------------------
$count_criteria = $criteria;
unset($count_criteria['ORDER']);
$count_criteria['SELECT'] = ['COUNT' => 'l.id AS cpt'];
$total = (int) ($DB->request($count_criteria)->current()['cpt'] ?? 0);
$per_page = 50;
$pages = max(1, (int) ceil($total / $per_page));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$rows = iterator_to_array($DB->request($criteria + ['START' => ($page - 1) * $per_page, 'LIMIT' => $per_page]), false);

// --- Affichage ----------------------------------------------------------
Html::header(StockDisposed::getTypeName(), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockDisposed::class);
Stock::showNavBar('disposed');

$base = Plugin::getWebDir('dsimanager') . '/front';
$self = "$base/stockdisposed.php";
if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT, READ)) {
    echo "<ul class='nav nav-tabs mb-3'><li class='nav-item'><a class='nav-link' href='$base/stock.php'>" . __('En stock', 'dsimanager') . "</a></li>"
       . "<li class='nav-item'><a class='nav-link active' href='#'><i class='ti ti-trash-x'></i> " . __('Matériel sorti du parc', 'dsimanager') . "</a></li></ul>";
}

echo "<form method='get' action='$self' class='card mb-3'><div class='card-body'><div class='row g-2 align-items-end'>";
echo "<div class='col-md-4'><label class='form-label'>" . __('Recherche (désignation, S/N, IMEI, n° inventaire, référence)', 'dsimanager') . "</label>"
   . "<input type='search' name='q' class='form-control' value=\"" . htmlspecialchars($flt['q']) . "\" autofocus></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Motif', 'dsimanager') . "</label><select name='reason' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach ($reasons as $k => $v) {
    echo "<option value='$k'" . ($flt['reason'] === $k ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Nature', 'dsimanager') . "</label><select name='kind' class='form-select'>"
   . "<option value='0'>" . __('Tout', 'dsimanager') . "</option>"
   . "<option value='" . StockOrderLine::KIND_UNIT . "'" . ($flt['kind'] === StockOrderLine::KIND_UNIT ? ' selected' : '') . ">" . __('Actifs unitaires', 'dsimanager') . "</option>"
   . "<option value='" . StockOrderLine::KIND_QTY . "'" . ($flt['kind'] === StockOrderLine::KIND_QTY ? ' selected' : '') . ">" . __('Articles (quantité)', 'dsimanager') . "</option></select></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Du', 'dsimanager') . "</label><input type='date' name='date_from' class='form-control' value='" . htmlspecialchars($flt['date_from']) . "'></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Au', 'dsimanager') . "</label><input type='date' name='date_to' class='form-control' value='" . htmlspecialchars($flt['date_to']) . "'></div>";
echo "<div class='col-12 d-flex gap-2'><button type='submit' class='btn btn-primary'><i class='ti ti-search'></i> " . __('Filtrer', 'dsimanager') . "</button>"
   . "<a class='btn btn-outline-secondary' href='$self'>" . __('Réinitialiser', 'dsimanager') . "</a>";
$csv = $_GET;
$csv['export'] = 'csv';
unset($csv['page']);
echo "<a class='btn btn-outline-success ms-auto' href='$self?" . htmlspecialchars(http_build_query($csv)) . "'><i class='ti ti-file-spreadsheet'></i> " . __('Export CSV', 'dsimanager') . "</a></div>";
echo "</div></div></form>";

echo "<div class='card'><div class='card-body'>";
echo "<p class='text-muted'>" . sprintf(_n('%d élément', '%d éléments', $total, 'dsimanager'), $total) . "</p>";
if (!count($rows)) {
    echo "<p class='text-muted'>" . __('Aucun matériel sorti du parc ne correspond.', 'dsimanager') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='table table-hover table-sm align-middle'><thead><tr>"
       . "<th>" . __('Date', 'dsimanager') . "</th><th>" . __('Commande', 'dsimanager') . "</th><th>" . __('Type', 'dsimanager') . "</th><th>" . __('Désignation', 'dsimanager')
       . "</th><th>" . __('N° de série / IMEI', 'dsimanager') . "</th><th>" . __('N° d\'inventaire', 'dsimanager') . "</th><th class='text-end'>" . __('Qté', 'dsimanager')
       . "</th><th>" . __('Motif', 'dsimanager') . "</th><th>" . __('Référence', 'dsimanager') . "</th><th>" . __('État actuel', 'dsimanager') . "</th></tr></thead><tbody>";
    foreach ($rows as $r) {
        $oid = (int) $r['orders_id'];
        $is_unit = (int) $r['kind'] === StockOrderLine::KIND_UNIT;
        [$state, $color, $url] = $assetState($r);
        $label = htmlspecialchars((string) $r['label']);
        echo "<tr>";
        echo "<td class='text-nowrap'>" . Html::convDateTime($r['date_validation']) . "</td>";
        echo "<td><a href='$base/stockorder.form.php?id=$oid'>" . StockOrder::formatReference(StockOrder::TYPE_DISPOSAL, $oid) . "</a></td>";
        echo "<td>" . htmlspecialchars($is_unit ? Stock::getUnitTypeLabel((string) $r['itemtype'], 0) : (Stock::isBundle((int) $r['articles_id']) ? __('Kit', 'dsimanager') : __('Article', 'dsimanager'))) . "</td>";
        echo "<td>" . ($url !== '' ? "<a href='$url'>$label</a>" : $label) . "</td>";
        echo "<td>" . htmlspecialchars((string) $r['serial']) . "</td>";
        echo "<td>" . htmlspecialchars((string) $r['otherserial']) . "</td>";
        echo "<td class='text-end'>" . (int) $r['quantity'] . "</td>";
        echo "<td>" . htmlspecialchars($reasons[(int) $r['reasons_id']] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars((string) $r['supplier_ref']) . "</td>";
        echo "<td>";
        if ($state !== '') {
            echo "<span class='badge' style='background:$color;color:#fff;'>" . htmlspecialchars($state) . "</span>";
        }
        if (isset($corrections[$oid])) {
            echo " <span class='small text-muted'>" . sprintf(__('annulée par %s', 'dsimanager'), htmlspecialchars(implode(', ', $corrections[$oid]))) . "</span>";
        }
        echo "</td></tr>";
    }
    echo "</tbody></table></div>";
    if ($pages > 1) {
        $params = $_GET;
        echo "<nav><ul class='pagination'>";
        for ($p = 1; $p <= $pages; $p++) {
            $params['page'] = $p;
            echo "<li class='page-item" . ($p === $page ? ' active' : '') . "'><a class='page-link' href='$self?" . htmlspecialchars(http_build_query($params)) . "'>$p</a></li>";
        }
        echo "</ul></nav>";
    }
}
echo "</div></div>";

Html::footer();
