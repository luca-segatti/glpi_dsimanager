<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;
use Html;

/**
 * Module Stock - Article géré en quantité (souris, câbles, coques...).
 * Catalogue propre au plugin : ces articles ne sont pas des actifs GLPI.
 */
class StockArticle extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockarticles';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Article (stock)', 'Articles (stock)', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-box';
    }

    public function showForm($ID, array $options = [])
    {
        global $DB;
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $categories = iterator_to_array($DB->request(['FROM' => StockArticleCategory::getTable(), 'ORDER' => 'name ASC']));

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Désignation', 'dsimanager') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name'], 'required' => true]) . "</td>";
        echo "<td>" . __('Catégorie', 'dsimanager') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('plugin_dsimanager_stockarticlecategories_id', array_column($categories, 'name', 'id'), [
            'value'               => $this->fields['plugin_dsimanager_stockarticlecategories_id'] ?? 0,
            'display_emptychoice' => true,
        ]);
        echo "</td>";
        echo "</tr>";

        // Type : article simple ou kit (figé dès que l'article a servi dans
        // une commande, pour garder un historique cohérent).
        $is_bundle = !empty($this->fields['is_bundle']);
        $locked = !$this->isNewID($ID) && $this->isUsedInOrders();
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Type', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        if ($locked) {
            echo "<input type='hidden' name='is_bundle' value='" . ($is_bundle ? 1 : 0) . "'>";
            echo $is_bundle ? __('Kit', 'dsimanager') : __('Article simple', 'dsimanager');
            echo " <span class='text-muted small'>" . __('(non modifiable : article déjà utilisé dans des commandes)', 'dsimanager') . "</span>";
        } else {
            echo "<label class='me-3'><input type='radio' name='is_bundle' value='0'" . (!$is_bundle ? ' checked' : '') . "> " . __('Article simple (stock propre)', 'dsimanager') . "</label>";
            echo "<label><input type='radio' name='is_bundle' value='1'" . ($is_bundle ? ' checked' : '') . "> " . __('Kit (composé d\'autres articles, décomptés en une fois)', 'dsimanager') . "</label>";
        }
        if ($is_bundle) {
            echo "<p class='text-muted small mb-0'>" . __('Un kit apparaît dans la catégorie « Kits » ; sa catégorie ci-dessus est ignorée. Sa disponibilité est calculée à partir du stock de ses composants.', 'dsimanager') . "</p>";
        }
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Phrase sur le bon', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        echo Html::input('doc_phrase', ['value' => $this->fields['doc_phrase'] ?? '', 'style' => 'width:100%']);
        echo "<p class='text-muted' style='margin:0;'>" . __('Ligne affichée sur le bon de remise / reprise (ex. « Une coque et verre de protection pour protéger le téléphone en cas de choc. »). Vide : la désignation est utilisée.', 'dsimanager') . "</p>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Actif', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        echo "<input type='hidden' name='is_active' value='0'>";
        echo "<input type='checkbox' name='is_active' value='1'" . (($this->isNewID($ID) || $this->fields['is_active']) ? ' checked' : '') . ">";
        echo " <span class='text-muted'>" . __('Décoché : n\'est plus proposé pour de nouvelles commandes (l\'historique est conservé).', 'dsimanager') . "</span>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Commentaire', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'], 'rows' => 3]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);

        if (!$this->isNewID($ID) && $is_bundle) {
            $this->showBundleComposition((int) $ID);
        }
        return true;
    }

    public function isUsedInOrders(): bool
    {
        global $DB;
        return $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_dsimanager_stockorderlines',
            'WHERE' => ['plugin_dsimanager_stockarticles_id' => (int) $this->getID()],
        ])->current()['cpt'] > 0;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        if ($input === false) {
            return false;
        }
        if (array_key_exists('is_bundle', $input) && (int) $input['is_bundle'] !== (int) $this->fields['is_bundle'] && $this->isUsedInOrders()) {
            unset($input['is_bundle']);
        }
        return $input;
    }

    /**
     * Composition d'un kit : liste, ajout / retrait de composants (après la
     * fermeture du formulaire principal, pas de formulaires imbriqués).
     */
    private function showBundleComposition(int $bundle_id): void
    {
        $self = \Plugin::getWebDir('dsimanager') . '/front/stockarticle.form.php';
        $canedit = \Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
        $articles = Stock::getArticles(false);
        $components = Stock::getBundleComponents($bundle_id);
        $levels = Stock::getLevelsMap();

        echo "<div class='card mt-3'><div class='card-body'>";
        echo "<h3>" . __('Composition du kit', 'dsimanager') . "</h3>";
        if (!count($components)) {
            echo "<p class='text-warning'><i class='ti ti-alert-triangle'></i> " . __('Aucun composant : ce kit ne peut pas encore être utilisé dans une commande.', 'dsimanager') . "</p>";
        } else {
            echo "<table class='table table-sm align-middle w-auto'><thead><tr><th>" . __('Article', 'dsimanager') . "</th><th class='text-end'>" . __('Quantité par kit', 'dsimanager') . "</th>"
               . ($canedit ? '<th></th>' : '') . "</tr></thead><tbody>";
            foreach ($components as $cid => $q) {
                echo "<tr><td>" . htmlspecialchars($articles[$cid]['name'] ?? ('#' . $cid)) . "</td><td class='text-end'>" . (int) $q . "</td>";
                if ($canedit) {
                    echo "<td><form method='post' action='$self' style='margin:0'><input type='hidden' name='id' value='$bundle_id'>"
                       . "<button type='submit' name='delete_component' value='$cid' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i></button>";
                    Html::closeForm();
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
            // Disponibilité par emplacement
            $avail = [];
            foreach (Stock::getLocations(true) as $lid => $lname) {
                $avail[] = htmlspecialchars($lname) . ' : <strong>' . Stock::getBundleAvailability($bundle_id, $lid, $levels) . '</strong>';
            }
            echo "<p class='text-muted'>" . __('Kits constituables :', 'dsimanager') . " " . implode(' · ', $avail) . "</p>";
        }
        if ($canedit) {
            $choices = array_filter($articles, static fn($a, $aid) => $a['is_active'] && !$a['is_bundle'] && $aid !== $bundle_id, ARRAY_FILTER_USE_BOTH);
            echo "<form method='post' action='$self' class='d-flex flex-wrap gap-2 align-items-end'><input type='hidden' name='id' value='$bundle_id'>";
            echo "<div><label class='form-label'>" . __('Ajouter un article', 'dsimanager') . "</label><select name='component_id' class='form-select'>";
            foreach ($choices as $aid => $a) {
                echo "<option value='$aid'>" . htmlspecialchars($a['name']) . "</option>";
            }
            echo "</select></div>";
            echo "<div><label class='form-label'>" . __('Quantité par kit', 'dsimanager') . "</label><input type='number' name='component_qty' min='1' value='1' class='form-control' style='width:110px'></div>";
            echo "<button type='submit' name='add_component' value='1' class='btn btn-success'><i class='ti ti-plus'></i> " . __('Ajouter', 'dsimanager') . "</button>";
            echo "<span class='text-muted small'>" . __('Un article déjà présent voit sa quantité remplacée.', 'dsimanager') . "</span>";
            Html::closeForm();
        }
        echo "</div></div>";
    }

    public static function addComponent(int $bundle_id, int $component_id, int $qty): string
    {
        global $DB;
        $articles = Stock::getArticles(false);
        if (empty($articles[$bundle_id]['is_bundle'])) {
            return __('Cet article n\'est pas un kit.', 'dsimanager');
        }
        if (!isset($articles[$component_id]) || $articles[$component_id]['is_bundle'] || $component_id === $bundle_id) {
            return __('Composant invalide (un kit ne peut pas contenir un autre kit).', 'dsimanager');
        }
        $qty = max(1, $qty);
        $where = ['plugin_dsimanager_stockarticles_id_bundle' => $bundle_id, 'plugin_dsimanager_stockarticles_id' => $component_id];
        if ($DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stockarticlecomponents', 'WHERE' => $where])->current()['cpt'] > 0) {
            $DB->update('glpi_plugin_dsimanager_stockarticlecomponents', ['quantity' => $qty], $where);
        } else {
            $DB->insert('glpi_plugin_dsimanager_stockarticlecomponents', $where + ['quantity' => $qty]);
        }
        Stock::clearComponentsCache();
        return '';
    }

    public static function deleteComponent(int $bundle_id, int $component_id): void
    {
        global $DB;
        $DB->delete('glpi_plugin_dsimanager_stockarticlecomponents', [
            'plugin_dsimanager_stockarticles_id_bundle' => $bundle_id,
            'plugin_dsimanager_stockarticles_id'        => $component_id,
        ]);
        Stock::clearComponentsCache();
    }

    /**
     * Un article présent dans l'historique ne peut pas être supprimé
     * (désactivation à la place) : la traçabilité doit rester complète.
     */
    public function pre_deleteItem()
    {
        global $DB;
        if (!parent::pre_deleteItem()) {
            return false;
        }
        $used = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_dsimanager_stockorderlines',
            'WHERE' => ['plugin_dsimanager_stockarticles_id' => (int) $this->getID()],
        ])->current()['cpt'];
        $in_kits = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_dsimanager_stockarticlecomponents',
            'WHERE' => ['plugin_dsimanager_stockarticles_id' => (int) $this->getID()],
        ])->current()['cpt'];
        if ($in_kits > 0) {
            \Session::addMessageAfterRedirect(__('Article utilisé dans la composition d\'un kit : retirez-le du kit avant de le supprimer.', 'dsimanager'), false, ERROR);
            return false;
        }
        if ($used > 0) {
            \Session::addMessageAfterRedirect(
                __('Article présent dans l\'historique : il ne peut pas être supprimé. Décochez « Actif » pour le masquer.', 'dsimanager'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    public function cleanDBonPurge()
    {
        global $DB;
        parent::cleanDBonPurge();
        $DB->delete('glpi_plugin_dsimanager_stocklevels', ['plugin_dsimanager_stockarticles_id' => (int) $this->getID()]);
        $DB->delete('glpi_plugin_dsimanager_stockthresholds', ['plugin_dsimanager_stockarticles_id' => (int) $this->getID()]);
        $DB->delete('glpi_plugin_dsimanager_stockarticlecomponents', ['plugin_dsimanager_stockarticles_id_bundle' => (int) $this->getID()]);
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        // Datatype 'dropdown' (et non 'specific') : c'est ce qui permet à
        // GLPI de proposer une vraie liste dans "Actions > Modifier". Avec
        // 'specific' sans getSpecificValueToSelect(), GLPI affichait un champ
        // vide et envoyait '' en base -> erreur SQL (corrigé en 2.4.0).
        // getItemTypeForTable() résout bien la classe StockArticleCategory
        // (vérifié sur GLPI 11.0.11).
        $tab[] = [
            'id'       => 900,
            'table'    => StockArticleCategory::getTable(),
            'field'    => 'name',
            'name'     => __('Catégorie', 'dsimanager'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => 901,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif', 'dsimanager'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 903,
            'table'    => $this->getTable(),
            'field'    => 'is_bundle',
            'name'     => __('Kit', 'dsimanager'),
            'datatype' => 'bool',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 902,
            'table'    => $this->getTable(),
            'field'    => 'doc_phrase',
            'name'     => __('Phrase sur le bon', 'dsimanager'),
            'datatype' => 'string',
        ];
        return $tab;
    }
}
