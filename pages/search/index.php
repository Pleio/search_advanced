<?php
/**
 * Elgg search page
 *
 * @todo much of this code should be pulled out into a library of functions
 */

// Search supports RSS
global $autofeed;
$autofeed = true;

// $search_type == all || entities || trigger plugin hook
$search_type = get_input('search_type', 'all');
$entity_type = get_input('entity_type', ELGG_ENTITIES_ANY_VALUE);

// @todo there is a bug in get_input that makes variables have slashes sometimes.
// @todo is there an example query to demonstrate ^
// XSS protection is more important that searching for HTML.
$query = stripslashes(get_input('q', get_input('tag', '')));
$profile_filter = get_input('search_advanced_profile_fields');
$profile_soundex = get_input('search_advanced_profile_fields_soundex');

// @todo - create function for sanitization of strings for display in 1.8
// encode <,>,&, quotes and characters above 127
if (function_exists('mb_convert_encoding')) {
	$display_query = mb_convert_encoding($query, 'HTML-ENTITIES', 'UTF-8');
} else {
	// if no mbstring extension, we just strip characters
	$display_query = preg_replace("/[^\x01-\x7F]/", "", $query);
}
$display_query = htmlspecialchars($display_query, ENT_QUOTES, 'UTF-8', false);


// show loader or direct page
$loader = (int) get_input("loader", 0);

$search_with_loader = false;
$plugin_setting = elgg_get_plugin_setting("search_with_loader", "search_advanced");
if ($plugin_setting == "yes") {
	$search_with_loader = true;
}

if ($search_with_loader && !elgg_is_xhr()) {
	// show loader
	$title = elgg_echo('search:results', array("\"$display_query\""));

	$page_data = elgg_view_layout("one_column", array(
		"content" => elgg_view("search_advanced/loader")
	));

	echo elgg_view_page($title, $page_data);
	return;
}


// check that we have an actual query
if (!$query && !((count($profile_filter) > 0) && $entity_type == "user")) {
	$title = sprintf(elgg_echo('search:results'), "\"$display_query\"");
	
	$body  = elgg_view_title(elgg_echo('search:search_error'));
	if (!elgg_is_xhr() || ($search_with_loader && $loader)) {
		$body .= elgg_view_form("search_advanced/search", array("action" => "search", "method" => "GET", "disable_security" => true), array());
	}
	
	$body .= elgg_echo('search:no_query');
	if (!elgg_is_xhr()) {
		$layout = elgg_view_layout('one_sidebar', array('content' => $body));
		$body = elgg_view_page($title, $layout);
	} elseif (elgg_is_xhr() && $loader) {
		$body = elgg_view_layout('one_sidebar', array('content' => $body));
	}
	echo $body;
	return;
}

// get limit and offset.  override if on search dashboard, where only 2
// of each most recent entity types will be shown.
$limit = ($search_type == 'all') ? 2 : get_input('limit', 10);
$offset = ($search_type == 'all') ? 0 : get_input('offset', 0);

$entity_subtype = get_input('entity_subtype', ELGG_ENTITIES_ANY_VALUE);
$owner_guid = get_input('owner_guid', ELGG_ENTITIES_ANY_VALUE);
$container_guid = get_input('container_guid', ELGG_ENTITIES_ANY_VALUE);
$friends = get_input('friends', ELGG_ENTITIES_ANY_VALUE);
$sort = get_input('sort');
switch ($sort) {
	case 'relevance':
	case 'created':
	case 'updated':
	case 'action_on':
	case 'alpha':
		break;

	default:
		$sort = 'relevance';
		break;
}

$order = get_input('sort', 'desc');
if ($order != 'asc' && $order != 'desc') {
	$order = 'desc';
}

// set up search params
$params = array(
	'query' => $query,
	'offset' => $offset,
	'limit' => $limit,
	'sort' => $sort,
	'order' => $order,
	'search_type' => $search_type,
	'type' => $entity_type,
	'subtype' => $entity_subtype,
//	'tag_type' => $tag_type,
	'owner_guid' => $owner_guid,
	'container_guid' => $container_guid,
//	'friends' => $friends
	'pagination' => ($search_type == 'all') ? FALSE : TRUE,
	'profile_filter' => $profile_filter,
	'profile_soundex' => $profile_soundex
);

// check for multisite possibilities

$search_multisite = null;

