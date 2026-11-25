import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { queueUpload } from '../offline.js';
import { can } from '../perm.js';
import { DOC_STATUS, DOC_TONE, DOC_TYPES, PAY_STATUS, PAY_TONE, ROLES, errText } from '../labels.js';

const FILE_TYPES = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.dwg,.txt,image/*';

function debounce(fn, ms) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
}

function optionMap(items, labelOf, empty) {
  const map = { '': empty };
  for (const item of items) map[item.id] = labelOf(item);
  return map;
}

/** Без связи списки не загрузить: форма всё равно должна открыться, чтобы сохранить документ в очередь. */
async function softGet(path, params) {
  try {
    return await api.get(path, params);
  } catch (error) {
    if (error.code === 'network') return [];
    throw error;
  }
}

async function addDocumentModal(project, ctx, preFile, onDone) {
  const [contracts, counterparties, team] = await Promise.all([
    softGet('/contracts', { project_id: project.id }),
    softGet('/counterparties'),
    softGet('/team'),
  ]);
  let ai = { enabled: false };
  if (can(ctx, 'ai.use')) {
    try { ai = await api.get('/ai/status'); } catch { ai = { enabled: false }; }
  }
  const fileInput = preFile ? null : h('input', { name: 'file', type: 'file', accept: FILE_TYPES, required: true });
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    preFile ? h('p', { class: 'muted' }, `Файл: ${preFile.name}`) : field('Файл', fileInput, 'PDF, фото, Word, Excel, чертёж. До 25 МБ.'),
    ai.enabled
      ? h('div', { class: 'ai-box' }, h('button', { class: 'btn small', id: 'ai-fill', type: 'button', onclick: () => aiFill() }, 'Заполнить по файлу (ИИ)'), h('span', { class: 'muted', id: 'ai-note' }, `Осталось запросов сегодня: ${ai.left}. ИИ может ошибаться: проверьте данные.`))
      : null,
    field('Название', h('input', { name: 'title', value: preFile ? preFile.name.replace(/\.[^.]+$/, '') : '', placeholder: 'Например: Акт скрытых работ', required: true })),
    field('Что это', select(DOC_TYPES, 'other', { name: 'doc_type' })),
    field('Договор', select(optionMap(contracts, (c) => c.title, 'Не привязан'), '', { name: 'contract_id' })),
    field('Контрагент', select(optionMap(counterparties, (c) => c.name, 'Не выбран'), '', { name: 'counterparty_id' })),
    field('Кто согласует', select(optionMap(team, (m) => `${m.name} (${ROLES[m.role] || m.role})`, 'Не назначен'), '', { name: 'assignee_id' })),
    field('Срок', h('input', { name: 'due_date', type: 'date' })),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:140px' }, field('Номер счёта', h('input', { name: 'number', placeholder: 'Например: 147' }), 'Для счетов: по номеру система найдёт оплату')),
      h('div', { style: 'flex:1;min-width:140px' }, field('Сумма, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '0.01', inputmode: 'decimal' }))),
    ),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Добавить документ')),
  );
  const dialog = modal('Новый документ', form);

  async function aiFill() {
    const file = preFile || (fileInput && fileInput.files[0]);
    const note = form.querySelector('#ai-note');
    if (!file) {
      toast('Сначала выберите файл', 'bad');
      return;
    }
    const button = form.querySelector('#ai-fill');
    button.disabled = true;
    note.textContent = 'Читаю документ…';
    try {
      const data = new FormData();
      data.append('file', file);
      const found = await api.upload('/documents/recognize', data);
      const set = (name, value) => { if (value !== null && value !== undefined && value !== '') form.elements[name].value = value; };
      if (!form.elements.title.value.trim() || preFile) set('title', found.title);
      set('doc_type', found.doc_type);
      set('number', found.number);
      set('amount', found.amount);
      set('due_date', found.due_date);
      if (found.counterparty_id) set('counterparty_id', String(found.counterparty_id));
      const unknown = !found.counterparty_id && found.counterparty_name ? ` Контрагент «${found.counterparty_name}» в контактах не найден.` : '';
      note.textContent = `Заполнено. Проверьте каждое поле.${unknown}`;
      const status = await api.get('/ai/status').catch(() => null);
      if (status) note.textContent += ` Осталось запросов: ${status.left}.`;
    } catch (error) {
      note.textContent = errText(error);
    } finally {
      button.disabled = false;
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const file = preFile || (fileInput && fileInput.files[0]);
    const title = form.elements.title.value.trim();
    if (!file || !title) {
      errorBox.textContent = !file ? 'Выберите файл.' : 'Напишите название документа.';
      errorBox.classList.remove('hidden');
      return;
    }
    const data = new FormData();
    data.append('title', title);
    data.append('doc_type', form.elements.doc_type.value);
    for (const key of ['contract_id', 'counterparty_id', 'assignee_id', 'due_date', 'number', 'amount']) {
      if (form.elements[key].value) data.append(key, form.elements[key].value);
    }
    data.append('file', file);
    const button = form.querySelector('button[type=submit]');
    button.disabled = true;
    try {
      await api.upload(`/projects/${project.id}/documents`, data);
      dialog.close();
      toast('Документ добавлен');
      onDone();
    } catch (error) {
      if (error.code === 'network') {
        // нет связи: сохраняем на телефоне, отправится сам
        try {
          const fields = {};
          for (const key of ['title', 'doc_type', 'contract_id', 'counterparty_id', 'assignee_id', 'due_date', 'number', 'amount']) fields[key] = form.elements[key].value;
          await queueUpload({ projectId: project.id, projectName: project.name, fields, file });
          dialog.close();
          toast('Нет связи. Документ сохранён на телефоне и отправится сам, как только появится интернет.');
          return;
        } catch {
          errorBox.textContent = 'Нет связи, и сохранить на телефоне не получилось. Попробуйте позже.';
          errorBox.classList.remove('hidden');
          button.disabled = false;
          return;
        }
      }
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
      button.disabled = false;
    }
  });
}

