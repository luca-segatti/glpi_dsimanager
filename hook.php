<?php
/**
 * Plugin Gestion DSI
 * Fichier hook.php : actions déclenchées à l'installation / désinstallation.
 */

use GlpiPlugin\Dsimanager\Todo;

/**
 * Appelée par GLPI après la construction complète du menu (hook
 * Glpi\Plugin\Hooks::REDEFINE_MENUS, déclaré dans setup.php), pour imposer
 * le titre de la catégorie de premier niveau indépendamment du titre que
 * chaque module lui a donné individuellement (voir Todo::getMenuContent()).
 */
function plugin_dsimanager_redefine_menu($menu)
{
    if (isset($menu['dsimanager'])) {
        $menu['dsimanager']['title'] = __('Gestion DSI', 'dsimanager');
    }

    // Module Stock : titre forcé + repositionnement juste après "Parc"
    // (clé 'assets') et donc avant "Assistance" (clé 'helpdesk'), clés
    // confirmées dans Html::getMenuInfos(). Si 'assets' est absent (profil
    // sans accès au parc, interface simplifiée), on ne touche à rien.
    if (isset($menu['dsimanager_stock'])) {
        $menu['dsimanager_stock']['title'] = __('Stock', 'dsimanager');
        if (isset($menu['assets'])) {
            $stock = $menu['dsimanager_stock'];
            unset($menu['dsimanager_stock']);
            $reordered = [];
            foreach ($menu as $key => $value) {
                $reordered[$key] = $value;
                if ($key === 'assets') {
                    $reordered['dsimanager_stock'] = $stock;
                }
            }
            $menu = $reordered;
        }
    }
    return $menu;
}

/**
 * Hook Glpi\Plugin\Hooks::DASHBOARD_CARDS (setup.php) : ajoute les cartes
 * du module Stock au catalogue du tableau de bord natif. GLPI passe à chaque
 * plugin le résultat du plugin précédent : on le complète, sans l'écraser.
 */
function plugin_dsimanager_dashboard_cards($cards = null)
{
    $cards = is_array($cards) ? $cards : [];
    return array_merge($cards, \GlpiPlugin\Dsimanager\StockStats::getDashboardCards());
}

/**
 * Installation du plugin : création des tables et déclaration des droits.
 */
