// Подписи для задач, приёмки, финансов, склада и смет.
export const TASK_STATUS = {
  new: 'Новая',
  in_progress: 'В работе',
  waiting_materials: 'Ждёт материалов',
  in_review: 'На проверке',
  done: 'Выполнена',
  rejected: 'Возвращена',
};
export const TASK_STATUS_TONE = { new: '', in_progress: 'blue', waiting_materials: 'orange', in_review: 'orange', done: 'green', rejected: 'red' };
export const PRIORITY = { low: 'Низкий', normal: 'Обычный', high: 'Высокий', critical: 'Критичный' };
export const PRIORITY_TONE = { low: '', normal: '', high: 'orange', critical: 'red' };

export const INSPECTION_STATUS = { requested: 'Ждёт проверки', passed: 'Принят', failed: 'Есть замечания' };
export const INSPECTION_TONE = { requested: 'orange', passed: 'green', failed: 'red' };

export const BUDGET_CATEGORIES = { materials: 'Материалы', labor: 'Работы', subcontract: 'Подрядчики', equipment: 'Техника и инструмент', transport: 'Транспорт', other: 'Прочее' };
export const PLAN_STATUS = { upcoming: 'Ожидается', partial: 'Частично', paid: 'Получен', overdue: 'Просрочен' };
export const PLAN_TONE = { upcoming: '', partial: 'blue', paid: 'green', overdue: 'red' };

export const ESTIMATE_SECTIONS = { materials: 'Материалы', work: 'Работы', equipment: 'Техника', transport: 'Транспорт', subcontract: 'Подрядчики', other: 'Прочее' };
export const ESTIMATE_STATUS = { draft: 'Черновик', approved: 'Утверждена', archived: 'В архиве' };
export const ESTIMATE_TONE = { draft: 'orange', approved: 'green', archived: '' };

export const REQUEST_STATUS = { new: 'Новая', partial: 'Выдана частично', issued: 'Выдана', purchase: 'Нужна закупка', cancelled: 'Отменена' };
export const REQUEST_TONE = { new: 'orange', partial: 'blue', issued: 'green', purchase: 'orange', cancelled: '' };
export const MOVE_KIND = { in: 'Приход', out: 'Выдача на объект', writeoff: 'Списание' };

export const ROLE_PLAIN = { owner: 'Владелец', manager: 'Менеджер', foreman: 'Прораб', buyer: 'Снабженец', accountant: 'Бухгалтер', pm: 'Руководитель проекта', storekeeper: 'Кладовщик' };

export const num = (value) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 3 }).format(value || 0);
