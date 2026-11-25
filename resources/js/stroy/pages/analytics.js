// Аналитика: сделки по этапам, конверсия, длительность этапов, итоги и план продаж.
import { api } from '../api.js';
import { badge, fmtMoney, h, plural, select } from '../dom.js';
import { goalBlock } from './home.js';

const PERIODS = { '': 'За всё время', '30': '30 дней', '90': '90 дней', '365': 'Год' };

export async function analyticsPage(ctx) {
  const pipelines = await api.get('/pipelines');
  const state = { pipeline: pipelines[0].id, days: '' };
  const holder = h('div');

  async function load() {
    const [data, goal] = await Promise.all([
      api.get('/analytics/funnel', { pipeline_id: state.pipeline, days: state.days || undefined }),
      api.get('/analytics/goal'),
    ]);
    const t = data.totals;
    const top = Math.max(1, ...data.stages.map((s) => s.reached));
    const kpi = (label, value, note) => h('div', { class: 'kpi' }, h('div', { class: 'kpi-label' }, label), h('div', { class: 'kpi-value' }, value), note ? h('div', { class: 'kpi-note' }, note) : null);

    holder.replaceChildren(
      h(
        'div',
        { class: 'kpis' },
        kpi('Сделок в работе', String(t.open), fmtMoney(t.open_sum)),
        kpi('Успешных', String(t.won), fmtMoney(t.won_sum)),
        kpi('Отказов', String(t.lost), data.lost.sum ? fmtMoney(data.lost.sum) : ''),
        kpi('Доля успешных', t.win_rate === null ? '—' : `${t.win_rate}%`, 'из закрытых сделок'),
        kpi('Конверсия заявка → оплата', t.overall_conversion === null ? '—' : `${t.overall_conversion}%`, ''),
        kpi('Средний цикл сделки', t.avg_cycle_days === null ? '—' : `${t.avg_cycle_days} ${plural(Math.round(t.avg_cycle_days), ['день', 'дня', 'дней'])}`, 'от заявки до оплаты'),
      ),
      h(
        'section',
        { class: 'widget', style: 'margin-bottom:16px' },
        h('header', { class: 'widget-head' }, h('h2', null, 'План продаж на месяц')),
        goalBlock(goal, ctx, load),
      ),
      h(
        'section',
        { class: 'widget' },
        h('header', { class: 'widget-head' }, h('h2', null, 'Воронка по этапам')),
        h(
          'div',
          { class: 'table-wrap' },
          h(
            'table',
            { class: 'data funnel-table', id: 'funnel-table' },
            h('thead', null, h('tr', null, h('th', null, 'Этап'), h('th', null, 'Дошли'), h('th', null, ''), h('th', null, 'Конверсия'), h('th', null, 'Сейчас на этапе'), h('th', null, 'Бюджет'), h('th', null, 'Среднее время'))),
            h(
              'tbody',
              null,
              data.stages.map((s) =>
                h(
                  'tr',
                  null,
                  h('td', null, h('strong', null, s.name)),
                  h('td', { class: 'num' }, String(s.reached)),
                  h('td', { class: 'bar-cell' }, h('span', { class: 'fbar' }, h('span', { class: 'fbar-fill' + (s.kind === 'won' ? ' won' : ''), style: `width:${Math.round((s.reached / top) * 100)}%` }))),
                  h('td', { class: 'num' }, s.conversion_prev === null ? '—' : `${s.conversion_prev}%`),
                  h('td', { class: 'num' }, String(s.count)),
                  h('td', { class: 'num' }, s.sum ? fmtMoney(s.sum) : '—'),
                  h('td', { class: 'num' }, s.avg_days === null ? '—' : `${s.avg_days} дн.`),
                ),
              ),
            ),
          ),
        ),
        h('p', { class: 'muted', style: 'margin-top:10px' }, 'Конверсия показывает, какая доля сделок дошла до этапа с предыдущего. Среднее время считается по сделкам, которые уже побывали на этапе.'),
      ),
    );
  }

  const pipelineOptions = {};
  for (const p of pipelines) pipelineOptions[p.id] = p.name;
  const page = h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Аналитика'), h('p', { class: 'muted' }, 'Где сделки застревают и сколько денег приносит воронка.'))),
    h(
      'div',
      { class: 'toolbar' },
      pipelines.length > 1 ? select(pipelineOptions, String(state.pipeline), { 'aria-label': 'Воронка', onchange: (e) => { state.pipeline = Number(e.target.value); load(); } }) : null,
      select(PERIODS, '', { id: 'period', 'aria-label': 'Период', onchange: (e) => { state.days = e.target.value; load(); } }),
    ),
    holder,
  );
  await load();
  return page;
}
