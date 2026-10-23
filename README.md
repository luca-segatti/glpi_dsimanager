# Plugin GLPI « Gestion DSI »

Plugin centralisant la gestion de la DSI : tâches, projets DATA/Cybersécurité,
roadmap, stock. **Version actuelle : 1.0.0 (édition générique) — Modules A
(TODO), B (Projets DATA), C (Projets Cybersécurité), D (Roadmap, avec
archivage) et Stock (Lots 1 à 4) opérationnels.**

## Personnaliser pour votre organisation (après installation)

Rien n'est à modifier dans le code. Après l'installation :

1. **Nom de la société sur les bons PDF** : *Stock > Configuration > Modèles
   de bon*, carte **Société** à droite. Ce nom remplace la variable
   `{societe}` dans les bons de remise et de reprise (pointillés si vide).
2. **Logo des bons** : même page, carte **Logo** (PNG/JPEG, 2 Mo max).
3. **Textes des bons** (titre, corps, signatures, pied de page avec adresse
   et mentions légales) : même page, un onglet par type de bon.
4. **Services demandeurs** (module Tâches) : une liste neutre est créée à
   l'installation (Direction, Ressources humaines, Comptabilité,
   Commercial, Marketing, Logistique, Informatique) ; l'adapter dans
   *Configuration > Listes déroulantes > Gestion DSI*.
5. **Catégories de tâches, types / priorités / statuts / catégories de
   semaine de la Roadmap, catégories d'articles, motifs de sortie du
   parc** : même endroit, ou depuis les pages de configuration de chaque
   module.
6. **Stock** : emplacements, statuts, localisation de sortie, groupe
   identifiant les tablettes et types d'actifs gérés à l'unité dans
   *Stock > Configuration*.
7. **Droits** : *Administration > Profils > [profil] > onglet Gestion DSI*.

Le nom technique du plugin est `dsimanager` (dossier, tables
`glpi_plugin_dsimanager_*`, espace de noms `GlpiPlugin\Dsimanager`). Le
renommer n'est pas nécessaire et casserait toute installation existante.

---

## 1. Installation

1. Copier le dossier `dsimanager` dans le répertoire `plugins/` de votre
   installation GLPI (ex. `/var/www/glpi/plugins/dsimanager`).
