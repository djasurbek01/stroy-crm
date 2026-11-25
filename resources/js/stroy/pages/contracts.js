import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { CONTRACT_STATUS, CONTRACT_TONE, errText } from '../labels.js';
import { can } from '../perm.js';

function deleteButton(kind, id, title, ctx, onDone) {
  return h('button', {
    class: 'btn small bad', type: 'button', 'aria-label': `Удалить: ${title}`,
    onclick: () => confirmDialog(`Удалить ${kind}?`, `«${title}» уйдёт в корзину на 30 дней, её можно будет вернуть.`, 'Удалить', async () => {
      try { await api.del(id); toast('Перемещено в корзину'); onDone(); } catch (error) { toast(errText(error), 'bad'); }
    }),
  }, '✕');
}


async function addContractModal(project, onDone) {
  const counterparties = await api.get('/counterparties');
  const parties = { '': 'Не выбран' };
  for (const c of counterparties) parties[c.id] = c.name;
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Название договора', h('input', { name: 'title', placeholder: 'Например: Договор подряда на фундамент', required: true })),
    field('С кем', select(parties, '', { name: 'counterparty_id' }), 'Контрагентов можно добавить в закупках через импорт или в документах.'),
    field('Сумма, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '0.01', inputmode: 'decimal' })),
    field('Срок', h('input', { name: 'due_date', type: 'date' })),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Добавить договор')),
  );
  const dialog = modal('Новый договор', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const title = form.elements.title.value.trim();
    if (!title) {
      errorBox.textContent = 'Напишите название договора.';
      errorBox.classList.remove('hidden');
      return;
    }
    try {
      await api.post(`/projects/${project.id}/contracts`, {
        title,
        amount: Number(form.elements.amount.value) || 0,
        counterparty_id: form.elements.counterparty_id.value ? Number(form.elements.counterparty_id.value) : null,
        due_date: form.elements.due_date.value || null,
      });
      dialog.close();
      toast('Договор добавлен');
      onDone();
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

export async function contractsTab(box, project, ctx) {
  const write = can(ctx, 'contracts.write');
  const sum = h('p', { class: 'sum' });
  const list = h('div', { class: 'list' });

  async function load() {
    const [contracts, counterparties] = await Promise.all([
      api.get('/contracts', { project_id: project.id }),
      api.get('/counterparties'),
    ]);
    const names = Object.fromEntries(counterparties.map((c) => [c.id, c.name]));
    sum.textContent = contracts.length ? `Договоров: ${contracts.length}, на сумму ${fmtMoney(contracts.reduce((s, c) => s + c.amount, 0))}` : '';
    list.replaceChildren(
      ...(contracts.length
        ? contracts.map((c) =>
            h(
              'div',
              { class: 'item' },
              h('div', null, h('div', { class: 'title' }, c.title), h('div', { class: 'meta' }, `${c.counterparty_id ? names[c.counterparty_id] || '' : 'Контрагент не выбран'}${c.due_date ? ' · срок ' + fmtDate(c.due_date) : ''}`)),
              h(
                'div',
                { class: 'side-info' },
                h('strong', null, fmtMoney(c.amount)),
                badge(CONTRACT_STATUS[c.status], CONTRACT_TONE[c.status]),
                write ? select(CONTRACT_STATUS, c.status, {
                  'aria-label': 'Статус договора',
                  onchange: async (event) => {
                    try {
                      await api.patch(`/contracts/${c.id}`, { status: event.target.value });
                      load();
                    } catch (error) {
                      toast(errText(error), 'bad');
                    }
                  },
                }) : null,
                write ? deleteButton('договор', `/contracts/${c.id}`, c.title, ctx, load) : null,
              ),
            ),
          )
        : [h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Договоров пока нет.'))]),
    );
  }

  box.append(
    write ? h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', onclick: () => addContractModal(project, load) }, '+ Добавить договор')) : null,
    sum,
    list,
  );
  await load();
}
