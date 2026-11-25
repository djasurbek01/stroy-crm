// Мини-помощники для построения интерфейса. Текст всегда вставляется как текст, не как HTML.

// Встроенные append/prepend/replaceChildren превращают null в слово «null». Условные блоки вида
// `условие ? узел : null` встречаются повсюду, поэтому пустые значения пропускаем централизованно.
// (в Node, где нет DOM, например в тестах, ничего не делаем)
for (const name of ['append', 'prepend', 'replaceChildren']) {
  if (typeof Element === 'undefined') break;
  for (const proto of [Element.prototype, DocumentFragment.prototype]) {
    const original = proto[name];
    proto[name] = function (...nodes) {
      return original.apply(this, nodes.flat(Infinity).filter((node) => node !== null && node !== undefined && node !== false));
    };
  }
}

function append(el, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  let value;
  let hasValue = false;
  for (const [key, val] of Object.entries(attrs || {})) {
    if (val === null || val === undefined || val === false) continue;
    if (key === 'class') el.className = val;
    else if (key === 'value') {
      value = val;
      hasValue = true;
    } else if (key === 'checked' || key === 'selected' || key === 'disabled') el[key] = true;
    else if (key.startsWith('on')) el.addEventListener(key.slice(2).toLowerCase(), val);
    else el.setAttribute(key, val === true ? '' : val);
  }
  append(el, children);
  if (hasValue) el.value = value;
  return el;
}

export function toast(message, kind = '') {
  const box = document.getElementById('toasts');
  const el = h('div', { class: 'toast ' + kind, role: 'status' }, message);
  box.append(el);
  setTimeout(() => el.classList.add('leaving'), 3300);
  setTimeout(() => el.remove(), 3550);
}

export function modal(title, content, { onClose } = {}) {
  const previous = document.activeElement;
  const overlay = h('div', { class: 'overlay' });
  let closed = false;
  function close() {
    if (closed) return;
    closed = true;
    // окно уезжает с анимацией, потом убирается из страницы
    overlay.classList.add('leaving');
    setTimeout(() => overlay.remove(), 180);
    document.removeEventListener('keydown', onKey);
    if (previous && previous.focus) previous.focus();
    if (onClose) onClose();
  }
  function onKey(event) {
    if (event.key === 'Escape') close();
  }
  const box = h(
    'div',
    { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h(
      'div',
      { class: 'modal-head' },
      h('h2', null, title),
      h('button', { class: 'close', type: 'button', 'aria-label': 'Закрыть', onclick: close }, '×'),
    ),
    content,
  );
  overlay.append(box);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) close();
  });
  document.addEventListener('keydown', onKey);
  document.body.append(overlay);
  const first = box.querySelector('input, select, textarea, button.btn');
  if (first) first.focus();
  return { close, box };
}

/** Окно «Точно удалить?». onConfirm вызывается только после нажатия красной кнопки. */
export function confirmDialog(title, text, confirmLabel, onConfirm) {
  const body = h(
    'div',
    null,
    h('p', null, text),
    h(
      'div',
      { class: 'actions' },
      h('button', { class: 'btn bad', id: 'confirm-yes', type: 'button', onclick: async () => { dialog.close(); await onConfirm(); } }, confirmLabel),
      h('button', { class: 'btn', type: 'button', onclick: () => dialog.close() }, 'Отмена'),
    ),
  );
  const dialog = modal(title, body);
  return dialog;
}

export function plural(n, forms) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return forms[0];
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return forms[1];
  return forms[2];
}

export function fmtDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}.${m}.${y}`;
}

export function fmtMoney(value) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value || 0) + ' ₽';
}

export function badge(text, tone = '') {
  return h('span', { class: 'badge ' + tone }, text);
}

export function field(label, input, hint) {
  return h('label', { class: 'field' }, h('span', null, label), input, hint ? h('p', { class: 'hint' }, hint) : null);
}

export function select(options, value, attrs = {}) {
  return h(
    'select',
    attrs,
    Object.entries(options).map(([key, text]) => h('option', { value: key, selected: key === value }, text)),
  );
}

export function store(key, value) {
  try {
    if (value === undefined) return localStorage.getItem(key);
    localStorage.setItem(key, value);
  } catch {
    return null;
  }
  return value;
}
