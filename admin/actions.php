<?php
require_once __DIR__ . '/inc.php';
Auth::require_admin();

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { redirect( 'index.php' ); }
csrf_check();

$store  = store();
$action = (string) req( 'action', '' );

// Default back-URL: whichever tab the form was submitted from. Each case can
// still override $back explicitly. Referrer-based so that "Mark shipped" from
// the Orders tab returns to Orders, "Save copy" from Storefront returns to
// Storefront, etc., without threading a hidden tab field through every form.
$_refTab = 'orders';
$_ref    = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
if ( $_ref && preg_match( '/[?&]tab=(orders|catalog|storefront|nodes|wallet)/', $_ref, $_m ) ) {
	$_refTab = $_m[1];
}
$back = 'index.php?tab=' . $_refTab;

switch ( $action ) {

	case 'create_batch':
		$name = trim( (string) req( 'name', '' ) );
		if ( $name !== '' ) {
			$store->q( 'INSERT INTO batches(name,status,created_at) VALUES(?,?,?)', array( $name, 'draft', now() ) );
		}
		break;

	case 'seed_demo':
		// Idempotent one-shot: adds a "Demo" batch + 3 placeholder products
		// (DEMO — Sticker / Hat / Coffee). Refuses if a Demo batch already
		// exists so re-clicking is safe. See seed_demo_products() in
		// lib/catalog.php for details.
		$r = seed_demo_products();
		$back = 'index.php?tab=catalog';
		if ( $r === 'exists' ) { $back .= '&msg=demo_exists'; }
		break;

	case 'rename_batch':
		$id   = (int) req( 'id', 0 );
		$name = trim( (string) req( 'name', '' ) );
		if ( $id && $name !== '' ) {
			// description is optional; only touch it when the field was submitted
			if ( null !== req( 'description', null ) ) {
				$store->q( 'UPDATE batches SET name=?, description=? WHERE id=?',
					array( $name, trim( (string) req( 'description', '' ) ), $id ) );
			} else {
				$store->q( 'UPDATE batches SET name=? WHERE id=?', array( $name, $id ) );
			}
		}
		break;

	case 'batch_move':   // dir = up | down
		$b = $store->one( 'SELECT * FROM batches WHERE id=?', array( (int) req( 'id', 0 ) ) );
		if ( $b ) {
			$dir  = req( 'dir', 'up' ) === 'down' ? 1 : -1;
			$swap = $store->one(
				$dir < 0
					? 'SELECT * FROM batches WHERE sort < ? ORDER BY sort DESC LIMIT 1'
					: 'SELECT * FROM batches WHERE sort > ? ORDER BY sort ASC LIMIT 1',
				array( (int) $b['sort'] )
			);
			if ( $swap ) {
				$store->q( 'UPDATE batches SET sort=? WHERE id=?', array( (int) $swap['sort'], $b['id'] ) );
				$store->q( 'UPDATE batches SET sort=? WHERE id=?', array( (int) $b['sort'], $swap['id'] ) );
			}
		}
		break;

	case 'batch_status':
		$id = (int) req( 'id', 0 );
		$st = (string) req( 'status', 'draft' );
		if ( ! in_array( $st, array( 'draft', 'live', 'closed' ), true ) ) { $st = 'draft'; }
		// Multiple batches may be live at once — each sells until its stock is gone.
		$store->q( 'UPDATE batches SET status=? WHERE id=?', array( $st, $id ) );
		break;

	case 'add_product':
		$batchId = (int) req( 'batch_id', 0 );
		$name    = trim( (string) req( 'name', '' ) );
		if ( $batchId && $name !== '' ) {
			$image = upload_image( 'image' );
			$store->q(
				'INSERT INTO products(batch_id,sku,name,subhead,description,image,price_fiat,stock,active,sort,created_at)
				 VALUES(?,?,?,?,?,?,?,?,1,0,?)',
				array(
					$batchId,
					trim( (string) req( 'sku', '' ) ),
					$name,
					trim( (string) req( 'subhead', '' ) ),
					trim( (string) req( 'description', '' ) ),
					$image,
					(float) req( 'price_fiat', 0 ),
					(int) req( 'stock', 0 ),
					now(),
				)
			);
			$pid = (int) $store->one( 'SELECT last_insert_rowid() AS id' )['id'];
			$store->q( 'UPDATE products SET needs_shipping=? WHERE id=?', array( '0' === (string) req( 'needs_shipping', '1' ) ? 0 : 1, $pid ) );
			save_gallery( $store, $pid, upload_images( 'gallery' ) );
		}
		break;

	case 'add_images': // add more photos to an existing product
		$pid = (int) req( 'product_id', 0 );
		if ( $pid && $store->one( 'SELECT id FROM products WHERE id=?', array( $pid ) ) ) {
			save_gallery( $store, $pid, upload_images( 'gallery' ) );
		}
		break;



	case 'edit_product':
		$id = (int) req( 'id', 0 );
		$p  = $id ? $store->one( 'SELECT * FROM products WHERE id=?', array( $id ) ) : null;
		if ( $p ) {
			$name = trim( (string) req( 'name', '' ) );
			if ( $name !== '' ) {
				// keep the existing image unless a new one was uploaded
				$image = upload_image( 'image' );
				if ( $image === '' ) { $image = (string) $p['image']; }
				// allow moving the product into a different batch
				$newBatch = (int) req( 'batch_id', 0 );
				if ( ! $newBatch || ! $store->one( 'SELECT id FROM batches WHERE id=?', array( $newBatch ) ) ) {
					$newBatch = (int) $p['batch_id'];
				}
				$store->q(
					'UPDATE products SET batch_id=?, sku=?, name=?, subhead=?, description=?, image=?, price_fiat=?, stock=?, sort=? WHERE id=?',
					array(
						$newBatch,
						trim( (string) req( 'sku', '' ) ),
						$name,
						trim( (string) req( 'subhead', '' ) ),
						trim( (string) req( 'description', '' ) ),
						$image,
						(float) req( 'price_fiat', 0 ),
						max( 0, (int) req( 'stock', 0 ) ),
						(int) req( 'sort', 0 ),
						$id,
					)
				);
				$store->q( 'UPDATE products SET needs_shipping=? WHERE id=?', array( '0' === (string) req( 'needs_shipping', '1' ) ? 0 : 1, $id ) );
				$p['batch_id'] = $newBatch;   // redirect to wherever it now lives
			}
		}
		$back = 'index.php?tab=catalog&batch=' . (int) ( $p ? $p['batch_id'] : 0 );
		break;

	case 'pricing_display_set':
		price_display_mode_set( (string) req( 'mode', 'both' ) );
		$back = 'index.php?tab=storefront#display';
		break;

	case 'probe_nodes_refresh':
		nodeprobe()->refresh();
		$back = 'index.php?tab=nodes';
		break;

	// ---- nodes: edit the settlement fleet from the console ----
	// Writes to kv['nodes_override']; Config::nodesRaw() prefers that over
	// config.php['nodes']. Empty kv falls through to config again.
	case 'node_add':
	case 'node_edit':
	case 'node_remove':
	case 'node_move':
		{
			$nodes = Config::nodesArray();
			$url   = trim( (string) req( 'url', '' ) );
			$idx   = (int) req( 'idx', -1 );

			if ( $action === 'node_add' ) {
				if ( $url !== '' && preg_match( '~^https?://~i', $url ) ) {
					$nodes[] = $url;
				}
			} elseif ( $action === 'node_edit' ) {
				if ( $idx >= 0 && $idx < count( $nodes ) && $url !== '' && preg_match( '~^https?://~i', $url ) ) {
					$nodes[ $idx ] = $url;
				}
			} elseif ( $action === 'node_remove' ) {
				if ( $idx >= 0 && $idx < count( $nodes ) ) {
					array_splice( $nodes, $idx, 1 );
				}
			} elseif ( $action === 'node_move' ) {
				$dir = req( 'dir', 'up' ) === 'down' ? 1 : -1;
				$j   = $idx + $dir;
				if ( $idx >= 0 && $idx < count( $nodes ) && $j >= 0 && $j < count( $nodes ) ) {
					$tmp = $nodes[ $idx ]; $nodes[ $idx ] = $nodes[ $j ]; $nodes[ $j ] = $tmp;
				}
			}

			$store->kvSet( 'nodes_override', implode( ',', $nodes ) );
			nodeprobe()->refresh();  // re-probe against the new list
			$back = 'index.php?tab=nodes';
		}
		break;

	case 'nodes_revert':
		// Drop the console override → Config::nodesRaw() falls back to config.php.
		$store->q( "DELETE FROM kv WHERE k='nodes_override'" );
		nodeprobe()->refresh();
		$back = 'index.php?tab=nodes';
		break;

	// ---- wallet identity (kv-override; Config::primaryAddress/viewKey read this) ----
	case 'wallet_save':
		{
			$address = trim( (string) req( 'primary_address', '' ) );
			$viewkey = trim( (string) req( 'view_key', '' ) );
			$confirm = (string) req( 'confirm_pass', '' );
			$msg     = '';

			// Elevated action — re-enter passphrase. Even though the operator's
			// already authed via cookie, wallet identity is the one edit that
			// lets an admin-compromise silently redirect real money. Cutting
			// the compromised-cookie attack in half is worth 5 seconds of
			// friction for a change that should happen ~once.
			$openOrders = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status IN ('pending','confirming')" )['c'];
			if ( ! password_verify( $confirm, trim( (string) ( store()->kvGet( 'admin_pass_hash' )['v'] ?? Config::get( 'admin_pass_hash', '' ) ) ) ) ) {
				$msg = 'wrong_pass';
			} elseif ( $openOrders > 0 ) {
				// The scanner watches with the CURRENT view key only; open orders' subaddresses
				// belong to the old wallet and would never be seen paid.
				$msg = 'open_orders';
			} elseif ( $address === '' || $viewkey === '' ) {
				$msg = 'missing';
			} elseif ( strlen( $address ) !== 95 || $address[0] !== '4' ) {
				$msg = 'bad_address';
			} elseif ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $viewkey ) ) {
				$msg = 'bad_viewkey';
			} else {
				$check = xmr()->verifyKeysPair( $address, $viewkey );
				if ( empty( $check['address_valid'] ) )      { $msg = 'bad_address'; }
				elseif ( empty( $check['key_match'] ) )      { $msg = 'mismatch'; }
			}

			if ( $msg === '' ) {
				$store->kvSet( 'wallet_primary_address', $address );
				$store->kvSet( 'wallet_view_key',        strtolower( $viewkey ) );
				$back = 'index.php?tab=wallet&saved=1';
			} else {
				$back = 'index.php?tab=wallet&err=' . urlencode( $msg );
			}
		}
		break;

	case 'wallet_revert':
		{
			$confirm = (string) req( 'confirm_pass', '' );
			if ( ! password_verify( $confirm, trim( (string) ( store()->kvGet( 'admin_pass_hash' )['v'] ?? Config::get( 'admin_pass_hash', '' ) ) ) ) ) {
				$back = 'index.php?tab=wallet&err=wrong_pass';
			} elseif ( (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status IN ('pending','confirming')" )['c'] > 0 ) {
				$back = 'index.php?tab=wallet&err=open_orders';
			} else {
				$store->q( "DELETE FROM kv WHERE k IN ('wallet_primary_address','wallet_view_key')" );
				$back = 'index.php?tab=wallet&reverted=1';
			}
		}
		break;

	// ---- gallery: helpers ----
	case 'save_copy':
		foreach ( array_keys( copy_defaults() ) as $k ) {
			$v = req( 'copy_' . $k, null );
			if ( null !== $v ) { site_copy_set( $k, trim( (string) $v ) ); }
		}
		$back = 'index.php?tab=storefront&copy=1';
		break;

	// ---- masthead banner ----
	case 'banner_save':
		// the file is optional: this form also saves the knobs on their own
		if ( ! empty( $_FILES['banner'] ) && ( $_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
			$old = basename( (string) banner_get( 'file' ) );
			$new = save_banner_image( $_FILES['banner'] );
			if ( '' !== $new ) {
				banner_set( 'file', $new );
				// only bin the old one once the new one is safely on disk
				if ( '' !== $old && $old !== $new ) { @unlink( banner_dir() . '/' . $old ); }
			}
		}

		$fit = (string) req( 'fit', 'cover' );
		banner_set( 'fit', in_array( $fit, array( 'cover', 'contain', 'stretch' ), true ) ? $fit : 'cover' );

		$pos = (string) req( 'pos', 'center' );
		banner_set( 'pos', in_array( $pos, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ? $pos : 'center' );

		$bh = (int) req( 'height', 0 );
		banner_set( 'height', $bh > 0 ? (string) min( 800, $bh ) : '' );

		banner_set( 'scrim', (string) max( 0, min( 100, (int) req( 'scrim', 55 ) ) ) );
		banner_set( 'text', 'light' === req( 'text', 'dark' ) ? 'light' : 'dark' );

		$back = 'index.php?tab=storefront&banner=1#banner';
		break;

	case 'banner_remove':
		$old = basename( (string) banner_get( 'file' ) );
		if ( '' !== $old ) { @unlink( banner_dir() . '/' . $old ); }
		banner_set( 'file', '' );
		$back = 'index.php?tab=storefront&banner=1#banner';
		break;

	case 'img_add':
		$pid = (int) req( 'product_id', 0 );
		if ( $pid && ! empty( $_FILES['images'] ) ) {
			$files = $_FILES['images'];
			$n = is_array( $files['name'] ) ? count( $files['name'] ) : 0;
			$next = (int) ( $store->one( 'SELECT COALESCE(MAX(sort),-1)+1 s FROM product_images WHERE product_id=?', array( $pid ) )['s'] ?? 0 );
			for ( $i = 0; $i < $n; $i++ ) {
				if ( (int) $files['error'][ $i ] !== UPLOAD_ERR_OK ) { continue; }
				$one = array( 'name' => $files['name'][ $i ], 'type' => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ], 'error' => $files['error'][ $i ], 'size' => $files['size'][ $i ] );
				$saved = save_one_image( $one );
				if ( $saved !== '' ) {
					$store->q( 'INSERT INTO product_images(product_id,file,sort,created_at) VALUES(?,?,?,?)', array( $pid, $saved, $next++, now() ) );
				}
			}
			sync_main_image( $store, $pid );
		}
		$back = 'index.php?tab=catalog&batch=' . (int) req( 'batch_id', 0 ) . '&edit=' . $pid;
		break;

	case 'image_main':   // legacy alias
	case 'img_main':   // promote to MAIN (sort 0)
		$img = $store->one( 'SELECT * FROM product_images WHERE id=?', array( (int) req( 'id', 0 ) ) );
		if ( $img ) {
			$pid = (int) $img['product_id'];
			$store->q( 'UPDATE product_images SET sort = sort + 1 WHERE product_id=?', array( $pid ) );
			$store->q( 'UPDATE product_images SET sort = 0 WHERE id=?', array( $img['id'] ) );
			renumber_images( $store, $pid );
			sync_main_image( $store, $pid );
		}
		$back = 'index.php?tab=catalog&batch=' . (int) req( 'batch_id', 0 ) . '&edit=' . (int) ( $img['product_id'] ?? 0 );
		break;

	case 'img_move':   // dir = up | down
		$img = $store->one( 'SELECT * FROM product_images WHERE id=?', array( (int) req( 'id', 0 ) ) );
		if ( $img ) {
			$pid = (int) $img['product_id'];
			$dir = req( 'dir', 'up' ) === 'down' ? 1 : -1;
			$swap = $store->one(
				$dir < 0
					? 'SELECT * FROM product_images WHERE product_id=? AND sort < ? ORDER BY sort DESC LIMIT 1'
					: 'SELECT * FROM product_images WHERE product_id=? AND sort > ? ORDER BY sort ASC LIMIT 1',
				array( $pid, (int) $img['sort'] )
			);
			if ( $swap ) {
				$store->q( 'UPDATE product_images SET sort=? WHERE id=?', array( (int) $swap['sort'], $img['id'] ) );
				$store->q( 'UPDATE product_images SET sort=? WHERE id=?', array( (int) $img['sort'], $swap['id'] ) );
				renumber_images( $store, $pid );
				sync_main_image( $store, $pid );
			}
		}
		$back = 'index.php?tab=catalog&batch=' . (int) req( 'batch_id', 0 ) . '&edit=' . (int) ( $img['product_id'] ?? 0 );
		break;

	case 'image_delete': // legacy alias
	case 'img_delete':
		$img = $store->one( 'SELECT * FROM product_images WHERE id=?', array( (int) req( 'id', 0 ) ) );
		if ( $img ) {
			$pid = (int) $img['product_id'];
			$store->q( 'DELETE FROM product_images WHERE id=?', array( $img['id'] ) );
			// only unlink if no other product/row still references the file
			$still = (int) $store->one( 'SELECT COUNT(*) c FROM product_images WHERE file=?', array( $img['file'] ) )['c'];
			if ( ! $still ) {
				$dir = (string) Config::get( 'uploads_dir', dirname( __DIR__ ) . '/public/assets/products' );
				@unlink( $dir . '/' . $img['file'] );
			}
			renumber_images( $store, $pid );
			sync_main_image( $store, $pid );
		}
		$back = 'index.php?tab=catalog&batch=' . (int) req( 'batch_id', 0 ) . '&edit=' . (int) ( $img['product_id'] ?? 0 );
		break;

	case 'product_active':
		$store->q( 'UPDATE products SET active=? WHERE id=?', array( (int) req( 'active', 0 ) ? 1 : 0, (int) req( 'id', 0 ) ) );
		break;

	case 'product_stock':
		$store->q( 'UPDATE products SET stock=? WHERE id=?', array( max( 0, (int) req( 'stock', 0 ) ), (int) req( 'id', 0 ) ) );
		break;

	case 'product_delete':
		$pid = (int) req( 'id', 0 );
		$p   = $store->one( 'SELECT image FROM products WHERE id=?', array( $pid ) );
		if ( ! $p ) { break; }
		// Orders keep a permanent reference to the product they were placed against, so a
		// product that has ever been ordered is HIDDEN, never destroyed. Deleting it would
		// break the order record (and the DB's foreign key rightly refuses).
		$hasOrders = (int) $store->one(
			'SELECT (SELECT COUNT(*) FROM orders WHERE product_id=?) + (SELECT COUNT(*) FROM order_items WHERE product_id=?) AS c',
			array( $pid, $pid )
		)['c'];
		if ( $hasOrders > 0 ) {
			$store->q( 'UPDATE products SET active=0 WHERE id=?', array( $pid ) );
			$back = 'index.php?tab=catalog&msg=hidden';
			break;
		}
		if ( $p['image'] !== '' ) { delete_image_file( $p['image'] ); }
		foreach ( $store->all( 'SELECT file FROM product_images WHERE product_id=?', array( $pid ) ) as $g ) {
			delete_image_file( $g['file'] );
		}
		$store->q( 'DELETE FROM product_images WHERE product_id=?', array( $pid ) );
		$store->q( 'DELETE FROM products WHERE id=?', array( $pid ) );
		$back = 'index.php?tab=catalog&msg=deleted';
		break;

	case 'order_ship':
		$store->q( "UPDATE orders SET status='shipped', shipped_at=? WHERE id=? AND status='paid'", array( now(), (int) req( 'id', 0 ) ) );
		break;

	case 'order_cancel':
		// Only an open (pending/confirming) order can be cancelled; the guard inside
		// releases each line's stock exactly once, even racing the worker's expiry.
		order_close_and_release( (int) req( 'id', 0 ), 'cancelled' );
		break;

	case 'order_delete':
		// Hard-delete an order row (plus its payments via ON DELETE CASCADE).
		// Stock is NOT restored — a settled order's decrement stands, matching how
		// the subaddress counter is deliberately not rolled back on test cleanup.
		// Use Cancel first if you want the stock reservation released.
		$store->q( 'DELETE FROM orders WHERE id=?', array( (int) req( 'id', 0 ) ) );
		break;

	case 'sim_pay': // demo only
		if ( ! xmr()->isReal() ) {
			$o = $store->one( 'SELECT * FROM orders WHERE id=?', array( (int) req( 'id', 0 ) ) );
			if ( $o && in_array( $o['status'], array( 'pending', 'confirming' ), true ) ) {
				$tip  = (int) xmr()->tipHeight();
				$minc = (int) Config::get( 'min_confirmations', 10 );
				$store->q(
					'INSERT OR IGNORE INTO payments(order_id,out_key,txid,amount_atomic,block_height,commitment_ok,locked,first_seen)
					 VALUES(?,?,?,?,?,1,0,?)',
					array( $o['id'], 'demo:' . $o['id'], 'demo' . substr( hash( 'sha256', $o['token'] ), 0, 60 ), $o['expected_pico'], max( 0, $tip - $minc ), now() )
				);
				require_once __DIR__ . '/../lib/settle.php';
				settle_order( $store, xmr(), $o, $tip );
			}
		}
		break;
}

redirect( $back );

/** Single-file product image upload (add/edit form). Resizes via save_one_image(). */
function upload_image( $field ) {
	if ( empty( $_FILES[ $field ] ) || ( $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) { return ''; }
	return save_one_image( $_FILES[ $field ] );
}


/** Multiple files from one <input name="gallery[]" multiple>; returns array of filenames. */
function upload_images( $field ) {
	$out = array();
	if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ]['tmp_name'] ) ) { return $out; }
	foreach ( $_FILES[ $field ]['tmp_name'] as $i => $tmp ) {
		if ( ( $_FILES[ $field ]['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) { continue; }
		$f = store_upload_tmp( $tmp );
		if ( $f !== '' ) { $out[] = $f; }
	}
	return $out;
}

/** Validate + store one uploaded tmp file. Returns stored filename or ''. */
function store_upload_tmp( $tmp ) {
	$info = @getimagesize( $tmp );
	$map  = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' );
	if ( ! $info || ! isset( $map[ $info[2] ] ) ) { return ''; }
	$dir = (string) Config::get( 'uploads_dir', dirname( __DIR__ ) . '/public/assets/products' );
	if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0770, true ); }
	// Always re-encode: it drops EXIF (phone photos carry GPS) and anything hiding in the file.
	$fname = bin2hex( random_bytes( 8 ) ) . '.jpg';
	$ok    = resize_to_jpeg( $tmp, $info, $dir . '/' . $fname, (int) Config::get( 'image_max_px', 1400 ), (int) Config::get( 'image_quality', 82 ) );
	@unlink( $tmp );
	return $ok ? $fname : '';
}

