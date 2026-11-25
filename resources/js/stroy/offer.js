// Страница коммерческого предложения для клиента: без входа, с печатью и кнопкой «Принимаю».
import { fmtDate, fmtMoney, h } from './dom.js';

const root = document.getElementById('offer-root');
const token = new URLSearchParams(location.search).get('t') || '';

function render(offer) {
  const accepted = Boolean(offer.accepted_at);
  const acceptBox = h('div', { class: 'no-print offer-accept' });
  const nameInput = h('input', { placeholder: 'Ваше имя', 'aria-label': 'Ваше имя', autocomplete: 'name' });
  const commentInput = h('textarea', { rows: '2', placeholder: 'Комментарий (необязательно)', 'aria-label': 'Комментарий' });
  const message = h('p', { class: 'muted', role: 'status' });

  async function accept(button) {
    button.disabled = true;
    try {
      const response = await fetch(`/public/offers/${encodeURIComponent(token)}/accept`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: nameInput.value, comment: commentInput.value }),
      });
      if (!response.ok) {
        let code = '';
        try { code = (await response.json()).detail; } catch { code = ''; }
        throw new Error(code === 'offer_expired' ? 'Срок предложения истёк. Позвоните нам, мы обновим его.' : 'Не получилось отправить. Попробуйте ещё раз или позвоните нам.');
      }
      acceptBox.replaceChildren(h('p', { class: 'offer-ok' }, 'Спасибо! Мы получили ваше согласие и свяжемся с вами.'));
    } catch (error) {
      message.textContent = error.message;
      button.disabled = false;
    }
  }

  if (accepted) {
    acceptBox.append(h('p', { class: 'offer-ok' }, `Вы приняли это предложение ${fmtDate(offer.accepted_at)}. Мы свяжемся с вами.`));
  } else if (offer.expired) {
    acceptBox.append(h('p', { class: 'error' }, 'Срок этого предложения истёк. Позвоните нам, мы обновим его.'));
  } else {
    const button = h('button', { class: 'btn primary', id: 'accept-offer', type: 'button', onclick: (event) => accept(event.currentTarget) }, 'Принимаю предложение');
    acceptBox.append(h('h2', null, 'Вас всё устраивает?'), nameInput, commentInput, button, message);
  }

  root.replaceChildren(
    h('header', { class: 'offer-head' }, h('div', null, h('h1', null, 'Коммерческое предложение'), h('p', { class: 'muted' }, `от ${fmtDate(offer.date)}`)), h('div', { class: 'offer-company' }, h('strong', null, offer.company.name), offer.company.phone ? h('div', null, offer.company.phone) : null, offer.company.email ? h('div', null, offer.company.email) : null)),
    h('section', { class: 'offer-block' }, h('h2', null, offer.title), offer.client ? h('p', null, 'Для: ', h('strong', null, offer.client)) : null, offer.address ? h('p', null, 'Адрес: ', offer.address) : null),
    h(
      'div',
      { class: 'table-wrap' },
      h(
        'table',
        { class: 'data offer-table' },
        h('thead', null, h('tr', null, h('th', null, 'Что делаем'), h('th', { class: 'num' }, 'Кол-во'), h('th', { class: 'num' }, 'Цена'), h('th', { class: 'num' }, 'Сумма'))),
        h('tbody', null, offer.items.map((i) => h('tr', null, h('td', null, i.title), h('td', { class: 'num' }, `${i.quantity} ${i.unit}`.trim()), h('td', { class: 'num' }, fmtMoney(i.price)), h('td', { class: 'num' }, fmtMoney(i.total))))),
        h('tfoot', null, h('tr', null, h('td', { colspan: '3' }, h('strong', null, 'Итого')), h('td', { class: 'num' }, h('strong', null, fmtMoney(offer.total))))),
      ),
    ),
    offer.note ? h('section', { class: 'offer-block' }, h('h2', null, 'Условия'), h('p', { class: 'pre' }, offer.note)) : null,
    offer.valid_until ? h('p', { class: 'muted' }, `Предложение действует до ${fmtDate(offer.valid_until)}.`) : null,
    offer.company.details ? h('p', { class: 'muted pre' }, offer.company.details) : null,
    h('div', { class: 'no-print' }, h('button', { class: 'btn', type: 'button', onclick: () => window.print() }, 'Распечатать или сохранить в PDF')),
    acceptBox,
  );
}

(async function load() {
  try {
    const response = await fetch(`/public/offers/${encodeURIComponent(token)}`);
    if (response.status === 404) {
      root.replaceChildren(h('h1', null, 'Предложение не найдено'), h('p', { class: 'muted' }, 'Ссылка устарела или была отключена. Попросите прислать новую.'));
      return;
    }
    if (!response.ok) throw new Error('bad');
    render(await response.json());
  } catch {
    root.replaceChildren(h('h1', null, 'Не получилось загрузить'), h('p', { class: 'muted' }, 'Проверьте интернет и обновите страницу.'));
  }
})();
