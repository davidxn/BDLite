<?php

class SpriteUseDetector {
	
	//Including the "r" in the last section is a hacky allowance for when a state lasts for a random() amount of time
	var $state_regex = '/^\h*[A-Za-z0-9]{4}\h+[a-zA-\]"]*\h+[-]?[0-9rR]+/m';
	var $sprite_file_regex = '/^[A-Za-z0-9]{4}[a-zA-\]\^]{1}[0-1]/';
	var $decorate_sprites_mentioned = [];
	var $sprites_available = [];
	var $sprite_errors = 0;
	var $sprites_unused = 0;
	var $zero_length_frames = [];
	
	public function run($decorate_folder, $sprite_folder) {

		$folders_to_run = explode(",", $decorate_folder);

		while(count($folders_to_run) > 0) {
			$decorate_folder = array_shift($folders_to_run);
			echo ("Examining DECORATE folder " . $decorate_folder . PHP_EOL);
			//Get the files in the folder
			$files = scandir($decorate_folder);
			foreach ($files as $key => $value) { 
				  if (!in_array($value,array(".",".."))) { 
				  
					$full_file_name = $decorate_folder . DIRECTORY_SEPARATOR . $value;
				  
					if (is_dir($full_file_name)) { 
						//Add to the list
						$folders_to_run[] = $full_file_name;
					}
					else {
						$file_string = file_get_contents($full_file_name);
						$this->examine_decorate_file($file_string);
					}
				}
			}
		}
		
		//Now run a similar search on the sprite folder, looking for filenames.
		
		$folders_to_run = explode(",", $sprite_folder);
		while(count($folders_to_run) > 0) {
			$sprite_folder = array_shift($folders_to_run);
			echo ("Examining sprite folder " . $sprite_folder . PHP_EOL);
			//Get the files in the folder
			$files = scandir($sprite_folder);
			foreach ($files as $key => $value) {
				  if (!in_array($value,array(".",".."))) { 
				  
					$full_file_name = $sprite_folder . DIRECTORY_SEPARATOR . $value;
				  
					if (is_dir($full_file_name)) { 
						//Add to the list
						$folders_to_run[] = $full_file_name;
						echo ("Adding " . $full_file_name . PHP_EOL);
					}
					else {
						//This might be a sprite file, if we have a match on this.
						$num_results = preg_match_all($this->sprite_file_regex, $value);
						if ($num_results > 0) {
							$sprite_name = strtoupper(substr($value, 0, 4));
							$sprite_frame = strtoupper(substr($value, 4, 1));
							$this->sprites_available[$sprite_name][$sprite_frame] = true;
							//Check for a double rotation
							$sprite_frame_2 = strtoupper(substr($value, 6, 1));
							if ($sprite_frame_2 != '.' && !empty($sprite_frame_2)) {
								$this->sprites_available[$sprite_name][$sprite_frame_2] = true;
							}
						}
					}
				}
			}
		}
		
		//We now have all the DECORATE mentions and sprites. Check to see if any of the DECORATE refers to a missing sprite.
		foreach ($this->decorate_sprites_mentioned as $dec_sprite_name => $dec_sprite_frames) {
			if (!isset($this->sprites_available[$dec_sprite_name])) {
				echo ("!!! $dec_sprite_name (" . count($dec_sprite_frames) . " frames) not found among the sprite files!" . PHP_EOL);
				$this->sprite_errors++;
				continue;
			}
			foreach ($dec_sprite_frames as $dec_sprite_frame => $blah) {
				if (!isset($this->sprites_available[$dec_sprite_name][$dec_sprite_frame])) {
					echo ("!!! File for $dec_sprite_name frame $dec_sprite_frame was not found!" . PHP_EOL);					
					$this->sprite_errors++;
				}
			}
		}
		echo ("Sprites missing: " . $this->sprite_errors . PHP_EOL);
		
		//And check to see if any sprites were never mentioned in the DECORATE.
		foreach ($this->sprites_available as $sprite_name => $sprite_frames) {
			if (!isset($this->decorate_sprites_mentioned[$sprite_name])) {
				echo ("??? $sprite_name (" . count($sprite_frames) . " frames) present but never used in DECORATE!" . PHP_EOL);
				$this->sprites_unused++;
				continue;
			}
			foreach ($sprite_frames as $sprite_frame => $blah) {
				if (!isset($this->decorate_sprites_mentioned[$sprite_name][$sprite_frame])) {
					echo ("??? $sprite_name frame $sprite_frame present but never used in DECORATE!" . PHP_EOL);					
					$this->sprites_unused++;
				}
			}
		}
		echo ("Sprites unused: " . $this->sprites_unused . PHP_EOL);

		echo (PHP_EOL . PHP_EOL);
		echo ("Sprites available" . PHP_EOL);
				
		//Output the full sprites available/sprites used arrays
		ksort($this->sprites_available);
		foreach ($this->sprites_available as $sprite_name => $sprite_frames) {
			ksort($sprite_frames);
			echo ($sprite_name . " ");
			foreach ($sprite_frames as $frame_letter => $frame_value) {
				echo $frame_letter;
			}
			echo PHP_EOL;
		}
		echo (PHP_EOL . PHP_EOL);
		ksort($this->decorate_sprites_mentioned);
		echo ("Sprites mentioned in DECORATE" . PHP_EOL);
		foreach ($this->decorate_sprites_mentioned as $sprite_name => $sprite_frames) {
			ksort($sprite_frames);
			echo ($sprite_name . " ");
			foreach ($sprite_frames as $frame_letter => $frame_value) {
				echo $frame_letter;
			}
			echo PHP_EOL;
		}
		
		echo (PHP_EOL . PHP_EOL);

	}
	
	public function examine_decorate_file($file_string) {
		$results = [];
		preg_match_all($this->state_regex, $file_string, $results);
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
			$result = strtoupper(substr($result, 4));
			if (!isset($this->decorate_sprites_mentioned[$sprite_name])) {
				$this->decorate_sprites_mentioned[$sprite_name] = [];
			}
			
			//TODO If we notice a sprite with a zero length that isn't TNT1, add it to the list

			//Next characters up until the next whitespace are the sprite frames
			$char = '0';
			$result = trim($result);
			while (!in_array($char, [' ', '\t', ''])) {
				$char = substr($result, 0, 1);
				$result = substr($result, 1);
				if (!in_array($char, [' ', '\t', ''])) {
					$this->decorate_sprites_mentioned[$sprite_name][$char] = true;
				}
			}
		}
	}
}

//

$decorate_folder = isset($argv[1]) ? $argv[1] : null;
if ($decorate_folder == null) {
	die("Please provide a DECORATE folder to search as the first command line arg!");
}
$sprite_folder = isset($argv[2]) ? $argv[2] : null;
if ($sprite_folder == null) {
	die("Please provide a sprite folder to search as the second command line arg!");
}
$sud = new SpriteUseDetector();
$sud->run($decorate_folder, $sprite_folder);
