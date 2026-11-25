import { api } from '../api.js';
import { badge, h, modal } from '../dom.js';
import { icon } from '../icons.js';
import { can } from '../perm.js';

/** Тестовые цены, ₽ в месяц. За год скидка YEAR_DISCOUNT. */
const YEAR_DISCOUNT = 0.2;

const PLANS = [
  {
    key: 'start',
    name: 'Старт',
    who: 'Малые подрядчики и частные дома',
    price: 2990,
    limits: { projects: 3, users: 5 },
    items: ['До 3 объектов', 'До 5 сотрудников', 'Сделки, документы, договоры, закупки', 'График работ и задачи', 'Загрузка закупок из Excel', 'Страница для заказчика'],
  },
  {
    key: 'business',
    name: 'Бизнес',
    who: 'Средние застройщики и подрядчики',
    price: 9900,
    popular: true,
    limits: { projects: 100, users: 100 },
    items: ['До 100 объектов и 100 сотрудников', 'До 20 воронок продаж', 'Подрядчики видят только свои объекты', 'Склад, сметы, финансы объекта', 'Платежи из банка и выгрузка для 1С', 'Всё из тарифа «Старт»'],
  },
  {
    key: 'corporate',
    name: 'Корпорация',
    who: 'Крупные застройщики и холдинги',
    price: 29900,
    from: true,
    items: ['Объекты и сотрудники без ограничений', 'Настройка под ваши процессы', 'Персональный менеджер', 'Вход через корпоративную учётную запись (в планах)', 'Обмен с 1С и ЭДО (в планах)', 'Размещение на вашем сервере (в планах)'],
  },
];

// [возможность, Старт, Бизнес, Корпорация]: true / false / текст
const COMPARE = [
  ['Объекты', '3', '100', 'Без ограничений'],
  ['Сотрудники', '5', '100', 'Без ограничений'],
  ['Воронки продаж', '1', '20', 'Без ограничений'],
  ['Сделки, контакты, задачи', true, true, true],
  ['Документы с согласованием', true, true, true],
  ['График работ, приёмка, фото', true, true, true],
  ['Страница для заказчика', true, true, true],
  ['Роли и доступ только к своим объектам', false, true, true],
  ['Склад, сметы, финансы объекта', false, true, true],
  ['Платежи из банка, выгрузка для 1С', false, true, true],
  ['Персональный менеджер', false, false, true],
];

const FAQ = [
  ['Можно ли сменить тариф позже?', 'Да, в любой момент. Все данные сохраняются: объекты, документы, история сделок.'],
  ['Что будет, если превысить лимит?', 'Новые объекты или сотрудников добавить не получится, пока не перейдёте на тариф выше. Всё, что уже есть, продолжит работать.'],
  ['Платят ли подрядчики и заказчики?', 'Нет. Заказчик смотрит ход стройки по ссылке без входа, а подрядчики считаются сотрудниками вашего тарифа.'],
  ['Как оплатить?', 'Онлайн-оплата пока не подключена. Напишите нам: выставим счёт для юрлица и включим тариф вручную.'],
];

const money = (value) => new Intl.NumberFormat('ru-RU').format(Math.round(value));

