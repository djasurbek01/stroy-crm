import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { PRIORITY, PRIORITY_TONE, TASK_STATUS, TASK_STATUS_TONE } from '../ops-labels.js';
import { can } from '../perm.js';

export const TASK_KIND = { task: 'Задача', order: 'Предписание' };
export const TASK_SIDE = { contractor: 'Делает подрядчик', customer: 'Делает заказчик' };

/** Одна строка списка для страницы заказчика (без внутренних данных). */
export function taskRow(task, actions) {
  return h('div', { class: 'item' + (task.done ? ' is-done' : '') },
    h('div', { style: 'flex:1;min-width:0' },
      h('strong', { class: task.done ? 'struck' : '' }, task.title), ' ',
      badge(TASK_KIND[task.kind], task.kind === 'order' ? 'orange' : ''), ' ',
      badge(TASK_SIDE[task.side], task.side === 'customer' ? 'blue' : ''),
      task.done ? [' ', badge('Выполнено', 'green')] : null,
      task.overdue ? [' ', badge('Просрочено', 'red')] : null,
      task.source === 'customer' ? [' ', badge('От заказчика', 'blue')] : null,
      h('p', { class: 'muted' }, task.due_date ? `Срок: ${fmtDate(task.due_date)}` : 'Без срока', task.author ? ` · ${task.author}` : '', task.note ? ` · ${task.note}` : ''),
    ),
    actions || null,
  );
}

/**
 * Какие переходы статуса показать этому человеку. ЛПР (поставил задачу или руководит объектом) принимает
 * и возвращает работу, в том числе уже выполненную. Исполнитель сдаёт работу на проверку, а не закрывает сам.
 */
function transitions(task, ctx) {
  const decider = task.can_decide;
  const mine = task.assignee_id === ctx.me.id;
  if (!decider && !mine && !task.can_edit) return [];
  if (task.needs_extension) return [];
  const out = [];
  const s = task.status;
  if (s === 'new' || s === 'rejected') out.push({ to: 'in_progress', label: 'В работу', primary: true });
  if (s === 'in_progress' || s === 'new') out.push({ to: 'waiting_materials', label: 'Нет материалов', reason: 'Чего не хватает?' });
  if (s === 'waiting_materials') out.push({ to: 'in_progress', label: 'Материалы есть', primary: true });
  if (['new', 'in_progress', 'rejected'].includes(s)) {
    if (decider && !task.requires_review && s !== 'rejected') out.push({ to: 'done', label: 'Выполнено', primary: true });
    else out.push({ to: 'in_review', label: 'Сдать на проверку', primary: s !== 'rejected' });
  }
  if (s === 'in_review' && decider) {
    out.push({ to: 'done', label: 'Принять', primary: true });
    out.push({ to: 'rejected', label: 'Вернуть на доработку', reason: 'Что не сделано или что исправить?', bad: true });
  }
  if (s === 'done' && decider) out.push({ to: 'rejected', label: 'Вернуть на доработку', reason: 'Что на самом деле не сделано?', bad: true });
  return out;
}

function reasonModal(title, prompt, onSend) {
  const input = h('textarea', { rows: '3', maxlength: '300', 'aria-label': prompt, placeholder: prompt });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const dialog = modal(title, h('div', { class: 'composer' }, error, input, h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'button', onclick: async () => {
    if (!input.value.trim()) { input.focus(); return; }
    try { await onSend(input.value.trim()); dialog.close(); } catch (e) { error.textContent = errText(e); error.classList.remove('hidden'); }
  } }, 'Отправить'))));
}

/** Исполнитель просит продлить срок: новая дата и обязательный комментарий. Решает ЛПР. */
function extensionModal(task, onDone) {
  const date = h('input', { type: 'date', name: 'due_date', min: new Date().toISOString().slice(0, 10) });
  const comment = h('textarea', { rows: '3', maxlength: '500', name: 'comment', placeholder: 'Почему нужно больше времени' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const dialog = modal('Попросить продление срока', h('div', null,
    h('p', { class: 'muted' }, task.due_date ? `Сейчас срок: ${fmtDate(task.due_date)}. Новый срок вступит в силу, когда его согласует ЛПР.` : 'Новый срок вступит в силу, когда его согласует ЛПР.'),
    error,
    field('Новый срок', date),
    field('Комментарий (обязательно)', comment),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', id: 'send-extension', type: 'button', onclick: async () => {
      if (!date.value) { error.textContent = 'Укажите новый срок.'; error.classList.remove('hidden'); return; }
      if (!comment.value.trim()) { error.textContent = 'Напишите, почему нужно продление.'; error.classList.remove('hidden'); return; }
      try { await api.post(`/site-tasks/${task.id}/extension`, { due_date: date.value, comment: comment.value.trim() }); dialog.close(); toast('Запрос на продление отправлен'); onDone(); }
      catch (e) { error.textContent = errText(e); error.classList.remove('hidden'); }
    } }, 'Отправить ЛПР')),
  ));
}

