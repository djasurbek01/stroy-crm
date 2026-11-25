// Контакты: заказчики, подрядчики, поставщики. Список и карточка с историей.
import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { CONTRACT_STATUS, DOC_STATUS, DOC_TYPES, errText } from '../labels.js';
import { can } from '../perm.js';

const KINDS = { customer: 'Заказчик', contractor: 'Подрядчик', supplier: 'Поставщик' };
const KIND_TONE = { customer: 'green', contractor: 'blue', supplier: 'orange' };

function contactModal(onSaved, existing) {
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Название или ФИО', h('input', { name: 'name', value: existing ? existing.name : '', required: true })),
    field('Кто это', select(KINDS, existing ? existing.kind : 'customer', { name: 'kind' })),
    field('Телефон', h('input', { name: 'phone', type: 'tel', inputmode: 'tel', value: existing ? existing.phone : '' })),
    field('Email', h('input', { name: 'email', type: 'email', value: existing ? existing.email : '' })),
    field('ИНН', h('input', { name: 'inn', inputmode: 'numeric', value: existing ? existing.inn : '' }), 'Нужен, чтобы платежи из банка сами находили контакт'),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, existing ? 'Сохранить' : 'Добавить контакт')),
  );
  const dialog = modal(existing ? 'Изменить контакт' : 'Новый контакт', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const f = form.elements;
    const body = { name: f.name.value.trim(), kind: f.kind.value, phone: f.phone.value.trim(), email: f.email.value.trim(), inn: f.inn.value.trim() };
    if (!body.name) {
      errorBox.textContent = 'Напишите название или ФИО.';
      errorBox.classList.remove('hidden');
      return;
    }
    try {
      const saved = existing ? await api.patch(`/counterparties/${existing.id}`, body) : await api.post('/counterparties', body);
      dialog.close();
      toast(existing ? 'Сохранено' : 'Контакт добавлен');
      onSaved(saved);
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

export async function contactsPage(ctx) {
  const all = await api.get('/counterparties');
  const state = { kind: '', q: '' };
  const holder = h('div');
  const chips = h('div', { class: 'chips' });

  function draw() {
    chips.replaceChildren(
      ...Object.entries({ '': 'Все', ...KINDS }).map(([key, label]) =>
        h('button', { class: 'chip' + (state.kind === key ? ' on' : ''), type: 'button', onclick: () => { state.kind = key; draw(); } }, label),
      ),
    );
    const needle = state.q.trim().toLowerCase();
    const rows = all.filter((c) => (!state.kind || c.kind === state.kind) && (!needle || `${c.name} ${c.inn} ${c.phone} ${c.email}`.toLowerCase().includes(needle)));
    holder.replaceChildren(
      rows.length
        ? h(
            'div',
            { class: 'table-wrap' },
            h(
              'table',
              { class: 'data', id: 'contacts-table' },
              h('thead', null, h('tr', null, h('th', null, 'Название'), h('th', null, 'Кто это'), h('th', null, 'Телефон'), h('th', null, 'ИНН'))),
              h(
                'tbody',
                null,
                rows.map((c) =>
                  h(
                    'tr',
                    { class: 'row-link', tabindex: '0', onclick: () => ctx.go(`#/contact/${c.id}`), onkeydown: (e) => e.key === 'Enter' && ctx.go(`#/contact/${c.id}`) },
                    h('td', null, h('strong', null, c.name)),
                    h('td', null, badge(KINDS[c.kind] || c.kind, KIND_TONE[c.kind])),
                    h('td', null, c.phone || '—'),
                    h('td', null, c.inn || '—'),
                  ),
                ),
              ),
            ),
          )
        : h('div', { class: 'empty' }, h('h2', null, all.length ? 'Никого не нашли' : 'Контактов пока нет'), h('p', { class: 'muted' }, 'Контакты появляются сами, когда вы создаёте сделки, или добавьте вручную.')),
    );
  }

  const search = h('input', { type: 'search', placeholder: 'Найти по имени, телефону, ИНН', 'aria-label': 'Поиск контактов', oninput: (e) => { state.q = e.target.value; draw(); } });
  const page = h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, 'Контакты'), h('p', { class: 'muted' }, 'Заказчики, подрядчики и поставщики с историей сделок, договоров и оплат.')),
      can(ctx, 'contacts.write') ? h('button', { class: 'btn primary', id: 'add-contact', type: 'button', onclick: () => contactModal((c) => ctx.go(`#/contact/${c.id}`)) }, '+ Контакт') : null,
    ),
    h('div', { class: 'toolbar' }, h('div', { class: 'grow' }, search)),
    chips,
    holder,
  );
  draw();
  return page;
}

