import { api } from '../api.js';
import { badge, confirmDialog, field, fmtMoney, h, modal, select, toast } from '../dom.js';
import { PURCHASE_STATUS, PURCHASE_TONE, errText } from '../labels.js';
import { can } from '../perm.js';

function deleteButton(kind, id, title, ctx, onDone) {
  return h('button', {
    class: 'btn small bad', type: 'button', 'aria-label': `Удалить: ${title}`,
    onclick: () => confirmDialog(`Удалить ${kind}?`, `«${title}» уйдёт в корзину на 30 дней, её можно будет вернуть.`, 'Удалить', async () => {
      try { await api.del(id); toast('Перемещено в корзину'); onDone(); } catch (error) { toast(errText(error), 'bad'); }
    }),
  }, '✕');
}


const COLUMN_NAMES = {
  item: 'Наименование',
  quantity: 'Количество',
  unit: 'Ед. изм.',
  amount: 'Сумма',
  supplier: 'Поставщик',
};

function addPurchaseModal(project, onDone) {
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Что купить', h('input', { name: 'item', placeholder: 'Например: Арматура А500', required: true })),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:120px' }, field('Количество', h('input', { name: 'quantity', type: 'number', min: '0', step: 'any', value: '1', inputmode: 'decimal' }))),
      h('div', { style: 'flex:1;min-width:120px' }, field('Ед. изм.', h('input', { name: 'unit', placeholder: 'шт, т, м³' }))),
    ),
    field('Сумма, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '0.01', inputmode: 'decimal' })),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Добавить в закупки')),
  );
  const dialog = modal('Новая закупка', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const item = form.elements.item.value.trim();
    if (!item) {
      errorBox.textContent = 'Напишите, что нужно купить.';
      errorBox.classList.remove('hidden');
      return;
    }
    try {
      await api.post(`/projects/${project.id}/purchases`, {
        item,
        quantity: Number(form.elements.quantity.value) || 1,
        unit: form.elements.unit.value.trim(),
        amount: Number(form.elements.amount.value) || 0,
      });
      dialog.close();
      toast('Добавлено в закупки');
      onDone();
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

async function importFlow(project, file, onDone) {
  let preview;
  try {
    const data = new FormData();
    data.append('file', file);
    preview = await api.upload(`/projects/${project.id}/purchases/import`, data, { dry_run: 'true' });
  } catch (error) {
    toast(errText(error), 'bad');
    return;
  }
  const found = Object.keys(preview.columns).map((key) => COLUMN_NAMES[key]).join(', ');
  const body = h(
    'div',
    null,
    h('p', null, `Нашли колонки: ${found}.`),
    h('p', { class: 'sum' }, `Будет добавлено позиций: ${preview.rows}`, preview.skipped ? ` (пустых строк пропущено: ${preview.skipped})` : ''),
    h(
      'div',
      { class: 'scroll-x' },
      h(
        'table',
        { class: 'preview' },
        h('thead', null, h('tr', null, h('th', null, 'Наименование'), h('th', null, 'Кол-во'), h('th', null, 'Сумма'), h('th', null, 'Поставщик'))),
        h(
          'tbody',
          null,
          preview.preview.map((row) => h('tr', null, h('td', null, row.item), h('td', null, `${row.quantity} ${row.unit}`.trim()), h('td', null, fmtMoney(row.amount)), h('td', null, row.supplier || '—'))),
        ),
      ),
    ),
    preview.rows > preview.preview.length ? h('p', { class: 'muted' }, `Показаны первые ${preview.preview.length}.`) : null,
    h(
      'div',
      { class: 'actions' },
      h(
        'button',
        {
          class: 'btn primary',
          type: 'button',
          disabled: preview.rows === 0,
          onclick: async () => {
            try {
              const data = new FormData();
              data.append('file', file);
              const result = await api.upload(`/projects/${project.id}/purchases/import`, data);
              dialog.close();
              toast(`Добавлено позиций: ${result.created}`);
              onDone();
            } catch (error) {
              toast(errText(error), 'bad');
            }
          },
        },
        `Добавить ${preview.rows}`,
      ),
      h('button', { class: 'btn', type: 'button', onclick: () => dialog.close() }, 'Отмена'),
    ),
  );
  const dialog = modal('Загрузка из Excel', body);
}

export async function purchasesTab(box, project, ctx) {
  const write = can(ctx, 'purchases.write');
  const sum = h('p', { class: 'sum' });
  const list = h('div', { class: 'list' });
  const excelInput = h('input', {
    type: 'file', class: 'hidden', accept: '.xlsx',
    onchange: (event) => {
      const file = event.target.files[0];
      event.target.value = '';
      if (file) importFlow(project, file, load);
    },
  });

  async function load() {
    const [purchases, counterparties] = await Promise.all([
      api.get('/purchases', { project_id: project.id }),
      api.get('/counterparties'),
    ]);
    const names = Object.fromEntries(counterparties.map((c) => [c.id, c.name]));
    sum.textContent = purchases.length ? `Позиций: ${purchases.length}, на сумму ${fmtMoney(purchases.reduce((s, p) => s + p.amount, 0))}` : '';
    list.replaceChildren(
      ...(purchases.length
        ? purchases.map((p) =>
            h(
              'div',
              { class: 'item' },
              h('div', null, h('div', { class: 'title' }, p.item), h('div', { class: 'meta' }, `${p.quantity} ${p.unit}`.trim() + (p.counterparty_id ? ` · ${names[p.counterparty_id] || ''}` : ''))),
              h(
                'div',
                { class: 'side-info' },
                h('strong', null, fmtMoney(p.amount)),
                badge(PURCHASE_STATUS[p.status], PURCHASE_TONE[p.status]),
                write ? select(PURCHASE_STATUS, p.status, {
                  'aria-label': 'Статус закупки',
                  onchange: async (event) => {
                    try {
                      await api.patch(`/purchases/${p.id}`, { status: event.target.value });
                      load();
                    } catch (error) {
                      toast(errText(error), 'bad');
                    }
                  },
                }) : null,
                write ? deleteButton('закупку', `/purchases/${p.id}`, p.item, ctx, load) : null,
              ),
            ),
          )
        : [h('div', { class: 'empty' }, h('p', null, 'Закупок пока нет.'), h('p', { class: 'muted' }, 'Добавьте вручную или загрузите таблицу Excel. В первой строке должны быть названия колонок: Наименование, Количество, Ед., Сумма, Поставщик.'))]),
    );
  }

  box.append(
    write
      ? h(
          'div',
          { class: 'toolbar' },
          h('button', { class: 'btn primary', type: 'button', onclick: () => addPurchaseModal(project, load) }, '+ Что купить'),
          excelInput,
          h('button', { class: 'btn', id: 'import-excel', type: 'button', onclick: () => excelInput.click() }, 'Загрузить из Excel'),
        )
      : null,
    sum,
    list,
  );
  await load();
}
