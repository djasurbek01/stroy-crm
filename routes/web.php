<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LifecycleController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProjDocController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SiteTaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\WorkStageController;
use Illuminate\Support\Facades\Route;

/*
| Адреса те же, что у прежней версии: интерфейс обращается к ним напрямую (без префикса /api).
| Числовые параметры проверяются шаблоном, поэтому /estimates/compare не путается с /estimates/{id}.
*/
foreach (['id', 'projectId', 'stageId', 'docId', 'visaId', 'taskId', 'reportId', 'purchaseId'] as $param) {
    Route::pattern($param, '[0-9]+');
}

// --- страницы ---
Route::get('/', [PageController::class, 'app']);
Route::get('/customer.html', [PageController::class, 'customer']);
Route::get('/lead.html', [PageController::class, 'lead']);
Route::get('/offer.html', [PageController::class, 'offer']);
Route::get('/shell-files', [PageController::class, 'shellFiles']);
Route::get('/health', [PageController::class, 'health']);
Route::get('/docs', [PageController::class, 'docs']);
Route::get('/openapi.json', [PageController::class, 'openapi']);

// --- без входа ---
Route::get('/auth/config', [AuthController::class, 'config']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/forgot', [AuthController::class, 'forgot']);
Route::post('/auth/reset', [AuthController::class, 'reset']);

Route::prefix('public')->group(function () {
    Route::post('/leads/{key}', [LeadController::class, 'publicLead']);
    Route::get('/offers/{token}', [OfferController::class, 'publicShow']);
    Route::post('/offers/{token}/accept', [OfferController::class, 'publicAccept']);
    Route::get('/customer/{token}', [WorkStageController::class, 'publicCustomer']);
    Route::post('/customer/{token}/tasks', [SiteTaskController::class, 'customerAdd']);
    Route::post('/customer/{token}/tasks/{taskId}/done', [SiteTaskController::class, 'customerDone']);
    Route::get('/customer/{token}/reports/{reportId}/file', [ReportController::class, 'customerFile']);
    Route::get('/customer/{token}/docs/{docId}/file', [ProjDocController::class, 'customerFile']);
    Route::post('/customer/{token}/docs/{docId}/visas/{visaId}/decide', [ProjDocController::class, 'customerDecide']);
    Route::get('/customer/{token}/chat', [ChatController::class, 'customerIndex']);
    Route::post('/customer/{token}/chat', [ChatController::class, 'customerSend']);
});

// --- после входа ---
Route::middleware('stroy.auth')->group(function () {
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/password', [AuthController::class, 'password']);

    // объекты
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('cap:projects.write');
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::patch('/projects/{id}', [ProjectController::class, 'update'])->middleware('cap:projects.write');
    Route::get('/projects/{id}/members', [ProjectController::class, 'members']);
    Route::put('/projects/{id}/members', [ProjectController::class, 'setMembers'])->middleware('cap:projects.write');

    // контакты
    Route::get('/counterparties', [CounterpartyController::class, 'index'])->middleware('cap:directory.read');
    Route::post('/counterparties', [CounterpartyController::class, 'store'])->middleware('cap:contacts.write');
    Route::get('/counterparties/{id}', [CounterpartyController::class, 'show'])->middleware('cap:directory.read');
    Route::patch('/counterparties/{id}', [CounterpartyController::class, 'update'])->middleware('cap:contacts.write');
    Route::get('/counterparties/{id}/overview', [CounterpartyController::class, 'overview'])->middleware('cap:contacts.read');
    Route::delete('/counterparties/{id}', [LifecycleController::class, 'deleteCounterparty']);

    // документы
    Route::post('/projects/{projectId}/documents', [DocumentController::class, 'store']);
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{id}', [DocumentController::class, 'show']);
    Route::get('/documents/{id}/download', [DocumentController::class, 'download']);
    Route::post('/documents/{id}/versions', [DocumentController::class, 'addVersionAction']);
    Route::post('/documents/{id}/submit', [DocumentController::class, 'submit']);
    Route::post('/documents/{id}/decision', [DocumentController::class, 'decide']);
    Route::delete('/documents/{id}', [LifecycleController::class, 'deleteDocument']);
    Route::get('/tasks', [DocumentController::class, 'tasks']);
    Route::get('/ai/status', [AiController::class, 'status'])->middleware('cap:ai.use');
    Route::post('/documents/recognize', [AiController::class, 'recognize'])->middleware('cap:ai.use');

    // договоры и закупки
    Route::post('/projects/{projectId}/contracts', [ContractController::class, 'store'])->middleware('cap:contracts.write');
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/{id}', [ContractController::class, 'show']);
    Route::patch('/contracts/{id}', [ContractController::class, 'update'])->middleware('cap:contracts.write');
    Route::delete('/contracts/{id}', [LifecycleController::class, 'deleteContract']);
    Route::post('/projects/{projectId}/purchases', [PurchaseController::class, 'store'])->middleware('cap:purchases.write');
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::patch('/purchases/{id}', [PurchaseController::class, 'update'])->middleware('cap:purchases.write');
    Route::delete('/purchases/{id}', [LifecycleController::class, 'deletePurchase']);
    Route::post('/projects/{projectId}/purchases/import', [PurchaseController::class, 'import'])->middleware('cap:purchases.write');

    // команда, демо, сводка, Telegram
    Route::get('/team', [TeamController::class, 'index']);
    Route::post('/team', [TeamController::class, 'store'])->middleware('cap:team.manage');
    Route::post('/team/{id}/password', [TeamController::class, 'setPassword'])->middleware('cap:team.manage');
    Route::get('/team/permissions', [TeamController::class, 'permissions'])->middleware('cap:team.manage');
    Route::put('/team/permissions', [TeamController::class, 'savePermissions'])->middleware('cap:team.manage');
    Route::get('/telegram/status', [TeamController::class, 'telegramStatus']);
    Route::post('/telegram/link', [TeamController::class, 'telegramLink']);
    Route::delete('/telegram/link', [TeamController::class, 'telegramUnlink']);
    Route::get('/dashboard', [HomeController::class, 'dashboard']);
    Route::post('/demo', [DemoController::class, 'store'])->middleware('cap:demo.manage');
    Route::delete('/demo', [DemoController::class, 'destroy'])->middleware('cap:demo.manage');

    // платежи и выгрузка
    Route::post('/payments/import', [PaymentController::class, 'import'])->middleware('cap:money.write');
    Route::post('/payments/rematch', [PaymentController::class, 'rematch'])->middleware('cap:money.write');
    Route::get('/payments', [PaymentController::class, 'index'])->middleware('cap:money.read');
    Route::patch('/payments/{id}', [PaymentController::class, 'update'])->middleware('cap:money.write');
    Route::get('/export.xlsx', [ExportController::class, 'xlsx'])->middleware('cap:money.read');
    Route::get('/export/{kind}.csv', [ExportController::class, 'csv'])->middleware('cap:money.read');
    Route::get('/expenses/summary', [TimesheetController::class, 'expenses'])->middleware('cap:money.read');

    // воронки и сделки
    Route::get('/pipelines', [PipelineController::class, 'index'])->middleware('cap:crm.read');
    Route::middleware('cap:pipelines.admin')->group(function () {
        Route::post('/pipelines', [PipelineController::class, 'store']);
        Route::patch('/pipelines/{id}', [PipelineController::class, 'update']);
        Route::delete('/pipelines/{id}', [PipelineController::class, 'destroy']);
        Route::post('/pipelines/{id}/stages', [PipelineController::class, 'addStage']);
        Route::post('/pipelines/{id}/stages/order', [PipelineController::class, 'reorder']);
        Route::patch('/stages/{id}', [PipelineController::class, 'updateStage']);
        Route::delete('/stages/{id}', [PipelineController::class, 'destroyStage']);
        Route::put('/stages/{id}/automations', [PipelineController::class, 'setAutomations']);
    });
    Route::get('/deals', [DealController::class, 'index'])->middleware('cap:crm.read');
    Route::get('/deals/{id}', [DealController::class, 'show'])->middleware('cap:crm.read');
    Route::delete('/deals/{id}', [LifecycleController::class, 'deleteDeal']);
    Route::middleware('cap:deals.write')->group(function () {
        Route::post('/deals', [DealController::class, 'store']);
        Route::patch('/deals/{id}', [DealController::class, 'update']);
        Route::post('/deals/{id}/move', [DealController::class, 'move']);
        Route::post('/deals/{id}/notes', [DealController::class, 'note']);
        Route::put('/deals/{id}/offer', [OfferController::class, 'save']);
        Route::post('/deals/{id}/offer/share', [OfferController::class, 'share']);
        Route::post('/deals/{id}/offer/unshare', [OfferController::class, 'unshare']);
        Route::get('/company', [OfferController::class, 'companyShow']);
    });
    Route::put('/company', [OfferController::class, 'companyUpdate'])->middleware('cap:settings.manage');
    Route::get('/todos', [DealController::class, 'todos']);
    Route::post('/todos', [DealController::class, 'storeTodo']);
    Route::patch('/todos/{id}', [DealController::class, 'updateTodo']);

    // заявки
    Route::get('/leads/settings', [LeadController::class, 'settingsShow'])->middleware('cap:leads.manage');
    Route::post('/leads/settings/rotate', [LeadController::class, 'rotate'])->middleware('cap:settings.manage');
    Route::post('/deals/{id}/accept', [LeadController::class, 'accept'])->middleware('cap:leads.manage');
    Route::post('/deals/{id}/reject', [LeadController::class, 'reject'])->middleware('cap:leads.manage');

    // аналитика, рабочий стол, поиск, корзина, журнал
    Route::get('/analytics/funnel', [AnalyticsController::class, 'funnel'])->middleware('cap:analytics.read');
    Route::get('/analytics/goal', [AnalyticsController::class, 'goal'])->middleware('cap:crm.read');
    Route::put('/analytics/goal', [AnalyticsController::class, 'setGoal'])->middleware('cap:goal.write');
    Route::get('/home', [HomeController::class, 'home'])->middleware('cap:home.read');
    Route::get('/search', [HomeController::class, 'search']);
    Route::get('/trash', [LifecycleController::class, 'trash'])->middleware('cap:trash.manage');
    Route::post('/trash/{kind}/{id}/restore', [LifecycleController::class, 'restore'])->middleware('cap:trash.manage');
    Route::get('/audit', [LifecycleController::class, 'audit'])->middleware('cap:audit.read');

    // табель
    Route::get('/workers', [TimesheetController::class, 'workers'])->middleware('cap:timesheet.read');
    Route::post('/workers', [TimesheetController::class, 'storeWorker'])->middleware('cap:payroll.write');
    Route::patch('/workers/{id}', [TimesheetController::class, 'updateWorker'])->middleware('cap:payroll.write');
    Route::get('/timesheet', [TimesheetController::class, 'entries'])->middleware('cap:timesheet.read');
    Route::put('/timesheet', [TimesheetController::class, 'setHours'])->middleware('cap:timesheet.write');
    Route::get('/timesheet/summary', [TimesheetController::class, 'summary'])->middleware('cap:payroll.read');

    // график работ и ссылка заказчика
    Route::get('/projects/{projectId}/stages', [WorkStageController::class, 'index'])->middleware('cap:stages.read');
    Route::middleware('cap:stages.write')->group(function () {
        Route::post('/projects/{projectId}/stages', [WorkStageController::class, 'store']);
        Route::post('/projects/{projectId}/stages/template', [WorkStageController::class, 'template']);
        Route::post('/projects/{projectId}/stages/{stageId}/shift', [WorkStageController::class, 'shift']);
        Route::patch('/work-stages/{id}', [WorkStageController::class, 'update']);
        Route::delete('/work-stages/{id}', [WorkStageController::class, 'destroy']);
    });
    Route::post('/projects/{projectId}/customer-link', [WorkStageController::class, 'share'])->middleware('cap:projects.write');
    Route::delete('/projects/{projectId}/customer-link', [WorkStageController::class, 'unshare'])->middleware('cap:projects.write');

    // задачи по объекту
    Route::get('/projects/{projectId}/site-tasks', [SiteTaskController::class, 'index'])->middleware('cap:sitetasks.read');
    Route::post('/projects/{projectId}/site-tasks', [SiteTaskController::class, 'store'])->middleware('cap:sitetasks.create');
    Route::patch('/site-tasks/{id}', [SiteTaskController::class, 'update'])->middleware('cap:sitetasks.create');
    Route::post('/site-tasks/{id}/status', [SiteTaskController::class, 'status']);
    Route::get('/site-tasks/{id}', [SiteTaskController::class, 'show'])->middleware('cap:sitetasks.read');
    Route::post('/site-tasks/{id}/comments', [SiteTaskController::class, 'comment'])->middleware('cap:sitetasks.read');
    Route::post('/site-tasks/{id}/extension', [SiteTaskController::class, 'requestExtension'])->middleware('cap:sitetasks.read');
    Route::post('/site-tasks/{id}/extension/decide', [SiteTaskController::class, 'decideExtension'])->middleware('cap:sitetasks.read');
    Route::delete('/site-tasks/{id}', [SiteTaskController::class, 'destroy'])->middleware('cap:sitetasks.create');

    // фото и видео
    Route::get('/projects/{projectId}/reports', [ReportController::class, 'index'])->middleware('cap:reports.read');
    Route::post('/projects/{projectId}/reports', [ReportController::class, 'store'])->middleware('cap:reports.write');
    Route::get('/reports/{id}/file', [ReportController::class, 'file'])->middleware('cap:reports.read');
    Route::delete('/reports/{id}', [ReportController::class, 'destroy'])->middleware('cap:reports.write');

    // проектная документация
    Route::get('/projects/{projectId}/proj-docs', [ProjDocController::class, 'index'])->middleware('cap:pdocs.read');
    Route::post('/projects/{projectId}/proj-docs', [ProjDocController::class, 'store'])->middleware('cap:pdocs.write');
    Route::post('/proj-docs/{id}/version', [ProjDocController::class, 'newVersion'])->middleware('cap:pdocs.write');
    Route::post('/proj-docs/{docId}/visas/{visaId}/decide', [ProjDocController::class, 'decide']);
    Route::post('/proj-docs/{id}/release', [ProjDocController::class, 'release'])->middleware('cap:pdocs.write');
    Route::delete('/proj-docs/{id}', [ProjDocController::class, 'destroy'])->middleware('cap:pdocs.write');
    Route::get('/proj-docs/{id}/file', [ProjDocController::class, 'file'])->middleware('cap:pdocs.read');

    // чат, лента, камера
    Route::get('/projects/{projectId}/chat', [ChatController::class, 'index'])->middleware('cap:chat.read');
    Route::post('/projects/{projectId}/chat', [ChatController::class, 'send'])->middleware('cap:chat.write');
    Route::post('/projects/{projectId}/chat/seen', [ChatController::class, 'seen'])->middleware('cap:chat.read');
    Route::get('/projects/{projectId}/activity', [ChatController::class, 'activity'])->middleware('cap:feed.read');
    Route::get('/projects/{projectId}/camera', [ChatController::class, 'camera'])->middleware('cap:camera.read');
    Route::put('/projects/{projectId}/camera', [ChatController::class, 'setCamera'])->middleware('cap:camera.write');

    // приёмка этапов
    Route::get('/projects/{projectId}/inspections', [InspectionController::class, 'index'])->middleware('cap:inspections.read');
    Route::post('/projects/{projectId}/inspections', [InspectionController::class, 'store'])->middleware('cap:inspections.request');
    Route::post('/inspections/{id}/result', [InspectionController::class, 'result'])->middleware('cap:inspections.perform');
    Route::post('/inspections/{id}/reinspect', [InspectionController::class, 'reinspect'])->middleware('cap:inspections.request');

    // уведомления
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);

    // финансы объекта
    Route::get('/projects/{projectId}/finance', [FinanceController::class, 'show'])->middleware('cap:finance.read');
    Route::put('/projects/{projectId}/budget', [FinanceController::class, 'budget'])->middleware('cap:budget.write');
    Route::post('/projects/{projectId}/plan-items', [FinanceController::class, 'storeItem'])->middleware('cap:budget.write');
    Route::patch('/plan-items/{id}', [FinanceController::class, 'updateItem'])->middleware('cap:budget.write');
    Route::delete('/plan-items/{id}', [FinanceController::class, 'destroyItem'])->middleware('cap:budget.write');
    Route::post('/plan-items/{id}/receive', [FinanceController::class, 'receive'])->middleware('cap:finance.write');

    // склад
    Route::get('/materials', [MaterialController::class, 'index'])->middleware('cap:materials.read');
    Route::post('/materials', [MaterialController::class, 'store'])->middleware('cap:materials.write');
    Route::patch('/materials/{id}', [MaterialController::class, 'update'])->middleware('cap:materials.write');
    Route::get('/materials/{id}/movements', [MaterialController::class, 'movements'])->middleware('cap:materials.read');
    Route::post('/materials/{id}/movements', [MaterialController::class, 'storeMovement'])->middleware('cap:materials.write');
    Route::get('/material-requests', [MaterialController::class, 'requests'])->middleware('cap:materials.read');
    Route::get('/projects/{projectId}/material-requests', [MaterialController::class, 'projectRequests'])->middleware('cap:materials.read');
    Route::post('/projects/{projectId}/material-requests', [MaterialController::class, 'storeRequest'])->middleware('cap:materials.request');
    Route::post('/material-requests/{id}/issue', [MaterialController::class, 'issue'])->middleware('cap:materials.issue');
    Route::post('/material-requests/{id}/to-purchase', [MaterialController::class, 'toPurchase'])->middleware('cap:materials.write');
    Route::post('/material-requests/{id}/cancel', [MaterialController::class, 'cancel'])->middleware('cap:materials.request');
    Route::post('/purchases/{purchaseId}/receive', [MaterialController::class, 'receivePurchase'])->middleware('cap:materials.write');
    Route::get('/projects/{projectId}/materials', [MaterialController::class, 'projectMaterials'])->middleware('cap:materials.read');

    // сметы
    Route::get('/projects/{projectId}/estimates', [EstimateController::class, 'index'])->middleware('cap:estimates.read');
    Route::post('/projects/{projectId}/estimates', [EstimateController::class, 'store'])->middleware('cap:estimates.write');
    Route::get('/estimates/compare', [EstimateController::class, 'compare'])->middleware('cap:estimates.read');
    Route::get('/estimates/{id}', [EstimateController::class, 'show'])->middleware('cap:estimates.read');
    Route::put('/estimates/{id}', [EstimateController::class, 'update'])->middleware('cap:estimates.write');
    Route::post('/estimates/{id}/new-version', [EstimateController::class, 'newVersion'])->middleware('cap:estimates.write');
    Route::post('/estimates/{id}/approve', [EstimateController::class, 'approve'])->middleware('cap:estimates.approve');
    Route::post('/estimates/{id}/apply-budget', [EstimateController::class, 'applyBudget'])->middleware('cap:budget.write');
    Route::delete('/estimates/{id}', [EstimateController::class, 'destroy'])->middleware('cap:estimates.write');

    // панель руководителя
    Route::get('/portfolio', [PortfolioController::class, 'index'])->middleware('cap:portfolio.read');
    Route::get('/portfolio/workload', [PortfolioController::class, 'workload'])->middleware('cap:portfolio.read');
});
