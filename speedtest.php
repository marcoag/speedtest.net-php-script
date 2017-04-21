#!/usr/bin/php5
<?php
/*
 *    Speedtest.net linux php terminal client, based on works by Alex and Janhouse.
 *     
 *    This script is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This script is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this script.  If not, see <http://www.gnu.org/licenses/>.
 */
 
header("content-type: text/plain");
//Url to retrieve the servers list
define( 'SERVER_LIST_URL',"http://www.speedtest.net/speedtest-servers.php");
//How many rounds of latency checks are down per server
define( 'LATENCY_ROUNDS', 5 );
//Timeout for latency requests
define( 'TIMEOUT_LATENCY', 5 );
//Max distance in km used by servers filter when you don't specify the location
define( 'DEFAULT_MAXDISTANCE', 100 );
//Default interface to measure
define( 'DEFAULT_INTERFACE', "eth0" );
//Default number of rounds to check before average is calculated
define( 'DEFAULT_MAXROUNDS', 1 );
//Temporal data storage where files to upload are created and deleted
$tmpdir="/tmp/";
$datadir="data/";

$xmlservermap=array(
    'url'=>'url',
    'lat'=>'lat',
    'lon'=>'lon',
    'name'=>'name',
    'countrycode'=>'(countrycode|cc)'
);

$randoms=rand(100000000000, 9999999999999);
$time=time();
$day=date("d-m-Y");

$do_size[1]=450;
$do_size[2]=500;
$do_size[3]=750;
$do_size[4]=1000;
$do_size[5]=1500;
$do_size[6]=2000;
$do_size[7]=2500;
$do_size[8]=3000;

$up_size[1]=300;
$up_size[2]=600;
$up_size[3]=1000;
$up_size[4]=2000;
$up_size[5]=6000;
$up_size[6]=10000;
$up_size[7]=20000;
$up_size[8]=50000;



$verbose=0;

function getDistanceInKm ( $p1, $p2 ) 
{
	$iLat = 0;
	$iLon = 1;

	$lon_1 = $p1[ $iLon ];
	$lon_2 = $p2[ $iLon ];
	$lat_1 = $p1[ $iLat ];
	$lat_2 = $p2[ $iLat ];


	$earth_radius = 6367; //in km
	$delta_lat    = $lat_2 - $lat_1;
	$delta_lon    = $lon_2 - $lon_1;
	$alpha        = $delta_lat / 2;
	$beta         = $delta_lon / 2;
	$a            = sin( deg2rad( $alpha ) ) * sin( deg2rad( $alpha ) ) + cos( deg2rad( $lat_1 ) ) * cos( deg2rad( $lat_2 ) ) * sin( deg2rad( $beta ) ) * sin( deg2rad( $beta ) );
	$c            = asin( min( 1, sqrt( $a ) ) );
	$distance     = 2 * $earth_radius * $c;
	$distance     = round( $distance, 4 );

	return $distance;
}

function getLatency ( $server ) 
{
	global
	$verbose,
	$randoms,
	$tmpdir;
	$ret            = array();
	$rounds         = array();
	$globalDuration = 0;

	if ($verbose)
		echo "\n\033[92mGetting latency for " . $server[ 'name' ] . "(" . $server[ 'countrycode' ] . ") from " . $server[ 'url' ] . "\033[0m\n";

	for ( $i = 0; $i <= LATENCY_ROUNDS; $i++ ) {
		$file = $tmpdir . $randoms . 'latency.txt';
		$fp   = fopen( $file, 'w+' );
		$ch   = curl_init( $server[ 'url' ] . "/speedtest/latency.txt?x=" . $randoms );

		curl_setopt( $ch, CURLOPT_HEADER, TRUE );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		curl_setopt( $ch, CURLOPT_TIMEOUT, TIMEOUT_LATENCY );
		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE );
		curl_setopt( $ch, CURLOPT_FORBID_REUSE, 1 );
		curl_setopt( $ch, CURLOPT_FRESH_CONNECT, 1 );

		if ( $verbose ) {
			echo "\tRound " . $i . ":";
		}

		$starttime = microtime( TRUE );
		$response  = curl_exec( $ch );
		$endtime   = microtime( TRUE );
		$duration  = $endtime - $starttime;

		if ( $response === FALSE ) 
		{
			if ( $verbose ) 
			{
				echo "\nRequest failed " . curl_error( $ch ) . "\n";
			}
			curl_close( $ch );
			fclose( $fp );

			return FALSE;
		}

		array_push( $rounds, round( $duration, 2 ) );

		if ( $verbose ) 
		{
			echo "\t" . round( $duration, 2 ) . "s\n";
		}


		$globalDuration += $duration;

		curl_close( $ch );
		fclose( $fp );
		unlink( $file );
	}
	$ret[ 'rounds' ] = $rounds;
	$ret[ 'avg' ]    = round( $globalDuration / LATENCY_ROUNDS, 2 );

	if ( $verbose ) 
	{
		echo "\tAvarage:\t\033[91m" . $ret[ 'avg' ] . "s\033[0m\n";
	}

	return $ret;
}

