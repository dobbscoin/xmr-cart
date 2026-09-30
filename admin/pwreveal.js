/*!
 * pwreveal.js -- click-to-decode-asterisks for every password box.
 *
 * Drop-in, zero dependencies, zero configuration. Finds every
 * <input type="password"> on the page, including ones added later by script
 * (the subgenius.vip card swaps panes; SMF and Roundcube inject forms), and
 * gives each one an eye button that toggles the masking.
 *
 * Deliberate choices:
 *  - type="button", so it can never submit the form it sits inside.
 *  - The input is re-masked on submit. A revealed password otherwise stays
 *    legible on a bfcache back-navigation.
 *  - Inline SVG, no external asset, so it survives any CSP that allows the
 *    page's own inline styles and blocks remote hosts.
 *  - Idempotent: a second include, or a re-render over the same node, will
 *    not stack two buttons.
 *  - spellcheck/autocorrect/autocapitalize are switched off on every field it
 *    touches. Unmasking makes a password box type="text", and that is exactly
 *    what makes it eligible for cloud spellcheck (Chrome Enhanced Spell Check,
 *    Edge's Microsoft Editor), which ships field contents to a remote service.
 *  - data-gramm/data-gramm_editor opt out of Grammarly-class extensions, which
 *    read the field directly and ignore spellcheck="false" entirely.
 *
 * SubGenius.Finance -- 2026-08-10, spellcheck + extension opt-out 2026-08-21
 */
(function () {
  'use strict';

  if (window.__pwreveal) return;          // a page may include this twice
  window.__pwreveal = true;

  var MARK = 'data-pwreveal';

  var EYE =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" ' +
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
    'stroke-linejoin="round" aria-hidden="true" focusable="false">' +
    '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>' +
    '<circle cx="12" cy="12" r="3"/></svg>';

  var EYE_OFF =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" ' +
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
    'stroke-linejoin="round" aria-hidden="true" focusable="false">' +
    '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 ' +
    '18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 ' +
    '18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>' +
    '<line x1="1" y1="1" x2="23" y2="23"/></svg>';

  /* One stylesheet for every button we make. Scoped hard to our own class so
     it cannot leak into a host skin's layout. */
  function injectCss() {
    if (document.getElementById('pwreveal-css')) return;
    var css =
      '.pwreveal-wrap{position:relative!important;display:block}' +
      '.pwreveal-wrap>input{padding-right:2.75em!important}' +
      '.pwreveal-btn{position:absolute;top:50%;right:.5em;transform:translateY(-50%);' +
      'z-index:5;display:flex;align-items:center;justify-content:center;' +
      'width:2em;height:2em;padding:0;margin:0;border:0;border-radius:4px;' +
      'background:transparent;color:inherit;opacity:.6;cursor:pointer;' +
      'line-height:0;-webkit-appearance:none;appearance:none;box-shadow:none}' +
      '.pwreveal-btn:hover,.pwreveal-btn:focus{opacity:1}' +
      '.pwreveal-btn:focus-visible{outline:2px solid currentColor;outline-offset:1px}';
    var s = document.createElement('style');
    s.id = 'pwreveal-css';
    s.appendChild(document.createTextNode(css));
    (document.head || document.documentElement).appendChild(s);
  }

  function setState(input, btn, shown) {
    input.type = shown ? 'text' : 'password';
    btn.innerHTML = shown ? EYE_OFF : EYE;
    btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
    var label = shown ? 'Hide password' : 'Show password';
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
  }

  function attach(input) {
    if (!input || input.getAttribute(MARK) === 'done') return;
    if (input.type !== 'password') return;
    // A hidden or zero-size box (SMF keeps decoy fields) gets nothing.
    if (input.offsetParent === null && input.offsetWidth === 0) return;
    input.setAttribute(MARK, 'done');

    /* Browsers exclude type="password" from spellcheck. Revealing the field
       makes it type="text", which opts it back in -- so a user with Chrome's
       Enhanced Spell Check on would send their password to Google the moment
       they clicked the eye. These three are inert while the box is masked, so
       set them once here rather than toggling them in setState(). */
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'none');
    /* Grammarly and its imitators read the field's value directly and ignore
       spellcheck="false". data-gramm is their own opt-out. */
    input.setAttribute('data-gramm', 'false');
    input.setAttribute('data-gramm_editor', 'false');

    var wrap = document.createElement('span');
    wrap.className = 'pwreveal-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pwreveal-btn';
    btn.tabIndex = 0;
    setState(input, btn, false);

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setState(input, btn, input.type === 'password');
      // Keep the caret where the user left it.
      try { input.focus({ preventScroll: true }); } catch (_) { input.focus(); }
    });

    wrap.appendChild(btn);

    // Never leave a password legible in a restored page.
    var form = input.form;
    if (form && !form.getAttribute(MARK)) {
      form.setAttribute(MARK, 'done');
      form.addEventListener('submit', function () {
        var boxes = form.querySelectorAll('input[' + MARK + '="done"]');
        for (var i = 0; i < boxes.length; i++) boxes[i].type = 'password';
      });
    }
  }

  function scan(root) {
    var nodes = (root || document).querySelectorAll('input[type="password"]');
    for (var i = 0; i < nodes.length; i++) attach(nodes[i]);
  }

  function start() {
    injectCss();
    scan(document);

    // Forms that appear after load: the card's panes, Roundcube dialogs,
    // SMF's ajax profile editor.
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var n = added[j];
            if (n.nodeType !== 1) continue;
            if (n.tagName === 'INPUT') attach(n);
            else scan(n);
          }
        }
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
