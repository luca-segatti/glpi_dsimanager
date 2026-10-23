<?php

namespace GlpiPlugin\Dsimanager;

use Config;
use Document;
use Glpi\RichText\RichText;
use Item_Line;
use RuntimeException;
use Session;
use TCPDF;

/**
 * Module Stock (Lot 2) - Bons de remise (sortie) et de reprise (entrée).
 *
 * Modèle personnalisable (table stockdoctemplates, un par type) : logo
 * commun, titre encadré, corps en texte riche avec placeholders, zone
 * "Fait à ..., le ..." et deux zones de signature. Le PDF est produit avec
 * TCPDF, déjà embarqué par GLPI (vendor/tecnickcom/tcpdf) : aucune
 * dépendance à installer sur le serveur.
 */
class StockDocument
{
    /** Types de bon = types de commande concernés. */
    public const TYPE_REMISE  = StockOrder::TYPE_OUT;
    public const TYPE_REPRISE = StockOrder::TYPE_IN;

    private const TABLE = 'glpi_plugin_dsimanager_stockdoctemplates';

    /** Texte affiché à la place d'une valeur absente, à compléter à la main. */
    public const BLANK = '..............................';

    // ------------------------------------------------------------------
    // Valeurs par défaut (modifiables dans Stock > Configuration > Modèles de bon)
    // ------------------------------------------------------------------

    public static function getDefaultTemplates(): array
    {
        return [
            self::TYPE_REMISE => [
                'title'           => 'Remise de matériel informatique',
                'body'            => '<p>Je soussigné {beneficiaire},<br>Certifie avoir reçu, de la société {societe} et à titre de prêt dans le cadre '
                                   . 'd\'une utilisation professionnelle le matériel suivant en état neuf :</p>'
                                   . '{liste_materiel}'
                                   . '<p>Je m\'engage à maintenir le matériel en bon état et à ne pas enlever les coques de protection des appareils.</p>',
                'footer'          => '',
                'signature_left'  => 'Signature du technicien',
                'signature_right' => 'Signature du bénéficiaire',
                'show_place_date' => 1,
                'space_after_logo'  => 4,
                'space_after_title' => 12,
            ],
            self::TYPE_REPRISE => [
                'title'           => 'Reprise de matériel informatique',
                'body'            => '<p>Je soussigné {beneficiaire},<br>Certifie avoir restitué à la société {societe} le matériel suivant, '
                                   . 'qui m\'avait été prêté dans le cadre de mon utilisation professionnelle :</p>'
                                   . '{liste_materiel}'
                                   . '<p>Observations : {commentaire}</p>',
                'footer'          => '',
                'signature_left'  => 'Signature du technicien',
                'signature_right' => 'Signature du collaborateur',
                'show_place_date' => 1,
                'space_after_logo'  => 4,
                'space_after_title' => 12,
            ],
        ];
    }

    /**
     * Phrases utilisées dans {liste_materiel} pour les actifs unitaires,
     * par type d'actif. "tablet" = téléphone du groupe Tablette ; "sim" =
     * complément ajouté quand une ligne est associée au téléphone.
     */
    public static function getDefaultPhrases(): array
    {
        return [
            'Phone'      => 'Un téléphone professionnel {fabricant} {modele} (IMEI : {serie}){sim}',
            'tablet'     => 'Une tablette {fabricant} {modele} (S/N : {serie}){sim}',
            'Computer'   => 'Un ordinateur portable {fabricant} {modele} (S/N : {serie})',
            'Monitor'    => 'Un écran {fabricant} {modele} (S/N : {serie})',
            'Line'       => 'Une carte SIM N° {serie}',
            'Peripheral' => 'Un périphérique {nom} (S/N : {serie})',
            'default'    => '{type} {nom} (S/N : {serie})',
            'sim'        => ' avec carte SIM N° {numero}',
        ];
    }

