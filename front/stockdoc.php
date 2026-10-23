<?php

/**
 * Module Stock (Lot 2) - téléchargement / affichage des bons PDF.
 *   ?id=N           bon de la commande N (affiché dans le navigateur)
 *   ?id=N&download  idem, en téléchargement
 *   ?preview=TYPE   aperçu du modèle avec des données d'exemple
 *   ?logo=1         logo actuel (aperçu sur la page des modèles)
 */

use GlpiPlugin\Dsimanager\StockConfig;
use GlpiPlugin\Dsimanager\StockDocument;
use GlpiPlugin\Dsimanager\StockOrder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

include('../../../inc/includes.php');

$send = static function (string $content, string $filename, bool $download): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store');
    echo $content;
    exit();
};

if (isset($_GET['logo'])) {
    if (!StockConfig::canView()) {
        throw new AccessDeniedHttpException();
    }
    $path = StockDocument::getLogoPath();
    if ($path === '') {
        throw new NotFoundHttpException();
    }
    header('Content-Type: ' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit();
}

if (isset($_GET['preview'])) {
    if (!StockConfig::canView()) {
        throw new AccessDeniedHttpException();
    }
    $type = (int) $_GET['preview'] === StockDocument::TYPE_REPRISE ? StockDocument::TYPE_REPRISE : StockDocument::TYPE_REMISE;
    $send(StockDocument::generatePreview($type), 'apercu_modele.pdf', false);
}

$order = new StockOrder();
if (!$order->getFromDB((int) ($_GET['id'] ?? 0))) {
    throw new NotFoundHttpException();
}
$type = (int) $order->fields['type'];
if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY, READ) && !StockOrder::canDoOnType($type, READ)) {
    throw new AccessDeniedHttpException();
}
if (!StockDocument::supportsOrder($order)) {
    throw new NotFoundHttpException();
}
$send(StockDocument::generatePdf($order), StockDocument::getFileName($order), isset($_GET['download']));
