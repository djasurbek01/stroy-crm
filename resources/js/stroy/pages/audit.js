// Журнал действий (только владелец): кто, что и когда менял.
import { api } from '../api.js';
import { badge, fmtMoney, h, select } from '../dom.js';
import { fmtTime } from '../deal-util.js';

const ENTITIES = {
  '': 'Всё',
  deal: 'Сделки', document: 'Документы', contract: 'Договоры', purchase: 'Закупки', contact: 'Контакты',
  project: 'Объекты', payment: 'Платежи', pipeline: 'Воронки', stage: 'Этапы', worker: 'Люди на объектах', timesheet: 'Табель', user: 'Сотрудники', export: 'Выгрузки',
};
const ACTIONS = {
  '': 'Любое действие',
  create: 'Создание', update: 'Изменение', delete: 'Удаление', restore: 'Восстановление', import: 'Загрузка выписки', export: 'Выгрузка', login_failed: 'Неудачный вход',
};
const TONE = { create: 'green', update: 'blue', delete: 'red', restore: 'orange', import: 'blue', export: 'orange', login_failed: 'red' };
const PAGE = 50;

function changesTable(details) {
  const rows = Object.entries((details && details.changes) || {});
  if (!rows.length) return null;
  return h(
    'div',
    { class: 'changes' },
    rows.map(([field, [before, after]]) =>
      h('div', null, h('span', { class: 'muted' }, `${field}: `), h('s', null, String(before ?? '—')), ' → ', h('strong', null, String(after ?? '—'))),
    ),
  );
}

export async function auditPage() {
  const state = { entity: '', action: '', offset: 0, total: 0 };
  const list = h('div', { class: 'list' });
  const more = h('button', { class: 'btn', id: 'audit-more', type: 'button', onclick: () => load(true) }, 'Показать ещё');
  const counter = h('p', { class: 'muted' });

  async function load(append = false) {
    if (!append) state.offset = 0;
    const { items, total } = await api.page('/audit', { entity: state.entity || undefined, action: state.action || undefined, limit: PAGE, offset: state.offset });
    state.total = total;
    state.offset += items.length;
    const rows = items.map((e) =>
      h(
        'div',
        { class: 'item audit-item' },
        h(
          'div',
          null,
          h('div', { class: 'title' }, e.summary),
          h('div', { class: 'meta' }, `${e.user || 'Система'} · ${fmtTime(e.created_at)}`),
          changesTable(e.details),
        ),
        badge(ACTIONS[e.action] || e.action, TONE[e.action] || ''),
      ),
    );
    if (append) list.append(...rows);
    else list.replaceChildren(...(rows.length ? rows : [h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Записей нет.'))]));
    counter.textContent = total ? `Показано ${Math.min(state.offset, total)} из ${total}` : '';
    more.classList.toggle('hidden', state.offset >= total);
  }

  const page = h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Журнал действий'), h('p', { class: 'muted' }, 'Кто и что менял. Записи нельзя удалить или поправить.'))),
    h(
      'div',
      { class: 'toolbar' },
      select(ENTITIES, '', { id: 'audit-entity', 'aria-label': 'Что', onchange: (e) => { state.entity = e.target.value; load(); } }),
      select(ACTIONS, '', { id: 'audit-action', 'aria-label': 'Действие', onchange: (e) => { state.action = e.target.value; load(); } }),
    ),
    list,
    counter,
    more,
  );
  await load();
  return page;
}