    public static function getPhraseLabels(): array
    {
        return [
            'Phone'      => __('Téléphone', 'dsimanager'),
            'tablet'     => __('Tablette', 'dsimanager'),
            'Computer'   => __('Ordinateur', 'dsimanager'),
            'Monitor'    => __('Écran', 'dsimanager'),
            'Line'       => __('Ligne téléphonique (seule)', 'dsimanager'),
            'Peripheral' => __('Périphérique', 'dsimanager'),
            'default'    => __('Autre type d\'actif', 'dsimanager'),
            'sim'        => __('Complément « carte SIM » ({sim})', 'dsimanager'),
        ];
    }

    /**
     * Placeholders utilisables dans le corps du bon : [placeholder => description].
     */
    public static function getPlaceholders(): array
    {
        return [
            '{societe}'             => __('Nom de la société (réglé sur cette page, carte « Société »)', 'dsimanager'),
            '{beneficiaire}'        => __('Prénom NOM du bénéficiaire (sortie) ou du collaborateur restituant (entrée)', 'dsimanager'),
            '{beneficiaire_prenom}' => __('Prénom seul', 'dsimanager'),
            '{beneficiaire_nom}'    => __('NOM seul', 'dsimanager'),
            '{service}'             => __('Service (groupe) de la commande', 'dsimanager'),
            '{date}'                => __('Date de la commande', 'dsimanager'),
            '{executant}'           => __('Technicien exécutant', 'dsimanager'),
            '{demandeur}'           => __('Demandeur', 'dsimanager'),
            '{reference}'           => __('Référence de la commande (S-00012...)', 'dsimanager'),
            '{emplacement}'         => __('Emplacement de stock', 'dsimanager'),
            '{ticket}'              => __('Numéro et titre du ticket lié', 'dsimanager'),
            '{commentaire}'         => __('Commentaire de la commande', 'dsimanager'),
            '{liste_materiel}'      => __('Liste à puces du matériel (phrases réglables ci-dessous)', 'dsimanager'),
        ];
    }

    // ------------------------------------------------------------------
    // Lecture / écriture des modèles et réglages
    // ------------------------------------------------------------------

