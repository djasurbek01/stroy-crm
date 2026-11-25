// Общая доска с колонками: сделки, закупки, договоры, документы.
// На компьютере карточки перетаскиваются, на телефоне вместо этого есть список «Переместить».
import { h } from './dom.js';

const coarse = () => window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

function quickForm(col) {
  const input = h('input', { placeholder: 'Быстрое добавление', 'aria-label': 'Быстрое добавление' });
  return h(
    'form',
    {
      class: 'quick',
      onsubmit: (event) => {
        event.preventDefault();
        const title = input.value.trim();
        if (!title) return;
        input.value = '';
        col.quickAdd(title);
      },
    },
    input,
  );
}

export function board({ columns, cards, onMove, renderCard, onOpen, emptyText = 'Пусто', readonly = false }) {
  const root = h('div', { class: 'board' });
  const touch = coarse() && !readonly;

  for (const col of columns) {
    const inColumn = cards.filter((c) => c.columnId === col.id);
    const body = h('div', { class: 'col-body', 'data-col': String(col.id) });

    body.addEventListener('dragover', (event) => {
      event.preventDefault();
      body.classList.add('drop');
    });
    body.addEventListener('dragleave', (event) => {
      if (!body.contains(event.relatedTarget)) body.classList.remove('drop');
    });
    body.addEventListener('drop', (event) => {
      if (readonly) return;
      event.preventDefault();
      body.classList.remove('drop');
      const id = event.dataTransfer.getData('text/plain');
      const card = cards.find((c) => String(c.id) === id);
      if (card && card.columnId !== col.id) onMove(card, col.id);
    });

    for (const card of inColumn) {
      const el = h(
        'div',
        {
          class: 'kcard',
          'data-card': String(card.id),
          draggable: touch || readonly ? null : 'true',
          tabindex: '0',
          role: 'button',
          onclick: (event) => {
            if (event.target.closest('select')) return;
            if (onOpen) onOpen(card);
          },
          onkeydown: (event) => {
            if (event.key === 'Enter' && onOpen && !event.target.closest('select')) onOpen(card);
          },
        },
        renderCard(card),
      );
      el.addEventListener('dragstart', (event) => {
        event.dataTransfer.setData('text/plain', String(card.id));
        event.dataTransfer.effectAllowed = 'move';
        el.classList.add('dragging');
      });
      el.addEventListener('dragend', () => el.classList.remove('dragging'));
      if (touch) {
        el.append(
          h(
            'select',
            {
              class: 'move-select',
              'aria-label': 'Переместить',
              onchange: (event) => {
                const target = columns.find((c) => String(c.id) === event.target.value);
                if (target && target.id !== card.columnId) onMove(card, target.id);
              },
            },
            h('option', { value: '' }, 'Переместить…'),
            columns.filter((c) => c.id !== col.id).map((c) => h('option', { value: String(c.id) }, c.title)),
          ),
        );
      }
      body.append(el);
    }
    if (!inColumn.length) body.append(h('div', { class: 'col-empty' }, emptyText));

    root.append(
      h(
        'section',
        { class: 'col' + (col.tone ? ' ' + col.tone : ''), 'aria-label': col.title },
        h(
          'header',
          { class: 'col-head' },
          h('span', { class: 'col-title' }, col.title),
          h('span', { class: 'col-count' }, String(inColumn.length)),
        ),
        col.meta ? h('div', { class: 'col-meta' }, col.meta) : null,
        col.quickAdd && !readonly ? quickForm(col) : null,
        body,
      ),
    );
  }
  return root;
}
