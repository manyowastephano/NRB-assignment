# National ID Registration System (NRB, Malawi)

Individual assignment for **CIS-DMS-421 Database Management Systems**.
A PHP and MySQL/MariaDB web application based on the EER, logical and physical models designed in the earlier assignments.

**Student:** Stephano Manyowa  **Reg. No.:** MSE/22/SS/028

## 1. What the system does

The system has two sides that work together, like a real registration office.

**Public portal (no login): for applicants**

| Page | File | Purpose |
|---|---|---|
| Home | `public/index.php` | Explains the process and what documents are needed |
| Apply for an ID | `public/apply.php` | The applicant fills in personal details, citizenship, address, chosen registration centre and a document. The system saves the application as **Pending** and gives a **reference number** |
| Track application | `public/track.php` | Reference number + date of birth shows the progress: submitted, documents verified, approved or rejected (with the reason), card ready, collected |

**Staff area (officer login): for NRB officers**

| Page | File | Purpose |
|---|---|---|
| Staff login | `public/login.php` | Employee number + staff password |
| Dashboard | `public/dashboard.php` | New online applications, pending and approved totals, work that needs attention |
| Applications (review queue) | `public/applications.php` | All applications by status; filter online or walk-in |
| **Review and approve** | `public/review.php` | The officer verifies the documents, then **approves or rejects** the application. **Approval happens here** |
| Walk-in registration | `public/register.php` | An officer registers an applicant who comes to the centre without applying online |
| Applicants | `public/applicants.php`, `applicant_view.php`, `applicant_edit.php`, `applicant_delete.php` | Search, view, update and controlled deletion of records |
| ID Cards | `public/cards.php`, `card_edit.php` | Issue a card for an approved application (NID number assigned), update card status and collection |

### Workflow

1. **Applicant** applies online and receives a reference number (application status: Pending).
2. The application appears in the staff **Applications** queue and on the dashboard.
3. The applicant visits the chosen centre with original documents. An **officer** opens the application in **Review**, ticks the documents that were verified, and clicks **Approve** (needs at least one verified document or a witness) or **Reject** (a reason is required).
4. The officer **issues the ID card** from the ID Cards page. The NID number is assigned to the person at this point.
5. The applicant sees the progress on **Track application**, and collects the card at the centre. The officer records the collection date.

Online applications are linked to a system account (`ONLINE-PORTAL`) until an officer reviews them; the reviewing officer then becomes the capturing officer. No database change is needed for this.

### Staff login details

* Employee numbers come from the `officer` table, for example `NRB-0101` (Registration Officer, Blantyre) or `NRB-0102` (Supervisor).
* The password is the same for all officers in this assignment and is set in `.env` as `STAFF_PASSWORD`. It is never stored in the code or in Git. In a real system each officer would have an individual password hash.

## 2. Requirements

* PHP 8.1 or newer with the `pdo_mysql` extension
* MySQL 8.0.16+ or MariaDB 10.2+ (CHECK constraints are enforced from these versions)
* A web server (Apache from XAMPP/WAMP/LAMP) **or** the PHP built-in server

## 3. Installation

### Step 1: get the code
```bash
git clone <repository-url> nrb-system
cd nrb-system
```

### Step 2: create the database
Import the SQL script into a fresh MySQL/MariaDB server. The script creates the database `nrb_db`
(dropping an older copy of it if one exists), all 19 tables with their constraints, and the fictional sample data.

```bash
mysql -u root -p < database/nrb_full_import.sql
```

The same content is also split in two files: `database/schema.sql` (tables and constraints) and
`database/sample_data.sql` (data). `database/queries.sql` holds the demonstration queries for Task 1 (d) and (e);
run it **after** the import (`mysql -u root -p < database/queries.sql`). It contains UPDATE and DELETE statements,
so re-import the full script afterwards to get the original data back.

### Step 3: create a database user (recommended)
```sql
CREATE USER 'nrb_user'@'localhost' IDENTIFIED BY 'choose_a_strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON nrb_db.* TO 'nrb_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 4: configure the environment
```bash
cp .env.example .env
```
Edit `.env` and fill in `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS`, and choose a `STAFF_PASSWORD` for the officer login.
The `.env` file is listed in `.gitignore` and must never be committed.

### Step 5: run the application
```bash
php -S localhost:8000 -t public
```
Open <http://localhost:8000/> for the public portal, and <http://localhost:8000/login.php> for the staff login.

With Apache (XAMPP): copy the project folder to `htdocs` and open `http://localhost/nrb-system/public/`.
Keep the `.env` file one level above `public/` (as in this repository) so it cannot be downloaded from the browser.

## 4. Project structure

```
nrb-system/
├── database/
│   ├── schema.sql            tables, keys and constraints
│   ├── sample_data.sql       fictional test data
│   ├── queries.sql           SELECT / JOIN / UPDATE / DELETE demonstrations
│   └── nrb_full_import.sql   schema + sample data in one importable file
├── public/                   web root
│   ├── index.php  apply.php  track.php            public portal
│   ├── login.php  logout.php  dashboard.php  applications.php  review.php
│   ├── register.php  applicants.php  applicant_view.php
│   ├── applicant_edit.php  applicant_delete.php  cards.php  card_edit.php
│   └── assets/css/style.css, assets/js/app.js
├── src/
│   ├── bootstrap.php         .env loader, PDO connection, helpers, validation
│   └── layout.php            shared header, footer and navigation
├── docs/
│   ├── TESTING.md            test cases and results
│   └── screenshots/          testing evidence
├── .env.example              variable names with placeholder values
├── .gitignore
└── README.md
```

## 5. Security and error handling (Task 3)

* **SQL injection:** every query that uses user input is a PDO prepared statement with real (non-emulated) preparation.
* **Validation:** names, dates, phone numbers, receipt, permit and certificate numbers, enumerated values, date order
  (for example application date after date of birth) and the officer/centre match are checked on the server before saving.
* **Duplicates and constraints:** receipt, permit, card serial and NID numbers are checked and also protected by UNIQUE keys.
  Database errors (1062 duplicate, 1451/1452 foreign key, CHECK failures) are translated into plain messages by `friendly_db_error()`.
* **XSS:** all output goes through `e()` (`htmlspecialchars`).
* **CSRF:** every POST form carries a session token that is verified.
* **Access control:** all staff pages call `require_staff()` and redirect visitors to the login page. Login attempts are limited, the session id is regenerated on login, and the tracking page needs both the reference number and the date of birth.
* **Transactions:** registration, deletion and card issuance run inside transactions and roll back on failure.
* **Secrets:** credentials are read from `.env`; connection errors never show the password or server details.

## 6. Database rules enforced

* Specialization: `citizen` and `foreign_resident` share the `person_id` key; the form creates exactly one of them.
* `id_card.application_id` is UNIQUE (one card per application); a foreign resident's card cannot outlive the residence permit.
* `application`: Approved requires an approving officer and date; Rejected requires a reason.
* ON DELETE CASCADE for weak entities and dependent rows; RESTRICT elsewhere.

## 7. Testing

See `docs/TESTING.md` for the test cases and `docs/screenshots/` for the evidence.

