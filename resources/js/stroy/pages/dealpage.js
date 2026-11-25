// Страница сделки в два столбца: слева поля и задачи, справа лента. Сверху полоса этапов.
import { api } from '../api.js';
import { EVENT_LABEL, EVENT_TONE, automationNotes, fmtTime } from '../deal-util.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';
import { offerSection } from './offer-section.js';

export async function dealPage(ctx, id) {
  const [first, pipelines, team, parties] = await Promise.all([
    api.get(`/deals/${id}`),
    api.get('/pipelines'),
    api.get('/team'),
    api.get('/counterparties'),
  ]);
  const pipeline = pipelines.find((p) => p.id === first.pipeline_id);
  const people = Object.fromEntries(team.map((m) => [m.id, m.name]));
  const page = h('div', { class: 'dealpage' });
  let composerTab = 'note';

  async function act(request, message) {
    try {
      const result = await request();
      if (message) toast(message);
      if (result && result.events) for (const note of automationNotes(result)) toast(note);
      draw(await api.get(`/deals/${id}`));
    } catch (error) {
      toast(errText(error), 'bad');
    }
  }

  function stageBar(deal) {
    const currentIndex = pipeline.stages.findIndex((s) => s.id === deal.stage_id);
    return h(
      'ol',
      { class: 'stagebar', 'aria-label': 'Этапы сделки' },
      pipeline.stages.map((s, i) => {
        const tone = s.kind === 'won' ? ' won' : s.kind === 'lost' ? ' lost' : '';
        const state = i < currentIndex && s.kind === 'open' ? ' passed' : i === currentIndex ? ' current' : '';
        return h(
          'li',
          null,
          h(
            'button',
            {
              type: 'button',
              class: 'step' + tone + state,
              disabled: !can(ctx, 'deals.write') && i !== currentIndex ? true : null,
              'aria-current': i === currentIndex ? 'step' : null,
              onclick: () => i !== currentIndex && act(() => api.post(`/deals/${id}/move`, { stage_id: s.id }), `Этап: ${s.name}`),
            },
            s.name,
          ),
        );
      }),
    );
  }

  function leftPane(deal) {
    const peopleOptions = { '': 'Не назначен' };
    for (const m of team) peopleOptions[m.id] = m.name;
    const partyOptions = { '': 'Не указан' };
    for (const c of parties) partyOptions[c.id] = c.name;
    const form = h(
      'form',
      {
        novalidate: true,
        onsubmit: (event) => {
          event.preventDefault();
          const f = event.target.elements;
          act(
            () =>
              api.patch(`/deals/${id}`, {
                title: f.title.value,
                amount: Number(f.amount.value) || 0,
                address: f.address.value,
                due_date: f.due_date.value || null,
                responsible_id: f.responsible_id.value ? Number(f.responsible_id.value) : null,
                counterparty_id: f.counterparty_id.value ? Number(f.counterparty_id.value) : null,
              }),
            'Сохранено',
          );
        },
      },
      field('Бюджет, ₽', h('input', { name: 'amount', type: 'number', min: '0', value: String(deal.amount), inputmode: 'decimal' })),
      field('Название', h('input', { name: 'title', value: deal.title })),
      field('Контакт', select(partyOptions, deal.counterparty_id ? String(deal.counterparty_id) : '', { name: 'counterparty_id' })),
      field('Ответственный', select(peopleOptions, deal.responsible_id ? String(deal.responsible_id) : '', { name: 'responsible_id' })),
      field('Срок', h('input', { name: 'due_date', type: 'date', value: deal.due_date || '' })),
      field('Адрес участка', h('input', { name: 'address', value: deal.address })),
      can(ctx, 'deals.write') ? h('button', { class: 'btn small primary', type: 'submit' }, 'Сохранить') : h('p', { class: 'muted' }, 'Менять сделку может менеджер или владелец.'),
    );
    if (!can(ctx, 'deals.write')) form.querySelectorAll('input, select').forEach((el) => { el.disabled = true; });

    const taskInput = h('input', { placeholder: 'Новая задача', 'aria-label': 'Новая задача' });
    const todos = h(
      'div',
      null,
      h('h2', { class: 'pane-title' }, 'Задачи'),
      deal.todos.length
        ? h(
            'div',
            { class: 'list' },
            deal.todos.map((t) =>
              h(
                'label',
                { class: 'item todo' + (t.done ? ' done' : '') },
                h(
                  'span',
                  { class: 'row' },
                  h('input', { type: 'checkbox', checked: t.done, onchange: (event) => act(() => api.patch(`/todos/${t.id}`, { done: event.target.checked })) }),
                  h('span', null, t.title),
                ),
                h('span', { class: 'side-info' }, t.due_date ? badge(fmtDate(t.due_date), t.overdue ? 'red' : '') : null),
              ),
            ),
          )
        : h('p', { class: 'muted' }, 'Задач нет.'),
      h(
        'form',
        {
          class: 'row',
          onsubmit: (event) => {
            event.preventDefault();
            const title = taskInput.value.trim();
            if (title) act(() => api.post('/todos', { title, deal_id: id, project_id: deal.project_id }), 'Задача добавлена');
          },
        },
        h('div', { style: 'flex:1;min-width:140px' }, taskInput),
        h('button', { class: 'btn small', type: 'submit' }, 'Добавить'),
      ),
    );

    return h(
      'div',
      { class: 'pane pane-left' },
      h('h2', { class: 'pane-title' }, 'Основное'),
      deal.project_id
        ? h('p', null, h('a', { class: 'btn small primary', id: 'open-object', href: `#/project/${deal.project_id}` }, 'Открыть объект →'))
        : h('p', { class: 'muted' }, 'Объект появится, когда сделка дойдёт до этапа с автоматикой «Создать объект».'),
      form,
      todos,
    );
  }

  function rightPane(deal) {
    const noteInput = h('textarea', { rows: '3', placeholder: 'Заметка: о чём договорились, что важно', 'aria-label': 'Заметка', id: 'note-text' });
    const dueInput = h('input', { type: 'date', 'aria-label': 'Срок задачи' });
    const taskTitle = h('input', { placeholder: 'Что нужно сделать', 'aria-label': 'Новая задача в ленте' });
    const tabs = h(
      'div',
      { class: 'composer-tabs' },
      [['note', 'Заметка'], ['task', 'Задача']].map(([key, label]) =>
        h('button', { type: 'button', class: 'ctab' + (composerTab === key ? ' on' : ''), onclick: () => { composerTab = key; draw(deal); } }, label),
      ),
    );
    const composer =
      composerTab === 'note'
        ? h(
            'div',
            null,
            noteInput,
            h('button', { class: 'btn small primary', type: 'button', style: 'margin-top:8px', onclick: () => { const text = noteInput.value.trim(); if (text) act(() => api.post(`/deals/${id}/notes`, { text }), 'Заметка добавлена'); } }, 'Добавить заметку'),
          )
        : h(
            'div',
            { class: 'row' },
            h('div', { style: 'flex:1;min-width:160px' }, taskTitle),
            dueInput,
            h('button', { class: 'btn small primary', type: 'button', onclick: () => { const title = taskTitle.value.trim(); if (title) act(() => api.post('/todos', { title, due_date: dueInput.value || null, deal_id: id, project_id: deal.project_id }), 'Задача добавлена'); } }, 'Поставить'),
          );

    return h(
      'div',
      { class: 'pane pane-right' },
      can(ctx, 'deals.write') ? h('div', { class: 'composer' }, tabs, composer) : null,
      h('h2', { class: 'pane-title' }, 'История'),
      h(
        'div',
        { class: 'feed' },
        deal.events.map((e) =>
          h(
            'div',
            { class: 'feed-item ' + e.kind },
            h('div', { class: 'feed-head' }, badge(EVENT_LABEL[e.kind] || e.kind, EVENT_TONE[e.kind] || ''), e.user_id ? h('strong', null, people[e.user_id] || '') : null, h('span', { class: 'muted' }, fmtTime(e.created_at))),
            h('div', { class: 'feed-text' }, e.text),
          ),
        ),
      ),
    );
  }

  function draw(deal) {
    page.replaceChildren(
      h('a', { href: '#/deals', class: 'muted' }, '← Сделки'),
      h(
        'div',
        { class: 'page-head', style: 'margin-top:8px' },
        h(
          'div',
          null,
          h('h1', null, deal.title),
          h('p', { class: 'muted' }, deal.amount ? fmtMoney(deal.amount) : 'Бюджет не указан', ' ', deal.state === 'won' ? badge('Успешно', 'green') : deal.state === 'lost' ? badge('Отказ', 'red') : null, deal.is_demo ? ' ' : null, deal.is_demo ? badge('Демо', 'orange') : null),
        ),
        can(ctx, 'deals.write') ? h('button', { class: 'btn small bad', id: 'delete-deal', type: 'button', onclick: () => confirmDialog('Удалить сделку?', `Сделка «${deal.title}» уйдёт в корзину на 30 дней, её можно будет вернуть. Объект и договор, созданные из неё, останутся.`, 'Удалить', async () => { try { await api.del(`/deals/${id}`); toast('Сделка в корзине'); ctx.go('#/deals'); } catch (error) { toast(errText(error), 'bad'); } }) }, 'Удалить сделку') : null,
      ),
      stageBar(deal),
      h('div', { class: 'two-pane' }, leftPane(deal), rightPane(deal)),
      offerSection(ctx, deal, async () => draw(await api.get(`/deals/${id}`))),
    );
  }

  draw(first);
  return page;
}