/** Плавно «докручивает» число до нового значения. */
function animateNumber(el, to) {
  const from = Number(el.dataset.value || to);
  el.dataset.value = String(to);
  if (from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = money(to); return; }
  const start = performance.now();
  const step = (now) => {
    const t = Math.min((now - start) / 450, 1);
    const eased = 1 - (1 - t) ** 3;
    el.textContent = money(from + (to - from) * eased);
    if (t < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

function contactDialog(plan) {
  modal(`Тариф «${plan.name}»`, h('div', null,
    h('p', null, 'Онлайн-оплата пока не подключена. Чтобы перейти на этот тариф, напишите нам: выставим счёт и включим тариф в тот же день.'),
    h('div', { class: 'plan-contact' },
      h('div', null, h('small', { class: 'muted' }, 'Почта'), h('strong', null, 'sales@stroyka.example')),
      h('div', null, h('small', { class: 'muted' }, 'Телефон'), h('strong', null, '+7 (900) 000-00-00')),
    ),
    h('p', { class: 'muted' }, 'Это тестовые контакты и цены: их можно заменить на настоящие.'),
  ));
}

function usageBar(label, used, limit) {
  const percent = limit ? Math.min(Math.round((used / limit) * 100), 100) : 0;
  return h('div', { class: 'usage-row' },
    h('div', { class: 'usage-head' }, h('span', null, label), h('strong', null, limit ? `${used} из ${limit}` : String(used))),
    h('div', { class: 'progress' }, h('div', { class: 'progress-fill' + (percent >= 90 ? ' warn' : ''), style: `width:${Math.max(percent, 2)}%` })),
  );
}

export async function plansPage(ctx) {
  const current = PLANS.find((p) => p.key === ctx.me.plan) || PLANS[0];
  let yearly = false;
  const priceEls = new Map();

  const cards = PLANS.map((plan) => {
    const isCurrent = plan.key === ctx.me.plan;
    const amount = h('span', { class: 'plan-amount', 'data-value': String(plan.price) }, money(plan.price));
    const period = h('span', { class: 'plan-period' }, '₽/мес');
    const note = h('div', { class: 'plan-note' }, ' ');
    priceEls.set(plan.key, { amount, note });
    return h(
      'div',
      { class: 'plan' + (plan.popular ? ' popular' : '') + (isCurrent ? ' current' : '') },
      plan.popular ? h('div', { class: 'plan-ribbon' }, 'Популярный') : null,
      h('div', { class: 'plan-top' }, h('h2', null, plan.name), isCurrent ? badge('Ваш тариф', plan.popular ? 'blue' : 'green') : null),
      h('p', { class: 'plan-who' }, plan.who),
      h('div', { class: 'plan-price' }, plan.from ? h('span', { class: 'plan-from' }, 'от') : null, amount, period),
      note,
      isCurrent
        ? h('button', { class: 'btn block', type: 'button', disabled: true }, 'Текущий тариф')
        : h('button', { class: 'btn block' + (plan.popular ? ' primary' : ''), type: 'button', onclick: () => contactDialog(plan) }, plan.from ? 'Обсудить условия' : 'Перейти на тариф'),
      h('ul', { class: 'plan-list' }, plan.items.map((text) => h('li', null, icon('check', 16), h('span', null, text)))),
    );
  });

  function updatePrices() {
    for (const plan of PLANS) {
      const { amount, note } = priceEls.get(plan.key);
      const monthly = yearly ? plan.price * (1 - YEAR_DISCOUNT) : plan.price;
      animateNumber(amount, monthly);
      note.textContent = yearly ? `${money(monthly * 12)} ₽ при оплате за год` : 'при оплате помесячно';
    }
  }

  const switcher = h('div', { class: 'billing-switch', role: 'group', 'aria-label': 'Период оплаты' });
  function drawSwitch() {
    switcher.replaceChildren(
      h('button', { class: 'seg-btn' + (!yearly ? ' on' : ''), type: 'button', 'aria-pressed': String(!yearly), onclick: () => { yearly = false; drawSwitch(); updatePrices(); } }, 'Помесячно'),
      h('button', { class: 'seg-btn' + (yearly ? ' on' : ''), type: 'button', 'aria-pressed': String(yearly), onclick: () => { yearly = true; drawSwitch(); updatePrices(); } }, 'За год ', h('span', { class: 'save-badge' }, `−${YEAR_DISCOUNT * 100}%`)),
    );
  }
  drawSwitch();
  updatePrices();

  // сколько уже занято: видит владелец, ему решать про тариф
  let usage = null;
  if (can(ctx, 'team.manage') && current.limits) {
    const [projects, team] = await Promise.all([api.get('/projects'), api.get('/team')]);
    usage = h('div', { class: 'card usage' },
      h('div', null, h('h2', null, 'Сейчас используется'), h('p', { class: 'muted' }, `Тариф «${current.name}». Когда лимит почти исчерпан, шкала становится оранжевой.`)),
      h('div', { class: 'usage-bars' },
        usageBar('Объекты', projects.filter((p) => !p.is_demo).length, current.limits.projects),
        usageBar('Сотрудники', team.length, current.limits.users),
      ),
    );
  }

  const cell = (value) => (value === true ? h('span', { class: 'yes' }, icon('check', 16)) : value === false ? h('span', { class: 'no' }, '—') : value);

  return h(
    'div',
    { class: 'pricing' },
    h('div', { class: 'pricing-hero' },
      badge('Тестовые цены', 'orange'),
      h('h1', { class: 'page-title' }, 'Тарифы'),
      h('p', { class: 'muted' }, 'Выберите тариф под размер вашей стройки. Сменить его можно в любой момент, данные сохранятся.'),
      switcher,
    ),
    usage,
    h('div', { class: 'plans' }, cards),
    h('h2', { class: 'pricing-title' }, 'Сравнение тарифов'),
    h('div', { class: 'table-wrap' }, h('table', { class: 'data compare' },
      h('thead', null, h('tr', null, h('th', null, 'Возможность'), PLANS.map((p) => h('th', { class: p.key === ctx.me.plan ? 'is-current' : '' }, p.name)))),
      h('tbody', null, COMPARE.map(([name, ...values]) => h('tr', null, h('td', null, name), values.map((v, i) => h('td', { class: PLANS[i].key === ctx.me.plan ? 'is-current' : '' }, cell(v)))))),
    )),
    h('h2', { class: 'pricing-title' }, 'Частые вопросы'),
    h('div', { class: 'faq' }, FAQ.map(([q, a]) => h('details', null, h('summary', null, q, icon('chevron', 18)), h('p', null, a)))),
  );
}
