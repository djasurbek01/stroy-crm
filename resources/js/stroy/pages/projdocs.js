import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

export const PDOC_STATUS = { on_review: 'На визировании', approved: 'Все визы подписаны', rejected: 'Отклонён', in_work: 'Передано в работу' };
const PDOC_TONE = { on_review: 'orange', approved: 'blue', rejected: 'red', in_work: 'green' };
const DECISION = { pending: 'Ждёт', approved: 'Подписано', rejected: 'Отклонено' };
const DECISION_TONE = { pending: '', approved: 'green', rejected: 'red' };
const SECTIONS = ['АР (архитектура)', 'КР (конструкции)', 'ОВиК', 'ВК', 'ЭОМ', 'Генплан', 'Смета', 'Другое'];

/** Цепочка виз: общая для кабинета подрядчика и страницы заказчика. */
export function visaChain(doc, extra) {
  return h('ol', { class: 'visas' }, doc.visas.map((v) => h('li', { class: 'visa' + (v.id === doc.active_visa_id ? ' active' : '') },
    h('strong', null, v.label), ' ',
    badge(v.side === 'customer' ? 'Заказчик' : 'Подрядчик', v.side === 'customer' ? 'blue' : ''), ' ',
    badge(DECISION[v.decision], DECISION_TONE[v.decision]),
    v.decided_at ? h('span', { class: 'muted' }, ` ${v.decided_by}, ${fmtDate(v.decided_at)}`) : null,
    v.comment ? h('p', { class: 'muted visa-note' }, v.comment) : null,
    extra ? extra(v) : null,
  )));
}

function createModal(project, members, onDone) {
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const steps = [];
  const stepsBox = h('div');
  const memberOptions = { '': 'Любой владелец', ...Object.fromEntries(members.map((m) => [String(m.id), m.name])) };

  function addStep(label = '', side = 'staff') {
    const labelInput = h('input', { placeholder: 'Кто визирует, например: Главный инженер', maxlength: '120', value: label, 'aria-label': 'Должность или роль' });
    const sideSelect = select({ staff: 'Подрядчик', customer: 'Заказчик' }, side, { 'aria-label': 'Сторона' });
    const userSelect = select(memberOptions, '', { 'aria-label': 'Сотрудник' });
    const row = h('div', { class: 'step-row' }, labelInput, sideSelect, userSelect,
      h('button', { class: 'btn small bad', type: 'button', 'aria-label': 'Убрать визу', onclick: () => { steps.splice(steps.indexOf(item), 1); row.remove(); } }, '✕'));
    const item = { labelInput, sideSelect, userSelect };
    const sync = () => userSelect.classList.toggle('hidden', sideSelect.value === 'customer');
    sideSelect.addEventListener('change', sync);
    sync();
    steps.push(item);
    stepsBox.append(row);
  }
  addStep('Главный инженер');
  addStep('Заказчик', 'customer');

  const file = h('input', { type: 'file', name: 'file', required: true, accept: '.pdf,.dwg,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png', 'aria-label': 'Файл документа' });
  const form = h('form', { novalidate: true, class: 'upload-form' },
    error,
    field('Название', h('input', { name: 'title', required: true, maxlength: '300', placeholder: 'Например: Раздел КР, фундаментная плита' })),
    field('Раздел', h('input', { name: 'section', list: 'pdoc-sections', maxlength: '60', placeholder: 'АР, КР, ОВиК…' })),
    h('datalist', { id: 'pdoc-sections' }, SECTIONS.map((s) => h('option', { value: s }))),
    field('Файл', file),
    h('div', { class: 'field' }, h('span', null, 'Кто ставит визы, по порядку'), stepsBox,
      h('button', { class: 'btn small', type: 'button', onclick: () => addStep() }, '+ Ещё виза')),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Отправить на визирование')),
  );
  const dialog = modal('Новый документ', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.classList.add('hidden');
    const title = form.elements.title.value.trim();
    const chain = steps.map((s) => ({ label: s.labelInput.value.trim(), side: s.sideSelect.value, user_id: s.sideSelect.value === 'staff' && s.userSelect.value ? Number(s.userSelect.value) : null }));
    const fail = (text) => { error.textContent = text; error.classList.remove('hidden'); };
    if (!title) return fail('Напишите название документа.');
    if (!file.files[0]) return fail('Выберите файл.');
    if (!chain.length || chain.some((c) => !c.label)) return fail('У каждой визы должна быть должность или роль.');
    const data = new FormData();
    data.append('title', title);
    data.append('section', form.elements.section.value.trim());
    data.append('chain', JSON.stringify(chain));
    data.append('file', file.files[0]);
    try {
      await api.upload(`/projects/${project.id}/proj-docs`, data);
      dialog.close();
      toast('Документ отправлен на визирование');
      onDone();
    } catch (e) {
      fail(errText(e));
    }
  });
}

