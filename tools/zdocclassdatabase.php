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
		return array_filter($this->names_to_class_data, function($zclass) use ($name) {
			return (strtolower($zclass->parent_class_name) == $name);
		});
	}
	
	public function get_neighbours($name) {
		$name = strtolower($name);
		$file = $this->get_zclass($name)->definition_filename;
		return array_filter($this->names_to_class_data, function($zclass) use ($file) {
			return ($zclass->definition_filename == $file);
		});
	}
	
	public function get_surrounding_tree($name) {
		$name = strtolower($name);
		$class = $this->get_zclass($name);
		$subclasses = array_values($this->get_subclasses($name));
		$tree_subclasses = [];
		foreach ($subclasses as $subclass) {
			$tree_subclasses[] = ['class' => $subclass, 'subclasses' => []];
		}
		$tree = ['class' => $class, 'subclasses' => $tree_subclasses];
		while (true) {
			$class = $this->get_zclass($class->parent_class_name);
			if (!$class) {
				break;
			}
			$tree = ['class' => $class, 'subclasses' => [$tree]];
		}
		return $tree;
	}
	
	//Stupid name of function, I can't think of a better word for "flags, properties, etc"
	public function get_parent_facts($name) {
		$tree = $this->get_surrounding_tree($name);
		
		$flags = [];
		$properties = [];
		//Having got the tree, iterate down gathering all flags until we hit our class
		while (strtolower($tree['class']->name) != $name) {
			$flags = array_merge($flags, $tree['class']->flags);
			$properties = array_merge($properties, $tree['class']->properties);
			if (count($tree['subclasses']) == 0) {
				break;
			}
			$tree = $tree['subclasses'][0];
		}
		return ['flags' => $flags, 'properties' => $properties];
	}

	public function get_reader_errors() {
		return $this->zdoc_class_reader->errors;
	}
	
	public function get_doomed_numbers() {
		return $this->zdoc_class_reader->get_doomed_numbers();
	}
}