export async function contactPage(ctx, id) {
  const data = await api.get(`/counterparties/${id}/overview`);
  const c = data.counterparty;
  const t = data.totals;
  const state = { tab: 'deals' };
  const box = h('div');

  const section = {
    deals: () =>
      data.deals.length
        ? h('div', { class: 'list' }, data.deals.map((d) => h('a', { class: 'item clickable', href: `#/deal/${d.id}` }, h('div', null, h('div', { class: 'title' }, d.title), h('div', { class: 'meta' }, d.stage)), h('strong', null, d.amount ? fmtMoney(d.amount) : '—'))))
        : h('p', { class: 'muted' }, 'Сделок нет.'),
    contracts: () =>
      data.contracts.length
        ? h('div', { class: 'list' }, data.contracts.map((x) => h('div', { class: 'item' }, h('div', null, h('div', { class: 'title' }, x.title), h('div', { class: 'meta' }, x.project || 'Объект не указан')), h('div', { class: 'side-info' }, h('strong', null, fmtMoney(x.amount)), badge(CONTRACT_STATUS[x.status] || x.status)))))
        : h('p', { class: 'muted' }, 'Договоров нет.'),
    documents: () =>
      data.documents.length
        ? h('div', { class: 'list' }, data.documents.map((x) => h('a', { class: 'item clickable', href: `#/project/${x.project_id}/documents` }, h('div', null, h('div', { class: 'title' }, x.title), h('div', { class: 'meta' }, DOC_TYPES[x.doc_type] || x.doc_type)), badge(DOC_STATUS[x.status] || x.status))))
        : h('p', { class: 'muted' }, 'Документов нет.'),
    payments: () =>
      data.payments.length
        ? h('div', { class: 'list' }, data.payments.map((p) => h('div', { class: 'item' }, h('div', null, h('div', { class: 'title' }, p.purpose || 'Платёж'), h('div', { class: 'meta' }, fmtDate(p.pay_date))), h('strong', { class: p.direction === 'in' ? 'plus' : '' }, (p.direction === 'out' ? '− ' : '+ ') + fmtMoney(p.amount)))))
        : h('p', { class: 'muted' }, 'Платежей нет.'),
  };
  const labels = { deals: `Сделки (${data.deals.length})`, contracts: `Договоры (${data.contracts.length})`, documents: `Документы (${data.documents.length})`, payments: `Платежи (${data.payments.length})` };

  function drawTabs() {
    box.replaceChildren(
      h('div', { class: 'composer-tabs' }, Object.entries(labels).map(([key, label]) => h('button', { type: 'button', class: 'ctab' + (state.tab === key ? ' on' : ''), onclick: () => { state.tab = key; drawTabs(); } }, label))),
      section[state.tab](),
    );
  }

  const page = h(
    'div',
    null,
    h('a', { href: '#/contacts', class: 'muted' }, '← Контакты'),
    h('div', { class: 'page-head', style: 'margin-top:8px' }, h('div', null, h('h1', null, c.name), h('p', { class: 'muted' }, badge(KINDS[c.kind] || c.kind, KIND_TONE[c.kind]), ' ', [c.phone, c.email, c.inn ? `ИНН ${c.inn}` : ''].filter(Boolean).join(' · ') || 'Контактных данных нет')), can(ctx, 'contacts.write') ? h('div', { class: 'row' }, h('button', { class: 'btn', type: 'button', onclick: () => contactModal(() => ctx.go(`#/contact/${id}`), c) }, 'Изменить'), h('button', { class: 'btn bad', id: 'delete-contact', type: 'button', onclick: () => confirmDialog('Удалить контакт?', `«${c.name}» уйдёт в корзину на 30 дней. Сделки, договоры и платежи останутся, но без привязки к этому контакту.`, 'Удалить', async () => { try { await api.del(`/counterparties/${id}`); toast('Контакт в корзине'); ctx.go('#/contacts'); } catch (error) { toast(errText(error), 'bad'); } }) }, 'Удалить')) : null),
    h(
      'div',
      { class: 'kpis' },
      h('div', { class: 'kpi' }, h('div', { class: 'kpi-label' }, 'Сделки на сумму'), h('div', { class: 'kpi-value' }, fmtMoney(t.deals_sum))),
      h('div', { class: 'kpi' }, h('div', { class: 'kpi-label' }, 'Договоры на сумму'), h('div', { class: 'kpi-value' }, fmtMoney(t.contracts_sum))),
      h('div', { class: 'kpi' }, h('div', { class: 'kpi-label' }, 'Мы заплатили'), h('div', { class: 'kpi-value' }, fmtMoney(t.paid_out))),
      h('div', { class: 'kpi' }, h('div', { class: 'kpi-label' }, 'Нам заплатили'), h('div', { class: 'kpi-value plus' }, fmtMoney(t.paid_in))),
    ),
    box,
  );
  drawTabs();
  return page;
}
