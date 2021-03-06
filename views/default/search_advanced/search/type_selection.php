<?php

$types = get_registered_entity_types();
$custom_types = elgg_trigger_plugin_hook('search_types', 'get_types', array(), array());

$current_selected = elgg_echo("all");

$entity_type = get_input("entity_type");
$entity_subtype = get_input("entity_subtype");
$search_type = get_input("search_type");

if (!in_array($search_type, $custom_types)) {
	$search_type = "";
}

if (!array_key_exists($entity_type, $types)) {
	$entity_type = "";
}

if (array_key_exists($entity_type, $types) && !in_array($entity_subtype, $types[$entity_type])) {
	$entity_subtype = "";
}

if (!empty($search_type) && ($search_type !== "entities")) {
	$current_selected = elgg_echo("search_types:" . $search_type);
	
	echo elgg_view("input/hidden", array("name" => "search_type", "value" => $search_type));
	echo elgg_view("input/hidden", array("name" => "entity_type", "disabled" => "disabled"));
	echo elgg_view("input/hidden", array("name" => "entity_subtype", "disabled" => "disabled"));
} elseif (!empty($entity_type)) {
	echo elgg_view("input/hidden", array("name" => "search_type", "value" => "entities"));
	echo elgg_view("input/hidden", array("name" => "entity_type", "value" => $entity_type));
	
	$current_selected = elgg_echo("item:" . $entity_type);
	
	if (!empty($entity_subtype)) {
		$current_selected = elgg_echo("item:" . $entity_type . ":" . $entity_subtype);
		
		echo elgg_view("input/hidden", array("name" => "entity_subtype", "value" => $entity_subtype));
	} else {
		echo elgg_view("input/hidden", array("name" => "entity_subtype", "disabled" => "disabled"));
	}
} else {
	echo elgg_view("input/hidden", array("name" => "search_type", "value" => "entities", "disabled" => "disabled"));
	echo elgg_view("input/hidden", array("name" => "entity_type", "disabled" => "disabled"));
	echo elgg_view("input/hidden", array("name" => "entity_subtype", "disabled" => "disabled"));
}

echo "<ul class='search-advanced-type-selection'>";
echo "<li>";
echo "<a>" .  $current_selected . "</a>";
echo elgg_view_menu("search_type_selection", array("class" => "search-advanced-type-selection-dropdown"));
echo "</li>";
echo "</ul>";
