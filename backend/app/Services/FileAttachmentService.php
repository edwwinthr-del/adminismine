<?php

namespace App\Services;

use App\Models\FileAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores record paperwork on the private `local` disk. Nothing here builds a
 * public URL: files are only ever served through an authenticated route.
 */
class FileAttachmentService
{
    public const DISK = 'local';

    /** @param  array{kind?: string|null, label?: string|null, notes?: string|null}  $meta */
    public function store(Model $attachable, UploadedFile $file, array $meta = []): FileAttachment
    {
        $path = $file->store($this->directoryFor($attachable), self::DISK);

        return $attachable->attachments()->create([
            'kind' => $meta['kind'] ?? 'other',
            'label' => $meta['label'] ?? null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'notes' => $meta['notes'] ?? null,
        ]);
    }

    public function download(FileAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($attachment->file_path), 404);

        return Storage::disk(self::DISK)->download($attachment->file_path, $attachment->original_name);
    }

    public function delete(FileAttachment $attachment): void
    {
        Storage::disk(self::DISK)->delete($attachment->file_path);
        $attachment->delete();
    }

    /** Remove every file of a record — call before deleting the record itself. */
    public function deleteAllFor(Model $attachable): void
    {
        $paths = $attachable->attachments()->pluck('file_path')->all();

        $attachable->attachments()->delete();

        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    /** e.g. attachments/machines/12 */
    private function directoryFor(Model $attachable): string
    {
        $segment = FileAttachment::ROUTE_SEGMENTS[$attachable::class]
            ?? Str::kebab(Str::plural(class_basename($attachable)));

        return "attachments/{$segment}/{$attachable->getKey()}";
    }
}
