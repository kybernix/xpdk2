<?php
// xpdk2_config
// Pukiwiki Decentralization Kit - Configuration File

// --------------------
// * COMMON
// ident string
define( 'XPDK2_IDENT',			'X-XA-AYY-XPDK2' );
// HTTP user-agent
define( 'XPDK2_USERAGENT',		'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; Trident/6.0)' );
// connection timeout
define( 'XPDK2_SYNCTIMEOUT',	60 );
// gzip compression level
define( 'XPDK2_GZIP_LEVEL',		9 );
// master(s)
$g_xpdk2_masters = array
(	'10.1.0.1'
,	'192.1.0.1'
,	'sync.xenowire.net'
);

// --------------------
// * MASTER
// master sync-file path: (must be ended with "/")
define( 'XPDK2_DATAPATH',	'./sync/' );
// master sync-file URL base: (must be ended with "/")
define( 'XPDK2_DATAURLBASE',	'http://www.sync.xenowire.net/xpdk2ay/' );

// --------------------
// * SLAVE
// slave(s)
$g_xpdk2_slaves = array
(	'http://www.slv1.sync.xenowire.net/index.php'
,	'http://www.slv2.sync.xenowire.net/index.php'
,	'http://backup06.sync.xenowire.net/index.php'
);

?>