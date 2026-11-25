<?php

namespace App\Support;

use App\Models\RolePermission;
use App\Models\User;

/** Права по ролям. Сервер решает, что можно, интерфейс только прячет лишние кнопки. */
class Perm
{
    /** Сотрудники компании: видят CRM (сделки, контакты, команду). */
    public const STAFF = ['owner', 'manager', 'foreman', 'buyer', 'accountant', 'pm', 'storekeeper'];

    /** Внешние участники объектов: видят только свои объекты. */
    public const EXTERNAL = ['director', 'contractor'];

    public const ROLES = [...self::STAFF, ...self::EXTERNAL];

    public const ROLE_NAMES = [
        'owner' => 'Владелец',
        'manager' => 'Менеджер по продажам',
        'foreman' => 'Прораб',
        'buyer' => 'Снабженец',
        'accountant' => 'Бухгалтер',
        'pm' => 'Руководитель проекта',
        'storekeeper' => 'Кладовщик',
        'director' => 'Генеральный директор',
        'contractor' => 'Подрядчик',
    ];

    public static function caps(): array
    {
        $all = self::ROLES;
        $staff = self::STAFF;

        return [
            // разделы меню
            'home.read' => $staff,
            'crm.read' => $staff,
            'contacts.read' => $staff,
            'boards.read' => $staff,
            'team.read' => $all,
            'plans.read' => $all,
            // справочники для форм (контрагенты, сотрудники): нужны всем сотрудникам компании
            'directory.read' => $staff,
            'sitetasks.create' => $all,
            'deals.write' => ['owner', 'manager'],
            'leads.manage' => ['owner', 'manager'],
            'pipelines.admin' => ['owner'],
            'contacts.write' => ['owner', 'manager', 'buyer', 'accountant'],
            'projects.write' => ['owner', 'pm'],
            'contracts.write' => ['owner', 'buyer'],
            'purchases.write' => ['owner', 'buyer', 'foreman', 'pm'],
            'documents.write' => ['owner', 'manager', 'foreman', 'buyer', 'accountant', 'pm', 'storekeeper'],
            'money.read' => ['owner', 'accountant'],
            'money.write' => ['owner', 'accountant'],
            'analytics.read' => ['owner', 'manager'],
            'goal.write' => ['owner'],
            'team.manage' => ['owner'],
            'demo.manage' => ['owner'],
            'audit.read' => ['owner'],
            'trash.manage' => ['owner'],
            'settings.manage' => ['owner'],
            'ai.use' => ['owner', 'manager', 'buyer', 'accountant'],
            'timesheet.read' => ['owner', 'foreman', 'accountant', 'pm'],
            'timesheet.write' => ['owner', 'foreman', 'pm'],
            'payroll.read' => ['owner', 'accountant'],
            'payroll.write' => ['owner', 'accountant'],
            'stages.read' => $all,
            'stages.write' => ['owner', 'foreman', 'pm', 'director'],
            'sitetasks.read' => $all,
            'sitetasks.write' => ['owner', 'foreman', 'pm', 'director'],
            'reports.read' => $all,
            'reports.write' => ['owner', 'foreman', 'pm', 'director', 'contractor'],
            'pdocs.read' => $all,
            'pdocs.write' => ['owner', 'foreman', 'pm', 'director'],
            'chat.read' => $all,
            'chat.write' => $all,
            'feed.read' => $all,
            'camera.read' => $all,
            'camera.write' => ['owner', 'foreman', 'pm'],
            // проверка задач и приёмка этапов
            'tasks.review' => ['owner', 'pm', 'director'],
            'inspections.read' => $all,
            'inspections.request' => ['owner', 'pm', 'foreman', 'director'],
            'inspections.perform' => ['owner', 'pm', 'director'],
            // панель руководителя и финансы объекта
            'portfolio.read' => ['owner', 'pm', 'accountant'],
            'finance.read' => ['owner', 'accountant', 'pm'],
            'finance.write' => ['owner', 'accountant'],
            'budget.write' => ['owner', 'pm'],
            // материалы и склад
            'materials.read' => $staff,
            'materials.write' => ['owner', 'storekeeper', 'buyer'],
            'materials.request' => ['owner', 'pm', 'foreman'],
            'materials.issue' => ['owner', 'storekeeper'],
            // сметы
            'estimates.read' => ['owner', 'pm', 'manager', 'accountant', 'buyer'],
            'estimates.write' => ['owner', 'pm'],
            'estimates.approve' => ['owner'],
        ];
    }

