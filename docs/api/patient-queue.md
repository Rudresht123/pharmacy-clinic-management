# Patient queue API (Flutter)

Where a patient stands in their doctor's queue today, for the patient app.

These endpoints need the clinic to have the **Smart Queue & TV Displays** module. Without it they answer `403`, so treat that as "this clinic has no live queue" and hide the screen.

## Authentication

The same as every portal endpoint:

```
X-Organization: {clinic subdomain}
Authorization: Bearer {patient token}
Accept: application/json
```

## Today's visits

```
GET /api/v1/tenant/portal/queue
```

Every visit today for the signed-in patient and their family members. Cancelled and no-show visits are left out.

```json
{
  "success": true,
  "data": [
    {
      "appointment_id": 812,
      "patient": { "name": "Rahul Tiwari", "is_self": true, "relation": null },
      "doctor": { "id": 4, "name": "Dr. Sharma" },
      "branch": { "id": 1, "name": "City Care Main" },
      "department": "Cardiology",
      "room": "01",
      "token": "A013",
      "now_serving": "A011",
      "queue_status": "waiting",
      "patients_ahead": 2,
      "estimated_wait_minutes": 20,
      "estimate_is_approximate": true,
      "queue_paused": false,
      "slot_at": "10:30",
      "called_at": null
    }
  ]
}
```

## One visit

```
GET /api/v1/tenant/portal/queue/{appointment_id}
```

The same object for one visit. A visit that is not this patient's or their family's answers `404`, the same as one that does not exist.

## Fields

| Field | Meaning |
|---|---|
| `token` | The token as it is said out loud and shown on the TV. `null` until they check in. |
| `now_serving` | The token with the doctor right now, or `null`. |
| `queue_status` | See below. |
| `patients_ahead` | How many are in front of them. `null` unless they are `waiting`. |
| `estimated_wait_minutes` | An estimate. `null` unless they are `waiting`. |
| `estimate_is_approximate` | Always `true`. Show "about 20 min". Never turn it into a clock time. |
| `queue_paused` | The doctor's queue is paused. Say so beside the estimate. |
| `room` | The doctor's room at this branch, or `null` if none is set. |
| `patient.is_self` | `false` for a family member's visit. `relation` then says who they are. |

| `queue_status` | Show the patient |
|---|---|
| `expected` | Booked, not checked in yet. |
| `waiting` | In the queue. Show the token, the place and the estimate. |
| `called` | Their turn. Tell them to go to the room now. |
| `skipped` | They were called and did not come. Tell them to speak to reception. |
| `with_doctor` | In consultation. |
| `done` | Finished with the doctor. |

The list and the estimates change all the time. While the screen is open, poll every 30 seconds.

## When the token is called

Two things happen, and neither needs the app to be polling.

**The in-app bell.** A notification of type `token_called` is written for the patient and appears in the existing notifications list.

**A push to the phone.** This goes out only if the clinic has turned on the rule **Tell the patient their token is called** under App notifications. It arrives off, like every other patient push rule, so the clinic decides. The push's `data.type` is `queue.token_called`, with the appointment id in its references. Open the queue screen when it is tapped.

## Other changes

`GET /portal/appointments` now also returns `token_label`, the token as shown (`"A013"`). Use it in place of `token_no`. At a clinic with the module, `token_no` alone is not enough, because every doctor has their own run of numbers.
