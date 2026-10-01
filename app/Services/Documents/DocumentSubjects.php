<?php

namespace App\Services\Documents;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Services\Clinic\ClinicEvent;
use App\Support\Documents\DocumentTypes;
use Illuminate\Database\Eloquent\Model;

/**
 * The record a document is made from.
 *
 * ONE PLACE for both ways a document is asked for — somebody pressing Print
 * with an id, and a rule reacting to an event — so the two cannot drift into
 * reading different rows, or the same row loaded two different ways.
 *
 * A closed match on the type's declared subject, never a class name from a
 * request: that is how an endpoint like this becomes a way to read any table
 * in the database.
 */
final class DocumentSubjects
{
    public static function find(string $subjectType, int $id): ?Model
    {
        return match ($subjectType) {
            DocumentTypes::SUBJECT_CUSTOMER => Customer::find($id),
            DocumentTypes::SUBJECT_APPOINTMENT => Appointment::with(['customer', 'doctor', 'consultation'])->find($id),
            DocumentTypes::SUBJECT_PRESCRIPTION => Prescription::with(['customer', 'doctor', 'items'])->find($id),
            DocumentTypes::SUBJECT_SALE => PharmacySale::with(['customer', 'items', 'payments'])->find($id),
            DocumentTypes::SUBJECT_INVOICE => Invoice::with(['customer', 'items', 'payments', 'doctor'])->find($id),
            /*
             * Money taken, not money given back: a refund row is a different
             * piece of paper, and printing it under a receipt's title would
             * hand somebody proof of a payment that went the other way.
             */
            DocumentTypes::SUBJECT_PAYMENT => InvoicePayment::with(['invoice.customer', 'invoice.items', 'invoice.payments', 'invoice.doctor'])
                ->where('is_refund', false)
                ->find($id),
            // And the other way round: only a refund row prints as a refund.
            DocumentTypes::SUBJECT_REFUND => InvoicePayment::with(['invoice.customer', 'invoice.items', 'invoice.payments', 'invoice.doctor', 'refunded'])
                ->where('is_refund', true)
                ->find($id),
            default => null,
        };
    }

    /**
     * The record of this kind an event is about, or null when it carries none.
     *
     * A payment event is raised about the INVOICE and names the payment in
     * its meta — two instalments are two events about one bill — so a receipt
     * is read from the meta and a copy of the bill from the subject.
     */
    public static function fromEvent(ClinicEvent $event, string $subjectType): ?Model
    {
        $id = match ($subjectType) {
            DocumentTypes::SUBJECT_PAYMENT => $event->meta['payment_id'] ?? null,
            DocumentTypes::SUBJECT_REFUND => $event->meta['refund_id'] ?? null,
            default => self::carries($event, $subjectType) ? $event->subjectId : null,
        };

        return $id === null ? null : self::find($subjectType, (int) $id);
    }

    private static function carries(ClinicEvent $event, string $subjectType): bool
    {
        $class = match ($subjectType) {
            DocumentTypes::SUBJECT_CUSTOMER => Customer::class,
            DocumentTypes::SUBJECT_APPOINTMENT => Appointment::class,
            DocumentTypes::SUBJECT_PRESCRIPTION => Prescription::class,
            DocumentTypes::SUBJECT_SALE => PharmacySale::class,
            DocumentTypes::SUBJECT_INVOICE => Invoice::class,
            default => null,
        };

        return $class !== null && $event->subjectType === (new $class)->getMorphClass();
    }
}
