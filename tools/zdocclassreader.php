<?php
namespace dxn\zdoc;

require_once('./zdocclass.php');

class ZDocClassReader {

	var $decorate_folder = null;
	var $zscript_folder = null;
	var $mapinfo = null;
	var $errors = [];

	const REGEX_ACTOR_DEFINITION = '/^\h*actor\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ZSCRIPT_ACTOR_DEFINITION = '/^\h*class\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ZSCRIPT_DEFAULT_DEFINITION = '/default\h*?{(.*?)}/is';
	const REGEX_ACTOR_DOC = '/\/\*\*\h*(.*?)\h*\*\/.*?actor\h*([A-Za-z0-9_]+)/si';
	const REGEX_STATE = '/^\h*[A-Za-z0-9]{4}\h+[a-zA-\]"]*\h+[-]?[0-9rR]+/m';
	const REGEX_STATE_DEFINITION = '/states.*?{(.*?)}/is';
	const REGEX_MAPINFO_DOOMED_NUMBERS = '/doomednums.*?{(.*?)}/si';

	var $multi_token_properties = [
		'dropitem',
		'player.startitem',
		'powerup.colormap',
		'damage',
		'painchance',
		'bloodtype',
		'health.lowmessage',
		'player.colorrange',
		'player.weaponslot',
		'player.colorset',
		'powerup.color',
		'//$category',
		'//$title',
		'damagefunction'
	];

	public function __construct($options) {
		$this->decorate_folder = isset($options['decorate']) ? $options['decorate'] : null;
		$this->zscript_folder = isset($options['zscript']) ? $options['zscript'] : null;
		$this->mapinfo = isset($options['mapinfo']) ? $options['mapinfo'] : null;
	}
	
	public function run() {
		$class_names_to_data = [];
		if (!empty($this->decorate_folder)) {
			$class_names_to_data = array_merge($class_names_to_data, $this->examine_script_folder($this->decorate_folder, 'decorate'));
		}
		if (!empty($this->zscript_folder)) {
			$class_names_to_data = array_merge($class_names_to_data, $this->examine_script_folder($this->zscript_folder, 'zscript'));
		}
		
		//Look at replaced classes. If anything is mentioned in another class's 'replaces' field, it's been replaced.
		foreach ($class_names_to_data as $classname => $data) {
			if (isset($data->replaces_class_name)) {
				if (!isset($class_names_to_data[strtolower($data->replaces_class_name)])) {
					continue;
				}
				$class_names_to_data[strtolower($data->replaces_class_name)]->replaced_by_class_name = $classname;
			}
		}
		
		//If we have a MAPINFO, parse the DoomEd numbers out of that and attach them to our classes
		if (isset($this->mapinfo)) {
			$doomed_numbers = $this->get_doomed_numbers();
			foreach ($doomed_numbers as $doomed_number) {
				if (!isset($class_names_to_data[strtolower($doomed_number['class'])])) {
					continue;
				}
				$class_names_to_data[strtolower($doomed_number['class'])]->doomed_number = $doomed_number['number'];
			}
		}
		return $class_names_to_data;
	}
	
	public function get_doomed_numbers() {
		$doomed_numbers = $this->get_default_doomed_numbers();
		$mapinfo_files = explode(",", $this->mapinfo);
		foreach ($mapinfo_files as $mapinfo_file) {
			if (!file_exists($mapinfo_file)) {
				throw new \Exception("Could not find MAPINFO file: " . $mapinfo_file);
			}
			$file_string = file_get_contents($mapinfo_file);
			$results = [];
			//Can you have multiple DoomEdNums definitions? Doing it anyway
			preg_match_all(self::REGEX_MAPINFO_DOOMED_NUMBERS, $file_string, $results);			
			for ($i = 0; $i < count($results[1]); $i++) {
				$doomed_list = trim($results[1][$i]);
				$lines = explode(PHP_EOL, $doomed_list);
				foreach ($lines as $line) {
					if (strpos($line, '//') !== false) {
						$line = substr($line, 0, strpos($line, '//')-1);
					}
					$line = trim($line);
					if (empty($line)) {
						continue;
					}
					$num_and_class = explode('=', $line);
					if (count($num_and_class) != 2) {
						continue;
					}
					$num = trim($num_and_class[0]);
					$class = strtolower(trim($num_and_class[1]));
					$doomed_numbers[] = ['class' => $class, 'number' => $num];
				}
			}			
		}
		return $doomed_numbers;
	}
	
	public function examine_script_folder($decorate_folder, $mode = 'decorate') {
		$source_folders = explode(",", $decorate_folder);
		$class_names_to_data = [];
		
		foreach ($source_folders as $source_folder) {
			$folders_to_run = [$source_folder];
			while(count($folders_to_run) > 0) {
				$decorate_folder = array_shift($folders_to_run);
				//Get the files in the folder
				$files = scandir($decorate_folder);
				foreach ($files as $key => $value) { 
					  if (!in_array($value, [".",".."])) {
						$full_file_name = $decorate_folder . DIRECTORY_SEPARATOR . $value;

						if (is_dir($full_file_name)) {
							//Add to the list
							$folders_to_run[] = $full_file_name;
						}
						else {
							$class_names_to_data = array_merge($class_names_to_data, $this->examine_script_file($full_file_name, $mode, $source_folder));
						}
					}
				}
			}
		}
		
		return $class_names_to_data;
	}

