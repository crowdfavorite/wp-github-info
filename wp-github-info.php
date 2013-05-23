<?php
/*
Plugin Name: CF GitHub Info 
Plugin URI: http://crowdfavorite.com/wordpress/plugins/ 
Description: Get latest version and release date from GitHub. Includes local caching with filtered cache timeout setting. 
Version: 1.1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

load_plugin_textdomain('cf-github-info');

function cf_github_info($repo_url, $key) {
	return cfghi_api_info($repo_url, $key);
}

function cfghi_api_info($repo_url, $key) {
	$data = cfghi_get_api_data($repo_url);
	if (isset($data->$key)) {
		return $data->$key;
	}
	return false;
}

function cfghi_cache_time() {
	return apply_filters('cfghi_cache_time', 3600); // 1 hour default
}

function cfghi_get_api_data($repo_url = '') {
	if (empty($repo_url)) {
		return false;
	}
	// check cache
	if ($data = cfghi_get_cached_data($repo_url)) {
		return $data;
	}
	// check remote, cache
	else if ($data = cfghi_get_remote_data($repo_url)) {
		cfghi_cache_data($data);
		return $data;
	}
	// return any cached data we might have - some data better than no data
	else if ($data = get_option('cfghi_'.md5($repo_url))) {
		return $data['data'];
	}
	return false;
}

function cfghi_get_cached_data($repo_url) {
	if ($data = get_option('cfghi_'.md5($repo_url))) {
		$cache_time = cfghi_cache_time();
		if (isset($data['timestamp']) && ($data['timestamp'] + $cache_time) > time()) {
			return $data['data'];
		}
	}
	return false;
}

function cfghi_get_remote_data($repo_url) {
	$data = new stdClass;
	$data->repo_url = md5($repo_url);
	$data->version = '';
	$data->last_updated = '';

// get tags
	$tags_url = 'https://api.github.com/repos/'.trailingslashit(
		str_replace('https://github.com/', '', $repo_url)
	).'tags';
	$result = wp_remote_get($tags_url, array(
		'timeout' => '10',
		'sslverify' => false
	));
	if (is_wp_error($result)) {
		return $data;
	}
	$tags = json_decode($result['body']);
	$commit_url = $current_tag = 0;
// loop through tags to find latest
	foreach ($tags as $tag) {
		// on a rate limit exceeded error, the following is returned
		// stdClass Object (
		//		[message] => API Rate Limit Exceeded for 67.136.188.202
		//	)
		// this is a hack to workaround that
		if (!isset($tag->name)) {
			return $data;
		}
		if (version_compare($current_tag, $tag->name, '<')) {
			$data->version = $tag->name;
// get commit URL
			$commit_url = $tag->commit->url;
			break;
		}
	}
	if (!$commit_url) {
		return $data;
	}
	$result = wp_remote_get($commit_url, array(
		'timeout' => '10',
		'sslverify' => false
	));
	if (is_wp_error($result)) {
		return $data;
	}
	$commit = json_decode($result['body']);
	$data->last_updated = substr($commit->commit->committer->date, 0, 10);
// return version and release date
	return $data;
}

function cfghi_cache_data($data) {
	$val = array(
		'timestamp' => time(),
		'data' => $data
	);
	update_option('cfghi_'.md5($data->repo_url), $val);
}
