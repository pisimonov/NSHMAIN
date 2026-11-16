/* Глобальное меню отчётов: открытие по клику, закрытие по Esc и клику вне меню, навигация стрелками. */
(function () {
  var nav = document.getElementById('gnav'); if (!nav) return;
  var btn = nav.querySelector('.gnav-btn'), menu = nav.querySelector('.gnav-menu');
  var items = function () { return Array.prototype.slice.call(menu.querySelectorAll('a')); };
  var open = function (v) {
    menu.hidden = !v; btn.setAttribute('aria-expanded', String(v));
    if (v) (menu.querySelector('a[aria-current="page"]') || items()[0]).focus();
  };
  btn.addEventListener('click', function (e) { e.stopPropagation(); open(menu.hidden); });
  document.addEventListener('click', function (e) { if (!nav.contains(e.target)) open(false); });
  nav.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { open(false); btn.focus(); }
    if (!menu.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault(); var l = items(), i = l.indexOf(document.activeElement);
      l[(i + (e.key === 'ArrowDown' ? 1 : -1) + l.length) % l.length].focus();
    }
  });
})();
