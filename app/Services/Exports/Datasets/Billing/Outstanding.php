<?php

namespace App\Services\Exports\Datasets\Billing;

use App\Models\Tenant\Invoice;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Bills still owed — the follow-up list, oldest first, with how long each has waited. */
class Outstanding extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'billing.outstanding';
    }

    public function tab(): string
    {
        return 'billing';
    }

    public function label(): string
    {
        return 'Outstanding';
    }

    public function description(): string
    {
        return 'Unpaid and part-paid bills — who owes what, and for how many days.';
    }

    public function noun(): string
    {
        return 'unpaid bills';
    }

    public function icon(): string
    {
        return 'ti ti-clock-dollar';
    }

    public function dateLabel(): ?string
    {
        return 'Invoice date';
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    public function statuses(): array
    {
        return [Invoice::STATUS_PENDING => 'Unpaid', Invoice::STATUS_PARTIALLY_PAID => 'Part paid'];
    }

    public function searchHint(): ?string
    {
        return 'Invoice no., patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Invoice date', Column::DATE, 7.5),
            new Column('number', 'Invoice no.', width: 10),
            new Column('patient', 'Patient', width: 12),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('kind', 'Department', width: 7.5),
            new Column('branch', 'Branch', width: 10),
            new Column('total', 'Total', Column::MONEY, 7),
            new Column('paid', 'Paid', Column::MONEY, 7),
            new Column('balance', 'Balance', Column::MONEY, 7.5),
            new Column('days', 'Days owed', Column::NUMBER, 6),
            new Column('status', 'Status', width: 6.5),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Invoice::query()
            ->with(['customer:'.$this->patientFields(), 'location:id,name'])
            ->whereIn('status', Invoice::OWES_MONEY)
            ->whereRaw(Invoice::OWED_SQL.' > 0');

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'invoice_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $this->matchingPatient(
                $inner->where('invoice_number', 'ILIKE', $like)->orWhere('walk_in_name', 'ILIKE', $like)->orWhere('walk_in_phone', 'ILIKE', $like),
                'customer',
                $like,
            )));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('invoice_date')->orderBy('id');
    }

    public function photoPath(): ?string
    {
        return 'customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'invoice_date', 'number' => 'invoice_number', 'total' => 'total_amount', 'paid' => 'paid_amount', 'status' => 'status'];
    }

    /** @param  Invoice  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->customer)]);
    }

    /** @param  Invoice  $record */
    public function link(Model $record): ?string
    {
        return '/billing/invoices/'.$record->id;
    }

    /** @param  Invoice  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->date($record->invoice_date),
            'number' => $record->invoice_number,
            ...$this->patientCells($record->customer, $record->walk_in_name, $record->walk_in_phone),
            'kind' => Invoices::KIND_LABELS[$record->kind] ?? $this->words($record->kind),
            'branch' => $record->location?->name,
            'total' => $this->money($record->total_amount),
            'paid' => $this->money($record->paid_amount),
            'balance' => $this->money($record->outstanding()),
            'days' => $record->invoice_date ? (int) $record->invoice_date->copy()->startOfDay()->diffInDays(Carbon::today()) : null,
            'status' => $record->status === Invoice::STATUS_PARTIALLY_PAID ? 'Part paid' : 'Unpaid',
        ];
    }
}
