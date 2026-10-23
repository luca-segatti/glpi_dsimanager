<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;
use Html;
use Session;

/**
 * Module Stock (Lot 4) - Profil de dotation : liste type du matériel à
 * remettre pour un profil (ex. "Commercial terrain" = 1 téléphone,
 * 1 tablette, 1 coque, 2 chargeurs).
 *  - articles en quantité : ajoutés automatiquement au brouillon ;
 *  - actifs unitaires : "à prévoir", le technicien choisit les appareils
 *    (un rappel affiche ce qui manque encore sur la commande).
 * Utilisé par la destination de formulaire "Commande de stock" et par le
 * bouton "Appliquer un profil" des brouillons.
 */
class StockKit extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    private const ITEMS_TABLE = 'glpi_plugin_dsimanager_stockkititems';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockkits';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Profil de dotation', 'Profils de dotation', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-briefcase';
    }

    // ------------------------------------------------------------------
    // Types unitaires d'un profil ("Phone:tablet" = téléphone du groupe tablette)
    // ------------------------------------------------------------------

    public static function getUnitTypeChoices(): array
    {
        $out = [];
        foreach (Stock::getUnitItemtypes() as $itemtype => $label) {
            if ($itemtype === 'Phone') {
                $out['Phone'] = __('Téléphone', 'dsimanager');
                $out['Phone:tablet'] = __('Tablette', 'dsimanager');
                continue;
            }
            $out[$itemtype] = $label;
        }
        return $out;
    }

    public static function getUnitTypeKey(string $itemtype, int $items_id): string
    {
        return ($itemtype === 'Phone' && Stock::isTablet($itemtype, $items_id)) ? 'Phone:tablet' : $itemtype;
    }

    public static function getUnitTypeKeyLabel(string $key): string
    {
        return self::getUnitTypeChoices()[$key] ?? $key;
    }

    // ------------------------------------------------------------------
    // Contenu
    // ------------------------------------------------------------------

    public static function getActiveKits(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name ASC']) as $row) {
            $out[(int) $row['id']] = $row['name'];
        }
        return $out;
    }

    /**
     * Profil actif dont le nom correspond au texte (casse et espaces ignorés).
     */
    public static function findByName(string $text): int
    {
        $norm = static fn(string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        $needle = $norm(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($needle === '') {
            return 0;
        }
        foreach (self::getActiveKits() as $id => $name) {
            if ($norm((string) $name) === $needle) {
                return $id;
            }
        }
        return 0;
    }

    public static function getItems(int $kits_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => self::ITEMS_TABLE,
            'WHERE' => ['plugin_dsimanager_stockkits_id' => $kits_id],
            'ORDER' => ['kind ASC', 'id ASC'],
        ]), false);
    }

    public static function addItem(int $kits_id, array $input): string
    {
        global $DB;
        $kind = (int) ($input['kind'] ?? 0);
        $qty  = max(1, (int) ($input['quantity'] ?? 1));
        $row  = ['plugin_dsimanager_stockkits_id' => $kits_id, 'kind' => $kind, 'quantity' => $qty];
        if ($kind === StockOrderLine::KIND_UNIT) {
            $key = (string) ($input['unit_type'] ?? '');
            if (!isset(self::getUnitTypeChoices()[$key])) {
                return __('Type d\'actif invalide.', 'dsimanager');
            }
            $row['unit_type'] = $key;
            $where = ['plugin_dsimanager_stockkits_id' => $kits_id, 'kind' => $kind, 'unit_type' => $key];
        } elseif ($kind === StockOrderLine::KIND_QTY) {
            $aid = (int) ($input['articles_id'] ?? 0);
            if (!isset(Stock::getArticles(true)[$aid])) {
                return __('Choisissez un article.', 'dsimanager');
            }
            $row['plugin_dsimanager_stockarticles_id'] = $aid;
            $where = ['plugin_dsimanager_stockkits_id' => $kits_id, 'kind' => $kind, 'plugin_dsimanager_stockarticles_id' => $aid];
        } else {
            return __('Ligne invalide.', 'dsimanager');
        }
        // Même élément déjà présent : on remplace la quantité.
        if ($DB->request(['COUNT' => 'cpt', 'FROM' => self::ITEMS_TABLE, 'WHERE' => $where])->current()['cpt'] > 0) {
            $DB->update(self::ITEMS_TABLE, ['quantity' => $qty], $where);
        } else {
            $DB->insert(self::ITEMS_TABLE, $row);
        }
        return '';
    }

    public static function deleteItem(int $kits_id, int $id): void
    {
        global $DB;
        $DB->delete(self::ITEMS_TABLE, ['id' => $id, 'plugin_dsimanager_stockkits_id' => $kits_id]);
    }

    public function cleanDBonPurge()
    {
        global $DB;
        parent::cleanDBonPurge();
        $DB->delete(self::ITEMS_TABLE, ['plugin_dsimanager_stockkits_id' => (int) $this->getID()]);
    }

    /**
     * Résumé lisible : "1 × Téléphone, 1 × Coque + verre, 2 × Chargeur".
     */
    public static function getSummary(int $kits_id): string
    {
        $articles = Stock::getArticles(false);
        $parts = [];
        foreach (self::getItems($kits_id) as $it) {
            $label = (int) $it['kind'] === StockOrderLine::KIND_UNIT
                ? self::getUnitTypeKeyLabel((string) $it['unit_type'])
                : ($articles[(int) $it['plugin_dsimanager_stockarticles_id']]['name'] ?? '?');
            $parts[] = (int) $it['quantity'] . ' × ' . $label;
        }
        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------
    // Application à une commande
    // ------------------------------------------------------------------

    /**
     * Ajoute les articles du profil au brouillon (sans doublon : un article
     * déjà présent n'est pas modifié) et mémorise le profil sur la commande.
     * Renvoie la liste des problèmes rencontrés (stock insuffisant...).
     */
    public static function applyToOrder(StockOrder $order, int $kits_id): array
    {
        global $DB;
        if (!$order->isDraft()) {
            return [__('Cette commande n\'est plus un brouillon.', 'dsimanager')];
        }
        $kit = new self();
        if (!$kit->getFromDB($kits_id)) {
            return [__('Profil de dotation introuvable.', 'dsimanager')];
        }
        $DB->update(StockOrder::getTable(), ['plugin_dsimanager_stockkits_id' => $kits_id], ['id' => $order->getID()]);
        $order->fields['plugin_dsimanager_stockkits_id'] = $kits_id;

        $present = [];
        foreach ($order->getLines() as $l) {
            if ((int) $l['kind'] === StockOrderLine::KIND_QTY) {
                $present[(int) $l['plugin_dsimanager_stockarticles_id']] = true;
            }
        }
        $issues = [];
        foreach (self::getItems($kits_id) as $it) {
            if ((int) $it['kind'] !== StockOrderLine::KIND_QTY || isset($present[(int) $it['plugin_dsimanager_stockarticles_id']])) {
                continue;
            }
            $err = $order->addLine([
                'kind'        => StockOrderLine::KIND_QTY,
                'articles_id' => (int) $it['plugin_dsimanager_stockarticles_id'],
                'quantity'    => (int) $it['quantity'],
            ]);
            if ($err !== '') {
                $articles = Stock::getArticles(false);
                $issues[] = ($articles[(int) $it['plugin_dsimanager_stockarticles_id']]['name'] ?? '?') . ' : ' . $err;
            }
        }
        return $issues;
    }

    /**
     * Avancement d'une commande par rapport à son profil :
     * liste de ['label', 'expected', 'present'] (unitaires et articles).
     */
    public static function getProgress(StockOrder $order): array
    {
        $kits_id = (int) ($order->fields['plugin_dsimanager_stockkits_id'] ?? 0);
        if ($kits_id <= 0) {
            return [];
        }
        $units = [];
        $qty = [];
        foreach ($order->getLines() as $l) {
            if ((int) $l['kind'] === StockOrderLine::KIND_UNIT) {
                $key = self::getUnitTypeKey((string) $l['itemtype'], (int) $l['items_id']);
                $units[$key] = ($units[$key] ?? 0) + 1;
            } else {
                $aid = (int) $l['plugin_dsimanager_stockarticles_id'];
                $qty[$aid] = ($qty[$aid] ?? 0) + (int) $l['quantity'];
            }
        }
        $articles = Stock::getArticles(false);
        $out = [];
        foreach (self::getItems($kits_id) as $it) {
            if ((int) $it['kind'] === StockOrderLine::KIND_UNIT) {
                $key = (string) $it['unit_type'];
                $out[] = ['label' => self::getUnitTypeKeyLabel($key), 'expected' => (int) $it['quantity'], 'present' => $units[$key] ?? 0, 'unit' => true];
            } else {
                $aid = (int) $it['plugin_dsimanager_stockarticles_id'];
                $out[] = ['label' => $articles[$aid]['name'] ?? '?', 'expected' => (int) $it['quantity'], 'present' => $qty[$aid] ?? 0, 'unit' => false];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Formulaire
    // ------------------------------------------------------------------

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nom du profil', 'dsimanager') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name'], 'required' => true]) . "</td>";
        echo "<td>" . __('Actif', 'dsimanager') . "</td>";
        echo "<td><input type='hidden' name='is_active' value='0'><input type='checkbox' name='is_active' value='1'"
           . (($this->isNewID($ID) || $this->fields['is_active']) ? ' checked' : '') . "></td>";
        echo "</tr>";
        echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted small'>"
           . __('Pour un formulaire : le nom du profil doit correspondre exactement au libellé de la réponse (ex. option « Commercial terrain » d\'une liste déroulante).', 'dsimanager')
           . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __('Commentaire', 'dsimanager') . "</td><td colspan='3'>";
        Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'], 'rows' => 2]);
        echo "</td></tr>";

        $this->showFormButtons($options);

        if ($this->isNewID($ID)) {
            echo "<p class='text-muted'>" . __('Enregistrez le profil pour y ajouter du matériel.', 'dsimanager') . "</p>";
            return true;
        }

        // Contenu du profil : formulaires séparés, après la fermeture du
        // formulaire principal (pas de formulaires imbriqués).
        $kid = (int) $ID;
        $self = \Plugin::getWebDir('dsimanager') . '/front/stockkit.form.php';
        $canedit = Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
        $articles = Stock::getArticles(false);
        echo "<div class='card mt-3'><div class='card-body'>";
        echo "<h3>" . __('Matériel du profil', 'dsimanager') . "</h3>";
        $items = self::getItems($kid);
        if (!count($items)) {
            echo "<p class='text-muted'>" . __('Aucun matériel pour l\'instant.', 'dsimanager') . "</p>";
        } else {
            echo "<table class='table table-sm align-middle w-auto'><thead><tr><th>" . __('Matériel', 'dsimanager') . "</th><th>" . __('Gestion', 'dsimanager')
               . "</th><th class='text-end'>" . __('Quantité', 'dsimanager') . "</th>" . ($canedit ? '<th></th>' : '') . "</tr></thead><tbody>";
            foreach ($items as $it) {
                $is_unit = (int) $it['kind'] === StockOrderLine::KIND_UNIT;
                $label = $is_unit ? self::getUnitTypeKeyLabel((string) $it['unit_type']) : ($articles[(int) $it['plugin_dsimanager_stockarticles_id']]['name'] ?? '?');
                echo "<tr><td>" . htmlspecialchars($label) . "</td><td class='text-muted small'>"
                   . ($is_unit ? __('Actif unitaire : choisi par le technicien', 'dsimanager') : __('Article : ajouté automatiquement', 'dsimanager'))
                   . "</td><td class='text-end'>" . (int) $it['quantity'] . "</td>";
                if ($canedit) {
                    echo "<td><form method='post' action='$self' style='margin:0'><input type='hidden' name='id' value='$kid'>"
                       . "<button type='submit' name='delete_kit_item' value='" . (int) $it['id'] . "' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i></button>";
                    Html::closeForm();
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
        if ($canedit) {
            echo "<div class='row'>";
            echo "<div class='col-md-6'><form method='post' action='$self' class='border rounded p-2'><input type='hidden' name='id' value='$kid'><input type='hidden' name='kind' value='" . StockOrderLine::KIND_UNIT . "'>";
            echo "<label class='form-label'>" . __('Actif unitaire', 'dsimanager') . "</label><div class='d-flex gap-2'>";
            echo "<select name='unit_type' class='form-select'>";
            foreach (self::getUnitTypeChoices() as $k => $l) {
                echo "<option value=\"" . htmlspecialchars($k) . "\">" . htmlspecialchars($l) . "</option>";
            }
            echo "</select><input type='number' name='quantity' min='1' value='1' class='form-control' style='width:90px'>";
            echo "<button type='submit' name='add_kit_item' value='1' class='btn btn-success'><i class='ti ti-plus'></i></button></div>";
            Html::closeForm();
            echo "</div>";
            echo "<div class='col-md-6'><form method='post' action='$self' class='border rounded p-2'><input type='hidden' name='id' value='$kid'><input type='hidden' name='kind' value='" . StockOrderLine::KIND_QTY . "'>";
            echo "<label class='form-label'>" . __('Article (quantité)', 'dsimanager') . "</label><div class='d-flex gap-2'>";
            echo "<select name='articles_id' class='form-select'>";
            foreach (Stock::getArticles(true) as $aid => $a) {
                echo "<option value='$aid'>" . htmlspecialchars($a['name']) . "</option>";
            }
            echo "</select><input type='number' name='quantity' min='1' value='1' class='form-control' style='width:90px'>";
            echo "<button type='submit' name='add_kit_item' value='1' class='btn btn-success'><i class='ti ti-plus'></i></button></div>";
            Html::closeForm();
            echo "</div></div>";
        }
        echo "</div></div>";
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = [
            'id'       => 900,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif', 'dsimanager'),
            'datatype' => 'bool',
        ];
        return $tab;
    }
}
