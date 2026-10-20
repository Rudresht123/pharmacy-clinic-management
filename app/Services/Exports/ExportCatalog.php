<?php

namespace App\Services\Exports;

use App\Services\Exports\Datasets\Billing\Invoices;
use App\Services\Exports\Datasets\Billing\Outstanding;
use App\Services\Exports\Datasets\Billing\Payments;
use App\Services\Exports\Datasets\Billing\Refunds;
use App\Services\Exports\Datasets\Communication\Campaigns;
use App\Services\Exports\Datasets\Communication\Messages;
use App\Services\Exports\Datasets\Patients\LabOrders;
use App\Services\Exports\Datasets\Patients\PatientRegister;
use App\Services\Exports\Datasets\Patients\Prescriptions;
use App\Services\Exports\Datasets\Patients\Visits;
use App\Services\Exports\Datasets\Pharmacy\Expiry;
use App\Services\Exports\Datasets\Pharmacy\Movements;
use App\Services\Exports\Datasets\Pharmacy\Purchases;
use App\Services\Exports\Datasets\Pharmacy\Returns;
use App\Services\Exports\Datasets\Pharmacy\Sales;
use App\Services\Exports\Datasets\Pharmacy\Stock;

/**
 * Everything the Export Center hands out: four tabs, and the exports in each.
 *
 * A tab is a subject, and it is what a role is given — one capability per tab
 * (ModuleRegistry, `exports`). It also names the module its records belong
 * to, so a tab is not offered where that module was never sold. An export
 * inside a tab may need a further module of its own (Dataset::module()).
 */
final class ExportCatalog
{
    /**
     * @var array<string, array{label: string, icon: string, capability: string, module: string}>
     */
    public const TABS = [
        'patients' => ['label' => 'Patients', 'icon' => 'ti ti-users', 'capability' => 'exports.patients', 'module' => 'customers'],
        'pharmacy' => ['label' => 'Pharmacy', 'icon' => 'ti ti-building-warehouse', 'capability' => 'exports.pharmacy', 'module' => 'pharmacy'],
        'billing' => ['label' => 'Billing', 'icon' => 'ti ti-receipt', 'capability' => 'exports.billing', 'module' => 'billing'],
        'communication' => ['label' => 'Communication', 'icon' => 'ti ti-messages', 'capability' => 'exports.communication', 'module' => 'communication'],
    ];

    /** In the order each tab lists them. */
    private const DATASETS = [
        PatientRegister::class,
        Visits::class,
        Prescriptions::class,
        LabOrders::class,

        Sales::class,
        Returns::class,
        Stock::class,
        Expiry::class,
        Purchases::class,
        Movements::class,

        Invoices::class,
        Payments::class,
        Refunds::class,
        Outstanding::class,

        Messages::class,
        Campaigns::class,
    ];

    /** @var array<string, Dataset>|null */
    private ?array $datasets = null;

    /** @return array<string, Dataset> by key */
    public function all(): array
    {
        if ($this->datasets === null) {
            $this->datasets = [];

            foreach (self::DATASETS as $class) {
                $dataset = new $class;
                $this->datasets[$dataset->key()] = $dataset;
            }
        }

        return $this->datasets;
    }

    public function find(string $key): ?Dataset
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<Dataset> */
    public function forTab(string $tab): array
    {
        return array_values(array_filter($this->all(), fn (Dataset $dataset) => $dataset->tab() === $tab));
    }

    public static function capability(string $tab): ?string
    {
        return self::TABS[$tab]['capability'] ?? null;
    }
}