function plugin_dsimanager_install()
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $migration = new Migration(PLUGIN_DSIMANAGER_VERSION);

    // --- Table principale : les tâches (Module A - TODO) ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_todos')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_todos` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `description` text,
            `plugin_dsimanager_todocategories_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `requesting_entity` varchar(255) DEFAULT NULL,
            `plugin_dsimanager_todorequestingservices_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `status` int NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_status_change` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            KEY `plugin_dsimanager_todocategories_id` (`plugin_dsimanager_todocategories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // --- Table de liaison : techniciens assignés (many-to-many) ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_todos_users')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_todos_users` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_todos_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_todos_id`, `users_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // --- Table des catégories de tâches (dropdown classique GLPI) ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_todocategories')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_todocategories` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `comment` text,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());

        // Quelques catégories de départ, modifiables ensuite depuis l'UI.
        foreach (['Nouvel utilisateur', 'Maintenance', 'Départ collaborateur', 'Divers'] as $name) {
            $DB->insert('glpi_plugin_dsimanager_todocategories', [
                'name'        => $name,
                'entities_id' => 0,
            ]);
        }
    }

    // Service demandeur (dropdown configurable, remplace le champ texte
    // libre initial).
    if (!$DB->tableExists('glpi_plugin_dsimanager_todorequestingservices')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_todorequestingservices` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `comment` text,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());

        foreach (['Direction', 'Ressources humaines', 'Comptabilité', 'Commercial', 'Marketing', 'Logistique', 'Informatique'] as $name) {
            $DB->insert('glpi_plugin_dsimanager_todorequestingservices', [
                'name'        => $name,
                'entities_id' => 0,
            ]);
        }
    }

    $migration->executeMigration();

    // --- Module B : Projets DATA ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_projectdatas')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_projectdatas` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `description` text,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `deadline` date DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `status` int NOT NULL DEFAULT 1,
            `plugin_dsimanager_roadmappriorities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `progress_percent` int NOT NULL DEFAULT 0,
            `steps_total` int NOT NULL DEFAULT 0,
            `steps_done` int NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `deadline` (`deadline`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_dsimanager_projectdatasteps')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_projectdatasteps` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_projectdatas_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `name` varchar(255) DEFAULT NULL,
            `description` varchar(255) DEFAULT NULL,
            `percent_done` int NOT NULL DEFAULT 0,
            `ranking` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `plugin_dsimanager_projectdatas_id` (`plugin_dsimanager_projectdatas_id`),
            KEY `ranking` (`ranking`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // --- Lien simple maison entre une tâche et un ou plusieurs tickets ---
    // (l'intégration native via Item_Ticket reste en place par ailleurs,
    // mais s'est avérée inutilisable côté ticket sur cette installation -
    // voir README, section Dette technique).
    if (!$DB->tableExists('glpi_plugin_dsimanager_todos_tickets')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_todos_tickets` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_todos_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_todos_id`, `tickets_id`),
            KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // --- Module C : Projets Cybersécurité (même structure que B + 2 champs) ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_projectcybers')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_projectcybers` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `description` text,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `deadline` date DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `status` int NOT NULL DEFAULT 1,
            `plugin_dsimanager_roadmappriorities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `progress_percent` int NOT NULL DEFAULT 0,
            `steps_total` int NOT NULL DEFAULT 0,
            `steps_done` int NOT NULL DEFAULT 0,
            `impacts_risks` text,
            `expected_results` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `deadline` (`deadline`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_dsimanager_projectcybersteps')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_projectcybersteps` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_projectcybers_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `name` varchar(255) DEFAULT NULL,
            `description` varchar(255) DEFAULT NULL,
            `percent_done` int NOT NULL DEFAULT 0,
            `ranking` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `plugin_dsimanager_projectcybers_id` (`plugin_dsimanager_projectcybers_id`),
            KEY `ranking` (`ranking`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // --- Module D : Roadmap ---

    // Dropdowns configurables (Configuration > Listes déroulantes), pour ne
    // pas figer en dur des catégories métier qui varient d'une équipe à
    // l'autre.
    $dropdown_tables = [
        'glpi_plugin_dsimanager_roadmaptypes'      => ['Interne', 'Client', 'Maintenance'],
        'glpi_plugin_dsimanager_roadmappriorities' => range(0, 10),
        'glpi_plugin_dsimanager_roadmapstatuses'   => ['Pas commencé', 'En cours', 'En pause', 'Terminé'],
    ];
    foreach ($dropdown_tables as $table => $defaults) {
        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `{$table}` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `comment` text,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
            $DB->doQuery($query) or die($DB->error());
            foreach ($defaults as $name) {
                $DB->insert($table, ['name' => $name, 'entities_id' => 0]);
            }
        }
    }

    // Catégories de semaine (contenu des cases de la grille), avec couleur
    // pour un repérage visuel rapide sur la grille.
    if (!$DB->tableExists('glpi_plugin_dsimanager_roadmapweekcategories')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_roadmapweekcategories` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `color` varchar(7) NOT NULL DEFAULT '#6c757d',
            `ranking` int NOT NULL DEFAULT 0,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `comment` text,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `ranking` (`ranking`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
        $dsimanager_cat_ranking = 0;
        foreach ([['Dev', '#4263eb'], ['Test', '#f59f00'], ['Recette', '#ae3ec9'], ['Livraison', '#2fb344']] as $cat) {
            $DB->insert('glpi_plugin_dsimanager_roadmapweekcategories', [
                'name'        => $cat[0],
                'color'       => $cat[1],
                'ranking'     => $dsimanager_cat_ranking,
                'entities_id' => 0,
            ]);
            $dsimanager_cat_ranking += 10;
        }
    }

    // Lignes de la Roadmap (projets).
    if (!$DB->tableExists('glpi_plugin_dsimanager_roadmapitems')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_roadmapitems` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `description` varchar(255) DEFAULT NULL,
            `details` text,
            `plugin_dsimanager_roadmaptypes_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_roadmappriorities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_roadmapstatuses_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `budget` decimal(15,2) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `ranking` int NOT NULL DEFAULT 0,
            `is_archived` tinyint NOT NULL DEFAULT 0,
            `date_archived` datetime DEFAULT NULL,
            `archived_by` int {$default_key_sign} NOT NULL DEFAULT 0,
            `date_restored` datetime DEFAULT NULL,
            `restored_by` int {$default_key_sign} NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `ranking` (`ranking`),
            KEY `is_archived` (`is_archived`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Historique des archivages/restaurations (qui, quand).
    if (!$DB->tableExists('glpi_plugin_dsimanager_roadmaparchivelogs')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_roadmaparchivelogs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_roadmapitems_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `action` varchar(20) NOT NULL,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `date` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_dsimanager_roadmapitems_id` (`plugin_dsimanager_roadmapitems_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Cases hebdomadaires : une ligne = (projet, semaine) ayant une
    // catégorie assignée. Pas de ligne = case vide = pause/trou sur le
    // projet cette semaine-là. `week_date` est le lundi de la semaine
    // concernée : en travaillant en dates réelles (pas en "numéro de
    // semaine dans l'année"), le débord sur l'année suivante se gère tout
    // seul, sans code spécial.
    if (!$DB->tableExists('glpi_plugin_dsimanager_roadmapcells')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_roadmapcells` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_roadmapitems_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `week_date` date NOT NULL,
            `plugin_dsimanager_roadmapweekcategories_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_roadmapitems_id`, `week_date`),
            KEY `week_date` (`week_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Équipe visible sur le bandeau congés (Roadmap D et E) - table
    // partagée entre les deux, ajout/retrait libre depuis l'UI (exigence
    // d'évolutivité du cahier des charges).
    if (!$DB->tableExists('glpi_plugin_dsimanager_team')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_team` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `initials` varchar(10) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Ressources multiples par ligne Roadmap (many-to-many, même principe
    // que les techniciens du Module A).
    if (!$DB->tableExists('glpi_plugin_dsimanager_roadmapitems_users')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_roadmapitems_users` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_roadmapitems_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_roadmapitems_id`, `users_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Congés : un compteur de jours d'absence par semaine et par personne
    // (0 à 5, semaine ouvrée) - suffisant pour distinguer une semaine
    // complète (5 -> case pleine à l'affichage) d'une absence partielle
    // (1-4 -> un "*" par jour), sans avoir à préciser quels jours
    // précisément. Table partagée entre Roadmap D et E - c'est le partage
    // de cette même table qui garantit la synchronisation entre les deux
    // pages, sans mécanisme supplémentaire à construire.
    if (!$DB->tableExists('glpi_plugin_dsimanager_conges')) {
        $query = "CREATE TABLE `glpi_plugin_dsimanager_conges` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `week_date` date NOT NULL,
            `days_off` int NOT NULL DEFAULT 0,
            `note` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`users_id`, `week_date`),
            KEY `week_date` (`week_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query) or die($DB->error());
    }

    // Ajout idempotent des colonnes introduites après la création initiale
    // des tables ci-dessus (via Migration::addField, qui ne fait rien si la
    // colonne existe déjà - nécessaire pour les installations déjà en place
    // avant l'ajout du statut / de la description des étapes).
    $migration2 = new Migration(PLUGIN_DSIMANAGER_VERSION);
    $migration2->addField('glpi_plugin_dsimanager_projectdatas', 'status', 'integer', ['value' => 1]);
    $migration2->addField('glpi_plugin_dsimanager_projectdatasteps', 'description', 'string');
    $migration2->addField('glpi_plugin_dsimanager_conges', 'note', 'string');
    $migration2->addField('glpi_plugin_dsimanager_team', 'initials', 'string');
    $migration2->addField('glpi_plugin_dsimanager_projectdatas', 'plugin_dsimanager_roadmappriorities_id', 'integer', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_projectcybers', 'plugin_dsimanager_roadmappriorities_id', 'integer', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_todos', 'plugin_dsimanager_todorequestingservices_id', 'integer', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_roadmapitems', 'is_archived', 'bool', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_roadmapitems', 'date_archived', 'datetime');
    $migration2->addField('glpi_plugin_dsimanager_roadmapitems', 'archived_by', 'integer', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_roadmapitems', 'date_restored', 'datetime');
    $migration2->addField('glpi_plugin_dsimanager_roadmapitems', 'restored_by', 'integer', ['value' => 0]);
    $migration2->addField('glpi_plugin_dsimanager_roadmapweekcategories', 'ranking', 'integer', ['value' => 0]);
    // Budget rendu réellement optionnel (nullable) - ne s'applique que si
    // la colonne existe déjà en NOT NULL DEFAULT 0 (installations
    // antérieures à cette version). Requête SQL directe plutôt qu'une
    // option de Migration::changeField() dont je n'étais pas certain à
    // 100% du nom exact.
    if ($DB->tableExists('glpi_plugin_dsimanager_roadmapitems') && $DB->fieldExists('glpi_plugin_dsimanager_roadmapitems', 'budget')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_dsimanager_roadmapitems` MODIFY `budget` decimal(15,2) DEFAULT NULL");
    }
    $migration2->executeMigration();

    // --- Déclaration du droit auprès de tous les profils existants ---
    // Par défaut : 0 (aucun accès) pour tous les profils, sauf Super-Admin
    // qui reçoit ALLSTANDARDRIGHT. C'est ce mécanisme qui permet de garder
    // le menu invisible pour tout le monde jusqu'à ce qu'un admin coche
    // la case dans Administration > Profils > [profil] > onglet Gestion DSI.
    //
    // Idempotent : GLPI rejoue plugin_dsimanager_install() à chaque mise à
    // jour du plugin (pas seulement à la toute première installation), donc
    // on vérifie d'abord que le droit n'existe pas déjà en base avant de
    // l'ajouter, pour ne pas violer la contrainte d'unicité de la table
    // glpi_profilerights et provoquer une erreur 500.
    foreach ([PLUGIN_DSIMANAGER_RIGHT_TODO, PLUGIN_DSIMANAGER_RIGHT_DATA, PLUGIN_DSIMANAGER_RIGHT_CYBER, PLUGIN_DSIMANAGER_RIGHT_ROADMAP] as $right_name) {
        $right_exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => $right_name],
        ])->current()['cpt'] > 0;

        if (!$right_exists) {
            ProfileRight::addProfileRights([$right_name]);
        }
    }

    // =====================================================================
    // --- Module Stock (v2.0.0) ---
    // =====================================================================
    plugin_dsimanager_install_stock($default_charset, $default_collation, $default_key_sign);

    return true;
}

/**
 * Installation du module Stock, séparée pour la lisibilité. Entièrement
 * idempotente : rejouée à chaque mise à jour du plugin.
 */
function plugin_dsimanager_install_stock(string $default_charset, string $default_collation, string $default_key_sign): void
{
    global $DB;

    // Emplacements de stockage (dropdown). Chaque emplacement est lié à une
    // Localisation GLPI native : c'est elle qui est inscrite sur l'actif
    // quand il entre dans cet emplacement.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stocklocations')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stocklocations` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `locations_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `ranking` int NOT NULL DEFAULT 0,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `locations_id` (`locations_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Catégories d'articles quantitatifs (dropdown).
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockarticlecategories')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockarticlecategories` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
        foreach (['Périphériques', 'Câbles et adaptateurs', 'Protection', 'Alimentation', 'Consommables'] as $name) {
            $DB->insert('glpi_plugin_dsimanager_stockarticlecategories', ['name' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
        }
    }

    // Catalogue des articles gérés en quantité (souris, câbles, coques...).
    // `doc_phrase` : phrase utilisée sur le bon de remise/reprise (Lot 2).
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockarticles')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockarticles` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `plugin_dsimanager_stockarticlecategories_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `doc_phrase` varchar(255) DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `plugin_dsimanager_stockarticlecategories_id` (`plugin_dsimanager_stockarticlecategories_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Niveaux de stock quantitatifs (cache article x emplacement), mis à
    // jour à chaque validation et recalculables depuis l'historique.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stocklevels')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stocklevels` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_stockarticles_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_stocklocations_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `quantity` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_stockarticles_id`, `plugin_dsimanager_stocklocations_id`),
            KEY `plugin_dsimanager_stocklocations_id` (`plugin_dsimanager_stocklocations_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Seuils d'alerte par article ET par emplacement.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockthresholds')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockthresholds` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_stockarticles_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_stocklocations_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `threshold` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_stockarticles_id`, `plugin_dsimanager_stocklocations_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Actifs unitaires actuellement en stock : simple pointeur vers l'actif
    // natif (itemtype + items_id), jamais une copie de ses données. Un
    // actif ne peut être qu'à un seul endroit (clé unique).
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockunits')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockunits` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_stocklocations_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `date_in` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`, `items_id`),
            KEY `plugin_dsimanager_stocklocations_id` (`plugin_dsimanager_stocklocations_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Commandes (en-têtes) : entrée / sortie / transfert / ajustement.
    // Une commande validée n'est plus jamais modifiée ni supprimée : une
    // erreur se corrige par une commande corrective (corrects_id).
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockorders')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockorders` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `type` int NOT NULL DEFAULT 1,
            `status` int NOT NULL DEFAULT 1,
            `date_order` date DEFAULT NULL,
            `users_id_requester` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_executor` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_beneficiary` int {$default_key_sign} NOT NULL DEFAULT 0,
            `groups_id_beneficiary` int {$default_key_sign} NOT NULL DEFAULT 0,
            `stocklocations_id_from` int {$default_key_sign} NOT NULL DEFAULT 0,
            `stocklocations_id_to` int {$default_key_sign} NOT NULL DEFAULT 0,
            `supplier_ref` varchar(255) DEFAULT NULL,
            `comment` text,
            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `corrects_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_creator` int {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_validator` int {$default_key_sign} NOT NULL DEFAULT 0,
            `date_validation` timestamp NULL DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `type` (`type`),
            KEY `status` (`status`),
            KEY `date_order` (`date_order`),
            KEY `date_validation` (`date_validation`),
            KEY `users_id_beneficiary` (`users_id_beneficiary`),
            KEY `users_id_executor` (`users_id_executor`),
            KEY `stocklocations_id_from` (`stocklocations_id_from`),
            KEY `stocklocations_id_to` (`stocklocations_id_to`),
            KEY `tickets_id` (`tickets_id`),
            KEY `corrects_id` (`corrects_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Lignes de commande. `label`, `serial`, `otherserial` sont des
    // instantanés figés au moment du mouvement : l'historique reste exact
    // même si l'actif est renommé ou modifié ensuite.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockorderlines')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockorderlines` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_stockorders_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `kind` int NOT NULL DEFAULT 1,
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_stockarticles_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `label` varchar(255) DEFAULT NULL,
            `serial` varchar(255) DEFAULT NULL,
            `otherserial` varchar(255) DEFAULT NULL,
            `quantity` int NOT NULL DEFAULT 1,
            `expected_quantity` int DEFAULT NULL,
            `lines_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `comment` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_dsimanager_stockorders_id` (`plugin_dsimanager_stockorders_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_dsimanager_stockarticles_id` (`plugin_dsimanager_stockarticles_id`),
            KEY `serial` (`serial`),
            KEY `label` (`label`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // --- Lot 2 (v2.1.0) : tickets, localisation de sortie, bons PDF ---
    $migration = new Migration(PLUGIN_DSIMANAGER_VERSION);
    // Localisation inscrite sur les actifs à la sortie (préremplie depuis
    // le ticket ; 0 = réglage par défaut du module).
    $migration->addField('glpi_plugin_dsimanager_stockorders', 'locations_id', 'fkey');
    // Dernier bon PDF généré et joint (Document GLPI natif).
    $migration->addField('glpi_plugin_dsimanager_stockorders', 'documents_id', 'fkey');
    $migration->addKey('glpi_plugin_dsimanager_stockorders', 'locations_id');
    $migration->executeMigration();

    // Modèles de bon : un pour la remise (sortie), un pour la reprise
    // (entrée). Modifiables depuis Stock > Configuration > Modèles de bon.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockdoctemplates')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockdoctemplates` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `type` int NOT NULL DEFAULT 0,
            `title` varchar(255) DEFAULT NULL,
            `body` longtext,
            `footer` text,
            `signature_left` varchar(255) DEFAULT NULL,
            `signature_right` varchar(255) DEFAULT NULL,
            `show_place_date` tinyint NOT NULL DEFAULT 1,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }
    // v2.2.0 : espacements réglables (logo -> titre, titre -> corps), en mm.
    $migration = new Migration(PLUGIN_DSIMANAGER_VERSION);
    $migration->addField('glpi_plugin_dsimanager_stockdoctemplates', 'space_after_logo', 'integer', ['value' => 4]);
    $migration->addField('glpi_plugin_dsimanager_stockdoctemplates', 'space_after_title', 'integer', ['value' => 12]);
    $migration->executeMigration();

    foreach (\GlpiPlugin\Dsimanager\StockDocument::getDefaultTemplates() as $tpl_type => $tpl) {
        $exists = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stockdoctemplates', 'WHERE' => ['type' => $tpl_type]])->current()['cpt'] > 0;
        if (!$exists) {
            $DB->insert('glpi_plugin_dsimanager_stockdoctemplates', ['type' => $tpl_type] + $tpl);
        }
    }

    // --- Lot 4 (v2.3.0) : profils de dotation, formulaires natifs ---
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockkits')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockkits` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }
    // Contenu d'un profil : actifs unitaires (unit_type = itemtype, ou
    // "Phone:tablet") à choisir par le technicien, articles en quantité
    // ajoutés automatiquement.
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockkititems')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockkititems` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_stockkits_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `kind` int NOT NULL DEFAULT 1,
            `unit_type` varchar(150) DEFAULT NULL,
            `plugin_dsimanager_stockarticles_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `quantity` int NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `plugin_dsimanager_stockkits_id` (`plugin_dsimanager_stockkits_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }
    $migration = new Migration(PLUGIN_DSIMANAGER_VERSION);
    $migration->addField('glpi_plugin_dsimanager_stockorders', 'plugin_dsimanager_stockkits_id', 'fkey');
    // Réponse de formulaire à l'origine du brouillon (traçabilité).
    $migration->addField('glpi_plugin_dsimanager_stockorders', 'forms_answerssets_id', 'fkey');
    $migration->addKey('glpi_plugin_dsimanager_stockorders', 'forms_answerssets_id');
    $migration->executeMigration();

    // --- v2.4.0 : kits d'articles, suppressions (sortie du parc) ---
    $migration = new Migration(PLUGIN_DSIMANAGER_VERSION);
    // Un "kit" est un article composé d'autres articles (ex. kit clavier +
    // souris filaire) : il n'a pas de stock propre, ses composants sont
    // décomptés en une fois.
    $migration->addField('glpi_plugin_dsimanager_stockarticles', 'is_bundle', 'bool', ['value' => 0]);
    // Composition figée sur la ligne de commande à la validation (JSON
    // {composant: quantité par kit}) : l'historique reste exact même si la
    // composition du kit change ensuite.
    $migration->addField('glpi_plugin_dsimanager_stockorderlines', 'components', 'text');
    $migration->addField('glpi_plugin_dsimanager_stockorders', 'plugin_dsimanager_stockdisposalreasons_id', 'fkey');
    $migration->addKey('glpi_plugin_dsimanager_stockorders', 'plugin_dsimanager_stockdisposalreasons_id');
    $migration->executeMigration();

    if (!$DB->tableExists('glpi_plugin_dsimanager_stockarticlecomponents')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockarticlecomponents` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_dsimanager_stockarticles_id_bundle` int {$default_key_sign} NOT NULL DEFAULT 0,
            `plugin_dsimanager_stockarticles_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `quantity` int NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_dsimanager_stockarticles_id_bundle`, `plugin_dsimanager_stockarticles_id`),
            KEY `plugin_dsimanager_stockarticles_id` (`plugin_dsimanager_stockarticles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
    }

    // Motifs de sortie du parc (liste modifiable).
    if (!$DB->tableExists('glpi_plugin_dsimanager_stockdisposalreasons')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dsimanager_stockdisposalreasons` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}") or die($DB->error());
        foreach ([
            'Recyclage (DEEE)', 'Restitution leasing', 'Non repris (rachat de société)', 'Hors service / destruction',
            'Vente / cession', 'Don', 'Perte / vol',
        ] as $name) {
            $DB->insert('glpi_plugin_dsimanager_stockdisposalreasons', ['name' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
        }
    }

    // --- Données natives GLPI nécessaires (créées seulement si absentes) ---
    // Statuts des éléments ("Actif" à la sortie, "En stock" à l'entrée) et
    // localisations des emplacements. Recherche par nom avant création.
    $state_in  = plugin_dsimanager_find_or_create_dropdown(\State::class, 'En stock');
    $state_out = plugin_dsimanager_find_or_create_dropdown(\State::class, 'Actif');
    $state_disposed = plugin_dsimanager_find_or_create_dropdown(\State::class, 'Sorti du parc');
    $loc_it    = plugin_dsimanager_find_or_create_dropdown(\Location::class, 'Stock IT');
    $loc_arch  = plugin_dsimanager_find_or_create_dropdown(\Location::class, 'Stock Archives');
    $loc_out   = plugin_dsimanager_find_dropdown(\Location::class, 'Siège');

    // Emplacements de départ (uniquement si la table est vide : ne jamais
    // écraser une liste déjà personnalisée).
    $nb_locations = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stocklocations'])->current()['cpt'];
    if ((int) $nb_locations === 0) {
        $DB->insert('glpi_plugin_dsimanager_stocklocations', [
            'name' => 'Bureau IT', 'locations_id' => $loc_it, 'ranking' => 10, 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $DB->insert('glpi_plugin_dsimanager_stocklocations', [
            'name' => 'Stock Archives', 'locations_id' => $loc_arch, 'ranking' => 20, 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
    }

    // Réglages (table native glpi_configs, contexte du plugin). Seules les
    // clés absentes sont initialisées, pour ne jamais écraser un réglage
    // modifié depuis la page Configuration du stock.
    $current  = \Config::getConfigurationValues('plugin:dsimanager');
    $defaults = [
        'stock_state_in'      => $state_in,
        'stock_state_out'     => $state_out,
        'stock_state_disposed' => $state_disposed,
        'stock_location_out'  => $loc_out,
        'stock_tablet_group'  => plugin_dsimanager_find_tablet_group(),
        'stock_unit_itemtypes' => json_encode(['Phone', 'Computer', 'Monitor', 'Line', 'Peripheral']),
        'stock_doc_phrases'    => json_encode(\GlpiPlugin\Dsimanager\StockDocument::getDefaultPhrases()),
        'stock_doc_logo'       => '',
        'stock_company_name'   => '',
    ];
    $to_set = [];
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $current)) {
            $to_set[$key] = $value;
        }
    }
    if (count($to_set)) {
        \Config::setConfigurationValues('plugin:dsimanager', $to_set);
    }

    // --- Droits du module Stock ---
    // Créés à 0 pour tous les profils (comme les autres modules), puis
    // accordés en totalité aux profils qui administrent la configuration
    // GLPI (Super-Admin en pratique) - uniquement à la toute première
    // création du droit, jamais lors d'une mise à jour.
    $full_rights = [
        PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT  => READ,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_IN       => READ | CREATE | UPDATE | PLUGIN_DSIMANAGER_STOCK_VALIDATE,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT      => READ | CREATE | UPDATE | PLUGIN_DSIMANAGER_STOCK_VALIDATE,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER => READ | CREATE | UPDATE | PLUGIN_DSIMANAGER_STOCK_VALIDATE,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY  => READ,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS    => READ,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG   => ALLSTANDARDRIGHT,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL => READ | CREATE | UPDATE | PLUGIN_DSIMANAGER_STOCK_VALIDATE,
    ];
    $admin_profiles = [];
    foreach ($DB->request([
        'SELECT' => ['profiles_id'],
        'FROM'   => 'glpi_profilerights',
        'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
    ]) as $row) {
        $admin_profiles[] = (int) $row['profiles_id'];
    }
    foreach ($full_rights as $right_name => $value) {
        $right_exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => $right_name],
        ])->current()['cpt'] > 0;
        if (!$right_exists) {
            ProfileRight::addProfileRights([$right_name]);
            foreach ($admin_profiles as $profiles_id) {
                $DB->update('glpi_profilerights', ['rights' => $value], [
                    'profiles_id' => $profiles_id,
                    'name'        => $right_name,
                ]);
            }
        }
    }
}

/**
 * Recherche un intitulé de dropdown natif par nom exact (racine ou non).
 * Renvoie 0 si absent.
 */
function plugin_dsimanager_find_dropdown(string $itemtype, string $name): int
{
    global $DB;
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => $itemtype::getTable(),
        'WHERE'  => ['name' => $name],
        'LIMIT'  => 1,
    ])->current();
    return $row ? (int) $row['id'] : 0;
}

/**
 * Recherche un intitulé de dropdown natif par nom, le crée à la racine de
 * l'entité 0 (récursif) s'il n'existe pas. Passe par add() pour que GLPI
 * calcule lui-même les champs dérivés (completename, level...).
 */
function plugin_dsimanager_find_or_create_dropdown(string $itemtype, string $name): int
{
    $id = plugin_dsimanager_find_dropdown($itemtype, $name);
    if ($id > 0) {
        return $id;
    }
    $item = new $itemtype();
    $new_id = $item->add([
        'name'         => $name,
        'entities_id'  => 0,
        'is_recursive' => 1,
    ]);
    return $new_id ? (int) $new_id : 0;
}

/**
 * Groupe GLPI qui identifie les tablettes parmi les téléphones
 * ("Matériels > Tablette"). Recherche sur le nom complet, puis sur le nom
 * simple. 0 si introuvable : réglable ensuite dans la Configuration du stock.
 */
function plugin_dsimanager_find_tablet_group(): int
{
    global $DB;
    foreach ([['completename' => 'Matériels > Tablette'], ['name' => 'Tablette']] as $where) {
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_groups',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ])->current();
        if ($row) {
            return (int) $row['id'];
        }
    }
    return 0;
}

/**
 * Désinstallation du plugin : suppression des tables et des droits.
 * (les données ne sont supprimées que si l'admin confirme explicitement
 * la désinstallation depuis Configuration > Plugins)
 */
function plugin_dsimanager_uninstall()
{
    global $DB;

    $tables = [
        'glpi_plugin_dsimanager_todos',
        'glpi_plugin_dsimanager_todos_users',
        'glpi_plugin_dsimanager_todocategories',
        'glpi_plugin_dsimanager_todorequestingservices',
        'glpi_plugin_dsimanager_projectdatas',
        'glpi_plugin_dsimanager_projectdatasteps',
        'glpi_plugin_dsimanager_todos_tickets',
        'glpi_plugin_dsimanager_projectcybers',
        'glpi_plugin_dsimanager_projectcybersteps',
        'glpi_plugin_dsimanager_roadmaptypes',
        'glpi_plugin_dsimanager_roadmappriorities',
        'glpi_plugin_dsimanager_roadmapstatuses',
        'glpi_plugin_dsimanager_roadmapweekcategories',
        'glpi_plugin_dsimanager_roadmapitems',
        'glpi_plugin_dsimanager_roadmapcells',
        'glpi_plugin_dsimanager_team',
        'glpi_plugin_dsimanager_conges',
        'glpi_plugin_dsimanager_roadmapitems_users',
        'glpi_plugin_dsimanager_roadmaparchivelogs',
        'glpi_plugin_dsimanager_stocklocations',
        'glpi_plugin_dsimanager_stockarticlecategories',
        'glpi_plugin_dsimanager_stockarticles',
        'glpi_plugin_dsimanager_stocklevels',
        'glpi_plugin_dsimanager_stockthresholds',
        'glpi_plugin_dsimanager_stockunits',
        'glpi_plugin_dsimanager_stockorders',
        'glpi_plugin_dsimanager_stockorderlines',
        'glpi_plugin_dsimanager_stockdoctemplates',
        'glpi_plugin_dsimanager_stockkits',
        'glpi_plugin_dsimanager_stockkititems',
        'glpi_plugin_dsimanager_stockarticlecomponents',
        'glpi_plugin_dsimanager_stockdisposalreasons',
    ];

    foreach ($tables as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
    }

    ProfileRight::deleteProfileRights([
        PLUGIN_DSIMANAGER_RIGHT_TODO, PLUGIN_DSIMANAGER_RIGHT_DATA, PLUGIN_DSIMANAGER_RIGHT_CYBER, PLUGIN_DSIMANAGER_RIGHT_ROADMAP,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT, PLUGIN_DSIMANAGER_RIGHT_STOCK_IN, PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER, PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY, PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS,
        PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL,
    ]);

    // Destinations "Commande de stock" ajoutées aux formulaires natifs :
    // supprimées, sinon GLPI les afficherait comme "plugin désactivé".
    if ($DB->tableExists('glpi_forms_destinations_formdestinations')) {
        $DB->delete('glpi_forms_destinations_formdestinations', ['itemtype' => 'GlpiPlugin\\Dsimanager\\FormDestinationStockOrder']);
    }

    // Réglages du module Stock. Les statuts et localisations natifs créés à
    // l'installation sont volontairement conservés : ils peuvent être
    // utilisés par des actifs du parc.
    Config::deleteConfigurationValues('plugin:dsimanager', [
        'stock_state_in', 'stock_state_out', 'stock_location_out', 'stock_tablet_group', 'stock_unit_itemtypes',
        'stock_doc_phrases', 'stock_doc_logo', 'stock_state_disposed',
    ]);

    return true;
}
