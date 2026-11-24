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
require_once $includes . 'domain/class-fe-meta.php';
require_once $includes . 'domain/class-fe-zone-rules.php';
require_once $includes . 'domain/class-fe-event-resolver.php';
require_once $includes . 'data/class-fe-event-repo.php';
require_once $includes . 'data/class-fe-zone-rule-repo.php';
require_once $includes . 'data/class-fe-location-repo.php';
require_once $includes . 'domain/class-fe-rate-builder.php';
require_once $includes . 'domain/class-fe-cart-grouper.php';
require_once $includes . 'domain/class-fe-split-math.php';
require_once $includes . 'domain/class-fe-fee-dedupe.php';
require_once $includes . 'domain/class-fe-addon-rules.php';
require_once $includes . 'domain/class-fe-order-event.php';
require_once $includes . 'wc/class-fe-cart.php';
require_once $includes . 'wc/class-fe-order-stamper.php';
require_once $includes . 'wc/class-fe-addon-field.php';
require_once $includes . 'wc/class-fe-split-status.php';
require_once $includes . 'wc/class-fe-order-splitter.php';
require_once $includes . 'admin/class-fe-event-editor.php';
require_once $includes . 'wc/class-fe-emails.php';
require_once $includes . 'wc/class-fe-order-received.php';
require_once $includes . 'admin/class-fe-notices.php';
require_once $includes . 'admin/class-fe-zone-grid.php';
require_once $includes . 'domain/class-fe-event-setup.php';
require_once $includes . 'admin/class-fe-product-fields.php';
