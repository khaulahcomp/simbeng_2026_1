  </main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sisipkan token CSRF ke semua form POST secara otomatis (termasuk form
// yang dibuat dinamis). Aman untuk form multipart (upload) & submit biasa.
(function () {
  var TOKEN = <?= json_encode(csrf_token()) ?>;
  function inject(form) {
    if (!form || (form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="csrf_token"]')) return;
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'csrf_token'; i.value = TOKEN;
    form.appendChild(i);
  }
  function injectAll() { document.querySelectorAll('form').forEach(inject); }
  injectAll();
  document.addEventListener('DOMContentLoaded', injectAll);
  // Jaring pengaman: pasang token tepat sebelum submit (untuk form dinamis).
  document.addEventListener('submit', function (e) { inject(e.target); }, true);
})();
</script>
</body>
</html>