    /**
     * Права, которые владелец может выдавать и отбирать ролям в разделе «Команда».
     * Остальные (просмотр своих объектов, чата, справочников, управление командой) закреплены.
     *
     * @return array<int, array{title: string, caps: array<string, string>}>
     */
    public static function editable(): array
    {
        return [
            ['title' => 'Разделы меню', 'caps' => [
                'home.read' => 'Главная',
                'crm.read' => 'Сделки (просмотр)',
                'contacts.read' => 'Контакты',
                'analytics.read' => 'Аналитика',
                'portfolio.read' => 'Панель руководителя',
                'materials.read' => 'Склад',
                'boards.read' => 'Закупки и договоры',
                'money.read' => 'Платежи и траты',
                'timesheet.read' => 'Табель',
                'team.read' => 'Команда',
                'plans.read' => 'Тариф',
                'audit.read' => 'Журнал действий',
                'trash.manage' => 'Корзина: просмотр и восстановление',
            ]],
            ['title' => 'Продажи', 'caps' => [
                'deals.write' => 'Создавать и вести сделки',
                'leads.manage' => 'Разбирать заявки с сайта',
                'contacts.write' => 'Добавлять и менять контакты',
                'goal.write' => 'Задавать план продаж',
                'pipelines.admin' => 'Настраивать воронки и автоматику',
                'ai.use' => 'Распознавать документы ИИ',
            ]],
            ['title' => 'Объекты и стройка', 'caps' => [
                'projects.write' => 'Создавать объекты и управлять участниками',
                'stages.write' => 'Менять график работ',
                'sitetasks.create' => 'Ставить задачи на объекте',
                'sitetasks.write' => 'Управлять всеми задачами объекта',
                'tasks.review' => 'Принимать работу и продлевать сроки (ЛПР)',
                'reports.write' => 'Загружать фото и видео',
                'pdocs.write' => 'Вести проектную документацию',
                'chat.write' => 'Писать в чат объекта',
                'camera.write' => 'Настраивать камеру',
                'inspections.request' => 'Отправлять этапы на приёмку',
                'inspections.perform' => 'Проводить приёмку этапов',
            ]],
            ['title' => 'Документы и закупки', 'caps' => [
                'documents.write' => 'Удалять свои документы',
                'contracts.write' => 'Вести договоры',
                'purchases.write' => 'Вести закупки',
            ]],
            ['title' => 'Финансы', 'caps' => [
                'money.write' => 'Загружать выписки и привязывать платежи',
                'finance.read' => 'Видеть финансы объекта',
                'finance.write' => 'Отмечать поступления от заказчика',
                'budget.write' => 'Вести бюджет и график платежей',
                'timesheet.write' => 'Отмечать часы в табеле',
                'payroll.read' => 'Видеть ставки и зарплату',
                'payroll.write' => 'Менять ставки рабочих',
            ]],
            ['title' => 'Склад и сметы', 'caps' => [
                'materials.write' => 'Приход и справочник материалов',
                'materials.request' => 'Подавать заявки на материалы',
                'materials.issue' => 'Выдавать и списывать материалы',
                'estimates.read' => 'Видеть сметы',
                'estimates.write' => 'Составлять сметы',
                'estimates.approve' => 'Утверждать сметы',
            ]],
        ];
    }

    /** Ключи настраиваемых прав. */
    public static function editableKeys(): array
    {
        return array_merge(...array_map(fn ($group) => array_keys($group['caps']), self::editable()));
    }

    /** Стандартное право роли (без настроек компании). */
    public static function can(string $role, string $cap): bool
    {
        return in_array($role, self::caps()[$cap] ?? [], true);
    }

    /**
     * Настройки компании: [роль => [право => выдано/отобрано]]. Читаются один раз за запрос.
     *
     * @return array<string, array<string, bool>>
     */
    public static function overrides(int $organizationId): array
    {
        $key = "stroy.perm.$organizationId";
        if (! app()->bound($key)) {
            $map = [];
            foreach (RolePermission::where('organization_id', $organizationId)->get() as $row) {
                $map[$row->role][$row->cap] = (bool) $row->allowed;
            }
            app()->instance($key, $map);
        }

        return app($key);
    }

    /** Забыть прочитанные настройки (после сохранения). */
    public static function forget(int $organizationId): void
    {
        app()->forgetInstance("stroy.perm.$organizationId");
    }

    /** Право роли с учётом настроек компании. Владелец всегда со стандартными правами. */
    public static function canInOrg(int $organizationId, string $role, string $cap): bool
    {
        if ($role !== 'owner' && in_array($cap, self::editableKeys(), true)) {
            $override = self::overrides($organizationId)[$role][$cap] ?? null;
            if ($override !== null) {
                return $override;
            }
        }

        return self::can($role, $cap);
    }

    /** Есть ли право у этого человека (с учётом настроек его компании). */
    public static function allows(User $user, string $cap): bool
    {
        return self::canInOrg($user->organization_id, $user->role, $cap);
    }

    /** Все права человека, по алфавиту: интерфейс по ним прячет лишние разделы и кнопки. */
    public static function capsForUser(User $user): array
    {
        $out = array_values(array_filter(array_keys(self::caps()), fn ($cap) => self::allows($user, $cap)));
        sort($out);

        return $out;
    }

    /** Люди организации с ролями, у которых есть право (с учётом настроек компании). */
    public static function rolesWith(int $organizationId, string $cap): array
    {
        return array_values(array_filter(self::ROLES, fn ($role) => self::canInOrg($organizationId, $role, $cap)));
    }
}
