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
$blank = "ship_name='' AND ship_email='' AND ship_addr='' AND ship_phone='' AND contact=''";

$scrub = "ship_name='', ship_email='', ship_addr='', ship_phone='', contact=''";

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

fwrite(STDOUT, sprintf(
  "[%s] pii-purge: scrubbed %d shipped(>%dd) + %d dead(>%dd) = %d rows\n",
  date('c'), $a, SHIP_KEEP_DAYS, $b, DEAD_KEEP_DAYS, $a + $b
));
