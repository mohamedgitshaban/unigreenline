<?php

namespace Modules\Core\Printing;

/**
 * Everything a printed business document shows — invoice, delivery note, transfer slip — in one shape, so every document
 * prints with the same layout and a client can also ask for the same content as JSON to draw it its own way.
 */
final class PrintableDocument
{
    /**
     * @param  string  $type  What it is called on paper, e.g. `Sales invoice`.
     * @param  string  $number  Its number or reference.
     * @param  array<string, string|null>  $meta  Label => value, shown beside the title (date, due date, status ...).
     * @param  array<int, array{title: string, lines: array<int, string|null>}>  $parties  Who and where: bill to, from, to ...
     * @param  array<int, array{title: ?string, columns: array<int, array{key: string, label: string, align?: string}>, rows: array<int, array<string, mixed>>}>  $tables
     * @param  array<int, array{label: string, value: string, strong?: bool}>  $totals
     * @param  array<int, string>  $signatures  Names of the signature boxes at the foot (Prepared by, Received by ...).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $number,
        public readonly string $company,
        public readonly array $meta = [],
        public readonly array $parties = [],
        public readonly array $tables = [],
        public readonly array $totals = [],
        public readonly ?string $notes = null,
        public readonly array $signatures = [],
        public readonly ?string $status = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'number' => $this->number,
            'status' => $this->status,
            'company' => $this->company,
            'meta' => $this->meta,
            'parties' => $this->parties,
            'tables' => $this->tables,
            'totals' => $this->totals,
            'notes' => $this->notes,
            'signatures' => $this->signatures,
        ];
    }

    /**
     * Money the way it is written on paper.
     */
    public static function money(int|float|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }
}