/** Insert gallery rows for freshly uploaded files. */
function save_gallery( $store, $pid, $files ) {
	$n = (int) ( $store->one( 'SELECT COALESCE(MAX(sort),0) AS s FROM product_images WHERE product_id=?', array( $pid ) )['s'] ?? 0 );
	foreach ( $files as $f ) {
		$n++;
		$store->q( 'INSERT INTO product_images(product_id,file,sort,created_at) VALUES(?,?,?,?)', array( $pid, $f, $n, now() ) );
	}
}

/** Remove a stored image from disk (best effort; never fatal). */
function delete_image_file( $file ) {
	$file = basename( (string) $file ); // never escape the uploads dir
	if ( $file === '' ) { return; }
	$dir  = (string) Config::get( 'uploads_dir', dirname( __DIR__ ) . '/public/assets/products' );
	@unlink( $dir . '/' . $file );
}


/** Renumber a product's images 0..N-1 (0 = MAIN). */
function renumber_images( $store, $pid ) {
	$i = 0;
	foreach ( $store->all( 'SELECT id FROM product_images WHERE product_id=? ORDER BY sort,id', array( $pid ) ) as $r ) {
		$store->q( 'UPDATE product_images SET sort=? WHERE id=?', array( $i, $r['id'] ) );
		$i++;
	}
}

