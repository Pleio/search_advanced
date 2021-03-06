<?php
/**
 * All hooks are bundled here
 */

/**
 * Return default results for searches on objects.
 *
 * @param string       $hook   name of hook
 * @param string       $type   type of hook
 * @param unknown_type $value  current value
 * @param array        $params parameters
 *
 * @return array
 */
function search_advanced_objects_hook($hook, $type, $value, $params) {

	static $tag_name_ids;
	static $tag_value_ids;
	static $valid_tag_names;
	
	$db_prefix = elgg_get_config('dbprefix');

	$query = sanitise_string($params['query']);
	
	if (!isset($tag_name_ids)) {
		if ($valid_tag_names = elgg_get_registered_tag_metadata_names()) {
			$tag_name_ids = array();
			foreach ($valid_tag_names as $tag_name) {
				$tag_name_ids[] = elgg_get_metastring_id($tag_name);
			}
		} else {
			$tag_name_ids = false;
		}
	}
	
	$params["joins"] = elgg_extract("joins", $params, array());
	
	if ($tag_name_ids) {
		$params["joins"][] = "JOIN {$db_prefix}objects_entity oe ON e.guid = oe.guid";
	} else {
		$params["joins"][] = "JOIN {$db_prefix}objects_entity oe ON e.guid = oe.guid";
	}
	
	$fields = array('title', 'description');
	
	if ($params["subtype"] === "page") {
		$params["subtype"] = array("page", "page_top");
	}

	if ($params["subtype"] === "groupforumtopic") {
		$params["subtype"] = array("groupforumtopic", "discussion_reply");
	}
	
	$where = search_advanced_get_where_sql('oe', $fields, $params, FALSE);

	$params["wheres"] = elgg_extract("wheres", $params, array());
	
	if ($tag_name_ids) {
		$query_parts = array();
		
		if (elgg_get_plugin_setting("enable_multi_tag", "search_advanced") == "yes") {
			$separator = elgg_get_plugin_setting("multi_tag_separator", "search_advanced", "comma");
			if ($separator == "comma") {
				$query_array = explode(",", $query);
			} else {
				$query_array = explode(" ", $query);
			}
			foreach ($query_array as $query_value) {
				$query_value = trim($query_value);
				if (!empty($query_value)) {
					$query_parts[] = $query_value;
				}
			}
		} else {
			$query_parts[] = $query;
		}
		
		// look up value ids to save a join
		if (!isset($tag_value_ids)) {
			$tag_value_ids = array();
			
			foreach ($query_parts as $query_part) {
				$metastring_ids = elgg_get_metastring_id($query_part, false);
				if (!is_array($metastring_ids)) {
					$metastring_ids = array($metastring_ids);
				}
				$tag_value_ids = array_merge($tag_value_ids, $metastring_ids);
			}
		}
		
		if (empty($tag_value_ids)) {
			$params['wheres'][] = $where;
		} else {
			$params["joins"][] = "LEFT OUTER JOIN {$db_prefix}metadata md on e.guid = md.entity_guid";
			
			$md_where = "((md.name_id IN (" . implode(",", $tag_name_ids) . ")) AND md.value_id IN (" . implode(",", $tag_value_ids) . "))";
			$params['wheres'][] = "(($where) OR ($md_where))";
		}
	} else {
		$params['wheres'][] = $where;
	}
	
	$params['count'] = TRUE;
	$count = elgg_get_entities($params);
	
	// no need to continue if nothing here.
	if (!$count || ($params["search_advanced_count_only"] == true)) {
		return array('entities' => array(), 'count' => $count);
	}
		
	$params['count'] = FALSE;
	$entities = elgg_get_entities($params);

	// add the volatile data for why these entities have been returned.
	foreach ($entities as $entity) {
		if ($valid_tag_names) {
			$matched_tags_strs = array();
	
			// get tags for each tag name requested to find which ones matched.
			foreach ($valid_tag_names as $tag_name) {
				$tags = $entity->getTags($tag_name);
	
				// @todo make one long tag string and run this through the highlight
				// function.  This might be confusing as it could chop off
				// the tag labels.
				if (isset($query_parts)) {
					foreach ($query_parts as $part) {
						if (in_array(strtolower($part), array_map('strtolower', $tags))) {
							if (is_array($tags)) {
								$tag_name_str = elgg_echo("tag_names:$tag_name");
								$matched_tags_strs[] = "$tag_name_str: " . implode(', ', $tags);
								// only need it once for each tag
								break;
							}
						}
					}
				}
			}
			
			$tags_str = implode('. ', $matched_tags_strs);
			$tags_str = search_get_highlighted_relevant_substrings($tags_str, $params['query']);
	
			$entity->setVolatileData('search_matched_extra', $tags_str);
		}
		
		$title = search_get_highlighted_relevant_substrings($entity->title, $params['query']);
		$entity->setVolatileData('search_matched_title', $title);

		$desc = search_get_highlighted_relevant_substrings($entity->description, $params['query']);
		$entity->setVolatileData('search_matched_description', $desc);
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

/**
 * Return default results for searches on groups.
 *
 * @param string       $hook   name of hook
 * @param string       $type   type of hook
 * @param unknown_type $value  current value
 * @param array        $params parameters
 *
 * @return array
 */
function search_advanced_groups_hook($hook, $type, $value, $params) {
	$db_prefix = elgg_get_config('dbprefix');

	$query = sanitise_string($params['query']);

	$profile_fields = array_keys(elgg_get_config('group'));
	if ($profile_fields) {
		$params['joins'] = array(
			"JOIN {$db_prefix}groups_entity ge ON e.guid = ge.guid",
			"JOIN {$db_prefix}metadata md on e.guid = md.entity_guid",
			"JOIN {$db_prefix}metastrings msv ON md.value_id = msv.id"
		);
	} else {
		$join = "JOIN {$db_prefix}groups_entity ge ON e.guid = ge.guid";
		$params['joins'] = array($join);
	}
	
	$fields = array('name', 'description');

	// force into boolean mode because we've having problems with the
	// "if > 50% match 0 sets are returns" problem.
	$where = search_advanced_get_where_sql('ge', $fields, $params, FALSE);

	if ($profile_fields) {

		$profile_field_metadata_search_values = elgg_get_plugin_setting("group_profile_fields_metadata_search", "search_advanced", array());
		if (!empty($profile_field_metadata_search_values)) {
			$profile_field_metadata_search_values = json_decode($profile_field_metadata_search_values, true);
		}
			
		$tag_name_ids = array();
		foreach ($profile_fields as $field) {
			if (!in_array($field, $profile_field_metadata_search_values)) {
				$tag_name_ids[] = elgg_get_metastring_id($field);
			}
		}
				
		$likes = array();
		if (elgg_get_plugin_setting("enable_multi_tag", "search_advanced") == "yes") {
			$separator = elgg_get_plugin_setting("multi_tag_separator", "search_advanced", "comma");
			if ($separator == "comma") {
				$query_array = explode(",", $query);
			} else {
				$query_array = explode(" ", $query);
			}
			foreach ($query_array as $query_value) {
				$query_value = trim($query_value);
				if (!empty($query_value)) {
					$likes[] = "msv.string LIKE '%$query_value%'";
				}
			}
		} else {
			$likes[] = "msv.string LIKE '%$query%'";
		}
				
		$md_where = "((md.name_id IN (" . implode(",", $tag_name_ids) . ")) AND (" . implode(" OR ", $likes) . "))";
		$params['wheres'] = array("(($where) OR ($md_where))");
	} else {
		$params['wheres'] = array($where);
	}
	
	// override subtype -- All groups should be returned regardless of subtype.
	$params['subtype'] = ELGG_ENTITIES_ANY_VALUE;

	$params['count'] = TRUE;
	$count = elgg_get_entities($params);
	
	// no need to continue if nothing here.
	if (!$count || ($params["search_advanced_count_only"] == true)) {
		return array('entities' => array(), 'count' => $count);
	}
	
	$params['count'] = FALSE;
	$entities = elgg_get_entities($params);

	// add the volatile data for why these entities have been returned.
	foreach ($entities as $entity) {
		$name = search_get_highlighted_relevant_substrings($entity->name, $query);
		$entity->setVolatileData('search_matched_title', $name);

		$description = search_get_highlighted_relevant_substrings($entity->description, $query);
		$entity->setVolatileData('search_matched_description', $description);
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

/**
 * Return default results for searches on users.
 *
 * @todo add profile field MD searching
 *
 * @param string       $hook   name of hook
 * @param string       $type   type of hook
 * @param unknown_type $value  current value
 * @param array        $params parameters
 *
 * @return array
 */
function search_advanced_users_hook($hook, $type, $value, $params) {
	
	$db_prefix = elgg_get_config('dbprefix');
	$query = sanitise_string($params['query']);

	$params['joins'] = array(
		"JOIN {$db_prefix}users_entity ue ON e.guid = ue.guid",
		"JOIN {$db_prefix}metadata md on e.guid = md.entity_guid",
		"JOIN {$db_prefix}metastrings msv ON md.value_id = msv.id"
	);
	
	if (isset($params["container_guid"])) {
		$entity = get_entity($params["container_guid"]);
	}
	
	if (isset($entity) && $entity instanceof ElggGroup) {
		// check for group membership relation
		$params["relationship"] = "member";
		$params["relationship_guid"] = $params["container_guid"];
		$params["inverse_relationship"] = TRUE;
		
		unset($params["container_guid"]);
	} else {
		// check for site relation ship
		if (empty($_SESSION["search_advanced:multisite"])) {
			$params["relationship"] = "member_of_site";
			$params["relationship_guid"] = elgg_get_site_entity()->getGUID();
			$params["inverse_relationship"] = TRUE;
		}
	}
	
	if (!empty($params["query"])) {
		$fields = array('username', 'name');
		$where = search_advanced_get_where_sql('ue', $fields, $params, FALSE);
		
		// profile fields
		$profile_fields = array_keys(elgg_get_config('profile_fields'));
		if ($profile_fields) {
			
			$profile_field_metadata_search_values = elgg_get_plugin_setting("user_profile_fields_metadata_search", "search_advanced", array());
			if (!empty($profile_field_metadata_search_values)) {
				$profile_field_metadata_search_values = json_decode($profile_field_metadata_search_values, true);
			}
			
			$tag_name_ids = array();
			foreach ($profile_fields as $field) {
				if (!in_array($field, $profile_field_metadata_search_values)) {
					$tag_name_ids[] = elgg_get_metastring_id($field);
				}
			}
			
			if (!empty($tag_name_ids)) {
				$likes = array();
				if (elgg_get_plugin_setting("enable_multi_tag", "search_advanced") == "yes") {
					$separator = elgg_get_plugin_setting("multi_tag_separator", "search_advanced", "comma");
					if ($separator == "comma") {
						$query_array = explode(",", $query);
					} else {
						$query_array = explode(" ", $query);
					}
					foreach ($query_array as $query_value) {
						$query_value = trim($query_value);
						if (!empty($query_value)) {
							$likes[] = "msv.string LIKE '%$query_value%'";
						}
					}
				} else {
					$likes[] = "msv.string LIKE '%$query%'";
				}
				
				$md_where = "((md.name_id IN (" . implode(",", $tag_name_ids) . ")) AND (" . implode(" OR ", $likes) . "))";
					
				$where = "(($where) OR ($md_where))";
			}
		}
		
		$params['wheres'] = array($where);
	}
	
	$profile_fields = $params["profile_filter"];
	if (!empty($profile_fields)) {
		$profile_field_likes = array();
		$profile_soundex = $params["profile_soundex"];
		$i = 0;
		foreach ($profile_fields as $field_name => $field_value) {
			$field_value = trim(sanitise_string($field_value));
			if (!empty($field_value)) {
				$tag_name_id = elgg_get_metastring_id($field_name);
				$i++;
				if ($i > 1) {
					$params["joins"][] = "JOIN {$db_prefix}metadata md$i on e.guid = md$i.entity_guid";
					$params["joins"][] = "JOIN {$db_prefix}metastrings msv$i ON md$i.value_id = msv$i.id";
				}
				
				// do a soundex match
				if (is_array($profile_soundex) && in_array($field_name, $profile_soundex)) {
					if ($i > 1) {
						$profile_field_likes[] = "md$i.name_id = $tag_name_id AND soundex(CONCAT('X', msv$i.string)) = soundex(CONCAT('X','$field_value'))";
					} else {
						$profile_field_likes[] = "md.name_id = $tag_name_id AND soundex(CONCAT('X', msv.string)) = soundex(CONCAT('X', '$field_value'))";
					}
				} else {
					if ($i > 1) {
						$profile_field_likes[] = "md$i.name_id = $tag_name_id AND msv$i.string LIKE '%$field_value%'";
					} else {
						$profile_field_likes[] = "md.name_id = $tag_name_id AND msv.string LIKE '%$field_value%'";
					}
				}
			}
		}
		if (!empty($profile_field_likes)) {
			$profile_field_where = "(" . implode(" AND ", $profile_field_likes) . ")";
			
			if (empty($params["wheres"])) {
				$params["wheres"] = array($profile_field_where);
			} else {
				$params["wheres"] = array($params["wheres"][0] . " AND " . $profile_field_where);
			}
		}
	}
	
	$wheres = (array) elgg_extract("wheres", $params);
	$wheres[] = "ue.banned = 'no'";
	$params["wheres"] = $wheres;
	
	// override subtype -- All users should be returned regardless of subtype.
	$params['subtype'] = ELGG_ENTITIES_ANY_VALUE;

	$params['count'] = TRUE;
	$count = elgg_get_entities_from_relationship($params);

	// no need to continue if nothing here.
	if (!$count || ($params["search_advanced_count_only"] == true)) {
		return array('entities' => array(), 'count' => $count);
	}
	
	$params['count'] = FALSE;
	$entities = elgg_get_entities_from_relationship($params);

	// add the volatile data for why these entities have been returned.
	foreach ($entities as $entity) {
		$username = search_get_highlighted_relevant_substrings($entity->username, $query);
		$entity->setVolatileData('search_matched_title', $username);

		$name = search_get_highlighted_relevant_substrings($entity->name, $query);
		$entity->setVolatileData('search_matched_description', $name);
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

/**
 * Registers menu type selection menu items
 *
 * @param string       $hook   name of hook
 * @param string       $type   type of hook
 * @param unknown_type $value  current value
 * @param array        $params parameters
 *
 * @return array
 */
function search_advanced_register_menu_type_selection($hook, $type, $value, $params) {
	$result = $value;
	
	$types = get_registered_entity_types();
	$custom_types = elgg_trigger_plugin_hook("search_types", "get_types", array(), array());
	
	$result[]  = ElggMenuItem::factory(array(
		"name" => "all",
		"text" => "<a>" . elgg_echo("all") . "</a>",
		"href" => false
	));
	$result[]  = ElggMenuItem::factory(array(
		"name" => "item:user",
		"text" => "<a rel='user'>" . elgg_echo("item:user") . "</a>",
		"href" => false
	));
	$result[]  = ElggMenuItem::factory(array(
		"name" => "item:group",
		"text" => "<a rel='group'>" . elgg_echo("item:group") . "</a>",
		"href" => false
	));
	
	foreach ($types["object"] as $subtype) {
		$result[]  = ElggMenuItem::factory(array(
			"name" => "item:object:$subtype",
			"text" => "<a rel='object " . $subtype . "'>" . elgg_echo("item:object:" . $subtype) . "</a>",
			"href" => false,
			"title" => elgg_echo("item:object:$subtype")
		));
	}
	
	foreach ($custom_types as $type) {
		$result[]  = ElggMenuItem::factory(array(
			"name" => "search_types:$type",
			"text" => "<a rel='" . $type . "'>" . elgg_echo("search_types:$type") . "</a>",
			"href" => false,
			"title" => elgg_echo("search_types:$type")
		));
	}
	
	return $result;
}