	public function examine_script_file($full_file_name, $mode = 'decorate', $source_folder) {
		echo('Parsing ' . $full_file_name . PHP_EOL);
		$class_names_to_data = [];
		$file_string = file_get_contents($full_file_name);

		//Get class definitions and add them to our master list
		$results = [];
		if ($mode == 'decorate') {
			preg_match_all(self::REGEX_ACTOR_DEFINITION, $file_string, $results, PREG_OFFSET_CAPTURE);			
		}
		else {
			preg_match_all(self::REGEX_ZSCRIPT_ACTOR_DEFINITION, $file_string, $results, PREG_OFFSET_CAPTURE);
		}
		foreach ($results[0] as $result_array) {
			$result = $result_array[0];
			$result_offset = $result_array[1];
			if (strpos($result, '//') !== false) {
				$result = substr($result, 0, strpos($result, '//')-1);
			}
			if (strpos($result, '/*') !== false) {
				$result = substr($result, 0, strpos($result, '/*')-1);
			}
			$result = trim($result);
			$result = substr($result, 5); //Cut off "class"
			$result = trim($result);
			//Split into tokens.
			$result = str_replace(["\t", "{", "}"], " ", $result);
			$result = str_replace([":"], " : ", $result);
			$tokens = preg_split('@\h@', $result, NULL, PREG_SPLIT_NO_EMPTY);
			$classes = [];
			$doomed_number = null;
			foreach ($tokens as $token) {
				//Assume this is a DoomEd number if it's numeric
				if (is_numeric(trim($token))) {
					$doomed_number = trim($token);
				}
				if (!is_numeric(trim($token)) && trim($token) != ':' && strtolower(trim($token)) != 'replaces') {
					$classes[] = $token;
				}
			}
			//Now add these to our master list
			$class = new ZDocClass();
			$class->name = trim($classes[0]);
			if (isset($classes[1])) { $class->parent_class_name = trim($classes[1]); }
			if (isset($classes[2])) { $class->replaces_class_name = trim($classes[2]); }
			$class_data = $this->get_class_data(substr($file_string, $result_offset), $mode);
			$class->flags = $class_data['flags'];
			$class->properties = $class_data['properties'];
			$class->vars = $class_data['vars'];
			$class->consts = $class_data['consts'];
			$class->sprite_name = $class_data['sprite'];
			$class->states = $class_data['states'];
			
			$class->definition_filename = substr($full_file_name, strlen($source_folder));
			$class->category = $this->get_category($class->definition_filename, (isset($class->parent_class_name) ? $class->parent_class_name : ''));
			$class->doomed_number = $doomed_number;
			$class_names_to_data[strtolower($class->name)] = $class;
		}
		
		//Get documentation comments and add them to our entries in the list
		$results = [];
		preg_match_all(self::REGEX_ACTOR_DOC, $file_string, $results);
		for ($i = 0; $i < count($results[1]); $i++) {
			$comment = trim($results[1][$i]);
			$class_name = trim($results[2][$i]);
			$class_name = strtolower($class_name);
			if (isset($class_names_to_data[$class_name])) {
				$class_names_to_data[$class_name]->comment = $comment;
			} else {
				$this->errors[] = 'Comment appears to be for class ' . $class_name . ' which does not exist';
			}
		}
		
		return $class_names_to_data;
	}
	
