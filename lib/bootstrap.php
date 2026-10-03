<?php
/** Common bootstrap for public pages, admin, and the worker. */

if ( function_exists( 'mb_internal_encoding' ) ) { mb_internal_encoding( 'UTF-8' ); }

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Price.php';
require_once __DIR__ . '/NodeProbe.php';
require_once __DIR__ . '/Xmr.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/catalog.php';

Config::load();
// Web requests need a real cookie_secret (it signs the admin cookie); fail up front, not mid-page.
if ( PHP_SAPI !== 'cli' ) { csrf_secret(); }

$GLOBALS['store'] = new Store( (string) Config::get( 'db_path', dirname( __DIR__ ) . '/data/store.sqlite' ) );
$GLOBALS['xmr']   = new Xmr();
$GLOBALS['price'] = new Price( $GLOBALS['store'] );
$GLOBALS['nodeprobe'] = new NodeProbe( $GLOBALS['store'] );

function store()     { return $GLOBALS['store']; }
function xmr()       { return $GLOBALS['xmr']; }
function price()     { return $GLOBALS['price']; }
function nodeprobe() { return $GLOBALS['nodeprobe']; }