async function decideExtension(task, approved, onDone) {
  if (approved) {
    try { await api.post(`/site-tasks/${task.id}/extension/decide`, { decision: 'approved' }); toast('Продление согласовано'); onDone(); } catch (e) { toast(errText(e), 'bad'); }
    return;
  }
  reasonModal('Отказать в продлении', 'Почему отказываете?', async (comment) => {
    await api.post(`/site-tasks/${task.id}/extension/decide`, { decision: 'rejected', comment });
    toast('В продлении отказано');
    onDone();
  });
}

const EVENT_TONE = { status: 'blue', extension: 'orange', comment: '', created: 'green', due: 'orange', assignee: '' };
const EVENT_KIND = { status: 'Статус', extension: 'Срок', comment: 'Комментарий', created: 'Создана', due: 'Срок', assignee: 'Исполнитель' };

/** Карточка задачи: всё о задаче, история и переписка между исполнителем и ЛПР. */
async function taskCard(taskId, ctx, onDone) {
  const body = h('div');
  const dialog = modal('Задача', body, { onClose: onDone });
  async function draw() {
    const t = await api.get(`/site-tasks/${taskId}`);
    const text = h('textarea', { rows: '2', maxlength: '1000', placeholder: 'Комментарий для исполнителя и ЛПР', 'aria-label': 'Комментарий' });
    body.replaceChildren(
      h('h3', { style: 'margin-top:0' }, t.title),
      h('p', null, badge(TASK_STATUS[t.status] || t.status, TASK_STATUS_TONE[t.status] || ''), ' ', t.overdue ? badge('Просрочено', 'red') : null, ' ',
        t.extension ? badge(`Просит продлить до ${fmtDate(t.extension.due_date)}`, 'orange') : null),
      h('dl', { class: 'kv' },
        h('dt', null, 'Поставил'), h('dd', null, t.created_by_name || t.author || '—'),
        h('dt', null, 'Исполнитель'), h('dd', null, t.assignee_name || 'не назначен'),
        h('dt', null, 'Срок'), h('dd', null, t.due_date ? fmtDate(t.due_date) : 'без срока'),
        t.note ? [h('dt', null, 'Пояснение'), h('dd', null, t.note)] : null,
      ),
      t.reason ? h('p', { class: 'reason' }, t.reason) : null,
      h('h3', { class: 'pane-title' }, 'История'),
      t.events.length ? h('div', null, t.events.map((e) => h('div', { class: 'feed-item' + (e.kind === 'comment' ? ' note' : e.kind === 'status' ? ' moved' : '') },
        h('div', { class: 'feed-head' }, badge(EVENT_KIND[e.kind] || e.kind, EVENT_TONE[e.kind] || ''), h('strong', null, e.author), h('span', { class: 'muted' }, fmtDate(e.created_at) + ', ' + (e.created_at || '').slice(11, 16))),
        h('div', { class: 'feed-text' }, e.text)))) : h('p', { class: 'muted' }, 'Пока ничего не происходило.'),
      h('div', { class: 'composer' }, text, h('div', { class: 'actions' }, h('button', { class: 'btn small primary', id: 'send-task-comment', type: 'button', onclick: async () => {
        if (!text.value.trim()) { text.focus(); return; }
        try { await api.post(`/site-tasks/${t.id}/comments`, { text: text.value.trim() }); await draw(); } catch (e) { toast(errText(e), 'bad'); }
      } }, 'Написать'))),
    );
  }
  try { await draw(); } catch (e) { dialog.close(); toast(errText(e), 'bad'); }
}

