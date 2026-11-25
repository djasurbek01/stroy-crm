// Быстрый чек с телефона: фото, объект, сумма, статья. Без связи уходит в очередь и отправляется сам.
import { api } from '../api.js';
import { field, h, modal, select, store, toast } from '../dom.js';
import { EXPENSE_CATEGORIES, errText } from '../labels.js';
import { queueUpload } from '../offline.js';

const LAST_PROJECT = 'receipt_last_project';
const PROJECT_CACHE = 'receipt_projects';

/** Список объектов запоминается на телефоне: на стройке без интернета чек всё равно можно сохранить. */
async function loadProjects() {
  try {
    const projects = await api.get('/projects');
    store(PROJECT_CACHE, JSON.stringify(projects.map((p) => ({ id: p.id, name: p.name }))));
    return projects;
  } catch (error) {
    if (error.code !== 'network') throw error;
    try {
      return JSON.parse(store(PROJECT_CACHE) || '[]');
    } catch {
      return [];
    }
  }
}

export async function quickReceipt(onDone) {
  const projects = await loadProjects();
  if (!projects.length) {
    toast('Сначала создайте объект: чек привязывается к объекту.', 'bad');
    return;
  }
  const last = store(LAST_PROJECT);
  const start = projects.some((p) => String(p.id) === last) ? last : String(projects[0].id);
  let category = '';

  const photo = h('input', { name: 'file', type: 'file', accept: 'image/*,.pdf', capture: 'environment', required: true });
  const chips = h('div', { class: 'chips' });
  const drawChips = () =>
    chips.replaceChildren(
      ...Object.entries(EXPENSE_CATEGORIES).map(([key, label]) =>
        h('button', { class: 'chip' + (category === key ? ' on' : ''), type: 'button', 'aria-pressed': String(category === key), onclick: () => { category = key; drawChips(); } }, label),
      ),
    );
  drawChips();

  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Фото чека', photo),
    field('Объект', select(Object.fromEntries(projects.map((p) => [p.id, p.name])), start, { name: 'project_id' })),
    field('Сумма, ₽', h('input', { name: 'amount', type: 'number', min: '0', step: '0.01', inputmode: 'decimal', placeholder: 'Например: 1250' })),
    h('div', { class: 'field' }, h('span', null, 'На что потратили'), chips),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить чек')),
  );
  const dialog = modal('Новый чек', form);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const file = photo.files[0];
    if (!file) {
      errorBox.textContent = 'Сфотографируйте или выберите чек.';
      errorBox.classList.remove('hidden');
      return;
    }
    const projectId = form.elements.project_id.value;
    const projectName = projects.find((p) => String(p.id) === projectId)?.name || '';
    const fields = {
      title: `Чек${category ? ', ' + EXPENSE_CATEGORIES[category].toLowerCase() : ''}, ${new Date().toLocaleDateString('ru-RU')}`,
      doc_type: 'receipt',
      category,
      amount: form.elements.amount.value,
    };
    const data = new FormData();
    for (const [key, value] of Object.entries(fields)) if (value) data.append(key, value);
    data.append('file', file);
    const button = form.querySelector('button[type=submit]');
    button.disabled = true;
    store(LAST_PROJECT, projectId);
    try {
      await api.upload(`/projects/${projectId}/documents`, data);
      dialog.close();
      toast('Чек сохранён');
      if (onDone) onDone();
    } catch (error) {
      if (error.code === 'network') {
        try {
          await queueUpload({ projectId, projectName, fields, file });
          dialog.close();
          toast('Нет связи. Чек сохранён на телефоне и отправится сам.');
          return;
        } catch {
          errorBox.textContent = 'Нет связи, и сохранить на телефоне не получилось. Попробуйте позже.';
        }
      } else {
        errorBox.textContent = errText(error);
      }
      errorBox.classList.remove('hidden');
      button.disabled = false;
    }
  });
}