export async function openDocument(id, ctx, onChange) {
  const [first, team] = await Promise.all([api.get(`/documents/${id}`), api.get('/team')]);
  const people = Object.fromEntries(team.map((m) => [m.id, m.name]));
  const body = h('div');
  let changed = false;
  const dialog = modal(first.title, body, { onClose: () => changed && onChange && onChange() });

  async function act(request, message) {
    try {
      await request();
      changed = true;
      if (message) toast(message);
      draw(await api.get(`/documents/${id}`));
    } catch (error) {
      toast(errText(error), 'bad');
    }
  }

  function draw(doc) {
    const canDecide = doc.assignee_id === null || doc.assignee_id === ctx.me.id || ctx.me.role === 'owner';
    const comment = h('textarea', { rows: '2', placeholder: 'Комментарий (необязательно)', 'aria-label': 'Комментарий' });
    const versionInput = h('input', {
      type: 'file',
      class: 'hidden',
      accept: FILE_TYPES,
      onchange: (event) => {
        const file = event.target.files[0];
        if (!file) return;
        const data = new FormData();
        data.append('file', file);
        act(() => api.upload(`/documents/${id}/versions`, data), 'Новая версия загружена');
      },
    });

    body.replaceChildren(
      h(
        'dl',
        { class: 'kv' },
        h('dt', null, 'Что это'), h('dd', null, DOC_TYPES[doc.doc_type] || doc.doc_type),
        h('dt', null, 'Статус'), h('dd', null, badge(DOC_STATUS[doc.status] || doc.status, DOC_TONE[doc.status])),
        h('dt', null, 'Срок'),
        h('dd', null, doc.due_date ? fmtDate(doc.due_date) : 'Не указан', ' ', doc.overdue ? badge('Просрочен', 'red') : null),
        h('dt', null, 'Согласует'), h('dd', null, doc.assignee_id ? people[doc.assignee_id] || '—' : 'Не назначен'),
        doc.number ? [h('dt', null, 'Номер'), h('dd', null, doc.number)] : null,
        doc.amount !== null ? [h('dt', null, 'Сумма'), h('dd', null, fmtMoney(doc.amount))] : null,
        doc.amount !== null ? [h('dt', null, 'Оплата'), h('dd', null, badge(PAY_STATUS[doc.pay_status] || '', PAY_TONE[doc.pay_status]), ` оплачено ${fmtMoney(doc.paid_amount)}`)] : null,
      ),
      h('h2', null, 'Файлы'),
      h(
        'div',
        { class: 'list', style: 'margin-bottom:14px' },
        [...doc.versions].reverse().map((v) =>
          h(
            'div',
            { class: 'item' },
            h('div', null, h('div', { class: 'title' }, `Версия ${v.version}`), h('div', { class: 'meta' }, `${v.filename} · ${fmtDate(v.created_at)}`)),
            h('a', { class: 'btn small', href: `/documents/${id}/download?version=${v.version}` }, 'Скачать'),
          ),
        ),
      ),
      doc.approvals.length
        ? h(
            'div',
            null,
            h('h2', null, 'История согласования'),
            h(
              'div',
              { class: 'history' },
              doc.approvals.map((a) =>
                h(
                  'p',
                  null,
                  h('strong', null, people[a.user_id] || 'Сотрудник'),
                  a.decision === 'approved' ? ' согласовал(а)' : ' отклонил(а)',
                  ` · ${fmtDate(a.created_at)}`,
                  a.comment ? h('span', { class: 'muted' }, ` — ${a.comment}`) : null,
                ),
              ),
            ),
          )
        : null,
      doc.status === 'in_review' && canDecide ? h('div', null, field('Ваше решение', comment)) : null,
      h(
        'div',
        { class: 'actions' },
        doc.status === 'draft'
          ? h('button', { class: 'btn primary', type: 'button', onclick: () => act(() => api.post(`/documents/${id}/submit`), 'Отправлено на согласование') }, 'Отправить на согласование')
          : null,
        doc.status === 'in_review' && canDecide
          ? [
              h('button', { class: 'btn good', type: 'button', onclick: () => act(() => api.post(`/documents/${id}/decision`, { decision: 'approved', comment: comment.value }), 'Документ согласован') }, 'Согласовать'),
              h('button', { class: 'btn bad', type: 'button', onclick: () => act(() => api.post(`/documents/${id}/decision`, { decision: 'rejected', comment: comment.value }), 'Документ отклонён') }, 'Отклонить'),
            ]
          : null,
        doc.created_by === ctx.me.id || ctx.me.role === 'owner'
          ? h('button', { class: 'btn bad', id: 'delete-document', type: 'button', onclick: () => confirmDialog('Удалить документ?', `«${doc.title}» уйдёт в корзину на 30 дней, его можно будет вернуть. Если к счёту привязаны оплаты, сначала отвяжите их.`, 'Удалить', async () => { try { await api.del(`/documents/${id}`); toast('Документ в корзине'); changed = true; dialog.close(); } catch (error) { toast(errText(error), 'bad'); } }) }, 'Удалить')
          : null,
        doc.status !== 'signed'
          ? [versionInput, h('button', { class: 'btn', type: 'button', onclick: () => versionInput.click() }, 'Загрузить новую версию')]
          : null,
      ),
    );
  }

  draw(first);
  return dialog;
}

