import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, modal, toast } from '../dom.js';
import { errText } from '../labels.js';
import { BUDGET_CATEGORIES, PLAN_STATUS, PLAN_TONE } from '../ops-labels.js';
import { can } from '../perm.js';

function showError(box, err) {
  box.textContent = errText(err);
  box.classList.remove('hidden');
}

function budgetModal(data, projectId, onDone) {
  const contract = h('input', { name: 'contract_value', type: 'number', min: '0', step: '1000', value: String(data.revenue.contract_value || '') });
  const inputs = Object.keys(BUDGET_CATEGORIES).map((c) => {
    const line = data.budget.lines.find((l) => l.category === c);
    return [c, h('input', { type: 'number', min: '0', step: '1000', value: line && line.planned ? String(line.planned) : '', 'aria-label': BUDGET_CATEGORIES[c] })];
  });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    try {
      await api.put(`/projects/${projectId}/budget`, {
        contract_value: Number(contract.value) || 0,
        lines: inputs.filter(([, i]) => Number(i.value) > 0).map(([category, i]) => ({ category, planned: Number(i.value) })),
      });
      dialog.close(); toast('Бюджет сохранён'); onDone();
    } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error,
    field('Стоимость по договору, ₽', contract, 'Сколько заплатит заказчик'),
    h('p', { class: 'muted' }, 'Сколько планируем потратить по статьям:'),
    ...inputs.map(([c, i]) => field(BUDGET_CATEGORIES[c], i)),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')));
  const dialog = modal('Бюджет объекта', form);
}

function planModal(item, projectId, onDone) {
  const title = h('input', { name: 'title', maxlength: '200', value: item ? item.title : '', placeholder: 'Например: Аванс 30%' });
  const due = h('input', { name: 'due_date', type: 'date', value: item ? item.due_date : '' });
  const amount = h('input', { name: 'amount', type: 'number', min: '1', step: '1000', value: item ? String(item.amount) : '' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    const body = { title: title.value.trim(), due_date: due.value, amount: Number(amount.value) };
    if (!body.title || !body.due_date || !(body.amount > 0)) { error.textContent = 'Заполните название, дату и сумму.'; error.classList.remove('hidden'); return; }
    try {
      if (item) await api.patch(`/plan-items/${item.id}`, body); else await api.post(`/projects/${projectId}/plan-items`, body);
      dialog.close(); toast('Сохранено'); onDone();
    } catch (err) { showError(error, err); }
  };
  const remove = () => confirmDialog('Удалить платёж?', item.title, 'Удалить', async () => {
    try { await api.del(`/plan-items/${item.id}`); dialog.close(); onDone(); } catch (err) { toast(errText(err), 'bad'); }
  });
  const form = h('form', { novalidate: true, onsubmit: submit }, error, field('Название', title), field('Срок оплаты', due), field('Сумма, ₽', amount),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить'),
      item && !item.received ? h('button', { class: 'btn bad', type: 'button', onclick: remove }, 'Удалить') : null));
  const dialog = modal(item ? 'Платёж заказчика' : 'Новый платёж заказчика', form);
}

function receiveModal(item, onDone) {
  const amount = h('input', { type: 'number', min: '1', step: '1000', value: String(item.remaining) });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    try { await api.post(`/plan-items/${item.id}/receive`, { amount: Number(amount.value) }); dialog.close(); toast('Поступление записано'); onDone(); } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error,
    h('p', { class: 'muted' }, `${item.title}. Осталось получить ${fmtMoney(item.remaining)}.`), field('Сколько поступило, ₽', amount),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Записать')));
  const dialog = modal('Деньги поступили', form);
}

export const kpiCard = (label, value, tone = '') => h('div', { class: 'kpi' + (tone ? ` ${tone}` : '') }, h('div', { class: 'kpi-label' }, label), h('div', { class: 'kpi-value' }, value));

