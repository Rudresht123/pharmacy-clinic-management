<?php

namespace App\Services\Exports\Datasets\Communication;

use App\Models\Tenant\MessageLog;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The message log — every WhatsApp, email, SMS and push the clinic sent, and
 * what became of it. The answer to "we never heard from you", as a file.
 *
 * Not narrowed by branch: a message is not sent from one, which is why the
 * whole Communication tab is organization-level (ExportCatalog::TABS).
 */
class Messages extends Dataset
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS', 'push' => 'Push'];

    public function key(): string
    {
        return 'communication.messages';
    }

    public function tab(): string
    {
        return 'communication';
    }

    public function label(): string
    {
        return 'Message log';
    }

    public function description(): string
    {
        return 'Every WhatsApp, email, SMS and app message sent — to whom, and whether it arrived.';
    }

    public function noun(): string
    {
        return 'messages';
    }

    public function icon(): string
    {
        return 'ti ti-message-2';
    }

    public function dateLabel(): ?string
    {
        return 'Sent on';
    }

    public function statuses(): array
    {
        return collect(MessageLog::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Recipient, number, email or template';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('channel', 'Channel', width: 6),
            new Column('name', 'Recipient', width: 11),
            new Column('recipient', 'Sent to', width: 12),
            new Column('template', 'Template', width: 11),
            new Column('subject', 'Subject', width: 12),
            new Column('status', 'Status', width: 6),
            new Column('sent_at', 'Sent', Column::DATE, 9),
            new Column('delivered_at', 'Delivered', Column::DATE, 9),
            new Column('read_at', 'Read', Column::DATE, 9),
            new Column('failure', 'Failure reason', width: 12),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = MessageLog::query()->where('is_test', false);

        $this->between($query, 'created_at', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
                ->where('recipient', 'ILIKE', $like)
                ->orWhere('recipient_name', 'ILIKE', $like)
                ->orWhere('template_name', 'ILIKE', $like)
                ->orWhere('subject', 'ILIKE', $like)));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function sortable(): array
    {
        return ['date' => 'created_at', 'channel' => 'channel', 'name' => 'recipient_name', 'status' => 'status'];
    }

    /** @param  MessageLog  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->dateTime($record->created_at),
            'channel' => self::CHANNELS[$record->channel] ?? $this->words($record->channel),
            'name' => $record->recipient_name,
            'recipient' => $record->recipient,
            'template' => $record->template_name,
            'subject' => $record->subject,
            'status' => $this->words($record->status),
            'sent_at' => $this->dateTime($record->sent_at),
            'delivered_at' => $this->dateTime($record->delivered_at),
            'read_at' => $this->dateTime($record->read_at),
            'failure' => $record->failure_reason,
        ];
    }
}
