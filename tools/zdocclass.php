<?php
namespace dxn\zdoc;

class ZDocClass {

	var $name = null;
	var $parent_class_name = null;
	var $parent_class = null;
	var $replaces_class_name = null;
	var $replaced_by_class_name = null;

	var $flags = [];
	var $properties = [];
	var $consts = [];
	var $vars = [];

	var $data = [];
	var $state_lines = [];
	var $sprite_name = null;
	var $definition_filename = null;
	var $comment = '';
	var $category = '';
	var $states = '';
}