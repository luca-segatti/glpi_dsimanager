<?php

namespace GlpiPlugin\Dsimanager;

use Config;
use RuntimeException;
use Session;

/**
 * Module Stock - fonctions utilitaires partagées (réglages, emplacements,
 * articles, niveaux, recherche d'actifs). Classe purement statique : pas
 * d'itemtype GLPI, pas de table propre.
 */
class Stock
{
    public const CONFIG_CONTEXT = 'plugin:dsimanager';

    /** Cache par requête HTTP des réglages. */
    private static ?array $config_cache = null;

    /**
     * Réglages du module (table native glpi_configs, contexte du plugin),
     * avec des valeurs par défaut sûres si une clé est absente.
     */
    public static function getConfig(): array
    {
        if (self::$config_cache !== null) {
            return self::$config_cache;
        }
        $raw = Config::getConfigurationValues(self::CONFIG_CONTEXT);

        $itemtypes = json_decode($raw['stock_unit_itemtypes'] ?? '', true);
        if (!is_array($itemtypes) || !count($itemtypes)) {
            $itemtypes = ['Phone', 'Computer', 'Monitor', 'Line', 'Peripheral'];
        }

        self::$config_cache = [
            'state_in'       => (int) ($raw['stock_state_in'] ?? 0),
            'state_out'      => (int) ($raw['stock_state_out'] ?? 0),
            'state_disposed' => (int) ($raw['stock_state_disposed'] ?? 0),
            'location_out'   => (int) ($raw['stock_location_out'] ?? 0),
            'tablet_group'   => (int) ($raw['stock_tablet_group'] ?? 0),
            'unit_itemtypes' => $itemtypes,
        ];
        return self::$config_cache;
    }