//Finds the best server among the list
function findBestServer ( &$servers ) 
{
	global
	$verbose,
	$randoms;
	$ret         = array();
	$bestLatency = $randoms;
	foreach ( $servers as &$server ) 
	{
		$server[ 'latency' ] = getLatency( $server );
		if ( $server[ 'latency' ] !== FALSE && $server[ 'latency' ][ 'avg' ] < $bestLatency ) 
		{
			$ret         =& $server;
			$bestLatency = $server[ 'latency' ][ 'avg' ];
		}
	}
	if($verbose)
		echo "\033[91m\nBest server founded is " . $ret[ 'name' ] . "(" . $ret[ 'countrycode' ] . ') at url ' . $ret[ 'url' ] . " with " . $ret[ 'distance' ] . "km of distance\033[0m\n";

	return $ret;
}

//Gets the information of the current connection
function getConnectionInfo () 
{
	global
	$tmpdir,
	$randoms,
	$verbose;

	$ret = array();

	$file = $tmpdir . $randoms . 'connectionInfo.txt';

	$cmd = "curl \"http://www.speedtest.net/speedtest-config.php?x=" . $randoms . "\" > " . $file . " 2>/dev/null";
	if ( $verbose ) 
		echo "\033[92mGetting connection info\033[0m\n";
		
	shell_exec( $cmd );

	$cmd = 'cat ' . $file . '|grep -i "<client"';
	$out = shell_exec( $cmd );

	unlink( $file );

	if ( preg_match( '/ip="(?P<ip>[^"]*)".*lat="(?P<lat>[^"]*).*lon="(?P<lon>[^"]*).*isp="(?P<isp>[^"]*).*/', $out, $m ) ) 
	{
		$ret = array(
			'ip'     => $m[ 'ip' ],
			'isp'    => $m[ 'isp' ],
			'coords' => array( $m[ 'lat' ], $m[ 'lon' ] )
		);
	} 
	else 
	{
		return FALSE;
	}

	if($verbose)
		echo "\tISP:\t" . $ret[ 'isp' ] . "\n\tIP:\t" . $ret[ 'ip' ] . "\n\tLat:\t" . $ret[ 'coords' ][ '0' ] . "\n\tLon:\t" . $ret[ 'coords' ][ 1 ] . "\n\n";

	return $ret;

}

function downloadServerList() 
{
	global
	$verbose;
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, SERVER_LIST_URL);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
	
	if( $verbose )
		echo 'Downloading server list from http://www.speedtest.net/speedtest-servers.php' . "\n";
	
	$server_list = curl_exec($ch);
	
	return $server_list;
}

function getServer () 
{
	global
	$max_distance,
	$conInfo,
	$xmlservermap,
	$verbose;

	$ret = array();
	
	$server_list = downloadServerList();
	if ( $server_list === FALSE ) 
	{
		return FALSE;
	}
	
	if ( $verbose )
		echo "\n\033[92mFiltering server in " . $max_distance . "km\033[0m\n";
		
	//iterate the xml line by line
	foreach(preg_split("/((\r?\n)|(\r\n?))/", $server_list) as $line)
	{
		$regex = '/.* ' . $xmlservermap[ 'url' ] . '="(?P<url>https?:\/\/[^\/]*)\/.*' . $xmlservermap[ 'lat' ] . '="(?P<lat>[^"]*).*' . $xmlservermap[ 'lon' ] . '="(?P<lon>[^"]*).*' . $xmlservermap[ 'name' ] . '="(?P<name>[^"]*).*' . $xmlservermap[ 'countrycode' ] . '="(?P<countrycode>[^"]*)".*/';
		if ( preg_match( $regex, $line, $m ) ) 
		{
			$server_distance = getDistanceInKm( $conInfo[ 'coords' ], array( $m[ 'lat' ], $m[ 'lon' ] ) );
			
			if ( $server_distance <= $max_distance)
			{
				$s = array(
					'name'        => $m[ 'name' ],
					'url'         => $m[ 'url' ],
					'countrycode' => $m[ 'countrycode' ],
					'coords'      => array( $m[ 'lat' ], $m[ 'lon' ] ),
					'distance'    => $server_distance
				);
				array_push( $ret, $s );
			}
		}
	}
	
	
		
// 	$ret = array_filter( $ret, "filterByDistance" );

	return $ret;
}

