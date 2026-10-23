<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use RuntimeException;
use Session;
use Throwable;

/**
 * Module Stock - Commande de stock (entrée, sortie, transfert, ajustement
 * d'inventaire). Cycle de vie :
 *   Brouillon  -> modifiable (en-tête et lignes), sans effet sur le stock ;
 *   Validée    -> stock et actifs natifs mis à jour en une seule
 *                 transaction (tout ou rien), puis figée définitivement ;
 *   Annulée    -> brouillon abandonné, conservé pour la traçabilité.
 * Une commande validée n'est jamais modifiée ni supprimée : une erreur se
 * corrige par une commande corrective (mouvement inverse, champ corrects_id).
 */
class StockOrder extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY;

    public const TYPE_IN       = 1;
    public const TYPE_OUT      = 2;
    public const TYPE_TRANSFER = 3;
    public const TYPE_ADJUST   = 4;
    /** Sortie définitive du parc : actif mis à la corbeille de GLPI (v2.4.0). */
    public const TYPE_DISPOSAL = 5;

    public const STATUS_DRAFT     = 1;
    public const STATUS_VALIDATED = 2;
    public const STATUS_CANCELLED = 3;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockorders';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Commande de stock', 'Commandes de stock', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    /**
     * Visible pour quiconque a accès à l'historique OU à au moins un type
     * de commande (on doit pouvoir rouvrir un brouillon qu'on a préparé).
     */
    public static function canView(): bool
    {
        return Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY, READ) || Stock::canAny(READ);
    }

    /**
     * Visibilité d'une commande précise : exige aussi le droit global. Sans
     * cela, GLPI montrerait la commande à l'utilisateur qui a rempli un
     * formulaire (liste "éléments créés" affichée après la soumission).
     */
    public function canViewItem(): bool
    {
        return static::canView() && parent::canViewItem();
    }

    public static function canCreate(): bool
    {
        return Stock::canAny(CREATE);
    }

    public static function canUpdate(): bool
    {
        return Stock::canAny(UPDATE);
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = __('Historique', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/stockorder.php';
            $menu['icon']  = self::getIcon();
        }
        return $menu;
    }

    // ------------------------------------------------------------------
    // Libellés et droits par type
    // ------------------------------------------------------------------

    public static function getTypes(): array
    {
        return [
            self::TYPE_IN       => __('Entrée', 'dsimanager'),
            self::TYPE_OUT      => __('Sortie', 'dsimanager'),
            self::TYPE_TRANSFER => __('Transfert', 'dsimanager'),
            self::TYPE_ADJUST   => __('Ajustement', 'dsimanager'),
            self::TYPE_DISPOSAL => __('Suppression', 'dsimanager'),
        ];
    }

    public static function getTypeLabel(int $type): string
    {
        return self::getTypes()[$type] ?? '?';
    }

    public static function getTypeColor(int $type): string
    {
        return [
            self::TYPE_IN       => '#2fb344',
            self::TYPE_OUT      => '#d63939',
            self::TYPE_TRANSFER => '#4263eb',
            self::TYPE_ADJUST   => '#ae3ec9',
            self::TYPE_DISPOSAL => '#495057',
        ][$type] ?? '#6c757d';
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT     => __('Brouillon', 'dsimanager'),
            self::STATUS_VALIDATED => __('Validée', 'dsimanager'),
            self::STATUS_CANCELLED => __('Annulée', 'dsimanager'),
        ];
    }

    public static function getStatusColor(int $status): string
    {
        return [
            self::STATUS_DRAFT     => '#f59f00',
            self::STATUS_VALIDATED => '#2fb344',
            self::STATUS_CANCELLED => '#6c757d',
        ][$status] ?? '#6c757d';
    }

    public static function getStatusLabel(int $status): string
    {
        return self::getStatuses()[$status] ?? '?';
    }

    public static function getRightForType(int $type): string
    {
        switch ($type) {
            case self::TYPE_IN:
                return PLUGIN_DSIMANAGER_RIGHT_STOCK_IN;
            case self::TYPE_OUT:
                return PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT;
            case self::TYPE_DISPOSAL:
                return PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL;
            default:
                return PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER;
        }
    }

    public static function canDoOnType(int $type, int $bit): bool
    {
        return isset(self::getTypes()[$type]) && (bool) Session::haveRight(self::getRightForType($type), $bit);
    }

    /** Emplacement d'origine requis pour ce type ? */
    public static function needsFrom(int $type): bool
    {
        return in_array($type, [self::TYPE_OUT, self::TYPE_TRANSFER], true);
    }

    /**
     * Emplacement d'origine possible (facultatif pour une suppression : un
     * actif en service peut sortir du parc sans être passé par le stock).
     */
    public static function allowsFrom(int $type): bool
    {
        return self::needsFrom($type) || $type === self::TYPE_DISPOSAL;
    }

    /** Emplacement de destination requis pour ce type ? */
    public static function needsTo(int $type): bool
    {
        return in_array($type, [self::TYPE_IN, self::TYPE_TRANSFER, self::TYPE_ADJUST], true);
    }

    /**
     * Référence lisible : E-00012 (entrée), S- (sortie), T- (transfert),
     * A- (ajustement).
     */
    public static function formatReference(int $type, int $id): string
    {
        $prefix = [self::TYPE_IN => 'E', self::TYPE_OUT => 'S', self::TYPE_TRANSFER => 'T', self::TYPE_ADJUST => 'A', self::TYPE_DISPOSAL => 'X'][$type] ?? '?';
        return $prefix . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function getReference(): string
    {
        return self::formatReference((int) $this->fields['type'], (int) $this->getID());
    }

    public function isDraft(): bool
    {
        return (int) $this->fields['status'] === self::STATUS_DRAFT;
    }

    public function getName($options = [])
    {
        if ($this->isNewItem()) {
            return parent::getName($options);
        }
        return $this->getReference();
    }

    // ------------------------------------------------------------------
    // En-tête
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        $type = (int) ($input['type'] ?? 0);
        if (!isset(self::getTypes()[$type])) {
            Session::addMessageAfterRedirect(__('Type de commande inconnu.', 'dsimanager'), false, ERROR);
            return false;
        }
        $input['status']           = self::STATUS_DRAFT;
        $input['users_id_creator'] = Session::getLoginUserID();
        $input['entities_id']      = $input['entities_id'] ?? Session::getActiveEntity();
        if (empty($input['date_order'])) {
            $input['date_order'] = date('Y-m-d');
        }
        return self::cleanHeaderInput($input, $type);
    }

    public function prepareInputForUpdate($input)
    {
        // Une commande validée ou annulée est figée : seul le passage
        // interne au statut "Validée" (validateOrder) écrit encore dedans,
        // via $DB->update() direct, jamais via update().
        if (!$this->isDraft()) {
            Session::addMessageAfterRedirect(__('Cette commande n\'est plus un brouillon : elle ne peut plus être modifiée.', 'dsimanager'), false, ERROR);
            return false;
        }
        // Le type et le statut ne se changent pas par le formulaire.
        unset($input['type'], $input['status'], $input['users_id_validator'], $input['date_validation'], $input['corrects_id']);
        return self::cleanHeaderInput($input, (int) $this->fields['type']);
    }

    /**
     * Ne garde que les emplacements pertinents pour le type de commande.
     */
    private static function cleanHeaderInput(array $input, int $type): array
    {
        if (!self::allowsFrom($type)) {
            $input['stocklocations_id_from'] = 0;
        }
        if (!self::needsTo($type)) {
            $input['stocklocations_id_to'] = 0;
        }
        if ($type !== self::TYPE_OUT) {
            $input['users_id_beneficiary']  = $type === self::TYPE_IN ? ($input['users_id_beneficiary'] ?? 0) : 0;
            $input['groups_id_beneficiary'] = $type === self::TYPE_IN ? ($input['groups_id_beneficiary'] ?? 0) : 0;
        }
        // Référence : fournisseur (entrée) ou bordereau / contrat (suppression).
        if (!in_array($type, [self::TYPE_IN, self::TYPE_DISPOSAL], true)) {
            $input['supplier_ref'] = '';
        }
        if ($type !== self::TYPE_DISPOSAL) {
            $input['plugin_dsimanager_stockdisposalreasons_id'] = 0;
        }
        // Localisation inscrite sur les actifs à la sortie : sorties seulement.
        if ($type !== self::TYPE_OUT) {
            $input['locations_id'] = 0;
        }
        // Ticket lié : doit exister, sinon on le retire (sans bloquer).
        if (array_key_exists('tickets_id', $input)) {
            $tid = (int) $input['tickets_id'];
            if ($tid > 0 && !(new \Ticket())->getFromDB($tid)) {
                Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('Ticket #%d introuvable : lien ignoré.', 'dsimanager'), $tid)), false, WARNING);
                $tid = 0;
            }
            $input['tickets_id'] = max(0, $tid);
        }
        return $input;
    }

    /**
     * Valeurs de départ d'une commande créée depuis un ticket : demandeur
     * (= bénéficiaire), son service (groupe demandeur du ticket, sinon groupe
     * par défaut de l'utilisateur) et la localisation du ticket.
     */
    public static function getTicketPrefill(int $tickets_id): array
    {
        global $DB;
        $ticket = new \Ticket();
        if ($tickets_id <= 0 || !$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
            return [];
        }
        $requester = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::REQUESTER, 'users_id' => ['>', 0]],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ])->current();
        $users_id = $requester ? (int) $requester['users_id'] : 0;

        $group = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => 'glpi_groups_tickets',
            'WHERE'  => ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::REQUESTER],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ])->current();
        $groups_id = $group ? (int) $group['groups_id'] : 0;
        if ($groups_id === 0 && $users_id > 0) {
            $user = new \User();
            if ($user->getFromDB($users_id)) {
                $groups_id = (int) ($user->fields['groups_id'] ?? 0);
            }
        }

        return [
            'tickets_id'            => $tickets_id,
            'users_id_requester'    => $users_id,
            'users_id_beneficiary'  => $users_id,
            'groups_id_beneficiary' => $groups_id,
            'locations_id'          => (int) $ticket->fields['locations_id'],
            'comment'               => sprintf(__('Ticket #%1$d - %2$s', 'dsimanager'), $tickets_id, $ticket->fields['name']),
        ];
    }

    // ------------------------------------------------------------------
    // Onglet "Stock" sur les tickets
    // ------------------------------------------------------------------

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Ticket && !$item->isNewItem() && self::canView()) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
                global $DB;
                $nb = (int) $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => self::getTable(),
                    'WHERE' => ['tickets_id' => $item->getID(), 'status' => [self::STATUS_DRAFT, self::STATUS_VALIDATED]],
                ])->current()['cpt'];
            }
            return self::createTabEntry(__('Stock', 'dsimanager'), $nb, $item::getType(), 'ti ti-packages');
        }
        return '';
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Ticket) {
            self::showForTicket($item);
        }
        return true;
    }

    public static function showForTicket(\Ticket $ticket): void
    {
        global $DB;
        $tid  = (int) $ticket->getID();
        $base = \Plugin::getWebDir('dsimanager') . '/front';

        echo "<div class='firstbloc'>";
        echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
        foreach ([self::TYPE_OUT => [__('Sortie de stock depuis ce ticket', 'dsimanager'), 'ti ti-arrow-bar-up'], self::TYPE_IN => [__('Entrée (reprise) depuis ce ticket', 'dsimanager'), 'ti ti-arrow-bar-to-down']] as $type => [$label, $icon]) {
            if (self::canDoOnType($type, CREATE)) {
                echo "<a class='btn btn-success btn-sm' href='$base/stockorder.form.php?type=$type&tickets_id=$tid'><i class='$icon'></i>&nbsp;" . htmlspecialchars($label) . "</a>";
            }
        }
        echo "</div>";

        $orders = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $tid],
            'ORDER' => ['id DESC'],
        ]), false);
        if (!count($orders)) {
            echo "<p class='text-muted'>" . __('Aucune commande de stock liée à ce ticket.', 'dsimanager') . "</p></div>";
            return;
        }
        echo "<table class='tab_cadre_fixehov'><tr><th>" . __('Commande', 'dsimanager') . "</th><th>" . __('Type', 'dsimanager') . "</th><th>"
           . __('Statut', 'dsimanager') . "</th><th>" . __('Date', 'dsimanager') . "</th><th>" . __('Matériel', 'dsimanager') . "</th><th>"
           . __('Bon', 'dsimanager') . "</th></tr>";
        foreach ($orders as $o) {
            $oid = (int) $o['id'];
            $labels = [];
            foreach ($DB->request(['SELECT' => ['label', 'quantity', 'kind'], 'FROM' => StockOrderLine::getTable(), 'WHERE' => ['plugin_dsimanager_stockorders_id' => $oid]]) as $l) {
                $labels[] = ((int) $l['kind'] === StockOrderLine::KIND_QTY && (int) $l['quantity'] > 1 ? (int) $l['quantity'] . ' × ' : '') . $l['label'];
            }
            $status = (int) $o['status'];
            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='$base/stockorder.form.php?id=$oid'>" . self::formatReference((int) $o['type'], $oid) . "</a></td>";
            echo "<td>" . StockOrderLine::typeBadge((int) $o['type']) . "</td>";
            echo "<td><span class='badge' style='background:" . self::getStatusColor($status) . ";color:#fff;'>" . htmlspecialchars(self::getStatusLabel($status)) . "</span></td>";
            echo "<td>" . \Html::convDate($o['date_order']) . "</td>";
            echo "<td>" . htmlspecialchars(implode(', ', $labels)) . "</td>";
            echo "<td>";
            if (in_array((int) $o['type'], [self::TYPE_IN, self::TYPE_OUT], true) && $status !== self::STATUS_CANCELLED) {
                echo "<a href='$base/stockdoc.php?id=$oid' target='_blank'><i class='ti ti-file-type-pdf'></i> PDF</a>";
            }
            echo "</td></tr>";
        }
        echo "</table></div>";
    }

    // ------------------------------------------------------------------
    // Lignes
    // ------------------------------------------------------------------

    public function getLines(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => StockOrderLine::getTable(),
            'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID()],
            'ORDER' => ['kind ASC', 'id ASC'],
        ]), false);
    }

    /**
     * Ajoute une ligne à un brouillon. Contrôles "de confort" immédiats
     * (doublons, disponibilité) : ils sont TOUS refaits à la validation,
     * qui seule fait foi. Renvoie un message d'erreur, ou '' si OK.
     */
    public function addLine(array $input): string
    {
        global $DB;
        if (!$this->isDraft()) {
            return __('Cette commande n\'est plus un brouillon.', 'dsimanager');
        }
        $type = (int) $this->fields['type'];
        $kind = (int) ($input['kind'] ?? 0);
        $line = ['plugin_dsimanager_stockorders_id' => $this->getID(), 'kind' => $kind];

        if ($kind === StockOrderLine::KIND_UNIT) {
            if ($type === self::TYPE_ADJUST) {
                return __('L\'ajustement d\'inventaire ne concerne que les articles gérés en quantité.', 'dsimanager');
            }
            if ($type === self::TYPE_IN) {
                $itemtype   = (string) ($input['itemtype'] ?? '');
                $identifier = trim((string) ($input['serial'] ?? ''));
                if (!isset(Stock::getUnitItemtypes()[$itemtype])) {
                    return __('Type d\'actif non géré.', 'dsimanager');
                }
                if ($identifier === '') {
                    return sprintf(__('%s obligatoire pour un actif unitaire (règle anti-doublon).', 'dsimanager'), Stock::getIdentifierLabel($itemtype));
                }
                $found = Stock::findAssetsByIdentifier($identifier);
                $same  = array_values(array_filter($found, static fn($f) => $f['itemtype'] === $itemtype));
                $other = array_values(array_filter($found, static fn($f) => $f['itemtype'] !== $itemtype));
                if (count($other) && !count($same)) {
                    return sprintf(
                        __('Ce numéro existe déjà sur un autre type d\'actif (%1$s « %2$s ») : vérifiez le type choisi.', 'dsimanager'),
                        $other[0]['itemtype']::getTypeName(1),
                        $other[0]['name']
                    );
                }
                [$items_id, $dup_error] = Stock::pickExistingAsset($same, $identifier);
                if ($dup_error !== '') {
                    return $dup_error;
                }
                if ($items_id > 0) {
                    $loc = Stock::getUnitLocation($itemtype, $items_id);
                    if ($loc > 0) {
                        return sprintf(__('Cet actif est déjà en stock (%s).', 'dsimanager'), Stock::getLocationName($loc));
                    }
                }
                if ($this->hasIdentifierInLines($itemtype, $identifier)) {
                    return __('Ce numéro figure déjà dans cette commande.', 'dsimanager');
                }
                $label = trim((string) ($input['label'] ?? ''));
                if ($items_id > 0 && $label === '') {
                    foreach ($same as $f) {
                        if ($f['id'] === $items_id) {
                            $label = (string) $f['name'];
                        }
                    }
                }
                $line += [
                    'itemtype'    => $itemtype,
                    'items_id'    => $items_id,
                    'label'       => $label !== '' ? $label : $identifier,
                    'serial'      => $identifier,
                    'otherserial' => trim((string) ($input['otherserial'] ?? '')),
                    'quantity'    => 1,
                ];
            } elseif ($type === self::TYPE_DISPOSAL && trim((string) ($input['serial'] ?? '')) !== '') {
                // Suppression d'un actif qui n'est pas forcément en stock :
                // recherche par n° de série / IMEI / ligne ou n° d'inventaire.
                $text  = trim((string) $input['serial']);
                $found = Stock::findAssetsForDisposal($text);
                if (!count($found)) {
                    return sprintf(__('Aucun actif actif (hors corbeille) ne porte le numéro « %s ».', 'dsimanager'), $text);
                }
                if (count($found) > 1) {
                    $list = array_map(static fn($f) => $f['itemtype']::getTypeName(1) . ' #' . $f['id'] . ' « ' . $f['name'] . ' »', $found);
                    return sprintf(__('Plusieurs actifs correspondent à « %1$s » : %2$s. Précisez le numéro.', 'dsimanager'), $text, implode(' ; ', $list));
                }
                $itemtype = $found[0]['itemtype'];
                $items_id = $found[0]['id'];
                $dup = $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => StockOrderLine::getTable(),
                    'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID(), 'itemtype' => $itemtype, 'items_id' => $items_id],
                ])->current()['cpt'];
                if ($dup > 0) {
                    return __('Cet actif figure déjà dans cette commande.', 'dsimanager');
                }
                $line += StockOrderLine::snapshotAsset($itemtype, $items_id) + ['itemtype' => $itemtype, 'items_id' => $items_id, 'quantity' => 1];
            } else {
                // Sortie / transfert : choix parmi les actifs en stock à
                // l'emplacement d'origine ("itemtype|id").
                $from = (int) $this->fields['stocklocations_id_from'];
                if ($from <= 0) {
                    return __('Choisissez et enregistrez d\'abord l\'emplacement d\'origine.', 'dsimanager');
                }
                $parts    = explode('|', (string) ($input['unit_ref'] ?? ''));
                $itemtype = $parts[0] ?? '';
                $items_id = (int) ($parts[1] ?? 0);
                if (!isset(Stock::getUnitItemtypes()[$itemtype]) || $items_id <= 0) {
                    return __('Choisissez un actif.', 'dsimanager');
                }
                if (Stock::getUnitLocation($itemtype, $items_id) !== $from) {
                    return __('Cet actif n\'est pas en stock à l\'emplacement d\'origine.', 'dsimanager');
                }
                $dup = $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => StockOrderLine::getTable(),
                    'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID(), 'itemtype' => $itemtype, 'items_id' => $items_id],
                ])->current()['cpt'];
                if ($dup > 0) {
                    return __('Cet actif figure déjà dans cette commande.', 'dsimanager');
                }
                $line += StockOrderLine::snapshotAsset($itemtype, $items_id) + ['itemtype' => $itemtype, 'items_id' => $items_id, 'quantity' => 1];
            }
        } elseif ($kind === StockOrderLine::KIND_QTY) {
            $articles_id = (int) ($input['articles_id'] ?? 0);
            $articles    = Stock::getArticles(true);
            if (!isset($articles[$articles_id])) {
                return __('Choisissez un article.', 'dsimanager');
            }
            $qty = (int) ($input['quantity'] ?? 0);
            if ($type === self::TYPE_ADJUST ? $qty < 0 : $qty <= 0) {
                return __('Quantité invalide.', 'dsimanager');
            }
            if (!empty($articles[$articles_id]['is_bundle'])) {
                if ($type === self::TYPE_ADJUST) {
                    return __('Un kit ne s\'inventorie pas : ajustez ses articles composants.', 'dsimanager');
                }
                if (!count(Stock::getBundleComponents($articles_id))) {
                    return __('Ce kit n\'a aucun composant : complétez sa composition dans Stock > Configuration > Articles.', 'dsimanager');
                }
            }
            $existing = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => StockOrderLine::getTable(),
                'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID(), 'plugin_dsimanager_stockarticles_id' => $articles_id],
            ])->current()['cpt'];
            if ($existing > 0) {
                return __('Cet article figure déjà dans cette commande : supprimez la ligne existante pour changer la quantité.', 'dsimanager');
            }
            if (self::allowsFrom($type)) {
                $from = (int) $this->fields['stocklocations_id_from'];
                if ($from <= 0) {
                    return __('Choisissez et enregistrez d\'abord l\'emplacement d\'origine.', 'dsimanager');
                }
                $available = Stock::getAvailable($articles_id, $from);
                if ($qty > $available) {
                    return sprintf(__('Stock insuffisant : %d disponible(s) à cet emplacement.', 'dsimanager'), $available);
                }
            }
            if ($type === self::TYPE_ADJUST && (int) $this->fields['stocklocations_id_to'] <= 0) {
                return __('Choisissez et enregistrez d\'abord l\'emplacement à inventorier.', 'dsimanager');
            }
            $line += [
                'plugin_dsimanager_stockarticles_id' => $articles_id,
                'label'                             => $articles[$articles_id]['name'],
                'quantity'                          => $qty,
            ];
        } else {
            return __('Ligne invalide.', 'dsimanager');
        }

        $line['comment'] = trim((string) ($input['line_comment'] ?? ''));
        $DB->insert(StockOrderLine::getTable(), $line);
        return '';
    }

    private function hasIdentifierInLines(string $itemtype, string $identifier): bool
    {
        global $DB;
        return $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => StockOrderLine::getTable(),
            'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID(), 'itemtype' => $itemtype, 'serial' => $identifier],
        ])->current()['cpt'] > 0;
    }

    public function deleteLine(int $lines_id): string
    {
        global $DB;
        if (!$this->isDraft()) {
            return __('Cette commande n\'est plus un brouillon.', 'dsimanager');
        }
        $row = $DB->request([
            'FROM'  => StockOrderLine::getTable(),
            'WHERE' => ['id' => $lines_id, 'plugin_dsimanager_stockorders_id' => $this->getID()],
        ])->current();
        $DB->delete(StockOrderLine::getTable(), [
            'id'                              => $lines_id,
            'plugin_dsimanager_stockorders_id' => $this->getID(),
        ]);
        if ($row && (string) $row['itemtype'] === 'Line' && (int) $row['items_id'] > 0) {
            $DB->update(StockOrderLine::getTable(), ['lines_id' => 0], [
                'plugin_dsimanager_stockorders_id' => $this->getID(),
                'lines_id'                        => (int) $row['items_id'],
            ]);
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Valide le brouillon : applique tous les mouvements en UNE transaction.
     * Au moindre problème (stock insuffisant, actif déplacé entre-temps,
     * doublon...), rien n'est appliqué. Renvoie la liste des erreurs
     * (vide = succès).
     */
    public function validateOrder(): array
    {
        global $DB;

        if (!$this->isDraft()) {
            return [__('Cette commande n\'est plus un brouillon.', 'dsimanager')];
        }
        $type  = (int) $this->fields['type'];
        $from  = (int) $this->fields['stocklocations_id_from'];
        $to    = (int) $this->fields['stocklocations_id_to'];
        $lines = $this->getLines();

        $errors = [];
        if (!count($lines)) {
            $errors[] = __('La commande ne contient aucune ligne.', 'dsimanager');
        }
        if (self::needsFrom($type) && $from <= 0) {
            $errors[] = __('Emplacement d\'origine manquant.', 'dsimanager');
        }
        if (self::needsTo($type) && $to <= 0) {
            $errors[] = __('Emplacement de destination manquant.', 'dsimanager');
        }
        if ($type === self::TYPE_DISPOSAL && (int) ($this->fields['plugin_dsimanager_stockdisposalreasons_id'] ?? 0) <= 0) {
            $errors[] = __('Indiquez le motif de sortie du parc.', 'dsimanager');
        }
        if ($type === self::TYPE_DISPOSAL && $from <= 0) {
            foreach ($lines as $l) {
                if ((int) $l['kind'] === StockOrderLine::KIND_QTY) {
                    $errors[] = __('Des articles en quantité sont supprimés : indiquez l\'emplacement d\'origine.', 'dsimanager');
                    break;
                }
            }
        }
        if ($type === self::TYPE_TRANSFER && $from === $to) {
            $errors[] = __('Les emplacements d\'origine et de destination doivent être différents.', 'dsimanager');
        }
        if (count($errors)) {
            return $errors;
        }

        $cfg = Stock::getConfig();
        $DB->beginTransaction();
        try {
            foreach ($lines as $line) {
                if ((int) $line['kind'] === StockOrderLine::KIND_UNIT) {
                    $this->applyUnitLine($line, $type, $from, $to, $cfg);
                } else {
                    $this->applyQtyLine($line, $type, $from, $to);
                }
            }
            if ($type === self::TYPE_OUT) {
                $this->applyLineLinks($lines);
            }
            $DB->update(self::getTable(), [
                'status'             => self::STATUS_VALIDATED,
                'users_id_validator' => Session::getLoginUserID(),
                'date_validation'    => date('Y-m-d H:i:s'),
            ], ['id' => $this->getID()]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            return [$e->getMessage()];
        }
        $this->getFromDB($this->getID());
        return [];
    }

    private function applyUnitLine(array $line, int $type, int $from, int $to, array $cfg): void
    {
        global $DB;
        $itemtype = (string) $line['itemtype'];
        if (!isset(Stock::getUnitItemtypes()[$itemtype])) {
            throw new RuntimeException(sprintf(__('Type d\'actif non géré : %s', 'dsimanager'), $itemtype));
        }
        $items_id = (int) $line['items_id'];

        if ($type === self::TYPE_IN) {
            $identifier = trim((string) $line['serial']);
            if ($items_id <= 0) {
                // Recherche à nouveau au moment de la validation : l'actif
                // a pu être créé dans l'inventaire depuis le brouillon.
                $found = Stock::findAssetsByIdentifier($identifier);
                foreach ($found as $f) {
                    if ($f['itemtype'] !== $itemtype) {
                        throw new RuntimeException(sprintf(__('Le numéro %1$s existe déjà sur un autre type d\'actif (%2$s).', 'dsimanager'), $identifier, $f['itemtype']::getTypeName(1)));
                    }
                }
                [$items_id, $dup_error] = Stock::pickExistingAsset($found, $identifier);
                if ($dup_error !== '') {
                    throw new RuntimeException($dup_error);
                }
            }

            $asset = new $itemtype();
            $loc_native = Stock::getNativeLocationId($to);
            if ($items_id > 0) {
                if (!$asset->getFromDB($items_id)) {
                    throw new RuntimeException(sprintf(__('Actif introuvable : %s', 'dsimanager'), $line['label']));
                }
                if (!empty($asset->fields['is_deleted'])) {
                    $asset->restore(['id' => $items_id]);
                }
            } else {
                // Actif inconnu : création dans l'inventaire GLPI, une seule
                // fois (règle "jamais de doublon").
                $create = [
                    'name'        => (string) $line['label'],
                    'entities_id' => (int) ($this->fields['entities_id'] ?? 0),
                    Stock::getIdentifierField($itemtype) => $identifier,
                ];
                if ($DB->fieldExists($itemtype::getTable(), 'otherserial') && (string) $line['otherserial'] !== '') {
                    $create['otherserial'] = (string) $line['otherserial'];
                }
                $items_id = (int) $asset->add($create);
                if ($items_id <= 0) {
                    throw new RuntimeException(sprintf(__('Création de l\'actif impossible : %s', 'dsimanager'), $line['label']));
                }
            }
            if (Stock::getUnitLocation($itemtype, $items_id) > 0) {
                throw new RuntimeException(sprintf(__('%s est déjà en stock.', 'dsimanager'), $line['label']));
            }
            $this->updateAsset($itemtype, $items_id, [
                'states_id'    => $cfg['state_in'],
                'locations_id' => $loc_native,
                'users_id'     => 0,
            ]);
            $DB->insert('glpi_plugin_dsimanager_stockunits', [
                'itemtype'                           => $itemtype,
                'items_id'                           => $items_id,
                'plugin_dsimanager_stocklocations_id' => $to,
                'date_in'                            => date('Y-m-d H:i:s'),
            ]);
        } elseif ($type === self::TYPE_DISPOSAL) {
            $asset = new $itemtype();
            if ($items_id <= 0 || !$asset->getFromDB($items_id)) {
                throw new RuntimeException(sprintf(__('Actif introuvable : %s', 'dsimanager'), $line['label']));
            }
            if (!empty($asset->fields['is_deleted'])) {
                throw new RuntimeException(sprintf(__('%s est déjà à la corbeille.', 'dsimanager'), $line['label']));
            }
            // Retiré du stock s'il y était (quel que soit l'emplacement).
            $DB->delete('glpi_plugin_dsimanager_stockunits', ['itemtype' => $itemtype, 'items_id' => $items_id]);
            // Instantané figé AVANT la mise à la corbeille.
            $DB->update(StockOrderLine::getTable(), StockOrderLine::snapshotAsset($itemtype, $items_id), ['id' => (int) $line['id']]);
            $this->updateAsset($itemtype, $items_id, [
                'states_id' => $cfg['state_disposed'],
                'users_id'  => 0,
            ]);
            // Corbeille GLPI (pas de suppression définitive) : restaurable.
            if (!$asset->delete(['id' => $items_id])) {
                throw new RuntimeException(sprintf(__('Mise à la corbeille impossible : %s', 'dsimanager'), $line['label']));
            }
            return;
        } else {
            if ($items_id <= 0 || Stock::getUnitLocation($itemtype, $items_id) !== $from) {
                throw new RuntimeException(sprintf(__('%s n\'est plus en stock à l\'emplacement d\'origine.', 'dsimanager'), $line['label']));
            }
            if ($type === self::TYPE_OUT) {
                $DB->delete('glpi_plugin_dsimanager_stockunits', ['itemtype' => $itemtype, 'items_id' => $items_id]);
                $changes = ['states_id' => $cfg['state_out']];
                // Localisation de la commande (souvent celle du ticket), sinon
                // le réglage par défaut du module (ex. "Siège").
                $loc_out = (int) ($this->fields['locations_id'] ?? 0) > 0 ? (int) $this->fields['locations_id'] : $cfg['location_out'];
                if ($loc_out > 0) {
                    $changes['locations_id'] = $loc_out;
                }
                if ((int) $this->fields['users_id_beneficiary'] > 0) {
                    $changes['users_id'] = (int) $this->fields['users_id_beneficiary'];
                }
                $this->updateAsset($itemtype, $items_id, $changes);
            } else { // transfert
                $DB->update('glpi_plugin_dsimanager_stockunits', [
                    'plugin_dsimanager_stocklocations_id' => $to,
                ], ['itemtype' => $itemtype, 'items_id' => $items_id]);
                $this->updateAsset($itemtype, $items_id, ['locations_id' => Stock::getNativeLocationId($to)]);
            }
        }

        // Instantané définitif de la ligne (valeurs de l'actif au moment
        // exact du mouvement).
        $DB->update(StockOrderLine::getTable(), ['items_id' => $items_id] + StockOrderLine::snapshotAsset($itemtype, $items_id), ['id' => (int) $line['id']]);
    }

    /**
     * Sortie : crée le lien natif GLPI entre chaque téléphone et la ligne
     * téléphonique choisie (onglet "Lignes" de l'appareil). Un lien
     * identique déjà présent n'est pas dupliqué.
     */
    private function applyLineLinks(array $lines): void
    {
        global $DB;
        foreach ($lines as $line) {
            $lines_id = (int) $line['lines_id'];
            if ((int) $line['kind'] !== StockOrderLine::KIND_UNIT || $lines_id <= 0 || (string) $line['itemtype'] === 'Line') {
                continue;
            }
            $where = ['lines_id' => $lines_id, 'itemtype' => (string) $line['itemtype'], 'items_id' => (int) $line['items_id']];
            if ($DB->request(['COUNT' => 'cpt', 'FROM' => \Item_Line::getTable(), 'WHERE' => $where])->current()['cpt'] > 0) {
                continue;
            }
            $link = new \Item_Line();
            if (!$link->add($where)) {
                throw new RuntimeException(sprintf(__('Association de la ligne téléphonique impossible (%s).', 'dsimanager'), $line['label']));
            }
        }
    }

    /**
     * Brouillon de sortie : associe (ou dissocie, $lines_id = 0) une ligne
     * téléphonique de la commande à un appareil de la commande.
     */
    public function setLineLink(int $orderlines_id, int $lines_id): string
    {
        global $DB;
        if (!$this->isDraft() || (int) $this->fields['type'] !== self::TYPE_OUT) {
            return __('Association possible uniquement sur une sortie en brouillon.', 'dsimanager');
        }
        $target = $DB->request([
            'FROM'  => StockOrderLine::getTable(),
            'WHERE' => ['id' => $orderlines_id, 'plugin_dsimanager_stockorders_id' => $this->getID(), 'kind' => StockOrderLine::KIND_UNIT],
        ])->current();
        if (!$target || (string) $target['itemtype'] === 'Line') {
            return __('Ligne de commande invalide.', 'dsimanager');
        }
        if ($lines_id > 0) {
            $in_order = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => StockOrderLine::getTable(),
                'WHERE' => ['plugin_dsimanager_stockorders_id' => $this->getID(), 'itemtype' => 'Line', 'items_id' => $lines_id],
            ])->current()['cpt'] > 0;
            if (!$in_order) {
                return __('La ligne téléphonique doit faire partie de la même sortie.', 'dsimanager');
            }
            // Une ligne n'est associée qu'à un seul appareil de la commande.
            $DB->update(StockOrderLine::getTable(), ['lines_id' => 0], [
                'plugin_dsimanager_stockorders_id' => $this->getID(),
                'lines_id'                        => $lines_id,
            ]);
        }
        $DB->update(StockOrderLine::getTable(), ['lines_id' => max(0, $lines_id)], ['id' => $orderlines_id]);
        return '';
    }

    private function applyQtyLine(array $line, int $type, int $from, int $to): void
    {
        global $DB;
        $art = (int) $line['plugin_dsimanager_stockarticles_id'];
        $qty = (int) $line['quantity'];
        $lbl = (string) $line['label'];
        if ($type === self::TYPE_ADJUST) {
            $previous = Stock::setLevel($art, $to, $qty);
            $DB->update(StockOrderLine::getTable(), ['expected_quantity' => $previous], ['id' => (int) $line['id']]);
            return;
        }

        // Kit : on décompte ses composants. La composition est figée sur la
        // ligne à la validation (une commande corrective reprend celle de la
        // commande d'origine, même si le kit a été modifié depuis).
        $parts = [$art => 1];
        if (Stock::isBundle($art)) {
            $snapshot = json_decode((string) ($line['components'] ?? ''), true);
            $parts = is_array($snapshot) && count($snapshot) ? $snapshot : Stock::getBundleComponents($art);
            if (!count($parts)) {
                throw new RuntimeException(sprintf(__('Le kit « %s » n\'a aucun composant.', 'dsimanager'), $lbl));
            }
            $DB->update(StockOrderLine::getTable(), ['components' => json_encode($parts)], ['id' => (int) $line['id']]);
        }
        $names = Stock::getArticles(false);
        foreach ($parts as $cid => $per) {
            $cid = (int) $cid;
            $n = $qty * (int) $per;
            $clbl = $cid === $art ? $lbl : ($lbl . ' : ' . ($names[$cid]['name'] ?? ('#' . $cid)));
            switch ($type) {
                case self::TYPE_IN:
                    Stock::changeLevel($cid, $to, $n, $clbl);
                    break;
                case self::TYPE_OUT:
                case self::TYPE_DISPOSAL:
                    Stock::changeLevel($cid, $from, -$n, $clbl);
                    break;
                case self::TYPE_TRANSFER:
                    Stock::changeLevel($cid, $from, -$n, $clbl);
                    Stock::changeLevel($cid, $to, $n, $clbl);
                    break;
            }
        }
    }

    /**
     * Met à jour un actif natif via update() : GLPI journalise alors le
     * changement dans l'historique natif de l'actif. Les champs absents de
     * la table de l'actif (ex. users_id sur un type qui n'en a pas) sont
     * ignorés ; les groupes ne sont jamais touchés (le groupe "Tablette"
     * doit être conservé).
     */
    private function updateAsset(string $itemtype, int $items_id, array $changes): void
    {
        global $DB;
        $table = $itemtype::getTable();
        $input = ['id' => $items_id];
        foreach ($changes as $field => $value) {
            if ($field === 'states_id' && (int) $value <= 0) {
                continue; // statut non configuré : on ne touche pas
            }
            if ($DB->fieldExists($table, $field)) {
                $input[$field] = $value;
            }
        }
        if (count($input) === 1) {
            return;
        }
        $asset = new $itemtype();
        if (!$asset->update($input)) {
            throw new RuntimeException(sprintf(__('Mise à jour de l\'actif impossible (%1$s #%2$d).', 'dsimanager'), $itemtype::getTypeName(1), $items_id));
        }
    }

    // ------------------------------------------------------------------
    // Annulation d'un brouillon, commande corrective
    // ------------------------------------------------------------------

    public function cancelDraft(): bool
    {
        global $DB;
        if (!$this->isDraft()) {
            return false;
        }
        $DB->update(self::getTable(), ['status' => self::STATUS_CANCELLED], ['id' => $this->getID()]);
        return true;
    }

    /**
     * Crée, en brouillon, le mouvement inverse d'une commande validée
     * (entrée <-> sortie, transfert dans l'autre sens). Les lignes sont
     * recopiées et peuvent être ajustées avant validation (ex. ne corriger
     * qu'une ligne erronée). Renvoie l'ID du brouillon ou un message d'erreur.
     */
    public function createCorrection()
    {
        global $DB;
        if ((int) $this->fields['status'] !== self::STATUS_VALIDATED) {
            return __('Seule une commande validée peut être corrigée.', 'dsimanager');
        }
        $type = (int) $this->fields['type'];
        $from = (int) $this->fields['stocklocations_id_from'];
        $to   = (int) $this->fields['stocklocations_id_to'];
        switch ($type) {
            case self::TYPE_IN:
                $new = ['type' => self::TYPE_OUT, 'stocklocations_id_from' => $to];
                break;
            case self::TYPE_OUT:
                $new = ['type' => self::TYPE_IN, 'stocklocations_id_to' => $from];
                break;
            case self::TYPE_TRANSFER:
                $new = ['type' => self::TYPE_TRANSFER, 'stocklocations_id_from' => $to, 'stocklocations_id_to' => $from];
                break;
            case self::TYPE_DISPOSAL:
                // Annulation d'une suppression = entrée en stock : les actifs
                // sont restaurés depuis la corbeille (voir applyUnitLine IN).
                $new = ['type' => self::TYPE_IN, 'stocklocations_id_to' => $from > 0 ? $from : (int) (array_key_first(Stock::getLocations(true)) ?? 0)];
                break;
            default:
                return __('Un ajustement d\'inventaire se corrige par un nouvel ajustement (nouveau comptage).', 'dsimanager');
        }
        if (!self::canDoOnType($new['type'], CREATE)) {
            return __('Vous n\'avez pas le droit de préparer ce type de commande.', 'dsimanager');
        }

        $draft = new self();
        $draft_id = $draft->add($new + [
            'tickets_id'         => (int) $this->fields['tickets_id'],
            'date_order'         => date('Y-m-d'),
            'users_id_requester' => (int) $this->fields['users_id_requester'],
            'users_id_executor'  => Session::getLoginUserID(),
            'comment'            => sprintf(__('Correction de la commande %s', 'dsimanager'), $this->getReference()),
        ]);
        if (!$draft_id) {
            return __('Création de la commande corrective impossible.', 'dsimanager');
        }
        $DB->update(self::getTable(), ['corrects_id' => $this->getID()], ['id' => $draft_id]);

        foreach ($this->getLines() as $line) {
            unset($line['id']);
            $line['plugin_dsimanager_stockorders_id'] = $draft_id;
            $line['expected_quantity'] = null;
            $DB->insert(StockOrderLine::getTable(), $line);
        }
        return (int) $draft_id;
    }

    /**
     * Commandes correctives liées à celle-ci.
     */
    public function getCorrections(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'type', 'status'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['corrects_id' => $this->getID()],
        ]), false);
    }
}
