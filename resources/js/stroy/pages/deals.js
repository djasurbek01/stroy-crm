import { api } from '../api.js';
import { board } from '../board.js';
import { automationNotes, initials } from '../deal-util.js';
import { badge, field, fmtDate, fmtMoney, h, modal, plural, select, store, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';
import { leadSettingsModal, unsortedBlock } from './unsorted.js';

const KIND_TONE = { won: 'won', lost: 'lost', open: '' };
const KIND_NAMES = { open: 'Обычный этап', won: 'Успешно', lost: 'Провал' };
const COUNT_FORMS = ['сделка', 'сделки', 'сделок'];

export async function dealsPage(ctx) {
  let [pipelines, team, parties] = await Promise.all([api.get('/pipelines'), api.get('/team'), api.get('/counterparties')]);
  const people = Object.fromEntries(team.map((m) => [m.id, m.name]));
  const clients = Object.fromEntries(parties.map((c) => [c.id, c.name]));
  const state = {
    q: '',
    responsible: '',
    view: store('deals_view') === 'list' ? 'list' : 'board',
    sort: { key: 'created_at', dir: 'desc' },
    pipeline: pipelines.find((p) => String(p.id) === store('pipeline')) || pipelines[0],
  };

  const tabs = h('div', { class: 'chips' });
  const boardBox = h('div');
  const unsortedUI = unsortedBlock(ctx, team, clients, () => loadBoard());
  const viewSwitch = h('div', { class: 'seg', role: 'group', 'aria-label': 'Вид' });

  async function reloadPipelines() {
    pipelines = await api.get('/pipelines');
    state.pipeline = pipelines.find((p) => p.id === state.pipeline.id) || pipelines[0];
    drawTabs();
    await loadBoard();
  }

  function drawTabs() {
    tabs.replaceChildren(
      ...pipelines.map((p) =>
        h('button', { class: 'chip' + (p.id === state.pipeline.id ? ' on' : ''), type: 'button', onclick: () => { state.pipeline = p; store('pipeline', String(p.id)); drawTabs(); loadBoard(); } }, p.name),
      ),
      can(ctx, 'pipelines.admin') ? h('button', { class: 'chip', id: 'add-pipeline', type: 'button', onclick: addPipeline }, '+ Воронка') : null,
    );
  }

  function drawViewSwitch() {
    viewSwitch.replaceChildren(
      ...[['board', 'Воронка'], ['list', 'Список']].map(([key, label]) =>
        h('button', { type: 'button', id: `view-${key}`, class: 'seg-btn' + (state.view === key ? ' on' : ''), onclick: () => { state.view = key; store('deals_view', key); drawViewSwitch(); loadBoard(); } }, label),
      ),
    );
  }

  async function quickAdd(title) {
    try {
      const deal = await api.post('/deals', { pipeline_id: state.pipeline.id, title });
      toast('Сделка добавлена');
      for (const note of automationNotes(deal)) toast(note);
    } catch (error) {
      toast(errText(error), 'bad');
    }
    await loadBoard();
  }

  async function moveDeal(dealId, stageId) {
    try {
      const result = await api.post(`/deals/${dealId}/move`, { stage_id: stageId });
      for (const note of automationNotes(result).slice(0, 3)) toast(note);
    } catch (error) {
      toast(errText(error), 'bad');
    }
    await loadBoard();
  }

  function renderBoard(deals) {
    const columns = state.pipeline.stages.map((s, index) => {
      const own = deals.filter((d) => d.stage_id === s.id);
      const sum = own.reduce((acc, d) => acc + d.amount, 0);
      return {
        id: s.id,
        title: s.name,
        tone: KIND_TONE[s.kind],
        meta: own.length ? `${own.length} ${plural(own.length, COUNT_FORMS)}: ${fmtMoney(sum)}` : '',
        quickAdd: index === 0 && can(ctx, 'deals.write') ? quickAdd : null,
      };
    });
    return board({
      columns,
      cards: deals.map((d) => ({ id: d.id, columnId: d.stage_id, deal: d })),
      emptyText: can(ctx, 'deals.write') ? 'Перетащите сюда' : 'Пусто',
      readonly: !can(ctx, 'deals.write'),
      onOpen: (card) => ctx.go(`#/deal/${card.id}`),
      onMove: (card, stageId) => moveDeal(card.id, stageId),
      renderCard: ({ deal }) =>
        h(
          'div',
          null,
          h('div', { class: 'title' }, deal.title),
          deal.counterparty_id ? h('div', { class: 'meta' }, clients[deal.counterparty_id] || '') : null,
          deal.amount ? h('div', { class: 'k-sum' }, fmtMoney(deal.amount)) : null,
          h(
            'div',
            { class: 'k-foot' },
            deal.responsible_id ? h('span', { class: 'avatar', title: people[deal.responsible_id] || '' }, initials(people[deal.responsible_id])) : null,
            deal.due_date ? badge(fmtDate(deal.due_date), deal.due_date < new Date().toISOString().slice(0, 10) && deal.state === 'open' ? 'red' : '') : null,
            deal.project_id ? badge('Объект', 'green') : null,
            deal.state === 'won' ? badge('Успешно', 'green') : deal.state === 'lost' ? badge('Отказ', 'red') : null,
          ),
        ),
    });
  }

  function renderList(deals) {
    const stageName = Object.fromEntries(state.pipeline.stages.map((s) => [s.id, s.name]));
    const order = Object.fromEntries(state.pipeline.stages.map((s, i) => [s.id, i]));
    const value = {
      title: (d) => d.title.toLowerCase(),
      client: (d) => (clients[d.counterparty_id] || '').toLowerCase(),
      stage: (d) => order[d.stage_id],
      amount: (d) => d.amount,
      responsible: (d) => (people[d.responsible_id] || '').toLowerCase(),
      due_date: (d) => d.due_date || '9999',
      created_at: (d) => d.created_at,
    }[state.sort.key];
    const sorted = [...deals].sort((a, b) => (value(a) < value(b) ? -1 : value(a) > value(b) ? 1 : 0) * (state.sort.dir === 'asc' ? 1 : -1));
    const th = (key, label) =>
      h(
        'th',
        { 'aria-sort': state.sort.key === key ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none' },
        h('button', { type: 'button', class: 'th-btn', onclick: () => { state.sort = { key, dir: state.sort.key === key && state.sort.dir === 'asc' ? 'desc' : 'asc' }; loadBoard(); } }, label, state.sort.key === key ? (state.sort.dir === 'asc' ? ' ↑' : ' ↓') : ''),
      );
    if (!sorted.length) return h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Сделок нет.'));
    return h(
      'div',
      { class: 'table-wrap' },
      h(
        'table',
        { class: 'data', id: 'deals-table' },
        h('thead', null, h('tr', null, th('title', 'Сделка'), th('client', 'Клиент'), th('stage', 'Этап'), th('amount', 'Бюджет'), th('responsible', 'Ответственный'), th('due_date', 'Срок'))),
        h(
          'tbody',
          null,
          sorted.map((d) =>
            h(
              'tr',
              { class: 'row-link', tabindex: '0', onclick: () => ctx.go(`#/deal/${d.id}`), onkeydown: (e) => e.key === 'Enter' && ctx.go(`#/deal/${d.id}`) },
              h('td', null, h('strong', null, d.title)),
              h('td', null, clients[d.counterparty_id] || '—'),
              h('td', null, badge(stageName[d.stage_id] || '', d.state === 'won' ? 'green' : d.state === 'lost' ? 'red' : '')),
              h('td', { class: 'num' }, d.amount ? fmtMoney(d.amount) : '—'),
              h('td', null, people[d.responsible_id] || '—'),
              h('td', null, d.due_date ? fmtDate(d.due_date) : '—'),
            ),
          ),
        ),
      ),
    );
  }

  async function loadBoard() {
    const deals = await api.get('/deals', {
      pipeline_id: state.pipeline.id,
      q: state.q,
      responsible_id: state.responsible || undefined,
    });
    boardBox.replaceChildren(state.view === 'board' ? renderBoard(deals) : renderList(deals));
  }

  // --- новая воронка ---
  function addPipeline() {
    const form = h(
      'form',
      { novalidate: true },
      h('div', { class: 'error hidden', role: 'alert' }),
      field('Название воронки', h('input', { name: 'name', placeholder: 'Например: Ремонт квартир', required: true })),
      h('p', { class: 'muted' }, 'Этапы можно будет настроить после создания.'),
      h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Создать')),
    );
    const dialog = modal('Новая воронка', form);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const errorBox = form.querySelector('.error');
      try {
        const created = await api.post('/pipelines', { name: form.elements.name.value });
        dialog.close();
        state.pipeline = created;
        store('pipeline', String(created.id));
        toast('Воронка создана');
        await reloadPipelines();
      } catch (error) {
        errorBox.textContent = errText(error);
        errorBox.classList.remove('hidden');
      }
    });
  }

  // --- новая сделка (полная форма) ---
  function addDeal() {
    const peopleOptions = {};
    for (const m of team) peopleOptions[m.id] = m.name;
    const form = h(
      'form',
      { novalidate: true },
      h('div', { class: 'error hidden', role: 'alert' }),
      field('Что строим', h('input', { name: 'title', placeholder: 'Например: Дом 140 м² для Иванова', required: true })),
      field('Клиент', h('input', { name: 'customer_name', placeholder: 'Фамилия или компания' })),
      field('Телефон клиента', h('input', { name: 'customer_phone', type: 'tel', inputmode: 'tel' })),
      field('Бюджет, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '1', inputmode: 'decimal' })),
      field('Адрес участка', h('input', { name: 'address' }), 'Попадёт в объект, когда будет подписан договор'),
      field('Ответственный', select(peopleOptions, String(ctx.me.id), { name: 'responsible_id' })),
      h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Создать сделку')),
    );
    const dialog = modal('Новая сделка', form);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const errorBox = form.querySelector('.error');
      errorBox.classList.add('hidden');
      const title = form.elements.title.value.trim();
      if (!title) {
        errorBox.textContent = 'Напишите, что строим.';
        errorBox.classList.remove('hidden');
        return;
      }
      try {
        const deal = await api.post('/deals', {
          pipeline_id: state.pipeline.id,
          title,
          customer_name: form.elements.customer_name.value.trim() || null,
          customer_phone: form.elements.customer_phone.value.trim() || null,
          amount: Number(form.elements.amount.value) || 0,
          address: form.elements.address.value.trim(),
          responsible_id: Number(form.elements.responsible_id.value),
        });
        dialog.close();
        toast('Сделка создана');
        for (const note of automationNotes(deal)) toast(note);
        await loadBoard();
      } catch (error) {
        errorBox.textContent = errText(error);
        errorBox.classList.remove('hidden');
      }
    });
  }

  // --- настройка воронки ---
  function openSettings() {
    const body = h('div');
    const dialog = modal('Настройка воронки', body);

    async function act(request, message) {
      try {
        await request();
        if (message) toast(message);
        await reloadPipelines();
        draw();
      } catch (error) {
        toast(errText(error), 'bad');
      }
    }

    function draw() {
      const p = state.pipeline;
      const stages = p.stages;
      const move = (i, delta) => {
        const ids = stages.map((s) => s.id);
        [ids[i], ids[i + delta]] = [ids[i + delta], ids[i]];
        act(() => api.post(`/pipelines/${p.id}/stages/order`, { ids }));
      };
      const newStage = h('input', { placeholder: 'Название нового этапа', 'aria-label': 'Название нового этапа' });
      body.replaceChildren(
        field('Название воронки', h('input', { value: p.name, onchange: (event) => act(() => api.patch(`/pipelines/${p.id}`, { name: event.target.value }), 'Переименовано') })),
        h('h2', null, 'Этапы'),
        h('div', { class: 'list', style: 'margin-bottom:12px' }, stages.map((s, i) =>
          h('div', { class: 'item' },
            h('div', { class: 'row', style: 'flex:1;min-width:200px' },
              h('input', { class: 'inline-input', value: s.name, 'aria-label': 'Название этапа', onchange: (event) => act(() => api.patch(`/stages/${s.id}`, { name: event.target.value })) }),
              select(KIND_NAMES, s.kind, { 'aria-label': 'Тип этапа', onchange: (event) => act(() => api.patch(`/stages/${s.id}`, { kind: event.target.value })) }),
            ),
            h('div', { class: 'side-info' },
              h('button', { class: 'btn small', type: 'button', 'aria-label': 'Выше', disabled: i === 0, onclick: () => move(i, -1) }, '↑'),
              h('button', { class: 'btn small', type: 'button', 'aria-label': 'Ниже', disabled: i === stages.length - 1, onclick: () => move(i, 1) }, '↓'),
              h('button', { class: 'btn small' + (s.automations.length ? ' primary' : ''), type: 'button', onclick: () => automationModal(s, async () => { await reloadPipelines(); draw(); }) }, s.automations.length ? `Автоматика: ${s.automations.length}` : 'Автоматика'),
              h('button', { class: 'btn small bad', type: 'button', 'aria-label': 'Удалить этап', onclick: () => act(() => api.del(`/stages/${s.id}`), 'Этап удалён') }, '✕'),
            ),
          ))),
        h('form', { class: 'row', onsubmit: (event) => { event.preventDefault(); const name = newStage.value.trim(); if (name) act(() => api.post(`/pipelines/${p.id}/stages`, { name }), 'Этап добавлен'); } },
          h('div', { style: 'flex:1;min-width:160px' }, newStage), h('button', { class: 'btn small', type: 'submit' }, '+ Этап')),
        h('div', { class: 'actions', style: 'margin-top:20px' },
          h('button', { class: 'btn bad small', type: 'button', onclick: async () => {
            try { await api.del(`/pipelines/${p.id}`); dialog.close(); toast('Воронка удалена'); state.pipeline = pipelines.find((x) => x.id !== p.id); await reloadPipelines(); }
            catch (error) { toast(errText(error), 'bad'); }
          } }, 'Удалить воронку')),
      );
    }
    draw();
  }

  function automationModal(stage, onSaved) {
    const has = (action) => stage.automations.find((a) => a.action === action);
    const tasksAuto = has('create_tasks');
    const notifyAuto = has('notify');
    const firstTask = tasksAuto && tasksAuto.params.tasks[0];
    const form = h(
      'form',
      { novalidate: true },
      h('p', { class: 'muted' }, `Что делать автоматически, когда сделка попадает на этап «${stage.name}».`),
      h('div', { class: 'error hidden', role: 'alert' }),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'project', checked: Boolean(has('create_project')) }), h('span', null, 'Создать объект и договор', h('small', { class: 'muted' }, ' (сумма, клиент и адрес берутся из сделки, один раз на сделку)'))),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'tasks', checked: Boolean(tasksAuto) }), h('span', null, 'Поставить задачи')),
      h('div', { class: 'indent' },
        field('Задачи, по одной в строке', h('textarea', { name: 'lines', rows: '3', placeholder: 'Собрать пакет документов\nСоставить список закупок' }, tasksAuto ? tasksAuto.params.tasks.map((t) => t.title).join('\n') : '')),
        h('div', { class: 'row' },
          h('div', { style: 'flex:1;min-width:140px' }, field('Кому', select({ responsible: 'Ответственному по сделке', owner: 'Владельцу' }, firstTask && firstTask.assignee === 'owner' ? 'owner' : 'responsible', { name: 'who' }))),
          h('div', { style: 'flex:1;min-width:120px' }, field('Срок, дней', h('input', { name: 'days', type: 'number', min: '0', max: '365', value: String(firstTask ? firstTask.days : 2) }))),
        ),
      ),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'notify', checked: Boolean(notifyAuto) }), h('span', null, 'Уведомить команду в Telegram')),
      h('div', { class: 'indent' }, field('Текст', h('input', { name: 'text', value: notifyAuto ? notifyAuto.params.text : 'Договор подписан: {deal}, {amount} ₽' }), 'Можно использовать {deal}, {client}, {amount}')),
      h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')),
    );
    const dialog = modal(`Автоматика: ${stage.name}`, form);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const f = form.elements;
      const errorBox = form.querySelector('.error');
      const payload = [];
      if (f.project.checked) payload.push({ action: 'create_project', params: {} });
      if (f.tasks.checked) {
        const titles = f.lines.value.split('\n').map((t) => t.trim()).filter(Boolean);
        if (!titles.length) {
          errorBox.textContent = 'Напишите хотя бы одну задачу или снимите галочку.';
          errorBox.classList.remove('hidden');
          return;
        }
        payload.push({ action: 'create_tasks', params: { tasks: titles.map((title) => ({ title, assignee: f.who.value, days: Number(f.days.value) || 0 })) } });
      }
      if (f.notify.checked) {
        if (!f.text.value.trim()) {
          errorBox.textContent = 'Напишите текст уведомления или снимите галочку.';
          errorBox.classList.remove('hidden');
          return;
        }
        payload.push({ action: 'notify', params: { text: f.text.value.trim() } });
      }
      try {
        await api.put(`/stages/${stage.id}/automations`, payload);
        dialog.close();
        toast('Автоматика сохранена');
        await onSaved();
      } catch (error) {
        errorBox.textContent = errText(error);
        errorBox.classList.remove('hidden');
      }
    });
  }

  const search = h('input', {
    type: 'search',
    placeholder: 'Фильтр по названию или адресу',
    'aria-label': 'Фильтр сделок',
    oninput: (() => {
      let timer;
      return (event) => {
        clearTimeout(timer);
        timer = setTimeout(() => { state.q = event.target.value; loadBoard(); }, 250);
      };
    })(),
  });
  const responsibleOptions = { '': 'Все ответственные' };
  for (const m of team) responsibleOptions[m.id] = m.name;
  const responsibleFilter = select(responsibleOptions, '', {
    id: 'filter-responsible',
    'aria-label': 'Ответственный',
    onchange: (event) => { state.responsible = event.target.value; loadBoard(); },
  });

  const page = h(
    'div',
    null,
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, 'Сделки'), h('p', { class: 'muted' }, 'От заявки до сданного объекта. Перетащите карточку на следующий этап.')),
      h(
        'div',
        { class: 'row' },
        can(ctx, 'deals.write') ? h('button', { class: 'btn primary', id: 'add-deal', type: 'button', onclick: addDeal }, '+ Сделка') : null,
        can(ctx, 'leads.manage') ? h('button', { class: 'btn', id: 'lead-settings', type: 'button', onclick: () => leadSettingsModal() }, 'Приём заявок') : null,
        can(ctx, 'pipelines.admin') ? h('button', { class: 'btn', id: 'pipeline-settings', type: 'button', onclick: openSettings }, 'Настроить воронку') : null,
      ),
    ),
    can(ctx, 'leads.manage') ? unsortedUI.element : null,
    h('div', { class: 'toolbar' }, viewSwitch, h('div', { class: 'grow' }, search), responsibleFilter),
    tabs,
    boardBox,
  );
  if (can(ctx, 'leads.manage')) await unsortedUI.load();
  drawTabs();
  drawViewSwitch();
  await loadBoard();
  return page;
}
