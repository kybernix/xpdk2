<?php
// xpdk2
// Pukiwiki Decentralization Kit
// 
// http://mog.xenowire.net/?xpdk
// http://github.com/kybernix/xpdk2
// 
// --------------------
// * INSTALLATION
// 
// n. side      instruction
// 1. BOTH:     edit xpdk2_config.php
// 2. BOTH:     copy xpdk2.php and xpdk2_config.php to PukiWiki/lib/
// 3. MASTER:   create and chmod(read write execute) XPDK2_DATAPATH directory
// 4. BOTH:     include xpdk2.php on the last of 'Include subroutines' in PukiWiki/lib/pukiwiki.php
//    [sample]
//    require_once( LIB_DIR . 'xpdk2.php' );
// 5. MASTER:   xpdk2_sync(TRUE); before page_write() in PukiWiki/plugin/edit.inc.php
//    [sample]
//    if( xpdk2_sync( TRUE ) )
//        page_write( ... );
// 6. SLAVE:    disable digest check function in plugin_edit_write() in PukiWiki/plugin/edit.inc.php
// 

// --------------------
// * COMMON
require_once( LIB_DIR . 'xpdk2_config.php' );
define( 'XPDK2_QUERY',				'xpdk' );
define( 'XPDK2_QUERY_FILE',			'xpdkfn' );
//define( 'XPDK2_QUERY_MODE',			'xpdkmode' );	// no longer used.
//define( 'XPDK2_QUERY_MODE_ACTIVE',	'active' );		// xpdk1 mode // removed.
define( 'XPDK2_RESPONSE_HEADER',	'X-xpdk2-res: ' );
define( 'XPDK2_RESPONSE_FAILED',	'NG' );
define( 'XPDK2_RESPONSE_SUCCEEDED',	'OK' );
//define( 'XPDK2_DEBUG',				TRUE );
define( 'PKWK_QUERY_NOTIMESTAMP',	'notimestamp' );

// call dispatcher in slave-mode
xpdk2_sync();

// dispatcher
function xpdk2_sync( $master = FALSE )
{
	if( $master )
	{
		return xpdk2_sendToSlaves();
	}
	else
	{
		if( $_GET[XPDK2_QUERY] == sha1( XPDK2_IDENT ) )
		{
			xpdk2_recvFromMaster();	// session will be terminated in this function.
			die('fatal: xpdk2 - undefined opetaion.');
		}
		
		return TRUE;
	}
}

// utils
function xpdk2_areYouMaster()
{
	global $g_xpdk2_masters;
	return
	(	in_array( $_SERVER['REMOTE_ADDR'], $g_xpdk2_masters )
	||	in_array( $_SERVER['REMOTE_NAME'], $g_xpdk2_masters )
	);
}
function xpdk2_amIMaster()
{
	global $g_xpdk2_masters;
	return
	(	in_array( $_SERVER['SERVER_ADDR'], $g_xpdk2_masters )
	||	in_array( $_SERVER['SERVER_NAME'], $g_xpdk2_masters )
	||	in_array( $_SERVER['HTTP_HOST'], $g_xpdk2_masters )
	);
}

function xpdk2_generateSyncFilename()
{
	return strtolower( bin2hex( $_POST['page'] ) );
}
function xpdk2_getPageFromSyncFilename( $fn )
{
	return pack('H*', (string)$fn );
}

// --------------------
// * MASTER
// processor
function xpdk2_sendToSlaves()
{
	// generate sync-filename
	$fn = xpdk2_generateSyncFilename();

	// write sync file
	if( !xpdk2_createSyncFile( $fn ) )
		return FALSE;

	// sync order
	$res = xpdk2_requestSyncAll( $fn );
	
	// delete sync file
	if( !xpdk2_removeSyncFile( $fn ) )
		return FALSE;
	
	// done.
	return $res;
}