	public function get_class_data($class_string, $mode = 'decorate') {
		
		$vars = [];
		$consts = [];
		$bracket_depth = 0;
		$started_class = false;
		$starting_index = 0;
		for($i = 0; $i < strlen($class_string); $i++) {
			$char = $class_string[$i];
			if ($char == '{') {
				$bracket_depth++;
				if ($started_class == false) {
					$starting_index = $i+1;
					$started_class = true;
				}
			}
			if ($char == '}') {
				$bracket_depth--;
			}
			if ($bracket_depth == 0 && $started_class) {
				$class_string = substr($class_string, $starting_index, $i-$starting_index);
				break;
			}
		}
		
		//For states, get everything between the states{ and } tokens
		$state_results = [];
		preg_match_all(self::REGEX_STATE_DEFINITION, $class_string, $state_results);
		$states = isset($state_results[1][0]) ? $state_results[1][0] : '';
		
		//If this class has a //$Sprite definition, add that as our sprite.	
		$sprite = null;
		if (isset($properties['//$sprite'])) {
			$sprite = $properties['//$sprite']['value'];
		}
		//If it doesn't, let's try using the first thing that looks like a sprite
		else {
			$sprite = $this->get_first_sprite($class_string);
			if ($sprite != null) {
				$sprite .= 'X';
			}
		}
		if (empty($sprite)) {
			$sprite = 'nosprite';
		}
		
		//If we're dealing with ZScript, it will have a default {} declaration - it's only the lines in here that we want to examine for properties. Otherwise we have to hack a bit.
		$default_results = [];
		preg_match_all(self::REGEX_ZSCRIPT_DEFAULT_DEFINITION, $class_string, $default_results);
		//If this is ZSCRIPT and we don't have a default, then there are no properties/flags
		if ($mode == 'zscript' && !isset($default_results[1][0])) {
			return ['flags' => [], 'properties' => [], 'sprite' => $sprite, 'vars' => $vars, 'consts' => $consts, 'states' => $states];
		}
		$class_string = isset($default_results[1][0]) ? $default_results[1][0] : $class_string;
		//Our class string is now just the class we want. Now break into lines and examine the properties and flags.
		$flags = [];
		$properties = [];
		$damage_factors = '';

		
		$lines = explode("\n", $class_string);
		foreach ($lines as $line) {
			$line = trim($line);
			$line = str_replace([";", "{", "}"], "", $line);
			if (strlen($line) == 0) {
				continue;
			}
			if (strtolower(substr($line, 0, 7)) === 'default') {
				continue;
			}
			if (strpos(strtolower($line), 'states') !== false) {
				break;
			}
			
			//By this time, we're either looking at a property or a flag. Split it into tokens, respecting quoted fields
			$tokens = [];
			$current_token = '';
			$in_quotes = false;
			for ($i = 0; $i < strlen($line); $i++) {
				if ($line[$i] == '/' && $i < strlen($line)-1 && $line[$i+1] == '/') {
					if (!isset($line[$i+2]) || $line[$i+2] != '$') {
						break;
					}
				}
				if (in_array($line[$i], [' ', '\t']) && !$in_quotes) {
					$tokens[] = $current_token;
					$current_token = '';
					continue;
				}
				if ($line[$i] == "\"") {
					$in_quotes = !$in_quotes;
				}
				$current_token .= $line[$i];
			}
			if (!empty($current_token) || $current_token === "0") {
				$tokens[] = $current_token;
			}
			$current_token = '';
			
			if (count($tokens) == 0 || empty($tokens[0])) {
				continue;
			}
			
			$property_name = strtolower($tokens[0]);
			//If there's just one token, it's a flag
			if (count($tokens) == 1) {
				$direction = 1;
				$flag_name = strtoupper($tokens[0]);
				if ($flag_name[0] == '+') {
					$flag_name = substr($flag_name, 1);
				}
				else if ($flag_name[0] == '-') {
					$flag_name = substr($flag_name, 1);
					$direction = 0;
				}
				$flags[$flag_name] = ['name' => $flag_name, 'value' => $direction];
			}
			//If there are two tokens, try to parse it as a property
			else if (count($tokens) == 2) {
				$property_value = $tokens[1];
				$properties[$property_name] = ['name' => $property_name, 'value' => $property_value];
			}			
			//Some special cases for other language features
			else if ($property_name == 'var') {
				$var_name = $tokens[1];
				$vars[] = $var_name;
			}
			else if ($property_name == 'const') {
				$const_name = $tokens[1];
				$consts[] = $const_name;
			}
			else if ($property_name == 'translation') {
				$translations_string = '';
				for ($i = 1; $i < count($tokens); $i++) {
					$translations_string .= $tokens[$i] . '<br/>';
				}
				$properties[$property_name] = ['name' => 'translation', 'value' => $translations_string];
			}
			else if (in_array($property_name, $this->multi_token_properties)) {
				if (!isset($properties[$property_name])) {
					$properties[$property_name] = ['name' => $property_name, 'value' => ''];
				}
				for ($i = 1; $i < count($tokens); $i++) {
					$properties[$property_name]['value'] .= $tokens[$i] . " ";
				}
			}
			//Damagefactors can have multiple definitions on the same line. They come in threes...
			else if ($property_name == 'damagefactor') {
				$current_damagetype = '';
				for ($i = 0; $i < count($tokens); $i++) {
					if ($i % 3 == 0) {
						//Damage type keyword
						continue;
					}
					if ($i % 3 == 1) {
						//Damage type
						$current_damagetype = str_replace(",", "", $tokens[$i]);
					}
					if ($i % 3 == 2) {
						//Damage amount
						$damage_factors .= $current_damagetype . " " . $tokens[$i] . '<br/>';
					}
				}
			}
			else {
				try {
					//Attempt to parse all of the items as flags
					for ($i = 0; $i < count($tokens); $i++) {
						if (empty($tokens[$i])) {
							continue;
						}
						$direction = 1;
						$flag_name = strtoupper($tokens[$i]);
						if ($flag_name[0] == '+') {
							$flag_name = substr($flag_name, 1);
						}
						else if ($flag_name[0] == '-') {
							$flag_name = substr($flag_name, 1);
							$direction = 0;
						}
						else {
							echo ('Could not parse line as property or flag: ' . $line . PHP_EOL);
							throw new \Exception('Could not parse property or flag: ' . $line);
						}
						$flags[$flag_name] = ['name' => $flag_name, 'value' => $direction];
					}
				} catch (\Exception $ex) {
					$this->errors[] = $ex->getMessage();
				}

			}
		}
		//Finalize the properties that are lists
		if (strlen($damage_factors) > 0) {
			$properties['damagefactor'] = ['name' => 'damagefactor', 'value' => $damage_factors];
		}
		return ['flags' => $flags, 'properties' => $properties, 'sprite' => $sprite, 'vars' => $vars, 'consts' => $consts, 'states' => $states];
	}
	
	public function get_first_sprite($class_string) {
		$results = [];
		preg_match_all(self::REGEX_STATE, $class_string, $results);
		foreach ($results[0] as $result) {
			$result = trim($result);
			//If we now have fewer than 8 characters, this is a false result so discard it
			if (strlen($result) < 8) {
				continue;
			}
			//Our first four characters are the sprite name
			$sprite_name = strtoupper(substr($result, 0, 4));
			if ($sprite_name == 'TNT1') { //The blank sprite
				continue;
			}
			$result = trim(strtoupper(substr($result, 4)));
			$char = substr($result, 0, 1); //Here's our frame letter
			return $sprite_name . $char;
		}

	}
	
