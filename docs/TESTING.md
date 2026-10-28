# Testing and Demonstration (Task 4)

All data used is fictional. Screenshots are in `docs/screenshots/`. Tests were run against MariaDB 10.11 and PHP 8.3.

| # | Test | Input | Expected result | Result | Evidence |
|---|---|---|---|---|---|
| 1 | Register a citizen | Valid personal, address and application data | Record saved; success message; applicant page shows pending application | Pass | `02`, `03`, `04` |
| 2 | Register a foreign resident | Passport, permit and expiry in the future | Saved with `foreign_resident` row | Pass | functional test 7 |
| 3 | List applicants | Open Applicants | Table with name, NID, district and latest application | Pass | `07` |
| 4 | Search by name | `Banda` | Only matching applicant(s) | Pass | `08` |
| 5 | Search by receipt number | `RCP-2026-0710` | The applicant with that receipt | Pass | `09` |
| 6 | Filter by status | Pending | Only applicants whose latest application is pending | Pass | `10` |
| 7 | Retrieve related records | Open an applicant | Addresses, applications, documents, witnesses, cards, family, biometrics shown (JOINs) | Pass | `11` |
| 8 | Update registration information | Approve a pending application (officer and date) | Status Approved, officer and date stored | Pass | `12`, `13` |
| 9 | Record ID card issuance | Issue card for approved application | Card saved, NID number assigned to the person | Pass | `14`, `15`, `16` |
| 10 | Update card status / collection | Set collection date | Card updated | Pass | functional test 18 |
| 11 | Invalid input | Empty names, bad phone, future date, no gender | Errors listed, nothing saved | Pass | `05` |
| 12 | Duplicate receipt number | Existing receipt `RCP-2022-0001` | Rejected with message | Pass | `05b` |
| 13 | Duplicate person | Same name and date of birth as an existing person | Rejected as possible duplicate | Pass | `06` |
| 14 | Duplicate permit number | Existing permit number | Rejected ("permit number is already registered") | Pass | functional test 8 |
| 15 | Officer from another centre | Officer not working at the chosen centre | Rejected | Pass | functional test 6 |
| 16 | Approve without officer/date | Status Approved, empty officer | Rejected by the form and by CHECK `chk_app_approved` | Pass | functional test 12 |
| 17 | Second card for same application | Issue twice | Rejected (UNIQUE `application_id`) | Pass | functional test 16 |
| 18 | Foreign resident card beyond permit | Expiry after permit expiry | Rejected | Pass | functional test 17 |
| 19 | Collection before issue date | Earlier date | Rejected | Pass | functional test 19 |
| 20 | Delete person with an ID card | Delete applicant who has a card | Blocked with explanation | Pass | `17` |
| 21 | Delete person without card | Delete deceased applicant (no applications) | Deleted with related rows | Pass | `18`, functional test 21 |
| 22 | SQL injection attempt | Search for `' OR 1=1 --` | Treated as text, 0 results | Pass | functional test |
| 23 | XSS attempt | `<script>` in a text field | Escaped on output, never executed | Pass | functional test 9 |
| 24 | Missing CSRF token | POST without token | Rejected ("form has expired") | Pass | functional test 10 |

## How PHP communicates with the database (for the demonstration)

1. `src/bootstrap.php` reads the settings from `.env` and creates one PDO object (`db()`) with exceptions turned on.
2. A page prepares a statement with placeholders, for example `SELECT ... WHERE receipt_no = :r`.
3. `execute()` sends the values separately from the SQL text, so input can never change the query.
4. Results come back as arrays and are printed through `e()`.
5. For multi-table saves (registration) PHP uses `beginTransaction()`, `commit()` and `rollBack()`.

## Applicant portal and officer approval workflow

| # | Test | Expected result | Result | Evidence |
|---|---|---|---|---|
| 25 | Applicant submits the public form | Reference number shown, application saved as Pending | Pass | `00b`, `00c` |
| 26 | Submit without ticking the declaration | Rejected with message | Pass | automated flow test |
| 27 | Same person applies twice | Rejected as already registered | Pass | automated flow test |
| 28 | Track with a wrong date of birth | "No application matches" and nothing is revealed | Pass | automated flow test |
| 29 | Track with the correct details | Progress timeline with status Pending | Pass | `00d` |
| 30 | Visit a staff page without logging in | Redirected to the login page (all 10 staff pages checked) | Pass | automated flow test |
| 31 | Staff login with a wrong password, and with the system account | Rejected | Pass | automated flow test |
| 32 | Officer logs in | Dashboard shows the new online application | Pass | `00e`, `00f` |
| 33 | Approve with no verified document or witness | Blocked | Pass | automated flow test |
| 34 | Reject without a reason | Blocked | Pass | automated flow test |
| 35 | Verify a document, then approve | Status Approved, officer and date saved, officer becomes the capturing officer | Pass | `00g`, `00h` |
| 36 | Applicant tracks after approval | Status Approved | Pass | `00i` |
| 37 | Approve the same application again | Blocked | Pass | automated flow test |
| 38 | Issue the card, then track | NID number assigned; applicant sees "card is ready" | Pass | automated flow test |
| 39 | Reject an application | Applicant sees the rejection reason on the tracking page | Pass | automated flow test |
| 40 | Log out | Staff pages are locked again | Pass | automated flow test |
