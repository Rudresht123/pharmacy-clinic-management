<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\CampaignResource;
use App\Models\Tenant\AudienceSegment;
use App\Models\Tenant\Campaign;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageTemplate;
use App\Services\Tenant\AudienceResolver;
use App\Services\Tenant\CampaignDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Bulk sending, now or later.
 *
 * Scheduling is just a status and a time: `schedule` sets both and the
 * dispatcher does the rest, so nothing here has to know how sending works.
 * That separation is what lets a campaign be scheduled, unscheduled and
 * rescheduled without anything being sent by accident in between.
 */
class CampaignController extends BaseApiController
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly CampaignDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()->with(['segment', 'template'])->latest('id');

        if (($channel = $request->string('channel')->toString()) !== '') {
            $query->forChannel($channel);
        }

        if (($status = $request->string('status')->toString()) !== '') {
            $query->where('status', $status);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)), CampaignResource::class);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return $this->ok(CampaignResource::make($campaign->load(['segment', 'template'])));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $campaign = Campaign::query()->create([
            ...$validated,
            'status' => Campaign::DRAFT,
            'created_by' => Auth::guard('web')->id(),
            'updated_by' => Auth::guard('web')->id(),
        ]);

        return $this->created(CampaignResource::make($campaign), 'Campaign created');
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        if (! $campaign->isEditable()) {
            return $this->respond(null, 'This campaign has already gone out and cannot be changed.', [], 422);
        }

        $campaign->fill($this->validated($request, $campaign));
        $campaign->updated_by = Auth::guard('web')->id();
        $campaign->save();

        return $this->ok(CampaignResource::make($campaign), 'Campaign saved');
    }

    /**
     * Set the time, and let it go by itself.
     *
     * The time must be in the future: a campaign scheduled for this morning
     * would be picked up on the dispatcher's very next run and go out
     * immediately, which is never what somebody choosing a past time meant.
     */
    public function schedule(Request $request, Campaign $campaign): JsonResponse
    {
        if (! $campaign->isEditable()) {
            return $this->respond(null, 'This campaign has already gone out.', [], 422);
        }

        $validated = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $audience = $this->audience->preview(
            $campaign->channel,
            $this->audience->filtersFor($campaign),
        );

        if ($audience['total'] === 0) {
            return $this->respond(null, 'Nobody matches this audience, so there is nothing to send.', [], 422);
        }

        $campaign->forceFill([
            'status' => Campaign::SCHEDULED,
            'scheduled_at' => Carbon::parse($validated['scheduled_at']),
            'recipients_count' => $audience['total'],
            'updated_by' => Auth::guard('web')->id(),
        ])->save();

        return $this->ok(
            CampaignResource::make($campaign),
            "Scheduled for {$campaign->scheduled_at->format('d M Y, g:i A')} — {$audience['total']} patient(s).",
        );
    }

    /** Back to a draft, before its time comes. */
    public function unschedule(Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== Campaign::SCHEDULED) {
            return $this->respond(null, 'This campaign is not scheduled.', [], 422);
        }

        $campaign->forceFill([
            'status' => Campaign::DRAFT,
            'scheduled_at' => null,
            'updated_by' => Auth::guard('web')->id(),
        ])->save();

        return $this->ok(CampaignResource::make($campaign), 'Schedule cancelled');
    }

    /** Send it now rather than waiting for a time. */
    public function send(Campaign $campaign): JsonResponse
    {
        if (! $campaign->isEditable()) {
            return $this->respond(null, 'This campaign has already gone out.', [], 422);
        }

        $reached = $this->dispatcher->dispatch($campaign);

        return $this->ok(
            CampaignResource::make($campaign->fresh()),
            "Sending to {$reached} patient(s).",
        );
    }

    /**
     * Remove a campaign, and its recipients with it.
     *
     * The database cascade never fires, because this is a soft delete and the
     * row stays — so the children are taken explicitly. The delivery log is
     * left alone on purpose: what actually reached a patient is evidence, and
     * removing the campaign that sent it does not unsend it.
     */
    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->status === Campaign::SENDING) {
            return $this->respond(null, 'This campaign is sending. Wait for it to finish.', [], 422);
        }

        $campaign->deleted_by = Auth::guard('web')->id();
        $campaign->deletion_reason = $request->string('reason')->toString() ?: null;
        $campaign->save();

        $campaign->recipients()->delete();
        $campaign->delete();

        return $this->ok(null, 'Campaign removed');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Campaign $campaign = null): array
    {
        return $request->validate([
            'channel' => [$campaign ? 'sometimes' : 'required', Rule::in(CommunicationChannel::CHANNELS)],
            'name' => ['required', 'string', 'max:200'],
            'campaign_type' => ['nullable', 'string', 'max:40'],
            'goal' => ['nullable', 'string', 'max:60'],

            'subject' => ['nullable', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],

            'message_template_id' => [
                'nullable',
                Rule::exists(MessageTemplate::class, 'id')->whereNull('deleted_at'),
            ],

            'from_name' => ['nullable', 'string', 'max:150'],
            'from_email' => ['nullable', 'email', 'max:150'],
            'reply_to' => ['nullable', 'email', 'max:150'],

            'audience_segment_id' => [
                'nullable',
                Rule::exists(AudienceSegment::class, 'id')->whereNull('deleted_at'),
            ],
            'audience_filters' => ['nullable', 'array'],
            'audience_filters.*.field' => ['required_with:audience_filters', 'string', Rule::in(array_keys(AudienceResolver::FIELDS))],
            'audience_filters.*.operator' => ['required_with:audience_filters', 'string', 'max:30'],
        ]);
    }

    /** The segments a campaign may choose from, with live counts. */
    public function segments(Request $request): JsonResponse
    {
        $channel = $request->string('channel', CommunicationChannel::EMAIL)->toString();

        $segments = AudienceSegment::query()->orderBy('sort_order')->orderBy('id')->get();

        return $this->ok(
            $segments->map(fn (AudienceSegment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'description' => $segment->description,
                'icon' => $segment->icon,
                'tone' => $segment->tone,
                'is_system' => $segment->is_system,
                'filters' => $segment->filters ?? [],
                'total' => $this->audience->preview($channel, $segment->filters ?? [])['total'],
            ])
        );
    }

    /** How many a set of filters would reach, before committing to it. */
    public function audiencePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(CommunicationChannel::CHANNELS)],
            'filters' => ['nullable', 'array'],
        ]);

        return $this->ok(
            $this->audience->preview($validated['channel'], $validated['filters'] ?? [])
        );
    }
}