    public static function saveConfig(array $values): void
    {
        $to_save = [];
        foreach (['state_in', 'state_out', 'state_disposed', 'location_out', 'tablet_group'] as $key) {
            if (array_key_exists($key, $values)) {
                $to_save['stock_' . $key] = (int) $values[$key];
            }
        }
        if (array_key_exists('unit_itemtypes', $values) && is_array($values['unit_itemtypes'])) {
            $valid = array_values(array_filter($values['unit_itemtypes'], [self::class, 'isUsableUnitItemtype']));
            if (count($valid)) {
                $to_save['stock_unit_itemtypes'] = json_encode($valid);
            }
        }
        if (count($to_save)) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $to_save);
        }
        self::$config_cache = null;
    }

    /**
     * Un type d'actif est utilisable en gestion unitaire s'il existe, est un
     * itemtype GLPI classique (CommonDBTM) et possède un champ identifiant
     * (numéro de série, ou numéro d'appel pour les lignes téléphoniques).
     * Permet d'ajouter un actif personnalisé GLPI 11 par simple réglage.
     */
    public static function isUsableUnitItemtype($itemtype): bool
    {
        global $DB;
        if (!is_string($itemtype) || $itemtype === '' || !class_exists($itemtype)) {
            return false;
        }
        if (!is_subclass_of($itemtype, \CommonDBTM::class)) {
            return false;
        }
        $table = $itemtype::getTable();
        return $DB->tableExists($table) && $DB->fieldExists($table, self::getIdentifierField($itemtype));
    }

    /**
     * Types d'actifs gérés à l'unité, avec leur libellé : [itemtype => libellé].
     */
    public static function getUnitItemtypes(): array
    {
        $out = [];
        foreach (self::getConfig()['unit_itemtypes'] as $itemtype) {
            if (self::isUsableUnitItemtype($itemtype)) {
                $out[$itemtype] = $itemtype::getTypeName(1);
            }
        }
        return $out;
    }

    /**
     * Champ qui identifie un actif de façon unique : le numéro de série
     * (où l'on saisit l'IMEI pour les téléphones, le S/N pour les tablettes),
     * sauf pour les lignes téléphoniques qui n'en ont pas (numéro d'appel).
     */
    public static function getIdentifierField(string $itemtype): string
    {
        return $itemtype === 'Line' ? 'caller_num' : 'serial';
    }

    public static function getIdentifierLabel(string $itemtype): string
    {
        return $itemtype === 'Line' ? __('Numéro de ligne', 'dsimanager') : __('N° de série / IMEI', 'dsimanager');
    }

    // ------------------------------------------------------------------
    // Emplacements et articles
    // ------------------------------------------------------------------

    /**
     * Emplacements de stockage [id => nom], triés par ordre d'affichage.
     */
    public static function getLocations(bool $active_only = true): array
    {
        global $DB;
        $where = $active_only ? ['is_active' => 1] : [];
        $out = [];
        foreach ($DB->request([
            'FROM'  => StockLocation::getTable(),
            'WHERE' => $where,
            'ORDER' => ['ranking ASC', 'name ASC'],
        ]) as $row) {
            $out[(int) $row['id']] = $row['name'];
        }
        return $out;
    }

    public static function getLocationName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $all = self::getLocations(false);
        return $all[$id] ?? ('#' . $id);
    }

    /**
     * Localisation GLPI native associée à un emplacement (0 si aucune).
     */
    public static function getNativeLocationId(int $stocklocations_id): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['locations_id'],
            'FROM'   => StockLocation::getTable(),
            'WHERE'  => ['id' => $stocklocations_id],
        ])->current();
        return $row ? (int) $row['locations_id'] : 0;
    }

    /**
     * Articles quantitatifs : [id => ['name', 'category', 'categories_id', 'is_active']].
     */
    public static function getArticles(bool $active_only = true): array
    {
        global $DB;
        $where = $active_only ? [StockArticle::getTable() . '.is_active' => 1] : [];
        $out = [];
        foreach ($DB->request([
            'SELECT'    => [
                StockArticle::getTable() . '.id',
                StockArticle::getTable() . '.name',
                StockArticle::getTable() . '.is_active',
                StockArticle::getTable() . '.is_bundle',
                StockArticle::getTable() . '.plugin_dsimanager_stockarticlecategories_id',
                StockArticleCategory::getTable() . '.name AS category',
            ],
            'FROM'      => StockArticle::getTable(),
            'LEFT JOIN' => [
                StockArticleCategory::getTable() => [
                    'FKEY' => [
                        StockArticle::getTable()         => 'plugin_dsimanager_stockarticlecategories_id',
                        StockArticleCategory::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => [StockArticle::getTable() . '.name ASC'],
        ]) as $row) {
            $is_bundle = (int) ($row['is_bundle'] ?? 0) === 1;
            $out[(int) $row['id']] = [
                'name'          => $row['name'],
                // Les kits forment une catégorie à part, "Kits".
                'category'      => $is_bundle ? __('Kits', 'dsimanager') : ($row['category'] ?? ''),
                'categories_id' => $is_bundle ? 0 : (int) $row['plugin_dsimanager_stockarticlecategories_id'],
                'is_active'     => (int) $row['is_active'],
                'is_bundle'     => $is_bundle,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Kits (articles composés d'autres articles)
    // ------------------------------------------------------------------

    /** Cache par requête de la composition des kits. */
    private static array $components_cache = [];

    /**
     * Composition actuelle d'un kit : [articles_id composant => quantité par kit].
     */
    public static function getBundleComponents(int $bundle_id): array
    {
        global $DB;
        if (!isset(self::$components_cache[$bundle_id])) {
            $out = [];
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_dsimanager_stockarticlecomponents',
                'WHERE' => ['plugin_dsimanager_stockarticles_id_bundle' => $bundle_id],
                'ORDER' => 'id ASC',
            ]) as $row) {
                $out[(int) $row['plugin_dsimanager_stockarticles_id']] = (int) $row['quantity'];
            }
            self::$components_cache[$bundle_id] = $out;
        }
        return self::$components_cache[$bundle_id];
    }

    public static function clearComponentsCache(): void
    {
        self::$components_cache = [];
    }

    public static function isBundle(int $articles_id): bool
    {
        $all = self::getArticles(false);
        return !empty($all[$articles_id]['is_bundle']);
    }

    /**
     * Nombre de kits constituables à un emplacement = minimum, sur chaque
     * composant, de (stock du composant / quantité par kit).
     */
    public static function getBundleAvailability(int $bundle_id, int $stocklocations_id, ?array $levels = null): int
    {
        $components = self::getBundleComponents($bundle_id);
        if (!count($components)) {
            return 0;
        }
        $levels = $levels ?? self::getLevelsMap();
        $min = PHP_INT_MAX;
        foreach ($components as $cid => $q) {
            $have = $levels[$cid][$stocklocations_id] ?? 0;
            $min = min($min, $q > 0 ? intdiv(max(0, $have), $q) : 0);
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Quantité disponible d'un article (stock réel) ou d'un kit (constituable).
     */
    public static function getAvailable(int $articles_id, int $stocklocations_id): int
    {
        return self::isBundle($articles_id)
            ? self::getBundleAvailability($articles_id, $stocklocations_id)
            : self::getLevel($articles_id, $stocklocations_id);
    }

    /**
     * Composition lisible : "1 × Clavier filaire, 1 × Souris filaire".
     */
    public static function getBundleSummary(int $bundle_id, ?array $components = null): string
    {
        $articles = self::getArticles(false);
        $parts = [];
        foreach (($components ?? self::getBundleComponents($bundle_id)) as $cid => $q) {
            $parts[] = (int) $q . ' × ' . ($articles[(int) $cid]['name'] ?? ('#' . $cid));
        }
        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------
    // Niveaux et seuils (quantitatif)
    // ------------------------------------------------------------------

    /**
     * Niveaux actuels : [articles_id][stocklocations_id] => quantité.
     */
    public static function getLevelsMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_dsimanager_stocklevels']) as $row) {
            $out[(int) $row['plugin_dsimanager_stockarticles_id']][(int) $row['plugin_dsimanager_stocklocations_id']] = (int) $row['quantity'];
        }
        return $out;
    }

    public static function getLevel(int $articles_id, int $stocklocations_id): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['quantity'],
            'FROM'   => 'glpi_plugin_dsimanager_stocklevels',
            'WHERE'  => [
                'plugin_dsimanager_stockarticles_id'  => $articles_id,
                'plugin_dsimanager_stocklocations_id' => $stocklocations_id,
            ],
        ])->current();
        return $row ? (int) $row['quantity'] : 0;
    }

    /**
     * Seuils : [articles_id][stocklocations_id] => seuil (seuils > 0 uniquement).
     */
    public static function getThresholdsMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_dsimanager_stockthresholds',
            'WHERE' => ['threshold' => ['>', 0]],
        ]) as $row) {
            $out[(int) $row['plugin_dsimanager_stockarticles_id']][(int) $row['plugin_dsimanager_stocklocations_id']] = (int) $row['threshold'];
        }
        return $out;
    }

    public static function saveThreshold(int $articles_id, int $stocklocations_id, int $threshold): void
    {
        global $DB;
        $where = [
            'plugin_dsimanager_stockarticles_id'  => $articles_id,
            'plugin_dsimanager_stocklocations_id' => $stocklocations_id,
        ];
        $exists = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stockthresholds', 'WHERE' => $where])->current()['cpt'] > 0;
        if ($threshold <= 0) {
            if ($exists) {
                $DB->delete('glpi_plugin_dsimanager_stockthresholds', $where);
            }
            return;
        }
        if ($exists) {
            $DB->update('glpi_plugin_dsimanager_stockthresholds', ['threshold' => $threshold], $where);
        } else {
            $DB->insert('glpi_plugin_dsimanager_stockthresholds', $where + ['threshold' => $threshold]);
        }
    }

    /**
     * Articles sous leur seuil d'alerte, par emplacement :
     * liste de ['articles_id', 'name', 'stocklocations_id', 'location', 'quantity', 'threshold'].
     */
    public static function getAlerts(): array
    {
        $levels     = self::getLevelsMap();
        $thresholds = self::getThresholdsMap();
        $articles   = self::getArticles(false);
        $locations  = self::getLocations(false);
        $alerts = [];
        foreach ($thresholds as $articles_id => $by_location) {
            if (!isset($articles[$articles_id]) || !$articles[$articles_id]['is_active']) {
                continue;
            }
            foreach ($by_location as $loc_id => $threshold) {
                $qty = $levels[$articles_id][$loc_id] ?? 0;
                if ($qty < $threshold) {
                    $alerts[] = [
                        'articles_id'       => $articles_id,
                        'name'              => $articles[$articles_id]['name'],
                        'stocklocations_id' => $loc_id,
                        'location'          => $locations[$loc_id] ?? ('#' . $loc_id),
                        'quantity'          => $qty,
                        'threshold'         => $threshold,
                    ];
                }
            }
        }
        return $alerts;
    }

    /**
     * Ajoute (ou retire, delta négatif) une quantité à un niveau de stock,
     * en verrouillant la ligne (SELECT ... FOR UPDATE) : doit être appelée
     * dans une transaction. Lève une exception si le stock deviendrait négatif.
     */
    public static function changeLevel(int $articles_id, int $stocklocations_id, int $delta, string $article_label = ''): int
    {
        global $DB;
        $current = self::lockLevel($articles_id, $stocklocations_id);
        $new = $current + $delta;
        if ($new < 0) {
            throw new RuntimeException(sprintf(
                __('Stock insuffisant pour « %1$s » à l\'emplacement « %2$s » : %3$d disponible(s), %4$d demandé(s).', 'dsimanager'),
                $article_label !== '' ? $article_label : ('#' . $articles_id),
                self::getLocationName($stocklocations_id),
                $current,
                -$delta
            ));
        }
        self::writeLevel($articles_id, $stocklocations_id, $new);
        return $new;
    }

    /**
     * Fixe un niveau à une valeur absolue (ajustement d'inventaire).
     * Renvoie l'ancienne valeur. À appeler dans une transaction.
     */
    public static function setLevel(int $articles_id, int $stocklocations_id, int $quantity): int
    {
        $current = self::lockLevel($articles_id, $stocklocations_id);
        self::writeLevel($articles_id, $stocklocations_id, max(0, $quantity));
        return $current;
    }

    private static function lockLevel(int $articles_id, int $stocklocations_id): int
    {
        global $DB;
        $result = $DB->doQuery(
            "SELECT `quantity` FROM `glpi_plugin_dsimanager_stocklevels`"
            . " WHERE `plugin_dsimanager_stockarticles_id` = " . (int) $articles_id
            . " AND `plugin_dsimanager_stocklocations_id` = " . (int) $stocklocations_id
            . " FOR UPDATE"
        );
        if ($result && $DB->numrows($result) > 0) {
            $row = $DB->fetchAssoc($result);
            return (int) $row['quantity'];
        }
        return 0;
    }

    private static function writeLevel(int $articles_id, int $stocklocations_id, int $quantity): void
    {
        global $DB;
        $where = [
            'plugin_dsimanager_stockarticles_id'  => $articles_id,
            'plugin_dsimanager_stocklocations_id' => $stocklocations_id,
        ];
        $exists = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stocklevels', 'WHERE' => $where])->current()['cpt'] > 0;
        if ($exists) {
            $DB->update('glpi_plugin_dsimanager_stocklevels', ['quantity' => $quantity], $where);
        } else {
            $DB->insert('glpi_plugin_dsimanager_stocklevels', $where + ['quantity' => $quantity]);
        }
    }

    /**
     * Recalcule tous les niveaux quantitatifs à partir de l'historique des
     * commandes validées (entrées - sorties ± transferts, ajustements).
     * Renvoie la liste des écarts trouvés : [articles_id, stocklocations_id,
     * cached, computed]. Si $apply, corrige le cache.
     */
    public static function recomputeLevels(bool $apply): array
    {
        global $DB;
        $computed = [];
        $add = static function (int $art, int $loc, int $delta) use (&$computed): void {
            if ($art <= 0 || $loc <= 0) {
                return;
            }
            $computed[$art][$loc] = ($computed[$art][$loc] ?? 0) + $delta;
        };

        $rows = $DB->request([
            'SELECT'     => [
                'l.plugin_dsimanager_stockarticles_id AS art',
                'l.quantity',
                'l.expected_quantity',
                'l.components',
                'o.type',
                'o.stocklocations_id_from AS loc_from',
                'o.stocklocations_id_to AS loc_to',
            ],
            'FROM'       => 'glpi_plugin_dsimanager_stockorderlines AS l',
            'INNER JOIN' => [
                'glpi_plugin_dsimanager_stockorders AS o' => [
                    'FKEY' => ['l' => 'plugin_dsimanager_stockorders_id', 'o' => 'id'],
                ],
            ],
            'WHERE'      => [
                'o.status' => StockOrder::STATUS_VALIDATED,
                'l.kind'   => StockOrderLine::KIND_QTY,
            ],
            'ORDER'      => ['o.date_validation ASC', 'o.id ASC'],
        ]);
        foreach ($rows as $row) {
            $qty = (int) $row['quantity'];
            // Un kit est développé selon sa composition figée à la validation.
            $parts = json_decode((string) ($row['components'] ?? ''), true);
            if (!is_array($parts) || !count($parts)) {
                $parts = [(int) $row['art'] => 1];
            }
            if ((int) $row['type'] === StockOrder::TYPE_ADJUST) {
                // quantity = quantité comptée, expected = quantité en cache au
                // moment de la validation : l'écart est ce qui a été appliqué.
                $add((int) $row['art'], (int) $row['loc_to'], $qty - (int) $row['expected_quantity']);
                continue;
            }
            foreach ($parts as $art => $per) {
                $art = (int) $art;
                $n   = $qty * (int) $per;
                switch ((int) $row['type']) {
                    case StockOrder::TYPE_IN:
                        $add($art, (int) $row['loc_to'], $n);
                        break;
                    case StockOrder::TYPE_OUT:
                    case StockOrder::TYPE_DISPOSAL:
                        $add($art, (int) $row['loc_from'], -$n);
                        break;
                    case StockOrder::TYPE_TRANSFER:
                        $add($art, (int) $row['loc_from'], -$n);
                        $add($art, (int) $row['loc_to'], $n);
                        break;
                }
            }
        }

        $cached = self::getLevelsMap();
        $diffs = [];
        $keys = [];
        foreach ([$cached, $computed] as $map) {
            foreach ($map as $art => $by_loc) {
                foreach ($by_loc as $loc => $unused) {
                    $keys[$art . '-' . $loc] = [$art, $loc];
                }
            }
        }
        foreach ($keys as [$art, $loc]) {
            $c = $cached[$art][$loc] ?? 0;
            $n = $computed[$art][$loc] ?? 0;
            if ($c !== $n) {
                $diffs[] = ['articles_id' => $art, 'stocklocations_id' => $loc, 'cached' => $c, 'computed' => $n];
            }
        }

        if ($apply && count($diffs)) {
            foreach ($diffs as $d) {
                self::writeLevel($d['articles_id'], $d['stocklocations_id'], max(0, $d['computed']));
            }
        }
        return $diffs;
    }

    // ------------------------------------------------------------------
    // Actifs unitaires
    // ------------------------------------------------------------------

    /**
     * Recherche, dans TOUS les types gérés à l'unité, les actifs portant cet
     * identifiant (n° de série / IMEI, ou numéro de ligne). Comparaison
     * insensible à la casse et aux espaces de bord (collation MySQL).
     * Renvoie une liste de ['itemtype', 'id', 'name', 'is_deleted'].
     */
    public static function findAssetsByIdentifier(string $identifier): array
    {
        global $DB;
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }
        $found = [];
        foreach (array_keys(self::getUnitItemtypes()) as $itemtype) {
            $table = $itemtype::getTable();
            $field = self::getIdentifierField($itemtype);
            $select = ['id', 'name'];
            if ($DB->fieldExists($table, 'entities_id')) {
                $select[] = 'entities_id';
            }
            if ($DB->fieldExists($table, 'is_deleted')) {
                $select[] = 'is_deleted';
            }
            $where = [$field => $identifier];
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }
            // Actifs personnalisés GLPI 11 : tous partagent la même table,
            // le critère système restreint au bon type (définition).
            if (method_exists($itemtype, 'getSystemSQLCriteria')) {
                $where = array_merge($where, $itemtype::getSystemSQLCriteria());
            }
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $found[] = [
                    'itemtype'   => $itemtype,
                    'id'         => (int) $row['id'],
                    'name'       => $row['name'],
                    'is_deleted' => (int) ($row['is_deleted'] ?? 0),
                    'entities_id' => (int) ($row['entities_id'] ?? 0),
                ];
            }
        }
        return $found;
    }

    /**
     * Actifs (hors corbeille) correspondant à un numéro, pour une
     * suppression : n° de série / IMEI / numéro de ligne OU n° d'inventaire.
     */
    public static function findAssetsForDisposal(string $text): array
    {
        global $DB;
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $found = [];
        foreach (array_keys(self::getUnitItemtypes()) as $itemtype) {
            $table = $itemtype::getTable();
            $or = [self::getIdentifierField($itemtype) => $text];
            if ($DB->fieldExists($table, 'otherserial')) {
                $or['otherserial'] = $text;
            }
            $where = [['OR' => $or]];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }
            if (method_exists($itemtype, 'getSystemSQLCriteria')) {
                $where = array_merge($where, $itemtype::getSystemSQLCriteria());
            }
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $found[] = ['itemtype' => $itemtype, 'id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        return $found;
    }

    /**
     * Choisit l'actif à réutiliser parmi ceux d'un même type qui portent le
     * numéro. S'il y en a plusieurs mais qu'UN SEUL n'est pas à la corbeille,
     * c'est lui qui est retenu (les fiches à la corbeille sont d'anciens
     * doublons). Sinon : erreur détaillée, avec un lien vers chaque fiche.
     * Renvoie [items_id (0 = à créer), message d'erreur ('' = OK)].
     */
    public static function pickExistingAsset(array $same, string $identifier): array
    {
        if (count($same) === 0) {
            return [0, ''];
        }
        if (count($same) === 1) {
            return [$same[0]['id'], ''];
        }
        $active = array_values(array_filter($same, static fn($f) => !$f['is_deleted']));
        if (count($active) === 1) {
            return [$active[0]['id'], ''];
        }
        $details = [];
        foreach ($same as $f) {
            $entity = \Dropdown::getDropdownName('glpi_entities', $f['entities_id'], false, true, false);
            $details[] = '#' . $f['id'] . ' « ' . $f['name'] . ' » (' . sprintf(__('entité %s', 'dsimanager'), $entity)
                . ($f['is_deleted'] ? ', ' . __('à la corbeille', 'dsimanager') : '') . ')';
        }
        return [0, sprintf(
            __('%1$d fiches %2$s portent le numéro « %3$s » : %4$s. Supprimez définitivement ou corrigez le doublon dans GLPI avant l\'entrée.', 'dsimanager'),
            count($same),
            $same[0]['itemtype']::getTypeName(2),
            $identifier,
            implode(' ; ', $details)
        )];
    }

    /**
     * Emplacement où se trouve un actif unitaire (0 s'il n'est pas en stock).
     */
    public static function getUnitLocation(string $itemtype, int $items_id): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['plugin_dsimanager_stocklocations_id'],
            'FROM'   => 'glpi_plugin_dsimanager_stockunits',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current();
        return $row ? (int) $row['plugin_dsimanager_stocklocations_id'] : 0;
    }

    /**
     * Actifs unitaires en stock, avec leurs données natives actuelles.
     * Filtres facultatifs : emplacement, type, texte libre.
     * Renvoie une liste de lignes prêtes à afficher.
     */
    public static function getUnitsInStock(int $stocklocations_id = 0, string $itemtype_filter = '', string $search = ''): array
    {
        global $DB;
        $types = self::getUnitItemtypes();
        $out = [];
        $search = trim($search);

        foreach ($types as $itemtype => $type_label) {
            if ($itemtype_filter !== '' && $itemtype_filter !== $itemtype && $itemtype_filter !== 'Phone:tablet' && $itemtype_filter !== 'Phone:phone') {
                continue;
            }
            if (($itemtype_filter === 'Phone:tablet' || $itemtype_filter === 'Phone:phone') && $itemtype !== 'Phone') {
                continue;
            }
            $table = $itemtype::getTable();
            $idf   = self::getIdentifierField($itemtype);
            $select = ["$table.id", "$table.name", "$table.$idf AS identifier", 'u.plugin_dsimanager_stocklocations_id', 'u.date_in'];
            if ($DB->fieldExists($table, 'otherserial')) {
                $select[] = "$table.otherserial";
            }
            $has_manufacturer = $DB->fieldExists($table, 'manufacturers_id');
            $joins = [
                $table => ['FKEY' => ['u' => 'items_id', $table => 'id']],
            ];
            if ($has_manufacturer) {
                $select[] = 'glpi_manufacturers.name AS manufacturer';
            }
            $where = ['u.itemtype' => $itemtype];
            if ($stocklocations_id > 0) {
                $where['u.plugin_dsimanager_stocklocations_id'] = $stocklocations_id;
            }
            if ($search !== '') {
                $or = [
                    "$table.name" => ['LIKE', "%$search%"],
                    "$table.$idf" => ['LIKE', "%$search%"],
                ];
                if ($DB->fieldExists($table, 'otherserial')) {
                    $or["$table.otherserial"] = ['LIKE', "%$search%"];
                }
                $where[] = ['OR' => $or];
            }
            $criteria = [
                'SELECT'     => $select,
                'FROM'       => 'glpi_plugin_dsimanager_stockunits AS u',
                'INNER JOIN' => $joins,
                'WHERE'      => $where,
                'ORDER'      => ["$table.name ASC"],
            ];
            if ($has_manufacturer) {
                $criteria['LEFT JOIN'] = [
                    'glpi_manufacturers' => ['FKEY' => [$table => 'manufacturers_id', 'glpi_manufacturers' => 'id']],
                ];
            }
            $tablets = $itemtype === 'Phone' ? self::getTabletIds() : [];
            foreach ($DB->request($criteria) as $row) {
                $is_tablet = $itemtype === 'Phone' && isset($tablets[(int) $row['id']]);
                if ($itemtype_filter === 'Phone:tablet' && !$is_tablet) {
                    continue;
                }
                if ($itemtype_filter === 'Phone:phone' && $is_tablet) {
                    continue;
                }
                $out[] = [
                    'itemtype'          => $itemtype,
                    'items_id'          => (int) $row['id'],
                    'type_label'        => $is_tablet ? __('Tablette', 'dsimanager') : $type_label,
                    'name'              => $row['name'],
                    'manufacturer'      => $row['manufacturer'] ?? '',
                    'identifier'        => $row['identifier'],
                    'otherserial'       => $row['otherserial'] ?? '',
                    'stocklocations_id' => (int) $row['plugin_dsimanager_stocklocations_id'],
                    'date_in'           => $row['date_in'],
                ];
            }
        }
        return $out;
    }

    /**
     * Identifiants des téléphones rattachés au groupe "Tablette" (réglable),
     * sous forme [phones_id => true]. GLPI 11 stocke les groupes des actifs
     * dans glpi_groups_items (trait AssignableItem), pas dans un champ.
     */
    public static function getTabletIds(): array
    {
        global $DB;
        $group = self::getConfig()['tablet_group'];
        if ($group <= 0 || !$DB->tableExists('glpi_groups_items')) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['items_id'],
            'FROM'   => 'glpi_groups_items',
            'WHERE'  => ['itemtype' => 'Phone', 'groups_id' => $group],
        ]) as $row) {
            $out[(int) $row['items_id']] = true;
        }
        return $out;
    }

    public static function isTablet(string $itemtype, int $items_id): bool
    {
        return $itemtype === 'Phone' && isset(self::getTabletIds()[$items_id]);
    }

    /**
     * Libellé du type d'un actif ("Tablette" pour un téléphone du groupe
     * tablette, sinon le nom du type GLPI).
     */
    public static function getUnitTypeLabel(string $itemtype, int $items_id = 0): string
    {
        if ($items_id > 0 && self::isTablet($itemtype, $items_id)) {
            return __('Tablette', 'dsimanager');
        }
        return class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype;
    }

    /**
     * Lien vers la fiche native d'un actif.
     */
    public static function getAssetLink(string $itemtype, int $items_id, string $label): string
    {
        if ($items_id <= 0 || !class_exists($itemtype)) {
            return htmlspecialchars($label);
        }
        return "<a href='" . $itemtype::getFormURLWithID($items_id) . "'>" . htmlspecialchars($label !== '' ? $label : ('#' . $items_id)) . "</a>";
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    /** Page "Matériel sorti du parc" : droit Contenu ou droit Suppressions. */
    public static function canViewDisposed(): bool
    {
        return Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT, READ)
            || Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL, READ);
    }

    public static function canAny(int $bit): bool
    {
        foreach ([PLUGIN_DSIMANAGER_RIGHT_STOCK_IN, PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT, PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER, PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL] as $right) {
            if (Session::haveRight($right, $bit)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pages de configuration (listes et fiches) : retour rapide vers la
     * liste concernée et vers la page Configuration du stock.
     * $list_page = fichier de la liste (ex. 'stockarticle.php'), '' pour
     * une page de liste (seul le retour Configuration est affiché).
     */
    public static function showConfigBackBar(string $list_page = '', string $list_label = '', bool $show_add = false, string $form_page = ''): void
    {
        $base = \Plugin::getWebDir('dsimanager') . '/front';
        echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
        echo "<a class='btn btn-outline-secondary btn-sm' href='$base/stockconfig.php'><i class='ti ti-arrow-left'></i>&nbsp;" . __('Configuration du stock', 'dsimanager') . "</a>";
        if ($list_page !== '') {
            echo "<a class='btn btn-outline-secondary btn-sm' href='$base/$list_page'><i class='ti ti-list'></i>&nbsp;" . htmlspecialchars($list_label) . "</a>";
        }
        if ($show_add && $form_page !== '' && Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, CREATE)) {
            echo "<a class='btn btn-primary btn-sm' href='$base/$form_page'><i class='ti ti-plus'></i>&nbsp;" . __('Ajouter', 'dsimanager') . "</a>";
        }
        echo "</div>";
    }

    /**
     * Petit menu d'onglets commun en haut des pages du module.
     */
    public static function showNavBar(string $active): void
    {
        $base = \Plugin::getWebDir('dsimanager') . '/front';
        $links = [];
        if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT, READ)) {
            $links['content'] = [__('Contenu', 'dsimanager'), "$base/stock.php", 'ti ti-packages'];
        }
        if (self::canViewDisposed()) {
            $links['disposed'] = [__('Sorti du parc', 'dsimanager'), "$base/stockdisposed.php", 'ti ti-trash-x'];
        }
        if (StockOrder::canView()) {
            $links['history'] = [__('Historique', 'dsimanager'), "$base/stockorder.php", 'ti ti-history'];
        }
        if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS, READ)) {
            $links['stats'] = [__('Statistiques', 'dsimanager'), "$base/stockstats.php", 'ti ti-chart-bar'];
        }
        if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ)) {
            $links['config'] = [__('Configuration', 'dsimanager'), "$base/stockconfig.php", 'ti ti-settings'];
        }

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        foreach ($links as $key => [$label, $url, $icon]) {
            $class = $key === $active ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
            echo "<a class='$class' href='$url'><i class='$icon'></i>&nbsp;" . $label . "</a>";
        }
        echo "<span class='mx-2'></span>";
        $new = [
            StockOrder::TYPE_IN       => [PLUGIN_DSIMANAGER_RIGHT_STOCK_IN, __('Nouvelle entrée', 'dsimanager'), 'ti ti-arrow-bar-to-down'],
            StockOrder::TYPE_OUT      => [PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT, __('Nouvelle sortie', 'dsimanager'), 'ti ti-arrow-bar-up'],
            StockOrder::TYPE_TRANSFER => [PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER, __('Transfert', 'dsimanager'), 'ti ti-arrows-exchange'],
            StockOrder::TYPE_ADJUST   => [PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER, __('Ajustement d\'inventaire', 'dsimanager'), 'ti ti-adjustments'],
            StockOrder::TYPE_DISPOSAL => [PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL, __('Suppression (sortie du parc)', 'dsimanager'), 'ti ti-trash-x'],
        ];
        foreach ($new as $type => [$right, $label, $icon]) {
            if (Session::haveRight($right, CREATE)) {
                echo "<a class='btn btn-success btn-sm' href='$base/stockorder.form.php?type=$type'><i class='$icon'></i>&nbsp;" . $label . "</a>";
            }
        }
        echo "</div>";
    }
}
