#!/usr/bin/php
<?php
php_sapi_name() == "cli" or die("<br><strong>This script is not intended to be runned from web.</strong>" . PHP_EOL);
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("config.php");
include("Airports.php");

define ("EOL_VATSIM_", "\r?\n");

function memcacheSetFixed(&$m, $key, $value, $flags = 0, $expiration = 0)
{
    if ($m->replace($key, $value, $flags, $expiration) == false) {
        return $m->set($key, $value, $flags, $expiration);
    }
    return true;
}

function parseCreatedTimeStamp($str)
{
    if (!is_string($str)) {
        error_log('parseCreatedTimeStamp(): str is not string!');
        return false;
    }
    $res = preg_match('/; Created at (\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2}):(\d{2})/', $str, $created);
    if (!$res || !is_array($created) || count($created) != 7) {
        error_log('preg_match() failed!');
        return false;
    }
    try {
        $obj = DateTime::createFromFormat("d/m/Y H:i:s", "{$created[1]}/{$created[2]}/{$created[3]} {$created[4]}:{$created[5]}:{$created[6]}", new DateTimeZone('UTC'));
        
        if (!$obj) {
            error_log('createFromFormat() failed!');
            return false;
        }
        return $obj->getTimestamp();
    }
    catch (Exception $e) {
        error_log($e->getMessage());
        return false;
    }
}

function getCreatedTimeStampFromMemCache()
{
    $m = new Memcache;
    $m->connect(MEMCACHE_IP, MEMCACHE_PORT);
    $clients_data = $m->get(md5(MEMCACHE_PREFIX_VATSIM . MEMCACHE_PREFIX_CLIENTS_DATA . MEMCACHE_PREFIX_META));
    $m->close();
    if (!$clients_data) {
        error_log('failed to get clients_data from memcache');
        return false;
    }
    return (int)$clients_data['created_timestamp'];
}

function toUTF8($str)
{
    if (!((bool) preg_match('//u', $str))) {
        $resultUTF8 = utf8_encode($str);
    } else {
        $resultUTF8 = $str;
    }
    return str_replace(utf8_encode(chr(0x5E) . chr(0xA7)), "\n", $resultUTF8);
}

function fixArrayEncoding(&$arr)
{
    foreach ($arr as $key => $val) {
        $arr[$key] = toUTF8($arr[$key]);
    }
}

function loadServersArray()
{
    return json_decode(file_get_contents("./vatsim_servers.json"), true);
}

function addToDB($arr, $timestamp)
{
    $m = new Memcache;
    $m->connect(MEMCACHE_IP, MEMCACHE_PORT);
    $clients = array();
    foreach ($arr as $v) {
        if ($v["clienttype"] != "ATC" && $v["clienttype"] != "PILOT") {
            continue;
        }
        $clients[] = array(
            $v["cid"],
            $v["callsign"],
            $v["clienttype"],
            $v["heading"],
            $v["latitude"],
            $v["longitude"],
            $v["atis_message"]
        );
        
        memcacheSetFixed($m, md5(MEMCACHE_PREFIX_VATSIM . $v["cid"] . $v["callsign"]), json_encode($v), 0, 60 * 60 * 24); //24 hours expiration
        if (json_last_error() != JSON_ERROR_NONE) {
            error_log("json_last_error(): " . json_last_error());
            print_r($v);
        }
    }
    $json = json_encode($clients);
    if (json_last_error() != JSON_ERROR_NONE) {
        error_log("json_last_error(): " . json_last_error());
    }
    $res = memcacheSetFixed($m, md5(MEMCACHE_PREFIX_VATSIM . MEMCACHE_PREFIX_CLIENTS_DATA . MEMCACHE_PREFIX_JSON), $json) && memcacheSetFixed($m, md5(MEMCACHE_PREFIX_VATSIM . MEMCACHE_PREFIX_CLIENTS_DATA . MEMCACHE_PREFIX_META), array(
        'md5' => md5($json),
        'last_modified' => time(),
        'created_timestamp' => $timestamp
    ));
    $m->close();
    if (!$res) {
        error_log('failed to save data to memcache!');
    }
}

function trytoparse($url)
{
    $clients_container = Array();
    $data              = file_get_contents($url);
    if (!$data) {
        error_log("file_get_contents($url) fails");
        return false;
    }
	if(!strpos($data, ";   END")){
		return false;
	}
    preg_match("/!CLIENTS:(.*?)" . EOL_VATSIM_ . ";" . EOL_VATSIM_ . ";" . EOL_VATSIM_ . "/s", $data, $clients_container);
    
    if (!isset($clients_container[1])) {
        error_log("cannot parse data");
        return false;
    }
    $clients   = "";
    $timestamp = parseCreatedTimeStamp($data);
    if (!$timestamp) {
        error_log('parseCreatedTimeStamp() fails.');
        return false;
    }
    $timestamp_from_memcache = getCreatedTimeStampFromMemCache();
    if (!$timestamp_from_memcache) {
        error_log('no timestamp from memcache, skip checking.');
    }
    if ($timestamp && $timestamp_from_memcache && ($timestamp <= $timestamp_from_memcache)) {
        //error_log('old data, skip');
        return false;
    }
    
    preg_match_all("/(.*?):" . EOL_VATSIM_ . "/", $clients_container[1], $clients);
    
    if (!isset($clients[1])) {
        error_log("cannot parse !CLIENTS container ($url)");
        return false;
    }
    
    $clients = $clients[1];
    
    preg_match("/; !CLIENTS section -(.*?):" . EOL_VATSIM_ . ";/", $data, $clients_tpl);
    
    if (!isset($clients_tpl[1])) {
        error_log("cannot parse clients_tpl ($url)");
        return false;
    }
    
    $clients_final = array();
    $tpl_array     = explode(":", trim($clients_tpl[1]));
    
    foreach ($clients as $key => $item) {
        $cl_array = explode(":", trim($item));
        fixArrayEncoding($cl_array);
		$combined = array_combine($tpl_array, $cl_array);
		if($combined && is_array($combined)){
			$clients_final[$key] = $combined;
		}
    }
    
    //get planned_depairport_lat, planned_depairport_lon, planned_destairport_lat, planned_destairport_lon values from the database
    $airports = new Airports();
    foreach ($clients_final as $k => $v) {
        if (!is_array($v)) {
            error_log("not an array!");
            continue;
        }
        $dep  = false;
        $dest = false;
        if (array_key_exists("planned_depairport", $v) && strlen($v["planned_depairport"]) > 0) {
            $dep = $airports->getAirportDetails($v["planned_depairport"]);
        }
        if (array_key_exists("planned_destairport", $v) && strlen($v["planned_destairport"]) > 0) {
            $dest = $airports->getAirportDetails($v["planned_destairport"]);
        }
        if ($dep) {
            $clients_final[$k]["planned_depairport_lat"] = $dep[6];
            $clients_final[$k]["planned_depairport_lon"] = $dep[7];
        }
        if ($dest) {
            $clients_final[$k]["planned_destairport_lat"] = $dest[6];
            $clients_final[$k]["planned_destairport_lon"] = $dest[7];
        }
    }
    
    addToDB($clients_final, $timestamp);
    
    return true;
}

$serversArray = loadServersArray();

if (count($serversArray) <= 0) {
    error_log("loadServersArray() fails!");
    die();
}

shuffle($serversArray);

foreach ($serversArray as $url) {
    if (trytoparse($url)) {
        break;
    }
}

exit(0);

?>
