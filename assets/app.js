document.addEventListener('DOMContentLoaded', () => {
  const trackInputs = document.querySelectorAll('input[name="code"]');
  trackInputs.forEach((input) => {
    input.addEventListener('input', () => {
      input.value = input.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 32);
    });
  });

  // One handler per upload field; the server remains the security authority.
  document.querySelectorAll('form[enctype="multipart/form-data"] input[type="file"]').forEach((file) => {
    file.addEventListener('change', () => {
      if (!file.files || !file.files[0]) return;
      const selected = file.files[0];
      const maxBytes = file.name === 'report_pdf' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
      const accepted = (file.getAttribute('accept') || '')
        .split(',')
        .map((entry) => entry.trim().toLowerCase())
        .filter(Boolean);
      const allowed = accepted.length === 0 || accepted.some((entry) => {
        if (entry.startsWith('.')) return selected.name.toLowerCase().endsWith(entry);
        if (entry.endsWith('/*')) return selected.type.startsWith(entry.slice(0, -1));
        return selected.type.toLowerCase() === entry;
      });
      if (!allowed || selected.size < 1 || selected.size > maxBytes) {
        file.value = '';
        const limit = file.name === 'report_pdf' ? '10 MB' : '5 MB';
        window.alert('Format atau ukuran file tidak sesuai. Ukuran maksimum ' + limit + '.');
      }
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!window.confirm(button.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?')) {
        event.preventDefault();
      }
    });
  });

  const navToggle = document.querySelector('.mobile-menu-toggle');
  const mobileDrawer = document.getElementById('mobile-drawer');
  if (navToggle && mobileDrawer) {
    navToggle.addEventListener('click', () => {
      const opening = mobileDrawer.hasAttribute('hidden');
      if (opening) mobileDrawer.removeAttribute('hidden');
      else mobileDrawer.setAttribute('hidden', '');
      navToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      navToggle.setAttribute('aria-label', opening ? 'Tutup menu tambahan' : 'Buka menu tambahan');
    });
    document.addEventListener('click', (event) => {
      if (!mobileDrawer.contains(event.target) && !navToggle.contains(event.target)) {
        mobileDrawer.setAttribute('hidden', '');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Buka menu tambahan');
      }
    });
  }

  const desktopMore = document.querySelector('.nav-more');
  const desktopMoreButton = document.querySelector('.nav-more-button');
  if (desktopMore && desktopMoreButton) {
    desktopMoreButton.addEventListener('click', () => {
      const opening = !desktopMore.classList.contains('is-open');
      desktopMore.classList.toggle('is-open', opening);
      desktopMoreButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
    document.addEventListener('click', (event) => {
      if (!desktopMore.contains(event.target)) {
        desktopMore.classList.remove('is-open');
        desktopMoreButton.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Preview is text-only and uses textContent to avoid injecting HTML.
  document.querySelectorAll('[data-preview-key]').forEach((input) => {
    const key = input.getAttribute('data-preview-key');
    if (!key) return;
    input.addEventListener('input', () => {
      document.querySelectorAll('[data-preview-target="' + key + '"]').forEach((target) => {
        target.textContent = input.value || '';
      });
    });
  });
});
