(function () {
  var header = document.getElementById('site-header');
  if (!header) return;

  function sync() {
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  }

  sync();
  window.addEventListener('scroll', sync, { passive: true });
})();
