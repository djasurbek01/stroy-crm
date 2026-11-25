// Блок «Расчёт и коммерческое предложение» на странице сделки.
import { api } from '../api.js';
import { badge, confirmDialog, fmtDate, fmtMoney, h, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

export function offerSection(ctx, deal, reload) {
  const writable = can(ctx, 'deals.write');
  const rows = deal.items.map((i) => ({ title: i.title, quantity: i.quantity, unit: i.unit, price: i.price }));
  const state = { note: deal.offer_note || '', valid: deal.offer_valid_until || '', dirty: false };
  const box = h('section', { class: 'pane offer-section', id: 'offer-section' });
  const totalOf = () => rows.reduce((sum, r) => sum + (Number(r.quantity) || 0) * (Number(r.price) || 0), 0);

  function draw() {
    const table = h(
      'div',
      { class: 'table-wrap' },
      h(
        'table',
        { class: 'data offer-edit' },
        h('thead', null, h('tr', null, h('th', null, 'Что делаем'), h('th', null, 'Кол-во'), h('th', null, 'Ед.'), h('th', null, 'Цена, ₽'), h('th', { class: 'num' }, 'Сумма'), h('th', null, ''))),
        h(
          'tbody',
          null,
          rows.length
            ? rows.map((row, index) =>
                h(
                  'tr',
                  { 'data-row': String(index) },
                  h('td', null, h('input', { value: row.title, disabled: !writable, 'aria-label': 'Название работы', oninput: (e) => { row.title = e.target.value; state.dirty = true; } })),
                  h('td', null, h('input', { value: String(row.quantity), type: 'number', min: '0', step: 'any', class: 'narrow', disabled: !writable, 'aria-label': 'Количество', oninput: (e) => { row.quantity = e.target.value; state.dirty = true; updateTotals(); } })),
                  h('td', null, h('input', { value: row.unit, class: 'narrow', disabled: !writable, 'aria-label': 'Единица', oninput: (e) => { row.unit = e.target.value; state.dirty = true; } })),
                  h('td', null, h('input', { value: String(row.price), type: 'number', min: '0', step: 'any', class: 'narrow', disabled: !writable, 'aria-label': 'Цена', oninput: (e) => { row.price = e.target.value; state.dirty = true; updateTotals(); } })),
                  h('td', { class: 'num row-total' }, fmtMoney((Number(row.quantity) || 0) * (Number(row.price) || 0))),
                  h('td', null, writable ? h('button', { class: 'btn small bad', type: 'button', 'aria-label': 'Убрать строку', onclick: () => { rows.splice(index, 1); state.dirty = true; draw(); } }, '✕') : null),
                ),
              )
            : [h('tr', null, h('td', { colspan: '6', class: 'muted' }, 'Позиций пока нет. Добавьте работы и материалы, и итог попадёт в бюджет сделки.'))],
        ),
        h('tfoot', null, h('tr', null, h('td', { colspan: '4' }, h('strong', null, 'Итого')), h('td', { class: 'num', id: 'offer-total' }, h('strong', null, fmtMoney(totalOf()))), h('td', null))),
      ),
    );

    const noteInput = h('textarea', { rows: '3', disabled: !writable, placeholder: 'Условия: этапы оплаты, сроки работ, что входит в цену', 'aria-label': 'Условия', value: state.note, oninput: (e) => { state.note = e.target.value; state.dirty = true; } });
    const validInput = h('input', { type: 'date', disabled: !writable, value: state.valid, 'aria-label': 'Действует до', onchange: (e) => { state.valid = e.target.value; state.dirty = true; } });

    async function save() {
      try {
        await api.put(`/deals/${deal.id}/offer`, {
          items: rows.filter((r) => String(r.title).trim()).map((r) => ({ title: String(r.title).trim(), quantity: Number(r.quantity) || 1, unit: r.unit || '', price: Number(r.price) || 0 })),
          note: state.note,
          valid_until: state.valid || null,
        });
        toast('Расчёт сохранён');
        state.dirty = false;
        await reload();
      } catch (error) {
        toast(errText(error), 'bad');
      }
    }

    async function share() {
      if (state.dirty) await save();
      try {
        const link = await api.post(`/deals/${deal.id}/offer/share`);
        await reload();
        copy(location.origin + link.path);
      } catch (error) {
        toast(errText(error), 'bad');
      }
    }

    function copy(url) {
      const done = () => toast('Ссылка скопирована. Отправьте её клиенту.');
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, () => window.prompt && toast(url));
      else toast(url);
    }

    box.replaceChildren(
      h('h2', { class: 'pane-title' }, 'Расчёт и коммерческое предложение'),
      deal.offer_accepted_at ? h('p', null, badge('Клиент принял предложение', 'green'), ` ${fmtDate(deal.offer_accepted_at)}`) : null,
      table,
      writable ? h('button', { class: 'btn small', id: 'add-item', type: 'button', onclick: () => { rows.push({ title: '', quantity: 1, unit: '', price: 0 }); state.dirty = true; draw(); } }, '+ Добавить позицию') : null,
      h('div', { class: 'row', style: 'margin-top:12px' }, h('div', { style: 'flex:2;min-width:220px' }, noteInput), h('label', { class: 'field', style: 'flex:1;min-width:160px' }, h('span', null, 'Действует до'), validInput)),
      writable
        ? h(
            'div',
            { class: 'actions' },
            h('button', { class: 'btn primary', id: 'save-offer', type: 'button', onclick: save }, 'Сохранить расчёт'),
            deal.offer_shared
              ? [
                  h('button', { class: 'btn', id: 'copy-offer', type: 'button', onclick: async () => { const link = await api.post(`/deals/${deal.id}/offer/share`); copy(location.origin + link.path); } }, 'Скопировать ссылку для клиента'),
                  h('button', { class: 'btn', type: 'button', onclick: async () => { const link = await api.post(`/deals/${deal.id}/offer/share`); window.open(link.path, '_blank', 'noopener'); } }, 'Открыть как клиент'),
                  h('button', { class: 'btn bad', type: 'button', onclick: () => confirmDialog('Отключить ссылку?', 'Клиент больше не сможет открыть предложение по этой ссылке. Новую можно создать в любой момент.', 'Отключить', async () => { try { await api.post(`/deals/${deal.id}/offer/unshare`); toast('Ссылка отключена'); await reload(); } catch (error) { toast(errText(error), 'bad'); } }) }, 'Отключить ссылку'),
                ]
              : h('button', { class: 'btn', id: 'share-offer', type: 'button', onclick: share }, 'Получить ссылку для клиента'),
          )
        : null,
    );
  }

  function updateTotals() {
    const total = box.querySelector('#offer-total');
    if (total) total.replaceChildren(h('strong', null, fmtMoney(totalOf())));
    box.querySelectorAll('tr[data-row]').forEach((tr) => {
      const row = rows[Number(tr.dataset.row)];
      tr.querySelector('.row-total').textContent = fmtMoney((Number(row.quantity) || 0) * (Number(row.price) || 0));
    });
  }

  draw();
  return box;
}
