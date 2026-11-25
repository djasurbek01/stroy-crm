import { api } from '../api.js';
import { badge, field, h, modal, select, toast } from '../dom.js';
import { PLAN_NAMES, ROLES, errText } from '../labels.js';
import { can } from '../perm.js';

const USER_LIMITS = { start: 5, business: 100 };
const ROLE_HELP = {
  manager: 'сделки, контакты, аналитика',
  foreman: 'документы, закупки, задачи на объекте',
  buyer: 'закупки, договоры, документы',
  accountant: 'платежи, выписки, выгрузка для 1С',
  pm: 'объекты, график, задачи, приёмка, бюджет',
  storekeeper: 'склад: приход, выдача, остатки',
  director: 'свои объекты: задачи, приёмка работ, продления сроков',
  contractor: 'свои объекты: задачи, фото, чат',
};

async function telegramCard() {
  const status = await api.get('/telegram/status');
  const box = h('div', { class: 'card', style: 'margin-bottom:20px' }, h('h2', null, 'Уведомления в Telegram'));
  async function link() {
    try {
      const { link: url } = await api.post('/telegram/link');
      window.open(url, '_blank', 'noopener');
      toast('Откройте Telegram и нажмите «Запустить». Потом обновите эту страницу.');
    } catch (error) {
      toast(errText(error), 'bad');
    }
  }
  if (!status.enabled) {
    box.append(h('p', { class: 'muted' }, 'Сюда будут приходить сроки, согласования и оплаты. Подключение к Telegram пока не настроено.'));
  } else if (status.linked) {
    box.append(
      h('p', null, 'Подключено. Сюда приходят согласования, оплаты по счетам и сводка по срокам.'),
      h('button', { class: 'btn small', type: 'button', onclick: async () => { await api.del('/telegram/link'); toast('Отключено'); box.replaceWith(await telegramCard()); } }, 'Отключить'),
    );
  } else {
    box.append(
      h('p', { class: 'muted' }, 'Получайте в Telegram: что ждёт вашего согласования, оплаты по счетам и сводку по срокам.'),
      h('button', { class: 'btn primary', id: 'tg-link', type: 'button', onclick: link }, 'Подключить Telegram'),
    );
  }
  return box;
}