    public static function getTemplate(int $type): array
    {
        global $DB;
        $defaults = self::getDefaultTemplates()[$type] ?? self::getDefaultTemplates()[self::TYPE_REMISE];
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['type' => $type]])->current();
        return $row ? array_merge($defaults, array_intersect_key($row, $defaults)) : $defaults;
    }

    public static function saveTemplate(int $type, array $input): void
    {
        global $DB;
        $data = [
            'title'           => trim((string) ($input['title'] ?? '')),
            // Corps : HTML de l'éditeur, nettoyé (aucun script / attribut dangereux).
            'body'            => RichText::getSafeHtml((string) ($input['body'] ?? '')),
            'footer'          => trim((string) ($input['footer'] ?? '')),
            'signature_left'  => trim((string) ($input['signature_left'] ?? '')),
            'signature_right' => trim((string) ($input['signature_right'] ?? '')),
            'show_place_date' => empty($input['show_place_date']) ? 0 : 1,
            // Espacements en mm, bornés pour rester sur une page A4.
            'space_after_logo'  => max(0, min(80, (int) ($input['space_after_logo'] ?? 4))),
            'space_after_title' => max(0, min(80, (int) ($input['space_after_title'] ?? 12))),
            'date_mod'        => date('Y-m-d H:i:s'),
        ];
        $exists = $DB->request(['COUNT' => 'cpt', 'FROM' => self::TABLE, 'WHERE' => ['type' => $type]])->current()['cpt'] > 0;
        if ($exists) {
            $DB->update(self::TABLE, $data, ['type' => $type]);
        } else {
            $DB->insert(self::TABLE, ['type' => $type] + $data);
        }
    }

    public static function resetTemplate(int $type): void
    {
        global $DB;
        $defaults = self::getDefaultTemplates()[$type] ?? null;
        if ($defaults !== null) {
            $DB->update(self::TABLE, $defaults + ['date_mod' => date('Y-m-d H:i:s')], ['type' => $type]);
        }
    }

    public static function getPhrases(): array
    {
        $raw = Config::getConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_doc_phrases']);
        $saved = json_decode($raw['stock_doc_phrases'] ?? '', true);
        return array_merge(self::getDefaultPhrases(), is_array($saved) ? $saved : []);
    }

    public static function savePhrases(array $phrases): void
    {
        $clean = [];
        foreach (array_keys(self::getDefaultPhrases()) as $key) {
            if (isset($phrases[$key])) {
                // Le complément SIM commence volontairement par un espace.
                $clean[$key] = $key === 'sim' ? rtrim((string) $phrases[$key]) : trim((string) $phrases[$key]);
            }
        }
        Config::setConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_doc_phrases' => json_encode($clean)]);
    }

    // ------------------------------------------------------------------
    // Nom de la société (variable {societe}), commun aux deux modèles
    // ------------------------------------------------------------------

    public static function getCompanyName(): string
    {
        $raw = Config::getConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_company_name']);
        return trim((string) ($raw['stock_company_name'] ?? ''));
    }

    public static function saveCompanyName(string $name): void
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 255);
        Config::setConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_company_name' => $name]);
    }

    // ------------------------------------------------------------------
    // Logo (commun aux deux modèles), stocké dans files/_plugins/dsimanager
    // ------------------------------------------------------------------

    private static function getStorageDir(): string
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/dsimanager';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function getLogoPath(): string
    {
        $raw = Config::getConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_doc_logo']);
        $name = basename((string) ($raw['stock_doc_logo'] ?? ''));
        if ($name === '') {
            return '';
        }
        $path = self::getStorageDir() . '/' . $name;
        return is_file($path) ? $path : '';
    }

    /**
     * Enregistre le logo envoyé ($_FILES['logo']). PNG ou JPEG uniquement,
     * 2 Mo maximum. Renvoie un message d'erreur, ou '' si OK.
     */
    public static function saveLogo(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return __('Aucun fichier reçu.', 'dsimanager');
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            return __('Logo trop lourd (2 Mo maximum).', 'dsimanager');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            return __('Format non pris en charge : utilisez un PNG ou un JPEG.', 'dsimanager');
        }
        self::deleteLogo();
        $name = 'logo_' . date('YmdHis') . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], self::getStorageDir() . '/' . $name)) {
            return __('Impossible d\'enregistrer le logo (droits sur files/_plugins ?).', 'dsimanager');
        }
        Config::setConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_doc_logo' => $name]);
        return '';
    }

    public static function deleteLogo(): void
    {
        $path = self::getLogoPath();
        if ($path !== '') {
            @unlink($path);
        }
        Config::setConfigurationValues(Stock::CONFIG_CONTEXT, ['stock_doc_logo' => '']);
    }

    // ------------------------------------------------------------------
    // Contenu du bon
    // ------------------------------------------------------------------

    public static function supportsOrder(StockOrder $order): bool
    {
        return in_array((int) $order->fields['type'], [self::TYPE_REMISE, self::TYPE_REPRISE], true)
            && (int) $order->fields['status'] !== StockOrder::STATUS_CANCELLED;
    }

    private static function userParts(int $users_id): array
    {
        if ($users_id <= 0) {
            return ['', ''];
        }
        $user = new \User();
        if (!$user->getFromDB($users_id)) {
            return ['', ''];
        }
        $first = trim((string) $user->fields['firstname']);
        $last  = trim((string) $user->fields['realname']);
        if ($first === '' && $last === '') {
            return ['', (string) $user->fields['name']];
        }
        return [$first, mb_strtoupper($last)];
    }

    /**
     * Valeurs des placeholders pour une commande (texte brut, non échappé).
     */
    public static function buildContext(StockOrder $order): array
    {
        $f = $order->fields;
        [$first, $last] = self::userParts((int) $f['users_id_beneficiary']);
        $full = trim($first . ' ' . $last);

        $ticket = '';
        if ((int) $f['tickets_id'] > 0) {
            $t = new \Ticket();
            $ticket = $t->getFromDB((int) $f['tickets_id']) ? ('#' . (int) $f['tickets_id'] . ' - ' . $t->fields['name']) : ('#' . (int) $f['tickets_id']);
        }
        $type = (int) $f['type'];
        $loc  = $type === StockOrder::TYPE_OUT ? (int) $f['stocklocations_id_from'] : (int) $f['stocklocations_id_to'];

        return [
            '{societe}'             => self::getCompanyName() !== '' ? self::getCompanyName() : self::BLANK,
            '{beneficiaire}'        => $full !== '' ? $full : self::BLANK,
            '{beneficiaire_prenom}' => $first !== '' ? $first : self::BLANK,
            '{beneficiaire_nom}'    => $last !== '' ? $last : self::BLANK,
            '{service}'             => (int) $f['groups_id_beneficiary'] > 0 ? \Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id_beneficiary'], false, true, false) : self::BLANK,
            '{date}'                => $f['date_order'] ? date('d/m/Y', strtotime((string) $f['date_order'])) : date('d/m/Y'),
            '{executant}'           => (int) $f['users_id_executor'] > 0 ? getUserName((int) $f['users_id_executor']) : self::BLANK,
            '{demandeur}'           => (int) $f['users_id_requester'] > 0 ? getUserName((int) $f['users_id_requester']) : self::BLANK,
            '{reference}'           => $order->isNewItem() ? 'S-00000' : $order->getReference(),
            '{emplacement}'         => Stock::getLocationName($loc),
            '{ticket}'              => $ticket,
            '{commentaire}'         => trim((string) $f['comment']) !== '' ? trim((string) $f['comment']) : '',
        ];
    }

    /**
     * Phrases de la liste du matériel, une par ligne de commande. Une ligne
     * téléphonique associée à un téléphone de la même commande n'est pas
     * répétée : elle apparaît dans la phrase du téléphone ({sim}).
     */
    public static function buildItemsList(array $lines): array
    {
        global $DB;
        $phrases = self::getPhrases();

        // Lignes téléphoniques "absorbées" par un téléphone de la commande.
        $absorbed = [];
        foreach ($lines as $l) {
            if ((int) $l['kind'] === StockOrderLine::KIND_UNIT && (int) $l['lines_id'] > 0) {
                $absorbed[(int) $l['lines_id']] = true;
            }
        }

        $out = [];
        foreach ($lines as $l) {
            if ((int) $l['kind'] === StockOrderLine::KIND_QTY) {
                $article = new StockArticle();
                $phrase = '';
                if ($article->getFromDB((int) $l['plugin_dsimanager_stockarticles_id'])) {
                    $phrase = trim((string) $article->fields['doc_phrase']);
                }
                if ($phrase === '') {
                    $phrase = (string) $l['label'];
                }
                $qty = (int) $l['quantity'];
                $out[] = ($qty > 1 ? $qty . ' × ' : '') . $phrase;
                continue;
            }

            $itemtype = (string) $l['itemtype'];
            $items_id = (int) $l['items_id'];
            if ($itemtype === 'Line' && isset($absorbed[$items_id])) {
                continue;
            }
            $is_tablet = Stock::isTablet($itemtype, $items_id);
            $key = $is_tablet ? 'tablet' : (isset($phrases[$itemtype]) ? $itemtype : 'default');

            $values = [
                '{type}'       => Stock::getUnitTypeLabel($itemtype, $items_id),
                '{nom}'        => (string) $l['label'],
                '{serie}'      => (string) $l['serial'],
                '{inventaire}' => (string) $l['otherserial'],
                '{fabricant}'  => '',
                '{modele}'     => '',
                '{sim}'        => '',
            ];
            if ($items_id > 0 && class_exists($itemtype)) {
                $asset = new $itemtype();
                if ($asset->getFromDB($items_id)) {
                    if (!empty($asset->fields['manufacturers_id'])) {
                        $values['{fabricant}'] = (string) \Dropdown::getDropdownName('glpi_manufacturers', (int) $asset->fields['manufacturers_id'], false, true, false);
                    }
                    $model_class = $itemtype . 'Model';
                    if (class_exists($model_class)) {
                        $fk = $model_class::getForeignKeyField();
                        if (!empty($asset->fields[$fk])) {
                            $values['{modele}'] = (string) \Dropdown::getDropdownName($model_class::getTable(), (int) $asset->fields[$fk], false, true, false);
                        }
                    }
                }
            }

            // Numéro de ligne associé : celui choisi dans la commande, sinon
            // le lien natif déjà existant (utile pour une reprise).
            $number = '';
            $lines_id = (int) $l['lines_id'];
            if ($lines_id <= 0 && $items_id > 0 && $itemtype !== 'Line') {
                $link = $DB->request([
                    'SELECT' => ['lines_id'],
                    'FROM'   => Item_Line::getTable(),
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
                    'LIMIT'  => 1,
                ])->current();
                $lines_id = $link ? (int) $link['lines_id'] : 0;
            }
            if ($lines_id > 0) {
                $line = new \Line();
                if ($line->getFromDB($lines_id)) {
                    $number = (string) $line->fields['caller_num'];
                }
            }
            if ($number !== '') {
                // Espaces insécables : un numéro n'est jamais coupé en fin de ligne.
                $values['{sim}'] = strtr($phrases['sim'], ['{numero}' => str_replace(' ', "\u{00A0}", $number)]);
            }
            $values['{serie}'] = str_replace(' ', "\u{00A0}", $values['{serie}']);

            $text = strtr($phrases[$key], $values);
            // Nettoyage des trous laissés par une valeur vide (fabricant ou
            // modèle non renseigné) : espaces doubles, parenthèses vides.
            $text = preg_replace('/\(\s*[^()]*:\s*\)/u', '', $text);
            $text = preg_replace('/\s{2,}/u', ' ', $text);
            $text = preg_replace('/\s+([,.)])/u', '$1', $text);
            $out[] = trim($text);
        }
        return $out;
    }

    /**
     * Corps HTML final : placeholders remplacés (valeurs échappées).
     */
    public static function renderBody(string $body_html, array $context, array $items): string
    {
        // Liste à tirets comme sur le modèle Word (retrait + "-" + texte
        // aligné). Construite en tableau : les puces HTML de TCPDF ne
        // permettent pas de choisir le symbole de façon fiable.
        $list = '';
        if (count($items)) {
            $list = '<table cellspacing="0" cellpadding="1">';
            foreach ($items as $item) {
                $list .= '<tr><td width="5%"></td><td width="5%">-</td><td width="90%">' . htmlspecialchars($item) . '</td></tr>';
            }
            $list .= '</table><br>';
        }
        $replace = ['{liste_materiel}' => $list];
        foreach ($context as $key => $value) {
            $replace[$key] = nl2br(htmlspecialchars((string) $value));
        }
        // {liste_materiel} seul dans un paragraphe : on retire le <p> qui
        // l'entoure (une liste dans un paragraphe s'affiche mal).
        $body_html = preg_replace('#<p>\s*\{liste_materiel\}\s*</p>#u', '{liste_materiel}', $body_html);
        return strtr($body_html, $replace);
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * Produit le PDF (contenu binaire) d'une commande.
     */
    public static function generatePdf(StockOrder $order): string
    {
        $type = (int) $order->fields['type'];
        $tpl  = self::getTemplate($type);
        $body = self::renderBody((string) $tpl['body'], self::buildContext($order), self::buildItemsList($order->getLines()));

        [$first, $last] = self::userParts((int) $order->fields['users_id_beneficiary']);
        $beneficiary = trim($first . ' ' . $last);
        $executor = (int) $order->fields['users_id_executor'] > 0 ? getUserName((int) $order->fields['users_id_executor']) : '';
        $draft = (int) $order->fields['status'] === StockOrder::STATUS_DRAFT;

        return self::renderPdf($tpl, $body, $executor, $beneficiary, $order->isNewItem() ? '' : $order->getReference(), $draft);
    }

    /**
     * Aperçu du modèle avec des données d'exemple (page Modèles de bon).
     */
    public static function generatePreview(int $type): string
    {
        $tpl = self::getTemplate($type);
        $context = [
            '{beneficiaire}' => 'Camille DUPONT', '{beneficiaire_prenom}' => 'Camille', '{beneficiaire_nom}' => 'DUPONT',
            '{service}' => 'Comptabilité', '{date}' => date('d/m/Y'), '{executant}' => 'Technicien IT', '{demandeur}' => 'Responsable Comptabilité',
            '{societe}' => self::getCompanyName() !== '' ? self::getCompanyName() : 'Ma Société',
            '{reference}' => StockOrder::formatReference($type, 42), '{emplacement}' => 'Bureau IT',
            '{ticket}' => '#1234 - Arrivée de Camille Dupont', '{commentaire}' => 'Matériel en bon état.',
        ];
        $p = self::getPhrases();
        $items = [
            strtr($p['Phone'], ['{fabricant}' => 'Apple', '{modele}' => 'iPhone 15', '{serie}' => '356938035643809', '{sim}' => strtr($p['sim'], ['{numero}' => "06\u{00A0}12\u{00A0}34\u{00A0}56\u{00A0}78"])]),
            'Une coque et verre de protection pour protéger le téléphone en cas de choc.',
            strtr($p['tablet'], ['{fabricant}' => 'Samsung', '{modele}' => 'Galaxy Tab A9', '{serie}' => 'R9XW30ABCDE', '{sim}' => '']),
            'Un chargeur pour téléphone',
        ];
        $body = self::renderBody((string) $tpl['body'], $context, $items);
        return self::renderPdf($tpl, $body, 'Technicien IT', 'Camille DUPONT', $context['{reference}'], false, true);
    }

    private static function renderPdf(array $tpl, string $body_html, string $executor, string $beneficiary, string $reference, bool $draft, bool $preview = false): string
    {
        if (!class_exists(TCPDF::class)) {
            throw new RuntimeException(__('Bibliothèque PDF (TCPDF) introuvable dans GLPI.', 'dsimanager'));
        }
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('GLPI - Gestion DSI');
        $pdf->SetTitle((string) $tpl['title']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // Logo et titre à 15 mm du bord, texte en retrait (25 mm) comme
        // sur le modèle Word d'origine.
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $font = 'dejavusans';

        // Logo (haut gauche)
        $logo = self::getLogoPath();
        $y = 15;
        if ($logo !== '') {
            // Logo de 18 mm de haut (12 -> 30 mm), puis espace réglable.
            $pdf->Image($logo, 15, 12, 0, 18, '', '', '', true, 300, '', false, false, 0, 'L');
            $y = 30 + max(0, (int) ($tpl['space_after_logo'] ?? 4));
        }
        // Mention discrète : référence / brouillon / aperçu (haut droit)
        $pdf->SetFont($font, '', 7);
        $pdf->SetTextColor(130, 130, 130);
        $mention = $preview ? __('APERÇU - données d\'exemple', 'dsimanager') : ($draft ? __('BROUILLON - commande non validée', 'dsimanager') . ' - ' . $reference : $reference);
        $pdf->SetXY(115, 12);
        $pdf->Cell(80, 4, $mention, 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // Titre encadré
        $pdf->SetXY(15, $y);
        $pdf->SetFont($font, '', 14);
        $pdf->Cell(180, 9, (string) $tpl['title'], 1, 1, 'C');
        $pdf->Ln(max(0, (int) ($tpl['space_after_title'] ?? 12)));

        // Corps (en retrait)
        $pdf->SetFont($font, '', 10.5);
        $pdf->setCellHeightRatio(1.35);
        $pdf->writeHTMLCell(160, 0, 25, $pdf->GetY(), $body_html, 0, 1, false, true, 'L', true);
        $pdf->Ln(10);

        // Fait à ..., le ...
        if (!empty($tpl['show_place_date'])) {
            if ($pdf->GetY() > 230) {
                $pdf->AddPage();
            }
            $pdf->SetX(105);
            $pdf->Cell(90, 6, 'Fait à ....................................., le ......................', 0, 1, 'L');
            $pdf->Ln(8);
        }

        // Signatures
        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }
        $sy = $pdf->GetY();
        $pdf->SetFont($font, '', 10);
        if (trim((string) $tpl['signature_left']) !== '') {
            $pdf->SetXY(25, $sy);
            $pdf->Cell(75, 6, (string) $tpl['signature_left'] . ' :', 0, 2, 'L');
            if ($executor !== '') {
                $pdf->SetFont($font, '', 8.5);
                $pdf->SetTextColor(90, 90, 90);
                $pdf->Cell(75, 5, $executor, 0, 2, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont($font, '', 10);
            }
        }
        if (trim((string) $tpl['signature_right']) !== '') {
            $pdf->SetXY(105, $sy);
            $pdf->Cell(85, 6, (string) $tpl['signature_right'] . ' :', 0, 2, 'L');
            if ($beneficiary !== '') {
                $pdf->SetFont($font, '', 8.5);
                $pdf->SetTextColor(90, 90, 90);
                $pdf->Cell(85, 5, $beneficiary, 0, 2, 'L');
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        // Pied de page libre (adresse, mentions...)
        $footer = trim((string) $tpl['footer']);
        if ($footer !== '') {
            // Pas de saut de page automatique pour le pied (sinon il
            // créerait une page blanche).
            $pdf->SetAutoPageBreak(false);
            $pdf->SetFont($font, '', 7.5);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->SetXY(15, 280);
            $pdf->MultiCell(180, 4, $footer, 0, 'C');
        }

        return $pdf->Output('bon.pdf', 'S');
    }

    public static function getFileName(StockOrder $order): string
    {
        $type = (int) $order->fields['type'];
        $prefix = $type === self::TYPE_REMISE ? 'Bon_remise' : 'Bon_reprise';
        [$first, $last] = self::userParts((int) $order->fields['users_id_beneficiary']);
        $who = trim($last . '_' . $first, '_');
        $who = preg_replace('/[^A-Za-z0-9_-]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $who) ?: '');
        return $prefix . '_' . $order->getReference() . ($who !== '' ? '_' . $who : '') . '.pdf';
    }

    /**
     * Génère le bon et le joint au ticket lié, comme document GLPI natif
     * (visible dans l'onglet Documents du ticket). Renvoie l'ID du document.
     */
    public static function attachToTicket(StockOrder $order): int
    {
        global $DB;
        $tickets_id = (int) $order->fields['tickets_id'];
        if ($tickets_id <= 0 || !self::supportsOrder($order)) {
            return 0;
        }
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            throw new RuntimeException(sprintf(__('Ticket #%d introuvable.', 'dsimanager'), $tickets_id));
        }

        $filename = self::getFileName($order);
        // Préfixe unique : Document::moveDocument() le retire du nom final.
        $prefix = uniqid('', true) . '_';
        if (file_put_contents(GLPI_TMP_DIR . '/' . $prefix . $filename, self::generatePdf($order)) === false) {
            throw new RuntimeException(__('Écriture du PDF impossible dans le dossier temporaire de GLPI.', 'dsimanager'));
        }

        // Document::add() empile ses propres messages ("Document copié avec
        // succès"...) : on les retire pour ne garder que le message du module.
        $saved_messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null;
        $doc = new Document();
        $docs_id = (int) $doc->add([
            'name'              => $filename,
            'entities_id'       => (int) $ticket->fields['entities_id'],
            'is_recursive'      => 0,
            'itemtype'          => \Ticket::class,
            'items_id'          => $tickets_id,
            '_filename'         => [$prefix . $filename],
            '_prefix_filename'  => [$prefix],
            'comment'           => sprintf(__('Généré depuis la commande de stock %s', 'dsimanager'), $order->getReference()),
        ]);
        if ($saved_messages !== null) {
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = $saved_messages;
        } else {
            unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        }
        if ($docs_id <= 0) {
            throw new RuntimeException(__('Création du document GLPI impossible.', 'dsimanager'));
        }
        $DB->update(StockOrder::getTable(), ['documents_id' => $docs_id], ['id' => $order->getID()]);
        $order->fields['documents_id'] = $docs_id;
        return $docs_id;
    }
}
