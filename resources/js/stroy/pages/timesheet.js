// Табель: кто сколько часов работал на объекте. Для прораба быстрая отметка за день, для владельца и бухгалтера зарплата за месяц.
import { api } from '../api.js';
import { field, fmtMoney, h, modal, select, store, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

const LAST_PROJECT = 'timesheet_project';
const today = () => new Date().toISOString().slice(0, 10);
const fmtHours = (value) => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value || 0)} ч`;

function workerModal(worker, onSaved) {
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Имя', h('input', { name: 'name', value: worker ? worker.name : '', placeholder: 'Например: Пётр Иванов', required: true })),
    field('Должность', h('input', { name: 'position', value: worker ? worker.position : '', placeholder: 'Например: плотник' })),
    field('Ставка, ₽ в час', h('input', { name: 'hourly_rate', type: 'number', min: '0', step: '10', inputmode: 'decimal', value: worker && worker.hourly_rate !== null ? String(worker.hourly_rate) : '' }), worker ? 'Новая ставка действует с сегодняшних записей. Уже отмеченные дни не пересчитываются.' : null),
    worker ? h('label', { class: 'check' }, h('input', { name: 'active', type: 'checkbox', checked: worker.active }), ' Работает сейчас') : null,
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')),
  );
  const dialog = modal(worker ? 'Сотрудник' : 'Новый сотрудник', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    const body = { name: form.elements.name.value.trim(), position: form.elements.position.value.trim(), hourly_rate: Number(form.elements.hourly_rate.value) || 0 };
    if (!body.name) {
      errorBox.textContent = 'Напишите имя.';
      errorBox.classList.remove('hidden');
      return;
    }
    if (worker) body.active = form.elements.active.checked;
    try {
      if (worker) await api.patch(`/workers/${worker.id}`, body);
      else await api.post('/workers', body);
      dialog.close();
      toast('Сохранено');
      onSaved();
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

export async function timesheetPage(ctx) {
  const projects = await api.get('/projects');
  const canWrite = can(ctx, 'timesheet.write');
  const showPay = can(ctx, 'payroll.read');
  const manage = can(ctx, 'payroll.write');
  const saved = store(LAST_PROJECT);
  const state = { date: today(), project: projects.some((p) => String(p.id) === saved) ? saved : projects[0] ? String(projects[0].id) : '', month: today().slice(0, 7) };

  const dayBox = h('div');
  const payBox = h('div', { style: 'margin-top:28px' });
  const root = h('div', null);

  async function drawDay() {
    if (!projects.length) {
      dayBox.replaceChildren(h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Сначала создайте объект: часы отмечаются на объекте.')));
      return;
    }
    const [workers, entries] = await Promise.all([api.get('/workers'), api.get('/timesheet', { date_from: state.date, date_to: state.date })]);
    const here = new Map(entries.filter((e) => String(e.project_id) === state.project).map((e) => [e.worker_id, e.hours]));
    const elsewhere = new Map();
    for (const e of entries) if (String(e.project_id) !== state.project) elsewhere.set(e.worker_id, (elsewhere.get(e.worker_id) || 0) + e.hours);
    const active = workers.filter((w) => w.active);
    if (!workers.length) {
      dayBox.replaceChildren(h('div', { class: 'empty' }, h('p', { class: 'muted' }, manage ? 'Людей пока нет. Добавьте первого сотрудника и его ставку.' : 'Людей пока нет. Попросите владельца или бухгалтера добавить сотрудников.')));
      return;
    }

    async function save(worker, input, row) {
      const hours = Number(input.value) || 0;
      try {
        await api.put('/timesheet', { worker_id: worker.id, project_id: Number(state.project), work_date: state.date, hours });
        row.classList.toggle('done', hours > 0);
        toast(hours ? `${worker.name}: ${fmtHours(hours)}` : `${worker.name}: отметка убрана`);
        if (showPay) drawPay();
      } catch (error) {
        toast(errText(error), 'bad');
        input.value = here.get(worker.id) || '';
      }
    }

    dayBox.replaceChildren(
      h(
        'div',
        { class: 'list' },
        active.map((w) => {
          const input = h('input', { type: 'number', min: '0', max: '24', step: '0.5', inputmode: 'decimal', class: 'hours-input', 'aria-label': `Часы: ${w.name}`, value: here.has(w.id) ? String(here.get(w.id)) : '', placeholder: '0', disabled: !canWrite });
          const row = h(
            'div',
            { class: 'item worker-row' + (here.get(w.id) ? ' done' : ''), 'data-worker': w.name },
            h('div', null, h('div', { class: 'title' }, w.name), h('div', { class: 'meta' }, [w.position, elsewhere.has(w.id) ? `на других объектах сегодня: ${fmtHours(elsewhere.get(w.id))}` : ''].filter(Boolean).join(' · ') || ' ')),
            h(
              'div',
              { class: 'side-info' },
              canWrite ? h('button', { class: 'btn small', type: 'button', onclick: () => { input.value = '8'; save(w, input, row); } }, '8 ч') : null,
              input,
              manage ? h('button', { class: 'btn small', type: 'button', onclick: () => workerModal(w, drawAll) }, 'Изменить') : null,
            ),
          );
          input.addEventListener('change', () => save(w, input, row));
          return row;
        }),
      ),
      workers.length !== active.length ? h('p', { class: 'muted' }, `Не работают сейчас: ${workers.length - active.length}. Их часы за прошлые дни сохранены.`) : null,
    );
  }

  async function drawPay() {
    if (!showPay) return;
    const data = await api.get('/timesheet/summary', { month: state.month });
    payBox.replaceChildren(
      h('h2', null, 'Зарплата за месяц'),
      h('div', { class: 'toolbar' }, field('Месяц', h('input', { type: 'month', value: state.month, 'aria-label': 'Месяц', onchange: (e) => { state.month = e.target.value || state.month; drawPay(); } }))),
      data.workers.length
        ? h(
            'div',
            null,
            h(
              'div',
              { class: 'table-wrap' },
              h(
                'table',
                { class: 'data', id: 'pay-table' },
                h('thead', null, h('tr', null, h('th', null, 'Сотрудник'), h('th', { class: 'num' }, 'Дней'), h('th', { class: 'num' }, 'Часов'), h('th', { class: 'num' }, 'К выплате'))),
                h(
                  'tbody',
                  null,
                  data.workers.map((w) => h('tr', null, h('td', null, w.name), h('td', { class: 'num' }, String(w.days)), h('td', { class: 'num' }, fmtHours(w.hours)), h('td', { class: 'num' }, fmtMoney(w.pay)))),
                  h('tr', null, h('td', null, h('strong', null, 'Итого')), h('td'), h('td', { class: 'num' }, h('strong', null, fmtHours(data.total_hours))), h('td', { class: 'num' }, h('strong', null, fmtMoney(data.total_pay)))),
                ),
              ),
            ),
            h('h2', null, 'Сколько ушло на работу по объектам'),
            h('div', { class: 'list' }, data.projects.map((p) => h('div', { class: 'item' }, h('div', null, h('div', { class: 'title' }, p.name), h('div', { class: 'meta' }, fmtHours(p.hours))), h('strong', null, fmtMoney(p.pay))))),
            h('p', { class: 'muted' }, 'Это сумма по часам и ставкам, без налогов и взносов.'),
          )
        : h('p', { class: 'muted' }, 'За этот месяц часов ещё нет.'),
    );
  }

  const drawAll = async () => {
    await drawDay();
    await drawPay();
  };

  root.append(
    h(
      'div',
      { class: 'page-head' },
      h('div', null, h('h1', { class: 'page-title' }, 'Табель'), h('p', { class: 'muted' }, 'Кто и сколько часов работал на объекте. Зарплата считается по почасовой ставке.')),
      manage ? h('button', { class: 'btn primary', id: 'add-worker', type: 'button', onclick: () => workerModal(null, drawAll) }, '+ Сотрудник') : null,
    ),
    h(
      'div',
      { class: 'toolbar' },
      field('День', h('input', { type: 'date', id: 'ts-date', value: state.date, max: today(), onchange: (e) => { state.date = e.target.value || today(); drawDay(); } })),
      field('Объект', select(Object.fromEntries(projects.map((p) => [p.id, p.name])), state.project, { id: 'ts-project', onchange: (e) => { state.project = e.target.value; store(LAST_PROJECT, state.project); drawDay(); } })),
    ),
    dayBox,
    payBox,
  );
  await drawAll();
  return root;
}
