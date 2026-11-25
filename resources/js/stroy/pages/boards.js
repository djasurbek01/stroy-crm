import { api } from '../api.js';
import { board } from '../board.js';
import { badge, fmtDate, fmtMoney, h, select, toast } from '../dom.js';
import { can } from '../perm.js';
import {
  CONTRACT_STATUS,
  CONTRACT_TONE,
  DOC_STATUS,
  DOC_TONE,
  DOC_TYPES,
  PAY_STATUS,
  PAY_TONE,
  PURCHASE_STATUS,
  PURCHASE_TONE,
  errText,
} from '../labels.js';
import { openDocument } from './documents.js';

const TABS = { purchases: 'Закупки', contracts: 'Договоры', documents: 'Документы' };

export async function boardsPage(ctx) {
  const state = { kind: 'purchases', project: '' };
  const holder = h('div');
  const tabs = h('div', { class: 'chips' });
  const projectBox = h('div', { class: 'toolbar' });

  const projects = await api.get('/projects');
  const names = Object.fromEntries(projects.map((p) => [p.id, p.name]));
  const parties = Object.fromEntries((await api.get('/counterparties')).map((c) => [c.id, c.name]));

  async function load() {
    const params = { project_id: state.project || undefined };
    let columns;
    let cards;
    let patch;
    let render;
    let onOpen = null;
    let readonly = false;

    if (state.kind === 'purchases') {
      const items = await api.get('/purchases', params);
      readonly = !can(ctx, 'purchases.write');
      columns = Object.entries(PURCHASE_STATUS).map(([id, title]) => ({ id, title }));
      cards = items.map((i) => ({ id: i.id, columnId: i.status, item: i }));
      patch = (card, status) => api.patch(`/purchases/${card.id}`, { status });
      render = ({ item }) => h('div', null, h('div', { class: 'title' }, item.item), h('div', { class: 'meta' }, `${item.quantity} ${item.unit}`.trim() + (item.counterparty_id ? ` · ${parties[item.counterparty_id] || ''}` : '')), item.amount ? h('div', { class: 'k-sum' }, fmtMoney(item.amount)) : null, h('div', { class: 'k-foot' }, badge(names[item.project_id] || 'Объект')));
    } else if (state.kind === 'contracts') {
      const items = await api.get('/contracts', params);
      readonly = !can(ctx, 'contracts.write');
      columns = Object.entries(CONTRACT_STATUS).map(([id, title]) => ({ id, title }));
      cards = items.map((i) => ({ id: i.id, columnId: i.status, item: i }));
      patch = (card, status) => api.patch(`/contracts/${card.id}`, { status });
      render = ({ item }) => h('div', null, h('div', { class: 'title' }, item.title), h('div', { class: 'meta' }, item.counterparty_id ? parties[item.counterparty_id] || '' : 'Контрагент не выбран'), h('div', { class: 'k-sum' }, fmtMoney(item.amount)), h('div', { class: 'k-foot' }, badge(names[item.project_id] || 'Объект'), item.due_date ? badge('до ' + fmtDate(item.due_date)) : null));
    } else {
      const items = await api.get('/documents', params);
      columns = Object.entries(DOC_STATUS).map(([id, title]) => ({ id, title }));
      cards = items.map((i) => ({ id: i.id, columnId: i.status, item: i }));
      onOpen = (card) => openDocument(card.id, ctx, load);
      // статус документа меняется только через согласование, перетащить можно лишь «на согласование»
      patch = async (card, status) => {
        if (card.columnId === 'draft' && status === 'in_review') return api.post(`/documents/${card.id}/submit`);
        const error = new Error('manual');
        error.code = 'doc_board_manual';
        throw error;
      };
      render = ({ item }) => h('div', null, h('div', { class: 'title' }, item.title), h('div', { class: 'meta' }, `${DOC_TYPES[item.doc_type] || ''}${item.number ? ' №' + item.number : ''}`), item.amount !== null && item.amount !== undefined ? h('div', { class: 'k-sum' }, fmtMoney(item.amount)) : null, h('div', { class: 'k-foot' }, item.amount !== null && item.amount !== undefined ? badge(PAY_STATUS[item.pay_status], PAY_TONE[item.pay_status]) : null, item.overdue ? badge('Просрочен', 'red') : null, badge(names[item.project_id] || 'Объект')));
    }

    holder.replaceChildren(
      board({
        columns,
        cards,
        onOpen,
        readonly,
        renderCard: render,
        onMove: async (card, columnId) => {
          try {
            await patch(card, columnId);
          } catch (error) {
            toast(error.code === 'doc_board_manual' ? 'Статус документа меняется через согласование: откройте документ.' : errText(error), 'bad');
          }
          await load();
        },
      }),
    );
  }

  function draw() {
    tabs.replaceChildren(
      ...Object.entries(TABS).map(([key, label]) =>
        h('button', { class: 'chip' + (state.kind === key ? ' on' : ''), id: `board-${key}`, type: 'button', onclick: () => { state.kind = key; draw(); load(); } }, label),
      ),
    );
  }

  const options = { '': 'Все объекты' };
  for (const p of projects) options[p.id] = p.name;
  projectBox.append(select(options, '', { 'aria-label': 'Объект', onchange: (event) => { state.project = event.target.value; load(); } }));

  const page = h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Доски'), h('p', { class: 'muted' }, 'Закупки, договоры и документы по этапам. Перетаскивайте карточки, чтобы поменять статус.'))),
    tabs,
    projectBox,
    holder,
  );
  draw();
  await load();
  return page;
}