function filterByDistance ( $server ) 
{
	global
	$max_distance;

	return $server[ 'distance' ] <= $max_distance;
}

function latency($round)
{
    global 
    $verbose, 
    $server, 
    $downloads, 
    $do_server, 
    $server, 
    $iface, 
    $randoms, 
    $do_size,
    $globallatency,
    $maxrounds;

    $file=$downloads."latency.txt";
    $fp = fopen ($file, 'w+');
    $ch = curl_init($do_server[$server]."/speedtest/latency.txt?x=".$randoms);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 50);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $starttime=microtime(true);
    $response=curl_exec($ch);
    $endtme=microtime(true);

    $duration=$endtme-$starttime;

    curl_close($ch);
    fclose($fp);
    unlink($file);

	if ($verbose)
		print round($duration, 2)."sec.";

    $globallatency+=$duration;
    if ($round > 1)
    {
        latency(--$round);
    } 
    else 
    {
        print "Average Latency:".round($globallatency/$maxrounds, 2)."sec.\n";
    }
}

function download($size,$round)
{
    global 
    $verbose, 
    $server, 
    $downloads, 
    $do_server, 
    $server, 
    $iface, 
    $randoms, 
    $do_size,
    $globaldownloadspeed,
    $maxrounds;
	
	if ($verbose)
		print ".";
    
    $ln = $do_server[$server]."/speedtest/random".$do_size[$size]."x".$do_size[$size].".jpg?x=".$randoms."-".$size;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$ln);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 50);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $starttime=microtime(true);
	$sakuma_rx=shell_exec("cat /sys/class/net/".$iface."/statistics/rx_bytes");
    $response=curl_exec($ch);
    $beigu_rx=shell_exec("cat /sys/class/net/".$iface."/statistics/rx_bytes");
    $endtme=microtime(true);

    if ($response === false)
    {
       print "Request failed:".curl_error($ch);
    }
    
    curl_close($ch);

    $duration=$endtme-$starttime;

    if($duration<4 && $size!=8) 
    {
        download(++$size,$round);
    }
    else
    {
        $sakuma_rx=trim($sakuma_rx);
        $beigu_rx=trim($beigu_rx);
        $rx=$beigu_rx-$sakuma_rx;
        $rx_speed=((($rx*8)/1000)/1000)/$duration;
        if($duration<4 && $verbose)
        {
          print "Duration is ".round($duration, 2)."sec - this may introduce errors.\n";
        }
		if ($verbose)
			print round($do_size[$size]/1000,2)."Mb at ".round($rx_speed, 2)."Mb/s";
        $globaldownloadspeed+=$rx_speed;
        if ($round > 1)
        {
           download($size,--$round);
        } 
        else 
        {
			if($verbose)
				print "Average download: ";
			print round($globaldownloadspeed/$maxrounds, 2)." Mbits/sec\n";
		}
    }
}

