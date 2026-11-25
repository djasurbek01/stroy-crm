import { api } from '../api.js';
import { badge, fmtDate, fmtMoney, h } from '../dom.js';
import { PROJECT_STATUS } from '../labels.js';
import { ROLE_PLAIN } from '../ops-labels.js';
import { kpiCard } from './finance.js';

function projectCard(p) {
  const f = p.finance;
  return h('a', { class: 'card pf-card' + (p.attention ? ' attention' : ''), href: `#/project/${p.id}/schedule` },
    h('div', { class: 'row', style: 'justify-content:space-between;align-items:flex-start' },
      h('div', { style: 'min-width:0' }, h('strong', null, p.name), h('p', { class: 'muted' }, [p.customer, p.address].filter(Boolean).join(' · ') || 'Без адреса')),
      badge(PROJECT_STATUS[p.status] || p.status)),
    h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': String(p.progress), 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': 'Готовность' },
      h('div', { class: 'progress-fill' + (p.progress >= 100 ? ' done' : ''), style: `width:${p.progress}%` })),
    h('p', { class: 'muted' }, `Готовность ${p.progress}%${p.current_stage ? ` · сейчас: ${p.current_stage}` : ''}`),
    p.planned_end ? h('p', { class: 'muted' }, `Срок ${fmtDate(p.planned_end)}`, p.deviation_days > 0 ? [` · прогноз ${fmtDate(p.forecast_end)} `, badge(`+${p.deviation_days} дн.`, 'red')] : ' · по графику') : null,
    h('p', { class: 'muted' }, [p.manager ? `Руководитель: ${p.manager}` : '', p.foreman ? `прораб: ${p.foreman}` : ''].filter(Boolean).join(' · ') || null),
    f && f.contract_value ? h('p', { class: 'muted' }, `Договор ${fmtMoney(f.contract_value)} · получено ${fmtMoney(f.received)} · расходы ${fmtMoney(f.fact_total)}`) : null,
    p.problems.length ? h('ul', { class: 'problems' }, p.problems.slice(0, 4).map((t) => h('li', null, t))) : h('p', { class: 'ok' }, 'Проблем не видно'));
}

export async function portfolioPage() {
  const [data, load] = await Promise.all([api.get('/portfolio'), api.get('/portfolio/workload')]);
  const k = data.kpi;
  const m = k.money;
  const attention = data.projects.filter((p) => p.attention);
  return h('div', null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', null, 'Панель руководителя'), h('p', { class: 'muted' }, attention.length ? `Объектов, на которые стоит посмотреть: ${attention.length}` : 'Все объекты идут спокойно'))),
    h('div', { class: 'kpis' },
      kpiCard('Объектов в работе', `${k.projects_active} из ${k.projects_total}`), kpiCard('Отстают от графика', String(k.projects_overdue), k.projects_overdue ? 'bad' : ''),
      kpiCard('Близки к сдаче', String(k.projects_finishing)), kpiCard('Задач на сегодня', String(k.tasks_today)),
      kpiCard('Задач просрочено', String(k.tasks_overdue), k.tasks_overdue ? 'bad' : ''), kpiCard('Ждут проверки', String(k.tasks_in_review)),
      kpiCard('Заявок на материалы', String(k.requests_open)), kpiCard('Материалов мало', String(k.low_stock), k.low_stock ? 'bad' : ''),
      m ? kpiCard('Получено от заказчиков', fmtMoney(m.received)) : null, m ? kpiCard('Ожидается', fmtMoney(m.expected)) : null,
      m ? kpiCard('Просрочено заказчиками', fmtMoney(m.overdue), m.overdue ? 'bad' : '') : null, m ? kpiCard('Потрачено', fmtMoney(m.expenses)) : null),
    data.projects.length ? h('div', { class: 'pf-grid' }, data.projects.map(projectCard)) : h('div', { class: 'empty' }, h('p', null, 'Объектов пока нет.')),
    h('section', { class: 'card' }, h('h2', null, 'Нагрузка команды'),
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', null, h('tr', null, ['Сотрудник', 'Роль', 'Открыто задач', 'Просрочено', 'На проверке', 'Объектов'].map((t) => h('th', null, t)))),
        h('tbody', null, load.map((w) => h('tr', { class: w.overdue_tasks ? 'over' : '' }, h('td', null, w.name), h('td', null, ROLE_PLAIN[w.role] || w.role), h('td', null, String(w.open_tasks)), h('td', null, String(w.overdue_tasks)), h('td', null, String(w.in_review)), h('td', null, String(w.projects)))))))));
}
