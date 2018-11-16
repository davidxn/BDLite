<?php
namespace dxn\zdoc;

require_once('./zdocclassreader.php');

class ZDocClassDatabase {
	
	var $zdoc_class_reader;
	var $names_to_class_data = [];
	
	public function __construct($options) {
		$this->zdoc_class_reader = new ZDocClassReader($options);
		$this->names_to_class_data = $this->zdoc_class_reader->run();
	}
	
	public function get_zclass_list() {
		return $this->names_to_class_data;
	}
	
	public function has_zclass($name) {
		return isset($this->names_to_class_data[strtolower($name)]);
	}
	
	public function get_zclass($name) {
		return isset($this->names_to_class_data[strtolower($name)]) ? $this->names_to_class_data[strtolower($name)] : false;	
	}

	public function get_parent($name) {
		$class = $this->get_zclass($name);
		return isset($this->names_to_class_data[$class->parent_class_name]) ? $this->names_to_class_data[$class->parent_class_name] : false;	
	}
	
	public function get_subclasses($name) {
		$name = strtolower($name);
		return array_filter($this->names_to_class_data, function($class) use ($name) {
			return (strtolower($class->parent_class_name) == $name);
		});
	}
	
	public function get_neighbours($name) {
		$name = strtolower($name);
		$file = $this->get_zclass($name)->definition_filename;
		return array_filter($this->names_to_class_data, function($class) use ($file) {
			return ($class->definition_filename == $file);
		});
	}

	public function get_reader_errors() {
		return $this->zdoc_class_reader->errors;
	}
}