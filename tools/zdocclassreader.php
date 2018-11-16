<?php
namespace dxn\zdoc;

require_once('./zdocclass.php');

class ZDocClassReader {

	var $decorate_folder = null;
	var $zscript_folder = null;
	var $errors = [];

	const REGEX_ACTOR_DEFINITION = '/^\h*actor\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ZSCRIPT_ACTOR_DEFINITION = '/^\h*class\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ACTOR_DOC = '/\/\*\*\h*(.*?)\h*\*\/.*?actor\h*([A-Za-z0-9_]+)/si';
	const REGEX_STATE = '/^\h*[A-Za-z0-9]{4}\h+[a-zA-\]"]*\h+[-]?[0-9rR]+/m';
	const REGEX_STATE_DEFINITION = '/states.*?{(.*?)}/is';
	const REGEX_THING_DEFINITION = '/thing.*?([0-9]+?).*?{(.*?)}/s';

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
		'//$title'
	];

	public function __construct($options) {
		$this->decorate_folder = isset($options['decorate']) ? $options['decorate'] : null;
		$this->zscript_folder = isset($options['zscript']) ? $options['zscript'] : null;
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
		
		return $class_names_to_data;
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
			//Split into tokens. Go through and remove all completely numeric ones. Then next from : is our extends, next from replaces is our replace
			$result = str_replace(["\t", "{", "}"], " ", $result);
			$result = str_replace([":"], " : ", $result);
			$tokens = preg_split('@\h@', $result, NULL, PREG_SPLIT_NO_EMPTY);
			$classes = [];
			foreach ($tokens as $token) {
				if (!is_numeric(trim($token)) && trim($token) != ':' && strtolower(trim($token)) != 'replaces') {
					$classes[] = $token;
				}
			}
			//Now add these to our master list
			$class = new ZDocClass();
			$class->name = trim($classes[0]);
			if (isset($classes[1])) { $class->parent_class_name = trim($classes[1]); }
			if (isset($classes[2])) { $class->replaces_class_name = trim($classes[2]); }
			$class_data = $this->get_class_data(substr($file_string, $result_offset));
			$class->flags = $class_data['flags'];
			$class->properties = $class_data['properties'];
			$class->vars = $class_data['vars'];
			$class->consts = $class_data['consts'];
			$class->sprite_name = $class_data['sprite'];
			$class->states = $class_data['states'];
			
			$class->definition_filename = substr($full_file_name, strlen($source_folder));
			$class->category = $this->get_category($class->definition_filename, (isset($class->parent_class_name) ? $class->parent_class_name : ''));
			$class_names_to_data[strtolower($class->name)] = $class;
		}
		
		//Get documentation comments and add them to our entries in the list
		$results = [];
		preg_match_all(self::REGEX_ACTOR_DOC, $file_string, $results);
		for ($i = 0; $i < count($results[1]); $i++) {
			$comment = trim($results[1][$i]);
			$class_name = trim($results[2][$i]);
			$class_names_to_data[strtolower($class_name)]->comment = $comment;
		}
		
		return $class_names_to_data;
	}
	
	public function get_class_data($class_string) {
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
		//Our class string is now just the class we want. Now break into lines and examine the properties and flags.
		$flags = [];
		$properties = [];
		$vars = [];
		$consts = [];
		$damage_factors = '';
		$lines = explode(PHP_EOL, $class_string);
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
							throw new Exception('Could not parse property or flag: ' . $line);
						}
						$flags[$flag_name] = ['name' => $flag_name, 'value' => $direction];
					}
				} catch (Exception $ex) {
					$this->errors[] = $ex->getMessage();
				}

			}
		}
		//Finalize the properties that are lists
		if (strlen($damage_factors) > 0) {
			$properties['damagefactor'] = ['name' => 'damagefactor', 'value' => $damage_factors];
		}

		//If this class has a //$Sprite definition, we want to add it to the images to search for.		
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
		
		//For states, get everything between the states{ and } tokens
		$state_results = [];
		preg_match_all(self::REGEX_STATE_DEFINITION, $class_string, $state_results);
		$states = isset($state_results[1][0]) ? $state_results[1][0] : '';
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

}