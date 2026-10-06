(function () {
    document.documentElement.classList.add('js');
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var items = document.querySelectorAll('.reveal');
    if (!reduce && 'IntersectionObserver' in window && items.length) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        items.forEach(function (el) { io.observe(el); });
    } else {
        items.forEach(function (el) { el.classList.add('in-view'); });
    }

    var grid = document.getElementById('featureGrid');
    if (!grid) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.feature-card'));
    var navBtns = Array.prototype.slice.call(document.querySelectorAll('.docs-nav button'));
    var search = document.getElementById('featureSearch');
    var none = document.getElementById('noResults');
    var group = 'all';
    function apply() {
        var q = search.value.trim().toLowerCase();
        var shown = 0;
        cards.forEach(function (c) {
            var ok = (group === 'all' || c.dataset.group === group) && (q === '' || c.textContent.toLowerCase().indexOf(q) !== -1);
            c.hidden = !ok;
            if (ok) shown++;
        });
        none.style.display = shown ? 'none' : 'block';
    }
    navBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            group = b.dataset.group;
            navBtns.forEach(function (x) { x.classList.toggle('active', x === b); });
            apply();
        });
    });
    search.addEventListener('input', apply);
})();
