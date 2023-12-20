<?php
/** Interrogation à la volé d'un catalogue et affichage d'une fiche en GeoDCAT-AP ou en DCAT-AP */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/dcatapapi.inc.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

//echo '<pre>'; print_r($_SERVER);

if (!isset($_SERVER['PATH_INFO'])) { // sans paramètre -> choix d'un catalogue
  echo "<h2>Liste des catalogues</h2><ul>\n";
  foreach (Server::servers() as $catId => $cat) {
    echo "<li><a href='$_SERVER[SCRIPT_NAME]/$catId'>$cat[title]</a></li>\n";
  }
  die();
}

const MAXRECORDS = 25;
if (preg_match('!^/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // /{catId} -> formulaire de recherche sur expression
  $catId = $matches[1];
  echo "Catalogue $catId<br>\n";
  $query = $_GET['query'] ?? '';
  $startPos = $_GET['startPos'] ?? 1;
  echo "<form><input <input type='text' name='query' size=80 value='",htmlspecialchars($query),"'></form>\n";
  if ($query) {
    $cswServer = new CswServer($catId, '', '');
    $filter = [
      'Filter'=> [
        'And'=> [
          'PropertyIsLike' => ['PropertyName'=> 'AnyText','Literal'=> "%$query%"],
          'PropertyIsEqualTo'=> ['PropertyName'=> 'dc:type', 'Literal'=> 'dataset'],
        ],
      ],
    ];
    $records = $cswServer->getRecords('dc', 'brief', $startPos, $filter, MAXRECORDS);
    $sxe = new SimpleXMLElement(str_replace(['csw:','dc:'],['csw_','dc_'], $records));
    if ($sxe->Exception) {
      throw new Exception("Exception retournée: ".$sxe->Exception->ExceptionText);
    }
    $numberOfRecordsMatched = (int)$sxe->csw_SearchResults['numberOfRecordsMatched'];
    $nextRecord = (int)$sxe->csw_SearchResults['nextRecord'];
    //echo "nextRecord=$nextRecord, numberOfRecordsMatched=$numberOfRecordsMatched<br>\n";
    //echo '<pre>',str_replace('<','&lt;', $records), "</pre>\n";
    $endPos = $startPos + MAXRECORDS - 1;
    if ($endPos > $numberOfRecordsMatched)
      $endPos = $numberOfRecordsMatched;
    echo "$startPos -> $endPos / $numberOfRecordsMatched<br>\n";
    echo "<table border=1>\n";
    foreach ($sxe->csw_SearchResults->csw_BriefRecord as $record) {
      //echo '<pre>'; print_r($record); echo "</pre>\n";
      //echo "identifier: ",$record->dc_identifier,"<br>\n";
      echo "<tr><td><a href='$_SERVER[SCRIPT_NAME]/$catId/",urlencode($record->dc_identifier),"'>",
            $record->dc_title,"</a></td></tr>";
    }
    echo "</table>\n";
    if ($nextRecord) {
      echo "<a href='$_SERVER[SCRIPT_NAME]/$catId?query=",urlencode($query),
            "&amp;startPos=$nextRecord'>suivant ($nextRecord/$numberOfRecordsMatched)<br>\n";
    }
  }
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)(/([^.]+)(\.(.*))?)?$!', $_SERVER['PATH_INFO'], $matches)) { // /{catId}/{fid} -> Affichage de la fiche
  //print_r($matches); echo "<br>\n";
  $catId = $matches[1];
  $fid = $matches[2];
  $model = $matches[4] ?? 'GeoDCAT-AP';
  if ($model <> 'DCAT-AP')
    $model = 'GeoDCAT-AP';
  $fmt = $matches[6] ?? null;
  
  echo "catId=$catId, fid=$fid, model=$model, fmt=$fmt<br>\n";
  $cswServer = new CswServer($catId, '', '');
  $furl = $cswServer->getRecordByIdUrl('gmd','full',$fid);
  $dcatAp = new DcatAP($furl);
  $rdf = $dcatAp->asEasyRdf($model);
  
  switch ($fmt) {
    case 'ttl': {
      echo '<pre>',htmlspecialchars($rdf->serialise('turtle'));
      break;
    }
    case null:
    case 'jsonld': {
      $frame = ['@type'=> 'http://www.w3.org/ns/dcat#Dataset'];
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
  
      echo '<pre>',Yaml::dump($framed, 10, 2);
      break;
    }
  }
  die();
}

die("Erreur, Paramètres non interprétés\n");