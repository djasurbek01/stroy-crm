<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Файлы организаций на диске сервера: storage/app/private/<STROY_STORAGE>/<организация>/<имя>. */
class Files
{
    public const ALLOWED_EXTENSIONS = ['.pdf', '.jpg', '.jpeg', '.png', '.doc', '.docx', '.xls', '.xlsx', '.dwg', '.txt'];

    /** Проверяет и сохраняет загрузку. Возвращает [имя файла, относительный путь, размер]. */
    public static function saveUpload(int $organizationId, ?UploadedFile $upload): array
    {
        if ($upload === null) {
            ApiError::abort(422, 'file_empty');
        }
        $filename = basename($upload->getClientOriginalName() ?: 'file');
        $extension = self::extension($filename);
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            ApiError::abort(422, 'file_type_not_allowed');
        }
        $data = self::read($upload, config('stroy.max_file_bytes'));
        if ($data === '') {
            ApiError::abort(422, 'file_empty');
        }

        return [$filename, self::saveBytes($organizationId, $extension, $data), strlen($data)];
    }

    /** Читает загруженный файл. Больше предела: 413 file_too_large. */
    public static function read(?UploadedFile $upload, int $maxBytes, string $tooLarge = 'file_too_large'): string
    {
        if ($upload === null || ! $upload->isValid()) {
            if ($upload !== null && in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                ApiError::abort(413, $tooLarge);
            }

            return '';
        }
        if ($upload->getSize() > $maxBytes) {
            ApiError::abort(413, $tooLarge);
        }

        return (string) file_get_contents($upload->getRealPath());
    }

    public static function extension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext === '' ? '' : '.'.$ext;
    }

    /** Пишет байты в папку организации, возвращает относительный путь. */
    public static function saveBytes(int $organizationId, string $extension, string $data): string
    {
        $relative = $organizationId.'/'.Str::uuid()->getHex().$extension;
        Storage::disk('local')->put(self::root().'/'.$relative, $data);

        return $relative;
    }

    public static function fullPath(string $relative): string
    {
        return Storage::disk('local')->path(self::root().'/'.$relative);
    }

    public static function delete(string $relative): void
    {
        try {
            Storage::disk('local')->delete(self::root().'/'.$relative);
        } catch (\Throwable) {
            // запись удалена, а остаток файла уберёт чистка диска
        }
    }

    private static function root(): string
    {
        return trim(config('stroy.storage_dir'), '/');
    }
}
