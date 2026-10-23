<?php

/**
 * Module Stock - Historique des mouvements.
 * Liste des commandes cliquables, filtres (période, type, statut,
 * emplacement, bénéficiaire, exécutant, actif, article) et recherche libre
 * qui porte aussi sur le CONTENU des commandes (désignation, S/N, IMEI,
 * n° d'inventaire des lignes), même s'il n'est pas affiché dans la liste.
 * Export CSV des résultats filtrés.
 */

use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockOrder;
use GlpiPlugin\Dsimanager\StockOrderLine;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

include('../../../inc/includes.php');

if (!StockOrder::canView()) {
    throw new AccessDeniedHttpException();
}
$full_history = (bool) Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY, READ);

global $DB;

// --- Filtres -----------------------------------------------------------
$flt = [
    'q'          => trim((string) ($_GET['q'] ?? '')),
    'date_from'  => (string) ($_GET['date_from'] ?? ''),
    'date_to'    => (string) ($_GET['date_to'] ?? ''),
    'type'       => (int) ($_GET['type'] ?? 0),
    'status'     => isset($_GET['status']) ? (int) $_GET['status'] : -1, // -1 = brouillons + validées
    'location'   => (int) ($_GET['location'] ?? 0),
    'beneficiary' => (int) ($_GET['beneficiary'] ?? 0),
    'executor'   => (int) ($_GET['executor'] ?? 0),
    'itemtype'   => (string) ($_GET['itemtype'] ?? ''),
    'items_id'   => (int) ($_GET['items_id'] ?? 0),
    'article'    => (int) ($_GET['article'] ?? 0),
];
foreach (['date_from', 'date_to'] as $k) {
    if ($flt[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $flt[$k])) {
        $flt[$k] = '';
    }
}

$table = StockOrder::getTable();
$where = [];

// Sans le droit Historique, on ne voit que les types sur lesquels on a la lecture.
$visible_types = [];
foreach (array_keys(StockOrder::getTypes()) as $t) {
    if ($full_history || StockOrder::canDoOnType($t, READ)) {
        $visible_types[] = $t;
    }
}
$where["$table.type"] = $flt['type'] > 0 && in_array($flt['type'], $visible_types, true) ? $flt['type'] : $visible_types;

if ($flt['status'] === -1) {
    $where["$table.status"] = [StockOrder::STATUS_DRAFT, StockOrder::STATUS_VALIDATED];
} elseif ($flt['status'] > 0) {
    $where["$table.status"] = $flt['status'];
}
if ($flt['date_from'] !== '') {
    $where[] = ["$table.date_order" => ['>=', $flt['date_from']]];
}
if ($flt['date_to'] !== '') {
    $where[] = ["$table.date_order" => ['<=', $flt['date_to']]];
}
if ($flt['location'] > 0) {
    $where[] = ['OR' => ["$table.stocklocations_id_from" => $flt['location'], "$table.stocklocations_id_to" => $flt['location']]];
}
if ($flt['beneficiary'] > 0) {
    $where["$table.users_id_beneficiary"] = $flt['beneficiary'];
}
if ($flt['executor'] > 0) {
    $where["$table.users_id_executor"] = $flt['executor'];
}

$lines_table = StockOrderLine::getTable();
$line_criteria = [];
if ($flt['itemtype'] !== '' && $flt['items_id'] > 0) {
    $line_criteria = ['itemtype' => $flt['itemtype'], 'items_id' => $flt['items_id']];
} elseif ($flt['article'] > 0) {
    $line_criteria = ['plugin_dsimanager_stockarticles_id' => $flt['article']];
}
if (count($line_criteria)) {
    $where["$table.id"] = new QuerySubQuery([
        'SELECT' => 'plugin_dsimanager_stockorders_id',
        'FROM'   => $lines_table,
        'WHERE'  => $line_criteria,
    ]);
}

if ($flt['q'] !== '') {
    $like = ['LIKE', '%' . $flt['q'] . '%'];
    $or = [
        "$table.comment"      => $like,
        "$table.supplier_ref" => $like,
        "$table.id"           => new QuerySubQuery([
            'SELECT' => 'plugin_dsimanager_stockorders_id',
            'FROM'   => $lines_table,
            'WHERE'  => ['OR' => ['label' => $like, 'serial' => $like, 'otherserial' => $like, 'comment' => $like]],
        ]),
    ];
    // Recherche par référence (E-00012, S-12, 12...).
    if (preg_match('/^(?:[ESTA]-?)?0*(\d+)$/i', $flt['q'], $m)) {
        $or[] = ["$table.id" => (int) $m[1]];
    }
    // Recherche par numéro de ticket lié (#1234 ou 1234).
    if (preg_match('/^#?(\d+)$/', $flt['q'], $m)) {
        $or[] = ["$table.tickets_id" => (int) $m[1]];
    }
    // Recherche par nom de bénéficiaire / exécutant.
    $or["$table.users_id_beneficiary"] = new QuerySubQuery([
        'SELECT' => 'id',
        'FROM'   => 'glpi_users',
        'WHERE'  => ['OR' => ['name' => $like, 'realname' => $like, 'firstname' => $like]],
    ]);
    $where[] = ['OR' => $or];
}

