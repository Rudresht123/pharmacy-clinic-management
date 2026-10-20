<?php

namespace App\Services\Exports\Datasets\Communication;

use App\Models\Tenant\Campaign;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Campaigns — what went out to whom, and how many it reached. */
class Campaigns extends Dataset
{
    public function key(): string
    {
        return 'communication.campaigns';
    }

    public function tab(): string
    {
        return 'communication';
    }

    public function label(): string
    {
        return 'Campaigns';
    }

    public function description(): string
    {
        return 'Each campaign sent — when, on which channel, and how many it reached, opened or failed.';
    }

    public function noun(): string
    {
        return 'campaigns';
    }

    public function icon(): string
    {
        return 'ti ti-speakerphone';
    }

    public function dateLabel(): ?string
    {
        return 'Created on';
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    public function statuses(): array
    {
        return collect(Campaign::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Campaign name';
    }

    public function columns(): array
    {
        return [
            new Column('created', 'Created', Column::DATE, 7),
            new Column('name', 'Campaign', width: 16),
            new Column('channel', 'Channel', width: 6.5),
            new Column('type', 'Type', width: 8),
            new Column('status', 'Status', width: 7),
            new Column('scheduled', 'Scheduled for', Column::DATE, 9.5),
            new Column('completed', 'Finished', Column::DATE, 9.5),
            new Column('recipients', 'Recipients', Column::NUMBER, 6.5),
            new Column('sent', 'Sent', Column::NUMBER, 5.5),
            new Column('delivered', 'Delivered', Column::NUMBER, 6),
            new Column('opened', 'Opened', Column::NUMBER, 5.5),
            new Column('failed', 'Failed', Column::NUMBER, 5.5),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Campaign::query();

        $this->between($query, 'created_at', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where('name', 'ILIKE', $like));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function sortable(): array
    {
        return ['created' => 'created_at', 'name' => 'name', 'status' => 'status', 'recipients' => 'recipients_count', 'sent' => 'sent_count'];
    }

    /** @param  Campaign  $record */
    public function link(Model $record): ?string
    {
        // Email and push campaigns have screens of their own; the others are read here.
        return in_array($record->channel, ['email', 'push'], true) ? "/communication/{$record->channel}/campaigns/{$record->id}" : null;
    }

    /** @param  Campaign  $record */
    public function row(Model $record): array
    {
        return [
            'created' => $this->date($record->created_at),
            'name' => $record->name,
            'channel' => Messages::CHANNELS[$record->channel] ?? $this->words($record->channel),
            'type' => $this->words($record->campaign_type),
            'status' => $this->words($record->status),
            'scheduled' => $this->dateTime($record->scheduled_at),
            'completed' => $this->dateTime($record->completed_at),
            'recipients' => (int) $record->recipients_count,
            'sent' => (int) $record->sent_count,
            'delivered' => (int) $record->delivered_count,
            'opened' => (int) $record->opened_count,
            'failed' => (int) $record->failed_count,
        ];
    }
}
