// Траты по объектам: сколько ушло по чекам и на что. Чеки без суммы или статьи показаны отдельно.
import { api } from '../api.js';
import { badge, fmtMoney, h } from '../dom.js';
import { EXPENSE_CATEGORIES } from '../labels.js';
import { quickReceipt } from './receipt.js';

export async function expensesPage(ctx) {
  const data = await api.get('/expenses/summary');
  const reload = () => ctx.go('#/expenses');
  return h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, 'Траты по объектам'), h('p', { class: 'muted' }, 'Чеки, разложенные по объектам и статьям. Всего: ' + fmtMoney(data.total))),
      h('button', { class: 'btn primary', id: 'add-receipt', type: 'button', onclick: () => quickReceipt(reload) }, '+ Чек'),
    ),
    data.unresolved
      ? h('p', { class: 'notice' }, `Не разнесено чеков: ${data.unresolved}. У них нет суммы или статьи расхода, откройте объект и дополните их.`)
      : null,
    data.projects.length
      ? h(
          'div',
          { class: 'list' },
          data.projects.map((p) =>
            h(
              'a',
              { class: 'item clickable', href: `#/project/${p.project_id}/documents` },
              h(
                'div',
                null,
                h('div', { class: 'title' }, p.name),
                h('div', { class: 'meta' }, Object.entries(p.by_category).map(([key, sum]) => `${EXPENSE_CATEGORIES[key]}: ${fmtMoney(sum)}`).join(' · ') || 'Сумм пока нет'),
              ),
              h('div', { class: 'side-info' }, p.no_amount + p.no_category ? badge('Нужно дополнить', 'orange') : null, h('strong', null, fmtMoney(p.total))),
            ),
          ),
        )
      : h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Чеков пока нет. Нажмите «+ Чек» и сфотографируйте первый.')),
  );
}