function decideModal(doc, visa, onDone) {
  const comment = h('input', { placeholder: 'Комментарий (необязательно)', maxlength: '1000', 'aria-label': 'Комментарий' });
  const send = (decision) => async () => {
    try { await api.post(`/proj-docs/${doc.id}/visas/${visa.id}/decide`, { decision, comment: comment.value }); dialog.close(); toast(decision === 'approved' ? 'Виза поставлена' : 'Документ отклонён'); onDone(); } catch (e) { toast(errText(e), 'bad'); }
  };
  const dialog = modal(`Виза: ${visa.label}`, h('div', null, h('p', { class: 'muted' }, doc.title), comment,
    h('div', { class: 'actions' }, h('button', { class: 'btn good', type: 'button', onclick: send('approved') }, 'Согласовать'), h('button', { class: 'btn bad', type: 'button', onclick: send('rejected') }, 'Отклонить'))));
}

export async function projDocsTab(box, project, ctx) {
  async function render() {
    const [docs, members] = await Promise.all([api.get(`/projects/${project.id}/proj-docs`), api.get('/team')]);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const editable = can(ctx, 'pdocs.write');
    box.replaceChildren(
      h('div', { class: 'row', style: 'justify-content:space-between;align-items:center;margin-bottom:8px' },
        h('p', { class: 'muted', style: 'margin:0' }, 'Проектная документация с визами. Документ идёт по цепочке по порядку, заказчик подписывает свою визу по ссылке.'),
        editable ? h('button', { class: 'btn primary', id: 'add-pdoc', type: 'button', onclick: () => createModal(project, members, reload) }, '+ Документ') : null,
      ),
      docs.length ? h('div', { class: 'list' }, docs.map((d) => {
        const active = d.visas.find((v) => v.id === d.active_visa_id);
        const mayDecide = active && active.side === 'staff' && (ctx.me.role === 'owner' || active.user_id === ctx.me.id);
        return h('div', { class: 'item col-item' },
          h('div', { style: 'flex:1;min-width:0' },
            h('strong', null, d.title), ' ', badge(PDOC_STATUS[d.status], PDOC_TONE[d.status]), ' ', badge(`версия ${d.version}`),
            h('p', { class: 'muted' }, [d.section, `загрузил ${d.created_by}`, d.released_at ? `в работе с ${fmtDate(d.released_at)} (${d.released_by})` : ''].filter(Boolean).join(' · ')),
            visaChain(d),
          ),
          h('div', { class: 'row' },
            h('a', { class: 'btn small', href: d.url }, 'Скачать'),
            mayDecide ? h('button', { class: 'btn small primary', type: 'button', onclick: () => decideModal(d, active, reload) }, 'Поставить визу') : null,
            editable && d.status === 'approved' ? h('button', { class: 'btn small good', type: 'button', onclick: async () => { try { await api.post(`/proj-docs/${d.id}/release`); toast('Передано в работу'); reload(); } catch (e) { toast(errText(e), 'bad'); } } }, 'Передать в работу') : null,
            editable ? h('label', { class: 'btn small' }, 'Новая версия', h('input', { type: 'file', class: 'hidden', 'aria-label': 'Новая версия', onchange: async (event) => {
              const f = event.target.files[0];
              if (!f) return;
              const data = new FormData(); data.append('file', f);
              try { await api.upload(`/proj-docs/${d.id}/version`, data); toast('Версия загружена, визы запущены заново'); reload(); } catch (e) { toast(errText(e), 'bad'); }
            } })) : null,
            editable ? h('button', { class: 'btn small bad', type: 'button', 'aria-label': `Удалить ${d.title}`, onclick: () => confirmDialog('Удалить документ?', `«${d.title}» и его визы будут удалены без возможности вернуть.`, 'Удалить', async () => {
              try { await api.del(`/proj-docs/${d.id}`); toast('Удалено'); reload(); } catch (e) { toast(errText(e), 'bad'); }
            }) }, '✕') : null,
          ),
        );
      })) : h('div', { class: 'empty' }, h('p', null, 'Документов пока нет. Загрузите проект и задайте цепочку виз.')),
    );
  }
  await render();
}