if (($user = elgg_get_logged_in_user_entity()) && elgg_trigger_plugin_hook("search_multisite", "search", array("user" => $user), false)) {
	// get and store search preference
	$search_multisite = (int) get_input("multisite", $_SESSION["search_advanced:multisite"]);
	$_SESSION["search_advanced:multisite"] = $search_multisite;
	
	if ($search_multisite) {
		$site_options = array(
			"type" => "site",
			"relationship" => "member_of_site",
			"relationship_guid" => $user->getGUID(),
			"limit" => false,
			"site_guids" => false
			// custom callback for guids only
		);
		if ($sites = elgg_get_entities_from_relationship($site_options)) {
			$params["site_guids"] = array();
			foreach ($sites as $row) {
				$params["site_guids"][] = $row->guid;
			}
		}
	}
}

$types = get_registered_entity_types();
$custom_types = elgg_trigger_plugin_hook('search_types', 'get_types', $params, array());

$search_result_counters = array();

// start the actual search
$results_html = array();
if (array_key_exists("object", $types)) {
	
	// let order reflect menu order
	
	$orig_types = $types;
	unset($types["object"]);
	$types["object"] = $orig_types["object"];
}

// to pass the correct current search type to the views
$current_params = $params;
$current_params['search_type'] = 'entities';
// foreach through types.
// if a plugin returns FALSE for subtype ignore it.
// if a plugin returns NULL or '' for subtype, pass to generic type search function.
// if still NULL or '' or empty(array()) no results found. (== don't show??)

$combine_search_results = false;
if (elgg_get_plugin_setting("combine_search_results", "search_advanced") == "yes") {
	$combine_search_results = true;
}

foreach ($types as $type => $subtypes) {
	if (is_array($subtypes) && count($subtypes) && !empty($params["query"])) {
		foreach ($subtypes as $subtype) {
			if ($subtype === "page_top") {
				// skip this one as it is merged with page objects
				continue;
			}
			// no need to search if we're not interested in these results
			// @todo when using index table, allow search to get full count.
			if ($search_type == "tags") {
				continue;
			}
			
			if ($search_type != 'all' && $entity_subtype != $subtype) {
				// only want count if doing specific search
				$current_params['search_advanced_count_only'] = true;
			}
			
			$current_params['subtype'] = $subtype;
			$current_params['type'] = $type;
			
			$results = elgg_trigger_plugin_hook('search', "$type:$subtype", $current_params, NULL);
			if ($results === FALSE) {
				// someone is saying not to display these types in searches.
				continue;
			} elseif (is_array($results) && !count($results)) {
				// no results, but results searched in hook.
			} elseif (!$results) {
				// no results and not hooked.  use default type search.
				// don't change the params here, since it's really a different subtype.
				// Will be passed to elgg_get_entities().
				$results = elgg_trigger_plugin_hook('search', $type, $current_params, array());
			}

			if (is_array($results['entities']) && $results['count']) {
				if ($view = search_get_search_view($current_params, 'list')) {
					$search_result_counters["item:" . $type . ":" . $subtype] = $results['count'];
					if ($current_params['search_advanced_count_only'] !== true) {
						$results_html["item:" . $type . ":" . $subtype] = elgg_view($view, array(
							'results' => $results,
							'params' => $current_params,
						));
					}
				}
			}
			
			unset($current_params["search_advanced_count_only"]);
		}
	}
	
	if ($type !== "object") {
		if ($type !== "user" && empty($params["query"])) {
			continue;
		}
		
		if ($type == "user" && empty($params["query"]) && empty($profile_filter)) {
			continue;
		}
		// pull in default type entities with no subtypes
		$current_params['type'] = $type;
		$current_params['subtype'] = ELGG_ENTITIES_NO_VALUE;
		
		if ($search_type != 'all' && $entity_type != $type) {
			// only want count if doing specific search
			$current_params['search_advanced_count_only'] = true;
		}
		
		$results = elgg_trigger_plugin_hook('search', $type, $current_params, array());
		if ($results) {
			// if $results = FALSE => someone is saying not to display these types in searches.
			if (is_array($results['entities']) && $results['count']) {
				if ($view = search_get_search_view($current_params, 'list')) {
					$search_result_counters["item:" . $type] = $results['count'];
					if ($current_params['search_advanced_count_only'] !== true) {
						$results_html["item:" . $type] = elgg_view($view, array(
							'results' => $results,
							'params' => $current_params,
						));
					}
				}
			}
		}
		unset($current_params['search_advanced_count_only']);
	}
}

