<?php
namespace dxn\zdoc;

require_once('./zdocclassdatabase.php');
require_once('./zdocclass.php');

//TODO Show vars and constants

class ZDocumenter {
	
	const ZDOC_VERSION = 'BETA 0.8';
	
	const ZDOOM_ACTOR_FLAG_URL = 'https://zdoom.org/wiki/Actor_flags#';

	const PICTURE_BOX_SIZE = 32;

	var $zdoc_class_database = null;
	var $classes_done = [];
	var $sprite_search_folder = '';
	var $show_icons = false;
	var $resize_sprites = false;
	var $class_sprites_to_get = [];
	var $target_folder = '.';
	var $html = '';
	var $fragment = false;

	const SPRITE_FOLDER_NAME = 'sprites';

	public function __construct($options) {
		$this->zdoc_class_database = new ZDocClassDatabase($options);
		$this->sprite_search_folder = isset($options['sprites']) ? $options['sprites'] : null;
		$this->show_icons = (isset($options['sprites']) || isset($options['showicons'])) ? true : false;
		$this->resize_sprites = isset($options['resizesprites']);
		$this->fragment = isset($options['fragment']); //Is set, not set to true. Bloody stupid PHP
		$this->target_folder = isset($options['targetdir']) ? $options['targetdir'] : '.';
	}
	
	public function run() {

		//Set up our target directories, if we need to
		if (!$this->fragment) {
			if (!is_dir($this->target_folder) && !mkdir($this->target_folder)) {
				die("Couldn't create target folder");
			}
			if (!is_dir($this->target_folder . '/css/') && !mkdir($this->target_folder . '/css/')) {
				die("Couldn't create CSS folder");
			}
			if (!copy('./zdoc.css', $this->target_folder . '/css/zdoc.css')) {
				die("Couldn't copy CSS file to target folder");
			}
		}
		
		//If we have a sprites folder to search, gather any sprites that we need to find, and copy them to our sprite images folder
		if (!empty($this->sprite_search_folder)) {
			$sprites_folder = $this->target_folder . '/' . self::SPRITE_FOLDER_NAME;
			if (!is_dir($sprites_folder) && !mkdir($sprites_folder)) {
				die("Couldn't create sprites folder");
			}
			foreach($this->zdoc_class_database->get_zclass_list() as $key => $class) {
				$this->class_sprites_to_get[] = $class->sprite_name;
			}
			copy('./nosprite.png', $sprites_folder . '/nosprite.png');
			$this->gather_images($this->sprite_search_folder);
		}
		
		//Now output top-level classes. The others will be done recursively
		foreach ($this->zdoc_class_database->get_zclass_list() as $classname => $data) {
			if (empty($data->parent_class_name) || !isset($data->parent_class_name)) {
				$this->html .= $this->html_class($classname, $data);
			}
		}

		//Now output any class that has a parent that doesn't exist in the list - these inherit from other projects. Create a fake entry for the parent and go from there.
		foreach ($this->zdoc_class_database->get_zclass_list() as $classname => $data) {
			if (in_array(strtolower($classname), $this->classes_done)) {
				continue;
			}
			if (!$this->zdoc_class_database->has_zclass($data->parent_class_name)) {
				if (!in_array(strtolower($data->parent_class_name), $this->classes_done)) {
					$fake_parent = new ZDocClass();
					$fake_parent->name = $data->parent_class_name;
					$fake_parent->category = 'notinproject';
					$fake_parent->definition_filename = 'another project';
					$this->html .= $this->html_class($data->parent_class_name, $fake_parent);
				}
			}
		}
		
		//Output any errors to the main file
		$this->html .= $this->html_errors($this->zdoc_class_database->get_reader_errors()) . PHP_EOL;
		
		//We have our fragment HTML. If the fragment option is checked, we just want to output that - otherwise, combine it with our predefined header and footer and set the filename ourselves.
		if (!$this->fragment) {
			file_put_contents($this->target_folder . '/index.html', $this->html_header() . $this->html . $this->html_footer());
		} else {
			file_put_contents($this->target_folder . '/hierarchy.html', $this->html);
		}
	}
	