function passwordModal(member) {
  const form = h(
    'form',
    { novalidate: true },
    h('p', { class: 'muted' }, `Новый пароль для сотрудника ${member.name}. Передайте его лично: вход на других устройствах сотрудника закроется.`),
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Новый пароль', h('input', { name: 'password', type: 'text', autocomplete: 'off', required: true }), 'Не короче 8 символов, буквы и цифры'),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сменить пароль')),
  );
  const dialog = modal(`Пароль: ${member.name}`, form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    try {
      await api.post(`/team/${member.id}/password`, { password: form.elements.password.value });
      dialog.close();
      toast('Пароль изменён');
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

async function peoplePage(ctx) {
  const members = await api.get('/team');
  const tgCard = await telegramCard();
  const plan = ctx.me.plan;
  const manage = can(ctx, 'team.manage');
  const page = h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h(
        'div',
        null,
        h('h1', { class: 'page-title' }, 'Команда'),
        h('p', { class: 'muted' }, `Пакет «${PLAN_NAMES[plan] || plan}»: сотрудников ${members.length} из ${USER_LIMITS[plan] || '—'}. `, h('a', { href: '#/plans' }, 'О пакетах')),
      ),
    ),
    h(
      'div',
      { class: 'list', style: 'margin-bottom:20px' },
      members.map((m) =>
        h(
          'div',
          { class: 'item' },
          h('div', null, h('div', { class: 'title' }, m.name), h('div', { class: 'meta' }, m.email)),
          h(
            'div',
            { class: 'side-info' },
            badge(ROLES[m.role] || m.role, m.role === 'owner' ? 'orange' : ''),
            manage && m.id !== ctx.me.id ? h('button', { class: 'btn small', type: 'button', onclick: () => passwordModal(m) }, 'Сменить пароль') : null,
          ),
        ),
      ),
    ),
  );

  page.append(tgCard);

  if (!manage) {
    page.append(h('p', { class: 'muted' }, 'Добавлять сотрудников может владелец.'));
    return page;
  }

  const roleOptions = Object.fromEntries(Object.entries(ROLES).filter(([key]) => key !== 'owner').map(([key, name]) => [key, `${name}: ${ROLE_HELP[key]}`]));
  const form = h(
    'form',
    { class: 'card', novalidate: true },
    h('h2', null, 'Добавить сотрудника'),
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Имя', h('input', { name: 'name', required: true })),
    field('Email', h('input', { name: 'email', type: 'email', required: true })),
    field('Роль', select(roleOptions, 'foreman', { name: 'role' }), 'От роли зависит, что сотрудник видит и может делать'),
    field('Пароль для входа', h('input', { name: 'password', type: 'text', autocomplete: 'off', required: true }), 'Не короче 8 символов, буквы и цифры. Передайте его сотруднику, потом он сам сменит в профиле.'),
    h('button', { class: 'btn primary', type: 'submit' }, 'Добавить'),
  );
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    try {
      await api.post('/team', {
        name: form.elements.name.value.trim(),
        email: form.elements.email.value.trim(),
        role: form.elements.role.value,
        password: form.elements.password.value,
      });
      toast('Сотрудник добавлен');
      ctx.go('#/team');
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
  page.append(form);
  return page;
}

/** Права ролей: владелец включает и выключает разделы и действия для каждой роли. */
async function permissionsView() {
  let data = await api.get('/team/permissions');
  let matrix = structuredClone(data.matrix);
  const page = h('div', { class: 'perm-page' });
  const bar = h('div', { class: 'perm-bar hidden', role: 'status' });

  const changes = () => {
    let count = 0;
    for (const role of Object.keys(matrix)) for (const cap of Object.keys(matrix[role])) if (matrix[role][cap] !== data.matrix[role][cap]) count += 1;
    return count;
  };
  const differsFromDefault = (role, cap) => matrix[role][cap] !== data.defaults[role][cap];

  async function save(body, message) {
    try {
      data = await api.put('/team/permissions', { matrix: body });
      matrix = structuredClone(data.matrix);
      toast(message);
      draw();
    } catch (e) { toast(errText(e), 'bad'); }
  }

  function drawBar() {
    const n = changes();
    bar.classList.toggle('hidden', n === 0);
    bar.replaceChildren(
      h('span', null, `Изменений: ${n}`),
      h('button', { class: 'btn small', type: 'button', onclick: () => { matrix = structuredClone(data.matrix); draw(); } }, 'Отменить'),
      h('button', { class: 'btn small primary', id: 'save-permissions', type: 'button', onclick: () => save(matrix, 'Права сохранены. Сотрудники увидят их после обновления страницы.') }, 'Сохранить'),
    );
  }

  function cell(role, cap, label) {
    const input = h('input', { type: 'checkbox', checked: matrix[role][cap], 'aria-label': label, onchange: (e) => {
      matrix[role][cap] = e.target.checked;
      e.target.closest('td').classList.toggle('custom', differsFromDefault(role, cap));
      drawBar();
    } });
    return h('td', { class: 'perm-cell' + (differsFromDefault(role, cap) ? ' custom' : ''), title: differsFromDefault(role, cap) ? 'Отличается от стандартных прав роли' : null }, h('label', { class: 'perm-toggle' }, input, h('span', null)));
  }

  function draw() {
    const custom = Object.keys(matrix).reduce((sum, role) => sum + Object.keys(matrix[role]).filter((cap) => differsFromDefault(role, cap)).length, 0);
    page.replaceChildren(
      h('div', { class: 'notice' }, 'Отметьте, что видит и может делать каждая роль. Владелец всегда видит и может всё. Сервер проверяет эти права при каждом действии. Сотрудники увидят изменения после обновления страницы.'),
      h('div', { class: 'perm-tools' },
        h('span', { class: 'muted' }, custom ? `Изменено относительно стандарта: ${custom}. Такие клетки отмечены точкой.` : 'Сейчас у всех ролей стандартные права.'),
        custom ? h('button', { class: 'btn small bad', type: 'button', onclick: () => confirmReset() }, 'Вернуть стандартные права') : null,
      ),
      h('div', { class: 'table-wrap perm-wrap' }, h('table', { class: 'data perm-table' },
        h('thead', null, h('tr', null,
          h('th', { class: 'perm-name' }, 'Право'),
          data.roles.map((r) => h('th', null, h('span', { class: 'perm-role' }, r.name), h('small', null, r.people ? `${r.people} чел.` : 'нет людей'))),
        )),
        data.groups.map((group) => h('tbody', null,
          h('tr', { class: 'perm-group' }, h('th', { colspan: String(data.roles.length + 1) }, group.title)),
          group.caps.map((cap) => h('tr', null, h('td', { class: 'perm-name' }, cap.label), data.roles.map((r) => cell(r.key, cap.key, `${cap.label} — ${r.name}`)))),
        )),
      )),
      bar,
    );
    drawBar();
  }

  function confirmReset() {
    const dialog = modal('Вернуть стандартные права?', h('div', null,
      h('p', null, 'Все роли получат права, которые были изначально. Ваши настройки будут удалены.'),
      h('div', { class: 'actions' },
        h('button', { class: 'btn bad', type: 'button', onclick: () => { dialog.close(); save(data.defaults, 'Возвращены стандартные права'); } }, 'Вернуть'),
        h('button', { class: 'btn', type: 'button', onclick: () => dialog.close() }, 'Отмена'),
      ),
    ));
  }

  draw();
  return page;
}

export async function teamPage(ctx) {
  const manage = can(ctx, 'team.manage');
  const tab = manage && location.hash.startsWith('#/team/permissions') ? 'permissions' : 'people';
  const switcher = manage
    ? h('div', { class: 'seg team-switch', role: 'tablist' },
        h('a', { class: 'seg-btn' + (tab === 'people' ? ' on' : ''), href: '#/team', role: 'tab', 'aria-selected': String(tab === 'people') }, 'Сотрудники'),
        h('a', { class: 'seg-btn' + (tab === 'permissions' ? ' on' : ''), href: '#/team/permissions', id: 'tab-permissions', role: 'tab', 'aria-selected': String(tab === 'permissions') }, 'Права ролей'))
    : null;
  if (tab === 'people') {
    const page = await peoplePage(ctx);
    if (switcher) page.firstChild.after(switcher);
    return page;
  }
  return h('div', null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Команда'), h('p', { class: 'muted' }, 'Права ролей: какие разделы видит и что может делать каждая роль.'))),
    switcher,
    await permissionsView(),
  );
}