// --- Export CSV ---------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $DB->request([
        'FROM'  => $table,
        'WHERE' => $where,
        'ORDER' => ["$table.date_order DESC", "$table.id DESC"],
    ]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="historique_stock_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
    fputcsv($out, ['Référence', 'Type', 'Statut', 'Date', 'Validée le', 'Emplacement', 'Bénéficiaire', 'Service', 'Demandeur', 'Exécutant',
        'Ligne - type', 'Ligne - désignation', 'Ligne - S/N / IMEI', 'Ligne - n° inventaire', 'Ligne - quantité', 'Commentaire'], ';');
    foreach ($rows as $o) {
        $o_type = (int) $o['type'];
        $base_row = [
            StockOrder::formatReference($o_type, (int) $o['id']),
            StockOrder::getTypeLabel($o_type),
            StockOrder::getStatusLabel((int) $o['status']),
            $o['date_order'],
            $o['date_validation'],
            StockOrderLine::formatLocations($o_type, (int) $o['stocklocations_id_from'], (int) $o['stocklocations_id_to']),
            $o['users_id_beneficiary'] ? getUserName($o['users_id_beneficiary']) : '',
            $o['groups_id_beneficiary'] ? Dropdown::getDropdownName('glpi_groups', (int) $o['groups_id_beneficiary']) : '',
            $o['users_id_requester'] ? getUserName($o['users_id_requester']) : '',
            $o['users_id_executor'] ? getUserName($o['users_id_executor']) : '',
        ];
        $order_lines = $DB->request(['FROM' => $lines_table, 'WHERE' => ['plugin_dsimanager_stockorders_id' => (int) $o['id']], 'ORDER' => 'id ASC']);
        $had = false;
        foreach ($order_lines as $l) {
            $had = true;
            $kind = (int) $l['kind'] === StockOrderLine::KIND_UNIT
                ? Stock::getUnitTypeLabel((string) $l['itemtype'], (int) $l['items_id'])
                : __('Article', 'dsimanager');
            fputcsv($out, array_merge($base_row, [$kind, $l['label'], $l['serial'], $l['otherserial'], $l['quantity'], $o['comment']]), ';');
        }
        if (!$had) {
            fputcsv($out, array_merge($base_row, ['', '', '', '', '', $o['comment']]), ';');
        }
    }
    fclose($out);
    exit();
}

// --- Pagination ---------------------------------------------------------
$per_page = 50;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$total    = (int) $DB->request(['COUNT' => 'cpt', 'FROM' => $table, 'WHERE' => $where])->current()['cpt'];
$pages    = max(1, (int) ceil($total / $per_page));
$page     = min($page, $pages);

$orders = iterator_to_array($DB->request([
    'FROM'   => $table,
    'WHERE'  => $where,
    'ORDER'  => ["$table.date_order DESC", "$table.id DESC"],
    'START'  => ($page - 1) * $per_page,
    'LIMIT'  => $per_page,
]), false);

// Résumé des lignes par commande (une seule requête pour la page).
$summaries = [];
if (count($orders)) {
    $ids = array_map(static fn($o) => (int) $o['id'], $orders);
    foreach ($DB->request(['FROM' => $lines_table, 'WHERE' => ['plugin_dsimanager_stockorders_id' => $ids], 'ORDER' => 'id ASC']) as $l) {
        $oid = (int) $l['plugin_dsimanager_stockorders_id'];
        $summaries[$oid]['count'] = ($summaries[$oid]['count'] ?? 0) + ((int) $l['kind'] === StockOrderLine::KIND_UNIT ? 1 : max(0, (int) $l['quantity']));
        $txt = (int) $l['kind'] === StockOrderLine::KIND_UNIT ? (string) $l['label'] : ((int) $l['quantity'] . ' × ' . $l['label']);
        $summaries[$oid]['items'][] = $txt;
    }
}

