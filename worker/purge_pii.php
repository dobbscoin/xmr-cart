<?php
/**
 * Data-minimisation purge. Scrubs buyer PII (name/email/address/phone + the legacy
 * `contact` blob) from orders once they no longer need it, keeping the order row
 * for accounting. Shrinks the blast radius of any future DB compromise to a rolling
 * recent window instead of "every buyer since launch".
 *
 * Rules:
 *   - paid & shipped  : purge PII SHIP_KEEP_DAYS after shipped_at
 *   - expired/cancelled: purge PII DEAD_KEEP_DAYS after created_at (never shipped)
 *   - expired/cancelled with NOTHING received: deleted outright at the same point
 *     (empty dead orders are clutter; one that received coin is kept, a refund is owed)
 * Financial columns (amount, txids, height, status) are untouched.
 *
 * Idempotent. Safe to run daily from cron. Prints a one-line summary.
 */
define('SHIP_KEEP_DAYS', 30);
define('DEAD_KEEP_DAYS',  7);

chdir(dirname(__DIR__));
require 'lib/bootstrap.php';

$now      = time();
$shipCut  = $now - SHIP_KEEP_DAYS * 86400;
$deadCut  = $now - DEAD_KEEP_DAYS * 86400;

// A row is "already scrubbed" when every PII field is empty — skip those so the
// count reflects real work and the job is idempotent.
$blank = "ship_name='' AND ship_email='' AND ship_addr='' AND ship_phone='' AND contact='' AND return_address=''";

$scrub = "ship_name='', ship_email='', ship_addr='', ship_phone='', contact='', return_address=''";

$db = store();

$a = $db->q(
  "UPDATE orders SET $scrub
   WHERE status IN ('paid','shipped') AND shipped_at IS NOT NULL
     AND shipped_at < ? AND NOT ($blank)",
  array($shipCut)
)->rowCount();

$b = $db->q(
  "UPDATE orders SET $scrub
   WHERE status IN ('expired','cancelled')
     AND created_at < ? AND NOT ($blank)",
  array($deadCut)
)->rowCount();

// Empty dead orders: no coin ever arrived, so there is nothing to account for or refund.
// order_items / payments go with them (ON DELETE CASCADE).
$c = $db->q(
  "DELETE FROM orders
   WHERE status IN ('expired','cancelled')
     AND created_at < ? AND received_pico = '0'
     AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = orders.id)",
  array($deadCut)
)->rowCount();

fwrite(STDOUT, sprintf(
  "[%s] pii-purge: scrubbed %d shipped(>%dd) + %d dead(>%dd) = %d rows; deleted %d empty dead orders\n",
  date('c'), $a, SHIP_KEEP_DAYS, $b, DEAD_KEEP_DAYS, $a + $b, $c
));
