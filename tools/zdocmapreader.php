<?php
namespace dxn\zdoc;

class ZDocMapReader {

	const REGEX_THING_DEFINITION = '/^thing.*?([0-9]+?).*?{(.*?)}/sim';

	public function __construct($options) {
	}
	
	public function run($map_file) {
		$map_files = explode(",", $map_file);
		foreach ($map_files as $mapinfo_file) {
			if (!file_exists($mapinfo_file)) {
				throw new \Exception("Could not find WAD file: " . $mapinfo_file);
			}
			$file_string = file_get_contents($mapinfo_file);
			$results = [];
			$things = [];
			preg_match_all(self::REGEX_THING_DEFINITION, $file_string, $results);
			for ($i = 0; $i < count($results[2]); $i++) {
				$thing_definition = trim($results[2][$i]);
				$lines = explode("\n", $thing_definition);
				$thing = [];
				foreach ($lines as $line) {
					if (strpos($line, " = ") === false) {
						continue;
					}
					$property_and_value = explode(" = ", $line);
					$thing[trim($property_and_value[0])] = trim(str_replace(";", "", $property_and_value[1]));
				}
				$things[] = $thing;
			}
		}
		return $things;
	}


}