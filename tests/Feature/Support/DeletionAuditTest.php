<?php

namespace Tests\Feature\Support;

use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\User;
use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\Deletion\HistoricalRecordException;
use App\Support\History\RecordsHistory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TenantTestCase;

/**
 * Deletion with a reason, restore with a reason, and no permanent deletion.
 *
 * No pharmacy model exists yet, so the traits are exercised on a throwaway
 * table in a real tenant database — the same connection, history log and
 * triggers the Medicine model will meet in Phase 1.
 */
class DeletionAuditTest extends TenantTestCase
{
    use RefreshDatabase;

    /** Runs the callback inside a tenant that has the throwaway table. */
    private function withSubjects(callable $callback): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () use ($callback) {
            Schema::connection('organization')->create('deletion_audit_subjects', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('deleted_by')->nullable();
                $table->string('deletion_reason', 500)->nullable();
            });

            // The person acting, as the tenant.actor middleware would set them.
            Auth::guard('web')->setUser(User::on('organization')->where('role', User::OWNER)->firstOrFail());

            $callback();
        });
    }

    private function subject(): DeletionAuditSubject
    {
        return DeletionAuditSubject::create(['name' => 'Paracetamol 500']);
    }

    /** @return list<ActivityLog> */
    private function history(DeletionAuditSubject $subject, string $event): array
    {
        return ActivityLog::on('organization')
            ->where('entity_type', 'DeletionAuditSubject')
            ->where('entity_id', $subject->id)
            ->where('event', $event)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_deleting_records_who_and_why_on_the_row_and_in_the_history(): void
    {
        $this->withSubjects(function () {
            $subject = $this->subject();

            $this->assertTrue($subject->deleteWithReason('  Duplicate of another entry  '));

            $row = DeletionAuditSubject::withTrashed()->findOrFail($subject->id);

            $this->assertNotNull($row->deleted_at);
            $this->assertSame(Auth::guard('web')->id(), (int) $row->deleted_by);
            $this->assertSame('Duplicate of another entry', $row->deletion_reason);

            // Out of every ordinary query.
            $this->assertNull(DeletionAuditSubject::find($subject->id));

            $deleted = $this->history($subject, 'deleted');
            $this->assertCount(1, $deleted);
            $this->assertSame(['reason' => 'Duplicate of another entry'], $deleted[0]->after);
            $this->assertNull($deleted[0]->before);

            // Stamping the reason is part of the delete, not an edit of its own.
            $this->assertSame([], $this->history($subject, 'updated'));
        });
    }

    public function test_restoring_records_its_own_reason_and_clears_the_row(): void
    {
        $this->withSubjects(function () {
            $subject = $this->subject();
            $subject->deleteWithReason('Entered by mistake');

            $trashed = DeletionAuditSubject::withTrashed()->findOrFail($subject->id);
            $this->assertTrue($trashed->restoreWithReason('It was not a duplicate'));

            $row = DeletionAuditSubject::findOrFail($subject->id);
            $this->assertNull($row->deleted_at);
            $this->assertNull($row->deleted_by);
            $this->assertNull($row->deletion_reason);

            $restored = $this->history($subject, 'restored');
            $this->assertCount(1, $restored);
            $this->assertSame(['reason' => 'It was not a duplicate'], $restored[0]->after);

            // The deletion's reason is still in the log after the row forgot it.
            $this->assertSame(['reason' => 'Entered by mistake'], $this->history($subject, 'deleted')[0]->after);

            // Clearing the deletion columns is not reported as an edit.
            $this->assertSame([], $this->history($subject, 'updated'));
        });
    }

    public function test_a_reason_is_required(): void
    {
        $this->withSubjects(function () {
            $subject = $this->subject();

            try {
                $subject->deleteWithReason('   ');
                $this->fail('A blank reason was accepted.');
            } catch (InvalidArgumentException) {
                // expected
            }

            // Nothing happened: not deleted, not stamped, not logged.
            $row = DeletionAuditSubject::findOrFail($subject->id);
            $this->assertNull($row->deletion_reason);
            $this->assertSame([], $this->history($subject, 'deleted'));

            $subject->deleteWithReason('Discontinued');

            $this->expectException(InvalidArgumentException::class);

            DeletionAuditSubject::withTrashed()->findOrFail($subject->id)->restoreWithReason('');
        });
    }

    /** A reason given for one delete does not leak onto a later one. */
    public function test_a_reason_is_used_once(): void
    {
        $this->withSubjects(function () {
            $subject = $this->subject();

            $subject->deleteWithReason('First reason');
            $subject->restoreWithReason('Second reason');

            // An ordinary delete, with no reason, says nothing.
            $subject->delete();

            $deleted = $this->history($subject, 'deleted');
            $this->assertCount(2, $deleted);
            $this->assertNull($deleted[1]->after);
        });
    }

    public function test_permanent_deletion_is_refused_with_a_409(): void
    {
        $this->withSubjects(function () {
            $subject = $this->subject();
            $subject->deleteWithReason('Discontinued');

            $trashed = DeletionAuditSubject::withTrashed()->findOrFail($subject->id);

            try {
                $trashed->forceDelete();
                $this->fail('forceDelete() went through.');
            } catch (HistoricalRecordException $e) {
                $this->assertInstanceOf(HttpExceptionInterface::class, $e);
                $this->assertSame(409, $e->getStatusCode());
            }

            // Still there, still removed.
            $this->assertTrue(DeletionAuditSubject::withTrashed()->whereKey($subject->id)->exists());
        });
    }

    /** The refusal reaches an API caller as a 409 with the usual envelope. */
    public function test_the_refusal_renders_as_json_409(): void
    {
        $response = $this->app->make(ExceptionHandler::class)->render(
            Request::create('/api/v1/tenant/anything', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']),
            new HistoricalRecordException,
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('cannot be permanently deleted', (string) $response->getContent());
    }
}

/** A stand-in for the pharmacy models the traits are written for. */
class DeletionAuditSubject extends Model
{
    use ForbidsForceDelete;
    use HasDeletionAudit;
    use RecordsHistory;
    use SoftDeletes;

    protected $connection = 'organization';

    protected $table = 'deletion_audit_subjects';

    protected $fillable = ['name'];
}
