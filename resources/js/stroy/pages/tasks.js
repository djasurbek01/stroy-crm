import { api } from '../api.js';
import { badge, fmtDate, h, toast } from '../dom.js';
import { DOC_STATUS, DOC_TONE, DOC_TYPES, errText } from '../labels.js';
import { openDocument } from './documents.js';

const WHEN = { all: 'Все сроки', overdue: 'Просрочено', today: 'Сегодня', later: 'Позже' };

export async function tasksPage(ctx) {
  const [docs, projects, team] = await Promise.all([api.get('/tasks'), api.get('/projects'), api.get('/team')]);
  const names = Object.fromEntries(projects.map((p) => [p.id, p.name]));
  const people = Object.fromEntries(team.map((m) => [m.id, m.name]));
  const state = { mine: true, when: 'all', done: false };
  const reload = () => ctx.go(location.hash);
  const todoBox = h('div');
  const chips = h('div', { class: 'chips' });

  const titleInput = h('input', { placeholder: 'Новая задача', 'aria-label': 'Новая задача', id: 'new-todo' });
  const dueInput = h('input', { type: 'date', 'aria-label': 'Срок' });

  async function loadTodos() {
    const todos = await api.get('/todos', { mine: state.mine ? 'true' : undefined, done: state.done ? 'true' : 'false' });
    const today = new Date().toISOString().slice(0, 10);
    const shown = todos.filter((t) => {
      if (state.done || state.when === 'all') return true;
      if (state.when === 'overdue') return t.overdue;
      if (state.when === 'today') return t.due_date === today;
      return !t.due_date || t.due_date > today;
    });
    todoBox.replaceChildren(
      shown.length
        ? h(
            'div',
            { class: 'list' },
            shown.map((t) =>
              h(
                'label',
                { class: 'item todo' + (t.done ? ' done' : '') },
                h(
                  'span',
                  { class: 'row' },
                  h('input', {
                    type: 'checkbox',
                    checked: t.done,
                    onchange: async (event) => {
                      try {
                        await api.patch(`/todos/${t.id}`, { done: event.target.checked });
                        loadTodos();
                      } catch (error) {
                        toast(errText(error), 'bad');
                      }
                    },
                  }),
                  h('span', null, t.title),
                ),
                h(
                  'span',
                  { class: 'side-info' },
                  !state.mine && t.assignee_id ? h('span', { class: 'meta' }, people[t.assignee_id] || '') : null,
                  t.deal_id ? h('a', { href: `#/deal/${t.deal_id}`, class: 'muted' }, 'сделка') : null,
                  t.due_date ? badge(fmtDate(t.due_date), t.overdue ? 'red' : '') : null,
                ),
              ),
            ),
          )
        : h('p', { class: 'muted' }, state.done ? 'Выполненных дел пока нет.' : 'Дел нет. Добавьте своё или дождитесь задач от автоматики воронки.'),
    );
  }

  function drawChips() {
    chips.replaceChildren(
      ...[['mine', 'Мои'], ['team', 'Все в команде']].map(([key, label]) =>
        h('button', { class: 'chip' + ((key === 'mine') === state.mine ? ' on' : ''), type: 'button', onclick: () => { state.mine = key === 'mine'; drawChips(); loadTodos(); } }, label),
      ),
      ...Object.entries(WHEN).map(([key, label]) =>
        h('button', { class: 'chip' + (!state.done && state.when === key ? ' on' : ''), type: 'button', onclick: () => { state.when = key; state.done = false; drawChips(); loadTodos(); } }, label),
      ),
      h('button', { class: 'chip' + (state.done ? ' on' : ''), type: 'button', onclick: () => { state.done = true; drawChips(); loadTodos(); } }, 'Выполненные'),
    );
  }

  const docSection = h(
    'div',
    { style: 'margin-top:24px' },
    h('h2', null, 'Документы'),
    docs.length
      ? h(
          'div',
          { class: 'list' },
          docs.map((doc) =>
            h(
              'div',
              { class: 'item clickable', tabindex: '0', role: 'button', onclick: () => openDocument(doc.id, ctx, reload), onkeydown: (e) => e.key === 'Enter' && openDocument(doc.id, ctx, reload) },
              h('div', null, h('div', { class: 'title' }, doc.title), h('div', { class: 'meta' }, `${names[doc.project_id] || 'Объект'} · ${DOC_TYPES[doc.doc_type] || ''}${doc.due_date ? ' · срок ' + fmtDate(doc.due_date) : ''}`)),
              h('div', { class: 'side-info' }, doc.overdue ? badge('Просрочен', 'red') : null, badge(DOC_STATUS[doc.status], DOC_TONE[doc.status])),
            ),
          ),
        )
      : h('p', { class: 'muted' }, 'Нет документов, которые ждут вашего решения или просрочены.'),
  );

  const page = h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Задачи'), h('p', { class: 'muted' }, 'Дела по сделкам и объектам, документы на согласование и просроченные.'))),
    h(
      'form',
      {
        class: 'row',
        style: 'margin-bottom:12px',
        onsubmit: async (event) => {
          event.preventDefault();
          const title = titleInput.value.trim();
          if (!title) return;
          try {
            await api.post('/todos', { title, due_date: dueInput.value || null });
            titleInput.value = '';
            dueInput.value = '';
            loadTodos();
          } catch (error) {
            toast(errText(error), 'bad');
          }
        },
      },
      h('div', { style: 'flex:1;min-width:180px' }, titleInput),
      dueInput,
      h('button', { class: 'btn small primary', type: 'submit' }, 'Добавить'),
    ),
    chips,
    todoBox,
    docSection,
  );
  drawChips();
  await loadTodos();
  return page;
}