/** Keep products.image mirroring the gallery MAIN so catalog cards stay correct. */
function sync_main_image( $store, $pid ) {
	$main = $store->one( 'SELECT file FROM product_images WHERE product_id=? ORDER BY sort LIMIT 1', array( $pid ) );
	$store->q( 'UPDATE products SET image=? WHERE id=?', array( $main ? $main['file'] : '', $pid ) );
}

/**
 * Banner upload. Same validation as a product photo, but a much wider max edge:
 * this image spans the full page, not a 240px card, so 1400px would look soft.
 */
function save_banner_image( $f ) {
	$info = @getimagesize( $f['tmp_name'] );
	$map  = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' );
	if ( ! $info || ! isset( $map[ $info[2] ] ) ) { return ''; }

	$dir = banner_dir();
	if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0775, true ); }

	$max  = (int) Config::get( 'banner_max_px', 2400 );   // longest edge
	$qual = (int) Config::get( 'banner_quality', 88 );

	$name = 'banner-' . bin2hex( random_bytes( 6 ) ) . '.jpg';
	$dest = $dir . '/' . $name;

	// No raw-bytes fallback: an un-re-encoded upload would keep its EXIF (GPS) data.
	return resize_to_jpeg( $f['tmp_name'], $info, $dest, $max, $qual ) ? $name : '';
}

