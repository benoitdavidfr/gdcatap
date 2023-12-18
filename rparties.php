<?php
/** Affiche les rparties des fiches non sélectionnées.
 * Une fiche est sélectionnée ssi au moins un de ses RParties appartient au pôle ministériel.
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/inspiremd.inc.php';
require_once __DIR__.'/maxharvests.inc.php';

use Symfony\Component\Yaml\Yaml;

$harvest = maxHarvest();

if ($argc == 1) { // appel sans paramètre -> affiche la documentation
  echo "usage: php $argv[0] {catId}\n";
  echo "Liste des catalogues:\n";
  foreach (new DirectoryIterator(__DIR__."/harvests/harvest$harvest") as $path) {
    if ($path->isDir() && !$path->isDot())
    echo " - $path\n";
  }
  die();
}

$catId = $argv[1];

class RParty {
  static array $namesIn=[]; // liste des rParties du pôle ministériel
  static array $namesOut=[]; // liste des RParties des fiches non sélectionnées [{string} => 1] 

  static function init(string $catId): void {
    self::$namesIn = is_file(__DIR__."/rparty/$catId.yaml") ? Yaml::parseFile(__DIR__."/rparty/$catId.yaml"): [];
  }
  
  static function add(array $rParties): void {
    if (is_array($rParties) && array_is_list($rParties)) {
      // si un des rParty est dans la sélection alors la fiche est dans la sélection et il est inutile de s'occuper des autres
      foreach ($rParties as $rParty) {
        if (isset($rParties['name']) && in_array($rParty['name'], self::$namesIn)) {
          return;
        }
      }
      // Si aucune des rParty est dans la sélection alors je les met ttes dans $namesOut
      foreach ($rParties as $rParty) {
        if (isset($rParties['name']))
          self::$namesOut[$rParty['name']] = 1;
      }
    }
    elseif ($rParties) {
      if (isset($rParties['name']) && !in_array($rParties['name'], self::$namesIn)) {
        self::$namesOut[$rParties['name']] = 1;
      }
    }
  }
};
RParty::init($catId);

$mdServer = new MdServer($catId, "harvest$harvest", $catId, 1);
foreach ($mdServer as $no => $md) {
  //echo " - $md->dc_title ($md->dc_type)\n";
  if ($md->dc_type == 'FeatureCatalogue') continue;
  //echo "$catId: getFullGmd $no/",$mdServer->numberOfRecordsMatched(),"\n";
  $xml = $mdServer->getFullGmd();
  $record = InspireMd::convert($xml);
  $rParties = $record['responsibleParty'] ?? [];
  //echo Yaml::dump([(string)$md->dc_identifier => $rParties], 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  RParty::add($rParties);
}

ksort(RParty::$namesOut);
echo "RParties définies hors du pôle ministériel:\n";
echo Yaml::dump(array_keys(RParty::$namesOut));