function upload($size,$round)
{
    global 
    $verbose, 
    $server, 
    $do_server, 
    $server, 
    $iface, 
    $randoms,
    $globaluploadspeed,
    $maxrounds, 
    $up_size, 
    $tmpdir;
    
    shell_exec( "dd if=/dev/urandom of=".$tmpdir."upload_".$size." bs=1K count=".$up_size[$size] );

    $file=$tmpdir."upload_".$size;

	if ($verbose)
		print ".";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
    curl_setopt($ch, CURLOPT_URL, $do_server[$server]."/speedtest/upload.php?x=0.".$randoms);
    curl_setopt($ch, CURLOPT_POST, true);
    $args['file'] = new CurlFile($file, '');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $args);

    $starttime=microtime(true);
	$sakuma_tx=shell_exec("cat /sys/class/net/".$iface."/statistics/tx_bytes");
    $response = curl_exec($ch);
	$beigu_tx=shell_exec("cat /sys/class/net/".$iface."/statistics/tx_bytes");
    $endtme=microtime(true);
    

    if ($response === false)
    {
       print "Request failed:".curl_error($ch);
    }
    
    $aiznem=substr($response, 5);
    $kopa=filesize($file)+$aiznem;

    $duration=$endtme-$starttime;

    if($duration<4 && $size!=8)
    {
		if ($verbose)
			print round(filesize($file)/1000000,2)."Mb is too small: ".round($duration, 2)."sec\n";
        shell_exec( "rm ".$tmpdir."upload_".$size );
        upload(++$size,$round);
    }
    else
    {
        $sakuma_tx=trim($sakuma_tx);
        $beigu_tx=trim($beigu_tx);
        $tx=$beigu_tx-$sakuma_tx;
        $tx_speed=((($tx*8)/1000)/1000)/$duration;
        if($duration<4 && $verbose)
        {
          print "Duration is ".round($duration, 2)."sec - this may introduce errors.\n";
        }
        if ($verbose)
			print round(filesize($file)/1000000,2)."Mb at ".round($tx_speed, 2)."Mb/s";
		
		shell_exec( "rm ".$tmpdir."upload_".$size );
        $globaluploadspeed+=$tx_speed;
        
        if ($round > 1)
        {
           upload($size,--$round);
        } 
        else 
        {
			if ($verbose)
				print "Average upload: ";
			print round($globaluploadspeed/$maxrounds, 2)." Mbits/sec\n";
		}
    }
}

function write_to_file($data, $updown)
{
    global 
    $day, 
    $time, 
    $datadir, 
    $maxrounds,
    $iface;
    
    $fp = fopen($datadir."data_".$day.".txt", "a");
    fwrite($fp, $time."|".$updown."|".$iface."|".$data."\n");
    fclose($fp);
}

$longopts  = array(
    "latency",
    "upload",
    "download",
    "distance::",
    "verbose",
    "iface::",
    "server::",
    "maxrounds::",
    "help"
);


//get the options
$options = getopt("h?", $longopts);


if(isset($options['?'])||isset($options['h'])||isset($options['help'])){
    echo "Usage:\n".
     "\t--latency\t\t\tTest latency to best server.\n".
     "\t--upload\t\t\tTest upload from best server.\n".
     "\t--download\t\t\tTest download from best server.\n".
     "\t--iface=[]\tInterface to use for the tests.\n".
	 "\t--distance=[km]\tTell max distance for servers selection.\n\t\t\t\t\tIf not specified it find the best sever in ".$max_distance."km.\n".
	 "\t--verbose\t\t\tExecute script with verbose output.\n".
	 "\t--server=[url]\t\t\tUse this specific server url othewise find best.\n".
	 "\t--maxrounds=[]\t\t\tNumber of rounds to check before average is calculated.\n".
	 "\t--help|-h|-?:\t\t\tThis help.\n";

    exit(1);
}


$verbose = isset($options['verbose']);
$test_latency = isset($options['latency']);
$test_download = isset($options['download']);
$test_upload = isset($options['upload']);
(isset($options['iface']) ? $iface=$options['iface'] : $iface = DEFAULT_INTERFACE);
(isset($options['distance']) ? $max_distance=$options['distance'] : $max_distance = DEFAULT_MAXDISTANCE);
(isset($options['maxrounds']) ? $maxrounds=$options['maxrounds'] : $maxrounds = DEFAULT_MAXROUNDS);

//check wheter server is selected manually or have to find best
if(isset($options['server']))
	$do_server[0]=$options['server'];
else
{
	$conInfo = getConnectionInfo();
	$servers = getServer();
	$bestServer = findBestServer( $servers );

	if	($verbose)
		print_r($bestServer);

	$do_server[0]=$bestServer['url'];
}

if ($test_latency)
{
	foreach ($do_server as $server => $serverurl)
	{
	$globallatency=0;
	if($verbose)
		print "* Testing latency for $server...";
	latency($maxrounds);
	}
}

if ($test_download)
{
	foreach ($do_server as $server => $serverurl)
	{
	$globaldownloadspeed=0;
	if($verbose)
		print "* Testing download speed for $server...";
	download(8,$maxrounds);
	}
}

if ($test_upload)
{
	foreach ($do_server as $server => $serverurl)
	{
	$globaluploadspeed=0;
	if($verbose)
		print "* Testing upload speed $server...";
	upload(8,$maxrounds);
	}
}

?>
