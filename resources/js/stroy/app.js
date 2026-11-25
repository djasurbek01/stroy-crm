import { api } from './api.js';
import { h, modal, store, toast } from './dom.js';
import { icon } from './icons.js';
import { can } from './perm.js';
import { ROLES, errText } from './labels.js';
import { searchBox } from './search.js';
import { analyticsPage } from './pages/analytics.js';
import { auditPage } from './pages/audit.js';
import { authPage } from './pages/auth.js';
import { boardsPage } from './pages/boards.js';
import { contactPage, contactsPage } from './pages/contacts.js';
import { dealPage } from './pages/dealpage.js';
import { dealsPage } from './pages/deals.js';
import { expensesPage } from './pages/expenses.js';
import { homePage } from './pages/home.js';
import { bellButton, notificationsPage } from './pages/notifications.js';
import { portfolioPage } from './pages/portfolio.js';
import { warehousePage } from './pages/warehouse.js';
import { paymentsPage } from './pages/payments.js';
import { plansPage } from './pages/plans.js';
import { profilePage } from './pages/profile.js';
import { projectPage } from './pages/project.js';
import { projectsPage } from './pages/projects.js';
import { tasksPage } from './pages/tasks.js';
import { teamPage } from './pages/team.js';
import { timesheetPage } from './pages/timesheet.js';
import { trashPage } from './pages/trash.js';
import { QUEUE_EVENT, discard, pending, retry, startAutoFlush } from './offline.js';
import { startTour } from './tour.js';

const root = document.getElementById('app');
const state = { me: null };
let renderToken = 0;

const ctx = {
  get me() {
    return state.me;
  },
  go(hash) {
    if (location.hash === hash) render();
    else location.hash = hash;
  },
  startTour: () => startTour(ctx),
};

// Разделы меню по смыслу. Пустой заголовок: пункт без группы (главная).
const NAV_GROUPS = [
  ['', ''],
  ['sales', 'Продажи'],
  ['site', 'Стройка'],
  ['supply', 'Снабжение'],
  ['money', 'Финансы'],
  ['company', 'Компания'],
];

// [ключ, ссылка, подпись, значок, класс, право, группа, подсказка].
// desk-only: на телефоне такие пункты уходят в «Ещё». Подсказка объясняет, что лежит в разделе.
const NAV = [
  ['home', '#/home', 'Главная', 'home', '', 'home.read', '', 'Главное на сегодня: сделки, задачи, документы и деньги'],
  ['deals', '#/deals', 'Сделки', 'deals', '', 'crm.read', 'sales', 'Воронка продаж: от заявки клиента до оплаты'],
  ['contacts', '#/contacts', 'Контакты', 'contacts', 'desk-only', 'contacts.read', 'sales', 'Заказчики, подрядчики и поставщики с историей'],
  ['analytics', '#/analytics', 'Аналитика', 'analytics', 'desk-only', 'analytics.read', 'sales', 'Конверсия по этапам и план продаж'],
  ['objects', '#/objects', 'Объекты', 'objects', '', '', 'site', 'Стройки: график, задачи, документы, приёмка, финансы'],
  ['tasks', '#/tasks', 'Мои задачи', 'tasks', '', '', 'site', 'Мои дела и документы, которые ждут моего решения'],
  ['portfolio', '#/portfolio', 'Панель руководителя', 'panel', 'desk-only', 'portfolio.read', 'site', 'Все объекты на одном экране: сроки, проблемы, деньги'],
  ['warehouse', '#/warehouse', 'Склад', 'warehouse', 'desk-only', 'materials.read', 'supply', 'Остатки, приход, выдача на объекты, заявки на материалы'],
  ['boards', '#/boards', 'Закупки и договоры', 'boards', 'desk-only', 'boards.read', 'supply', 'Доски закупок, договоров и документов по всем объектам'],
  ['payments', '#/payments', 'Платежи', 'money', 'desk-only', 'money.read', 'money', 'Банковская выписка и оплата счетов'],
  ['expenses', '#/expenses', 'Траты', 'money', 'desk-only', 'money.read', 'money', 'Чеки по объектам и статьям расходов'],
  ['timesheet', '#/timesheet', 'Табель', 'team', 'desk-only', 'timesheet.read', 'money', 'Часы рабочих по объектам и зарплата'],
  ['team', '#/team', 'Команда', 'team', 'desk-only', 'team.read', 'company', 'Сотрудники, роли и подключение Telegram'],
  ['audit', '#/audit', 'Журнал действий', 'audit', 'desk-only', 'audit.read', 'company', 'Кто, что и когда менял'],
  ['trash', '#/trash', 'Корзина', 'trash', 'desk-only', 'trash.manage', 'company', 'Удалённое: можно восстановить в течение 30 дней'],
  ['plans', '#/plans', 'Тариф', 'plans', 'desk-only', 'plans.read', 'company', 'Ваш пакет и его ограничения'],
];
const visibleNav = () => NAV.filter(([, , , , , cap]) => !cap || can(ctx, cap));

