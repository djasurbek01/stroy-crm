<?php

namespace App\Support;

/**
 * Документация API (OpenAPI 3.1) для страницы /docs.
 *
 * Основа — схема прежней версии (resources/openapi/base.json): адреса, ответы и коды ошибок в Laravel те же.
 * Здесь к ней добавляются адреса, появившиеся после переноса (участники объекта, ход задачи, права ролей),
 * и обновляется описание входа: вместо cookie `token` теперь сессия Laravel.
 */
class ApiDocs
{
    public const BASE = 'openapi/base.json';

    /** Полная схема. */
    public static function spec(): array
    {
        $spec = json_decode(file_get_contents(resource_path(self::BASE)), true);
        $cookie = config('session.cookie');

        $spec['info']['version'] = '2.0.0';
        $spec['info']['description'] = str_replace(
            'В ответ сервер ставит cookie **`token`**: подписанный токен на 7 суток',
            "В ответ сервер ставит cookie сессии **`$cookie`** на 7 суток",
            $spec['info']['description'] ?? '',
        )."\n\n**Права ролей настраиваются владельцем** (раздел «Команда» → «Права ролей», адрес `/team/permissions`). "
            .'В описаниях операций указаны стандартные права; итоговые права сотрудника приходят в поле `can` ответа `GET /auth/me`.'
            ."\n\n**Доступ к объектам.** Владелец видит все объекты компании, остальные — только объекты, где они участники "
            .'(или назначены руководителем проекта либо прорабом). Записи чужих объектов отвечают 404.';
        $spec['components']['securitySchemes']['cookieAuth'] = [
            'type' => 'apiKey',
            'in' => 'cookie',
            'name' => $cookie,
            'description' => "Cookie сессии `$cookie` ставится при входе (`POST /auth/login`, `POST /auth/register`). Живёт 7 суток.",
        ];
        $spec['servers'] = [['url' => '/', 'description' => 'Этот сервер']];

        foreach (self::additions() as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $spec['paths'][$path][$method] = $operation;
            }
        }
        self::amendSiteTasks($spec);
        ksort($spec['paths']);

