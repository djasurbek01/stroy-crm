// Публичная форма заявки: без входа, работает и во встроенном окне на чужом сайте.
const form = document.getElementById('lead-form');
const errorBox = document.getElementById('lead-error');
const done = document.getElementById('lead-done');
const key = new URLSearchParams(location.search).get('k') || '';

const TEXT = {
  contact_required: 'Оставьте телефон или почту, чтобы мы могли связаться.',
  too_many_attempts: 'Слишком много заявок с вашего адреса. Попробуйте через час.',
  lead_form_not_found: 'Эта форма больше не работает. Позвоните нам по телефону на сайте.',
};

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  errorBox.classList.add('hidden');
  const button = form.querySelector('button[type=submit]');
  const data = Object.fromEntries(new FormData(form).entries());
  if (!String(data.phone || '').replace(/\D/g, '').length && !String(data.message || '').trim()) {
    errorBox.textContent = 'Оставьте телефон и коротко опишите, что нужно.';
    errorBox.classList.remove('hidden');
    return;
  }
  button.disabled = true;
  try {
    const response = await fetch(`/public/leads/${encodeURIComponent(key)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: data.name || '', phone: data.phone || '', message: data.message || '', website: data.website || '' }),
    });
    if (!response.ok) {
      let code = '';
      try { code = (await response.json()).detail; } catch { code = ''; }
      throw new Error(TEXT[code] || 'Не получилось отправить. Попробуйте ещё раз или позвоните нам.');
    }
    form.classList.add('hidden');
    done.classList.remove('hidden');
  } catch (error) {
    errorBox.textContent = error.message === 'Failed to fetch' ? 'Нет связи. Проверьте интернет и повторите.' : error.message;
    errorBox.classList.remove('hidden');
    button.disabled = false;
  }
});
