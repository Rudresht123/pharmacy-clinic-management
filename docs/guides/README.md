# Guides

How HMS works, written for the people who run and support a clinic on it, not for its developers.
Each guide has an editable `.html` source and the PDF built from it.

**In the app:** sidebar › **Help › Guide** (`/guide`) lists every guide in this folder for any member of staff
signed in; each one reads inside the app and has a **Download PDF** button. The folder is the list: a guide
added here appears there with no code change. Its `<title>` is the card's title and its
`<meta name="description">` the summary. `GuideTest` fails if a guide here has no built PDF or no description.

| Guide | What it covers |
|---|---|
| [Receipts, Refund Slips aur Automatic Documents](receipts-and-automatic-documents.pdf) ([source](receipts-and-automatic-documents.html)) | Branch-wise invoice/receipt/refund numbers, what a receipt and a refund slip print, every print button and where it appears, taking a payment and a refund, Settings › Automatic documents, the one-copy rules, permissions, troubleshooting, and a file map for developers. |
| [Billing and Payments: User Guide](billing-user-guide.pdf) ([source](billing-user-guide.html)) | The staff manual in simple English, with eight flow charts: the three bills of a visit; who can do what and how to change a role; first-time setup; the OPD visit from check-in to payment; taking a payment (split tenders, the pay-in-full rule); refunds, cancelling and correcting a bill; lab bills; the pharmacy counter (sales, collecting a balance, returns); reading the Finance screen; troubleshooting; and where to find everything. |
| [Subscription aur Renewal](subscription-and-renewal.pdf) ([source](subscription-and-renewal.html)) | For the clinic owner: where the plan is shown, how days left are counted (calendar days, clinic timezone), expiry → grace → lock and what stays open while locked, renewing and upgrading through Razorpay or bank transfer, branch/doctor/employee limits and how each is counted, the monthly WhatsApp/email/video allowance and overage, add-ons, invoices, who sees what, and troubleshooting. |
| [Billing: Har Department ka Apna Bill](billing-by-department.pdf) ([source](billing-by-department.html)) | OPD, Lab and Pharmacy each billing for themselves; the four parts of Settings › Billing; OPD and lab timings; the pharmacy counter (credit, collecting later, returns); payment rules (full, part, credit, split tenders); refunds crediting the bill; when a bill may be corrected; the Finance screen's five figures; permissions; and a file map for developers. |

## Adding or changing a guide

1. Write or edit the `.html` file in this folder. Use `DejaVu Sans` (the rupee sign needs it) and no emoji; the PDF font has none.
2. Build the PDF:

   ```
   php docs/guides/build.php            # every guide here
   php docs/guides/build.php receipts   # only files whose name contains "receipts"
   ```

3. Add a line for it to the table above.

Architecture notes for developers live in [`docs/architecture`](../architecture), and API notes in [`docs/api`](../api).
