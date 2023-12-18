<?php
/** Moissonne les catalogues définis dans catalog.yaml.
 * Doit être appelé sans paramètre pipé avec sh, crée un process de moissonnage pour chaque catalogue
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/maxharvests.inc.php';

use Symfony\Component\Yaml\Yaml;

if ($argc == 1) { // appel sans paramètre -> affiche la documentation
  echo "usage: php $argv[0] [new|prev] | sh ou $argv[0] list\n";
  echo "  - new pour créer une nouvelle moisson\n";
  echo "  - prev pour remoissoner la moisson précédente\n";
  echo "  - list pour lister les moissons\n";
  die();
}

if ($argc == 2) { // appel avec un paramètre 
  if ($argv[1] == 'list') {
    if (!is_dir(__DIR__.'/harvests')) {
      die("Aucune moisson\n");
    }
    foreach (new DirectoryIterator(__DIR__.'/harvests') as $path) {
      if ((substr($path, 0, 7) == 'harvest') && $path->isDir() && is_numeric(substr($path, 7))) {
        echo "$path\n";
      }
    }
    die();
  }
  $harvest = maxHarvest();
  if (($argv[1] == 'new') || ($harvest == -1)) { // appel sans paramètre par défaut, création d'une nouvelle moisson
    $harvest++;
  }
  
  foreach (Server::servers() as $catId => $cat) {
    if (!in_array($catId, ['sigloire','picto'])) continue;
    echo "php $argv[0] harvest$harvest $catId &\n";
  }
  die();
}

function getRecords(string $harvest, string $catId): void { // Lecture des getRecords
  $server = new CswServer($catId, $harvest, $catId);
  $startPosition = 1;
  while ($startPosition) {
    $records = $server->getRecords('dc', 'brief', $startPosition);
    $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
    $records = new SimpleXMLElement($records);
    $numberOfRecordsMatched = $records->csw_SearchResults['numberOfRecordsMatched'];
    $nextRecord = (int)$records->csw_SearchResults['nextRecord'];
    echo "$catId: $startPosition/$numberOfRecordsMatched, nextRecord=$nextRecord\n";
    $startPosition = $nextRecord;
    $server->sleep();
  }
  echo 'lastCachepathReturned=',$server->cache->lastCachepathReturned(),"\n";
}

$harvest = $argv[1];
$catId = $argv[2];

getRecords($harvest, $catId);

$mdServer = new MdServer($catId, $harvest, $catId, 1);
foreach ($mdServer as $no => $md) {
  //echo " - $md->dc_title ($md->dc_type)\n";
  if (in_array((string)$md->dc_type, ['FeatureCatalogue','service'])) continue;
  echo "$catId: getFullGmd $no/",$mdServer->numberOfRecordsMatched(),"\n";
  $xml = $mdServer->getFullGmd();
  
  //$record = InspireMd::convert($xml);
  //echo YamlDump([(string)$md->dc_identifier => $record], 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
}
