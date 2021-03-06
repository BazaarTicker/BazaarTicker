<html>

<?php
// set max runtime
set_time_limit(3000);
ini_set( 'serialize_precision', -1 );

//-------GLOBAL VARIABLES--------
// old and new timestamps for comparing if minutes/hours have passed
$OLD_TIME_ST = json_decode(file_get_contents("old_timestamps.json"), true);

// table names for seconds, minutes, hours data, limit for number of rows
// MUST arrange in increasing time intervals
$TBL_NAMES = array("bsecnew", "bmin", "bhour");
$TBL_ROW_LIM = array(30, 80, 30);

//--------FUNCTIONS-------------
function updateDB($sqlHandler){
	/*
	updates server database with hypixel stuff.
	*/

	//accessing global variables (old timestamps)
	global $OLD_TIME_ST;
	global $TBL_NAMES;
	global $TBL_ROW_LIM;

	// getting current date, buy sell
	$timestr = gmdate("His", time());
	$buysell = getHypixData();

	// don't add if hypixel is not responding correctly
	if (strlen($buysell) <= 0){
		return;
	}
	
	// keeping rowcount in seconds 20
	for ($i=0; $i < count($TBL_NAMES); $i++){
		while (getNumRows($sqlHandler, $TBL_NAMES[$i]) > $TBL_ROW_LIM[$i]){
			$deltime = getRow($sqlHandler, $TBL_NAMES[$i], 0)[0];
			delRow($sqlHandler, $TBL_NAMES[$i], $deltime);
		}
	}

	
	// adding data to seconds database
	addDataToDB($sqlHandler, "bsecnew", $timestr, $buysell);
	
	// collecting averages into minutes and hour table by checking timestamps
	for ($i = 0; $i < count($TBL_NAMES) - 1; $i++){
		$tbl = $TBL_NAMES[$i];
		$rowlen = getNumRows($sqlHandler, $tbl);

		if ($rowlen > 0){
			$newrow = getRow($sqlHandler, $tbl, $rowlen-1);

			if ($rowlen == 1 || $OLD_TIME_ST[$i] == 0){
				$OLD_TIME_ST[$i] = getRow($sqlHandler, $tbl, 0)[0];
			}
			$oldrow = getRowTS($sqlHandler, $tbl, $OLD_TIME_ST[$i]);
			
			// comparing timestamps for minutely hourly
			// use formula: timestamp index to compare = length of timestamp - 2*(i+1) - 1
			if (checkInterval($oldrow[0], $newrow[0], strlen($oldrow[0]) - 2*($i+1) - 1)){
				$avg = getAverage($sqlHandler, $tbl, $OLD_TIME_ST[$i], $newrow[0]);
				$processed = json_encode($avg);
				addDataToDB($sqlHandler, $TBL_NAMES[$i + 1], $OLD_TIME_ST[$i], $processed);
	
				$OLD_TIME_ST[$i] = $newrow[0];
			}
		}
		
	}

}

function addDataToDB($sqlHandler, $tableName, $time, $data){
	/*
	adds one row to end of table with <tableName>, w/ timestamp, sell, buy. Returns true if query successful.	
	column names MUST be called 'timestamp', 'data'. Assume all parameters are strings.
	*/
	
	$q = "INSERT INTO `" . $tableName . "` (`timestamp`, `data`) VALUES ('". $time . "', '". $data ."');";
	
	if ($result = $sqlHandler -> query($q)){
		return true;
	}
	return false;
	
}

function getHypixData(){
	/*
	gets buy, sell data from hypixel bazaar, returns them in an array containing string of buy and sell data in JSON format.
	*/
	
	$url = 'https://api.hypixel.net/skyblock/bazaar';
	$data = json_decode(file_get_contents($url), true);

	if ($data == null){
		return "";
	}

	$products = $data['products'];
	$buy_arr = [];
	$sell_arr = [];

	foreach ($products as $item){
		
		$buy_arr[$item["product_id"]] = strval(round($item['quick_status']['buyPrice'], 2));
		$sell_arr[$item["product_id"]] = strval(round($item['quick_status']['sellPrice'], 2));

	}
	return json_encode(array($buy_arr, $sell_arr));
}

