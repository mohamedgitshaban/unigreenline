<?php

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\DocumentNumberService;

/**
 * Gives a document a readable, gap-free number (SO-000123, INV-000123 …) when it is first saved.
 * The using model says which counter it belongs to through documentSequence().
 */
trait HasDocumentNumber
{
    /**
     * @return array{0: string, 1: string} The sequence key and the number prefix, e.g. ['invoice', 'INV-'].
     */
    abstract public static function documentSequence(): array;

    protected static function bootHasDocumentNumber(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('number') !== null || $model->getAttribute('tenant_id') === null) {
                return;
            }

            [$key, $prefix] = static::documentSequence();

            $model->setAttribute('number', app(DocumentNumberService::class)->next($model->getAttribute('tenant_id'), $key, $prefix));
        });
    }
}
