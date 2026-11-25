// Общий поиск в верхней панели: сделки, контакты, документы, объекты.
import { api } from './api.js';
import { h } from './dom.js';

const GROUPS = [
  ['deals', 'Сделки', (r) => ({ text: r.title, href: `#/deal/${r.id}` })],
  ['contacts', 'Контакты', (r) => ({ text: r.name, href: `#/contact/${r.id}` })],
  ['projects', 'Объекты', (r) => ({ text: r.name, href: `#/project/${r.id}` })],
  ['documents', 'Документы', (r) => ({ text: r.title, href: `#/project/${r.project_id}/documents` })],
];

export function searchBox() {
  const input = h('input', {
    type: 'search',
    id: 'global-search',
    placeholder: 'Поиск по CRM',
    'aria-label': 'Общий поиск',
    autocomplete: 'off',
  });
  const results = h('div', { class: 'search-results hidden', role: 'listbox' });
  const box = h('div', { class: 'search' }, input, results);
  let timer;
  let token = 0;

  function hide() {
    results.classList.add('hidden');
  }

  async function run() {
    const q = input.value.trim();
    if (q.length < 2) return hide();
    const mine = ++token;
    let data;
    try {
      data = await api.get('/search', { q });
    } catch {
      return hide();
    }
    if (mine !== token) return;
    const blocks = GROUPS.filter(([key]) => data[key].length).map(([key, title, map]) =>
      h(
        'div',
        { class: 'search-group' },
        h('div', { class: 'search-title' }, title),
        data[key].map((row) => {
          const { text, href } = map(row);
          return h('a', { class: 'search-item', href, role: 'option', onclick: () => { hide(); input.value = ''; } }, text);
        }),
      ),
    );
    results.replaceChildren(...(blocks.length ? blocks : [h('div', { class: 'search-empty' }, 'Ничего не найдено')]));
    results.classList.remove('hidden');
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, 220);
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hide();
      input.blur();
    }
  });
  document.addEventListener('click', (event) => {
    if (!box.contains(event.target)) hide();
  });
  return box;
}
