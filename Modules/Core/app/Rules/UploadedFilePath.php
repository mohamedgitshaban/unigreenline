<?php

namespace Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Accepts only a path returned by one of the upload endpoints (e.g.
 * POST /suppliers/contracts) for this tenant: inside the given directory,
 * no traversal, an allowed extension, the file still exists, and no other
 * record already points at it.
 */
class UploadedFilePath implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  Model whose `$column` stores the path.
     * @param  list<string>  $extensions
     * @param  ?string  $disk  Disk the upload endpoint stored the file on; null = the default disk.
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $model,
        private readonly string $column,
        private readonly string $uploadEndpoint,
        private readonly array $extensions = ['jpg', 'jpeg', 'png', 'pdf'],
        private readonly ?string $ignoreId = null,
        private readonly ?string $disk = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '#^'.preg_quote($this->directory, '#').'/[A-Za-z0-9]+\.('.implode('|', $this->extensions).')$#';

        if (! is_string($value) || ! preg_match($pattern, $value) || ! Storage::disk($this->disk)->exists($value)) {
            $fail("The :attribute must be a file uploaded via {$this->uploadEndpoint}.");

            return;
        }

        $alreadyAttached = $this->model::query()
            ->where($this->column, $value)
            ->when($this->ignoreId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();

        if ($alreadyAttached) {
            $fail('The :attribute is already attached to another record.');
        }
    }
}
