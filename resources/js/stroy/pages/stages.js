import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

export const STAGE_STATUS = { not_started: 'Не начат', in_progress: 'В работе', done: 'Готов' };
const STAGE_TONE = { not_started: '', in_progress: 'blue', done: 'green' };
const DAY = 86400000;

function dayNumber(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return Date.UTC(y, m - 1, d) / DAY;
}

function stageModal(stage, projectId, schedule, people, onDone) {
  const isNew = !stage;
  const start = h('input', { name: 'start_date', type: 'date', value: stage ? stage.start_date || '' : '' });
  const end = h('input', { name: 'end_date', type: 'date', value: stage ? stage.end_date || '' : '' });
  const percent = h('input', { name: 'percent', type: 'range', min: '0', max: '100', step: '5', value: String(stage ? stage.percent : 0) });
  const percentText = h('strong', null, `${percent.value}%`);
  percent.addEventListener('input', () => { percentText.textContent = `${percent.value}%`; });
  const quick = h('div', { class: 'row' }, [0, 25, 50, 75, 100].map((n) =>
    h('button', { class: 'btn small', type: 'button', onclick: () => { percent.value = String(n); percentText.textContent = `${n}%`; } }, `${n}%`)));
  const others = schedule.stages.filter((s) => !stage || s.id !== stage.id);
  const dependsOptions = { '': 'Не зависит', ...Object.fromEntries(others.map((s) => [String(s.id), s.name])) };
  const peopleOptions = { '': 'Не назначен', ...Object.fromEntries(people.map((m) => [String(m.id), m.name])) };
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const form = h(
    'form',
    { novalidate: true },
    error,
    field('Название этапа', h('input', { name: 'name', placeholder: 'Например: Фундамент', required: true, value: stage ? stage.name : '' })),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Начало', start)),
      h('div', { style: 'flex:1;min-width:140px' }, field('Конец', end)),
    ),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Начнётся после этапа', select(dependsOptions, stage && stage.depends_on_id ? String(stage.depends_on_id) : '', { name: 'depends_on_id' }), 'Если предыдущий задержится, срок сдвинется в прогнозе')),
      h('div', { style: 'flex:1;min-width:140px' }, field('Ответственный', select(peopleOptions, stage && stage.responsible_id ? String(stage.responsible_id) : '', { name: 'responsible_id' }))),
    ),
    h('div', { class: 'field' }, h('span', null, 'Готовность: ', percentText), percent, quick),
    field('Причина задержки', h('input', { name: 'delay_reason', maxlength: '300', placeholder: 'Заполняется, если этап отстаёт от графика', value: stage ? stage.delay_reason : '' })),
    field('Заметка для заказчика', h('input', { name: 'note', maxlength: '500', placeholder: 'Необязательно', value: stage ? stage.note : '' })),
    h('div', { class: 'actions' },
      h('button', { class: 'btn primary', type: 'submit' }, isNew ? 'Добавить этап' : 'Сохранить'),
      stage ? h('button', { class: 'btn bad', type: 'button', onclick: () => confirmDialog('Удалить этап?', `Этап «${stage.name}» исчезнет из графика.`, 'Удалить', async () => {
        try { await api.del(`/work-stages/${stage.id}`); dialog.close(); toast('Этап удалён'); onDone(); } catch (e) { toast(errText(e), 'bad'); }
      }) }, 'Удалить') : null,
    ),
  );
  const dialog = modal(isNew ? 'Новый этап' : 'Этап', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.classList.add('hidden');
    const name = form.elements.name.value.trim();
    if (!name) { error.textContent = 'Напишите название этапа.'; error.classList.remove('hidden'); return; }
    const num = (v) => (v ? Number(v) : null);
    const body = {
      name, start_date: start.value || null, end_date: end.value || null, percent: Number(percent.value), note: form.elements.note.value.trim(),
      depends_on_id: num(form.elements.depends_on_id.value), responsible_id: num(form.elements.responsible_id.value), delay_reason: form.elements.delay_reason.value.trim(),
    };
    try {
      if (isNew) await api.post(`/projects/${projectId}/stages`, body);
      else await api.patch(`/work-stages/${stage.id}`, body);
      dialog.close();
      toast(isNew ? 'Этап добавлен' : 'Сохранено');
      onDone();
    } catch (e) {
      error.textContent = errText(e);
      error.classList.remove('hidden');
    }
  });
}