export async function documentsTab(box, project, ctx) {
  const state = { type: '', q: '' };
  const list = h('div', { class: 'list' });
  const chips = h('div', { class: 'chips' });

  async function load() {
    const docs = await api.get('/documents', { project_id: project.id, doc_type: state.type, q: state.q });
    list.replaceChildren(
      ...(docs.length
        ? docs.map((doc) =>
            h(
              'div',
              { class: 'item clickable', tabindex: '0', role: 'button', onclick: () => openDocument(doc.id, ctx, load), onkeydown: (e) => e.key === 'Enter' && openDocument(doc.id, ctx, load) },
              h('div', null, h('div', { class: 'title' }, doc.title), h('div', { class: 'meta' }, `${DOC_TYPES[doc.doc_type] || doc.doc_type}${doc.number ? ' №' + doc.number : ''} · версия ${doc.version}${doc.amount !== null ? ' · ' + fmtMoney(doc.amount) : ''}${doc.due_date ? ' · срок ' + fmtDate(doc.due_date) : ''}`)),
              h('div', { class: 'side-info' }, doc.amount !== null ? badge(PAY_STATUS[doc.pay_status], PAY_TONE[doc.pay_status]) : null, doc.overdue ? badge('Просрочен', 'red') : null, badge(DOC_STATUS[doc.status] || doc.status, DOC_TONE[doc.status])),
            ),
          )
        : [h('div', { class: 'empty' }, h('p', { class: 'muted' }, state.q || state.type ? 'Ничего не нашлось.' : 'Документов пока нет. Добавьте первый.'))]),
    );
  }

  function drawChips() {
    chips.replaceChildren(
      ...Object.entries({ '': 'Все', ...DOC_TYPES }).map(([key, label]) =>
        h('button', { class: 'chip' + (state.type === key ? ' on' : ''), type: 'button', onclick: () => { state.type = key; drawChips(); load(); } }, label),
      ),
    );
  }

  const photoInput = h('input', {
    type: 'file', class: 'hidden', accept: 'image/*', capture: 'environment',
    onchange: (event) => {
      const file = event.target.files[0];
      event.target.value = '';
      if (file) addDocumentModal(project, ctx, file, load);
    },
  });

  box.append(
    h(
      'div',
      { class: 'toolbar' },
      h('div', { class: 'grow' }, h('input', { type: 'search', placeholder: 'Найти документ по названию', 'aria-label': 'Поиск документов', oninput: debounce((e) => { state.q = e.target.value; load(); }, 250) })),
      h('button', { class: 'btn primary', id: 'add-document', type: 'button', onclick: () => addDocumentModal(project, ctx, null, load) }, '+ Добавить документ'),
      photoInput,
      h('button', { class: 'btn', type: 'button', onclick: () => photoInput.click() }, 'Сфотографировать'),
    ),
    chips,
    list,
  );
  drawChips();
  await load();
}