// --- Affichage ----------------------------------------------------------
Html::header(__('Historique du stock', 'dsimanager'), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockOrder::class);
Stock::showNavBar('history');

$self = Plugin::getWebDir('dsimanager') . '/front/stockorder.php';
$form_url = Plugin::getWebDir('dsimanager') . '/front/stockorder.form.php';

// Formulaire de filtres en GET (pas de jeton CSRF nécessaire pour un GET).
echo "<form method='get' action='$self' class='card mb-3'><div class='card-body'>";
echo "<div class='row g-2 align-items-end'>";
echo "<div class='col-md-4'><label class='form-label'>" . __('Recherche (référence, n° de ticket, désignation, S/N, IMEI, n° inventaire, personne, commentaire)', 'dsimanager') . "</label>"
   . "<input type='search' name='q' class='form-control' value=\"" . htmlspecialchars($flt['q']) . "\" autofocus></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Du', 'dsimanager') . "</label><input type='date' name='date_from' class='form-control' value='" . htmlspecialchars($flt['date_from']) . "'></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Au', 'dsimanager') . "</label><input type='date' name='date_to' class='form-control' value='" . htmlspecialchars($flt['date_to']) . "'></div>";

echo "<div class='col-md-2'><label class='form-label'>" . __('Type', 'dsimanager') . "</label><select name='type' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach (StockOrder::getTypes() as $k => $v) {
    if (in_array($k, $visible_types, true)) {
        echo "<option value='$k'" . ($flt['type'] === $k ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
    }
}
echo "</select></div>";

