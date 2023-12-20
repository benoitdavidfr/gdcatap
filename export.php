<?php
/** Exporte en GeoDCAT-AP ou en DCAT-AP les fiches sélectionnées des différents catalogues.
 * Le script prend 3 paramètres:
 *  - model est le modèle demandé, il vaut soit GeoDCAT-AP (défaut) ou DCAT-AP
 *  - harvest est l'identifiant de la moisson utilisée
 *    Cela permet d'effectuer un export d'une moisson donnée, même si une autre moisson est finalisée entre temps.
 *    Doit être défini pour les pages autres qua la première.
 *  - page est le numéro de la page demandée, >= 1, vaut 1 par défaut.
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/maxharvests.inc.php';
require_once __DIR__.'/dcatapapi.inc.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

$model = $_GET['model'] ?? 'GeoDCAT-AP';
$harvest = $_GET['harvest'] ?? null; // l'identifiant de la moisson utilisée
$pageNo = $_GET['page'] ?? 1;
if (!ctype_digit(strval($pageNo)) || ($pageNo <= 0)) {
  die("Erreur, page=$_GET[page] doit être un entier supérieur ou égal à 1");
}

if ($pageNo == 1) {
  if (!$harvest) {
    $harvestNo = maxHarvest();
    if (!is_file(__DIR__."/harvests/harvest$harvestNo/root.json")) {
      $harvestNo--;
      if (!is_file(__DIR__."/harvests/harvest$harvestNo/root.json")) {
        die("Erreur, aucune moisson finalisée");
      }
    }
    $harvest = "harvest$harvestNo";
  }
}
else { // ($pageNo > 1)
  if (!$harvest) {
    die("Erreur, le paramètre harvest doit être défini pour les pages autres que 1");
  }
}
if (!is_file(__DIR__."/harvests/$harvest/root.json")) {
  die("Erreur, moisson $harvest absente");
}

$root = json_decode(file_get_contents(__DIR__."/harvests/$harvest/root.json"), true);
$catId = null;
$pageCat = $pageNo-1; // page dans $catId à partir de 0
foreach ($root as $cId => $cat) {
  if ($pageCat < $cat['nbPages']) {
    $catId = $cId;
    break;
  }
  $pageCat -= $cat['nbPages'];
}

$nbPages = 0;
$totalItems = 0;
foreach ($root as $cId => $cat) {
  $nbPages += $cat['nbPages'];
  $totalItems += $cat['nbItems'];
}
//echo "nbPages=$nbPages<br>\n";

if (!$catId) {
  die("Erreur, page $pageNo incorrecte, le no de page doit être compris entre 1 et $nbPages");
}
echo "Sélection de la page $pageCat du catalogue $catId\n";
$catPages = json_decode(file_get_contents(__DIR__."/harvests/$harvest/$catId.json"), true);
//echo '<pre>'; print_r($catPages);
$catPage = $catPages[$pageCat];

//print_r($_SERVER);
$scriptUrl = (($_SERVER['HTTP_HOST'] == 'localhost') ? 'http' : 'https')
  . '://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
echo "scriptUrl=$scriptUrl<br>\n";

$view = [
  '@id'=> $scriptUrl."?model=$model&harvest=$harvest&page=$pageNo",
  '@type'=> 'PartialCollectionView',
  'first'=> $scriptUrl."?model=$model&harvest=$harvest&page=1",
];
if ($pageNo > 1) {
  $view['previous'] = $scriptUrl."?model=$model&harvest=$harvest&page=".$pageNo-1;
}
if ($pageNo < $nbPages) {
  $view['next'] = $scriptUrl."?model=$model&harvest=$harvest&page=".$pageNo+1;
}
$view['last'] = $scriptUrl."?model=$model&harvest=$harvest&page=$nbPages";

function addInCatalog(array $resource, string $catId): array {
  //echo '<pre>'; print_r($resource); die();
  $resource['http://www.w3.org/ns/dcat#inCatalog'] = [
    '@type'=> 'http://www.w3.org/ns/dcat#Catalog',
    'http://purl.org/dc/terms/title'=> [
      '@language'=> 'fr',
      '@value'=> $catId,
    ],
    'http://xmlns.com/foaf/0.1/homepage'=> [
      '@id'=> "http://$catId",
    ],
  ];
  return $resource;
}
  
// Convertit dans le modèle $model la fiche $fid du catalogue $catId
function convert(string $model, string $catId, CswServer $server, string $fid): array {
  //$gmd = $this->server->getRecordById('gmd', 'full', $fid); // lecture de la fiche en ISO
  $url = $server->getRecordByIdUrl('gmd', 'full', $fid); // URL de la fiche
  $path = $server->cache->id($url); // path dans le cache
  $dcatAp = new DcatAP($path);
  $rdf = $dcatAp->asEasyRdf($model);
  //$jsonld = $rdf->serialise('jsonld');
  
  $frame = [
    '@type'=> 'http://www.w3.org/ns/dcat#Dataset',
  ];
  $framed = JsonLD::frame($rdf->serialise('jsonld'), json_encode($frame));
  $framed = json_decode(json_encode($framed), true);
  try {
    $framed = BlankNode::del($framed);
  }
  catch (Exception $e) {
    echo $e->getMessage();
    echo '<pre>',Yaml::dump([$fid => $framed], 10, 2);
    $gmd = $server->getRecordById('gmd', 'full', $fid);
    echo str_replace('<','&lt;', $gmd);
    die();
  }
  $framed = addInCatalog($framed, $catId);
  return $framed;
}

$server = new CswServer($catId, $harvest, $catId);

if (0) {
  $array = convert($model, $server, $catPage[0]);
  echo '<pre>',Yaml::dump($array, 10, 2);
  die("ligne ".__LINE__);
}

$page = [
  '@context'=> 'http://www.w3.org/ns/hydra/context.jsonld',
  '@id'=> 'http://api.example.com/an-issue/comments',
  '@type'=> 'Collection',
  'totalItems'=> $totalItems,
  'member'=> array_map(
    function(string $fid) use($model, $catId, $server): array { return convert($model, $catId, $server, $fid); },
    $catPage),
  'view'=> $view,
];
echo '<pre>',Yaml::dump($page, 10, 2);