function getAverage($sqlHandler, $tableName, $start, $end){
	/*
	gets average of buy and sell values between 2 timestamp strings (inclusive). Returns an array containing start timestamp as string, 
	and buy and sell. --> [buy, sell]
	*/
	
	$sum = array();
	$count = 0;
	
	$foundStart = false;
	
	for ($i=0; $i<getNumRows($sqlHandler, $tableName); $i++){
		
		$row = getRow($sqlHandler, $tableName, $i);
		if ($row[0] == $start || $foundStart || $row[0]  == $end){
			
			$curr_buy = json_decode($row[1], true)[0];
			$curr_sell = json_decode($row[1], true)[1];
			
			$count++;
		}
		
		
		if ($row[0] == $start){
			array_push($sum, $curr_buy, $curr_sell);
			$foundStart = true;
		}
		
		else if ($row[0]  == $end){
			$sum[0] = sumArray($sum[0], $curr_buy);
			$sum[1] = sumArray($sum[1], $curr_sell);
			
			break;
		}
		
		else if ($foundStart){
			$sum[0] = sumArray($sum[0], $curr_buy);
			$sum[1] = sumArray($sum[1], $curr_sell);
		}
	}
	
	return [scaleArray($sum[0], 1/$count), scaleArray($sum[1], 1/$count)];
}

function getNumRows($sqlHandler, $tableName){
	/*
	get the length of table <tableName> in db assoc. with <sqlHandler>,
	returns length of table, if query successful, -1 if not.
	
	don't need to add ` symbols to tableName.
	*/
	
	$q = "SELECT COUNT(*) FROM `" . $tableName . "`";
	
	if ($result = $sqlHandler -> query($q)){
		$arr = $result -> fetch_row();
		return $arr[0];
	}
		
	return -1;
}

function delRow($sqlHandler, $tableName, $timestamp){
	/*
	deletes row with <timestamp>. Returns true on successful query
	*/
	
	$q = "DELETE FROM `" . $tableName . "` WHERE timestamp = " . strval($timestamp);
	
	echo $q;
	if ($result = $sqlHandler -> query($q)){
		echo "deleted";
		return true;
	}
	
	echo "didn't work";
	return false;
}

function checkInterval($old, $new, $pos){
	/*
	check if 2 timestamps are > 1 minute and returns true.
	*/
	$strol = $old;
	$strnew = $new;
	
	if ($strol[$pos] != $strnew[$pos]){
		//minute changed
		return true;
	}
	
	return false;
}

function getRow($sqlHandler, $tableName, $index){
	/*
	gets row at <index> from <tableName> at the <sqlHandler> db.
	
	returns one row from the table. each element = data assoc. with column.
	*/
	$q = "SELECT * FROM `" . $tableName . "` LIMIT " . $index . ",1";
	$res = $sqlHandler -> query($q);
	return $res -> fetch_row();
}

function sumArray($a, $b){
	/*
	Sums arrays a and b element-wise, returns an array the same length as a or b if successful. Returns -1 if not.
	*/
	
	if (count($a) != count($b)){
		return -1;
	}
	
	$c = array();
	
	foreach ($a as $item => $value){
		$c[$item] = $a[$item] + $b[$item];
	}
	
	return $c;
	
}

function getRowTS($sqlHandler, $tableName, $timestamp){
	/*
	fetches a row from table in database by timestamp string. Returns row if successful. -1 if not.
	*/

	$q = "SELECT * FROM `" . $tableName . "` WHERE `timestamp` = " . "$timestamp";
	if ($res = $sqlHandler -> query($q)){
		return $res -> fetch_row();
	}
	return -1;
}

function scaleArray($a, $scalar){
	/*
	multiplies all elements of array by the scalar. Returns a new array
	*/
	
	$c = array();
	
	foreach ($a as $item => $value){
		$c[$item] = round($a[$item] * $scalar, 2);
	}
	
	return $c;
}


//-------CODE--------------

// connecting to mysql server
$mysqli = new mysqli('localhost:3306', 'root', 'root', 'test');

if ($mysqli->connect_error){
	echo "server died";
}
else{
	echo 'connected succ';
}


$i = 1;
while($i < 5){
	updateDB($mysqli);
	$i++;
	sleep(5);
}

// write new timestamps to json file after loop
file_put_contents("old_timestamps.json", json_encode($OLD_TIME_ST));
$mysqli -> close();

?>
</html>