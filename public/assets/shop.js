(function () {
  // ---- QR from the monero: URI (vendored qrcode.js, no network) ----
  var qel = document.getElementById('qr');
  if (qel && qel.dataset.uri && typeof qrcode === 'function') {
    try {
      var qr = qrcode(0, 'M');
      qr.addData(qel.dataset.uri);
      qr.make();
      qel.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    } catch (e) {
      qel.innerHTML = '<span class="hint">QR unavailable — copy the address below.</span>';
    }
  }

  var cert = document.querySelector('.cert');
  if (!cert) return;
  var token = cert.dataset.token;
  var minconf = parseInt(cert.dataset.minconf || '10', 10);
  var pillEl = document.getElementById('statuspill');
  var doneEl = document.getElementById('done');
  var meter = document.getElementById('meter');
  var mfill = document.getElementById('meterfill');
  var mlab = document.getElementById('meterlabel');
  var mcnt = document.getElementById('metercount');

  // Drive the meter from whatever the worker has settled.
  //   pending    -> 0%, waiting for the tx to appear
  //   confirming -> a slice for detection, the rest earned per confirmation
  //   paid       -> 100%, green
  function setMeter(status, confs, recv, exp) {
    if (!meter || !mfill) return;
    var pct = 0, label = 'Waiting for payment…', count = '';
    meter.classList.remove('waiting', 'working', 'paid', 'expired');

    if (status === 'paid' || status === 'shipped') {
      pct = 100;
      label = 'Payment confirmed';
      count = confs + '/' + minconf + ' confirmations';
      meter.classList.add('paid');
    } else if (status === 'expired' || status === 'cancelled') {
      pct = 100;
      label = status === 'expired' ? 'Quote expired' : 'Order cancelled';
      meter.classList.add('expired');
    } else if (status === 'confirming' || status === 'mempool' || status === 'partial') {
      // detection is worth the first 15%; confirmations fill the remaining 85%
      var ratio = minconf > 0 ? Math.min(1, confs / minconf) : 1;
      pct = 15 + ratio * 85;
      label = confs > 0 ? 'Confirming on-chain…' : 'Payment seen — waiting for a block…';
      count = confs + '/' + minconf + ' confirmations';
      meter.classList.add('working');
    } else {
      // pending: keep a small live sliver so the bar doesn't read as dead
      pct = 4;
      label = 'Waiting for payment…';
      count = recv && parseFloat(recv) > 0 ? recv + ' / ' + exp + ' XMR' : '';
      meter.classList.add('waiting');
    }

    mfill.style.width = pct.toFixed(1) + '%';
    meter.setAttribute('aria-valuenow', Math.round(pct));
    if (mlab) mlab.textContent = label;
    if (mcnt) mcnt.textContent = count;
  }

  // ---- quote countdown ----
  // When the clock lapses we pull the address off the page immediately. The worker
  // may not mark the order 'expired' for up to another 60s, and coin sent to a dead
  // order's subaddress is never credited — so the buyer must stop sending NOW, not
  // whenever the next cron tick happens to notice.
  var cd = document.getElementById('countdown');
  var payblock = document.getElementById('payblock');
  var lapsedEl = document.getElementById('lapsed');
  var lastStatus = (cert && cert.dataset.status) || 'pending';

  function showLapsed() {
    if (payblock) payblock.style.display = 'none';
    if (lapsedEl) lapsedEl.style.display = 'block';
  }
  function hideLapsed() {
    if (lapsedEl) lapsedEl.style.display = 'none';
  }

  function tick() {
    if (!cd) return;
    var left = parseInt(cd.dataset.exp, 10) * 1000 - Date.now();
    if (left <= 0) {
      cd.textContent = 'expired';
      // Only a still-unpaid order is at risk. Once anything has landed the order is
      // 'confirming' and can never expire, so the lapsed clock is moot — say nothing.
      if (lastStatus === 'pending') { showLapsed(); }
      return;
    }
    var m = Math.floor(left / 60000), s = Math.floor((left % 60000) / 1000);
    cd.textContent = m + 'm ' + (s < 10 ? '0' : '') + s + 's';
  }
  tick(); setInterval(tick, 1000);

  // ---- status polling (reads the row the worker updates) ----
  function pill(status, confs) {
    var label = status.charAt(0).toUpperCase() + status.slice(1);
    if (status === 'confirming') label = 'Confirming ' + confs + '/' + minconf;
    return '<span class="pill ' + status + '">' + label + '</span>';
  }
  var stop = false;
  function poll() {
    if (stop) return;
    fetch('status.php?t=' + encodeURIComponent(token), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) return;
        lastStatus = d.status;
        if (pillEl) pillEl.innerHTML = pill(d.status, d.confirmations);
        setMeter(d.status, d.confirmations, d.received_xmr, d.expected_xmr);

        // payment landed after the clock lapsed: the order is safe, take the warning down
        if (d.status === 'confirming' || d.status === 'paid' || d.status === 'shipped') {
          hideLapsed();
        }
        if (d.status === 'paid' || d.status === 'shipped') {
          if (doneEl) doneEl.style.display = 'block';
          stop = true;
        }
        // the worker has now killed it — reload so the server renders the dead-order page
        if (d.status === 'expired' || d.status === 'cancelled') {
          stop = true;
          window.location.reload();
        }
      })
      .catch(function () {});
  }
  poll();
  setInterval(poll, 5000);
})();
