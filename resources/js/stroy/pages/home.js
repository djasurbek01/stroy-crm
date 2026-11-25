// Рабочий стол: главное за один экран. Каждый блок ведёт в свой раздел.
import { api } from '../api.js';
import { EVENT_LABEL, EVENT_TONE, fmtTime } from '../deal-util.js';
import { badge, field, fmtDate, fmtMoney, h, modal, plural, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';
import { quickReceipt } from './receipt.js';

const MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

function widget(title, href, ...content) {
  return h(
    'section',
    { class: 'widget' },
    h('header', { class: 'widget-head' }, h('h2', null, title), href ? h('a', { href, class: 'muted' }, 'Открыть →') : null),
    ...content,
  );
}

export function goalBlock(goal, ctx, reload) {
  const [year, month] = goal.month.split('-').map(Number);
  const label = `${MONTHS[month - 1]} ${year}`;
  const box = h('div', { class: 'goal' });
  if (!goal.amount) {
    box.append(
      h('p', { class: 'muted' }, `План продаж на ${label} не задан. Успешных сделок за месяц: ${goal.won_count} на ${fmtMoney(goal.won_sum)}.`),
      can(ctx, 'goal.write') ? h('button', { class: 'btn small primary', id: 'set-goal', type: 'button', onclick: () => goalModal(goal, reload) }, 'Задать план') : null,
    );
    return box;
  }
  const percent = Math.min(goal.progress ?? 0, 100);
  box.append(
    h('div', { class: 'goal-line' }, h('strong', null, fmtMoney(goal.won_sum)), h('span', { class: 'muted' }, ` из ${fmtMoney(goal.amount)} · ${goal.progress}%`)),
    h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': String(percent), 'aria-valuemin': '0', 'aria-valuemax': '100' }, h('div', { class: 'progress-fill' + (goal.progress >= 100 ? ' done' : ''), style: `width:${percent}%` })),
    h('p', { class: 'muted' }, `${label}: успешных сделок ${goal.won_count}`),
    can(ctx, 'goal.write') ? h('button', { class: 'btn small', type: 'button', onclick: () => goalModal(goal, reload) }, 'Изменить план') : null,
  );
  return box;
}

export function goalModal(goal, onSaved) {
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('План на месяц, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '1000', value: goal.amount ? String(goal.amount) : '', inputmode: 'decimal', required: true }), 'Сумма успешных сделок, которую хотите закрыть за месяц'),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')),
  );
  const dialog = modal('План продаж', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    try {
      await api.put('/analytics/goal', { month: goal.month, amount: Number(form.elements.amount.value) || 0 });
      dialog.close();
      toast('План сохранён');
      onSaved();
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

export async function homePage(ctx) {
  const data = await api.get('/home');
  const reload = () => ctx.go('#/home');
  const maxCount = Math.max(1, ...data.funnel.map((s) => s.count));

  const kpi = (label, value, note, href, tone = '') =>
    h('a', { class: 'kpi ' + tone, href }, h('div', { class: 'kpi-label' }, label), h('div', { class: 'kpi-value' }, value), note ? h('div', { class: 'kpi-note' }, note) : null);

  return h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, `Здравствуйте, ${ctx.me.name.split(' ')[0]}`), h('p', { class: 'muted' }, 'Главное по вашим сделкам, задачам, документам и деньгам.')),
      h('div', { class: 'row' },
        can(ctx, 'timesheet.write') ? h('a', { class: 'btn', id: 'quick-hours', href: '#/timesheet' }, 'Отметить часы') : null,
        can(ctx, 'documents.write') ? h('button', { class: 'btn primary', id: 'quick-receipt', type: 'button', onclick: () => quickReceipt(reload) }, '+ Чек') : null,
      ),
    ),
    h(
      'div',
      { class: 'kpis' },
      kpi('В работе', `${data.deals.open} ${plural(data.deals.open, ['сделка', 'сделки', 'сделок'])}`, fmtMoney(data.deals.open_sum), '#/deals'),
      can(ctx, 'leads.manage') && data.deals.unsorted ? kpi('Новые заявки', String(data.deals.unsorted), 'ждут решения', '#/deals', 'bad') : null,
      kpi('Мои задачи', String(data.tasks.total), data.tasks.overdue ? `просрочено: ${data.tasks.overdue}` : data.tasks.today ? `на сегодня: ${data.tasks.today}` : 'всё в срок', '#/tasks', data.tasks.overdue ? 'bad' : ''),
      kpi('Ждут согласования', String(data.documents.to_approve), data.documents.overdue ? `просрочено документов: ${data.documents.overdue}` : 'у вас на столе', '#/tasks', data.documents.overdue ? 'bad' : ''),
      data.money ? kpi('Неоплаченные счета', String(data.money.unpaid_count), fmtMoney(data.money.unpaid_sum), '#/payments') : null,
    ),
    h(
      'div',
      { class: 'widgets' },
      widget('План продаж', can(ctx, 'analytics.read') ? '#/analytics' : null, goalBlock(data.goal, ctx, reload)),
      widget(
        'Воронка',
        '#/deals',
        h(
          'div',
          { class: 'funnel-mini' },
          data.funnel.map((s) =>
            h(
              'div',
              { class: 'frow' },
              h('span', { class: 'fname' }, s.name),
              h('span', { class: 'fbar' }, h('span', { class: 'fbar-fill' + (s.kind === 'won' ? ' won' : ''), style: `width:${Math.round((s.count / maxCount) * 100)}%` })),
              h('span', { class: 'fval' }, String(s.count)),
            ),
          ),
        ),
      ),
      widget(
        'Задачи на сегодня',
        '#/tasks',
        data.tasks.items.length
          ? h('div', { class: 'list' }, data.tasks.items.map((t) => h('div', { class: 'item' }, h('span', null, t.title), t.due_date ? badge(fmtDate(t.due_date), t.overdue ? 'red' : '') : null)))
          : h('p', { class: 'muted' }, 'Открытых задач нет.'),
      ),
      data.money && widget(
        'Деньги за месяц',
        '#/payments',
        h('div', { class: 'money' }, h('div', null, h('div', { class: 'muted' }, 'Ушло'), h('strong', null, fmtMoney(data.money.out_month))), h('div', null, h('div', { class: 'muted' }, 'Пришло'), h('strong', { class: 'plus' }, fmtMoney(data.money.in_month)))),
      ),
      widget(
        'Что происходило',
        '#/deals',
        data.recent.length
          ? h(
              'div',
              { class: 'feed compact' },
              data.recent.map((e) =>
                h('a', { class: 'feed-item ' + e.kind, href: `#/deal/${e.deal_id}` }, h('div', { class: 'feed-head' }, badge(EVENT_LABEL[e.kind] || e.kind, EVENT_TONE[e.kind] || ''), h('strong', null, e.deal), h('span', { class: 'muted' }, fmtTime(e.created_at))), h('div', { class: 'feed-text' }, e.text)),
              ),
            )
          : h('p', { class: 'muted' }, 'Событий пока нет.'),
      ),
    ),
  );
}