/** Видимые пункты меню, разложенные по группам (пустые группы не показываются). */
function navGroups(items) {
  return NAV_GROUPS.map(([key, title]) => [title, items.filter((item) => item[6] === key)]).filter(([, list]) => list.length);
}
const ROUTE_CAPS = { deals: 'crm.read', deal: 'crm.read', contacts: 'contacts.read', contact: 'contacts.read', boards: 'boards.read', team: 'team.read', plans: 'plans.read', portfolio: 'portfolio.read', warehouse: 'materials.read', payments: 'money.read', expenses: 'money.read', timesheet: 'timesheet.read', analytics: 'analytics.read', audit: 'audit.read', trash: 'trash.manage' };
const ACTIVE_FOR = { deal: 'deals', project: 'objects', contact: 'contacts' };

function parseRoute() {
  const parts = location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
  return { name: parts[0] || 'home', id: parts[1], tab: parts[2] };
}

function logout() {
  api.post('/auth/logout').finally(() => {
    state.me = null;
    location.hash = '#/';
    render();
  });
}

// --- документы, ждущие связи ---
function queueBadge() {
  const button = h('button', { class: 'queue-badge hidden', id: 'queue-badge', type: 'button', onclick: openQueue }, '');
  async function refresh() {
    let items = [];
    try { items = await pending(); } catch { items = []; }
    button.textContent = items.length ? `Ждёт отправки: ${items.length}` : '';
    button.classList.toggle('hidden', !items.length);
    button.classList.toggle('bad', items.some((i) => i.state === 'failed'));
  }
  window.addEventListener(QUEUE_EVENT, refresh);
  refresh();
  return button;
}

async function openQueue() {
  const body = h('div');
  const dialog = modal('Документы без связи', body);
  async function draw() {
    const items = await pending();
    if (!items.length) { dialog.close(); return; }
    body.replaceChildren(
      h('p', { class: 'muted' }, 'Эти документы сохранены на телефоне и уйдут на сервер, когда появится интернет.'),
      h('div', { class: 'list' }, items.map((i) => h('div', { class: 'item' },
        h('div', null, h('div', { class: 'title' }, i.fields.title || i.name), h('div', { class: 'meta' }, `${i.projectName || 'Объект'} · ${i.state === 'failed' ? 'не отправлено: ' + errText({ code: i.error }) : 'ждёт связи'}`)),
        h('div', { class: 'side-info' },
          i.state === 'failed' ? h('button', { class: 'btn small', type: 'button', onclick: async () => { await retry(i.id); draw(); } }, 'Повторить') : null,
          h('button', { class: 'btn small bad', type: 'button', onclick: async () => { await discard(i.id); draw(); } }, 'Удалить'),
        )))),
    );
  }
  await draw();
  const refresh = () => draw();
  window.addEventListener(QUEUE_EVENT, refresh);
}

function offlineScreen() {
  return h('div', { class: 'auth' }, h('div', { class: 'brand' }, h('div', { class: 'brand-mark' }, icon('logo', 26)), 'Стройка'), h('div', { class: 'auth-card' }, h('h2', null, 'Нет связи'), h('p', null, 'Приложение откроется, как только появится интернет. Документы, которые вы уже сохранили на телефоне, не пропадут: они отправятся сами.'), h('button', { class: 'btn primary block', id: 'retry-online', type: 'button', onclick: () => location.reload() }, 'Повторить')));
}

/** Инициалы для аватара: «Иван Петров» → «ИП». */
function initials(name) {
  return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
}

