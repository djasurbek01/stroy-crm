// Профиль: смена пароля и выход на всех устройствах.
import { api } from '../api.js';
import { field, h, toast } from '../dom.js';
import { ROLES, errText } from '../labels.js';
import { can } from '../perm.js';

export async function profilePage(ctx) {
  const me = ctx.me;
  const company = can(ctx, 'deals.write') ? await api.get('/company') : null;
  const form = h(
    'form',
    { class: 'card', novalidate: true, id: 'password-form' },
    h('h2', null, 'Сменить пароль'),
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Текущий пароль', h('input', { name: 'old_password', type: 'password', autocomplete: 'current-password', required: true })),
    field('Новый пароль', h('input', { name: 'new_password', type: 'password', autocomplete: 'new-password', required: true }), 'Не короче 8 символов, буквы и цифры'),
    h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить пароль'),
    h('p', { class: 'muted' }, 'После смены пароля вход на других устройствах закроется.'),
  );
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    try {
      await api.post('/auth/password', { old_password: form.elements.old_password.value, new_password: form.elements.new_password.value });
      form.reset();
      toast('Пароль изменён');
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });

  return h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Профиль'), h('p', { class: 'muted' }, `${me.name} · ${me.email} · ${ROLES[me.role] || me.role}`))),
    h('div', { class: 'two-cards' }, form, company ? companyCard(ctx, company) : null, h(
      'div',
      { class: 'card' },
      h('h2', null, 'Безопасность'),
      h('p', null, 'Если вы входили с чужого или потерянного устройства, завершите вход везде, кроме этого.'),
      h('button', { class: 'btn', id: 'logout-all', type: 'button', onclick: async () => {
        try { await api.post('/auth/logout-all'); toast('Вход на других устройствах закрыт'); } catch (error) { toast(errText(error), 'bad'); }
      } }, 'Выйти на всех других устройствах'),
    )),
  );
}

function companyCard(ctx, company) {
  const owner = can(ctx, 'settings.manage');
  const form = h(
    'form',
    { class: 'card', novalidate: true, id: 'company-form' },
    h('h2', null, 'Компания в предложениях'),
    h('p', { class: 'muted' }, 'Эти данные клиент увидит в шапке коммерческого предложения.'),
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Телефон', h('input', { name: 'phone', value: company.phone, disabled: !owner })),
    field('Почта', h('input', { name: 'email', type: 'email', value: company.email, disabled: !owner })),
    field('Реквизиты', h('textarea', { name: 'details', rows: '3', disabled: !owner }, company.details), 'ИНН, адрес, банк: что хотите показать клиенту'),
    owner ? h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить') : h('p', { class: 'muted' }, 'Менять реквизиты может владелец.'),
  );
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    try {
      await api.put('/company', { phone: form.elements.phone.value, email: form.elements.email.value, details: form.elements.details.value });
      toast('Реквизиты сохранены');
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
  return form;
}
