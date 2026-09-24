<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\Providers\DigiwarePayloadMapper;
use App\Services\WhatsApp\Providers\DigiwareWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Digiware adapter, against a faked API.
 *
 * What is asserted: named variables land in the positional fields the
 * template declares rather than the order the caller wrote them; a refusal
 * and a fault are told apart, because only one is worth retrying; a body that
 * is not JSON is an answer and not an exception; and the token never appears
 * in anything the adapter hands back.
 *
 * No test here reaches the real API. A suite that does is a suite that fails
 * when somebody else's server is having an afternoon.
 */
class DigiwareWhatsAppProviderTest extends TestCase
{
    private const CREDENTIALS = [
        'base_url' => 'https://lms.digiware.test/api',
        'vendor_uid' => 'vendor-123',
        'token' => 'secret-token-value',
    ];

    private function provider(): DigiwareWhatsAppProvider
    {
        return new DigiwareWhatsAppProvider(self::CREDENTIALS, new DigiwarePayloadMapper);
    }

    private function message(array $variables = []): WhatsAppMessage
    {
        return WhatsAppMessage::template(
            phone: '+91 98765 43210',
            template: 'appointment_confirmation',
            variables: $variables,
        )->resolvedFor('appointment_confirmation', array_keys($variables));
    }

    public function test_a_template_send_reports_the_provider_message_id(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'success', 'message_id' => 'wamid.ABC'], 200),
        ]);

        $response = $this->provider()->sendTemplate($this->message(['patient_name' => 'Amit']));

        $this->assertTrue($response->success);
        $this->assertSame('sent', $response->status);
        $this->assertSame('wamid.ABC', $response->providerMessageId);
        $this->assertSame('digiware', $response->provider);
    }

    /**
     * The shape Digiware actually returns, captured from a real send.
     *
     * The verdict is `result`, not `status`, and the id is `data.wamid`. Both
     * were guessed before a real call was made and both were guessed wrong, so
     * the recorded body is pinned here rather than described.
     */
    public function test_the_real_response_shape_is_read_correctly(): void
    {
        Http::fake(['*' => Http::response([
            'result' => 'success',
            'message' => 'Message processed',
            'data' => [
                'log_uid' => '62d154e9-7ede-499c-9509-db974d2e0b3c',
                'contact_uid' => 'e3cfd6a1-1a69-47c7-96bb-8bcb1c409d13',
                'phone_number' => '919628908321',
                'wamid' => 'wamid.HBgMOTE5NjI4OTA4MzIxFQIAERgSREMzRDUzQTE5RTc4OTUwMzVGAA==',
                'status' => 'accepted',
            ],
        ], 200)]);

        $response = $this->provider()->sendTemplate($this->message(['patient_name' => 'Amit']));

        $this->assertTrue($response->success);
        $this->assertSame(
            'wamid.HBgMOTE5NjI4OTA4MzIxFQIAERgSREMzRDUzQTE5RTc4OTUwMzVGAA==',
            $response->providerMessageId,
            'Without the wamid a delivery receipt can never be matched to its message.',
        );
    }

    /**
     * The other half of the same discovery.
     *
     * Reading only `status` meant a refusal carrying `result: error` was read
     * as a send — the log would have said delivered while the patient got
     * nothing, which is the one failure a delivery log exists to prevent.
     */
    public function test_a_refusal_under_result_is_not_read_as_a_send(): void
    {
        Http::fake(['*' => Http::response([
            'result' => 'error',
            'message' => 'Template not approved',
        ], 200)]);

        $response = $this->provider()->sendTemplate($this->message());

        $this->assertFalse($response->success);
        $this->assertSame('Template not approved', $response->message);
    }

    /**
     * The point of naming variables: the ORDER comes from the template, not
     * from however the caller happened to write the array.
     */
    public function test_named_variables_follow_the_templates_order_not_the_callers(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $message = WhatsAppMessage::template(
            phone: '919876543210',
            template: 'appointment_confirmation',
            // Deliberately the wrong way round.
            variables: ['doctor_name' => 'Dr Rahul', 'patient_name' => 'Amit'],
        )->resolvedFor('appointment_confirmation', ['patient_name', 'doctor_name']);

        $this->provider()->sendTemplate($message);

        Http::assertSent(function ($request) {
            return $request['field_1'] === 'Amit'
                && $request['field_2'] === 'Dr Rahul';
        });
    }

    public function test_the_phone_number_loses_its_plus_spaces_and_leading_zero(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->provider()->sendTemplate($this->message(['patient_name' => 'Amit']));

        Http::assertSent(fn ($request) => $request['phone_number'] === '919876543210');
    }

    public function test_a_refusal_is_final_and_not_retried(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'error', 'message' => 'Template not found'], 422),
        ]);

        $response = $this->provider()->sendTemplate($this->message());

        $this->assertFalse($response->success);
        $this->assertSame('failed', $response->status);
        $this->assertFalse($response->retryable, 'A bad template will be refused again.');
        $this->assertSame('Template not found', $response->message);
    }

    public function test_a_server_fault_is_transient_and_worth_retrying(): void
    {
        Http::fake(['*' => Http::response('gateway down', 503)]);

        $response = $this->provider()->sendTemplate($this->message());

        $this->assertFalse($response->success);
        $this->assertTrue($response->retryable);
    }

    public function test_rate_limiting_is_transient(): void
    {
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

        $this->assertTrue($this->provider()->sendTemplate($this->message())->retryable);
    }

    public function test_a_timeout_is_an_answer_rather_than_an_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $response = $this->provider()->sendTemplate($this->message());

        $this->assertFalse($response->success);
        $this->assertTrue($response->retryable);
        $this->assertSame('connection', $response->errorCode);
    }

    /** An HTML error page from a proxy must not throw while being parsed. */
    public function test_a_malformed_body_fails_cleanly(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 200)]);

        $response = $this->provider()->sendTemplate($this->message());

        $this->assertIsBool($response->success);
        $this->assertNotEmpty($response->message);
    }

    public function test_missing_credentials_are_refused_before_any_request(): void
    {
        Http::fake();

        $provider = new DigiwareWhatsAppProvider(['base_url' => 'https://x.test'], new DigiwarePayloadMapper);
        $response = $provider->sendTemplate($this->message());

        $this->assertFalse($response->success);
        $this->assertSame('config', $response->errorCode);
        Http::assertNothingSent();
    }

    /**
     * A REJECTED TOKEN COMES BACK AS HTTP 200, recorded from the live API.
     *
     * Anything that trusted the status code would report a dead account as
     * healthy — a clinic whose messages have silently stopped, being shown a
     * green tick.
     */
    public function test_a_rejected_token_is_caught_although_the_status_is_200(): void
    {
        Http::fake(['*' => Http::response([
            'result' => 'failed',
            'message' => 'Invalid Token',
            'data' => [],
        ], 200)]);

        $response = $this->provider()->testConnection();

        $this->assertFalse($response->success);
        $this->assertSame('Invalid Token', $response->message);
        $this->assertSame('credentials', $response->errorCode);
    }

    /**
     * Working credentials answer 422, because the check posts nothing.
     *
     * Being told the phone number is required IS the pass: the request got
     * past authentication and failed validation instead.
     */
    public function test_working_credentials_are_recognised_from_the_validation_error(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'The phone number field is required. (and 1 more error)',
            'errors' => ['phone_number' => ['The phone number field is required.']],
        ], 422)]);

        $this->assertTrue($this->provider()->testConnection()->success);
    }

    /**
     * This API has no read endpoint, so the check is an empty send. The
     * property that keeps it safe is not the path — it is that there is
     * nobody to deliver to.
     */
    public function test_the_connection_check_carries_no_recipient(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['phone_number' => ['required']]], 422)]);

        $this->provider()->testConnection();

        Http::assertSent(fn ($request) => empty($request['phone_number'])
            && empty($request['message_body'])
            && empty($request['template_name']));
    }

    /** Templates and free text are different endpoints with different fields. */
    public function test_a_template_and_free_text_go_to_their_own_endpoints(): void
    {
        Http::fake(['*' => Http::response(['result' => 'success'], 200)]);

        $this->provider()->sendTemplate($this->message(['patient_name' => 'Amit']));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'send-template-message?token='.self::CREDENTIALS['token'])
            && $request['template_name'] === 'appointment_confirmation');

        $this->provider()->sendText(WhatsAppMessage::text('919876543210', 'Hello there'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/contact/send-message?')
            && $request['message_body'] === 'Hello there');
    }

    /** The one thing that must never leak, checked directly. */
    public function test_the_token_never_appears_in_anything_returned(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'no'], 400)]);

        $response = $this->provider()->sendTemplate($this->message(['patient_name' => 'Amit']));

        $encoded = json_encode($response->toArray()).json_encode($response->raw);

        $this->assertStringNotContainsString(self::CREDENTIALS['token'], $encoded);
    }
}