// add the combined content search results
if ($combine_search_results && ($search_type == 'all') && !empty($params["query"])) {
	$current_params = $params;
	$current_params['search_type'] = 'entities';
	$current_params['type'] = "object";
	$current_params['limit'] = 20;
	if (array_key_exists("object", $types)) {
		$current_params['subtype'] = $types["object"];
		$results = elgg_trigger_plugin_hook('search', $type, $current_params, array());
		if (is_array($results['entities']) && $results['count']) {
			if ($view = search_get_search_view($current_params, 'list')) {
				
				// reset count to 0 to remove the "view more" url
				$results['count'] = 0;
				
				$results_html["all:combined"] = elgg_view($view, array(
					'results' => $results,
					'params' => $current_params,
				));
			}
		}
		
		// determine menu counters
		$db_prefix = elgg_get_config('dbprefix');
					
		$count_query  = "SELECT es.subtype, count(distinct e.guid) as total";
		$count_query .= " FROM {$db_prefix}entities e";
		$count_query .= " JOIN {$db_prefix}objects_entity oe ON e.guid = oe.guid";
		$count_query .= " JOIN {$db_prefix}entity_subtypes es ON e.subtype = es.id";

		$fields = array('title', 'description');
		$where = search_advanced_get_where_sql('oe', $fields, $params);
		
		// add tags search
		if ($valid_tag_names = elgg_get_registered_tag_metadata_names()) {
			$tag_name_ids = array();
			foreach ($valid_tag_names as $tag_name) {
				$tag_name_ids[] = elgg_get_metastring_id($tag_name);
			}
			
			$count_query .= " JOIN {$db_prefix}metadata md on e.guid = md.entity_guid";
			$count_query .= " JOIN {$db_prefix}metastrings msv ON md.value_id = msv.id";
			
			$md_where = "((md.name_id IN (" . implode(",", $tag_name_ids) . ")) AND msv.string = '" . sanitise_string($params["query"]) . "')";
		}
		
		
		// add wheres
		$count_query .= " WHERE e.type = 'object' AND es.subtype IN ('" . implode("', '", $current_params['subtype']) . "') AND ";
		if ($container_guid) {
			$count_query .= "e.container_guid = " . $container_guid . " AND ";
		}
		
		if (isset($md_where)) {
			$count_query .= "((" . $where . ") OR (" . $md_where . "))";
		} else {
			$count_query .= $where;
		}
		
		$count_query .= " AND ";
		
		if ($search_multisite) {
			$site_options = array(
					"type" => "site",
					"relationship" => "member_of_site",
					"relationship_guid" => $user->getGUID(),
					"limit" => false,
					"site_guids" => false
					// custom callback for guids only
			);
			
			if ($sites = elgg_get_entities_from_relationship($site_options)) {
				$site_guids = array();
				foreach ($sites as $row) {
					$site_guids[] = $row->guid;
				}
				$count_query .= "e.site_guid IN (" . implode(", ", $site_guids) . ") ";
			} else {
				$count_query .= "e.site_guid = " . elgg_get_site_entity()->getGUID() . " ";
			}
		} else {
			$count_query .= "e.site_guid = " . elgg_get_site_entity()->getGUID() . " ";
		}
		
		$count_query .= " AND ";
		
		// Add access controls
		$count_query .= get_access_sql_suffix('e');
		
		$count_query .= " GROUP BY e.subtype";
		$totals = get_data($count_query);
		if ($totals) {
			foreach ($totals as $row) {
				$search_result_counters["item:object:" . $row->subtype] = $row->total;
			}
		}
	}
}

// call custom searches
if (is_array($custom_types) && !empty($params["query"])) {
	foreach ($custom_types as $type) {

		$current_params = $params;
		$current_params['search_type'] = $type;

		// no need to search if we're not interested in these results
		// @todo when using index table, allow search to get full count.
		if ($search_type == "tags") {
			continue;
		}
		
		if ($search_type != 'all' && $search_type != $type) {
			// only want count if doing specific search
			$current_params['search_advanced_count_only'] = true;
		}
		
		$results = elgg_trigger_plugin_hook('search', $type, $current_params, array());

		if ($results === FALSE) {
			// someone is saying not to display these types in searches.
			continue;
		}
		
		if (isset($results['entities']) && is_array($results['entities']) && $results['count']) {
			if ($view = search_get_search_view($current_params, 'list')) {
				$search_result_counters["search_types:" . $type] = $results['count'];
				if ($current_params['search_advanced_count_only'] !== true) {
					$results_html["search_types:" . $type] = elgg_view($view, array(
						'results' => $results,
						'params' => $current_params,
					));
				}
			}
		}
		
		if (isset($results["content"])) {
			$results_html["search_types:" . $type] = $results["content"];
		}
	}
}

