<?php

namespace GlpiPlugin\Dsimanager;

use CommonGLPI;
use DateTime;
use Session;

/**
 * Module Stock (Lot 3) - Statistiques : calculs pour la page
 * front/stockstats.php et pour les cartes du tableau de bord GLPI.
 *
 * Règles de calcul :
 *  - seules les commandes VALIDÉES comptent, à leur date de validation
 *    (moment réel du mouvement) ;
 *  - une commande corrective est déduite du type de la commande qu'elle
 *    corrige (ex. la correction d'une sortie retire ses éléments des
 *    sorties, au lieu de compter comme une entrée) ;
 *  - "éléments" = 1 par actif unitaire + la quantité pour les articles.
 */
class StockStats extends CommonGLPI
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS;

    public static function getTypeName($nb = 0)
    {
        return __('Statistiques du stock', 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = __('Statistiques', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/stockstats.php';
            $menu['icon']  = self::getIcon();
        }
        return $menu;
    }

    // ------------------------------------------------------------------
    // Période
    // ------------------------------------------------------------------

    public static function getPeriodPresets(): array
    {
        return [
            '30d'    => __('30 derniers jours', 'dsimanager'),
            '90d'    => __('3 derniers mois', 'dsimanager'),
            '12m'    => __('12 derniers mois', 'dsimanager'),
            'year'   => __('Année en cours', 'dsimanager'),
            'custom' => __('Personnalisée', 'dsimanager'),
        ];
    }

    /**
     * Bornes [début, fin] (Y-m-d) d'un préréglage ; dates libres pour 'custom'.
     */
    public static function resolvePeriod(string $preset, string $from = '', string $to = ''): array
    {
        $today = date('Y-m-d');
        switch ($preset) {
            case '30d':
                return [date('Y-m-d', strtotime('-29 days')), $today];
            case '90d':
                return [date('Y-m-d', strtotime('-3 months +1 day')), $today];
            case 'year':
                return [date('Y') . '-01-01', $today];
            case 'custom':
                $ok = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
                $f = $ok($from) ? $from : date('Y-m-d', strtotime('-12 months +1 day'));
                $t = $ok($to) ? $to : $today;
                return $f <= $t ? [$f, $t] : [$t, $f];
            default: // 12m
                return [date('Y-m-01', strtotime('-11 months')), $today];
        }
    }

    // ------------------------------------------------------------------
    // Mouvements de la période
    // ------------------------------------------------------------------

    /**
     * Lignes des commandes validées sur la période (et l'emplacement), avec
     * le type "effectif" (type de la commande corrigée pour une corrective)
     * et le signe (+1 / -1).
     */
    private static function getMovementLines(string $from, string $to, int $location = 0): array
    {
        global $DB;
        $where = [
            'o.status' => StockOrder::STATUS_VALIDATED,
            ['o.date_validation' => ['>=', $from . ' 00:00:00']],
            ['o.date_validation' => ['<=', $to . ' 23:59:59']],
        ];
        if ($location > 0) {
            $where[] = ['OR' => ['o.stocklocations_id_from' => $location, 'o.stocklocations_id_to' => $location]];
        }
        $rows = $DB->request([
            'SELECT'     => [
                'o.id AS orders_id', 'o.type', 'o.corrects_id', 'o.date_validation',
                'orig.type AS orig_type',
                'l.kind', 'l.itemtype', 'l.items_id', 'l.plugin_dsimanager_stockarticles_id AS articles_id',
                'l.label', 'l.quantity', 'l.expected_quantity',
            ],
            'FROM'       => StockOrder::getTable() . ' AS o',
            'INNER JOIN' => [
                StockOrderLine::getTable() . ' AS l' => ['FKEY' => ['l' => 'plugin_dsimanager_stockorders_id', 'o' => 'id']],
            ],
            'LEFT JOIN'  => [
                StockOrder::getTable() . ' AS orig' => ['FKEY' => ['orig' => 'id', 'o' => 'corrects_id']],
            ],
            'WHERE'      => $where,
        ]);
        $out = [];
        foreach ($rows as $r) {
            $is_corr = (int) $r['corrects_id'] > 0 && $r['orig_type'] !== null;
            $r['eff_type'] = $is_corr ? (int) $r['orig_type'] : (int) $r['type'];
            $r['sign']     = $is_corr ? -1 : 1;
            $r['elements'] = (int) $r['kind'] === StockOrderLine::KIND_UNIT ? 1 : (int) $r['quantity'];
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Toutes les statistiques de la période, en une passe.
     */
    public static function compute(string $from, string $to, int $location = 0): array
    {
        $lines = self::getMovementLines($from, $to, $location);

        // Découpage temporel : semaines jusqu'à ~2 mois, mois au-delà.
        $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        $by_week = $days <= 62;
        $buckets = self::buildBuckets($from, $to, $by_week);

        $totals = [];
        foreach (array_keys(StockOrder::getTypes()) as $t) {
            $totals[$t] = ['orders' => [], 'elements' => 0];
        }
        $adjust_delta = 0;
        $top_units = [];
        $top_articles = [];
        $model_cache = [];

        foreach ($lines as $l) {
            $type = $l['eff_type'];
            $sign = $l['sign'];
            if (!isset($totals[$type])) {
                continue;
            }
            if ($sign > 0) {
                $totals[$type]['orders'][(int) $l['orders_id']] = true;
            }
            if ($type === StockOrder::TYPE_ADJUST) {
                $adjust_delta += (int) $l['quantity'] - (int) $l['expected_quantity'];
                continue;
            }
            $totals[$type]['elements'] += $sign * $l['elements'];

            $bkey = self::bucketKey((string) $l['date_validation'], $by_week);
            if (isset($buckets[$bkey]) && in_array($type, [StockOrder::TYPE_IN, StockOrder::TYPE_OUT], true)) {
                $buckets[$bkey][$type] += $sign * $l['elements'];
            }

            if ($type === StockOrder::TYPE_OUT) {
                if ((int) $l['kind'] === StockOrderLine::KIND_UNIT) {
                    $ck = $l['itemtype'] . '#' . (int) $l['items_id'];
                    if (!isset($model_cache[$ck])) {
                        $model_cache[$ck] = self::describeUnit((string) $l['itemtype'], (int) $l['items_id']);
                    }
                    $key = $model_cache[$ck];
                    $top_units[$key] = ($top_units[$key] ?? 0) + $sign;
                } else {
                    $key = (int) $l['articles_id'];
                    $top_articles[$key] = ($top_articles[$key] ?? 0) + $sign * (int) $l['quantity'];
                }
            }
        }

        $articles = Stock::getArticles(false);
        $top_art_named = [];
        foreach ($top_articles as $aid => $qty) {
            $top_art_named[$articles[$aid]['name'] ?? ('#' . $aid)] = $qty;
        }

        return [
            'from'         => $from,
            'to'           => $to,
            'by_week'      => $by_week,
            'totals'       => array_map(static fn($t) => ['orders' => count($t['orders']), 'elements' => $t['elements']], $totals),
            'adjust_delta' => $adjust_delta,
            'buckets'      => $buckets,
            'top_units'    => self::topN($top_units, 10),
            'top_articles' => self::topN($top_art_named, 10),
        ];
    }

    private static function topN(array $values, int $n): array
    {
        $values = array_filter($values, static fn($v) => $v > 0);
        arsort($values);
        return array_slice($values, 0, $n, true);
    }

    /**
     * "Tablette - Samsung Galaxy Tab A9" : type affiché + fabricant/modèle
     * actuels de l'actif (le nom de l'appareil, propre à chaque unité,
     * n'aurait pas de sens pour un classement).
     */
    private static function describeUnit(string $itemtype, int $items_id): string
    {
        $label = Stock::getUnitTypeLabel($itemtype, $items_id);
        if ($items_id <= 0 || !class_exists($itemtype)) {
            return $label;
        }
        $asset = new $itemtype();
        if (!$asset->getFromDB($items_id)) {
            return $label;
        }
        $parts = [];
        if (!empty($asset->fields['manufacturers_id'])) {
            $parts[] = \Dropdown::getDropdownName('glpi_manufacturers', (int) $asset->fields['manufacturers_id'], false, true, false);
        }
        $model_class = $itemtype . 'Model';
        $has_model = class_exists($model_class);
        if ($has_model) {
            $fk = $model_class::getForeignKeyField();
            if (!empty($asset->fields[$fk])) {
                $parts[] = \Dropdown::getDropdownName($model_class::getTable(), (int) $asset->fields[$fk], false, true, false);
            }
        }
        $detail = trim(implode(' ', $parts));
        if ($detail !== '') {
            return $label . ' - ' . $detail;
        }
        // Types sans modèle (lignes téléphoniques) : le type seul suffit.
        return $has_model ? $label . ' - ' . __('modèle non renseigné', 'dsimanager') : $label;
    }

    private static function bucketKey(string $datetime, bool $by_week): string
    {
        $dt = new DateTime(substr($datetime, 0, 10));
        if ($by_week) {
            $dow = (int) $dt->format('N');
            if ($dow > 1) {
                $dt->modify('-' . ($dow - 1) . ' days');
            }
            return $dt->format('Y-m-d');
        }
        return $dt->format('Y-m');
    }

    /**
     * Toutes les tranches de la période (y compris vides), dans l'ordre :
     * [clé => [TYPE_IN => 0, TYPE_OUT => 0, 'label' => ...]].
     */
    private static function buildBuckets(string $from, string $to, bool $by_week): array
    {
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $out = [];
        $cursor = new DateTime(self::bucketKey($from, $by_week) . ($by_week ? '' : '-01'));
        $end = new DateTime($to);
        $guard = 0;
        while ($cursor <= $end && $guard++ < 400) {
            $key = $by_week ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
            $label = $by_week
                ? 'S' . $cursor->format('W')
                : $months[(int) $cursor->format('n') - 1] . ' ' . $cursor->format('y');
            $out[$key] = [StockOrder::TYPE_IN => 0, StockOrder::TYPE_OUT => 0, 'label' => $label];
            $cursor->modify($by_week ? '+7 days' : '+1 month');
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // État actuel du stock
    // ------------------------------------------------------------------

    /**
     * État actuel : par catégorie (type d'actif unitaire, catégorie
     * d'article) et par emplacement.
     *   'categories' => [libellé => ['kind' => unit|qty, 'total' => n, 'by_location' => [loc => n]]]
     *   'locations'  => [loc_id => ['name', 'units', 'articles', 'alerts']]
     */
    public static function getStockState(int $location = 0): array
    {
        $locations = Stock::getLocations(false);
        $categories = [];
        $locs = [];
        foreach (Stock::getLocations(true) as $lid => $name) {
            if ($location <= 0 || $location === $lid) {
                $locs[$lid] = ['name' => $name, 'units' => 0, 'articles' => 0, 'alerts' => 0];
            }
        }

        foreach (Stock::getUnitsInStock($location) as $u) {
            $lid = $u['stocklocations_id'];
            $key = $u['type_label'];
            $categories[$key]['kind'] = 'unit';
            $categories[$key]['total'] = ($categories[$key]['total'] ?? 0) + 1;
            $categories[$key]['by_location'][$lid] = ($categories[$key]['by_location'][$lid] ?? 0) + 1;
            if (!isset($locs[$lid])) {
                $locs[$lid] = ['name' => ($locations[$lid] ?? '#' . $lid) . ' ' . __('(inactif)', 'dsimanager'), 'units' => 0, 'articles' => 0, 'alerts' => 0];
            }
            $locs[$lid]['units']++;
        }

        $articles = Stock::getArticles(true);
        foreach (Stock::getLevelsMap() as $aid => $by_loc) {
            if (!isset($articles[$aid])) {
                continue;
            }
            $key = $articles[$aid]['category'] !== '' ? $articles[$aid]['category'] : __('Articles sans catégorie', 'dsimanager');
            foreach ($by_loc as $lid => $qty) {
                if (($location > 0 && $lid !== $location) || $qty === 0) {
                    continue;
                }
                $categories[$key]['kind'] = 'qty';
                $categories[$key]['total'] = ($categories[$key]['total'] ?? 0) + $qty;
                $categories[$key]['by_location'][$lid] = ($categories[$key]['by_location'][$lid] ?? 0) + $qty;
                if (!isset($locs[$lid])) {
                    $locs[$lid] = ['name' => ($locations[$lid] ?? '#' . $lid) . ' ' . __('(inactif)', 'dsimanager'), 'units' => 0, 'articles' => 0, 'alerts' => 0];
                }
                $locs[$lid]['articles'] += $qty;
            }
        }

        $alerts = array_values(array_filter(Stock::getAlerts(), static fn($a) => $location <= 0 || $a['stocklocations_id'] === $location));
        foreach ($alerts as $a) {
            if (isset($locs[$a['stocklocations_id']])) {
                $locs[$a['stocklocations_id']]['alerts']++;
            }
        }

        uasort($categories, static fn($a, $b) => [$a['kind'], -$a['total']] <=> [$b['kind'], -$b['total']]);
        return ['categories' => $categories, 'locations' => $locs, 'alerts' => $alerts];
    }

    // ------------------------------------------------------------------
    // Rendu (HTML / SVG maison, aucune bibliothèque externe)
    // ------------------------------------------------------------------

    /**
     * Couleurs des séries, validées daltonisme / contraste (bleu = entrées,
     * orange = sorties), avec variantes pour le thème sombre de GLPI.
     */
    public static function getCss(): string
    {
        return <<<CSS
<style>
.at-stats { --at-s-in:#2a78d6; --at-s-out:#eb6834; --at-s-one:#2a78d6; --at-grid:rgba(120,120,120,.22); }
html[data-glpi-theme-dark="1"] .at-stats { --at-s-in:#3987e5; --at-s-out:#d95926; --at-s-one:#3987e5; --at-grid:rgba(200,200,200,.18); }
.at-stats .at-kpi { border:1px solid var(--tblr-border-color, #e6e7e9); border-radius:8px; padding:12px 14px; height:100%; }
.at-stats .at-kpi .at-kpi-label { font-size:.8rem; color:var(--tblr-secondary, #667382); }
.at-stats .at-kpi .at-kpi-value { font-size:1.6rem; font-weight:600; line-height:1.2; }
.at-stats .at-kpi .at-kpi-sub { font-size:.8rem; color:var(--tblr-secondary, #667382); }
.at-stats .at-kpi.at-alert { border-color:#d63939; }
.at-stats .at-kpi.at-alert .at-kpi-value { color:#d63939; }
.at-stats .at-legend { display:flex; gap:16px; font-size:.85rem; margin-bottom:6px; }
.at-stats .at-legend span::before { content:''; display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:-1px; background:var(--c); }
.at-stats .at-hbar { display:grid; grid-template-columns:minmax(120px, 45%) 1fr auto; align-items:center; gap:4px 10px; font-size:.875rem; }
.at-stats .at-hbar .at-track { height:14px; }
.at-stats .at-hbar .at-fill { height:14px; background:var(--at-s-one); border-radius:0 4px 4px 0; min-width:2px; }
.at-stats .at-hbar .at-val { font-variant-numeric:tabular-nums; text-align:right; min-width:2.5em; }
.at-stats .at-hbar .at-lbl { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.at-stats svg text { fill:currentColor; font-size:11px; }
.at-stats svg .at-axis { fill:var(--tblr-secondary, #667382); }
.at-stats svg .at-bar-in { fill:var(--at-s-in); }
.at-stats svg .at-bar-out { fill:var(--at-s-out); }
.at-stats svg .at-bar-in:hover, .at-stats svg .at-bar-out:hover { opacity:.8; }
.at-stats svg line.at-gridline { stroke:var(--at-grid); stroke-width:1; }
.at-stats table.at-data td, .at-stats table.at-data th { font-variant-numeric:tabular-nums; }
</style>
CSS;
    }

    /**
     * Maximum de l'axe : 4 graduations entières et "rondes".
     */
    private static function niceMax(int $max): int
    {
        if ($max <= 4) {
            return 4;
        }
        $raw = $max / 4;
        $mag = 10 ** (int) floor(log10($raw));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
            $step = $m * $mag;
            if ($step >= $raw && floor($step) == $step) {
                return (int) ($step * 4);
            }
        }
        return (int) (ceil($raw) * 4);
    }

    /** Barre verticale à sommet arrondi (4 px), base carrée. */
    private static function barPath(float $x, float $y, float $w, float $h): string
    {
        if ($h <= 0) {
            return '';
        }
        $r = min(4, $w / 2, $h);
        return sprintf(
            'M%.1f,%.1f V%.1f Q%.1f,%.1f %.1f,%.1f H%.1f Q%.1f,%.1f %.1f,%.1f V%.1f Z',
            $x, $y + $h, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $y + $h
        );
    }

    /**
     * Histogramme groupé entrées / sorties par tranche de temps. Infobulle
     * native (<title>) sur chaque barre ; tableau de données sous le graphe.
     */
    public static function renderMovementsChart(array $buckets): string
    {
        $W = 820;
        $H = 260;
        $left = 40;
        $right = 10;
        $top = 10;
        $bottom = 28;
        $n = max(1, count($buckets));
        $max = 0;
        foreach ($buckets as $b) {
            $max = max($max, $b[StockOrder::TYPE_IN], $b[StockOrder::TYPE_OUT]);
        }
        $ymax = self::niceMax($max);
        $plot_w = $W - $left - $right;
        $plot_h = $H - $top - $bottom;
        $slot = $plot_w / $n;
        $bar_w = min(24, max(3, ($slot - 8) / 2 - 1));
        $label_every = (int) max(1, ceil($n / 14));

        $svg = "<svg viewBox='0 0 $W $H' width='100%' role='img' aria-label=\"" . htmlspecialchars(__('Entrées et sorties par période', 'dsimanager')) . "\">";
        for ($i = 0; $i <= 4; $i++) {
            $v = (int) round($ymax * $i / 4);
            $y = $top + $plot_h - $plot_h * $i / 4;
            $svg .= sprintf("<line class='at-gridline' x1='%d' x2='%d' y1='%.1f' y2='%.1f'/>", $left, $W - $right, $y, $y);
            $svg .= sprintf("<text class='at-axis' x='%d' y='%.1f' text-anchor='end' dominant-baseline='middle'>%d</text>", $left - 6, $y, $v);
        }
        $i = 0;
        foreach ($buckets as $b) {
            $cx = $left + $slot * $i + $slot / 2;
            foreach ([[StockOrder::TYPE_IN, 'at-bar-in', -1, __('Entrées', 'dsimanager')], [StockOrder::TYPE_OUT, 'at-bar-out', 1, __('Sorties', 'dsimanager')]] as [$t, $cls, $side, $name]) {
                $val = max(0, (int) $b[$t]);
                $h = $ymax > 0 ? $plot_h * $val / $ymax : 0;
                $x = $side < 0 ? $cx - 1 - $bar_w : $cx + 1;
                $tip = htmlspecialchars($b['label'] . ' - ' . $name . ' : ' . $val);
                // Zone de survol pleine hauteur (plus grande que la barre).
                $svg .= sprintf("<g><title>%s</title><rect x='%.1f' y='%d' width='%.1f' height='%d' fill='transparent'/>", $tip, $x, $top, $bar_w, $plot_h);
                if ($h > 0) {
                    $svg .= "<path class='$cls' d='" . self::barPath($x, $top + $plot_h - $h, $bar_w, $h) . "'/>";
                }
                $svg .= "</g>";
            }
            if ($i % $label_every === 0) {
                $svg .= sprintf("<text class='at-axis' x='%.1f' y='%d' text-anchor='middle'>%s</text>", $cx, $H - 8, htmlspecialchars($b['label']));
            }
            $i++;
        }
        $svg .= sprintf("<line class='at-gridline' x1='%d' x2='%d' y1='%d' y2='%d' style='stroke-opacity:1'/>", $left, $W - $right, $top + $plot_h, $top + $plot_h);
        $svg .= "</svg>";
        return $svg;
    }

    /**
     * Barres horizontales HTML (une série) : libellé, barre, valeur.
     */
    public static function renderHbars(array $values, string $unit = ''): string
    {
        if (!count($values)) {
            return "<p class='text-muted mb-0'>" . __('Aucune donnée sur la période.', 'dsimanager') . "</p>";
        }
        $max = max($values) ?: 1;
        $html = "<div class='at-hbar'>";
        foreach ($values as $label => $v) {
            $pct = round(100 * $v / $max, 1);
            $html .= "<div class='at-lbl' title=\"" . htmlspecialchars((string) $label) . "\">" . htmlspecialchars((string) $label) . "</div>";
            $html .= "<div class='at-track' title=\"" . htmlspecialchars($label . ' : ' . $v . $unit) . "\"><div class='at-fill' style='width:$pct%'></div></div>";
            $html .= "<div class='at-val'>" . (int) $v . "</div>";
        }
        return $html . "</div>";
    }

    // ------------------------------------------------------------------
    // Cartes du tableau de bord GLPI (hook dashboard_cards)
    // ------------------------------------------------------------------

    public static function getDashboardCards(): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return [];
        }
        $group = __('Stock (Gestion DSI)', 'dsimanager');
        $p = self::class . '::';
        return [
            'dsimanager_stock_alerts' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Stock - Articles sous le seuil d\'alerte', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardAlerts',
                'cache'      => false,
            ],
            'dsimanager_stock_out_30d' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Stock - Éléments sortis (30 jours)', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardOut30',
                'cache'      => false,
            ],
            'dsimanager_stock_in_30d' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Stock - Éléments entrés (30 jours)', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardIn30',
                'cache'      => false,
            ],
            'dsimanager_stock_drafts' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Stock - Commandes en brouillon', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardDrafts',
                'cache'      => false,
            ],
            'dsimanager_stock_by_location' => [
                'widgettype' => ['hbar', 'pie', 'donut', 'bar', 'multipleNumber'],
                'label'      => __('Stock - Éléments en stock par emplacement', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardByLocation',
                'cache'      => false,
            ],
            'dsimanager_stock_by_category' => [
                'widgettype' => ['hbar', 'pie', 'donut', 'bar', 'multipleNumber'],
                'label'      => __('Stock - Éléments en stock par catégorie', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardByCategory',
                'cache'      => false,
            ],
            'dsimanager_stock_evolution' => [
                'widgettype' => ['bars', 'lines'],
                'label'      => __('Stock - Entrées et sorties (12 mois)', 'dsimanager'),
                'group'      => $group,
                'provider'   => $p . 'cardEvolution',
                'cache'      => false,
            ],
        ];
    }

    private static function statsUrl(string $extra = ''): string
    {
        return \Plugin::getWebDir('dsimanager') . '/front/stockstats.php' . $extra;
    }

    private static function denied(array $params): array
    {
        return ['number' => 0, 'label' => $params['label'] ?? '', 'icon' => 'ti ti-lock', 'data' => ['nodata' => true]];
    }

    public static function cardAlerts(array $params = []): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        return [
            'number' => count(Stock::getAlerts()),
            'url'    => \Plugin::getWebDir('dsimanager') . '/front/stock.php',
            'label'  => __('Articles sous le seuil d\'alerte', 'dsimanager'),
            'icon'   => 'ti ti-alert-triangle',
        ];
    }

    private static function card30(int $type, string $label, string $icon, array $params): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        [$from, $to] = self::resolvePeriod('30d');
        $stats = self::compute($from, $to);
        return [
            'number' => max(0, $stats['totals'][$type]['elements']),
            'url'    => self::statsUrl('?period=30d'),
            'label'  => $label,
            'icon'   => $icon,
        ];
    }

    public static function cardOut30(array $params = []): array
    {
        return self::card30(StockOrder::TYPE_OUT, __('Éléments sortis du stock (30 jours)', 'dsimanager'), 'ti ti-arrow-bar-up', $params);
    }

    public static function cardIn30(array $params = []): array
    {
        return self::card30(StockOrder::TYPE_IN, __('Éléments entrés en stock (30 jours)', 'dsimanager'), 'ti ti-arrow-bar-to-down', $params);
    }

    public static function cardDrafts(array $params = []): array
    {
        global $DB;
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        $nb = (int) $DB->request(['COUNT' => 'cpt', 'FROM' => StockOrder::getTable(), 'WHERE' => ['status' => StockOrder::STATUS_DRAFT]])->current()['cpt'];
        return [
            'number' => $nb,
            'url'    => \Plugin::getWebDir('dsimanager') . '/front/stockorder.php?status=' . StockOrder::STATUS_DRAFT,
            'label'  => __('Commandes de stock en brouillon', 'dsimanager'),
            'icon'   => 'ti ti-file-pencil',
        ];
    }

    private static function listCard(array $values, string $label, string $icon, callable $url_for): array
    {
        $data = [];
        foreach ($values as $name => $number) {
            $data[] = ['number' => $number, 'label' => (string) $name, 'url' => $url_for($name)];
        }
        if (!count($data)) {
            $data = ['nodata' => true];
        }
        return ['data' => $data, 'label' => $label, 'icon' => $icon];
    }

    public static function cardByLocation(array $params = []): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        $state = self::getStockState();
        $values = [];
        $ids = [];
        foreach ($state['locations'] as $lid => $l) {
            if ($l['units'] + $l['articles'] > 0) {
                $values[$l['name']] = $l['units'] + $l['articles'];
                $ids[$l['name']] = $lid;
            }
        }
        $base = \Plugin::getWebDir('dsimanager') . '/front/stock.php?location=';
        return self::listCard($values, __('Éléments en stock par emplacement', 'dsimanager'), 'ti ti-building-warehouse', static fn($n) => $base . $ids[$n]);
    }

    public static function cardByCategory(array $params = []): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        $values = [];
        foreach (self::getStockState()['categories'] as $name => $c) {
            $values[$name] = $c['total'];
        }
        arsort($values);
        $url = \Plugin::getWebDir('dsimanager') . '/front/stock.php';
        return self::listCard($values, __('Éléments en stock par catégorie', 'dsimanager'), 'ti ti-category', static fn($n) => $url);
    }

    public static function cardEvolution(array $params = []): array
    {
        if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            return self::denied($params);
        }
        [$from, $to] = self::resolvePeriod('12m');
        $stats = self::compute($from, $to);
        $labels = [];
        $in = [];
        $out = [];
        $url = self::statsUrl('?period=12m');
        foreach ($stats['buckets'] as $b) {
            $labels[] = $b['label'];
            $in[] = ['value' => max(0, $b[StockOrder::TYPE_IN]), 'url' => $url];
            $out[] = ['value' => max(0, $b[StockOrder::TYPE_OUT]), 'url' => $url];
        }
        return [
            'data'  => [
                'labels' => $labels,
                'series' => [
                    ['name' => __('Entrées', 'dsimanager'), 'data' => $in],
                    ['name' => __('Sorties', 'dsimanager'), 'data' => $out],
                ],
            ],
            'label' => __('Entrées et sorties de stock (12 mois)', 'dsimanager'),
            'icon'  => 'ti ti-chart-bar',
        ];
    }
}