2. Vérifier les droits Unix : le propriétaire doit être le même que le reste
   de GLPI (généralement `www-data`).
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/dsimanager
   ```
3. Dans GLPI : **Configuration > Plugins**, repérer « Gestion DSI » et
   cliquer sur **Installer**, puis **Activer**.
4. Aller dans **Administration > Profils**, ouvrir le profil qui doit avoir
   accès, onglet **« Gestion DSI »**, cocher l'accès aux modules souhaités
   (Tâches, Projets DATA), enregistrer.
5. Le menu « Gestion DSI » apparaît dans le bandeau latéral pour les
   utilisateurs de ce profil uniquement.

### Étendre l'accès à d'autres profils plus tard (MEP)

Aucune modification de code n'est nécessaire : retourner dans
**Administration > Profils > [profil concerné] > Gestion DSI** et cocher la
case du module concerné.

---

## 2. Utilisation (Module A - Tâches)

- **Liste des tâches** : menu Gestion DSI > TODO. Recherche, tri et
  filtres avancés disponibles sur tous les champs via le moteur de
  recherche standard GLPI.
- **Créer une tâche** : bouton « + » depuis la liste.
- **Modifier une tâche** : cliquer sur son titre, tous les champs sont
  éditables, y compris après création.
- **Statuts** : À faire, En cours, En attente, Partiel, Terminé, Annulé -
  affichés avec un code couleur dans la liste (vert/jaune/orange/rouge pour
  Terminé/En cours/En attente/Annulé).
- **Historique** : onglet automatique fourni nativement par GLPI, trace
  tous les changements de champs avec date, ancienne/nouvelle valeur et
  auteur.
- **Ticket(s) liés** : onglet dédié sur la fiche tâche, permettant de lier
  un ou plusieurs tickets existants (lien simple maison, table
  `glpi_plugin_dsimanager_todos_tickets` - l'intégration native GLPI via
  `Item_Ticket` a été tentée mais s'est avérée inutilisable pour l'ajout
  côté élément, voir section Dette technique ci-dessous).
- **Catégories de tâches** et **Services demandeurs** : gérables depuis
  **Configuration > Listes déroulantes > Gestion DSI**, ou directement via
  les icônes "i" (commentaire) et "+" (ajout rapide) à côté de chaque champ
  dans le formulaire - pas de page de gestion séparée nécessaire pour ces
  deux listes.

## 2bis. Utilisation (Module B - Projets DATA)

- **Liste des projets** : menu Gestion DSI > Projets DATA. La colonne
  "Avancement" affiche directement une barre de progression et le compte
  d'étapes (X/Y) sans avoir à ouvrir la fiche.
- **Étapes du projet** : gérées dans l'onglet "Étapes" de la fiche projet
  (visible une fois le projet créé) - ajout, modification du % et
  suppression directement depuis ce tableau.
- Le pourcentage d'avancement global et les compteurs affichés en liste
  sont recalculés automatiquement à chaque modification d'étape
  (dénormalisé sur la table principale, pas de calcul à la volée).
- Droit dédié `plugin_dsimanager_data`, à activer par profil.

---

## 2ter. Utilisation (Module C - Projets Cybersécurité)

- Structure identique au Module B (titre, description, technicien,
  deadline, statut, étapes avec avancement visible en liste).
- Deux champs supplémentaires : **Impacts / Risques** et **Résultats
  attendus / obtenus**.
- Droit dédié `plugin_dsimanager_cyber`.

---

## 2quater. Utilisation (Module D - Roadmap)

- Page dédiée (pas une liste de recherche standard GLPI) : menu Gestion DSI
  > Roadmap.
- **Barre d'actions en haut de page** : équipe, congés, et les 4 listes
  déroulantes configurables, chacune sur sa propre page avec liste +
  ajout/édition/suppression complets.
- **Congés** : gérés sur une page dédiée (`front/roadmap_conges.php`) avec
  un nombre de jours (0 à 5) et une note libre par personne et par semaine.
  Sur la page principale, affichage en lecture seule : bleu pâle si semaine
  complète, blanc avec un `*` par jour si absence partielle, note visible
  en survol et en résumé sous la case.
- **Grille Roadmap** : une ligne par projet (Type, Description, Détails,
  Priorité, Ressource(s), Statut, Budget), une colonne par semaine. Les
  colonnes descriptives sont en lecture seule sur la grille (tronquées
  avec "..." et texte complet au survol), pour rester lisibles quel que
  soit le contenu - l'édition complète se fait sur une fiche dédiée
  (`front/roadmapitem.form.php`, bouton "Modifier" par ligne / "Nouveau
  projet" en haut), comme pour un ticket. Plusieurs ressources peuvent
  être assignées à un même projet (comme les techniciens du Module A) ;
  la liste des ressources sélectionnables est restreinte à l'équipe
  Roadmap, pas tous les utilisateurs GLPI. En grille, elles s'affichent
  par leurs initiales (gérées depuis "Gérer l'équipe") plutôt que leur nom
  complet, pour rester compact. Chaque case de la grille hebdomadaire est
  une liste déroulante de "catégorie de semaine" (configurable, avec
  couleur, remplissant toute la case) - une case vide représente une
  pause sur le projet cette semaine-là.
- **Colonnes et bandeau figés** : les colonnes Type à Budget restent
  visibles pendant le défilement horizontal (comme un gel de colonnes façon
  tableur), séparées les unes des autres et du reste de la grille par des
  bordures verticales. Le bandeau congés reste visible en haut de page
  pendant le défilement vertical, et son défilement horizontal est
  synchronisé en JS avec celui de la grille Roadmap pour que les semaines
  restent alignées entre les deux tableaux.
- **Tri** : cliquer sur l'en-tête Type / Description / Priorité / Statut /
  Budget trie la grille sur cette colonne (tri fait en PHP après
  chargement, pas en SQL). Tri par défaut : priorité croissante - l'ordre
  des lignes n'a pas d'autre signification particulière dans ce module.
- **Budget optionnel** : peut être laissé vide (colonne nullable), ne
  s'affiche plus comme "0" par défaut.
- **Archivage** : case à cocher sur la fiche projet ("Archiver ce
  projet") - masque le projet de la grille principale sans supprimer
  aucune donnée (cases hebdomadaires conservées). Page dédiée "Projets
  archivés" (lien en haut de la grille) pour les consulter et les
  restaurer en un clic. Date/auteur d'archivage et de restauration
  affichés sur la fiche, avec un historique complet (qui a archivé/
  restauré, et quand) dans la table
  `glpi_plugin_dsimanager_roadmaparchivelogs`.
- **Fenêtre affichée** : date de départ et nombre de semaines (12/26/52)
  réglables en haut de page, sans jamais supprimer l'historique en base -
  seul l'affichage change. Les semaines sont comptées en dates réelles
  (pas en numéro de semaine dans l'année), donc un projet à cheval sur
  deux années s'affiche sans code spécifique.
- **Édition** : un seul bouton "Enregistrer" par tableau (équipe à part,
  congés à part, grille Roadmap à part) - pas d'édition case par case avec
  sauvegarde individuelle, pour rester simple et fiable.
- Droit dédié `plugin_dsimanager_roadmap`.

## 2quinquies. Utilisation (Module Stock — v2.0.0, Lot 1)

Catégorie de menu dédiée **« Stock »**, placée entre « Parc » et
« Assistance » : Contenu, Historique, Configuration.

### Principe : jamais de doublon avec l'inventaire

- **Actifs unitaires** (téléphones/tablettes, ordinateurs, écrans, lignes
  téléphoniques, périphériques suivis…) : ce sont les **actifs natifs GLPI**.
  Le module ne garde qu'un pointeur « cet actif est en stock à tel
  emplacement » (`stockunits`). Identifiant unique : le **numéro de série**
  (IMEI pour un téléphone, S/N pour une tablette), ou le **numéro d'appel**
  pour une ligne téléphonique. Un numéro déjà connu n'est jamais recréé :
  l'actif existant est réutilisé (même s'il est sorti puis revient, ou s'il
  est à la corbeille).
- **Articles quantitatifs** (souris, câbles, coques…) : catalogue propre au
  plugin, quantités par emplacement. Stock = entrées − sorties ± transferts
  ± ajustements, mis en cache et recalculable depuis l'historique.
- **Tablettes** : téléphones du groupe GLPI « Matériels > Tablette »
  (réglable). Le groupe n'est jamais modifié par le module.

### Commandes

Quatre types : **Entrée**, **Sortie**, **Transfert** (sans passer par une
sortie + entrée), **Ajustement d'inventaire** (comptage réel → écart tracé).

1. **Brouillon** : en-tête puis lignes. Aucun effet sur le stock.
2. **Validation** : tout est appliqué en **une seule transaction SQL** (tout
   ou rien). Disponibilités, emplacement des actifs et doublons sont
   revérifiés à cet instant. Les actifs natifs sont mis à jour via GLPI
   (historique natif de l'actif renseigné) :
   - entrée : statut « En stock », localisation de l'emplacement, utilisateur vidé ;
   - sortie : statut « Actif », localisation de sortie si réglée (« Siège »),
     utilisateur = bénéficiaire s'il est renseigné ;
   - transfert : localisation du nouvel emplacement.
3. Une commande validée est **figée** (ni modifiable, ni supprimable). Une
   erreur se corrige par **« Créer une commande corrective »** : brouillon du
   mouvement inverse, lié à l'original, ajustable avant validation.
4. Un brouillon abandonné passe en « Annulée » (reste visible dans l'historique).

### Pages

- **Contenu** : alertes de seuil, recherche globale, filtres emplacement /
  catégorie (téléphones et tablettes séparés), synthèse par type et par
  emplacement, accès direct à la fiche et à l'historique de chaque actif.
- **Historique** : commandes cliquables, filtres période / type / statut /
  emplacement / bénéficiaire / exécutant / article / actif. La recherche
  libre porte aussi sur le **contenu** des commandes (désignation, S/N,
  IMEI, n° d'inventaire, commentaire de ligne). Export CSV (une ligne par
  matériel).
- **Onglet « Mouvements de stock »** sur les fiches Téléphone, Ordinateur,
  Moniteur, Ligne, Périphérique.
- **Configuration** : emplacements (ajout sans code), catalogue et
  catégories d'articles, réglages (statuts, localisation de sortie, groupe
  tablette, types gérés à l'unité, y compris actifs personnalisés GLPI 11),
  **seuils par article et par emplacement**, contrôles de cohérence
  (quantités vs historique, actifs modifiés hors module).

### Droits (Administration > Profils > onglet Gestion DSI)

| Droit | Niveaux |
|---|---|
| Stock - Contenu | Lecture |
| Stock - Entrées / Sorties / Transferts (et ajustements) | Lecture, Préparer (brouillon), Valider |
| Stock - Historique | Lecture (tous types) |
| Stock - Statistiques | Lecture (page Statistiques et cartes du tableau de bord) |
| Stock - Configuration | Lecture, Modification |

À l'installation, les profils qui administrent la configuration GLPI
(Super-Admin) reçoivent tous les droits Stock. Bit « Valider » : 1024
(`PLUGIN_DSIMANAGER_STOCK_VALIDATE`).

### Lot 2 (v2.1.0) : tickets, bons PDF, lien téléphone ↔ ligne

- **Onglet « Stock » sur les tickets** : commandes liées au ticket (avec
  lien vers leur bon PDF) et boutons « Sortie de stock depuis ce ticket » /
  « Entrée (reprise) depuis ce ticket ».
- **Préremplissage depuis le ticket** : demandeur et bénéficiaire (premier
  demandeur du ticket), service (groupe demandeur du ticket, sinon groupe
  par défaut de l'utilisateur), localisation du ticket (inscrite sur les
  actifs à la sortie), commentaire « Ticket #n - titre ».
- Champ **Ticket lié** sur toute commande (modifiable en brouillon) ;
  recherche par n° de ticket dans l'historique.
- **Localisation après sortie** par commande (vide = réglage du module).
- **Bons de remise (sortie) et de reprise (entrée) en PDF** (TCPDF, déjà
  fourni par GLPI) : bouton sur la commande (brouillon = mention
  « BROUILLON »), et **ajout automatique au ticket lié à la validation**
  (document GLPI natif, onglet Documents du ticket). Pas d'ajout
  automatique pour une commande corrective. Bouton « Joindre au ticket »
  pour régénérer.
- **Stock > Configuration > Modèles de bon** : logo (PNG/JPEG, commun),
  titre encadré, corps en texte riche avec variables (`{beneficiaire}`,
  `{service}`, `{date}`, `{liste_materiel}`...), signatures gauche/droite,
  « Fait à …, le … » optionnel, pied de page, phrases de la liste du
  matériel par type d'actif, aperçu PDF, retour aux valeurs par défaut.
  Logo stocké dans `files/_plugins/dsimanager/`.
- **Lien téléphone ↔ ligne** : sur une sortie, chaque téléphone/tablette
  peut être associé à une ligne téléphonique de la même commande ; à la
  validation, le lien natif GLPI (`Item_Line`) est créé. Sur le bon : « …
  avec carte SIM N° … », la ligne n'est pas listée deux fois. Une reprise
  réutilise le lien natif existant.
- Nouvelles colonnes `stockorders.locations_id` et `documents_id`, nouvelle
  table `stockdoctemplates`, réglages `stock_doc_phrases` / `stock_doc_logo`.

### Lot 3 (v2.2.0) : statistiques et tableau de bord

- **Stock > Statistiques** (droit « Stock - Statistiques ») :
  - période : 30 jours, 3 mois, 12 mois, année en cours ou dates libres ;
    filtre par emplacement ; export CSV ;
  - indicateurs : entrées, sorties, transferts (éléments et commandes),
    ajustements (écart net), stock actuel, articles sous le seuil ;
  - graphique des entrées / sorties par semaine (période ≤ 2 mois) ou par
    mois, avec tableau de données ;
  - matériels les plus distribués (type + fabricant/modèle) et
    consommables les plus utilisés (10 premiers) ;
  - état actuel du stock par catégorie et par emplacement, liste des
    articles sous leur seuil.
- Règles de calcul : commandes **validées** uniquement, à leur date de
  validation ; une **commande corrective est déduite** du mouvement qu'elle
  corrige (la correction d'une sortie diminue les sorties, elle ne compte
  pas comme une entrée) ; « éléments » = 1 par actif + quantité des articles.
- **Cartes du tableau de bord GLPI** (Accueil > Tableau de bord > mode
  édition > « + » > groupe « Stock (Gestion DSI) ») : articles sous le
  seuil, éléments sortis / entrés sur 30 jours, commandes en brouillon,
  stock par emplacement, stock par catégorie, entrées / sorties sur 12 mois.
  Visibles uniquement avec le droit « Stock - Statistiques ».
- Graphiques en SVG/HTML maison (aucune bibliothèque ajoutée), couleurs
  vérifiées pour le daltonisme, variantes pour le thème sombre de GLPI.
- Modèles de bon : **espaces réglables** (mm) entre le logo et le titre, et
  entre le titre et le corps du texte (colonnes `space_after_logo`,
  `space_after_title`).

### Lot 4 (v2.3.0) : formulaires natifs et profils de dotation

- **Destination de formulaire « Commande de stock (brouillon) »** : dans
  Administration > Formulaires > [formulaire] > onglet Destinations, bouton
  « Ajouter Commande de stock (brouillon) », à côté de la destination
  Ticket. API native de GLPI 11 (`FormDestinationManager::
  registerPluginDestinationType`). Réglages de la destination :
  type (sortie / entrée), emplacement, bénéficiaire (demandeur du ticket,
  auteur du formulaire ou réponse à une question), service (groupe du
  ticket, du bénéficiaire ou réponse), localisation après sortie (ticket ou
  réponse), profil de dotation (fixe ou réponse), recopie des réponses dans
  le commentaire. Les conditions de création natives de GLPI s'appliquent.
- À chaque soumission : commande **en brouillon** liée au ticket créé par
  le même formulaire (onglet Stock du ticket), sans effet sur le stock tant
  qu'un technicien ne l'a pas validée. Invisible pour l'auteur du
  formulaire (pas de droit Stock). Une erreur du module n'empêche jamais la
  soumission : elle est journalisée dans `files/_log/dsimanager.log`.
- **Profils de dotation** (Stock > Configuration > Profils de dotation) :
  liste type par profil. Articles en quantité ajoutés automatiquement au
  brouillon ; actifs unitaires (téléphone, tablette, ordinateur...) à
  choisir par le technicien, avec un bandeau d'avancement sur la commande
  (« Téléphone 0/1 · Tablette 0/1 · Coque 1/1 »). Pour un formulaire, le
  libellé de la réponse doit être exactement le nom du profil. Bouton
  « Appliquer un profil de dotation » sur tout brouillon d'entrée / sortie.
- Stock > Configuration indique si la destination est disponible et quels
  formulaires l'utilisent. À la désinstallation, les destinations du
  plugin sont retirées des formulaires.
- Nouvelles tables `stockkits`, `stockkititems` ; colonnes
  `stockorders.plugin_dsimanager_stockkits_id` et `forms_answerssets_id`.

### Correctif 2.3.1

- Entrée d'un actif unitaire : si plusieurs fiches du même type portent le
  numéro mais qu'une seule n'est pas à la corbeille, c'est elle qui est
  réutilisée. Sinon, le message liste chaque fiche en double (ID, nom,
  entité, corbeille) pour la retrouver facilement. Rappel : la comparaison
  ignore la casse et les espaces en fin de numéro.

### Version 2.4.0 : kits, suppressions, ergonomie

- **Kits d'articles** (Stock > Configuration > Articles, type « Kit ») : un
  kit (ex. « Kit clavier + souris filaire ») est composé d'articles
  simples. Il n'a pas de stock propre : sortir N kits décompte en une fois
  N × chaque composant ; une entrée de kits les réincrémente. Disponibilité
  = nombre de kits constituables avec le stock des composants. Catégorie
  « Kits » dans Contenu et dans les listes d'articles des commandes. La
  composition est figée sur la ligne de commande à la validation (colonne
  `stockorderlines.components`) : historique, recalcul et commandes
  correctives restent exacts même si le kit est modifié ensuite. Un kit ne
  contient pas d'autre kit, ne s'inventorie pas (ajuster ses composants) et
  son type est figé dès sa première utilisation.
- **Commande « Suppression »** (préfixe X-, droit « Stock - Suppressions »
  avec lecture / brouillon / validation) : sortie définitive du parc.
  Motif obligatoire (liste modifiable : recyclage, restitution leasing, non
  repris dans un rachat de société...), référence (bordereau, contrat),
  emplacement d'origine facultatif. Actifs choisis dans le stock ou
  recherchés par n° de série / IMEI / n° d'inventaire (même hors stock).
  À la validation : statut « Sorti du parc » (réglable), utilisateur vidé,
  retrait du stock, **mise à la corbeille GLPI** (restaurable). Articles
  et kits : retirés de l'emplacement d'origine. La commande corrective
  d'une suppression est une entrée en stock qui restaure les actifs.
- **Contenu > Matériel sorti du parc** (aussi dans le menu « Sorti du
  parc ») : tout ce qui est sorti, motif, référence, état actuel (à la
  corbeille, restauré, supprimé définitivement), annulations, filtres,
  export CSV. Indicateur « Sortis du parc » dans les statistiques.
- **Boutons de retour** sur les listes et fiches de configuration
  (Configuration du stock, liste concernée, Ajouter).
- **Correctif** : « Actions > Modifier > Catégorie » sur plusieurs
  articles provoquait une erreur SQL (option de recherche en datatype
  `specific` sans liste de valeurs). Passée en `dropdown`. Même correctif
  sur les colonnes Catégorie et Service demandeur du module TODO.

### Données créées à l'installation (si absentes)

- Statuts « En stock », « Actif » et « Sorti du parc » ; localisations
  « Stock IT » et « Stock Archives » ; emplacements « Bureau IT » (→ Stock
  IT) et « Stock Archives » ; quelques catégories d'articles et motifs de
  sortie du parc ; liste neutre de services demandeurs.
- Localisation de sortie : pré-réglée sur une localisation GLPI nommée
  « Siège » si elle existe (jamais créée), sinon à régler à la main.
- Groupe « Tablette » : détecté automatiquement s'il existe un groupe
  « Matériels > Tablette » ou « Tablette », sinon à régler à la main.
- Réglages dans `glpi_configs` (contexte `plugin:dsimanager`).

---

## 3. Architecture technique

```
dsimanager/
├── setup.php                     Déclaration du plugin, menu, hooks
├── hook.php                       Install / uninstall (tables + droits)
├── src/
│   ├── Todo.php                    Module A : itemtype tâches
│   ├── TodoCategory.php            Module A : dropdown des catégories
│   ├── ProjectData.php             Module B : itemtype projets DATA
│   ├── ProjectDataStep.php         Module B : étapes (onglet dédié)
│   └── Profile.php                 Onglet de gestion des droits sur Profil
├── front/
│   ├── todo.php / todo.form.php               Module A : liste / formulaire
│   ├── projectdata.php / projectdata.form.php Module B : liste / formulaire
│   ├── projectdatastep.form.php    Module B : CRUD des étapes (contrôleur dédié)
│   └── profile_rights.form.php     Sauvegarde des droits (contrôleur dédié)
└── README.md
```

### Tables SQL créées

| Table | Rôle |
|---|---|
| `glpi_plugin_dsimanager_todos` | Table principale des tâches (Module A) |
| `glpi_plugin_dsimanager_todos_users` | Liaison many-to-many tâche ↔ techniciens |
| `glpi_plugin_dsimanager_todocategories` | Dropdown des catégories de tâches |
| `glpi_plugin_dsimanager_todorequestingservices` | Dropdown des services demandeurs |
| `glpi_plugin_dsimanager_projectdatas` | Table principale des projets DATA (Module B) |
| `glpi_plugin_dsimanager_projectdatasteps` | Étapes de chaque projet DATA |
| `glpi_plugin_dsimanager_todos_tickets` | Lien maison tâche ↔ tickets |
| `glpi_plugin_dsimanager_projectcybers` | Table principale des projets Cybersécurité (Module C) |
| `glpi_plugin_dsimanager_projectcybersteps` | Étapes de chaque projet Cybersécurité |
| `glpi_plugin_dsimanager_roadmaptypes` | Dropdown Type (Module D) |
| `glpi_plugin_dsimanager_roadmappriorities` | Dropdown Priorité (Module D) |
| `glpi_plugin_dsimanager_roadmapstatuses` | Dropdown Statut (Module D) |
| `glpi_plugin_dsimanager_roadmapweekcategories` | Dropdown Catégorie de semaine, avec couleur (Module D) |
| `glpi_plugin_dsimanager_roadmapitems` | Lignes de la grille Roadmap (Module D) |
| `glpi_plugin_dsimanager_roadmapcells` | Cases hebdomadaires (projet × semaine) |
| `glpi_plugin_dsimanager_team` | Équipe visible sur le bandeau congés (partagée D/E) |
| `glpi_plugin_dsimanager_conges` | Jours d'absence par personne et par semaine (partagée D/E) |
| `glpi_plugin_dsimanager_roadmaparchivelogs` | Historique d'archivage/restauration des projets Roadmap |

Ces tables sont entièrement séparées des tables cœur GLPI (préfixe
`glpi_plugin_dsimanager_`) : une mise à jour de GLPI ne les touche jamais
directement. Le risque à surveiller lors d'une montée de version GLPI
concerne l'API interne (classes/hooks utilisés par le plugin), encadrée par
les bornes `min`/`max` déclarées dans `setup.php`.

### Menu et droits

Le menu utilise le hook `Glpi\Plugin\Hooks::MENU_TOADD` (confirmé en lisant
le code source de GLPI - le nom `menu_toplevel` utilisé dans une première
version n'existait pas dans cette version de GLPI et ne faisait donc rien).
Chaque module fournit sa propre méthode statique `getMenuContent()`
(ex. `Todo::getMenuContent()`, `ProjectData::getMenuContent()`), enregistrée
dans le tableau `types` du hook (voir `setup.php`). Le titre de la
catégorie de premier niveau "Gestion DSI" est forcé séparément via le hook
`Glpi\Plugin\Hooks::REDEFINE_MENUS` (voir `plugin_dsimanager_redefine_menu()`
dans `hook.php`), qui s'exécute après la construction du menu et permet de
distinguer le nom de la catégorie du nom de chaque entrée de sous-menu.

Chaque module a son propre droit (`plugin_dsimanager_todo`,
`plugin_dsimanager_data`, ...), déclaré à l'installation
(`ProfileRight::addProfileRights`, de façon idempotente - voir `hook.php`)
et géré via l'onglet ajouté sur la fiche Profil (`src/Profile.php`,
méthode `getManagedRights()`).

Pour ajouter un futur module (Cybersécurité, Roadmap...) :

1. Ajouter une constante `PLUGIN_DSIMANAGER_RIGHT_XXX` dans `setup.php`.
2. Ajouter l'entrée correspondante dans `Profile::getManagedRights()`.
3. Ajouter la classe à la liste `types` du hook `MENU_TOADD` dans `setup.php`.
4. Créer l'itemtype dans `src/` avec sa propre méthode `getMenuContent()`,
   et la table dans `hook.php` (`install()`, de façon idempotente).

### Historique / traçabilité

Activé nativement avec `public $dohistory = true;` sur une classe (ex.
`Todo`) — GLPI journalise alors automatiquement toute modification de champ
dans la table cœur `glpi_logs`, consultable depuis l'onglet Historique de
chaque fiche. Aucun code de journalisation manuel n'a été écrit.

### Recherche avancée

Fournie nativement par `rawSearchOptions()` dans chaque itemtype, qui
alimente le moteur de recherche GLPI standard (`Search::show()`) : tri,
filtres multiples, export CSV/PDF sont gratuits, sans code supplémentaire.
Pour une colonne calculée comme "Avancement" (Module B), la valeur est
dénormalisée sur la table principale plutôt que calculée à la volée dans le
moteur de recherche, pour rester compatible nativement avec tri/filtre.

---

## 4. Points d'attention techniques (retours d'expérience)

- **Vérifier les méthodes GLPI avant de les utiliser largement** :
  `Html::autocompletionTextField()`, utilisée dans une première version du
  Module A, n'existe plus dans cette version de GLPI et provoquait une
  erreur 500 à l'ouverture du formulaire. Remplacée par `Html::input()`.
- **`plugin_dsimanager_install()` doit être idempotente** : GLPI la rejoue à
  chaque mise à jour du plugin depuis le Marketplace, pas seulement à la
  toute première installation. Toute opération non protégée par une
  vérification d'existence (ex. `ProfileRight::addProfileRights()` sur un
  droit déjà présent) provoque une erreur 500 lors d'une mise à jour.
- **Le menu est mis en cache en session** (`$_SESSION['glpimenu']`) et n'est
  pas recalculé à chaque page. En cas de modification du mécanisme de menu,
  incrémenter `PLUGIN_DSIMANAGER_VERSION` et/ou vider le cache
  (`php bin/console cache:clear`) en plus d'une reconnexion.

---

## 5. Dette technique connue

- **CSRF** : GLPI 11 vérifie le jeton CSRF **automatiquement** sur toute
  requête POST, au niveau du noyau (`Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener`),
  indépendamment de tout code du plugin. Règle à respecter partout dans ce
  plugin : fermer chaque `<form>` avec `Html::closeForm()` (qui pose le
  jeton), et ne **jamais** appeler soi-même `Session::checkCSRF()` dans un
  contrôleur - un second appel manuel consomme/re-vérifie un jeton déjà
  validé par le contrôleur automatique et provoque un rejet
  ("L'action que vous avez réalisée n'est pas autorisée"), qui n'a donc
  rien à voir avec des droits insuffisants. Un oubli de `Html::closeForm()`
  sur `front/roadmap.php` (remplacé par un simple `</form>`) a provoqué ce
  symptôme sur le Module D ; les autres contrôleurs du plugin utilisaient
  déjà `Html::closeForm()` correctement.
- **Intégration native `Item_Ticket` conservée mais peu utile en l'état** :
  `Todo` est bien dans `$CFG_GLPI['ticket_types']` et possède un onglet
  "Ticket item(s)" natif (voir `Todo::defineTabs()`), mais celui-ci
  n'affiche un formulaire d'ajout que si un lien existe déjà par un autre
  biais (`Item_ticket_item.showListForItem()` dans le cœur GLPI) - et
  l'onglet "Éléments associés" n'était pas disponible côté ticket sur
  cette installation pour tester l'autre sens. D'où l'ajout du lien maison
  ci-dessus comme solution principale.
- **`datatype: 'dropdown'` sur une colonne pointant vers une table du
  plugin** provoque une erreur fatale (`getClassForItemtype(): ... null
  given`) dès qu'il y a au moins une ligne de résultat à afficher (vide =
  ça marche, non vide = ça plante) : le moteur de recherche GLPI essaie de
  résoudre automatiquement le type d'objet correspondant à la table pour
  générer un lien cliquable, et cette résolution échoue sur les tables de
  plugin. Utiliser `datatype: 'specific'` à la place pour toute colonne
  jointe sur une table du plugin (voir `Todo::getSpecificValueToDisplay()`
  ou `ProjectData::getSpecificValueToDisplay()`, cas `'name'`) : simple
  affichage texte, sans lien cliquable, mais fiable. **Fournir
  systématiquement `'itemtype' => MaClasse::class`** dans toute colonne de
  recherche `table`+`field` pointant vers une table du plugin, quel que
  soit le `datatype` choisi - sans ça, GLPI essaie de deviner le type
  d'objet correspondant à la table et échoue avec une erreur fatale
  (`getClassForItemtype(): ... null given`) dès qu'il y a au moins une
  ligne de résultat à afficher (la mettre à `'specific'` seule ne suffit
  pas, contrairement à ce qu'on aurait pu penser).

---

## 6. Prochaines étapes (roadmap du plugin)


- Module E : Roadmap Admin (même mécanique que D, table de projets
  distincte, équipe/congés partagés)
- Import CSV pour le Module A (évoqué, non planifié dans l'immédiat)
