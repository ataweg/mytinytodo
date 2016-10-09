<?php

/*
	This file is part of myTinyTodo.
	(C) Copyright 2010-2011 Max Pozdeev <maxpozdeev@gmail.com>
	For modifications and enhancements (C) Copyright 2016 Axel Werner
	Licensed under the GNU GPL v2 license. See file COPYRIGHT for details.
*/

//$dontStartSession = 1;
require_once('./init.php');

$onlyPublishedList = false;
if(!have_write_access()) $onlyPublishedList = true;

$listId = (int)_get('list');
$listData = $db->sqa("SELECT * FROM {$db->prefix}lists WHERE id=$listId ". ($onlyPublishedList ? "AND published=1" : "") );
if(!$listData) {
	die("No such list or access denied");
}

$sqlSort = "ORDER BY compl ASC, ";
if($listData['sorting'] == 1) $sqlSort .= "prio DESC, ddn ASC, duedate ASC, ow ASC";
elseif($listData['sorting'] == 2) $sqlSort .= "ddn ASC, duedate ASC, prio DESC, ow ASC";
else $sqlSort .= "ow ASC";

$data = array();
$q = $db->dq("SELECT *, duedate IS NULL AS ddn FROM {$db->prefix}todolist WHERE list_id=$listId $sqlSort");
while($r = $q->fetch_assoc($q))
{
	$data[] = $r;
}

$format = _get('format');

if($format == 'ical') printICal($listData, $data);
else printCSV($listData, $data);


function have_write_access()
{
	if(Config::get('password') == '') return true;
	if(is_logged()) return true;
	return false;
}


function printCSV($listData, $data)
{
	$seperator = Lang::instance()->get('csv_seperator');

	$s = chr(0xEF).chr(0xBB).chr(0xBF).  /* UTF-8 encoded file */
		            Lang::instance()->get('taskdate_completed').
		$seperator. Lang::instance()->get('priority').
		$seperator. Lang::instance()->get('task').
		$seperator. Lang::instance()->get('tags').
		$seperator. Lang::instance()->get('due').
		$seperator. Lang::instance()->get('taskdate_created').
		$seperator. Lang::instance()->get('taskdate_modified').
		$seperator. Lang::instance()->get('taskdate_completed').
		$seperator. Lang::instance()->get('text_opt_markup').
		$seperator. Lang::instance()->get('text_hard_wrap').
		$seperator. Lang::instance()->get('text_keep_blanks').
		$seperator. Lang::instance()->get('note').
		$seperator. "\n";

	foreach($data as $r)
	{
		$na = str_split( $r['note'], 32000);

		foreach( $na as $n )
		{
			$s .= ($r['compl']?'1':'0').
			$seperator. $r['prio'].
			$seperator. escape_csv($r['title']).
			$seperator. escape_csv($r['tags']).
			$seperator. $r['duedate'].
			$seperator. date('d.m.Y H:i', $r['d_created']).	  /* 'Y-m-d H:i:s O' */
			$seperator. date('d.m.Y H:i', $r['d_edited']).	   /* 'Y-m-d H:i:s O' */
			$seperator. ($r['d_completed'] ? date('d.m.Y H:i', $r['d_completed']) : '').   /* 'Y-m-d H:i:s O' */
			$seperator. $r['d_markup'].
			$seperator. $r['d_hard_wrap'].
			$seperator. $r['d_keep_blanks'].
			$seperator. escape_csv( $n).
			$seperator. "\n";
		}
	}
	header('Content-type: text/csv; charset=utf-8');
	header('Content-disposition: attachment; filename="'.$listData['name'].'.csv"');
	print $s;
}

function escape_csv($v)
{
	return '"'.str_replace('"', '""', $v).'"';
}