function moreMenu() {
  const links = visibleNav().filter((item) => item[4] === 'desk-only');
  const body = h(
    'div',
    { class: 'more-menu' },
    navGroups(links).map(([title, list]) => [
      title ? h('div', { class: 'more-caption' }, title) : null,
      list.map(([, href, label, iconName]) => h('a', { href, class: 'more-link', onclick: () => dialog.close() }, icon(iconName), label)),
    ]),
    h('div', { class: 'more-caption' }, 'Личное'),
    h('a', { href: '#/profile', class: 'more-link', onclick: () => dialog.close() }, icon('contacts'), 'Профиль и пароль'),
    h('button', { class: 'more-link', type: 'button', onclick: () => { dialog.close(); startTour(ctx); } }, icon('help'), 'Обучение'),
    h('button', { class: 'more-link', type: 'button', onclick: logout }, icon('logout'), 'Выйти'),
  );
  const dialog = modal('Разделы', body);
}

function shell(routeName) {
  const activeKey = ACTIVE_FOR[routeName] || routeName;
  const view = h('main', { class: 'main', id: 'view', tabindex: '-1' }, h('div', { class: 'loading' }, 'Загружаем…'));
  const nav = h(
    'nav',
    { class: 'nav', 'aria-label': 'Основное меню' },
    navGroups(visibleNav()).map(([title, list]) =>
      h(
        'div',
        { class: 'nav-group', role: 'group', 'aria-label': title || 'Главная' },
        title ? h('div', { class: 'nav-caption' }, title) : null,
        list.map(([key, href, label, iconName, extra, , , hint]) =>
          h('a', { href, title: hint ? `${label}: ${hint}` : label, class: (key === activeKey ? 'active ' : '') + (extra || ''), 'aria-current': key === activeKey ? 'page' : null }, icon(iconName), h('span', null, label)),
        ),
      ),
    ),
    h('button', { class: 'more-btn', type: 'button', onclick: moreMenu }, icon('more'), h('span', null, 'Ещё')),
  );
  const shellBox = h('div', { class: 'shell' + (store('side_collapsed') === '1' ? ' collapsed' : '') });
  const toggleSide = () => {
    const collapsed = shellBox.classList.toggle('collapsed');
    store('side_collapsed', collapsed ? '1' : '0');
  };
  const roleName = ROLES[state.me.role] || state.me.role;
  const side = h(
    'aside',
    { class: 'side' },
    h(
      'div',
      { class: 'side-head' },
      h('a', { class: 'brand', href: '#/home', 'aria-label': 'Стройка, главная' }, h('div', { class: 'brand-mark' }, icon('logo', 20)), h('span', { class: 'brand-text' }, 'Стройка', h('small', null, 'CRM для стройки'))),
      h('button', { class: 'side-toggle', type: 'button', title: 'Свернуть или развернуть меню', 'aria-label': 'Свернуть меню', onclick: toggleSide }, icon('chevron', 18)),
    ),
    nav,
    h(
      'div',
      { class: 'side-foot' },
      h(
        'a',
        { class: 'side-user', href: '#/profile', title: 'Профиль и пароль' },
        h('span', { class: 'avatar' }, initials(state.me.name)),
        h('span', { class: 'side-user-text' }, h('strong', null, state.me.name), h('small', null, roleName)),
      ),
      h('button', { class: 'side-icon', type: 'button', title: 'Показать обучение', 'aria-label': 'Обучение', onclick: () => startTour(ctx) }, icon('help', 18)),
      h('button', { class: 'side-icon', type: 'button', title: 'Выйти', 'aria-label': 'Выйти', onclick: logout }, icon('logout', 18)),
    ),
  );
  const topbar = h(
    'header',
    { class: 'topbar' },
    searchBox(),
    queueBadge(),
    bellButton(),
    h('a', { class: 'who', href: '#/profile', title: 'Профиль и пароль' }, h('span', { class: 'avatar' }, initials(state.me.name)), h('span', { class: 'who-text' }, h('strong', null, state.me.name), h('small', null, roleName))),
  );
  const mobileTop = h(
    'div',
    { class: 'mobile-top' },
    h('div', { class: 'brand' }, h('div', { class: 'brand-mark' }, icon('logo', 18)), 'Стройка'),
    h('a', { class: 'avatar', href: '#/profile', 'aria-label': 'Профиль' }, initials(state.me.name)),
  );
  shellBox.append(side, h('div', { class: 'content' }, topbar, view));
  return { element: h('div', null, mobileTop, shellBox), view };
}