        return $spec;
    }

    /** Ответы-ошибки в формате прежней схемы: [статус => [код => пояснение]]. */
    private static function errors(array $byStatus): array
    {
        $titles = [
            401 => 'Нет входа или вход истёк', 403 => 'Нет права на действие', 404 => 'Не найдено или принадлежит другой организации',
            409 => 'Действие невозможно в текущем состоянии', 422 => 'Ошибка в данных запроса',
        ];
        $out = [];
        foreach ($byStatus as $status => $codes) {
            $examples = [];
            foreach ($codes as $code => $text) {
                $examples[$code] = ['summary' => $code, 'description' => $text, 'value' => ['detail' => $code]];
            }
            $out[(string) $status] = [
                'description' => $titles[$status] ?? 'Ошибка',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorOut'], 'examples' => $examples]],
            ];
        }

        return $out;
    }

    private static function ok(array $example, string $description = 'Успешный ответ', string $status = '200'): array
    {
        return [$status => ['description' => $description, 'content' => ['application/json' => ['example' => $example]]]];
    }

    private static function json(array $schema, array $example): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $schema, 'example' => $example]]];
    }

    private static function id(string $name, string $title): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'title' => $title]];
    }

    private static function op(string $tag, string $summary, string $description, string $access, array $extra): array
    {
        return array_merge([
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $description."\n\n".$access,
            'security' => [['cookieAuth' => []]],
        ], $extra);
    }

    /** Пример задачи объекта в кабинете (с полями хода работ). */
    private static function taskExample(array $override = []): array
    {
        return array_merge([
            'id' => 5, 'kind' => 'task', 'side' => 'contractor', 'source' => 'staff', 'title' => 'Залить фундамент', 'note' => '',
            'author' => 'Анна Владелец', 'due_date' => '2026-11-25', 'done' => false, 'done_at' => null, 'overdue' => false,
            'status' => 'in_progress', 'priority' => 'normal', 'assignee_id' => 2, 'assignee_name' => 'Пётр Подрядчик',
            'start_date' => null, 'reason' => '', 'requires_review' => false, 'stage_id' => null,
            'created_by' => 1, 'created_by_name' => 'Анна Владелец', 'extension' => null,
            'can_decide' => true, 'can_edit' => true, 'needs_extension' => false,
        ], $override);
    }

    /** Адреса, которых не было в прежней версии. */
    private static function additions(): array
    {
        $anyRole = '**Нужен вход.** Подходит любая роль; объект должен быть доступен человеку.';
        $notFoundTask = [404 => ['task_not_found' => 'Задача не найдена или объект недоступен.']];
        $member = ['id' => 2, 'name' => 'Пётр Подрядчик', 'role' => 'contractor', 'role_name' => 'Подрядчик', 'via' => 'member'];

        return [
            '/projects/{id}/members' => [
                'get' => self::op('projects', 'Участники объекта', 'Кто видит объект. `via`: `owner` (владелец видит все объекты), `manager` (руководитель проекта), `foreman` (прораб), `member` (добавлен участником).', $anyRole, [
                    'operationId' => 'project_members',
                    'parameters' => [self::id('id', 'Project Id')],
                    'responses' => self::ok([['id' => 1, 'name' => 'Анна Владелец', 'role' => 'owner', 'role_name' => 'Владелец', 'via' => 'owner'], $member])
                        + self::errors([401 => ['not_authenticated' => 'Войдите в систему заново.'], 404 => ['project_not_found' => 'Объект не найден или недоступен.']]),
                ]),
                'put' => self::op('projects', 'Задать участников объекта', 'Список участников целиком: кого нет в списке, у того объект пропадает. Владелец, руководитель проекта и прораб объекта видят его всегда.', '**Нужен вход и право `projects.write`** (стандартно: владелец, руководитель проекта).', [
                    'operationId' => 'set_project_members',
                    'parameters' => [self::id('id', 'Project Id')],
                    'requestBody' => self::json(['type' => 'object', 'required' => ['user_ids'], 'properties' => ['user_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 200]]], ['user_ids' => [2, 3]]),
                    'responses' => self::ok([$member]) + self::errors([403 => ['forbidden' => 'У вашей роли нет прав на это действие.'], 404 => ['project_not_found' => 'Объект не найден.', 'assignee_not_found' => 'Такого сотрудника нет в компании.']]),
                ]),
            ],
            '/site-tasks/{id}' => [
                'get' => self::op('site-tasks', 'Карточка задачи с историей', 'Задача со всеми полями и историей: создание, смены статуса с комментариями, запросы на продление и решения по ним, комментарии.', $anyRole, [
                    'operationId' => 'site_task_card',
                    'parameters' => [self::id('id', 'Task Id')],
                    'responses' => self::ok(self::taskExample(['events' => [
                        ['id' => 1, 'kind' => 'created', 'text' => 'Задача поставлена, срок 25.11.2026', 'author' => 'Анна Владелец', 'created_at' => '2026-11-20T09:00:00'],
                        ['id' => 2, 'kind' => 'comment', 'text' => 'Бетон привезут в среду', 'author' => 'Пётр Подрядчик', 'created_at' => '2026-11-20T10:15:00'],
                    ]])) + self::errors($notFoundTask),
                ]),
            ],
            '/site-tasks/{id}/comments' => [
                'post' => self::op('site-tasks', 'Комментарий в задаче', 'Пишет любой, кому доступен объект. Уведомление получают исполнитель и поставивший задачу (кроме автора комментария). Ответ — карточка задачи с историей.', $anyRole, [
                    'operationId' => 'site_task_comment',
                    'parameters' => [self::id('id', 'Task Id')],
                    'requestBody' => self::json(['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000]]], ['text' => 'Бетон привезут в среду']),
                    'responses' => self::ok(self::taskExample(['events' => []]), 'Комментарий добавлен', '201') + self::errors($notFoundTask),
                ]),
            ],
            '/site-tasks/{id}/extension' => [
                'post' => self::op('site-tasks', 'Попросить продление срока', 'Только исполнитель задачи. Новая дата позже текущего срока и не раньше сегодня, комментарий обязателен. Срок меняется, когда ЛПР согласует запрос. По просроченной задаче исполнитель не может менять статус, пока продление не согласовано.', $anyRole, [
                    'operationId' => 'site_task_request_extension',
                    'parameters' => [self::id('id', 'Task Id')],
                    'requestBody' => self::json(['type' => 'object', 'required' => ['due_date', 'comment'], 'properties' => ['due_date' => ['type' => 'string', 'format' => 'date'], 'comment' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]]], ['due_date' => '2026-11-28', 'comment' => 'Поставщик бетона сдвинул отгрузку на 3 дня']),
                    'responses' => self::ok(self::taskExample(['can_decide' => false, 'can_edit' => false, 'extension' => ['due_date' => '2026-11-28', 'reason' => 'Поставщик бетона сдвинул отгрузку на 3 дня', 'requested_by' => 'Пётр Подрядчик', 'requested_at' => '2026-11-20T10:20:00']]))
                        + self::errors([403 => ['not_your_task' => 'Просить продление может только исполнитель.'], 409 => ['task_closed' => 'Задача уже выполнена.'], 422 => ['extension_date_invalid' => 'Новый срок должен быть позже текущего.']] + $notFoundTask),
                ]),
            ],
            '/site-tasks/{id}/extension/decide' => [
                'post' => self::op('site-tasks', 'Согласовать или отклонить продление', 'Решает ЛПР по задаче: поставивший её или руководство объекта (право `tasks.review`). При согласовании новый срок вступает в силу. Отказ — только с комментарием.', $anyRole, [
                    'operationId' => 'site_task_decide_extension',
                    'parameters' => [self::id('id', 'Task Id')],
                    'requestBody' => self::json(['type' => 'object', 'required' => ['decision'], 'properties' => ['decision' => ['type' => 'string', 'enum' => ['approved', 'rejected']], 'comment' => ['type' => 'string', 'maxLength' => 500]]], ['decision' => 'approved', 'comment' => 'Согласовано']),
                    'responses' => self::ok(self::taskExample(['due_date' => '2026-11-28']))
                        + self::errors([403 => ['not_decider' => 'Это решает тот, кто поставил задачу, или руководитель объекта.'], 409 => ['no_extension_request' => 'Запроса на продление нет.'], 422 => ['comment_required' => 'Для отказа нужен комментарий.']] + $notFoundTask),
                ]),
            ],
            '/team/permissions' => [
                'get' => self::op('team', 'Права ролей компании', 'Каталог настраиваемых прав по группам, текущие права каждой роли (`matrix`) и стандартные (`defaults`). Владелец в списке ролей не участвует: у него всегда все права.', '**Нужен вход и право `team.manage`** (владелец).', [
                    'operationId' => 'role_permissions',
                    'responses' => self::ok([
                        'roles' => [['key' => 'foreman', 'name' => 'Прораб', 'people' => 1]],
                        'groups' => [['title' => 'Разделы меню', 'caps' => [['key' => 'crm.read', 'label' => 'Сделки (просмотр)'], ['key' => 'money.read', 'label' => 'Платежи и траты']]]],
                        'matrix' => ['foreman' => ['crm.read' => false, 'money.read' => true]],
                        'defaults' => ['foreman' => ['crm.read' => true, 'money.read' => false]],
                        'counts' => ['owner' => 1, 'foreman' => 1],
                    ]) + self::errors([403 => ['forbidden' => 'Настраивать права может только владелец.']]),
                ]),
                'put' => self::op('team', 'Сохранить права ролей', 'Присылаются нужные значения `роль → право → true/false`; не присланные остаются как есть. В базе хранятся только отличия от стандарта, поэтому прислать стандартные значения — значит вернуть права по умолчанию. Закреплённые права и права владельца не меняются.', '**Нужен вход и право `team.manage`** (владелец).', [
                    'operationId' => 'save_role_permissions',
                    'requestBody' => self::json(['type' => 'object', 'required' => ['matrix'], 'properties' => ['matrix' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']]]]], ['matrix' => ['foreman' => ['crm.read' => false, 'money.read' => true]]]),
                    'responses' => self::ok(['roles' => [], 'groups' => [], 'matrix' => ['foreman' => ['crm.read' => false, 'money.read' => true]], 'defaults' => [], 'counts' => []])
                        + self::errors([403 => ['forbidden' => 'Настраивать права может только владелец.']]),
                ]),
            ],
        ];
    }

    /** Новые правила хода задач в описаниях прежних операций. */
    private static function amendSiteTasks(array &$spec): void
    {
        $rules = "\n\n**Ход задачи после доработки:** исполнитель, не являющийся ЛПР, вместо «выполнено» отправляет задачу на проверку (`in_review`). "
            .'ЛПР (поставивший задачу или руководство объекта) может вернуть даже выполненную задачу на доработку (`rejected`, нужна причина). '
            .'По просроченной задаче исполнитель получает `409 extension_required`, пока продление не согласовано.';
        foreach (['/site-tasks/{task_id}/status' => 'post', '/site-tasks/{task_id}' => 'patch'] as $path => $method) {
            if (isset($spec['paths'][$path][$method])) {
                $spec['paths'][$path][$method]['description'] .= $rules;
            }
        }
        if (isset($spec['paths']['/site-tasks/{task_id}/status']['post']['responses']['409']['content']['application/json']['examples'])) {
            $spec['paths']['/site-tasks/{task_id}/status']['post']['responses']['409']['content']['application/json']['examples']['extension_required'] = [
                'summary' => 'extension_required', 'description' => 'Срок прошёл: сначала согласуйте продление.', 'value' => ['detail' => 'extension_required'],
            ];
        }
        if (isset($spec['paths']['/projects/{project_id}/site-tasks']['post'])) {
            $spec['paths']['/projects/{project_id}/site-tasks']['post']['description'] .= "\n\nСтавить задачи может любой участник объекта (право `sitetasks.create`). Исполнитель — только участник объекта, иначе `422 assignee_not_member`.";
        }
    }
}