// highlight search terms
$searched_words = search_remove_ignored_words($display_query, 'array');
$highlighted_query = search_highlight_words($searched_words, $display_query);

$body = elgg_view_title(elgg_echo('search:results', array("\"$highlighted_query\"")));

// add search form
if (!elgg_is_xhr() || ($search_with_loader && $loader)) {
	$body .= elgg_view_form("search_advanced/search",array("action" => "search", "method" => "GET", "disable_security" => true), $params);
}

$results_html = elgg_trigger_plugin_hook("search_results", "search", array("orig_results" => $results_html), $results_html);

if (empty($results_html)) {
	$body .= elgg_view('search/no_results');
} else {
	$body .= implode("", $results_html);
}

// add sidebar items for all and native types
// moved to bottom so we can use search result count in labels

$data = htmlspecialchars(http_build_query(array(
	'q' => $query,
	'search_type' => 'all',
)));
$url = "search?$data";
$menu_item = new ElggMenuItem('all', elgg_echo('all'), $url);
elgg_register_menu_item('page', $menu_item);

foreach ($types as $type => $subtypes) {
	// @todo when using index table, can include result counts on each of these.
	if (is_array($subtypes) && count($subtypes)) {
		foreach ($subtypes as $subtype) {
			if ($subtype === "page_top") {
				// skip this one as it is merged with page objects
				continue;
			}
			$label = "item:$type:$subtype";
			
			$count = "";
			if (array_key_exists($label, $search_result_counters)) {
				$total = $search_result_counters[$label];
				if ($total > 0) {
					$count = " <span class='elgg-quiet'>(" . $total . ")</span>";
				}
			}
			if (!empty($count)) {
				$data = htmlspecialchars(http_build_query(array(
					'q' => $query,
					'entity_subtype' => $subtype,
					'entity_type' => $type,
					'owner_guid' => $owner_guid,
					'container_guid' => $container_guid,
					'search_type' => 'entities',
					'friends' => $friends
				)));
	
				$url = "search?$data";
				$menu_item = new ElggMenuItem($label, elgg_echo($label) . $count, $url);
				$menu_item->setSection($type);
				elgg_register_menu_item('page', $menu_item);
			}
		}
	} else {
		$label = "item:$type";

		$count = "";
		if (array_key_exists($label, $search_result_counters)) {
			$total = $search_result_counters[$label];
			if ($total > 0) {
				$count = " <span class='elgg-quiet'>(" . $total . ")</span>";
			}
		}
		
		if (!empty($count)) {
			$data = htmlspecialchars(http_build_query(array(
				'q' => $query,
				'entity_type' => $type,
				'owner_guid' => $owner_guid,
				'container_guid' => $container_guid,
				'search_type' => 'entities',
				'friends' => $friends
			)));
	
			$url = elgg_get_site_url() . "search?$data";
	
			$menu_item = new ElggMenuItem($label, elgg_echo($label) . $count, $url);
			elgg_register_menu_item('page', $menu_item);
		}
	}
}

// add sidebar for custom searches
foreach ($custom_types as $type) {
	
	$label = "search_types:$type";

	$count = "";
	if (array_key_exists($label, $search_result_counters)) {
		$total = $search_result_counters[$label];
		if ($total > 0) {
			$count = " <span class='elgg-quiet'>(" . $total . ")</span>";
		}
	}

	if (!empty($count)) {
		$data = htmlspecialchars(http_build_query(array(
			'q' => $query,
			'search_type' => $type,
			'container_guid' => $container_guid,
		)));
	
		$url = "search?$data";
	
		$menu_item = new ElggMenuItem($label, elgg_echo($label) . $count, $url);
		elgg_register_menu_item('page', $menu_item);
	}
}

// this is passed the original params because we don't care what actually
// matched (which is out of date now anyway).
// we want to know what search type it is.
if (elgg_is_xhr() && !$loader) {
	echo $body;
} elseif (elgg_is_xhr() && $loader) {

	$layout_view = search_get_search_view($params, 'layout');
	$layout = elgg_view($layout_view, array('params' => $params, 'body' => $body));

	echo $layout;
} else {
	$layout_view = search_get_search_view($params, 'layout');
	$layout = elgg_view($layout_view, array('params' => $params, 'body' => $body));

	$title = elgg_echo('search:results', array("\"$display_query\""));

	echo elgg_view_page($title, $layout);
}
