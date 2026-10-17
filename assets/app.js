document.addEventListener('DOMContentLoaded', () => {
  const themeToggle = document.querySelector('.theme-toggle');
  const applyTheme = (theme, persist = false) => {
    const selectedTheme = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.theme = selectedTheme;
    if (themeToggle) {
      const nextLabel = selectedTheme === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap';
      themeToggle.setAttribute('aria-label', nextLabel);
      themeToggle.setAttribute('title', nextLabel);
      themeToggle.setAttribute('aria-pressed', selectedTheme === 'dark' ? 'true' : 'false');
      const icon = themeToggle.querySelector('span');
      if (icon) icon.textContent = selectedTheme === 'dark' ? '☀' : '☾';
    }
    if (persist) {
      try { document.cookie = 'desa-theme=' + selectedTheme + '; Max-Age=31536000; Path=/; SameSite=Lax'; } catch {}
    }
  };
  const initialTheme = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
  applyTheme(initialTheme);
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
  }

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
      navToggle.setAttribute('aria-label', opening
        ? (navToggle.getAttribute('data-close-label') || 'Tutup menu tambahan')
        : (navToggle.getAttribute('data-open-label') || 'Buka menu tambahan'));
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

  // Hero previews mirror the focal point saved by the admin.
  const focusInputs = Array.from(document.querySelectorAll('[data-focus-key]'));
  const getFocus = (key) => {
    const field = focusInputs.find((input) => input.getAttribute('data-focus-key') === key);
    return field ? Math.max(0, Math.min(100, Number(field.value) || 0)) : 50;
  };
  const updateHeroFocus = () => {
    const x = getFocus('hero_focus_x');
    const y = getFocus('hero_focus_y');
    document.querySelectorAll('[data-preview-hero-image]').forEach((img) => {
      img.style.objectPosition = x + '% ' + y + '%';
    });
    focusInputs.forEach((input) => {
      const key = input.getAttribute('data-focus-key');
      const output = document.querySelector('[data-focus-value="' + key + '"]');
      if (output) output.textContent = input.value;
    });
  };
  focusInputs.forEach((input) => input.addEventListener('input', updateHeroFocus));
  updateHeroFocus();

  // Keep the large public-copy editor practical: filter settings by their label/key.
  const siteSettingFilter = document.querySelector('[data-site-setting-filter]');
  if (siteSettingFilter) {
    const settingElements = Array.from(document.querySelectorAll('[data-site-setting]'));
    const sectionHeadings = Array.from(document.querySelectorAll('#site-content-form .cms-subheading'));
    const countElement = document.getElementById('site-setting-filter-count');
    const filterSettings = () => {
      const query = siteSettingFilter.value.trim().toLocaleLowerCase();
      let visible = 0;
      settingElements.forEach((element) => {
        const haystack = ((element.getAttribute('data-setting-key') || '') + ' ' + (element.textContent || '')).toLocaleLowerCase();
        const shown = query === '' || haystack.includes(query);
        element.hidden = !shown;
        const preview = element.nextElementSibling;
        if (preview && preview.classList.contains('cms-image-preview')) preview.hidden = !shown;
        if (shown) visible++;
      });
      sectionHeadings.forEach((heading) => { heading.hidden = query !== ''; });
      if (countElement) countElement.textContent = query === '' ? 'Semua pengaturan ditampilkan.' : visible + ' pengaturan cocok.';
    };
    siteSettingFilter.addEventListener('input', filterSettings);
  }

  // Preview each replacement image before it is uploaded.
  const imagePreviewUrls=new Map();
  document.querySelectorAll('[data-preview-image-key]').forEach((input)=>{
    input.addEventListener('change',()=>{
      const file=input.files&&input.files[0],key=input.getAttribute('data-preview-image-key');
      if(!file||!key||!file.type.startsWith('image/'))return;
      const oldUrl=imagePreviewUrls.get(key);if(oldUrl)URL.revokeObjectURL(oldUrl);
      const url=URL.createObjectURL(file);imagePreviewUrls.set(key,url);
      document.querySelectorAll('[data-preview-site-image="'+key+'"]').forEach((img)=>{img.src=url;img.hidden=false;});
      if(key==='hero_image')document.querySelectorAll('[data-preview-hero-image]').forEach((img)=>{img.src=url;});
    });
  });
  document.querySelectorAll('[data-preview-site-image]').forEach((img)=>img.addEventListener('error',()=>{img.hidden=true;}));

  // Preview text uses textContent to avoid injecting HTML.
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