async function render() {
  const token = ++renderToken;
  const route = parseRoute();
  if (!state.me) {
    root.replaceChildren(authPage(onAuth, { resetToken: route.name === 'reset' ? route.id : null }));
    return;
  }
  const { element, view } = shell(route.name);
  root.replaceChildren(element);
  try {
    let page;
    if (ROUTE_CAPS[route.name] && !can(ctx, ROUTE_CAPS[route.name])) {
      page = h('div', { class: 'empty' }, h('h2', null, 'Нет доступа'), h('p', { class: 'muted' }, 'У вашей роли нет доступа к этому разделу.'), h('a', { class: 'btn', href: '#/home' }, 'На главную'));
    } else if (route.name === 'profile') page = await profilePage(ctx);
    else if (route.name === 'audit') page = await auditPage(ctx);
    else if (route.name === 'trash') page = await trashPage(ctx);
    else if (route.name === 'deal' && route.id) page = await dealPage(ctx, route.id);
    else if (route.name === 'project' && route.id) page = await projectPage(ctx, route.id, route.tab);
    else if (route.name === 'contact' && route.id) page = await contactPage(ctx, route.id);
    else if (route.name === 'contacts') page = await contactsPage(ctx);
    else if (route.name === 'portfolio') page = await portfolioPage(ctx);
    else if (route.name === 'warehouse') page = await warehousePage(ctx);
    else if (route.name === 'notifications') page = await notificationsPage(ctx);
    else if (route.name === 'tasks') page = await tasksPage(ctx);
    else if (route.name === 'team') page = await teamPage(ctx);
    else if (route.name === 'payments') page = await paymentsPage(ctx);
    else if (route.name === 'expenses') page = await expensesPage(ctx);
    else if (route.name === 'timesheet') page = await timesheetPage(ctx);
    else if (route.name === 'boards') page = await boardsPage(ctx);
    else if (route.name === 'objects') page = await projectsPage(ctx);
    else if (route.name === 'analytics') page = await analyticsPage(ctx);
    else if (route.name === 'plans') page = await plansPage(ctx);
    else if (route.name === 'deals') page = await dealsPage(ctx);
    else if (!can(ctx, 'home.read')) page = await projectsPage(ctx); // подрядчик и ген. директор начинают со своих объектов
    else page = await homePage(ctx);
    if (token !== renderToken) return;
    view.replaceChildren(page);
    announce(view);
  } catch (error) {
    if (token !== renderToken) return;
    view.replaceChildren(
      h(
        'div',
        { class: 'empty' },
        h('h2', null, 'Не получилось открыть'),
        h('p', { class: 'muted' }, errText(error)),
        h('div', { class: 'row' }, h('a', { class: 'btn', href: '#/home' }, 'На главную'), h('button', { class: 'btn primary', type: 'button', onclick: () => render() }, 'Повторить')),
      ),
    );
  }
}

/** Для экранных дикторов и вкладок браузера: название страницы в заголовке и фокус на содержимом. */
function announce(view) {
  const heading = view.querySelector('h1');
  document.title = heading && heading.textContent ? `${heading.textContent} · Стройка` : 'Стройка CRM';
  if (!document.activeElement || document.activeElement === document.body) view.focus({ preventScroll: true });
}

function welcome() {
  const flag = `tour_done_${state.me.id}`;
  if (store(flag)) return;
  const body = h(
    'div',
    null,
    h('p', null, 'Хотите быстро посмотреть, как всё устроено? Покажем на готовом примере: сделки, объект, документы, закупки. Это займёт минуту, можно пропустить.'),
    h(
      'div',
      { class: 'actions' },
      h('button', { class: 'btn primary', type: 'button', onclick: () => { dialog.close(); startTour(ctx); } }, 'Показать'),
      h('button', { class: 'btn', type: 'button', onclick: () => { store(flag, '1'); dialog.close(); } }, 'Начать с нуля'),
    ),
  );
  const dialog = modal(`Добро пожаловать, ${state.me.name}!`, body);
}

async function onAuth(user, isNew) {
  state.me = user;
  location.hash = '#/home';
  await render();
  if (isNew) welcome();
}

window.addEventListener('hashchange', render);
window.addEventListener('unauthorized', () => {
  state.me = null;
  render();
});

(async function boot() {
  try {
    state.me = await api.get('/auth/me');
  } catch (error) {
    state.me = null;
    if (error.code === 'network') {
      // без связи не показываем форму входа: человек не выходил, просто нет интернета
      root.replaceChildren(offlineScreen());
      window.addEventListener('online', () => location.reload(), { once: true });
      return;
    }
  }
  await render();
  if (state.me) startAutoFlush((n) => toast(`Отправлено документов, ждавших связи: ${n}`));
})();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
