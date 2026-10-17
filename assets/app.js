document.addEventListener('DOMContentLoaded', () => {
  const trackInputs = document.querySelectorAll('input[name="code"]');
  trackInputs.forEach((input) => {
    input.addEventListener('input', () => {
      input.value = input.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 32);
    });
  });

  document.querySelectorAll('form[enctype="multipart/form-data"]').forEach((form) => {
    const file = form.querySelector('input[type="file"]');
    if (!file) return;
    file.addEventListener('change', () => {
      if (!file.files || !file.files[0]) return;
      const selected = file.files[0];
      const allowed = ['image/jpeg', 'image/png', 'image/webp'];
      if (!allowed.includes(selected.type) || selected.size > 5 * 1024 * 1024) {
        file.value = '';
        window.alert('Pilih gambar JPG, PNG, atau WebP dengan ukuran maksimal 5 MB.');
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
});
