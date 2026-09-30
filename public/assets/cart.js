/*
 * Cart, kept in the buyer's browser. localStorage holds [{id, qty}] and nothing
 * else; names, prices and stock always come from the server (cart.php). With
 * storage blocked the Add-to-cart button stays hidden and the single-item Buy
 * form on each product page works as before.
 */
(function () {
  var KEY = 'xmrcart:v1';

  function load() { // array, or null when storage is unavailable
    try {
      var v = JSON.parse(localStorage.getItem(KEY) || '[]');
      if (!Array.isArray(v)) return [];
      return v.filter(function (x) { return x && x.id > 0 && x.qty > 0; })
              .map(function (x) { return { id: x.id | 0, qty: x.qty | 0 }; });
    } catch (e) { return null; }
  }
  function save(c) {
    try { localStorage.setItem(KEY, JSON.stringify(c)); return true; } catch (e) { return false; }
  }
  function enc(c) { return c.map(function (x) { return x.id + ':' + x.qty; }).join(','); }
  function dec(s) {
    return (s || '').split(',').filter(Boolean).map(function (b) {
      var kv = b.split(':'); return { id: kv[0] | 0, qty: kv[1] | 0 };
    }).filter(function (x) { return x.id > 0 && x.qty > 0; });
  }
  function units(c) { return c.reduce(function (a, x) { return a + x.qty; }, 0); }

  var bar = document.getElementById('cartbar');
  function paint(c) {
    if (!bar) return;
    var n = c ? units(c) : 0;
    bar.hidden = n === 0;
    bar.querySelector('.n').textContent = n === 1 ? '1 item' : n + ' items';
    bar.href = 'cart.php?c=' + enc(c || []);
  }

  var cart = load();

  // An order placed from the cart: it's done, empty it.
  if (/[?&]cart=done\b/.test(location.search) && cart) { cart = []; save(cart); }
  paint(cart);

  // Product page: Add to cart.
  var add = document.getElementById('addcart');
  if (add && cart !== null) {
    add.hidden = false;
    var or = document.querySelector('.addcart-or');
    if (or) or.hidden = false;
    add.addEventListener('click', function () {
      var c = load() || [];
      var id = +add.dataset.id, max = +add.dataset.stock;
      var qEl = document.getElementById('qty');
      var q = qEl ? (+qEl.value || 1) : 1;
      var line = null;
      c.forEach(function (x) { if (x.id === id) line = x; });
      if (line) line.qty = Math.min(max, line.qty + q); else c.push({ id: id, qty: Math.min(max, q) });
      var msg = document.getElementById('addcart-msg');
      if (save(c)) {
        paint(c);
        msg.innerHTML = 'Added. <a href="cart.php?c=' + enc(c) + '">View cart &amp; check out</a>';
      } else {
        msg.textContent = "Couldn't save your cart in this browser. You can still buy this item on its own below.";
      }
      msg.hidden = false;
    });
  }

  // Cart page.
  var page = document.getElementById('cartpage');
  if (!page) return;
  if (page.dataset.load === '1') {
    // Arrived without ?c=: fetch the lines for what this browser holds.
    if (cart && cart.length) { location.replace('cart.php?c=' + enc(cart)); return; }
    var empty = document.getElementById('cart-empty');
    if (empty) empty.hidden = false;
    return;
  }
  // The server's list is the truth (unavailable lines dropped, qty clamped to stock).
  var clean = dec(page.dataset.cart);
  save(clean);
  paint(clean);
  function go(c) { save(c); location.replace('cart.php?c=' + enc(c)); }
  page.querySelectorAll('select[data-id]').forEach(function (s) {
    s.addEventListener('change', function () {
      var id = +s.dataset.id;
      go(clean.map(function (x) { return x.id === id ? { id: id, qty: +s.value } : x; }));
    });
  });
  page.querySelectorAll('[data-remove]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = +b.dataset.remove;
      go(clean.filter(function (x) { return x.id !== id; }));
    });
  });
})();
