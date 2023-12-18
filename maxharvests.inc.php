<?php
/** Fonction maxHarvest()
 */

function maxHarvest(): int { // retourne le no du dernier répertoire harvest ou -1 s'il n'y en n'a pas
  if (!is_dir(__DIR__.'/harvests')) {
    mkdir(__DIR__.'/harvests');
    return -1;
  }
  $maxHarvest = -1;
  foreach (new DirectoryIterator(__DIR__.'/harvests') as $path) {
    //echo "$path\n";
    if ((substr($path, 0, 7) == 'harvest') && $path->isDir() && is_numeric(substr($path, 7))) {
      $hnum = substr($path, 7);
      if ($hnum > $maxHarvest)
        $maxHarvest = $hnum;
    }
  }
  //echo "maxHarvest=$maxHarvest\n";
  return $maxHarvest;
}