	public function get_category($file_path, $parent) {
		if (strpos($file_path, 'monster')) {
			return 'monster';
		}
		if (strpos($file_path, 'weapon')) {
			return 'weapon';
		}
		return 'thing';
	}

	public function get_default_doomed_numbers() {
		return [	
			['class' => 'Unknown', 'number' => 0],
			['class' => 'Player1Start', 'number' => 1],
			['class' => 'Player2Start', 'number' => 2],
			['class' => 'Player3Start', 'number' => 3],
			['class' => 'Player4Start', 'number' => 4],
			['class' => 'DeathmatchStart', 'number' => 11],
			['class' => 'TeleportDest', 'number' => 14],
			['class' => 'ZBridge', 'number' => 118],
			['class' => 'MBFHelperDog', 'number' => 888],
			['class' => '$SSeqOverride 0', 'number' => 1400],
			['class' => '$SSeqOverride 1', 'number' => 1401],
			['class' => '$SSeqOverride 2', 'number' => 1402],
			['class' => '$SSeqOverride 3', 'number' => 1403],
			['class' => '$SSeqOverride 4', 'number' => 1404],
			['class' => '$SSeqOverride 5', 'number' => 1405],
			['class' => '$SSeqOverride 6', 'number' => 1406],
			['class' => '$SSeqOverride 7', 'number' => 1407],
			['class' => '$SSeqOverride 8', 'number' => 1408],
			['class' => '$SSeqOverride 9', 'number' => 1409],
			['class' => 'SSeqOverride', 'number' => 1411],
			['class' => 'VavoomFloor', 'number' => 1500],
			['class' => 'VavoomCeiling', 'number' => 1501],
			['class' => 'VavoomLightWhite', 'number' => 1502],
			['class' => 'VavoomLightColor', 'number' => 1503],
			['class' => 'VertexFloorZ', 'number' => 1504],
			['class' => 'VertexCeilingZ', 'number' => 1505],
			['class' => 'PointPusher', 'number' => 5001],
			['class' => 'PointPuller', 'number' => 5002],
			['class' => 'FS_Mapspot', 'number' => 5004],
			['class' => 'SkyCamCompat', 'number' => 5006],
			['class' => 'InvisibleBridge32', 'number' => 5061],
			['class' => 'InvisibleBridge16', 'number' => 5064],
			['class' => 'InvisibleBridge8', 'number' => 5065],
			['class' => 'MapSpot', 'number' => 9001],
			['class' => 'MapSpotGravity', 'number' => 9013],
			['class' => 'PatrolPoint', 'number' => 9024],
			['class' => 'SecurityCamera', 'number' => 9025],
			['class' => 'Spark', 'number' => 9026],
			['class' => 'RedParticleFountain', 'number' => 9027],
			['class' => 'GreenParticleFountain', 'number' => 9028],
			['class' => 'BlueParticleFountain', 'number' => 9029],
			['class' => 'YellowParticleFountain', 'number' => 9030],
			['class' => 'PurpleParticleFountain', 'number' => 9031],
			['class' => 'BlackParticleFountain', 'number' => 9032],
			['class' => 'WhiteParticleFountain', 'number' => 9033],
			['class' => 'BetaSkull', 'number' => 9037],
			['class' => 'ColorSetter', 'number' => 9038],
			['class' => 'FadeSetter', 'number' => 9039],
			['class' => 'MapMarker', 'number' => 9040],
			['class' => 'SectorFlagSetter', 'number' => 9041],
			['class' => 'TeleportDest3', 'number' => 9043],
			['class' => 'TeleportDest2', 'number' => 9044],
			['class' => 'Waterzone', 'number' => 9045],
			['class' => 'SecretTrigger', 'number' => 9046],
			['class' => 'PatrolSpecial', 'number' => 9047],
			['class' => 'SoundEnvironment', 'number' => 9048],
			['class' => 'InterpolationPoint', 'number' => 9070],
			['class' => 'PathFollower', 'number' => 9071],
			['class' => 'MovingCamera', 'number' => 9072],
			['class' => 'AimingCamera', 'number' => 9073],
			['class' => 'ActorMover', 'number' => 9074],
			['class' => 'InterpolationSpecial', 'number' => 9075],
			['class' => 'HateTarget', 'number' => 9076],
			['class' => 'UpperStackLookOnly', 'number' => 9077],
			['class' => 'LowerStackLookOnly', 'number' => 9078],
			['class' => 'SkyViewpoint', 'number' => 9080],
			['class' => 'SkyPicker', 'number' => 9081],
			['class' => 'SectorSilencer', 'number' => 9082],
			['class' => 'SkyCamCompat', 'number' => 9083],
			['class' => 'Decal', 'number' => 9200],
			['class' => 'PolyAnchor', 'number' => 9300],
			['class' => 'PolySpawn', 'number' => 9301],
			['class' => 'PolySpawnCrush', 'number' => 9302],
			['class' => 'PolySpawnHurt', 'number' => 9303],
			['class' => 'SlopeFloorPointLine', 'number' => 9500],
			['class' => 'SlopeCeilingPointLine', 'number' => 9501],
			['class' => 'SetFloorSlope', 'number' => 9502],
			['class' => 'SetCeilingSlope', 'number' => 9503],
			['class' => 'CopyFloorPlane', 'number' => 9510],
			['class' => 'CopyCeilingPlane', 'number' => 9511],
			['class' => 'PointLight', 'number' => 9800],
			['class' => 'PointLightPulse', 'number' => 9801],
			['class' => 'PointLightFlicker', 'number' => 9802],
			['class' => 'SectorPointLight', 'number' => 9803],
			['class' => 'PointLightFlickerRandom', 'number' => 9804],
			['class' => 'PointLightAdditive', 'number' => 9810],
			['class' => 'PointLightPulseAdditive', 'number' => 9811],
			['class' => 'PointLightFlickerAdditive', 'number' => 9812],
			['class' => 'SectorPointLightAdditive', 'number' => 9813],
			['class' => 'PointLightFlickerRandomAdditive', 'number' => 9814],
			['class' => 'PointLightSubtractive', 'number' => 9820],
			['class' => 'PointLightPulseSubtractive', 'number' => 9821],
			['class' => 'PointLightFlickerSubtractive', 'number' => 9822],
			['class' => 'SectorPointLightSubtractive', 'number' => 9823],
			['class' => 'PointLightFlickerRandomSubtractive', 'number' => 9824],
			['class' => 'VavoomLight', 'number' => 9825],
			['class' => 'PointLightAttenuated', 'number' => 9830],
			['class' => 'PointLightPulseAttenuated', 'number' => 9831],
			['class' => 'PointLightFlickerAttenuated', 'number' => 9832],
			['class' => 'SectorPointLightAttenuated', 'number' => 9833],
			['class' => 'PointLightFlickerRandomAttenuated', 'number' => 9834],
			['class' => 'SpotLight', 'number' => 9840],
			['class' => 'SpotLightPulse', 'number' => 9841],
			['class' => 'SpotLightFlicker', 'number' => 9842],
			['class' => 'SectorSpotLight', 'number' => 9843],
			['class' => 'SpotLightFlickerRandom', 'number' => 9844],
			['class' => 'SpotLightAdditive', 'number' => 9850],
			['class' => 'SpotLightPulseAdditive', 'number' => 9851],
			['class' => 'SpotLightFlickerAdditive', 'number' => 9852],
			['class' => 'SectorSpotLightAdditive', 'number' => 9853],
			['class' => 'SpotLightFlickerRandomAdditive', 'number' => 9854],
			['class' => 'SpotLightSubtractive', 'number' => 9860],
			['class' => 'SpotLightPulseSubtractive', 'number' => 9861],
			['class' => 'SpotLightFlickerSubtractive', 'number' => 9862],
			['class' => 'SectorSpotLightSubtractive', 'number' => 9863],
			['class' => 'SpotLightFlickerRandomSubtractive', 'number' => 9864],
			['class' => 'SpotLightAttenuated', 'number' => 9870],
			['class' => 'SpotLightPulseAttenuated', 'number' => 9871],
			['class' => 'SpotLightFlickerAttenuated', 'number' => 9872],
			['class' => 'SectorSpotLightAttenuated', 'number' => 9873],
			['class' => 'SpotLightFlickerRandomAttenuated', 'number' => 9874],
			['class' => 'SecActEyesAboveC', 'number' => 9982],
			['class' => 'SecActEyesBelowC', 'number' => 9983],
			['class' => 'CustomSprite', 'number' => 9988],
			['class' => 'SecActHitFakeFloor', 'number' => 9989],
			['class' => 'InvisibleBridge', 'number' => 9990],
			['class' => 'CustomBridge', 'number' => 9991],
			['class' => 'SecActEyesSurface', 'number' => 9992],
			['class' => 'SecActEyesDive', 'number' => 9993],
			['class' => 'SecActUseWall', 'number' => 9994],
			['class' => 'SecActUse', 'number' => 9995],
			['class' => 'SecActHitCeil', 'number' => 9996],
			['class' => 'SecActExit', 'number' => 9997],
			['class' => 'SecActEnter', 'number' => 9998],
			['class' => 'SecActHitFloor', 'number' => 9999],
			['class' => 'AmbientSound 1', 'number' => 14001],
			['class' => 'AmbientSound 2', 'number' => 14002],
			['class' => 'AmbientSound 3', 'number' => 14003],
			['class' => 'AmbientSound 4', 'number' => 14004],
			['class' => 'AmbientSound 5', 'number' => 14005],
			['class' => 'AmbientSound 6', 'number' => 14006],
			['class' => 'AmbientSound 7', 'number' => 14007],
			['class' => 'AmbientSound 8', 'number' => 14008],
			['class' => 'AmbientSound 9', 'number' => 14009],
			['class' => 'AmbientSound 10', 'number' => 14010],
			['class' => 'AmbientSound 11', 'number' => 14011],
			['class' => 'AmbientSound 12', 'number' => 14012],
			['class' => 'AmbientSound 13', 'number' => 14013],
			['class' => 'AmbientSound 14', 'number' => 14014],
			['class' => 'AmbientSound 15', 'number' => 14015],
			['class' => 'AmbientSound 16', 'number' => 14016],
			['class' => 'AmbientSound 17', 'number' => 14017],
			['class' => 'AmbientSound 18', 'number' => 14018],
			['class' => 'AmbientSound 19', 'number' => 14019],
			['class' => 'AmbientSound 20', 'number' => 14020],
			['class' => 'AmbientSound 21', 'number' => 14021],
			['class' => 'AmbientSound 22', 'number' => 14022],
			['class' => 'AmbientSound 23', 'number' => 14023],
			['class' => 'AmbientSound 24', 'number' => 14024],
			['class' => 'AmbientSound 25', 'number' => 14025],
			['class' => 'AmbientSound 26', 'number' => 14026],
			['class' => 'AmbientSound 27', 'number' => 14027],
			['class' => 'AmbientSound 28', 'number' => 14028],
			['class' => 'AmbientSound 29', 'number' => 14029],
			['class' => 'AmbientSound 30', 'number' => 14030],
			['class' => 'AmbientSound 31', 'number' => 14031],
			['class' => 'AmbientSound 32', 'number' => 14032],
			['class' => 'AmbientSound 33', 'number' => 14033],
			['class' => 'AmbientSound 34', 'number' => 14034],
			['class' => 'AmbientSound 35', 'number' => 14035],
			['class' => 'AmbientSound 36', 'number' => 14036],
			['class' => 'AmbientSound 37', 'number' => 14037],
			['class' => 'AmbientSound 38', 'number' => 14038],
			['class' => 'AmbientSound 39', 'number' => 14039],
			['class' => 'AmbientSound 40', 'number' => 14040],
			['class' => 'AmbientSound 41', 'number' => 14041],
			['class' => 'AmbientSound 42', 'number' => 14042],
			['class' => 'AmbientSound 43', 'number' => 14043],
			['class' => 'AmbientSound 44', 'number' => 14044],
			['class' => 'AmbientSound 45', 'number' => 14045],
			['class' => 'AmbientSound 46', 'number' => 14046],
			['class' => 'AmbientSound 47', 'number' => 14047],
			['class' => 'AmbientSound 48', 'number' => 14048],
			['class' => 'AmbientSound 49', 'number' => 14049],
			['class' => 'AmbientSound 50', 'number' => 14050],
			['class' => 'AmbientSound 51', 'number' => 14051],
			['class' => 'AmbientSound 52', 'number' => 14052],
			['class' => 'AmbientSound 53', 'number' => 14053],
			['class' => 'AmbientSound 54', 'number' => 14054],
			['class' => 'AmbientSound 55', 'number' => 14055],
			['class' => 'AmbientSound 56', 'number' => 14056],
			['class' => 'AmbientSound 57', 'number' => 14057],
			['class' => 'AmbientSound 58', 'number' => 14058],
			['class' => 'AmbientSound 59', 'number' => 14059],
			['class' => 'AmbientSound 60', 'number' => 14060],
			['class' => 'AmbientSound 61', 'number' => 14061],
			['class' => 'AmbientSound 62', 'number' => 14062],
			['class' => 'AmbientSound 63', 'number' => 14063],
			['class' => 'AmbientSound 64', 'number' => 14064],
			['class' => 'AmbientSound', 'number' => 14065],
			['class' => 'SoundSequence', 'number' => 14066],
			['class' => 'AmbientSoundNoGravity', 'number' => 14067],
			['class' => 'MusicChanger 1', 'number' => 14101],
			['class' => 'MusicChanger 2', 'number' => 14102],
			['class' => 'MusicChanger 3', 'number' => 14103],
			['class' => 'MusicChanger 4', 'number' => 14104],
			['class' => 'MusicChanger 5', 'number' => 14105],
			['class' => 'MusicChanger 6', 'number' => 14106],
			['class' => 'MusicChanger 7', 'number' => 14107],
			['class' => 'MusicChanger 8', 'number' => 14108],
			['class' => 'MusicChanger 9', 'number' => 14109],
			['class' => 'MusicChanger 10', 'number' => 14110],
			['class' => 'MusicChanger 11', 'number' => 14111],
			['class' => 'MusicChanger 12', 'number' => 14112],
			['class' => 'MusicChanger 13', 'number' => 14113],
			['class' => 'MusicChanger 14', 'number' => 14114],
			['class' => 'MusicChanger 15', 'number' => 14115],
			['class' => 'MusicChanger 16', 'number' => 14116],
			['class' => 'MusicChanger 17', 'number' => 14117],
			['class' => 'MusicChanger 18', 'number' => 14118],
			['class' => 'MusicChanger 19', 'number' => 14119],
			['class' => 'MusicChanger 20', 'number' => 14120],
			['class' => 'MusicChanger 21', 'number' => 14121],
			['class' => 'MusicChanger 22', 'number' => 14122],
			['class' => 'MusicChanger 23', 'number' => 14123],
			['class' => 'MusicChanger 24', 'number' => 14124],
			['class' => 'MusicChanger 25', 'number' => 14125],
			['class' => 'MusicChanger 26', 'number' => 14126],
			['class' => 'MusicChanger 27', 'number' => 14127],
			['class' => 'MusicChanger 28', 'number' => 14128],
			['class' => 'MusicChanger 29', 'number' => 14129],
			['class' => 'MusicChanger 30', 'number' => 14130],
			['class' => 'MusicChanger 31', 'number' => 14131],
			['class' => 'MusicChanger 32', 'number' => 14132],
			['class' => 'MusicChanger 33', 'number' => 14133],
			['class' => 'MusicChanger 34', 'number' => 14134],
			['class' => 'MusicChanger 35', 'number' => 14135],
			['class' => 'MusicChanger 36', 'number' => 14136],
			['class' => 'MusicChanger 37', 'number' => 14137],
			['class' => 'MusicChanger 38', 'number' => 14138],
			['class' => 'MusicChanger 39', 'number' => 14139],
			['class' => 'MusicChanger 40', 'number' => 14140],
			['class' => 'MusicChanger 41', 'number' => 14141],
			['class' => 'MusicChanger 42', 'number' => 14142],
			['class' => 'MusicChanger 43', 'number' => 14143],
			['class' => 'MusicChanger 44', 'number' => 14144],
			['class' => 'MusicChanger 45', 'number' => 14145],
			['class' => 'MusicChanger 46', 'number' => 14146],
			['class' => 'MusicChanger 47', 'number' => 14147],
			['class' => 'MusicChanger 48', 'number' => 14148],
			['class' => 'MusicChanger 49', 'number' => 14149],
			['class' => 'MusicChanger 50', 'number' => 14150],
			['class' => 'MusicChanger 51', 'number' => 14151],
			['class' => 'MusicChanger 52', 'number' => 14152],
			['class' => 'MusicChanger 53', 'number' => 14153],
			['class' => 'MusicChanger 54', 'number' => 14154],
			['class' => 'MusicChanger 55', 'number' => 14155],
			['class' => 'MusicChanger 56', 'number' => 14156],
			['class' => 'MusicChanger 57', 'number' => 14157],
			['class' => 'MusicChanger 58', 'number' => 14158],
			['class' => 'MusicChanger 59', 'number' => 14159],
			['class' => 'MusicChanger 60', 'number' => 14160],
			['class' => 'MusicChanger 61', 'number' => 14161],
			['class' => 'MusicChanger 62', 'number' => 14162],
			['class' => 'MusicChanger 63', 'number' => 14163],
			['class' => 'MusicChanger 64', 'number' => 14164],
			['class' => 'MusicChanger', 'number' => 14165],
			['class' => 'DoomBuilderCamera', 'number' => 32000],
			['class' => 'BlueCard', 'number' => 5],
			['class' => 'YellowCard', 'number' => 6],
			['class' => 'SpiderMastermind', 'number' => 7],
			['class' => 'Backpack', 'number' => 8],
			['class' => 'ShotgunGuy', 'number' => 9],
			['class' => 'GibbedMarine', 'number' => 10],
			['class' => 'GibbedMarineExtra', 'number' => 12],
			['class' => 'RedCard', 'number' => 13],
			['class' => 'DeadMarine', 'number' => 15],
			['class' => 'Cyberdemon', 'number' => 16],
			['class' => 'CellPack', 'number' => 17],
			['class' => 'DeadZombieMan', 'number' => 18],
			['class' => 'DeadShotgunGuy', 'number' => 19],
			['class' => 'DeadDoomImp', 'number' => 20],
			['class' => 'DeadDemon', 'number' => 21],
			['class' => 'DeadCacodemon', 'number' => 22],
			['class' => 'DeadLostSoul', 'number' => 23],
			['class' => 'Gibs', 'number' => 24],
			['class' => 'DeadStick', 'number' => 25],
			['class' => 'LiveStick', 'number' => 26],
			['class' => 'HeadOnAStick', 'number' => 27],
			['class' => 'HeadsOnAStick', 'number' => 28],
			['class' => 'HeadCandles', 'number' => 29],
			['class' => 'TallGreenColumn', 'number' => 30],
			['class' => 'ShortGreenColumn', 'number' => 31],
			['class' => 'TallRedColumn', 'number' => 32],
			['class' => 'ShortRedColumn', 'number' => 33],
			['class' => 'Candlestick', 'number' => 34],
			['class' => 'Candelabra', 'number' => 35],
			['class' => 'HeartColumn', 'number' => 36],
			['class' => 'SkullColumn', 'number' => 37],
			['class' => 'RedSkull', 'number' => 38],
			['class' => 'YellowSkull', 'number' => 39],
			['class' => 'BlueSkull', 'number' => 40],
			['class' => 'EvilEye', 'number' => 41],
			['class' => 'FloatingSkull', 'number' => 42],
			['class' => 'TorchTree', 'number' => 43],
			['class' => 'BlueTorch', 'number' => 44],
			['class' => 'GreenTorch', 'number' => 45],
			['class' => 'RedTorch', 'number' => 46],
			['class' => 'Stalagtite', 'number' => 47],
			['class' => 'TechPillar', 'number' => 48],
			['class' => 'BloodyTwitch', 'number' => 49],
			['class' => 'Meat2', 'number' => 50],
			['class' => 'Meat3', 'number' => 51],
			['class' => 'Meat4', 'number' => 52],
			['class' => 'Meat5', 'number' => 53],
			['class' => 'BigTree', 'number' => 54],
			['class' => 'ShortBlueTorch', 'number' => 55],
			['class' => 'ShortGreenTorch', 'number' => 56],
			['class' => 'ShortRedTorch', 'number' => 57],
			['class' => 'Spectre', 'number' => 58],
			['class' => 'NonsolidMeat2', 'number' => 59],
			['class' => 'NonsolidMeat4', 'number' => 60],
			['class' => 'NonsolidMeat3', 'number' => 61],
			['class' => 'NonsolidMeat5', 'number' => 62],
			['class' => 'NonsolidTwitch', 'number' => 63],
			['class' => 'Archvile', 'number' => 64],
			['class' => 'ChaingunGuy', 'number' => 65],
			['class' => 'Revenant', 'number' => 66],
			['class' => 'Fatso', 'number' => 67],
			['class' => 'Arachnotron', 'number' => 68],
			['class' => 'HellKnight', 'number' => 69],
			['class' => 'BurningBarrel', 'number' => 70],
			['class' => 'PainElemental', 'number' => 71],
			['class' => 'CommanderKeen', 'number' => 72],
			['class' => 'HangNoGuts', 'number' => 73],
			['class' => 'HangBNoBrain', 'number' => 74],
			['class' => 'HangTLookingDown', 'number' => 75],
			['class' => 'HangTSkull', 'number' => 76],
			['class' => 'HangTLookingUp', 'number' => 77],
			['class' => 'HangTNoBrain', 'number' => 78],
			['class' => 'ColonGibs', 'number' => 79],
			['class' => 'SmallBloodPool', 'number' => 80],
			['class' => 'BrainStem', 'number' => 81],
			['class' => 'SuperShotgun', 'number' => 82],
			['class' => 'Megasphere', 'number' => 83],
			['class' => 'WolfensteinSS', 'number' => 84],
			['class' => 'TechLamp', 'number' => 85],
			['class' => 'TechLamp2', 'number' => 86],
			['class' => 'BossTarget', 'number' => 87],
			['class' => 'BossBrain', 'number' => 88],
			['class' => 'BossEye', 'number' => 89],
			['class' => 'Shotgun', 'number' => 2001],
			['class' => 'Chaingun', 'number' => 2002],
			['class' => 'RocketLauncher', 'number' => 2003],
			['class' => 'PlasmaRifle', 'number' => 2004],
			['class' => 'Chainsaw', 'number' => 2005],
			['class' => 'BFG9000', 'number' => 2006],
			['class' => 'Clip', 'number' => 2007],
			['class' => 'Shell', 'number' => 2008],
			['class' => 'RocketAmmo', 'number' => 2010],
			['class' => 'Stimpack', 'number' => 2011],
			['class' => 'Medikit', 'number' => 2012],
			['class' => 'Soulsphere', 'number' => 2013],
			['class' => 'HealthBonus', 'number' => 2014],
			['class' => 'ArmorBonus', 'number' => 2015],
			['class' => 'EvilSceptre', 'number' => 2016],
			['class' => 'UnholyBible', 'number' => 2017],
			['class' => 'GreenArmor', 'number' => 2018],
			['class' => 'BlueArmor', 'number' => 2019],
			['class' => 'InvulnerabilitySphere', 'number' => 2022],
			['class' => 'Berserk', 'number' => 2023],
			['class' => 'BlurSphere', 'number' => 2024],
			['class' => 'RadSuit', 'number' => 2025],
			['class' => 'Allmap', 'number' => 2026],
			['class' => 'Column', 'number' => 2028],
			['class' => 'ExplosiveBarrel', 'number' => 2035],
			['class' => 'Infrared', 'number' => 2045],
			['class' => 'RocketBox', 'number' => 2046],
			['class' => 'Cell', 'number' => 2047],
			['class' => 'ClipBox', 'number' => 2048],
			['class' => 'ShellBox', 'number' => 2049],
			['class' => 'DoomImp', 'number' => 3001],
			['class' => 'Demon', 'number' => 3002],
			['class' => 'BaronOfHell', 'number' => 3003],
			['class' => 'Zombieman', 'number' => 3004],
			['class' => 'Cacodemon', 'number' => 3005],
			['class' => 'LostSoul', 'number' => 3006],
			['class' => 'Player5Start', 'number' => 4001],
			['class' => 'Player6Start', 'number' => 4002],
			['class' => 'Player7Start', 'number' => 4003],
			['class' => 'Player8Start', 'number' => 4004],
			['class' => 'Pistol', 'number' => 5010],
			['class' => 'Stalagmite', 'number' => 5050],
			['class' => 'StealthArachnotron', 'number' => 9050],
			['class' => 'StealthArchvile', 'number' => 9051],
			['class' => 'StealthBaron', 'number' => 9052],
			['class' => 'StealthCacodemon', 'number' => 9053],
			['class' => 'StealthChaingunGuy', 'number' => 9054],
			['class' => 'StealthDemon', 'number' => 9055],
			['class' => 'StealthHellKnight', 'number' => 9056],
			['class' => 'StealthDoomImp', 'number' => 9057],
			['class' => 'StealthFatso', 'number' => 9058],
			['class' => 'StealthRevenant', 'number' => 9059],
			['class' => 'StealthShotgunGuy', 'number' => 9060],
			['class' => 'StealthZombieMan', 'number' => 9061],
			['class' => 'ScriptedMarine', 'number' => 9100],
			['class' => 'MarineFist', 'number' => 9101],
			['class' => 'MarineBerserk', 'number' => 9102],
			['class' => 'MarineChainsaw', 'number' => 9103],
			['class' => 'MarinePistol', 'number' => 9104],
			['class' => 'MarineShotgun', 'number' => 9105],
			['class' => 'MarineSSG', 'number' => 9106],
			['class' => 'MarineChaingun', 'number' => 9107],
			['class' => 'MarineRocket', 'number' => 9108],
			['class' => 'MarinePlasma', 'number' => 9109],
			['class' => 'MarineRailgun', 'number' => 9110],
			['class' => 'MarineBFG', 'number' => 9111]
		];
	}
}