/** Validate, downscale, re-encode, and store an uploaded image. Returns filename or ''. */
function save_one_image( $f ) {
	$info = @getimagesize( $f['tmp_name'] );
	$map  = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' );
	if ( ! $info || ! isset( $map[ $info[2] ] ) ) { return ''; }

	$dir = (string) Config::get( 'uploads_dir', dirname( __DIR__ ) . '/public/assets/products' );
	if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0770, true ); }

	$max  = (int) Config::get( 'image_max_px', 1400 );   // longest edge
	$qual = (int) Config::get( 'image_quality', 82 );

	$name  = bin2hex( random_bytes( 8 ) ) . '.jpg';      // everything normalises to jpeg
	$dest  = $dir . '/' . $name;

	$resized = resize_to_jpeg( $f['tmp_name'], $info, $dest, $max, $qual );
	if ( ! $resized ) {
		// GD failed (exotic file) — fall back to storing the original untouched.
		$name = bin2hex( random_bytes( 8 ) ) . '.' . $map[ $info[2] ];
		$dest = $dir . '/' . $name;
		if ( ! @move_uploaded_file( $f['tmp_name'], $dest ) && ! @rename( $f['tmp_name'], $dest ) ) { return ''; }
	}
	return $name;
}

/** Downscale to a max longest-edge and write JPEG. Returns true on success. */
function resize_to_jpeg( $src, $info, $dest, $max, $qual ) {
	list( $w, $h ) = $info;
	if ( $w < 1 || $h < 1 ) { return false; }

	switch ( $info[2] ) {
		case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg( $src ); break;
		case IMAGETYPE_PNG:  $im = @imagecreatefrompng( $src );  break;
		case IMAGETYPE_WEBP: $im = @imagecreatefromwebp( $src ); break;
		default: return false;
	}
	if ( ! $im ) { return false; }

	// honour EXIF orientation so phone photos aren't sideways
	if ( IMAGETYPE_JPEG === $info[2] && function_exists( 'exif_read_data' ) ) {
		$ex = @exif_read_data( $src );
		if ( ! empty( $ex['Orientation'] ) ) {
			if ( 3 === (int) $ex['Orientation'] ) { $im = imagerotate( $im, 180, 0 ); }
			elseif ( 6 === (int) $ex['Orientation'] ) { $im = imagerotate( $im, -90, 0 ); }
			elseif ( 8 === (int) $ex['Orientation'] ) { $im = imagerotate( $im, 90, 0 ); }
			$w = imagesx( $im ); $h = imagesy( $im );
		}
	}

	$scale = min( 1.0, $max / max( $w, $h ) );          // never upscale
	$nw    = max( 1, (int) round( $w * $scale ) );
	$nh    = max( 1, (int) round( $h * $scale ) );

	$out = imagecreatetruecolor( $nw, $nh );
	// flatten transparency onto white (jpeg has no alpha)
	$white = imagecolorallocate( $out, 255, 255, 255 );
	imagefilledrectangle( $out, 0, 0, $nw, $nh, $white );
	imagecopyresampled( $out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );

	$ok = @imagejpeg( $out, $dest, $qual );
	imagedestroy( $im );
	imagedestroy( $out );
	return (bool) $ok;
}

