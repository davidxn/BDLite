<?php

class ZDocumenter {
	
	const ZDOC_VERSION = 'BETA 0.2';
	
	const REGEX_ACTOR_DEFINITION = '/^\h*actor\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ZSCRIPT_ACTOR_DEFINITION = '/^\h*class\h+[A-Za-z0-9_]+?.*?\{/sim';
	const REGEX_ACTOR_DOC = '/\/\*\*\h*(.*?)\h*\*\/.*?actor\h*([A-Za-z0-9_]+)/si';
	const REGEX_STATE = '/^\h*[A-Za-z0-9]{4}\h+[a-zA-\]"]*\h+[-]?[0-9rR]+/m';

	const PICTURE_BOX_SIZE = 48;

	var $class_names_to_data = [];
	var $classes_done = [];
	var $errors = [];
	var $decorate_folder = '';
	var $sprite_search_folder = '';
	var $class_sprites_to_get = [];
	var $target_folder = '.';
	var $html = '';
	var $fragment = false;
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
	
	const SPRITE_FOLDER_NAME = 'sprites';
	
	public function __construct($options) {
		$this->decorate_folder = isset($options['decorate']) ? $options['decorate'] : null;
		$this->zscript_folder = isset($options['zscript']) ? $options['zscript'] : null;
		$this->sprite_search_folder = isset($options['sprites']) ? $options['sprites'] : null;
		$this->fragment = isset($options['fragment']); //Is set, not set to true. Bloody stupid PHP
		$this->target_folder = isset($options['target']) ? $options['target'] : '.';
	}
	
	public function run() {
		//Set up our database by parsing the DECORATE and/or ZSCRIPT
		if (!empty($this->decorate_folder)) {
			$this->examine_script_folder($this->decorate_folder, 'decorate');
		}
		if (!empty($this->zscript_folder)) {
			$this->examine_script_folder($this->zscript_folder, 'zscript');
		}
		
		//Set up our target directories, if we need to
		if (!$this->fragment) {
			@mkdir($this->target_folder);
			@mkdir($this->target_folder . '/css/');
			copy('./zdoc.css', $this->target_folder . '/css/zdoc.css');
			//If we have a sprites folder to search, gather any sprites that we need to find, and copy them to our sprite images folder
			if (!empty($this->sprite_search_folder)) {
				@mkdir($this->target_folder . '/' . self::SPRITE_FOLDER_NAME);
				$this->gather_images($this->sprite_search_folder);
			}
		}
		
		//Look at replaced classes. If anything is mentioned in another class's 'replaces' field, it's been replaced.
		foreach ($this->class_names_to_data as $classname => $data) {
			if (isset($data['replaces'])) {
				if (!isset($this->class_names_to_data[strtolower($data['replaces'])])) {
					continue;
				}
				$this->class_names_to_data[strtolower($data['replaces'])]['replacedby'] = $classname;
			}
		}
		
		//Now output top-level classes. The others will be done recursively
		foreach ($this->class_names_to_data as $classname => $data) {
			if (empty($data['parent']) || !isset($data['parent'])) {
				$this->html .= $this->html_class($classname, $data);
			}
		}

		//Now output any class that has a parent that doesn't exist in the list - these inherit from other projects. Create a fake entry for the parent and go from there.
		foreach ($this->class_names_to_data as $classname => $data) {
			if (in_array(strtolower($classname), $this->classes_done)) {
				continue;
			}
			if (!isset($this->class_names_to_data[strtolower($data['parent'])])) {
				if (!in_array(strtolower($data['parent']), $this->classes_done)) {
					$this->html .= $this->html_class($data['parent'], ['name' => $data['parent'], 'category' => 'notinproject', 'filename' => 'another project', 'data' => ['flags' => [], 'properties' => []]]);
				}
			}
		}
		
		//Output any errors
		$this->html .= $this->html_errors() . PHP_EOL;
		
		//We have our fragment HTML. If the fragment option is checked, we just want to output that - otherwise, combine it with our predefined header and footer and set the filename ourselves.
		if (!$this->fragment) {
			file_put_contents($this->target_folder . '/index.html', $this->html_header() . $this->html . $this->html_footer());
		} else {
			file_put_contents($this->target_folder, $this->html);
		}
	}
	
	public function resize_and_gather_image($source_file, $target_filename) {
		try {			
			@$img = imagecreatefrompng($source_file);
			if ($img === false) {
				return;
			}
			$source_width = imagesx($img);
			$source_height = imagesy($img);
			
			$target_width = self::PICTURE_BOX_SIZE;
			$target_height = self::PICTURE_BOX_SIZE;
			
			if ($source_width < $source_height) {
				$ratio = $source_height / self::PICTURE_BOX_SIZE;
			} else {
				$ratio = $source_width / self::PICTURE_BOX_SIZE;
			}
			
			$target_width = round($source_width / $ratio);
			$target_height = round($source_height / $ratio);
			
			$target_img = imagecreatetruecolor($target_width, $target_height);
			imagealphablending($target_img, false);
			imagesavealpha($target_img, true);
			$transparency = imagecolorallocatealpha($target_img, 255, 255, 255, 127);
			imagefilledrectangle($target_img, 0, 0, $target_width, $target_height, $transparency);
			imagecopyresampled($target_img, $img, 0, 0, 0, 0, $target_width, $target_height, $source_width, $source_height);
			$target_path = $this->target_folder . '/' . self::SPRITE_FOLDER_NAME . '/' . $target_filename;
			$this->log('Writing image ' . $target_path);
			imagepng($target_img, $target_path);
		} catch (Exception $ex) {
			$this->log('Could not create image from ' . $source_file, 1);			
		}
	}

	public function html_errors() {
		$html = '<div class="errorbox"><ul>';
		if (count($this->errors) == 0) {
			$html .= '<li>ZDoc generated with no errors.</li>';
		}
		else {
			for ($i = 0; $i < count($this->errors); $i++) {
				$html .= '<li>' . $this->errors[$i] . '</li>';
			}
		}
		$html .= '<li>Generated by ZDoc version ' . self::ZDOC_VERSION . '</li></ul></div>';
		return $html;
	}

	public function examine_script_folder($decorate_folder, $mode = 'decorate') {
		$source_folders = explode(",", $decorate_folder);
		
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
							$this->examine_script_file($full_file_name, $mode, $source_folder);							
						}
					}
				}
			}
		}
	}
	
	public function gather_images($sprite_search_folder) {
		$folders_to_run = explode(",", $sprite_search_folder);

		while(count($folders_to_run) > 0) {
			$folder = array_shift($folders_to_run);
			//Get the files in the folder
			$files = scandir($folder);
			foreach ($files as $key => $value) { 
				  if (!in_array($value, [".",".."])) { 
					$full_file_name = $folder . DIRECTORY_SEPARATOR . $value;
				  
					if (is_dir($full_file_name)) { 
						//Add to the list
						$folders_to_run[] = $full_file_name;
					}
					else {
						$filename = explode('.', $value);
						$filename = $filename[0];
						if (in_array($filename, $this->class_sprites_to_get)) {
							$this->log('Found a sprite file matching ' . $filename);
							$this->resize_and_gather_image($full_file_name, $filename . ".png");
							continue;
						}
						$truncated_filename = substr($filename, 0, 5);
						//Now look to see if this file has the given sprite name and frame. If it does, and the file's sixth character is 0 or 1, copy it appending an X.
						if (in_array($truncated_filename, $this->class_sprites_to_get) && isset($filename[5]) && in_array($filename[5], ['0', '1'])) {
							$this->log('Found a sprite file matching ' . $truncated_filename);
							$this->resize_and_gather_image($full_file_name, $truncated_filename . "X.png");
							continue;
						}
					}
				}
			}
		}
	}

	public function examine_script_file($full_file_name, $mode = 'decorate', $source_folder) {
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
			$class = [];
			$class['name'] = trim($classes[0]);
			if (isset($classes[1])) { $class['parent'] = trim($classes[1]); }
			if (isset($classes[2])) { $class['replaces'] = trim($classes[2]); }
			$class['data'] = $this->get_class_data(substr($file_string, $result_offset));
			$class['filename'] = substr($full_file_name, strlen($source_folder));
			$class['category'] = $this->get_category($class['filename'], (isset($class['parent']) ? $class['parent'] : ''));
			$this->class_names_to_data[strtolower($class['name'])] = $class;
		}
		
		//Get documentation comments and add them to our entries in the list
		$results = [];
		preg_match_all(self::REGEX_ACTOR_DOC, $file_string, $results);
		for ($i = 0; $i < count($results[1]); $i++) {
			$comment = trim($results[1][$i]);
			$class_name = trim($results[2][$i]);
			$this->class_names_to_data[strtolower($class_name)]['comment'] = $comment;
		}
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
			else if ($property_name == 'var' || $property_name == 'const') {
				//TODO Add custom variables?
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
			$this->class_sprites_to_get[] = $sprite;
		}
		//If it doesn't, let's try using the first thing that looks like a sprite
		else {
			$sprite = $this->get_first_sprite($class_string);
			if ($sprite != null) {
				$this->class_sprites_to_get[] = $sprite;
				$sprite .= 'X';
			}
		}
		return ['flags' => $flags, 'properties' => $properties, 'sprite' => $sprite];
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
	
	public function html_class($classname, $data, $depth = 0, $current_flags = [], $current_properties = []) {
		//If we've done this one, just ignore it
		if (in_array(strtolower($classname), $this->classes_done)) {
			return;
		}
		$this->log('Writing class ' . $data['name']);
		$html = '';
		
		//Output HTML for this class
		$expandableclass = '';
		if ($data['category'] != 'notinproject') {
			$expandableclass = 'expandable';
		}
		
		$html .= ('<div class="zdoomclasswrapper" style="margin-left:' . $depth*50 . 'px">');
		$html .= ('<label for="zdoomclass-' . $classname . '">');
		$html .= ('<div class="zdoomclass ' . $expandableclass . ' class_' . $data['category'] . '">');
		if (!empty($this->sprite_search_folder) && isset($data['data']['sprite'])) {
			$html .= ('<div class="zdoomclassspritewrapper" style="background-image: url(' . self::SPRITE_FOLDER_NAME . '/' . $data['data']['sprite'] . '.png);"></div>');
		}
		$html .= ('<div class="zdoomclassinfowrapper">');
		$strikestyle = '';
		if (isset($data['replacedby'])) {
			$strikestyle = 'strike';
		}
		$html .= ('<div class="zdoomclassname ' . $strikestyle . '">' . $data['name'] . '</div>');
		if (isset($data['parent'])) {
			$html .= ('<div class="zdoomclassextends ' . $strikestyle . '"> : ' . $data['parent'] . '</div>');
		}
		if (isset($data['replaces'])) {
			$html .= ('<div class="zdoomclassreplaces ' . $strikestyle . '"> replaces ' . $data['replaces'] . '</div>');
		}
		if (isset($data['replacedby'])) {
			$html .= ('<div class="zdoomclasscomment">Replaced by ' . $this->class_names_to_data[$data['replacedby']]['name'] . '</div>');
		}
		$html .= ('<div class="zdoomclasscomment">' . (isset($data['comment']) ? nl2br($data['comment']) : '') . '</div>');
		$html .= ('</div>'); //Class info wrapper
		$html .= ('</label></div>'); //Class

		if ($data['category'] != 'notinproject') {
			$html .= '<input type="checkbox" id="zdoomclass-' . $classname . '" class="detailshower"/>';
			$html .= '<div class="content zdoomclasscontent">';
			$html .= '<table class="classtable"><tr><td>'; //Bollocks to it
			$html .= '<h3>File</h3><ul><li>Defined in ' . $data['filename'] . '</li></ul></div>';
			//For flags and properties, get an HTML table for them highlighting the differences, then merge them together with our current ones.
			$html .= '</td><td>';
			$html .= ($this->html_flags($current_flags, $data['data']['flags']));
			$html .= '</td><td>';
			$current_flags = array_merge($current_flags, $data['data']['flags']);
			$html .= '</td><td>';
			$html .= ($this->html_properties($current_properties, $data['data']['properties']));
			$html .= '</td></tr></table>';
			$current_properties = array_merge($current_properties, $data['data']['properties']);
			$html .= ('</div>'); //End class content
		}

		$html .= ('</div>'); //End class wrapper
		$html .= (PHP_EOL);
		//Now call this again on any class in the list that inherits directly from this class.
		foreach ($this->class_names_to_data as $childclassname => $childdata) {
			if (isset($childdata['parent']) && strtolower($childdata['parent']) == strtolower($classname)) {
				$html .= $this->html_class($childclassname, $childdata, $depth+1, $current_flags, $current_properties);
			}
		}
		$this->classes_done[] = strtolower($classname);
		return $html;
	}
	
	public function html_flags($current_flags, $new_flags) {
		$flags_to_output = $this->get_flags_to_output($current_flags, $new_flags);
		if (empty($flags_to_output)) {
			return '';
		}
		
		$html = '<h3>Flags</h3><ul>';
		foreach($flags_to_output as $flag) {
			$html .= '<li class="' . $flag['status'] . $flag['value'] . '">' . ($flag['value'] == 1 ? '+' : '-') . $flag['name'] . '</li>';
		}
		$html .= '</ul>';
		return $html;
	}
	
	public function html_properties($current_flags, $new_flags) {
		$flags_to_output = $this->get_flags_to_output($current_flags, $new_flags);
		if (empty($flags_to_output)) {
			return '';
		}
		
		$html = '<h3>Properties</h3><table>';
		foreach($flags_to_output as $flag) {
			$html .= '<tr><td>' . $flag['name'] . '</td><td class="' . $flag['status'] . '1">' . $flag['value'] . '</tr></td>';
		}
		$html .= '</tr></table>';
		return $html;
	}
	
	public function get_flags_to_output($current_flags, $new_flags) {
		//If this flag is new, or is mentioned and is the opposite of the parent, highlight it.
		//If it's mentioned and is the same as the parent, highlight it as redundant.
		$flags_to_output = [];
		foreach ($new_flags as $new_flag) {
			$flag_name = $new_flag['name'];
			$flag_value = $new_flag['value'];
			
			$flags_to_output[$flag_name] = ['name' => $flag_name, 'value' => $flag_value];
			
			if (!isset($current_flags[$flag_name])) {
				$flags_to_output[$flag_name]['status'] = 'new';
			} else {
				if ($current_flags[$flag_name]['value'] != $flag_value) {
					$flags_to_output[$flag_name]['status'] = 'new';				
				} else {
					$flags_to_output[$flag_name]['status'] = 'redundant';
				}
			}
		}
		
		foreach($current_flags as $flag) {
			//If a current flag isn't already mentioned among the flags to output, it must be unchanged.
			if (!isset($flags_to_output[$flag['name']])) {
				$flags_to_output[$flag['name']] = ['name' => $flag['name'], 'value' => $flag['value'], 'status' => 'existing'];
			}
		}
		
		ksort($flags_to_output);

		return $flags_to_output;
	}
	
	public function html_header() {
		return <<<EOL
<!DOCTYPE HTML5>
<html>
<head>
<title>ZDoc Hierarchy</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta http-equiv="Content-Style-Type" content="text/css" />
<meta name="robots" content="index,follow" />
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Exo">	
<link rel="stylesheet" type="text/css" href="./css/zdoc.css"/>
<link rel="shortcut icon" href="./img/favicon.png" type="image/x-icon" />
</head>
<body>
	<div id="maincontainer">
EOL;
	}
	
	public function html_footer() {
		return "</div></body></html>";
	}
	
	public function log($msg, $level = 0) {
		$prefix = '.';
		if ($level == 1) {
			$prefix = 'X';
		}
		echo (' ' . $prefix . ' ' . $msg . PHP_EOL);			
	}

}

