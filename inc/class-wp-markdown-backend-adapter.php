<?php
/** Compatibility bridge that loads the installed backend adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/sqlite/class-wp-markdown-sqlite-operations.php';