	public function resize_and_gather_image($source_file, $target_filename) {
		
		$target_path = $this->target_folder . '/' . self::SPRITE_FOLDER_NAME . '/' . $target_filename;
		
		if (!$this->resize_sprites) {
			$this->log('Copying sprite image to ' . $target_path);
			copy($source_file, $target_path);
			return;
		}
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
			$this->log('Writing icon image ' . $target_path);
			imagepng($target_img, $target_path);
		} catch (Exception $ex) {
			$this->log('Could not create icon image from ' . $source_file, 1);			
		}
	}

	public function html_errors($errors) {
		$html = '<div class="errorbox"><ul>';
		if (count($errors) == 0) {
			$html .= '<li>ZDoc generated with no errors.</li>';
		}
		else {
			for ($i = 0; $i < count($errors); $i++) {
				$html .= '<li>' . $errors[$i] . '</li>';
			}
		}
		$html .= '<li>Generated by ZDoc version ' . self::ZDOC_VERSION . '</li></ul></div>';
		return $html;
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
						$truncated_filename = substr($filename, 0, 5) . 'X';
						//Now look to see if this file has the given sprite name and frame. If it does, and the file's sixth character is 0 or 1, copy it appending an X.
						if (in_array($truncated_filename, $this->class_sprites_to_get) && isset($filename[5]) && in_array($filename[5], ['0', '1'])) {
							$this->log('Found a sprite file matching ' . $truncated_filename);
							$this->resize_and_gather_image($full_file_name, $truncated_filename . ".png");
							continue;
						}
					}
				}
			}
		}
	}
	
	public function html_class($classname, $data, $depth = 0, $current_flags = [], $current_properties = []) {
		//If we've done this one, just ignore it
		if (in_array(strtolower($classname), $this->classes_done)) {
			return;
		}
		$this->log('Writing class ' . $data->name);
		$html = '';
		
		//Output HTML for this class
		$expandableclass = '';
		if ($data->category != 'notinproject') {
			$expandableclass = 'expandable';
		}
		
		$html .= '<div class="zdoomclasswrapper" id="' . $classname .  '" style="margin-left:' . $depth*50 . 'px">';
		$html .= '<label for="zdoomclass-' . $classname . '">';
		$html .= '<div class="zdoomclass ' . $expandableclass . ' class_' . $data->category . '">';
		if ($this->show_icons && isset($data->sprite_name)) {
			$html .= ('<div class="zdoomclassspritewrapper" style="background-image: url(' . self::SPRITE_FOLDER_NAME . '/' . $data->sprite_name . '.png);"></div>');
		}
		$html .= '<div class="zdoomclassinfowrapper">';
		$strikestyle = '';
		if (isset($data->replaced_by_class_name)) {
			$strikestyle = 'strike';
		}
		$html .= '<div class="zdoomclassnamewrapper">';
		
		if (!$this->fragment && $this->zdoc_class_database->get_zclass($classname)) {
			$html .= '<a href="./' . $classname . '.html">';
		}
		$html .= '<div class="zdoomclassname ' . $strikestyle . '">' . $data->name . '</div>';
		if (!$this->fragment && $this->zdoc_class_database->get_zclass($classname)) {
			$html .= '</a>';
		}

		if (!empty($data->parent_class_name)) {
			$html .= '<div class="zdoomclassextends ' . $strikestyle . '"> : ' . $data->parent_class_name . '</div>';
		}
		if (!empty($data->replaces_class_name)) {
			$html .= '<div class="zdoomclassreplaces ' . $strikestyle . '"> replaces ' . $data->replaces_class_name . '</div>';
		}
		if (!empty($data->replaced_by_class_name)) {
			$this->log("Attempting to get " . $data->replaced_by_class_name);
			$replacer_class = $this->zdoc_class_database->get_zclass($data->replaced_by_class_name);
			$html .= ('<div class="zdoomclasscomment">Replaced by ' . $this->zdoc_class_database->get_zclass($data->replaced_by_class_name)->name . '</div>');
		}
		$html .= '</div>'; //Class name wrapper
		$html .= '</div>'; //Class info wrapper
		$html .= '<div class="zdoomclasscomment">' . (isset($data->comment) ? nl2br($data->comment) : '') . '</div>';
		$html .= '</label></div>'; //Class

		if ($data->category != 'notinproject') {
			$html .= '<input type="checkbox" id="zdoomclass-' . $classname . '" class="detailshower"/>';
			$html .= '<div class="content zdoomclasscontent">';
			$html .= '<table class="classtable"><tr><td>'; //Bollocks to it
			$html .= '<h3>File</h3><ul><li>Defined in ' . $data->definition_filename . '</li></ul>';
			//For flags and properties, get an HTML table for them highlighting the differences, then merge them together with our current ones.
			$html .= '</td><td>';
			$html .= ($this->html_flags($current_flags, $data->flags));
			$html .= '</td><td>';
			$current_flags = array_merge($current_flags, $data->flags);
			$html .= '</td><td>';
			$html .= ($this->html_properties($current_properties, $data->properties));
			$html .= '</td></tr></table>';
			$current_properties = array_merge($current_properties, $data->properties);
			$html .= ('</div>'); //End class content
		}

		$html .= ('</div>'); //End class wrapper
		$html .= (PHP_EOL);
		
		if (!$this->fragment && $data->category != 'notinproject') {
			$this->output_class_page($classname, $data);
		}
		//Now call this again on any class in the list that inherits directly from this class.
		foreach ($this->zdoc_class_database->get_zclass_list() as $childclassname => $childdata) {
			if (isset($childdata->parent_class_name) && strtolower($childdata->parent_class_name) == strtolower($classname)) {
				$html .= $this->html_class($childclassname, $childdata, $depth+1, $current_flags, $current_properties);
			}
		}
		$this->classes_done[] = strtolower($classname);
		return $html;
	}
	
	public function output_class_page($classname, $data) {
		$html = '';
		$strikestyle = '';
		if (isset($data->replaced_by_class_name)) {
			$strikestyle = 'strike';
		}
		$html .= '<a class="mainpagelink" href="./index.html#' . $classname . '">&lt;</a>';
		$html .= '<div class="zdoomclasspageheaderwrapper">';
		$html .= '<div class="zdoomclasspageheader">';
		$html .= '<div class="zdoomclasspageheaderimage" style="background-image: url(\'./sprites/' . $data->sprite_name . '.png\')">&nbsp;</div>';
		$html .= '<div class="zdoomclasspagetitle">' . $data->name;
		if (isset($data->parent_class_name)) {
			$html .= ('<div class="zdoomclassextends ' . $strikestyle . '"> : ' . $this->html_class_link($data->parent_class_name) . '</div>');
		}
		if (isset($data->replaces_class_name)) {
			$html .= ('<div class="zdoomclassreplaces ' . $strikestyle . '"> replaces ' . $this->html_class_link($data->replaces_class_name) . '</div>');
		}
		if (isset($data->replaced_by_class_name)) {
			$html .= ('<div class="zdoomclasscomment">Replaced by ' . $this->html_class_link($this->zdoc_class_database->get_zclass($data->replaced_by_class_name)->name) . '</div>');
		}
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';

		$html .= '<div class="zdoomclasspageheaderspacer">&nbsp;</div>';

		$html .= ('<div class="zdoomclasspagecomment">' . (isset($data->comment) ? nl2br($data->comment) : '') . '</div>');
		
		$html .= '<div class="content">';
		$html .= '<h3>File</h3><ul><li>Defined in ' . $data->definition_filename . '</li></ul>';
		
		$parent_facts = $this->zdoc_class_database->get_parent_facts($classname);
		
		$html .= $this->html_flags($parent_facts['flags'], $data->flags);
		$html .= $this->html_properties($parent_facts['properties'], $data->properties);

		$html .= '<h3>States</h3>';
		if (empty(trim($data->states))) {
			$html .= "<ul><li>This class has no states.</li></ul>";
		} else {
			$html .= '<div class="zdoccode">' . str_replace("\t", "    ", $data->states) . '</div>';
		}
		
		$html .= '<h3>Class Hierarchy</h3>';
		$tree = $this->zdoc_class_database->get_surrounding_tree($classname);
		$html .= $this->html_class_tree($tree, [$classname]);

		$html .= '<h3>Classes defined in ' . $data->definition_filename . '</h3><ul>';
		foreach ($this->zdoc_class_database->get_neighbours($classname) as $neighbour) {
			$html .= '<li>' . $this->html_class_link($neighbour->name) . '</li>';
		}
		$html .= '</ul>';
		
		$html .= ('</div>'); //End class content
		
		$header = str_replace("ZDoc Hierarchy", $data->name, $this->html_header());
		
		file_put_contents($this->target_folder . '/' . strtolower($classname) . '.html', $header . $html . $this->html_footer());
	}
	
	public function html_class_tree($tree, $highlight = []) {
		$classlink = $this->html_class_link($tree['class']->name);
		if (in_array(strtolower($tree['class']->name), $highlight)) {
			$classlink = '<b>' . $classlink . '</b>';
		}
		$html = '<ul><li>' . $classlink . '</li>';
		foreach ($tree['subclasses'] as $subclass) {
			$html .= $this->html_class_tree($subclass, $highlight);
		}
		$html .= '</ul>';
		return $html;
	}
	
	public function html_class_link($classname) {
		//Only link if the class is available
		if ($this->zdoc_class_database->has_zclass($classname)) {
			return '<a href="./' . strtolower($classname) . '.html">' . $classname . '</a>';
		} else {
			return $classname;
		}
	}
	
	public function html_flags($current_flags, $new_flags) {
		$flags_to_output = $this->get_flags_to_output($current_flags, $new_flags);
		if (empty($flags_to_output)) {
			return '';
		}
		
		$html = '<h3>Flags</h3><ul class="flags">';
		foreach($flags_to_output as $flag) {
			$html .= '<li class="' . $flag['status'] . $flag['value'] . '">' . ($flag['value'] == 1 ? '+' : '-');
			$html .= '<a href="' . self::ZDOOM_ACTOR_FLAG_URL . $flag['name'] . '">' . $flag['name'] . '</a></li>';
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
		//If this flag is new, or is mentioned and is different from the parent, highlight it.
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto+Mono">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Exo">	
<link rel="stylesheet" type="text/css" href="./css/zdoc.css"/>
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
echo ('ZDoc version ' . ZDocumenter::ZDOC_VERSION . PHP_EOL . PHP_EOL);
$options = [
	"decorate:",
	"zscript:",
	"sprites:",
	"fragment",
	"showicons",
	"resizesprites",
	"targetdir:"
];

$myoptions = getopt('', $options);
if (empty($myoptions)) {
    echo ('A tool to parse Decorate/ZScript and generate a class hierarchy.' . ZDocumenter::ZDOC_VERSION . PHP_EOL . PHP_EOL);

	echo ('Usage: zdoc --decorate=? --zscript=? --sprites=? --fragment --showicons --resizesprites --targetdir=?' . PHP_EOL);
	echo ('--decorate=./pk3/decorate/ Path to DECORATE folder to parse classes' . PHP_EOL);
	echo ('--zscript=./pk3/zscript/   Path to ZSCRIPT folder to parse classes' . PHP_EOL);
	echo ('--sprites=./pk3/sprites/   Path to folder in which to search for PNG sprites' . PHP_EOL);
	echo ('--resizesprites            Resize found sprites to 32x32 icons. Alternatively, crash the tool if on Windows' . PHP_EOL);
	echo ('--fragment                 Include this to only output a single HTML file, no CSS or header/footer' . PHP_EOL);
	echo ('--showicons                Show class icons if available. Automatically done if --sprites= is set.' . PHP_EOL);
	echo ('--targetdir=./output/      Name of the folder in which to generate the ZDoc files.' . PHP_EOL);
	echo ('                           The root file will be called index.html without --fragment, hierarchy.html with --fragment.' . PHP_EOL);
	echo (PHP_EOL);
	echo ('Multiple Decorate and/or ZScript folders can be provided with comma separation (no space).' . PHP_EOL);
	echo ('e.g. zdoc --zscript=doom2/zscript,mypk3/zscript --targetdir=./myoutputfolder/' . PHP_EOL);
	die();
}
if (!isset($myoptions['decorate']) && !isset($myoptions['zscript'])) {
	die("Please provide a DECORATE or ZSCRIPT folder to parse with --decorate= or --zscript=");
}
if (!isset($myoptions['targetdir'])) {
	die("Please provide a target folder with --targetdir=");
}

$h = new ZDocumenter($myoptions);
$h->run();