function shiftButtons(stage, projectId, onDone) {
  const go = async (days) => {
    try { await api.post(`/projects/${projectId}/stages/${stage.id}/shift`, { days }); onDone(); } catch (e) { toast(errText(e), 'bad'); }
  };
  return h('span', { class: 'shift', title: 'Сдвинуть этот этап и все следующие' },
    h('button', { class: 'btn small', type: 'button', 'aria-label': `Сдвинуть «${stage.name}» и следующие на неделю раньше`, onclick: () => go(-7) }, '−7 дн'),
    h('button', { class: 'btn small', type: 'button', 'aria-label': `Сдвинуть «${stage.name}» и следующие на неделю позже`, onclick: () => go(7) }, '+7 дн'),
  );
}

export function gantt(stages) {
  const dated = stages.filter((s) => s.start_date && s.end_date);
  if (!dated.length) return null;
  const from = Math.min(...dated.map((s) => dayNumber(s.start_date)));
  const to = Math.max(...dated.map((s) => Math.max(dayNumber(s.end_date), s.forecast_end ? dayNumber(s.forecast_end) : 0)));
  const span = Math.max(to - from + 1, 1);
  const today = Math.floor(Date.now() / DAY);
  const todayLeft = today >= from && today <= to ? ((today - from) / span) * 100 : null;
  const pct = (days) => (days / span) * 100;
  return h('div', { class: 'gantt', role: 'img', 'aria-label': 'График работ' },
    h('div', { class: 'gantt-scale muted' }, h('span', null, fmtDate(new Date(from * DAY).toISOString())), h('span', null, fmtDate(new Date(to * DAY).toISOString()))),
    stages.map((s) => {
      if (!s.start_date || !s.end_date) {
        return h('div', { class: 'gantt-row' }, h('div', { class: 'gantt-name' }, s.name), h('div', { class: 'gantt-track muted' }, 'Сроки не указаны'));
      }
      const left = pct(dayNumber(s.start_date) - from);
      const width = pct(dayNumber(s.end_date) - dayNumber(s.start_date) + 1);
      const tail = s.forecast_end && dayNumber(s.forecast_end) > dayNumber(s.end_date) && s.status !== 'done'
        ? h('div', { class: 'gantt-tail', title: `Прогноз: до ${fmtDate(s.forecast_end)}`, style: `left:${left + width}%;width:${pct(dayNumber(s.forecast_end) - dayNumber(s.end_date))}%` }) : null;
      return h('div', { class: 'gantt-row' },
        h('div', { class: 'gantt-name' }, s.name),
        h('div', { class: 'gantt-track' },
          todayLeft === null ? null : h('i', { class: 'gantt-today', style: `left:${todayLeft}%` }),
          h('div', { class: 'gantt-bar' + (s.overdue ? ' late' : '') + (s.status === 'done' ? ' done' : ''), style: `left:${left}%;width:${Math.max(width, 1.5)}%` },
            h('div', { class: 'gantt-fill', style: `width:${s.percent}%` }),
            width >= 12 ? h('span', null, `${s.percent}%`) : null,
          ),
          tail,
        ),
      );
    }),
  );
}

/** Строка про сроки объекта: по плану, по прогнозу и отклонение. */
export function scheduleSummary(data) {
  if (!data.planned_end) return null;
  const dev = data.deviation_days;
  return h('p', { class: 'muted schedule-summary' },
    `По плану объект готов ${fmtDate(data.planned_end)}`,
    data.forecast_end && dev > 0 ? [`, по прогнозу ${fmtDate(data.forecast_end)} `, badge(`отставание ${dev} дн.`, 'red')] : data.forecast_end ? [', идёт по графику ', badge('в срок', 'green')] : null);
}

function linkBlock(project, data, ctx, onDone) {
  if (!can(ctx, 'projects.write')) return null;
  const box = h('section', { class: 'card customer-link' });
  if (data.customer_token) {
    const url = `${location.origin}/customer.html?t=${data.customer_token}`;
    const input = h('input', { value: url, readonly: true, 'aria-label': 'Ссылка для заказчика', onfocus: (e) => e.target.select() });
    box.append(
      h('h2', null, 'Ссылка для заказчика'),
      h('p', { class: 'muted' }, 'По этой ссылке заказчик видит этапы, сроки и готовность. Денег и договоров он не видит. Войти по ней можно без пароля.'),
      h('div', { class: 'row' }, input,
        h('button', { class: 'btn small', type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(url); toast('Ссылка скопирована'); } catch { input.select(); toast('Выделила ссылку, скопируйте её'); } } }, 'Копировать'),
        h('a', { class: 'btn small', href: `/customer.html?t=${data.customer_token}`, target: '_blank', rel: 'noopener' }, 'Открыть как заказчик'),
        h('button', { class: 'btn small bad', type: 'button', onclick: () => confirmDialog('Отключить ссылку?', 'Заказчик больше не сможет ей пользоваться. Позже можно создать новую.', 'Отключить', async () => { try { await api.del(`/projects/${project.id}/customer-link`); toast('Ссылка отключена'); onDone(); } catch (e) { toast(errText(e), 'bad'); } }) }, 'Отключить'),
      ),
    );
  } else {
    box.append(
      h('h2', null, 'Показать ход стройки заказчику'),
      h('p', { class: 'muted' }, 'Создайте ссылку, и заказчик по ней увидит этапы и готовность без регистрации.'),
      h('button', { class: 'btn primary', type: 'button', onclick: async () => { try { await api.post(`/projects/${project.id}/customer-link`); onDone(); } catch (e) { toast(errText(e), 'bad'); } } }, 'Создать ссылку'),
    );
  }
  return box;
}