function xpdk2_createSyncFile( $fn )
{
	// write
	$f = fopen( XPDK2_DATAPATH . $fn, 'wb' );
	if( !is_resource( $f ) )
		return FALSE;
	$ret = fwrite( $f, gzcompress( $_POST['msg'], XPDK2_GZIP_LEVEL ) );
	fclose( $f );

	return $ret;
}
function xpdk2_removeSyncFile( $fn )
{
	return unlink( XPDK2_DATAPATH . $fn );
}
function xpdk2_requestSyncAll( $fn )
{
	global $g_xpdk2_slaves;
	foreach( $g_xpdk2_slaves as &$s )
	{
		if( defined( 'XPDK2_DEBUG' ) )
		{
			echo "* {$s}\n";
		}
		if( ($res = xpdk2_requestSync( $s, $fn )) === FALSE )
			return FALSE;
		
		if( defined( 'XPDK2_DEBUG' ) )
		{
			var_dump( $res );
		}
		if( strpos( $res, XPDK2_RESPONSE_HEADER . XPDK2_RESPONSE_SUCCEEDED ) === FALSE )
			return FALSE;

		unset( $res );
	}
	return TRUE;
}
function xpdk2_requestSync( &$slaveUrl, $fn )
{
	// HEAD request
	if( ($c = curl_init()) === FALSE )
		return FALSE;
	curl_setopt( $c, CURLOPT_RETURNTRANSFER, TRUE );
	curl_setopt( $c, CURLOPT_CONNECTTIMEOUT, XPDK2_SYNCTIMEOUT );
	curl_setopt( $c, CURLOPT_USERAGENT, XPDK2_USERAGENT );
	curl_setopt( $c, CURLOPT_FAILONERROR, TRUE ); // fail on: HTTP response code >= 400
	curl_setopt( $c, CURLOPT_NOBODY, TRUE ); // use "HEAD" request
	curl_setopt( $c, CURLOPT_HEADER, TRUE ); // store header
	if( defined( 'XPDK2_DEBUG' ) )
		curl_setopt( $c, CURLINFO_HEADER_OUT, TRUE ); // store request header
	curl_setopt( $c, CURLOPT_URL, "{$slaveUrl}?" . XPDK2_QUERY . '=' . sha1( XPDK2_IDENT ) . '&' . XPDK2_QUERY_FILE . "={$fn}&" . PKWK_QUERY_NOTIMESTAMP . '=' . $_POST[PKWK_QUERY_NOTIMESTAMP] );
	$res = curl_exec( $c );
	if( $res === FALSE )
	{
		$res .= curl_error( $c ) . "\n" . implode( "\n", curl_getinfo( $c ) );
	}
	curl_close( $c );
	
	return $res;
}

// --------------------
// * SLAVE
// processor
function xpdk2_recvFromMaster()
{
	if( defined( 'XPDK2_DEBUG' ) )
		touch( '_xd_1' );
	// peer check
	if( !xpdk2_areYouMaster() )
		return xpdk2_sendResult( XPDK2_RESPONSE_FAILED );

	if( defined( 'XPDK2_DEBUG' ) )
		touch( '_xd_2' );
	// get sync-filename
	$fn = $_GET[XPDK2_QUERY_FILE];
	// safe-guard
	if( !preg_match( '/^[0-9a-z]+$/i', $fn ) )
		return xpdk2_sendResult( XPDK2_RESPONSE_FAILED );
	
	if( defined( 'XPDK2_DEBUG' ) )
		touch( '_xd_3' );
	// read sync file
	if( ( $d = xpdk2_readSyncFile( $fn ) ) === FALSE )
		return xpdk2_sendResult( XPDK2_RESPONSE_FAILED );
	
	if( defined( 'XPDK2_DEBUG' ) )
		touch( '_xd_4' );
	// apply to local
	if( !xpdk2_applyToLocal( $d, $fn, $_GET[PKWK_QUERY_NOTIMESTAMP] ) )
		return xpdk2_sendResult( XPDK2_RESPONSE_FAILED );
	unset( $d );
		
	if( defined( 'XPDK2_DEBUG' ) )
		touch( '_xd_5' );
	// done.	
	return xpdk2_sendResult( XPDK2_RESPONSE_SUCCEEDED );
}

function xpdk2_readSyncFile( $fn )
{
	// GET request
	if( ($c = curl_init()) === FALSE )
		return FALSE;
	curl_setopt( $c, CURLOPT_RETURNTRANSFER, TRUE );
	curl_setopt( $c, CURLOPT_CONNECTTIMEOUT, XPDK2_SYNCTIMEOUT );
	curl_setopt( $c, CURLOPT_USERAGENT, XPDK2_USERAGENT );
	curl_setopt( $c, CURLOPT_FAILONERROR, TRUE ); // fail if HTTP response code is >= 400
	curl_setopt( $c, CURLOPT_HEADER, FALSE );
	curl_setopt( $c, CURLOPT_URL, XPDK2_DATAURLBASE . $fn );
	$res = curl_exec( $c );
	curl_close( $c );

	return $res;
}

function xpdk2_applyToLocal( &$data, $fn, $notimestamp )
{
	$msg = gzuncompress( $data );
	$page = xpdk2_getPageFromSyncFilename( $fn );
	
	if( defined( 'XPDK2_DEBUG' ) )
	{
		$f = @fopen( '_xpdk2_debug.txt', 'wb' );
		fwrite( $f, "page: {$page}\nmsg: {$msg}" );
		fclose( $f );
	}

	// write local
	page_write( $page, $msg, $notimestamp );
	unset( $msg );

	return TRUE;
}

function xpdk2_sendResult( $res )
{
	header( XPDK2_RESPONSE_HEADER . $res );
	exit;
}
?>