function printICal($listData, $data)
{
	$mttToIcalPrio = array("1" => 5, "2" => 1);
	$s = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:PUBLISH\r\nCALSCALE:GREGORIAN\r\nPRODID:-//myTinyTodo//iCalendar Export v1.4//EN\r\n".
		"X-WR-CALNAME:". $listData['name']. "\r\nX-MTT-TIMEZONE:".Config::get('timezone')."\r\n";
	# to-do
	foreach($data as $r)
	{
		$a = array();
		$a[] = "BEGIN:VTODO";
		$a[] = "UID:". $r['uuid'];
		$a[] = "CREATED:". gmdate('Ymd\THis\Z', $r['d_created']);
		$a[] = "DTSTAMP:". gmdate('Ymd\THis\Z', $r['d_edited']);
		$a[] = "LAST-MODIFIED:". gmdate('Ymd\THis\Z', $r['d_edited']);
		$a[] = utf8chunks("SUMMARY:". $r['title']);
		if($r['duedate']) {
			$dda = explode('-', $r['duedate']);
			$a[] = "DUE;VALUE=DATE:".sprintf("%u%02u%02u", $dda[0], $dda[1], $dda[2]);
		}
		# Apple's iCal priorities: low-9, medium-5, high-1
		if($r['prio'] > 0 && isset($mttToIcalPrio[$r['prio']])) $a[] = "PRIORITY:". $mttToIcalPrio[$r['prio']];
		$a[] = "X-MTT-PRIORITY:". $r['prio'];

		$descr = array();
		if($r['tags'] != '') $descr[] = Lang::instance()->get('tags'). ": ". str_replace(',', ', ', $r['tags']);
		if($r['note'] != '') $descr[] = Lang::instance()->get('note'). ": ". $r['note'];
		if($descr) $a[] = utf8chunks("DESCRIPTION:". str_replace("\n", '\\n', implode("\n",$descr)));

		if($r['compl']) {
			$a[] = "STATUS:COMPLETED"; #used in Sunbird
			$a[] = "COMPLETED:". gmdate('Ymd\THis\Z', $r['d_completed']);
			#$a[] = "PERCENT-COMPLETE:100"; #used in Sunbird
		}
		if($r['tags'] != '') $a[] = utf8chunks("X-MTT-TAGS:". $r['tags']);
		$a[] = "END:VTODO\r\n";
		$s .= implode("\r\n", $a);
	}
	# events
	foreach($data as $r)
	{
		if(!$r['duedate'] || $r['compl']) continue;	# skip tasks completed and without duedate
		$a = array();
		$a[] = "BEGIN:VEVENT";
		$a[] = "UID:_". $r['uuid'];	# do not duplicate VTODO UID
		$a[] = "CREATED:". gmdate('Ymd\THis\Z', $r['d_created']);
		$a[] = "DTSTAMP:". gmdate('Ymd\THis\Z', $r['d_edited']);
		$a[] = "LAST-MODIFIED:". gmdate('Ymd\THis\Z', $r['d_edited']);
		$a[] = utf8chunks("SUMMARY:". $r['title']);
		if($r['prio'] > 0 && isset($mttToIcalPrio[$r['prio']])) $a[] = "PRIORITY:". $mttToIcalPrio[$r['prio']];
		$dda = explode('-', $r['duedate']);
		$a[] = "DTSTART;VALUE=DATE:".sprintf("%u%02u%02u", $dda[0], $dda[1], $dda[2]);
		$a[] = "DTEND;VALUE=DATE:".date('Ymd', mktime(1,1,1,$dda[1],$dda[2],$dda[0]) + 86400);
		$descr = array();
		if($r['tags'] != '') $descr[] = Lang::instance()->get('tags'). ": ". str_replace(',', ', ', $r['tags']);
		if($r['note'] != '') $descr[] = Lang::instance()->get('note'). ": ". $r['note'];
		if($descr) $a[] = utf8chunks("DESCRIPTION:". str_replace("\n", '\\n', implode("\n",$descr)));
		$a[] = "END:VEVENT\r\n";
		$s .= implode("\r\n", $a);
	}
	$s .= "END:VCALENDAR\r\n";
	header('Content-type: text/calendar; charset=utf-8');
	header('Content-disposition: attachment; filename="'.$listData['name'].'.ics"');
	print $s;
}

function utf8chunks($text, $chunklen=75, $delimiter="\r\n\t")
{
	if($text == '') return '';
	preg_match_all('/./u', $text, $m);
	$chars = $m[0];
	$a = array();
	$s = '';
	$max = count($chars);
	for($i=0; $i<$max; $i++)
	{
		$ch = $chars[$i];
		if(strlen($s) + strlen($ch) > $chunklen) { # line should be not more than $chunklen bytes
			$a[] = $s;
			$s = $ch;
		}
		else $s .= $ch;
	}
	if($s != '') $a[] = $s;
	return implode($delimiter, $a);
}

?>