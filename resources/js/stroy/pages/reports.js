import { api } from '../api.js';
import { confirmDialog, fmtDate, h, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { can } from '../perm.js';

const MAX_VIDEO_SECONDS = 30;
const MAX_VIDEO_BYTES = 25 * 1024 * 1024;

/** Сжимает фото до 1600 px по длинной стороне, чтобы на сервере не копились снимки по 8 МБ. */
async function compressPhoto(file) {
  if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    return blob && blob.size < file.size ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
  } catch {
    return file;
  }
}

function videoSeconds(file) {
  return new Promise((resolve) => {
    const video = document.createElement('video');
    const url = URL.createObjectURL(file);
    video.preload = 'metadata';
    video.onloadedmetadata = () => { URL.revokeObjectURL(url); resolve(video.duration); };
    video.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
    video.src = url;
  });
}

/** Сетка фото и видео. Общая для кабинета подрядчика и страницы заказчика. */
export function mediaGrid(reports, stages, onDelete) {
  if (!reports.length) return h('p', { class: 'muted' }, 'Фото и видео пока нет.');
  const names = new Map(stages.map((s) => [s.id, s.name]));
  const groups = new Map();
  for (const r of reports) {
    const key = r.stage_id && names.has(r.stage_id) ? r.stage_id : 0;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(r);
  }
  const order = [...stages.map((s) => s.id).filter((id) => groups.has(id)), ...(groups.has(0) ? [0] : [])];
  return h('div', null, order.map((key) => h('section', { class: 'media-group' },
    h('h3', null, key ? names.get(key) : 'Без этапа'),
    h('div', { class: 'media-grid' }, groups.get(key).map((r) => h('figure', { class: 'media-item' },
      r.kind === 'video'
        ? h('video', { src: r.url, controls: true, preload: 'metadata', playsinline: true, 'aria-label': r.caption || 'Видео с объекта' })
        : h('a', { href: r.url, target: '_blank', rel: 'noopener' }, h('img', { src: r.url, alt: r.caption || 'Фото с объекта', loading: 'lazy' })),
      h('figcaption', null, fmtDate(r.created_at), r.caption ? ` · ${r.caption}` : '',
        onDelete ? h('button', { class: 'btn small bad', type: 'button', 'aria-label': 'Удалить', onclick: () => onDelete(r) }, '✕') : null),
    ))),
  )));
}

async function cameraBlock(project, ctx) {
  const cam = await api.get(`/projects/${project.id}/camera`);
  const box = h('section', { class: 'card camera-block' }, h('h2', null, 'Камера на объекте'));
  if (cam.url) {
    box.append(h('p', { class: 'muted' }, cam.label || 'Трансляция'), h('a', { class: 'btn small', id: 'open-camera', href: cam.url, target: '_blank', rel: 'noopener noreferrer' }, 'Открыть трансляцию'));
  } else {
    box.append(h('p', { class: 'muted' }, 'Ссылка на камеру не добавлена. Подойдёт ссылка на трансляцию из облачного сервиса видеонаблюдения.'));
  }
  if (can(ctx, 'camera.write')) {
    const url = h('input', { type: 'url', placeholder: 'https://… ссылка на трансляцию', value: cam.url || '', 'aria-label': 'Ссылка на камеру', maxlength: '500' });
    const label = h('input', { placeholder: 'Название, например: Въезд на участок', value: cam.label || '', 'aria-label': 'Название камеры', maxlength: '120' });
    const pub = h('input', { type: 'checkbox', id: 'camera-public', checked: cam.public });
    const save = h('button', { class: 'btn small primary', id: 'camera-save', type: 'button', onclick: async () => {
      try { await api.put(`/projects/${project.id}/camera`, { url: url.value.trim() || null, label: label.value.trim(), public: pub.checked }); toast('Сохранено'); const fresh = await cameraBlock(project, ctx); box.replaceWith(fresh); } catch (e) { toast(errText(e), 'bad'); }
    } }, 'Сохранить');
    box.append(h('div', { class: 'upload-form' }, h('div', { class: 'row' }, url, label),
      h('label', { class: 'check-row' }, pub, ' Показывать заказчику на его странице'), h('div', { class: 'actions' }, save)));
  }
  return box;
}

export async function reportsTab(box, project, ctx) {
  async function render() {
    const [reports, schedule] = await Promise.all([api.get(`/projects/${project.id}/reports`), api.get(`/projects/${project.id}/stages`)]);
    const stages = schedule.stages;
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    box.replaceChildren(await cameraBlock(project, ctx));

    if (can(ctx, 'reports.write')) {
      const stageSelect = select({ '': 'Без этапа', ...Object.fromEntries(stages.map((s) => [String(s.id), s.name])) }, '', { 'aria-label': 'Этап' });
      const caption = h('input', { placeholder: 'Подпись (необязательно)', maxlength: '300', 'aria-label': 'Подпись' });
      const file = h('input', { type: 'file', accept: 'image/*,video/*', id: 'report-file', 'aria-label': 'Фото или видео' });
      const status = h('p', { class: 'muted', role: 'status' }, `Фото сожмём само. Видео — до ${MAX_VIDEO_SECONDS} секунд и 25 МБ.`);
      const button = h('button', { class: 'btn primary', id: 'report-send', type: 'button' }, 'Загрузить');
      button.addEventListener('click', async () => {
        let chosen = file.files[0];
        if (!chosen) { status.textContent = 'Сначала выберите фото или видео.'; return; }
        button.disabled = true;
        try {
          if (chosen.type.startsWith('video/')) {
            const seconds = await videoSeconds(chosen);
            if (chosen.size > MAX_VIDEO_BYTES || (seconds !== null && seconds > MAX_VIDEO_SECONDS + 1)) {
              status.textContent = `Видео длиннее ${MAX_VIDEO_SECONDS} секунд или больше 25 МБ. Снимите покороче.`;
              return;
            }
          } else {
            status.textContent = 'Сжимаем фото…';
            chosen = await compressPhoto(chosen);
          }
          const form = new FormData();
          form.append('file', chosen);
          if (stageSelect.value) form.append('stage_id', stageSelect.value);
          form.append('caption', caption.value);
          await api.upload(`/projects/${project.id}/reports`, form);
          toast('Загружено');
          reload();
        } catch (e) {
          status.textContent = errText(e);
        } finally {
          button.disabled = false;
        }
      });
      box.append(h('section', { class: 'card upload-form' }, h('h2', null, 'Новый отчёт'),
        h('div', { class: 'row' }, stageSelect, caption), h('div', { style: 'margin-top:10px' }, file), h('div', { class: 'actions' }, button), status));
    }

    box.append(mediaGrid(reports, stages, can(ctx, 'reports.write') ? (r) => confirmDialog('Удалить?', 'Фото или видео исчезнет и у заказчика.', 'Удалить', async () => {
      try { await api.del(`/reports/${r.id}`); toast('Удалено'); reload(); } catch (e) { toast(errText(e), 'bad'); }
    }) : null));
  }
  await render();
}
