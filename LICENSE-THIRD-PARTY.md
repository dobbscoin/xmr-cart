# Third-party code in this store

This application bundles code from two upstream projects. Both are MIT-licensed.
Their copyright notices and permission text are reproduced below, as MIT requires.

---

## 1. xmr-pay-woocommerce  by SlowBearDigger  (payment verification engine)

    Source:  https://github.com/SlowBearDigger/xmr-pay-woocommerce
    Author:  SlowBearDigger  (https://github.com/SlowBearDigger)
    Version: 1.1.4
    Licence: MIT

Files used, essentially unmodified:

    lib/scanner/class-xmrpay-scanner.php     — payment scanning + verification
    lib/scanner/class-xmrpay-util.php        — crypto helpers

This is the code that actually verifies incoming Monero payments: output
ownership, amount decoding, Pedersen commitment validation, and confirmation
depth. It fails closed. **We did not write it. Full credit to SlowBearDigger.**

Local modification: none to these two files.

Upstream MIT licence text, reproduced as required:

    MIT License

    Copyright (c) 2026 SlowBearDigger

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.

---

## 2. monero-php / Monero Integrations  (cryptographic primitives)

Vendored inside the plugin above, and used here at:

    lib/scanner/vendor/monero/Cryptonote.php
    lib/scanner/vendor/monero/ed25519.php
    lib/scanner/vendor/monero/Keccak.php
    lib/scanner/vendor/monero/base58.php
    lib/scanner/vendor/monero/Varint.php
    lib/scanner/vendor/monero/load.php

Their headers carry:

    Copyright (c) 2018, Monero Integrations

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.

---

## What is NOT third-party

Everything else — the storefront, catalog, batches, product and image
management, checkout, pay page, admin console, settlement wrapper, cron worker,
and pricing — was written for this store.

`lib/Price.php` is ours. It is not the plugin's pricing code; the plugin fetches
rates through WordPress's HTTP API, which this standalone store does not have.
