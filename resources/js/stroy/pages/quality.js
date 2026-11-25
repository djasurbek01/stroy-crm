import { api } from '../api.js';
import { badge, field, fmtDate, h, modal, toast } from '../dom.js';
import { errText } from '../labels.js';
import { INSPECTION_STATUS, INSPECTION_TONE } from '../ops-labels.js';
import { can } from '../perm.js';

function resultModal(inspection, onDone) {
  const remarks = [];
  const list = h('div', { class: 'list' });
  const text = h('input', { maxlength: '500', placeholder: 'Что не так? Например: трещина в углу стены', 'aria-label': 'Текст замечания' });
  const notes = h('input', { maxlength: '1000', placeholder: 'Общий комментарий (необязательно)', 'aria-label': 'Комментарий' });
  const fixBy = h('input', { type: 'date', name: 'fix_by' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const redraw = () => list.replaceChildren(...remarks.map((r, i) => h('div', { class: 'item' }, h('span', { style: 'flex:1' }, r),
    h('button', { class: 'btn small', type: 'button', onclick: () => { remarks.splice(i, 1); redraw(); } }, 'Убрать'))));
  const add = () => { const v = text.value.trim(); if (!v) return; remarks.push(v); text.value = ''; redraw(); };
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
  const send = async (result) => {
    error.classList.add('hidden');
    if (result === 'failed') add();
    try {
      await api.post(`/inspections/${inspection.id}/result`, {
        result, notes: notes.value.trim(), fix_by: result === 'failed' ? fixBy.value || null : null,
        remarks: result === 'failed' ? remarks.map((t) => ({ text: t })) : [],
      });
      dialog.close();
      toast(result === 'passed' ? 'Этап принят' : 'Замечания отправлены, по ним созданы задачи');
      onDone();
    } catch (e) { error.textContent = errText(e); error.classList.remove('hidden'); }
  };
  const body = h('div', null, error,
    h('p', { class: 'muted' }, `Этап «${inspection.stage_name}». Если всё в порядке, нажмите «Принять». Если нет, добавьте замечания: по каждому появится задача для прораба.`),
    field('Замечание', h('div', { class: 'row' }, text, h('button', { class: 'btn small', type: 'button', onclick: add }, 'Добавить'))), list,
    field('Исправить до', fixBy), field('Комментарий', notes),
    h('div', { class: 'actions' },
      h('button', { class: 'btn primary', type: 'button', id: 'insp-pass', onclick: () => send('passed') }, 'Принять этап'),
      h('button', { class: 'btn bad', type: 'button', id: 'insp-fail', onclick: () => send('failed') }, 'Есть замечания')));
  const dialog = modal('Результат проверки', body);
}

export async function qualityTab(box, project, ctx) {
  async function render() {
    const [items, stages] = await Promise.all([api.get(`/projects/${project.id}/inspections`), api.get(`/projects/${project.id}/stages`)]);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const perform = can(ctx, 'inspections.perform');
    const request = can(ctx, 'inspections.request');
    const busy = (id) => items.some((i) => i.stage_id === id && (i.status === 'requested' || i.status === 'failed'));
    const ready = stages.stages.filter((s) => s.percent >= 100 && !s.accepted && !busy(s.id));
    const ask = (s) => async () => {
      try { await api.post(`/projects/${project.id}/inspections`, { stage_id: s.id }); toast('Отправлено на приёмку'); reload(); } catch (e) { toast(errText(e), 'bad'); }
    };
    const again = (i) => async () => {
      try { await api.post(`/inspections/${i.id}/reinspect`, {}); toast('Повторная проверка запрошена'); reload(); } catch (e) { toast(errText(e), 'bad'); }
    };
    const row = (i) => h('div', { class: 'item col-item' },
      h('div', { style: 'flex:1;min-width:0' },
        h('strong', null, i.stage_name || 'Этап'), ' ', badge(INSPECTION_STATUS[i.status], INSPECTION_TONE[i.status]),
        h('p', { class: 'muted' }, [`Запрос: ${fmtDate(i.created_at)}`, i.inspector ? `проверил: ${i.inspector}` : '', i.fix_by ? `исправить до ${fmtDate(i.fix_by)}` : '', i.notes].filter(Boolean).join(' · ')),
        i.remarks.length ? h('ul', { class: 'remarks' }, i.remarks.map((r) => h('li', null, r.text, ' ', badge(r.task_status === 'done' ? 'исправлено' : 'не исправлено', r.task_status === 'done' ? 'green' : 'orange')))) : null),
      h('div', { class: 'row' },
        i.status === 'requested' && perform ? h('button', { class: 'btn small primary', type: 'button', onclick: () => resultModal(i, reload) }, 'Проверить') : null,
        i.status === 'failed' && request ? h('button', { class: 'btn small', type: 'button', onclick: again(i) }, 'Замечания устранены') : null));
    box.replaceChildren(
      h('p', { class: 'muted' }, 'Когда этап готов на 100%, прораб отправляет его на приёмку. Ответственный принимает этап или записывает замечания: по каждому создаётся задача.'),
      ready.length && request ? h('div', { class: 'card' }, h('strong', null, 'Готовы к приёмке'), h('div', { class: 'list' }, ready.map((s) => h('div', { class: 'item' }, h('span', { style: 'flex:1' }, s.name),
        h('button', { class: 'btn small primary', type: 'button', onclick: ask(s) }, 'На приёмку'))))) : null,
      items.length ? h('div', { class: 'list' }, items.map(row)) : h('div', { class: 'empty' }, h('p', null, 'Приёмок пока не было. Они появятся, когда этап будет готов на 100%.')));
  }
  await render();
}