//

$options = [
	"decorate:",
	"zscript:",
	"sprites:",
	"fragment",
	"target:"
];

$myoptions = getopt('', $options);
if (empty($myoptions)) {
	echo ('Usage: zdoc --decorate=? --zscript=? --sprites=? --fragment --target=?' . PHP_EOL);
	echo ('--decorate=./pk3/decorate/ Path to DECORATE folder to parse classes' . PHP_EOL);
	echo ('--zscript=./pk3/zscript/   Path to ZSCRIPT folder to parse classes' . PHP_EOL);
	echo ('--sprites=./pk3/sprites/   Path to folder in which to search for PNG sprites' . PHP_EOL);
	echo ('--fragment                 Include this to only output a single HTML file, no JS/CSS/sprites' . PHP_EOL);
	echo ('--target=./output/         If "--fragment", name of the file to output.' . PHP_EOL);
	echo ('                           Otherwise, name of the folder in which to generate the ZDoc files.' . PHP_EOL);
	echo (PHP_EOL);
	echo ('Multiple Decorate and/or ZScript folders can be provided with comma separation (no space).' . PHP_EOL);
	echo ('e.g. zdoc --zscript=doom2/zscript,mypk3/zscript --target=./myoutputfolder/' . PHP_EOL);
	die();
}
if (!isset($myoptions['decorate']) && !isset($myoptions['zscript'])) {
	die("Please provide a DECORATE or ZSCRIPT folder to parse with --decorate= or --zscript=");
}
if (!isset($myoptions['target'])) {
	die("Please provide a target folder with --target=, or use --fragment with --target=file.html to just generate an HTML fragment.");
}

$h = new ZDocumenter($myoptions);
$h->run();
