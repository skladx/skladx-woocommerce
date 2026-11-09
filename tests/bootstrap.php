<?php
/**
 * Unit test bootstrap: the tested classes are pure PHP, so WordPress is not
 * loaded — only ABSPATH is defined so the files' direct-access guard passes.
 *
 * @package SkladX\WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