function taskModal(task, project, people, stages, onDone) {
  const isNew = !task;
  const canMoveDue = isNew || task.can_decide;
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const peopleOptions = { '': 'Не назначен', ...Object.fromEntries(people.map((m) => [String(m.id), `${m.name} (${m.role_name})`])) };
  const stageOptions = { '': 'Без этапа', ...Object.fromEntries(stages.map((s) => [String(s.id), s.name])) };
  const review = h('input', { type: 'checkbox', name: 'requires_review', checked: task ? task.requires_review : false });
  const form = h(
    'form',
    { novalidate: true },
    error,
    field('Что нужно сделать', h('input', { name: 'title', required: true, maxlength: '300', value: task ? task.title : '', placeholder: 'Например: Подготовить фундамент до 10 ноября' })),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Вид', select({ task: 'Задача', order: 'Предписание' }, task ? task.kind : 'task', { name: 'kind' }))),
      h('div', { style: 'flex:1;min-width:140px' }, field('Кто делает', select(TASK_SIDE, task ? task.side : 'contractor', { name: 'side' }))),
    ),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Исполнитель', select(peopleOptions, task && task.assignee_id ? String(task.assignee_id) : '', { name: 'assignee_id' }), 'Только участники этого объекта')),
      h('div', { style: 'flex:1;min-width:140px' }, field('Приоритет', select(PRIORITY, task ? task.priority : 'normal', { name: 'priority' }))),
    ),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Начало', h('input', { name: 'start_date', type: 'date', value: task && task.start_date ? task.start_date : '' }))),
      h('div', { style: 'flex:1;min-width:140px' }, field('Срок', h('input', { name: 'due_date', type: 'date', value: task && task.due_date ? task.due_date : '', disabled: !canMoveDue }),
        canMoveDue ? null : 'Срок меняет ЛПР. Исполнитель просит продление.')),
    ),
    field('Этап графика', select(stageOptions, task && task.stage_id ? String(task.stage_id) : '', { name: 'stage_id' })),
    field('Пояснение', h('input', { name: 'note', maxlength: '1000', value: task ? task.note : '', placeholder: 'Необязательно' })),
    h('label', { class: 'check-row' }, review, ' Результат нужно проверить и принять'),
    h('div', { class: 'actions' },
      h('button', { class: 'btn primary', type: 'submit' }, isNew ? 'Добавить' : 'Сохранить'),
      task ? h('button', { class: 'btn bad', type: 'button', onclick: () => confirmDialog('Удалить?', `«${task.title}» исчезнет и у заказчика.`, 'Удалить', async () => {
        try { await api.del(`/site-tasks/${task.id}`); dialog.close(); toast('Удалено'); onDone(); } catch (e) { toast(errText(e), 'bad'); }
      }) }, 'Удалить') : null,
    ),
  );
  const dialog = modal(isNew ? 'Новая задача' : 'Задача', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.classList.add('hidden');
    const title = form.elements.title.value.trim();
    if (!title) { error.textContent = 'Напишите, что нужно сделать.'; error.classList.remove('hidden'); return; }
    const num = (v) => (v ? Number(v) : null);
    const body = {
      title, kind: form.elements.kind.value, side: form.elements.side.value, start_date: form.elements.start_date.value || null,
      note: form.elements.note.value.trim(), priority: form.elements.priority.value, assignee_id: num(form.elements.assignee_id.value), stage_id: num(form.elements.stage_id.value),
      requires_review: review.checked,
    };
    if (canMoveDue) body.due_date = form.elements.due_date.value || null;
    try {
      if (isNew) await api.post(`/projects/${project.id}/site-tasks`, body);
      else await api.patch(`/site-tasks/${task.id}`, body);
      dialog.close();
      toast(isNew ? 'Добавлено' : 'Сохранено');
      onDone();
    } catch (e) {
      error.textContent = errText(e);
      error.classList.remove('hidden');
    }
  });
}

