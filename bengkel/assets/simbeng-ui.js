/* Simbeng UI: interaksi tampilan saja (bilah progres balap + tombol ke atas). */
(function () {
  'use strict';
  // ---- Bilah progres: tampil saat pindah halaman atau menyimpan form ----
  var bar = document.createElement('div');
  bar.id = 'sbProgress';
  document.body.appendChild(bar);
  var timer = null;
  function start() {
    clearTimeout(timer);
    bar.className = '';
    void bar.offsetWidth;                      // reset animasi
    bar.className = 'run';
    timer = setTimeout(finish, 9000);          // jaring pengaman (mis. unduhan file tidak memindah halaman)
  }
  function finish() { clearTimeout(timer); bar.className = 'done'; }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download') || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-no-progress')) return;
    if (/export\.php|backup\.php|\.(pdf|xlsx?|docx?|csv|zip)(\?|$)/i.test(href)) return;   // unduhan
    if (a.host && a.host !== location.host) return;
    start();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented) return;            // mis. dibatalkan oleh dialog konfirmasi
    if (f && f.target && f.target !== '_self') return;
    if (f && /export\.php|backup\.php/i.test(f.getAttribute('action') || '')) return;
    start();
  });
  window.addEventListener('pageshow', finish); // tombol Back/Forward (bfcache)

  // ---- Tombol kembali ke atas ----
  var top = document.createElement('button');
  top.type = 'button'; top.className = 'sb-top'; top.title = 'Kembali ke atas';
  top.setAttribute('aria-label', 'Kembali ke atas'); top.setAttribute('data-testid', 'back-to-top');
  top.innerHTML = '<i class="fa-solid fa-angles-up" aria-hidden="true"></i>';
  document.body.appendChild(top);
  function toggleTop() { top.classList.toggle('show', (window.pageYOffset || document.documentElement.scrollTop) > 360); }
  window.addEventListener('scroll', toggleTop, { passive: true });
  top.addEventListener('click', function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
  });
  toggleTop();
})();
