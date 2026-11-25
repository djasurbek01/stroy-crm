// Пошаговое обучение: подсвечивает нужное место и объясняет простыми словами.
// Листается кнопками, стрелками клавиатуры и свайпом. Можно пропустить в любой момент.
import { api } from './api.js';
import { h, store } from './dom.js';

let active = false;

function waitFor(selector, timeout = 3000) {
  return new Promise((resolve) => {
    const started = Date.now();
    (function poll() {
      const el = document.querySelector(selector);
      if (el) return resolve(el);
      if (Date.now() - started > timeout) return resolve(null);
      setTimeout(poll, 80);
    })();
  });
}

function buildSteps(projectId) {
  const docs = `#/project/${projectId}/documents`;
  const steps = [
    { route: '#/home', sel: '.kpis', title: 'Главная', text: 'Главное за один экран: сделки в работе, ваши задачи, документы на согласование, неоплаченные счета и план продаж.' },
    { route: '#/deals', sel: '.board', title: 'Сделки', text: 'Каждая сделка идёт по этапам: от заявки до сданного объекта. Перетащите карточку на следующий этап, когда дело продвинулось.' },
    { route: '#/deals', sel: '#pipeline-settings', title: 'Свои этапы и автоматика', text: 'Этапы можно менять под себя. Например, когда договор подписан, система сама создаст объект, поставит задачи и напишет команде.' },
    { route: '#/objects', sel: '.page-title', title: 'Ваши объекты', text: 'Один объект — одна стройка. Здесь они все. Красная метка значит, что что-то просрочено.' },
    { route: '#/objects', sel: '#add-project', title: 'Новый объект', text: 'Нажмите сюда, чтобы завести новую стройку. Достаточно названия.' },
  ];
  if (projectId) {
    steps.push(
      { route: docs, sel: '#tab-documents', title: 'Документы', text: 'Все договоры, счета и акты объекта лежат здесь. Ничего не теряется и не путается.' },
      { route: docs, sel: '#add-document', title: 'Добавить документ', text: 'Загрузите файл или сфотографируйте бумагу телефоном. Можно сразу назначить, кто согласует.' },
      { route: `#/project/${projectId}/contracts`, sel: '#tab-contracts', title: 'Договоры', text: 'Договоры с подрядчиками и поставщиками, суммы и статусы. Документы привязываются к договору.' },
      { route: `#/project/${projectId}/purchases`, sel: '#import-excel', title: 'Закупки из Excel', text: 'Есть таблица закупок? Загрузите её, и позиции появятся здесь сами. Печатать заново не нужно.' },
    );
  }
  steps.push(
    { route: '#/payments', sel: '#import-bank', title: 'Платежи из банка', text: 'Загрузите выписку из банка. Система сама найдёт, за какой счёт и по какому объекту был платёж, и отметит счёт оплаченным.' },
    { route: '#/tasks', sel: '.page-title', title: 'Мои задачи', text: 'Здесь то, что ждёт вашего решения или уже просрочено. Начинайте день отсюда.' },
    { route: '#/team', sel: '.page-title', title: 'Команда', text: 'Добавьте прораба и снабженца, чтобы работать вместе в одной системе.' },
  );
  return steps;
}

export async function startTour(ctx) {
  if (active) return;
  active = true;
  let demo = null;
  try {
    demo = await api.post('/demo');
  } catch {
    demo = null;
  }
  const steps = buildSteps(demo ? demo.id : null);
  let index = 0;
  let target = null;

  const dim = h('div', { class: 'tour-dim hidden' });
  const title = h('h3');
  const text = h('p');
  const dots = h('div', { class: 'tour-dots' });
  const back = h('button', { class: 'btn small', type: 'button', onclick: () => go(index - 1) }, 'Назад');
  const next = h('button', { class: 'btn small primary', type: 'button', onclick: () => go(index + 1) }, 'Дальше');
  const skip = h('button', { class: 'link-btn', type: 'button', onclick: () => finish() }, 'Пропустить');
  const card = h('div', { class: 'tour-card', role: 'dialog', 'aria-label': 'Обучение' }, title, text, dots, h('div', { class: 'row' }, back, next, skip));
  document.body.append(dim, card);

  function place() {
    const margin = 12;
    if (!target) {
      dim.classList.add('hidden');
      card.style.left = `${Math.max(margin, (window.innerWidth - card.offsetWidth) / 2)}px`;
      card.style.top = `${Math.max(margin, (window.innerHeight - card.offsetHeight) / 2)}px`;
      return;
    }
    const rect = target.getBoundingClientRect();
    const pad = 6;
    dim.classList.remove('hidden');
    Object.assign(dim.style, {
      left: `${rect.left - pad}px`,
      top: `${rect.top - pad}px`,
      width: `${rect.width + pad * 2}px`,
      height: `${rect.height + pad * 2}px`,
    });
    const cardHeight = card.offsetHeight;
    const below = rect.bottom + pad + margin + cardHeight < window.innerHeight;
    const top = below ? rect.bottom + pad + margin : Math.max(margin, rect.top - pad - margin - cardHeight);
    const left = Math.min(Math.max(margin, rect.left), window.innerWidth - card.offsetWidth - margin);
    card.style.top = `${top}px`;
    card.style.left = `${left}px`;
  }

  async function go(i) {
    if (i < 0) return;
    if (i >= steps.length) return finish();
    index = i;
    const step = steps[i];
    title.textContent = `${i + 1}. ${step.title}`;
    text.textContent = step.text;
    dots.replaceChildren(...steps.map((_, k) => h('i', { class: k === i ? 'on' : '' })));
    back.disabled = i === 0;
    next.textContent = i === steps.length - 1 ? 'Готово' : 'Дальше';
    if (location.hash !== step.route) ctx.go(step.route);
    target = await waitFor(step.sel);
    if (index !== i) return;
    if (target) target.scrollIntoView({ block: 'center', behavior: 'instant' });
    place();
  }

  function finish() {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', place);
    dim.remove();
    card.remove();
    active = false;
    store(`tour_done_${ctx.me.id}`, '1');
    ctx.go('#/home');
  }

  function onKey(event) {
    if (event.key === 'ArrowRight') go(index + 1);
    else if (event.key === 'ArrowLeft') go(index - 1);
    else if (event.key === 'Escape') finish();
  }

  let startX = null;
  card.addEventListener('touchstart', (event) => {
    startX = event.touches[0].clientX;
  }, { passive: true });
  card.addEventListener('touchend', (event) => {
    if (startX === null) return;
    const delta = event.changedTouches[0].clientX - startX;
    startX = null;
    if (Math.abs(delta) > 50) go(index + (delta < 0 ? 1 : -1));
  });

  document.addEventListener('keydown', onKey);
  window.addEventListener('resize', place);
  await go(0);
}
