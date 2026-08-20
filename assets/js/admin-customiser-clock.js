(function() {
    'use strict';

    if (typeof window.uxstudioServerClock === 'undefined') {
        return;
    }

    var serverTs = parseInt(window.uxstudioServerClock.timestamp, 10) * 1000;
    var clientTs = Date.now();
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function update() {
        var now = new Date(serverTs + (Date.now() - clientTs));
        var timeStr = pad(now.getUTCHours()) + ':' + pad(now.getUTCMinutes()) + ':' + pad(now.getUTCSeconds());
        var dateStr = now.getUTCDate() + ' ' + months[now.getUTCMonth()] + ' ' + now.getUTCFullYear();

        ['uxstudio-server-clock-time', 'uxstudio-server-clock-time-lg'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.textContent = timeStr;
        });

        var dateEl = document.getElementById('uxstudio-server-clock-date');
        if (dateEl) dateEl.textContent = dateStr;
    }

    setInterval(update, 1000);
    update();
})();
