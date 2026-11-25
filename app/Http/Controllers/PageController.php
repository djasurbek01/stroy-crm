<?php

namespace App\Http\Controllers;

use App\Support\ApiDocs;
use Illuminate\Support\Facades\File;

/** Страницы интерфейса: приложение и три публичные страницы (заявка, КП, страница заказчика). */
class PageController extends Controller
{
    public function app()
    {
        return view('app');
    }

    public function customer()
    {
        return view('customer');
    }

    public function lead()
    {
        return view('lead');
    }

    public function offer()
    {
        return view('offer');
    }

    /** Документация API: Swagger UI из public/swagger, без внешних сайтов. */
    public function docs()
    {
        abort_unless(config('stroy.docs'), 404);

        return view('docs');
    }

    public function openapi()
    {
        abort_unless(config('stroy.docs'), 404);

        return response()->json(ApiDocs::spec(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function health()
    {
        $file = base_path('BUILD');

        return ['status' => 'ok', 'build' => is_file($file) ? trim(File::get($file)) : 'dev'];
    }

    /** Список файлов оболочки приложения: сервис-воркер кладёт их в кэш при установке. */
    public function shellFiles()
    {
        $files = ['/', '/manifest.webmanifest'];
        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            foreach (json_decode(File::get($manifest), true) ?? [] as $entry) {
                $files[] = '/build/'.$entry['file'];
                foreach ($entry['css'] ?? [] as $css) {
                    $files[] = '/build/'.$css;
                }
            }
        }
        foreach (glob(public_path('icons/*.png')) as $icon) {
            $files[] = '/icons/'.basename($icon);
        }

        return array_values(array_unique($files));
    }
}