export async function scheduleTab(box, project, ctx) {
  async function render() {
    const editable = can(ctx, 'stages.write');
    const [data, people] = await Promise.all([api.get(`/projects/${project.id}/stages`), api.get('/team')]);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    box.replaceChildren();

    box.append(
      h('div', { class: 'row', style: 'justify-content:space-between;align-items:center;margin-bottom:8px' },
        h('div', { style: 'flex:1;min-width:200px' },
          h('strong', null, `Готовность объекта: ${data.overall}%`),
          h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': String(data.overall), 'aria-valuemin': '0', 'aria-valuemax': '100' },
            h('div', { class: 'progress-fill' + (data.overall >= 100 ? ' done' : ''), style: `width:${data.overall}%` })),
          scheduleSummary(data),
        ),
        editable ? h('button', { class: 'btn primary', id: 'add-stage', type: 'button', onclick: () => stageModal(null, project.id, data, people, reload) }, '+ Этап') : null,
      ),
    );

    if (!data.stages.length) {
      box.append(h('div', { class: 'empty' },
        h('p', null, 'Этапов пока нет. График строится из крупных этапов: фундамент, стены, кровля и так далее.'),
        editable ? h('button', { class: 'btn', id: 'stage-template', type: 'button', onclick: async () => { try { await api.post(`/projects/${project.id}/stages/template`); reload(); } catch (e) { toast(errText(e), 'bad'); } } }, 'Подставить типовой график дома') : null,
      ));
    } else {
      const chart = gantt(data.stages);
      if (chart) box.append(h('div', { class: 'card' }, chart));
      box.append(h('div', { class: 'list' }, data.stages.map((s) => {
        const canAsk = can(ctx, 'inspections.request') && s.percent >= 100 && !s.accepted && s.inspection !== 'requested' && s.inspection !== 'failed';
        return h('div', { class: 'item' },
          h('div', { style: 'flex:1;min-width:0' },
            h('strong', null, s.name), ' ', badge(STAGE_STATUS[s.status], STAGE_TONE[s.status]),
            s.accepted ? [' ', badge('Принят', 'green')] : null,
            s.inspection === 'requested' ? [' ', badge('Ждёт приёмки', 'orange')] : null,
            s.inspection === 'failed' ? [' ', badge('Есть замечания', 'red')] : null,
            s.overdue ? [' ', badge(`Отстаёт на ${s.delay_days} дн.`, 'red')] : null,
            s.blocked_by ? [' ', badge(`Ждёт: ${s.blocked_by}`, 'orange')] : null,
            s.conflict ? [' ', badge('Начало раньше конца предыдущего', 'red')] : null,
            h('p', { class: 'muted' }, [s.start_date && s.end_date ? `${fmtDate(s.start_date)} — ${fmtDate(s.end_date)}` : 'Сроки не указаны', `${s.percent}%`,
              s.forecast_end && s.status !== 'done' && s.forecast_end > s.end_date ? `прогноз ${fmtDate(s.forecast_end)}` : '', s.responsible_name ? `отв.: ${s.responsible_name}` : '', s.note].filter(Boolean).join(' · ')),
            s.delay_reason ? h('p', { class: 'reason' }, `Причина задержки: ${s.delay_reason}`) : (s.needs_reason && editable ? h('p', { class: 'reason need' }, 'Укажите причину задержки: нажмите «Изменить».') : null),
          ),
          h('div', { class: 'row' },
            canAsk ? h('button', { class: 'btn small primary', type: 'button', onclick: async () => { try { await api.post(`/projects/${project.id}/inspections`, { stage_id: s.id }); toast('Этап отправлен на приёмку'); reload(); } catch (e) { toast(errText(e), 'bad'); } } }, 'На приёмку') : null,
            editable ? shiftButtons(s, project.id, reload) : null,
            editable ? h('button', { class: 'btn small', type: 'button', onclick: () => stageModal(s, project.id, data, people, reload) }, 'Изменить') : null,
          ),
        );
      })));
    }
    const link = linkBlock(project, data, ctx, reload);
    if (link) box.append(link);
  }
  await render();
}
