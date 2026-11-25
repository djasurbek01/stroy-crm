import { api } from '../api.js';
import { badge, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

async function importFlow(file, onDone) {
  let preview;
  try {
    const data = new FormData();
    data.append('file', file);
    preview = await api.upload('/payments/import', data, { dry_run: 'true' });
  } catch (error) {
    toast(errText(error), 'bad');
    return;
  }
  const body = h(
    'div',
    null,
    h('p', { class: 'sum' }, `Новых платежей: ${preview.new}`),
    h(
      'ul',
      null,
      h('li', null, `Привязано к счетам автоматически: ${preview.invoices}`),
      h('li', null, `Определён объект: ${preview.matched}`),
      preview.duplicates ? h('li', null, `Уже были загружены раньше: ${preview.duplicates}`) : null,
      preview.skipped ? h('li', null, `Не удалось прочитать строк: ${preview.skipped}`) : null,
    ),
    preview.new
      ? h(
          'div',
          { class: 'scroll-x' },
          h(
            'table',
            { class: 'preview' },
            h('thead', null, h('tr', null, h('th', null, 'Дата'), h('th', null, 'Кому/от кого'), h('th', null, 'Сумма'), h('th', null, 'Счёт'))),
            h(
              'tbody',
              null,
              preview.preview.map((p) =>
                h(
                  'tr',
                  null,
                  h('td', null, fmtDate(p.pay_date)),
                  h('td', null, p.party_name || '—'),
                  h('td', null, (p.direction === 'out' ? '− ' : '+ ') + fmtMoney(p.amount)),
                  h('td', null, p.invoice ? 'найден' : '—'),
                ),
              ),
            ),
          ),
        )
      : null,
    preview.new > preview.preview.length ? h('p', { class: 'muted' }, `Показаны первые ${preview.preview.length}.`) : null,
    h(
      'div',
      { class: 'actions' },
      h(
        'button',
        {
          class: 'btn primary',
          type: 'button',
          disabled: preview.new === 0,
          onclick: async () => {
            try {
              const data = new FormData();
              data.append('file', file);
              const result = await api.upload('/payments/import', data);
              dialog.close();
              toast(`Добавлено платежей: ${result.created}, привязано к счетам: ${result.invoices}`);
              onDone();
            } catch (error) {
              toast(errText(error), 'bad');
            }
          },
        },
        preview.new ? `Добавить ${preview.new}` : 'Нечего добавлять',
      ),
      h('button', { class: 'btn', type: 'button', onclick: () => dialog.close() }, 'Отмена'),
    ),
  );
  const dialog = modal('Банковская выписка', body);
}

export async function paymentsPage(ctx) {
  const state = { filter: '' };
  const list = h('div', { class: 'list' });
  const chips = h('div', { class: 'chips' });
  const fileInput = h('input', {
    type: 'file',
    class: 'hidden',
    accept: '.txt,.xlsx',
    onchange: (event) => {
      const file = event.target.files[0];
      event.target.value = '';
      if (file) importFlow(file, load);
    },
  });

  async function load() {
    const [payments, projects, invoices] = await Promise.all([
      api.get('/payments', { unassigned: state.filter === 'free' ? 'true' : undefined }),
      api.get('/projects'),
      api.get('/documents', { doc_type: 'invoice' }),
    ]);
    const projectOptions = { '': 'Без объекта' };
    for (const p of projects) projectOptions[p.id] = p.name;
    const invoiceOptions = { '': 'Счёт не выбран' };
    for (const d of invoices) invoiceOptions[d.id] = `${d.number ? '№' + d.number + ' ' : ''}${d.title}`;
    const shown = state.filter === 'noinvoice' ? payments.filter((p) => !p.document_id) : payments;

    list.replaceChildren(
      ...(shown.length
        ? shown.map((p) =>
            h(
              'div',
              { class: 'item' },
              h(
                'div',
                null,
                h('div', { class: 'title' }, p.party_name || 'Без названия'),
                h('div', { class: 'meta' }, `${fmtDate(p.pay_date)} · ${p.purpose || 'без назначения'}`),
              ),
              h(
                'div',
                { class: 'side-info' },
                h('strong', null, (p.direction === 'out' ? '− ' : '+ ') + fmtMoney(p.amount)),
                p.match === 'auto' ? badge('Счёт найден сам', 'green') : p.document_id ? badge('Счёт выбран', 'blue') : badge('Нужно проверить', 'orange'),
                select(invoiceOptions, p.document_id ? String(p.document_id) : '', {
                  'aria-label': 'Счёт',
                  disabled: !can(ctx, 'money.write'),
                  onchange: async (event) => {
                    try {
                      await api.patch(`/payments/${p.id}`, { document_id: event.target.value ? Number(event.target.value) : null });
                      load();
                    } catch (error) {
                      toast(errText(error), 'bad');
                    }
                  },
                }),
                select(projectOptions, p.project_id ? String(p.project_id) : '', {
                  'aria-label': 'Объект',
                  disabled: !can(ctx, 'money.write'),
                  onchange: async (event) => {
                    try {
                      await api.patch(`/payments/${p.id}`, { project_id: event.target.value ? Number(event.target.value) : null });
                      load();
                    } catch (error) {
                      toast(errText(error), 'bad');
                    }
                  },
                }),
              ),
            ),
          )
        : [
            h(
              'div',
              { class: 'empty' },
              h('h2', null, 'Платежей пока нет'),
              h('p', { class: 'muted' }, 'Выгрузите выписку из интернет-банка (формат для 1С, файл .txt) или таблицу Excel и загрузите сюда. Система сама найдёт, за какой счёт и по какому объекту был платёж.'),
            ),
          ]),
    );
  }

  function drawChips() {
    chips.replaceChildren(
      ...Object.entries({ '': 'Все', noinvoice: 'Без счёта', free: 'Без объекта' }).map(([key, label]) =>
        h('button', { class: 'chip' + (state.filter === key ? ' on' : ''), type: 'button', onclick: () => { state.filter = key; drawChips(); load(); } }, label),
      ),
    );
  }

  async function rematch() {
    try {
      const result = await api.post('/payments/rematch');
      toast(result.matched ? `Привязано платежей к счетам: ${result.matched}` : 'Новых совпадений нет');
      load();
    } catch (error) {
      toast(errText(error), 'bad');
    }
  }

  const page = h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, 'Платежи'), h('p', { class: 'muted' }, 'Банковская выписка: система сама привязывает оплаты к счетам и объектам.')),
      h(
        'div',
        { class: 'row' },
        fileInput,
        can(ctx, 'money.write') ? h('button', { class: 'btn primary', id: 'import-bank', type: 'button', onclick: () => fileInput.click() }, 'Загрузить выписку') : null,
        can(ctx, 'money.write') ? h('button', { class: 'btn', type: 'button', onclick: rematch }, 'Сверить заново') : null,
        h('a', { class: 'btn', id: 'export-1c', href: '/export.xlsx' }, 'Выгрузить для 1С'),
      ),
    ),
    chips,
    list,
  );
  drawChips();
  await load();
  return page;
}
