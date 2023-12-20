<?php

/** Effectue la transformation d'une fiche XML ISO 19139 en DCAT-AP ou GeoDCAT-AP en utilisant le XSLT */
class DcatAp {
  const MODELS = [
    'DCAT-AP'=> [
      'xslt'=> 'https://raw.githubusercontent.com/SEMICeu/iso-19139-to-dcat-ap/master/iso-19139-to-dcat-ap.xsl',
      'params' => [
        'profile' => 'core'
      ],
    ],
    'GeoDCAT-AP'=> [
      'xslt'=> 'https://raw.githubusercontent.com/SEMICeu/iso-19139-to-dcat-ap/master/iso-19139-to-dcat-ap.xsl',
      'params' => [
        'profile' => 'extended',
      ],
    ],
  ];
  
  readonly public string $urlGmdFull;
  
  function __construct(string $urlGmdFull) { $this->urlGmdFull = $urlGmdFull; }

  /** Retourne la fiche transformée */
  function asEasyRdf(string $model): \EasyRdf\Graph {
    if (!isset(self::MODELS[$model]))
      throw new Exception("Erreur, model $model inconnu");
    
    // Loading the source document 
    $xml = new DOMDocument;
    if (!$xml->load($this->urlGmdFull)) {
      returnHttpError(404);
    }
    
    // Loading the XSLT to transform the source document into RDF/XML
    $xsl = new DOMDocument;
    if (!is_file('iso-19139-to-dcat-ap.xsl')) {
      $content = file_get_contents(self::MODELS[$model]['xslt']);
      file_put_contents('iso-19139-to-dcat-ap.xsl', $content);
    }
    if (!$xsl->load('iso-19139-to-dcat-ap.xsl')) {
      returnHttpError(404);
    }
    
    // Transforming the source document into RDF/XML
    $proc = new XSLTProcessor();
    $proc->importStyleSheet($xsl);

    foreach (self::MODELS[$model]['params'] as $k => $v) {
      $proc->setParameter("", $k, $v);
    }

    if (!$rdf = $proc->transformToXML($xml)) {
      returnHttpError(404);
    }
    
    // Setting namespace prefixes
    \EasyRdf\RdfNamespace::set('adms', 'http://www.w3.org/ns/adms#');
    \EasyRdf\RdfNamespace::set('cnt', 'http://www.w3.org/2011/content#');
    \EasyRdf\RdfNamespace::set('dc', 'http://purl.org/dc/elements/1.1/');
    \EasyRdf\RdfNamespace::set('dcat', 'http://www.w3.org/ns/dcat#');
    \EasyRdf\RdfNamespace::set('dqv', 'http://www.w3.org/ns/dqv#');
    \EasyRdf\RdfNamespace::set('geodcatap', 'http://data.europa.eu/930/');
    \EasyRdf\RdfNamespace::set('geosparql', 'http://www.opengis.net/ont/geosparql#');
    \EasyRdf\RdfNamespace::set('locn', 'http://www.w3.org/ns/locn#');
    \EasyRdf\RdfNamespace::set('prov', 'http://www.w3.org/ns/prov#');
    
    // Creating the RDF graph from the RDF/XML serialisation
    $graph = new \EasyRdf\Graph;
    $graph->parse($rdf, null, 'URI');
    
    return $graph;
  }
};

/** Supprime les blankNodes dans la description des sous-ressources */
class BlankNode {
  const DEBUG = false;
  
  static function del(array|string $graph): array|string {
    if (is_array($graph) && isset($graph['@graph'])) {
      if (count($graph['@graph']) <> 1)
        throw new Exception("Erreur, le graph ne peut être remplacé par une ressource");
      $graph = $graph['@graph'][0];
    }
    return self::delOnResource($graph, []);
  }
  
  static function delOnResource(array|string $resource, array $path): array|string {
    // $resource décrit une ressource décrite 
    //   - soit par un uri
    //   - soit par des propriétés qui peut être
    //      => array≤prop,<object>|list<object>>
    // où <object> peut être:
    //  - une ressource
    //  - un littéral ayant au moins le champ '@value' défini
    if (is_string($resource)) {
      if (self::DEBUG)
        echo implode('>',$path)," -> URI $resource<br>\n";
      return $resource;
    }
    if (self::DEBUG)
      echo implode('/',$path)," correspond à un ensemble propriétés<br>\n";
    foreach ($resource as $prop => $objects) {
      if (($prop == '@id') && (substr($objects, 0, 2)=='_:')) {
        unset($resource[$prop]);
      }
      elseif (is_string($objects) || !array_is_list($objects)) {
        $resource[$prop] = self::delOnObject($objects, array_merge($path, [$prop]));
      }
      else {
        $newObject = [];
        foreach ($objects as $object) {
          $newObject[] = self::delOnObject($object, array_merge($path, [$prop]));
        }
        $resource[$prop] = $newObject;
      }
    }
    return $resource;
  }
  
  static function delOnObject(array|string $object, array $path): array|string {
    // $object peut être:
    //  - soit une ressource
    //  - soit un littéral ayant au moins le champ '@value' défini
    if (is_string($object)) {
      if (self::DEBUG)
        echo implode('>',$path)," -> URI $object<br>\n";
      return $object;
    }
    elseif (isset($object['@value'])) {
      if (self::DEBUG)
        echo implode('>',$path)," -> littéral ",$object['@value'],"<br>\n";
      return $object;
    }
    else {
      if (self::DEBUG)
        echo implode('>',$path)," -> ressource, appel récursif<br>\n";
      return self::delOnResource($object, $path);
    }
  }
};
 