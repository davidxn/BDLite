<?php
namespace dxn\zdoc;

require_once('./zdocclassdatabase.php');
require_once('./zdocclass.php');
require_once('./zdocmapreader.php');

class ThingAnalysis {

	var $zdoc_class_database = null;
	var $zdoc_map_reader = null;
	var $map = null;
	var $thing_points = [];
	var $doomed_nums_to_class_names = null;
	var $class_names_to_doomed_nums = null;

	public function __construct($options) {
		$this->zdoc_class_database = new ZDocClassDatabase($options);
		$this->zdoc_map_reader = new ZDocMapReader($options);
		$this->map = $options['map'];
		//Get the DoomEd numbers from MAPINFO
		foreach ($this->zdoc_class_database->get_doomed_numbers() as $doomed_number) {
			$class = $this->zdoc_class_database->get_zclass($doomed_number['class']);
			$name = $doomed_number['class'];
			if (!empty($class)) {
				$name = $class->name;
			}
			$this->doomed_nums_to_class_names[$doomed_number['number']] = $name;
			$this->class_names_to_doomed_nums[$name] = $doomed_number['number'];
		}
		//Now add any numbers defined directly on the classes
		foreach ($this->zdoc_class_database->get_zclass_list() as $key => $data) {
			if (isset($data->doomed_number)) {
				$this->doomed_nums_to_class_names[$data->doomed_number] = $data->name;
				$this->class_names_to_doomed_nums[$key] = $data->doomed_number;
			}
		}

		if (isset($options['thingpointsfile'])) {
			$this->thing_points = [];
			$thingpointsfiles = explode(",", $options['thingpointsfile']);
			foreach ($thingpointsfiles as $thingpointsfile) {
				if (!file_exists($thingpointsfile)) {
					throw new \Exception("Could not find thing points file: " . $thingpointsfile);
				}
				$file_string = trim(file_get_contents($thingpointsfile));
				$lines = explode("\n", $file_string);
				foreach ($lines as $line) {
					$terms = explode(",", $line);
					$this->thing_points[$terms[0]][$terms[1]] = trim($terms[2]);
				}
			}
		}
	}
	
	public function run() {
		$thing_counts = [];
		$things = $this->zdoc_map_reader->run($this->map);
		
		foreach ($things as $thing) {
			$type = $thing['type'];
			if (!isset($thing_counts[$type])) {
				$thing_counts[$type] = 0;
			}
			$thing_counts[$type]++;
		}
		$lines = [];
		foreach ($thing_counts as $thing_type => $thing_count) {
			$name = $thing_type;
			if (isset($this->doomed_nums_to_class_names[$thing_type])) {
				$name = $this->doomed_nums_to_class_names[$thing_type];
			}
			$lines[] = ($name . ": " . $thing_count . PHP_EOL);
		}
		asort($lines);
		foreach($lines as $line) {
			echo($line);
		}
		echo (PHP_EOL);

		//Now, for each group we found, loop through and get points + combined points
		foreach ($this->thing_points as $groupname => $group) {
			$totalpoints = 0;
			echo ($groupname . PHP_EOL);
			foreach ($group as $classname => $pointvalue) {
				$doomed_num = $this->class_names_to_doomed_nums[$classname];
				$number = 0;
				if (isset($thing_counts[$doomed_num])) {
					$number = $thing_counts[$doomed_num];
				}
				$points = $number * $pointvalue;
				$totalpoints += $points;
				echo ($classname . ': ' . $points . PHP_EOL);
			}
			echo ("Total points for " . $groupname . ": " . $totalpoints . PHP_EOL . PHP_EOL);
		}
	}
}
$options = [
	"decorate:",
	"zscript:",
	"mapinfo:",
	"map:",
	"thingpointsfile:"
];
$myoptions = getopt('', $options);
if (empty($myoptions)) {
    echo ('A tool to analyze things on maps' . PHP_EOL . PHP_EOL);

	echo ('Usage: thinganalysis --decorate=? --zscript=? --mapinfo=? --map=?' . PHP_EOL);
	echo ('--decorate=./pk3/decorate/ Path to DECORATE folder to parse classes' . PHP_EOL);
	echo ('--zscript=./pk3/zscript/   Path to ZSCRIPT folder to parse classes' . PHP_EOL);
	echo ('--mapinfo=./pk3/mapinfo    Location of MAPINFO file(s) from which to get DoomEd numbers' . PHP_EOL);
	echo ('--map=./pk3/maps/E1M1.WAD  Location of WAD(s) with TEXTMAP lumps' . PHP_EOL);
	echo (PHP_EOL);
	echo ('Multiple folders or files can be provided with comma separation (no space).' . PHP_EOL);
	echo ('e.g. thinganalysis --zscript=doom2/zscript,mypk3/zscript --targetdir=./myoutputfolder/' . PHP_EOL);
	die();
}
if (!isset($myoptions['decorate']) && !isset($myoptions['zscript'])) {
	die("Please provide a DECORATE or ZSCRIPT folder to parse with --decorate= or --zscript=");
}
if (!isset($myoptions['map'])) {
	die("Please provide a WAD with a TEXTMAP lump by using --map=");
}

$h = new ThingAnalysis($myoptions);
$h->run();
