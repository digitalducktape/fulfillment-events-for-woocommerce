<?php
/**
 * Unit-test bootstrap. Domain and pure files carry no WordPress dependency;
 * FE_TESTING lets them load outside WordPress without the ABSPATH guard
 * exiting.
 */

define( 'FE_TESTING', true );
define( 'FE_DB_VERSION', 1 );

require_once __DIR__ . '/wc-stubs.php';

$includes = __DIR__ . '/../includes/';
require_once $includes . 'class-fe-activator.php';
