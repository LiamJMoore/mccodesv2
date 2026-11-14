/* MCCodes engine v3: keeps the sidebar (money, crystals, level, bars) fresh without reloading the page.
   It listens for the answer js/notify.js already fetches, so it adds no requests of its own. */
(function () {
    'use strict';
    function set(el, text) {
        if (!el || el.textContent === text) { return; }
        el.textContent = text;
        el.classList.remove('live-changed');
        void el.offsetWidth;                    // restart the highlight
        el.classList.add('live-changed');
    }
    document.addEventListener('mcc:live', function (e) {
        var d = e.detail;
        if (!d || !d.bars) { return; }
        set(document.querySelector('[data-live="money"]'), d.money_text);
        set(document.querySelector('[data-live="crystals"]'), String(d.crystals));
        set(document.querySelector('[data-live="level"]'), String(d.level));
        Object.keys(d.bars).forEach(function (name) {
            var bar = document.querySelector('[data-live-bar="' + name + '"]');
            if (!bar) { return; }
            set(bar.querySelector('em'), d.bars[name].label);
            var fill = bar.querySelector('i');
            if (fill) { fill.style.setProperty('--v', d.bars[name].percent + '%'); }
        });
    });
})();