echo "<div class='col-md-2'><label class='form-label'>" . __('Statut', 'dsimanager') . "</label><select name='status' class='form-select'>";
echo "<option value='-1'" . ($flt['status'] === -1 ? ' selected' : '') . ">" . __('Brouillons et validées', 'dsimanager') . "</option>";
echo "<option value='0'" . ($flt['status'] === 0 ? ' selected' : '') . ">" . __('Tous (y compris annulées)', 'dsimanager') . "</option>";
foreach (StockOrder::getStatuses() as $k => $v) {
    echo "<option value='$k'" . ($flt['status'] === $k ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";

echo "<div class='col-md-3'><label class='form-label'>" . __('Emplacement', 'dsimanager') . "</label><select name='location' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach (Stock::getLocations(false) as $k => $v) {
    echo "<option value='$k'" . ($flt['location'] === $k ? ' selected' : '') . ">" . htmlspecialchars($v) . "</option>";
}
echo "</select></div>";

echo "<div class='col-md-3'><label class='form-label'>" . __('Bénéficiaire', 'dsimanager') . "</label>";
User::dropdown(['name' => 'beneficiary', 'value' => $flt['beneficiary'], 'right' => 'all', 'width' => '100%']);
echo "</div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Exécutant', 'dsimanager') . "</label>";
User::dropdown(['name' => 'executor', 'value' => $flt['executor'], 'right' => 'all', 'width' => '100%']);
echo "</div>";

$articles = Stock::getArticles(false);
echo "<div class='col-md-3'><label class='form-label'>" . __('Article', 'dsimanager') . "</label><select name='article' class='form-select'><option value='0'>" . __('Tous', 'dsimanager') . "</option>";
foreach ($articles as $k => $a) {
    echo "<option value='$k'" . ($flt['article'] === $k ? ' selected' : '') . ">" . htmlspecialchars($a['name']) . "</option>";
}
echo "</select></div>";

if ($flt['itemtype'] !== '' && $flt['items_id'] > 0 && class_exists($flt['itemtype'])) {
    $snap = StockOrderLine::snapshotAsset($flt['itemtype'], $flt['items_id']);
    echo "<input type='hidden' name='itemtype' value=\"" . htmlspecialchars($flt['itemtype']) . "\"><input type='hidden' name='items_id' value='" . $flt['items_id'] . "'>";
    echo "<div class='col-12'><span class='badge' style='background:#4299e1;color:#fff;'>" . sprintf(__('Filtré sur l\'actif : %s', 'dsimanager'), htmlspecialchars($snap['label'] . ' ' . $snap['serial']))
       . "</span> <a href='$self'>" . __('retirer ce filtre', 'dsimanager') . "</a></div>";
}

echo "<div class='col-12 d-flex gap-2'>";
echo "<button type='submit' class='btn btn-primary'><i class='ti ti-search'></i> " . __('Filtrer', 'dsimanager') . "</button>";
echo "<a class='btn btn-outline-secondary' href='$self'>" . __('Réinitialiser', 'dsimanager') . "</a>";
$csv_params = $_GET;
$csv_params['export'] = 'csv';
unset($csv_params['page']);
echo "<a class='btn btn-outline-success ms-auto' href='$self?" . htmlspecialchars(http_build_query($csv_params)) . "'><i class='ti ti-file-spreadsheet'></i> " . __('Export CSV', 'dsimanager') . "</a>";
echo "</div>";
echo "</div></div></form>";

echo "<div class='card'><div class='card-body'>";
echo "<p class='text-muted'>" . sprintf(_n('%d commande', '%d commandes', $total, 'dsimanager'), $total) . "</p>";

if (!count($orders)) {
    echo "<p class='text-muted'>" . __('Aucune commande ne correspond à ces critères.', 'dsimanager') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='table table-hover table-sm align-middle'>";
    echo "<thead><tr><th>" . __('Référence', 'dsimanager') . "</th><th>" . __('Type', 'dsimanager') . "</th><th>" . __('Statut', 'dsimanager')
       . "</th><th>" . __('Date', 'dsimanager') . "</th><th>" . __('Matériel', 'dsimanager') . "</th><th>" . __('Qté', 'dsimanager')
       . "</th><th>" . __('Emplacement', 'dsimanager') . "</th><th>" . __('Bénéficiaire', 'dsimanager') . "</th><th>" . __('Exécutant', 'dsimanager')
       . "</th><th>" . __('Commentaire', 'dsimanager') . "</th></tr></thead><tbody>";
    foreach ($orders as $o) {
        $oid    = (int) $o['id'];
        $o_type = (int) $o['type'];
        $status = (int) $o['status'];
        $items  = $summaries[$oid]['items'] ?? [];
        $shown  = array_slice($items, 0, 3);
        $more   = count($items) - count($shown);
        $date   = $status === StockOrder::STATUS_VALIDATED && $o['date_validation'] ? Html::convDateTime($o['date_validation']) : Html::convDate($o['date_order']);
        $corr = (int) $o['corrects_id'] > 0 ? " <span class='badge' style='background:#f59f00;color:#fff;' title=\"" . __('Commande corrective', 'dsimanager') . "\"><i class='ti ti-arrow-back-up'></i></span>" : '';

        echo "<tr style='cursor:pointer' onclick=\"if(!event.target.closest('a')){window.location='$form_url?id=$oid';}\">";
        echo "<td><a href='$form_url?id=$oid'><strong>" . StockOrder::formatReference($o_type, $oid) . "</strong></a>$corr</td>";
        echo "<td>" . StockOrderLine::typeBadge($o_type) . "</td>";
        echo "<td><span class='badge' style='background:" . StockOrder::getStatusColor($status) . ";color:#fff;'>" . htmlspecialchars(StockOrder::getStatusLabel($status)) . "</span></td>";
        echo "<td>" . $date . "</td>";
        echo "<td>" . htmlspecialchars(implode(', ', $shown)) . ($more > 0 ? " <span class='text-muted'>+$more</span>" : '') . "</td>";
        echo "<td>" . (int) ($summaries[$oid]['count'] ?? 0) . "</td>";
        echo "<td>" . htmlspecialchars(StockOrderLine::formatLocations($o_type, (int) $o['stocklocations_id_from'], (int) $o['stocklocations_id_to'])) . "</td>";
        echo "<td>" . ($o['users_id_beneficiary'] ? htmlspecialchars(getUserName($o['users_id_beneficiary'])) : '') . "</td>";
        echo "<td>" . ($o['users_id_executor'] ? htmlspecialchars(getUserName($o['users_id_executor'])) : '') . "</td>";
        echo "<td class='text-truncate' style='max-width:220px' title=\"" . htmlspecialchars((string) $o['comment']) . "\">" . htmlspecialchars((string) $o['comment']) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";

    if ($pages > 1) {
        $params = $_GET;
        echo "<nav><ul class='pagination'>";
        for ($p = 1; $p <= $pages; $p++) {
            if ($pages > 12 && $p > 2 && $p < $pages - 1 && abs($p - $page) > 2) {
                if ($p === 3 || $p === $pages - 2) {
                    echo "<li class='page-item disabled'><span class='page-link'>…</span></li>";
                }
                continue;
            }
            $params['page'] = $p;
            echo "<li class='page-item" . ($p === $page ? ' active' : '') . "'><a class='page-link' href='$self?" . htmlspecialchars(http_build_query($params)) . "'>$p</a></li>";
        }
        echo "</ul></nav>";
    }
}
echo "</div></div>";

Html::footer();
