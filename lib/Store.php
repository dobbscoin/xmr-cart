<?php
/** Thin PDO/SQLite wrapper. Creates the schema on first use; no migrations needed. */
class Store {
	/** @var PDO */
	public $db;

	public function __construct( $path ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0770, true ); }
		$this->db = new PDO( 'sqlite:' . $path );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->db->exec( 'PRAGMA journal_mode=WAL' );
		$this->db->exec( 'PRAGMA foreign_keys=ON' );
		$this->migrate();
	}

	private function migrate() {
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS batches (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL,
				status TEXT NOT NULL DEFAULT "draft",   -- draft | live | closed
				created_at INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS products (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
				sku TEXT NOT NULL DEFAULT "",
				name TEXT NOT NULL,
				description TEXT NOT NULL DEFAULT "",
				image TEXT NOT NULL DEFAULT "",
				price_fiat REAL NOT NULL DEFAULT 0,
				stock INTEGER NOT NULL DEFAULT 0,
				active INTEGER NOT NULL DEFAULT 1,
				sort INTEGER NOT NULL DEFAULT 0,
				created_at INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS orders (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				token TEXT NOT NULL UNIQUE,
				product_id INTEGER NOT NULL REFERENCES products(id),
				product_name TEXT NOT NULL,
				qty INTEGER NOT NULL DEFAULT 1,
				currency TEXT NOT NULL,
				price_fiat REAL NOT NULL,
				xmr_rate REAL NOT NULL,
				xmr_amount TEXT NOT NULL,       -- canonical string the buyer pays
				expected_pico TEXT NOT NULL,    -- exact piconero
				sub_minor INTEGER NOT NULL,
				subaddress TEXT NOT NULL,
				contact TEXT NOT NULL DEFAULT "",   -- buyer shipping/contact (plaintext; keep box locked down)
				status TEXT NOT NULL DEFAULT "pending", -- pending|confirming|paid|shipped|expired|cancelled
				received_pico TEXT NOT NULL DEFAULT "0",
				confirmations INTEGER NOT NULL DEFAULT 0,
				txids TEXT NOT NULL DEFAULT "",
				created_height INTEGER NOT NULL DEFAULT 0,
				checkpoint_height INTEGER NOT NULL DEFAULT 0,
				created_at INTEGER NOT NULL,
				expires_at INTEGER NOT NULL,
				paid_at INTEGER,
				shipped_at INTEGER
			);
			CREATE TABLE IF NOT EXISTS payments (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				out_key TEXT NOT NULL UNIQUE,
				txid TEXT NOT NULL,
				amount_atomic TEXT NOT NULL,
				block_height INTEGER NOT NULL,
				commitment_ok INTEGER NOT NULL DEFAULT 0,
				locked INTEGER NOT NULL DEFAULT 0,
				first_seen INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS product_images (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
				file TEXT NOT NULL,
				sort INTEGER NOT NULL DEFAULT 0,
				created_at INTEGER NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_product_images ON product_images(product_id, sort);
			CREATE TABLE IF NOT EXISTS counters ( k TEXT PRIMARY KEY, v INTEGER NOT NULL );
			CREATE TABLE IF NOT EXISTS kv ( k TEXT PRIMARY KEY, v TEXT NOT NULL, updated_at INTEGER NOT NULL );'
		);
		$this->db->exec( 'INSERT OR IGNORE INTO counters(k,v) VALUES ("sub_minor", 0)' );

		// Additive column migrations. SQLite `ADD COLUMN` is safe but not idempotent —
		// gate each one on PRAGMA table_info() so this stays cheap and re-runnable.
		$this->addColumnIfMissing( 'products', 'subhead',         'TEXT NOT NULL DEFAULT ""' );
		$this->addColumnIfMissing( 'orders',   'product_subhead', 'TEXT NOT NULL DEFAULT ""' );
		$this->addColumnIfMissing( 'batches',  'sort',            'INTEGER NOT NULL DEFAULT 0' );
		$this->addColumnIfMissing( 'batches',  'description',     'TEXT NOT NULL DEFAULT ""' );
		// Structured ship-to fields. checkout.php and purge_pii.php have written these for
		// a while, but nothing created them, so a fresh install couldn't take an order.
		foreach ( array( 'ship_name', 'ship_email', 'ship_addr', 'ship_phone', 'return_address' ) as $c ) {
			$this->addColumnIfMissing( 'orders', $c, 'TEXT NOT NULL DEFAULT ""' );
		}

		// Line items: an order holds one or more products. The orders.product_* / qty
		// columns stay (NOT NULL, and SQLite can't drop them without a rebuild); new
		// orders fill them with the first line + total units. order_items is the truth.
		// Name, subhead and unit price are snapshotted like orders.price_fiat, so a
		// later catalog edit never rewrites an old order.
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS order_items (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				product_id INTEGER NOT NULL REFERENCES products(id),
				product_name TEXT NOT NULL,
				product_subhead TEXT NOT NULL DEFAULT "",
				sku TEXT NOT NULL DEFAULT "",
				qty INTEGER NOT NULL,
				unit_fiat REAL NOT NULL,
				line_fiat REAL NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
			CREATE INDEX IF NOT EXISTS idx_order_items_product ON order_items(product_id);'
		);
		// Backfill pre-cart orders: one line from the order's own columns. Idempotent.
		$this->db->exec(
			'INSERT INTO order_items (order_id,product_id,product_name,product_subhead,sku,qty,unit_fiat,line_fiat)
			 SELECT o.id, o.product_id, o.product_name, o.product_subhead, COALESCE(p.sku, ""), o.qty,
			        ROUND(o.price_fiat / MAX(o.qty, 1), 2), o.price_fiat
			   FROM orders o LEFT JOIN products p ON p.id = o.product_id
			  WHERE NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)'
		);
	}

	private function addColumnIfMissing( $table, $col, $decl ) {
		$cols = $this->db->query( 'PRAGMA table_info(' . $table . ')' )->fetchAll();
		foreach ( $cols as $c ) { if ( $c['name'] === $col ) { return; } }
		$this->db->exec( 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl );
	}

	public function q( $sql, $args = array() ) {
		$st = $this->db->prepare( $sql );
		$st->execute( $args );
		return $st;
	}
	public function all( $sql, $args = array() ) { return $this->q( $sql, $args )->fetchAll(); }
	public function one( $sql, $args = array() ) { $r = $this->q( $sql, $args )->fetch(); return $r ?: null; }

	/** Atomically allocate the next subaddress minor index (>=1; 0 is the primary). */
	public function nextSubMinor() {
		$this->db->beginTransaction();
		try {
			$this->db->exec( 'UPDATE counters SET v = v + 1 WHERE k = "sub_minor"' );
			$v = (int) $this->one( 'SELECT v FROM counters WHERE k = "sub_minor"' )['v'];
			$this->db->commit();
			return $v;
		} catch ( \Throwable $e ) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function kvGet( $k, $default = null ) {
		$r = $this->one( 'SELECT v, updated_at FROM kv WHERE k = ?', array( $k ) );
		return $r ?: $default;
	}
	public function kvSet( $k, $v ) {
		$this->q( 'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
			array( $k, $v, time() ) );
	}
}
