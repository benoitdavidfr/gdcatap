<?php
/** Construit harvests/harvest{i}/{catId}.json et harvests/harvest{i}/root.json.
 * harvests/harvest{i}/{catId}.json liste les fiches sélectionnées du catalogue {catId} réparties par page.
 * harvests/harvest{i}/root.json définit la répartition des pages d'export par catalogue.
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/inspiremd.inc.php';
require_once __DIR__.'/maxharvests.inc.php';

use Symfony\Component\Yaml\Yaml;

if ($argc == 1) { // appel sans paramètre -> affiche la documentation
  echo "usage: php $argv[0] [{catId}|all|root]\n";
  echo "  php $argv[0] {catId}   génère le fichier des fiches sélectionnées du catalogue {catId} réparties par page\n";
  echo "  php $argv[0] root   génère le fichier de répartition des pages d'export par catalogue.\n";
  echo "  php $argv[0] all   génère les commandes sh pour enchainer la génération des différents fichiers\n";
  die();
}

if ($argv[1] == 'all') {
  foreach (Server::servers() as $catId => $cat) {
    if (!in_array($catId, ['sigloire','picto'])) continue;
    echo "php $argv[0] $catId\n";
  }
  echo "php $argv[0] root\n";
  die();
}

$harvest = maxHarvest();

if ($argv[1] == 'root') { // Génère le fichier harvests/harvest{i}/root.json.
  $noPage = 0;
  $root = [];
  foreach (Server::servers() as $catId => $cat) {
    if (!in_array($catId, ['sigloire','picto'])) continue;
    if (!is_file(__DIR__."/harvests/harvest$harvest/$catId.json"))
      die("Erreur le fichier harvest$harvest/$catId.json est absent\n");
    $pages = json_decode(file_get_contents(__DIR__."/harvests/harvest$harvest/$catId.json"), true);
    $nbPages = count($pages);
    $nbItems = 0;
    foreach ($pages as $page)
      $nbItems += count($page);
    $root[$catId] = ['start'=> $noPage, 'nbItems'=> $nbItems, 'nbPages'=> $nbPages];
    $noPage += $nbPages;
  }
  file_put_contents(__DIR__."/harvests/harvest$harvest/root.json", json_encode($root, JSON_PRETTY_PRINT));
  die();
}

// génère le fichier harvests/harvest{i}/{catId}.json
const NB_PER_PAGE = 100; // nbre de fiches par page

$catId = $argv[1];

class RPartyIn { // implémente le test pour savoir si une fiche est sélectionnée ou non
  static array $namesIn=[]; // liste des rParties du pôle ministériel

  static function init(string $catId): void {
    self::$namesIn = is_file(__DIR__."/rparty/$catId.yaml") ? Yaml::parseFile(__DIR__."/rparty/$catId.yaml"): [];
  }
  
  static function mdSelected(string $gmd): bool { // la fiche de MD fait-elle partie de la sélection ?
    $record = InspireMd::convert($gmd);
    $rParties = $record['responsibleParty'] ?? [];
    if (!$rParties) return false;
    if (!array_is_list($rParties)) {
      return in_array($rParties['name'] ?? '', self::$namesIn);
    }
    foreach ($rParties as $rParty) {
      if (in_array($rParty['name'] ?? '', self::$namesIn))
        return true;
    }
    return false;
  }
};
RPartyIn::init($catId);

$noMd = 0; // no de MD sélectionnée
$pages = []; // liste des fiches sélectionnées définies par leur fid réparties par page
$mdServer = new MdServer($catId, "harvest$harvest", $catId, 1);
foreach ($mdServer as $no => $md) {
  //echo " - $md->dc_title ($md->dc_type)\n";
  if (in_array((string)$md->dc_type, ['FeatureCatalogue','service'])) continue;
  $gmd = $mdServer->getFullGmd();
  if (!RPartyIn::mdSelected($gmd)) continue;
  $noPage = intval(floor($noMd++ / NB_PER_PAGE));
  $pages[$noPage][] = (string)$md->dc_identifier;
}
//echo Yaml::dump($pages);
echo "$no fiches sélectionnées\n";

file_put_contents(__DIR__."/harvests/harvest$harvest/$catId.json", json_encode($pages, JSON_PRETTY_PRINT));
