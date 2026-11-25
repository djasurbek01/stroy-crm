import { api } from '../api.js';
import { field, h } from '../dom.js';
import { icon } from '../icons.js';
import { errText } from '../labels.js';

const PASSWORD_HINT = 'Не короче 8 символов, буквы и цифры';

export function authPage(onLogin, { resetToken = null } = {}) {
  let mode = resetToken ? 'reset' : 'register';
  let emailReset = false;
  const box = h('div', { class: 'auth' });

  function showError(form, message) {
    const errorBox = form.querySelector('.error');
    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
  }

  async function submit(event) {
    event.preventDefault();
    const form = event.target;
    const button = form.querySelector('button[type=submit]');
    form.querySelector('.error').classList.add('hidden');
    button.disabled = true;
    const value = (name) => form.elements[name].value.trim();
    try {
      if (mode === 'forgot') {
        await api.post('/auth/forgot', { email: value('email') });
        mode = 'forgot-sent';
        draw();
        return;
      }
      if (mode === 'reset') {
        await api.post('/auth/reset', { token: resetToken, password: form.elements.password.value });
        history.replaceState(null, '', '#/home');
        onLogin(await api.get('/auth/me'), false);
        return;
      }
      const user =
        mode === 'register'
          ? await api.post('/auth/register', {
              org_name: value('org_name'),
              name: value('name'),
              email: value('email'),
              password: form.elements.password.value,
            })
          : await api.post('/auth/login', { email: value('email'), password: form.elements.password.value });
      onLogin(user, mode === 'register');
    } catch (error) {
      showError(form, errText(error));
      button.disabled = false;
    }
  }

  function title() {
    return { register: 'Создать аккаунт', login: 'Войти', forgot: 'Забыли пароль?', reset: 'Новый пароль' }[mode];
  }

  function body() {
    if (mode === 'forgot-sent') {
      return h(
        'div',
        { class: 'auth-card' },
        h('h2', null, 'Проверьте почту'),
        h('p', null, 'Если такой адрес есть в Стройке, мы отправили на него ссылку для нового пароля. Она действует час.'),
        h('p', { class: 'muted' }, 'Письма нет? Проверьте «Спам» или попросите владельца компании задать вам пароль в разделе «Команда».'),
        h('button', { class: 'btn block', type: 'button', onclick: () => { mode = 'login'; draw(); } }, 'Вернуться ко входу'),
      );
    }
    if (mode === 'forgot' && !emailReset) {
      return h(
        'div',
        { class: 'auth-card' },
        h('h2', null, 'Забыли пароль?'),
        h('p', null, 'Попросите владельца вашей компании задать вам новый пароль: раздел «Команда», кнопка «Сменить пароль» рядом с вашим именем.'),
        h('p', { class: 'muted' }, 'Если вы владелец и забыли пароль, обратитесь к тому, кто установил Стройку на сервер: у него есть команда для сброса.'),
        h('button', { class: 'btn block', type: 'button', onclick: () => { mode = 'login'; draw(); } }, 'Вернуться ко входу'),
      );
    }
    const register = mode === 'register';
    const reset = mode === 'reset';
    const forgot = mode === 'forgot';
    return h(
      'form',
      { class: 'auth-card', onsubmit: submit, novalidate: true },
      h('h2', null, title()),
      h('div', { class: 'error hidden', role: 'alert' }),
      register ? field('Название компании', h('input', { name: 'org_name', autocomplete: 'organization', required: true })) : null,
      register ? field('Ваше имя', h('input', { name: 'name', autocomplete: 'name', required: true })) : null,
      reset ? null : field('Email', h('input', { name: 'email', type: 'email', autocomplete: 'email', required: true })),
      forgot
        ? null
        : field(
            reset ? 'Новый пароль' : 'Пароль',
            h('input', { name: 'password', type: 'password', autocomplete: register || reset ? 'new-password' : 'current-password', required: true }),
            register || reset ? PASSWORD_HINT : null,
          ),
      h('button', { class: 'btn primary block', type: 'submit' }, register ? 'Начать' : reset ? 'Сохранить пароль' : forgot ? 'Отправить ссылку' : 'Войти'),
      mode === 'login' ? h('p', { class: 'auth-switch' }, h('button', { class: 'link-btn', id: 'forgot-link', type: 'button', onclick: () => { mode = 'forgot'; draw(); } }, 'Забыли пароль?')) : null,
    );
  }

  function draw() {
    const register = mode === 'register';
    const simple = mode === 'forgot-sent' || mode === 'reset';
    box.replaceChildren(
      h('div', { class: 'brand' }, h('div', { class: 'brand-mark' }, icon('logo', 26)), 'Стройка'),
      h('p', { class: 'muted' }, 'Объекты, договоры, документы и закупки в одном месте. Вместо таблиц Excel.'),
      body(),
      simple
        ? null
        : h(
            'p',
            { class: 'auth-switch' },
            register ? 'Уже есть аккаунт? ' : mode === 'login' ? 'Ещё нет аккаунта? ' : '',
            mode === 'forgot'
              ? h('button', { class: 'link-btn', type: 'button', onclick: () => { mode = 'login'; draw(); } }, 'Вернуться ко входу')
              : h('button', { class: 'link-btn', type: 'button', onclick: () => { mode = register ? 'login' : 'register'; draw(); } }, register ? 'Войти' : 'Создать'),
          ),
    );
  }

  draw();
  const features = [
    ['objects', 'Объекты и график', 'Этапы, сроки с прогнозом и приёмка работ'],
    ['tasks', 'Задачи без потерь', 'Проверка, возврат на доработку и продление через ЛПР'],
    ['money', 'Деньги под контролем', 'Бюджет, платежи из банка и выгрузка для 1С'],
    ['shield', 'Каждому — свой объект', 'Подрядчики видят только свои стройки'],
  ];
  const hero = h(
    'section',
    { class: 'auth-hero', 'aria-hidden': 'true' },
    h('div', { class: 'hero-blob one' }),
    h('div', { class: 'hero-blob two' }),
    h('div', { class: 'brand' }, h('div', { class: 'brand-mark' }, icon('logo', 22)), 'Стройка'),
    h('div', { class: 'hero-copy' },
      h('h1', null, 'Стройка под контролем — от заявки до сдачи объекта'),
      h('p', null, 'Сделки, объекты, документы, закупки и команда в одном окне. Вместо таблиц Excel и переписок в мессенджерах.'),
    ),
    h('ul', { class: 'hero-features' }, features.map(([iconName, title, text]) =>
      h('li', null, h('span', { class: 'hero-icon' }, icon(iconName, 20)), h('span', null, h('strong', null, title), h('small', null, text))))),
  );
  const page = h('div', { class: 'auth-page' }, hero, h('div', { class: 'auth-side' }, box));
  api.get('/auth/config').then((cfg) => {
    emailReset = Boolean(cfg.email_reset);
    if (mode === 'forgot') draw();
  }).catch(() => {});
  return page;
}