export async function financeTab(box, project, ctx) {
  async function render() {
    const d = await api.get(`/projects/${project.id}/finance`);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const budgetEdit = can(ctx, 'budget.write');
    const receive = can(ctx, 'finance.write');
    const r = d.revenue;
    const b = d.budget;
    const res = d.result;
    const lines = b.lines.filter((l) => l.planned || l.fact || l.committed);
    const result = res.forecast_margin === null ? 'нет договора' : `${fmtMoney(res.forecast_margin)}${res.forecast_margin_percent !== null ? ` (${res.forecast_margin_percent}%)` : ''}`;
    box.replaceChildren(
      d.alerts.length ? h('div', { class: 'alert-box', role: 'alert' }, d.alerts.map((a) => h('div', null, a))) : null,
      h('div', { class: 'kpis' },
        kpiCard('Договор', fmtMoney(r.contract_value)), kpiCard('Получено', fmtMoney(r.received)),
        kpiCard('Осталось получить', fmtMoney(r.receivable), r.overdue_count ? 'bad' : ''),
        kpiCard('Потрачено', fmtMoney(b.fact_total)), kpiCard('Прогноз расходов', fmtMoney(b.forecast_total), b.over_budget ? 'bad' : ''),
        kpiCard('Прогноз результата', result, res.forecast_margin !== null && res.forecast_margin < 0 ? 'bad' : '')),
      h('section', { class: 'card' },
        h('div', { class: 'row', style: 'justify-content:space-between;align-items:center' }, h('h2', null, 'Бюджет и расходы'),
          budgetEdit ? h('button', { class: 'btn small', id: 'edit-budget', type: 'button', onclick: () => budgetModal(d, project.id, reload) }, 'Задать бюджет') : null),
        lines.length ? h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
          h('thead', null, h('tr', null, ['Статья', 'План', 'Потрачено', 'Заказано', 'Прогноз'].map((t) => h('th', null, t)))),
          h('tbody', null, lines.map((l) => h('tr', { class: l.over ? 'over' : '' },
            h('td', null, BUDGET_CATEGORIES[l.category]), h('td', null, fmtMoney(l.planned)), h('td', null, fmtMoney(l.fact)), h('td', null, fmtMoney(l.committed)),
            h('td', null, fmtMoney(l.forecast), l.over ? [' ', badge('больше плана', 'red')] : null)))))) : h('p', { class: 'muted' }, 'Пока ни бюджета, ни расходов. Задайте бюджет или добавьте чеки, закупки и табель.'),
        h('p', { class: 'muted' }, `Откуда факт: чеки ${fmtMoney(d.sources.receipts)}, закупки ${fmtMoney(d.sources.purchases)}, работы по табелю ${fmtMoney(d.sources.labor)}. Платежи банка (${fmtMoney(d.sources.bank_out)}) показаны отдельно и в сумму не входят.`)),
      h('section', { class: 'card' },
        h('div', { class: 'row', style: 'justify-content:space-between;align-items:center' }, h('h2', null, 'График платежей заказчика'),
          budgetEdit ? h('button', { class: 'btn small', id: 'add-plan', type: 'button', onclick: () => planModal(null, project.id, reload) }, '+ Платёж') : null),
        d.plan.length ? h('div', { class: 'list' }, d.plan.map((i) => h('div', { class: 'item' },
          h('div', { style: 'flex:1;min-width:0' }, h('strong', null, i.title), ' ', badge(PLAN_STATUS[i.status], PLAN_TONE[i.status]),
            h('p', { class: 'muted' }, `${fmtDate(i.due_date)} · ${fmtMoney(i.amount)}${i.received ? ` · получено ${fmtMoney(i.received)}` : ''}`)),
          h('div', { class: 'row' },
            receive && i.remaining > 0 ? h('button', { class: 'btn small primary', type: 'button', onclick: () => receiveModal(i, reload) }, 'Поступили') : null,
            budgetEdit ? h('button', { class: 'btn small', type: 'button', onclick: () => planModal(i, project.id, reload) }, 'Изменить') : null)))) : h('p', { class: 'muted' }, 'Платежей по договору пока нет.')),
    );
  }
  await render();
}