export async function siteTasksTab(box, project, ctx) {
  let filter = 'open';
  async function render() {
    const [tasks, people, schedule] = await Promise.all([api.get(`/projects/${project.id}/site-tasks`), api.get(`/projects/${project.id}/members`), api.get(`/projects/${project.id}/stages`)]);
    const creator = can(ctx, 'sitetasks.create');
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const decide = tasks.filter((t) => t.can_decide && (t.status === 'in_review' || t.extension));
    const shown = tasks.filter((t) => (filter === 'open' ? !t.done
      : filter === 'review' ? t.status === 'in_review' || t.extension
        : filter === 'mine' ? t.assignee_id === ctx.me.id && !t.done
          : filter === 'set' ? t.created_by === ctx.me.id
            : filter === 'done' ? t.done : true));
    const open = tasks.filter((t) => !t.done).length;
    const chip = (key, text) => h('button', { class: 'chip' + (filter === key ? ' on' : ''), type: 'button', onclick: () => { filter = key; render(); } }, text);
    box.replaceChildren(
      h('div', { class: 'row', style: 'justify-content:space-between;align-items:center;margin-bottom:8px' },
        h('p', { class: 'muted', style: 'margin:0' }, tasks.length ? `Открыто: ${open} из ${tasks.length}. Задачи с пометкой заказчика видны ему по ссылке.` : 'Общие задачи и предписания. Ставить их может любой участник объекта.'),
        creator ? h('button', { class: 'btn primary', id: 'add-site-task', type: 'button', onclick: () => taskModal(null, project, people, schedule.stages, reload) }, '+ Задача') : null,
      ),
      decide.length ? h('p', { class: 'notice' }, `Ждут вашего решения: ${decide.length} (проверка работы или продление срока).`) : null,
      h('div', { class: 'row', style: 'margin-bottom:8px' }, chip('open', 'Открытые'), chip('mine', 'Мне'), chip('set', 'Поставил я'), chip('review', 'Ждут решения'), chip('done', 'Выполненные'), chip('all', 'Все')),
      shown.length ? h('div', { class: 'list' }, shown.map((t) => staffRow(t, ctx, () => taskModal(t, project, people, schedule.stages, reload), reload)))
        : h('div', { class: 'empty' }, h('p', null, tasks.length ? 'В этом списке пусто.' : 'Задач пока нет. Добавьте первую: заказчик увидит её по своей ссылке.')),
    );
  }
  await render();
}

function staffRow(t, ctx, edit, reload) {
  const mine = t.assignee_id === ctx.me.id;
  const moves = transitions(t, ctx);
  const go = (move) => async () => {
    const send = async (reason) => { await api.post(`/site-tasks/${t.id}/status`, { status: move.to, reason: reason || '' }); reload(); };
    if (move.reason) reasonModal(move.label, move.reason, send);
    else { try { await send(''); } catch (e) { toast(errText(e), 'bad'); } }
  };
  return h('div', { class: 'item col-item' + (t.done ? ' is-done' : '') },
    h('div', { style: 'flex:1;min-width:0' },
      h('strong', { class: t.done ? 'struck' : '' }, t.title), ' ',
      badge(TASK_STATUS[t.status] || t.status, TASK_STATUS_TONE[t.status] || ''), ' ',
      t.priority !== 'normal' ? [badge(PRIORITY[t.priority], PRIORITY_TONE[t.priority]), ' '] : null,
      badge(TASK_KIND[t.kind], t.kind === 'order' ? 'orange' : ''), ' ', badge(TASK_SIDE[t.side], t.side === 'customer' ? 'blue' : ''),
      t.overdue ? [' ', badge('Просрочено', 'red')] : null, t.source === 'customer' ? [' ', badge('От заказчика', 'blue')] : null,
      h('p', { class: 'muted' }, [t.assignee_name ? `Исполнитель: ${t.assignee_name}` : 'Исполнитель не назначен', t.created_by_name ? `поставил ${t.created_by_name}` : '', t.due_date ? `срок ${fmtDate(t.due_date)}` : 'без срока', t.requires_review ? 'с проверкой' : '', t.note].filter(Boolean).join(' · ')),
      t.reason ? h('p', { class: 'reason' }, t.reason) : null,
      t.extension ? h('p', { class: 'reason' }, `${t.extension.requested_by || 'Исполнитель'} просит продлить срок до ${fmtDate(t.extension.due_date)}: ${t.extension.reason}`) : null,
      t.needs_extension && !t.extension ? h('p', { class: 'reason need' }, 'Срок прошёл. Чтобы продолжить работу, попросите продление и дождитесь согласования ЛПР.') : null,
    ),
    h('div', { class: 'row' },
      moves.map((m) => h('button', { class: 'btn small' + (m.primary ? ' primary' : '') + (m.bad ? ' bad' : ''), type: 'button', onclick: go(m) }, m.label)),
      t.extension && t.can_decide ? [
        h('button', { class: 'btn small primary', type: 'button', onclick: () => decideExtension(t, true, reload) }, 'Согласовать продление'),
        h('button', { class: 'btn small bad', type: 'button', onclick: () => decideExtension(t, false, reload) }, 'Отказать'),
      ] : null,
      mine && !t.done && !t.extension && !t.can_decide ? h('button', { class: 'btn small' + (t.needs_extension ? ' primary' : ''), type: 'button', onclick: () => extensionModal(t, reload) }, 'Попросить продление') : null,
      h('button', { class: 'btn small', type: 'button', onclick: () => taskCard(t.id, ctx, reload) }, 'Открыть'),
      t.can_edit ? h('button', { class: 'btn small', type: 'button', onclick: edit }, 'Изменить') : null,
    ),
  );
}
