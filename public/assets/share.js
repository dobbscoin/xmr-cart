/*
 * Share buttons: [data-share] holds a relative link to the item's page.
 * Phones get the native share sheet; everything else copies the link.
 * Reads nothing from the page but the button's own attributes; sends nothing.
 */
(function () {
  function say(btn, msg) {
    var was = btn.textContent;
    btn.textContent = msg;
    setTimeout(function () { btn.textContent = was; }, 1600);
  }
  function oldCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', '');
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok;
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-share]');
    if (!btn) return;
    e.preventDefault();
    var url = new URL(btn.getAttribute('data-share'), location.href).href;
    var title = btn.getAttribute('data-title') || document.title;
    if (navigator.share && window.matchMedia && matchMedia('(pointer: coarse)').matches) {
      navigator.share({ title: title, url: url }).catch(function () {});
      return;
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(
        function () { say(btn, 'Link copied'); },
        function () { say(btn, oldCopy(url) ? 'Link copied' : url); });
    } else {
      say(btn, oldCopy(url) ? 'Link copied' : url);
    }
  });
})();
