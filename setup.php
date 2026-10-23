<?php
/**
 * Plugin Gestion DSI
 * Fichier setup.php : point d'entrée obligatoire, chargé par GLPI au boot.
 */

define('PLUGIN_DSIMANAGER_VERSION', '1.0.0');

// Version minimale et maximale de GLPI compatibles (bornes inclusive / exclusive)
define('PLUGIN_DSIMANAGER_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_DSIMANAGER_MAX_GLPI_VERSION', '11.99.99');

/**
 * Nom du droit utilisé pour le module TODO.
 * Un droit distinct sera ajouté par futur module (DATA, CYBER, ROADMAP...).
 */
define('PLUGIN_DSIMANAGER_RIGHT_TODO', 'plugin_dsimanager_todo');
define('PLUGIN_DSIMANAGER_RIGHT_DATA', 'plugin_dsimanager_data');
define('PLUGIN_DSIMANAGER_RIGHT_CYBER', 'plugin_dsimanager_cyber');
define('PLUGIN_DSIMANAGER_RIGHT_ROADMAP', 'plugin_dsimanager_roadmap');

/**
 * Module Stock (v2.0.0) : un droit par page, avec des niveaux fins
 * (lecture / préparer un brouillon / valider) gérés sur l'onglet Profil.
 */
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT', 'plugin_dsimanager_stock_content');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_IN', 'plugin_dsimanager_stock_in');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT', 'plugin_dsimanager_stock_out');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER', 'plugin_dsimanager_stock_transfer');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY', 'plugin_dsimanager_stock_history');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS', 'plugin_dsimanager_stock_stats');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG', 'plugin_dsimanager_stock_config');
define('PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL', 'plugin_dsimanager_stock_disposal');

// Bit de droit spécifique "Valider une commande de stock" (les bits 1 à
// 128 sont déjà utilisés par les droits standards de GLPI : READ, UPDATE,
// CREATE, DELETE, PURGE, READNOTE, UPDATENOTE, UNLOCK).
define('PLUGIN_DSIMANAGER_STOCK_VALIDATE', 1024);

/**
 * Hook exécuté très tôt au boot, avant la session.
 * On ne fait rien de spécial ici pour l'instant, mais GLPI l'appelle
 * systématiquement : on le garde en réserve pour un usage futur (API stateless, etc).
 */
function plugin_dsimanager_boot()
{
    // Rien pour l'instant.
}

/**
 * Initialisation du plugin - déclaration des hooks GLPI.
 * Appelé sur (quasi) toutes les pages si le plugin est actif.
 */
