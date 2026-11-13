/* MCCodes engine v3: keeps the notifications bell fresh without reloading the page. */
(function () {
    'use strict';
    var bell = document.getElementById('mcc-bell');
    if (!bell || !window.fetch) { return; }
    var count = bell.querySelector('.bell-n');
    var list = bell.querySelector('.bell-items');
    var title = document.title.replace(/^\(\d+\+?\)\s*/, '');
    var seen = count.hidden ? 0 : parseInt(count.textContent, 10) || 0;

    function show(data) {
        var n = data.total || 0;
        count.hidden = n === 0;
        count.textContent = n > 99 ? '99+' : String(n);
        document.title = (n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' : '') + title;
        while (list.firstChild) { list.removeChild(list.firstChild); }
        if (!data.items || !data.items.length) {
            var none = document.createElement('div');
            none.className = 'bell-empty';
            none.textContent = 'Nothing new.';
            list.appendChild(none);
        } else {
            data.items.forEach(function (item) {
                var a = document.createElement('a');
                a.className = 'bell-item bell-' + (item.type === 'mail' ? 'mail' : 'event');
                a.href = item.type === 'mail' ? 'mailbox.php' : 'events.php';
                var text = document.createElement('span');
                text.textContent = item.text;          // as text, never as HTML
                var ago = document.createElement('small');
                ago.textContent = item.ago;
                a.appendChild(text);
                a.appendChild(ago);
                list.appendChild(a);
            });
        }
        if (n > seen) { bell.classList.add('bell-new'); }
        seen = n;
    }

    function check() {
        if (document.hidden) { return; }
        fetch(bell.getAttribute('data-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.ok) { show(data.data); } })
            .catch(function () { /* offline or logged out: try again next time */ });
    }

    bell.addEventListener('toggle', function () { bell.classList.remove('bell-new'); });
    document.addEventListener('click', function (e) { if (bell.open && !bell.contains(e.target)) { bell.open = false; } });
    document.addEventListener('visibilitychange', check);
    if (seen > 0) { document.title = '(' + (seen > 99 ? '99+' : seen) + ') ' + title; }
    setInterval(check, 30000);
})();