function plugin_init_dsimanager()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['dsimanager'] = true;

    // Enregistre les classes du plugin auprès du cœur GLPI.
    // Todo::class est un itemtype "classique" utilisable dans le moteur de recherche.
    Plugin::registerClass(\GlpiPlugin\Dsimanager\Todo::class, [
        'addtabon' => [], // pas d'onglet ajouté sur un objet natif pour l'instant
    ]);

    // Lien simple maison entre une tâche et des tickets (onglet dédié).
    // L'intégration native (Item_Ticket / $CFG_GLPI['ticket_types']),
    // testée puis retirée : l'onglet natif ne proposait pas de formulaire
    // d'ajout côté élément et faisait doublon avec cet onglet maison sans
    // rien apporter de plus (voir README, Dette technique).
    Plugin::registerClass(\GlpiPlugin\Dsimanager\TodoTicket::class, [
        'addtabon' => [\GlpiPlugin\Dsimanager\Todo::class],
    ]);

    // Module B : Projets DATA, avec ses étapes gérées dans un onglet dédié
    // sur la fiche projet (même technique que l'onglet Profil : addtabon).
    Plugin::registerClass(\GlpiPlugin\Dsimanager\ProjectData::class, [
        'addtabon' => [],
    ]);
    Plugin::registerClass(\GlpiPlugin\Dsimanager\ProjectDataStep::class, [
        'addtabon' => [\GlpiPlugin\Dsimanager\ProjectData::class],
    ]);

    // Module C : Projets Cybersécurité (même structure que B).
    Plugin::registerClass(\GlpiPlugin\Dsimanager\ProjectCyber::class, [
        'addtabon' => [],
    ]);
    Plugin::registerClass(\GlpiPlugin\Dsimanager\ProjectCyberStep::class, [
        'addtabon' => [\GlpiPlugin\Dsimanager\ProjectCyber::class],
    ]);

    // Module D : Roadmap. RoadmapItem n'utilise pas Search::show() (grille
    // hebdomadaire entièrement custom, pas une liste GLPI classique), mais
    // reste un itemtype standard pour bénéficier des droits/CRUD habituels.
    Plugin::registerClass(\GlpiPlugin\Dsimanager\RoadmapItem::class, [
        'addtabon' => [],
    ]);

    // Module Stock : commandes (historique) et onglet "Mouvements de stock"
    // ajouté sur les fiches des actifs natifs gérés à l'unité. Le sens
    // "classe du plugin ajoutée sur une classe native" fonctionne avec
    // registerClass() (c'est l'inverse qui échoue - voir piège n°4).
    // Onglet "Stock" sur les tickets (Lot 2) : commandes liées au ticket
    // et création d'une entrée / sortie préremplie depuis le ticket.
    Plugin::registerClass(\GlpiPlugin\Dsimanager\StockOrder::class, [
        'addtabon' => ['Ticket'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Dsimanager\StockOrderLine::class, [
        'addtabon' => ['Computer', 'Monitor', 'Phone', 'Line', 'Peripheral'],
    ]);

    // Ajoute un onglet "Gestion DSI" sur la fiche Profil pour gérer les droits.
    Plugin::registerClass(\GlpiPlugin\Dsimanager\Profile::class, [
        'addtabon' => ['Profile'],
    ]);

    // Entrée de menu de premier niveau dans le bandeau latéral.
    // Hook réel confirmé en lisant le code source de GLPI (Glpi\Plugin\Hooks::MENU_TOADD) :
    // la clé interne ('dsimanager' ci-dessous) devient la clé de la nouvelle
    // catégorie de premier niveau dans $_SESSION['glpimenu']. Chaque itemtype
    // listé dans le tableau doit fournir sa propre méthode statique
    // getMenuContent() (voir Todo::getMenuContent()) : GLPI l'appelle
    // directement pour construire le contenu de cette catégorie.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::MENU_TOADD]['dsimanager'] = [
        'dsimanager' => [
            \GlpiPlugin\Dsimanager\Todo::class,
            \GlpiPlugin\Dsimanager\ProjectData::class,
            \GlpiPlugin\Dsimanager\ProjectCyber::class,
            \GlpiPlugin\Dsimanager\RoadmapItem::class,
        ],
        // Seconde catégorie de premier niveau, propre au module Stock.
        // Html::generateMenuSession() accepte plusieurs clés par plugin
        // (boucle foreach sur $items, vérifié dans src/Html.php). Elle est
        // ensuite repositionnée entre "Parc" et "Assistance" par
        // plugin_dsimanager_redefine_menu() (hook.php).
        'dsimanager_stock' => [
            \GlpiPlugin\Dsimanager\StockContent::class,
            \GlpiPlugin\Dsimanager\StockDisposed::class,
            \GlpiPlugin\Dsimanager\StockOrder::class,
            \GlpiPlugin\Dsimanager\StockStats::class,
            \GlpiPlugin\Dsimanager\StockConfig::class,
        ],
    ];

    // Force le titre de la catégorie de premier niveau à "Gestion DSI",
    // indépendamment du titre que chaque module (Todo, puis futurs modules
    // B/C/D/E) donne à sa propre entrée de sous-menu. Confirmé que ce hook
    // s'exécute APRES la construction du menu par generateMenuSession().
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::REDEFINE_MENUS]['dsimanager'] = 'plugin_dsimanager_redefine_menu';

    // Module Stock (Lot 3) : cartes ajoutées au tableau de bord natif GLPI
    // (Accueil > Tableau de bord, mode édition > Ajouter une carte, groupe
    // "Stock"). Fonction dans hook.php, données fournies par StockStats.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::DASHBOARD_CARDS]['dsimanager'] = 'plugin_dsimanager_dashboard_cards';

    // Module Stock (Lot 4) : destination "Commande de stock (brouillon)"
    // proposée dans l'onglet Destinations des formulaires natifs GLPI 11.
    // API vérifiée dans src/Glpi/Form/Destination/FormDestinationManager.php ;
    // test d'existence pour ne pas casser GLPI si elle venait à changer.
    if (
        class_exists(\Glpi\Form\Destination\FormDestinationManager::class)
        && method_exists(\Glpi\Form\Destination\FormDestinationManager::class, 'registerPluginDestinationType')
    ) {
        \Glpi\Form\Destination\FormDestinationManager::getInstance()->registerPluginDestinationType(
            new \GlpiPlugin\Dsimanager\FormDestinationStockOrder()
        );
    }
}

/**
 * Informations affichées dans Configuration > Plugins.
 */
function plugin_version_dsimanager()
{
    return [
        'name'           => 'Gestion DSI',
        'version'        => PLUGIN_DSIMANAGER_VERSION,
        'author'         => 'Gestion DSI',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_DSIMANAGER_MIN_GLPI_VERSION,
                'max' => PLUGIN_DSIMANAGER_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => '8.1',
            ],
        ],
    ];
}

/**
 * Vérifications avant install (facultatif mais recommandé).
 */
function plugin_dsimanager_check_prerequisites()
{
    return true;
}

/**
 * Vérification de la configuration une fois le plugin activé.
 */
function plugin_dsimanager_check_config($verbose = false)
{
    return true;
}
